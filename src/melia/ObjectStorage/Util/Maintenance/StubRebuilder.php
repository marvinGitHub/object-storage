<?php

namespace melia\ObjectStorage\Util\Maintenance;

use DateTimeImmutable;
use melia\ObjectStorage\UUID\Validator;
use melia\ObjectStorage\Util\UuidTrie;
use RuntimeException;

/**
 * Rebuilds the class index (the stubs) of a storage in place, step by step (see SteppedMaintenance):
 *
 *   1. objects: for every stored object, its stub is created if it is missing; objects without class are moved
 *      aside as corrupted
 *   2. stubs: stubs of objects that don't exist (anymore) or are of another class are removed
 *
 * The snapshots are the UUIDs of the stored objects and the UUIDs that have stubs. At the end, the registry of
 * class names is rebuilt from the stubs and empty stub directories are removed. The index stays usable meanwhile:
 * it only gains missing stubs and loses wrong ones, so no safe mode is needed, and objects stored or deleted
 * meanwhile keep their stubs right.
 */
class StubRebuilder extends SteppedMaintenance
{
    public const int DEFAULT_BATCH_SIZE = 500;

    public const string PHASE_OBJECTS = 'objects';
    public const string PHASE_STUBS = 'stubs';

    private const string STUB_SUFFIX = '.stub';

    protected function getName(): string
    {
        return 'rebuild-stubs';
    }

    /**
     * Rebuilds the stubs completely; continues a running rebuild.
     *
     * @param callable(array): void|null $onProgress Called with the progress after every step, see getProgress().
     * @return array The final progress.
     * @throws RuntimeException
     */
    public function rebuildStubs(?callable $onProgress = null, int $batchSize = self::DEFAULT_BATCH_SIZE): array
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
     * Starts a rebuild: takes snapshots of the stored objects and of the UUIDs that have stubs.
     *
     * @return array The progress, see getProgress().
     * @throws RuntimeException If a rebuild is running already.
     */
    public function start(): array
    {
        return $this->locked(function (): array {
            if (null !== $this->readState()) {
                throw new RuntimeException('A stub rebuild is running already; continue it.');
            }

            $storage = $this->getObjectStorage();

            // the objects as stored (list() without a class reads the object files, not the stubs)
            $objects = new UuidTrie();
            $chunk = [];
            foreach ($storage->list() as $uuid) {
                $chunk[] = $uuid;
                if (count($chunk) >= 10000) {
                    $objects->add(...$chunk);
                    $chunk = [];
                }
            }
            $objects->add(...$chunk);
            $this->writeTrie(self::PHASE_OBJECTS, $objects);

            // the UUIDs that have a stub, in any class directory
            $stubs = new UuidTrie();
            $chunk = [];
            foreach (glob($storage->getStubDirectory() . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*' . self::STUB_SUFFIX) ?: [] as $file) {
                $uuid = basename($file, self::STUB_SUFFIX);
                if (Validator::validate($uuid)) {
                    $chunk[] = $uuid;
                }
                if (count($chunk) >= 10000) {
                    $stubs->add(...$chunk);
                    $chunk = [];
                }
            }
            $stubs->add(...$chunk);
            $this->writeTrie(self::PHASE_STUBS, $stubs);

            $state = [
                'phase' => self::PHASE_OBJECTS,
                'objects' => $objects->count(),
                'stubs' => $stubs->count(),
                'processed' => 0,
                'created' => 0,
                'removed' => 0,
                'corrupted' => 0,
                'cursor' => null,
                'startedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
            ];
            $this->writeState($state);

            return $this->progressOf($state);
        });
    }

    /**
     * Processes the next objects or stubs of the running rebuild; completes it after the last one.
     *
     * @param int $batchSize The number of objects or UUIDs with stubs to process in this step.
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
            [$uuids, $finished] = $this->nextUuids($this->readTrie($state['phase']), $state['cursor'], $batchSize);

            if (self::PHASE_OBJECTS === $state['phase']) {
                foreach ($uuids as $uuid) {
                    $className = $storage->getClassName($uuid);

                    if (empty($className)) {
                        // without metadata or class: not loadable, as before moved aside
                        $storage->markAsCorrupted($uuid);
                        $state['corrupted']++;
                    } else {
                        $existed = is_file($storage->getFilePathStub($className, $uuid));
                        $storage->createStub($className, $uuid);
                        $state['created'] += $existed ? 0 : 1;
                    }
                    $state['processed']++;
                    $state['cursor'] = $uuid;
                }

                if ($finished) {
                    $state['phase'] = self::PHASE_STUBS;
                    $state['cursor'] = null;
                }

                $this->writeState($state);
                return $this->progressOf($state);
            }

            $stubDirectory = $storage->getStubDirectory();
            foreach ($uuids as $uuid) {
                // the stubs of the UUID in all class directories; right is only the one of the class of its object
                $className = $storage->getClassName($uuid);
                foreach (glob($stubDirectory . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . $uuid . self::STUB_SUFFIX) ?: [] as $stub) {
                    if ((null === $className || md5($className) !== basename(dirname($stub))) && @unlink($stub)) {
                        $state['removed']++;
                    }
                }
                $state['processed']++;
                $state['cursor'] = $uuid;
            }

            if (!$finished) {
                $this->writeState($state);
                return $this->progressOf($state);
            }

            $storage->rebuildClassnameRegistry();
            static::removeEmptyDirectories($stubDirectory);
            $this->finish(self::PHASE_OBJECTS, self::PHASE_STUBS);

            return ['done' => true, 'percent' => 100, 'running' => false, 'phase' => null] + $this->progressOf($state);
        });
    }

    /**
     * The progress of the running rebuild.
     *
     * @return array{running: bool, phase: ?string, total: int, processed: int, created: int, removed: int, corrupted: int, percent: int, done: bool, startedAt: ?string}
     */
    public function getProgress(): array
    {
        return $this->progressOf($this->readState());
    }

    private function progressOf(?array $state): array
    {
        if (null === $state) {
            return ['running' => false, 'phase' => null, 'total' => 0, 'processed' => 0, 'created' => 0, 'removed' => 0, 'corrupted' => 0, 'percent' => 100, 'done' => true, 'startedAt' => null];
        }

        $total = $state['objects'] + $state['stubs'];

        return [
            'running' => true,
            'phase' => $state['phase'],
            'total' => $total,
            'processed' => $state['processed'],
            'created' => $state['created'],
            'removed' => $state['removed'],
            'corrupted' => $state['corrupted'],
            'percent' => $total > 0 ? (int)floor($state['processed'] * 100 / $total) : 0,
            'done' => false,
            'startedAt' => $state['startedAt'],
        ];
    }
}
