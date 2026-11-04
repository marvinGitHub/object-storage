<?php

namespace melia\ObjectStorage\Util\Maintenance;

use DateTimeImmutable;
use FilesystemIterator;
use melia\ObjectStorage\File\IO\RealAdapter;
use melia\ObjectStorage\UUID\Helper;
use melia\ObjectStorage\UUID\Validator;
use melia\ObjectStorage\Util\UuidTrie;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Moves the object files into the directories of the current shard depth of the strategy.
 *
 * The objects are moved step by step (see SteppedMaintenance): start() takes a snapshot of the UUIDs of the stored
 * objects and of the depths their files are found in, step() moves the files of the next objects, the last step
 * removes empty directories.
 *
 * The storage is in safe mode from the start until the rebuild is complete, as objects can't be found while their
 * files are in directories of different depths; an interrupted rebuild keeps it there until it is continued.
 */
class ShardRebuilder extends SteppedMaintenance
{
    public const int DEFAULT_BATCH_SIZE = 500;

    private const string TRIE_OBJECTS = 'objects';

    protected function getName(): string
    {
        return 'rebuild-shards';
    }

    /**
     * Rebuilds the shards completely; continues a running rebuild.
     *
     * @param callable(array): void|null $onProgress Called with the progress after every step, see getProgress().
     * @param int $batchSize The number of objects to move per step.
     * @return array The final progress.
     * @throws RuntimeException
     */
    public function rebuildShards(?callable $onProgress = null, int $batchSize = self::DEFAULT_BATCH_SIZE): array
    {
        $progress = $this->isRunning() ? $this->getProgress() : $this->start();
        $onProgress && $onProgress($progress);

        while (!$progress['done']) {
            $progress = $this->step($batchSize);
            $onProgress && $onProgress($progress);
        }

        return $progress;
    }

    /**
     * Starts a rebuild to the current shard depth of the strategy: enables safe mode and collects the objects to
     * move and the depths their files are in.
     *
     * @return array The progress, see getProgress().
     * @throws RuntimeException If a rebuild is running already.
     */
    public function start(): array
    {
        return $this->locked(function (): array {
            if (null !== $this->readState()) {
                throw new RuntimeException('A shard rebuild is running already; continue it.');
            }

            $storage = $this->getObjectStorage();
            $stateHandler = $storage->getStateHandler();
            // safe mode enabled before (e.g. after a corruption) stays enabled after the rebuild
            $safeModeBefore = (bool)$stateHandler?->safeModeEnabled();
            $stateHandler?->enableSafeMode();

            // the files are named after their object ("<uuid>.obj", "<uuid>.metadata", …): the UUIDs are the
            // snapshot, the depths tell where to find their files
            $shardDir = $storage->getShardDir();
            $objects = new UuidTrie();
            $depths = [];
            $chunk = [];

            if (is_dir($shardDir)) {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($shardDir, FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $info) {
                    /** @var SplFileInfo $info */
                    $uuid = substr($info->getFilename(), 0, 36);
                    if (!$info->isFile() || !Validator::validate($uuid)) {
                        continue;
                    }
                    $depths[$iterator->getDepth()] = true;
                    $chunk[] = $uuid;
                    if (count($chunk) >= 10000) {
                        $objects->add(...$chunk);
                        $chunk = [];
                    }
                }
            }
            $objects->add(...$chunk);
            $this->writeTrie(self::TRIE_OBJECTS, $objects);

            $depths = array_keys($depths);
            sort($depths);

            $state = [
                'depth' => $storage->getStrategy()?->getShardDepth(),
                'depths' => $depths,
                'total' => $objects->count(),
                'processed' => 0,
                'moved' => 0,
                'cursor' => null,
                'safeModeBefore' => $safeModeBefore,
                'startedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
            ];
            $this->writeState($state);

            return $this->progressOf($state);
        });
    }

    /**
     * Moves the files of the next objects of the running rebuild; completes it after the last one.
     *
     * @param int $batchSize The number of objects to move in this step.
     * @return array The progress, see getProgress().
     * @throws RuntimeException
     */
    public function step(int $batchSize = self::DEFAULT_BATCH_SIZE): array
    {
        return $this->locked(function () use ($batchSize): array {
            $state = $this->readState();
            if (null === $state) {
                return $this->progressOf(null);
            }

            $storage = $this->getObjectStorage();

            // the depth of the rebuild, also if this step runs in another request
            $strategy = $storage->getStrategy();
            if (null !== $state['depth'] && method_exists($strategy, 'setShardDepth')) {
                $strategy->setShardDepth($state['depth']);
            }

            [$uuids, $finished] = $this->nextUuids($this->readTrie(self::TRIE_OBJECTS), $state['cursor'], $batchSize);

            $shardDir = $storage->getShardDir();
            foreach ($uuids as $uuid) {
                // the files of the object in the depths found at the start (a file moved meanwhile isn't found)
                $key = Helper::removeHyphens($uuid);
                foreach ($state['depths'] as $depth) {
                    $directory = rtrim($shardDir . DIRECTORY_SEPARATOR . chunk_split(substr($key, 0, $depth), 1, DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR);
                    foreach (glob($directory . DIRECTORY_SEPARATOR . $uuid . '*') ?: [] as $file) {
                        if (is_file($file) && $this->relocate($file)) {
                            $state['moved']++;
                        }
                    }
                }
                $state['processed']++;
                $state['cursor'] = $uuid;
            }

            if (!$finished) {
                $this->writeState($state);
                return $this->progressOf($state);
            }

            static::removeEmptyDirectories($shardDir);

            if (!$state['safeModeBefore']) {
                $storage->getStateHandler()?->disableSafeMode();
            }

            $this->finish(self::TRIE_OBJECTS);

            return ['done' => true, 'percent' => 100, 'running' => false] + $this->progressOf($state);
        });
    }

    /**
     * The progress of the running rebuild.
     *
     * @return array{running: bool, depth: ?int, total: int, processed: int, moved: int, percent: int, done: bool, startedAt: ?string}
     */
    public function getProgress(): array
    {
        return $this->progressOf($this->readState());
    }

    /**
     * Moves a file into the directory of the current shard depth.
     *
     * @return bool Whether the file was moved.
     * @throws RuntimeException
     */
    private function relocate(string $path): bool
    {
        $adapter = new RealAdapter();

        $basename = basename($path);
        // the file name starts with the UUID: "<uuid>.obj", "<uuid>.metadata", "<uuid>.obj.pending", …
        $targetDir = $this->getObjectStorage()->buildShardedDirectory(substr($basename, 0, 36));

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Failed to create shard directory: ' . $targetDir);
        }

        $targetPath = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $basename;

        if ($this->pathsEqual($path, $targetPath)) {
            return false;
        }

        if ($adapter->fileExists($targetPath)) {
            // Keep the newest file (by mtime). Discard the older one.
            $srcMTime = $adapter->fileMTime($path);
            $dstMTime = $adapter->fileMTime($targetPath);

            // If we can't determine times reliably, or the target is newer or the same, keep the target
            if ($srcMTime === false || $dstMTime === false || $dstMTime >= $srcMTime) {
                $adapter->unlink($path);
                return false;
            }

            // Source is newer -> replace target with source.
            if (!$adapter->unlink($targetPath) && $adapter->fileExists($targetPath)) {
                throw new RuntimeException('Failed to remove older target file: ' . $targetPath);
            }
        }

        if (!$adapter->rename($path, $targetPath)) {
            if (!$adapter->copy($path, $targetPath)) {
                throw new RuntimeException('Failed to move file to: ' . $targetPath);
            }
            $adapter->unlink($path);
        }

        return true;
    }

    private function progressOf(?array $state): array
    {
        if (null === $state) {
            return ['running' => false, 'depth' => null, 'total' => 0, 'processed' => 0, 'moved' => 0, 'percent' => 100, 'done' => true, 'startedAt' => null];
        }

        return [
            'running' => true,
            'depth' => $state['depth'],
            'total' => $state['total'],
            'processed' => $state['processed'],
            'moved' => $state['moved'],
            'percent' => $state['total'] > 0 ? (int)floor($state['processed'] * 100 / $state['total']) : 0,
            'done' => false,
            'startedAt' => $state['startedAt'],
        ];
    }

    /**
     * Compares two file paths to determine if they are equal, accounting for platform-specific directory separators
     * and case insensitivity on Windows systems.
     */
    private function pathsEqual(string $a, string $b): bool
    {
        $na = str_replace('/', DIRECTORY_SEPARATOR, $a);
        $nb = str_replace('/', DIRECTORY_SEPARATOR, $b);

        if (DIRECTORY_SEPARATOR === '\\') {
            return strtolower($na) === strtolower($nb);
        }

        return $na === $nb;
    }
}
