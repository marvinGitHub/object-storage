<?php

namespace melia\ObjectStorage\Util\Maintenance;

use FilesystemIterator;
use melia\ObjectStorage\File\Directory;
use melia\ObjectStorage\File\IO\RealAdapter;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Storage\StorageAwareTrait;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

class ShardRebuilder
{
    use StorageAwareTrait;

    public function rebuildShards(): void
    {
        $adapter = new RealAdapter();

        /** @var ObjectStorage $storage */
        $storage = $this->getStorage();

        /* enable safe mode */
        $storage->getStateHandler()?->enableSafeMode();

        try {
            // Assumption: stored filename equals UUID (adjust if needed).
            // We only relocate files; the storage decides the correct sharded directory.
            $shardDir = $storage->getShardDir();

            $dirs = [];

            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $shardDir,
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO
                ),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($it as $info) {
                /** @var SplFileInfo $info */
                $path = $info->getPathname();

                if ($info->isDir()) {
                    $dirs[] = $path;
                    continue;
                }

                if (!$info->isFile()) {
                    continue;
                }

                $uuid = $info->getBasename();

                $targetDir = $storage->getShardedDirectory($uuid);

                if (!is_dir($targetDir)) {
                    if (!@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                        throw new RuntimeException('Failed to create shard directory: ' . $targetDir);
                    }
                }

                $targetPath = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $info->getBasename();

                if ($this->pathsEqual($path, $targetPath)) {
                    continue;
                }

                if ($adapter->fileExists($targetPath)) {
                    // Keep the newest file (by mtime). Discard the older one.
                    $srcMTime = $adapter->fileMTime($path);
                    $dstMTime = $adapter->fileMTime($targetPath);

                    // If we can't determine times reliably, prefer keeping the existing target to avoid accidental overwrite.
                    if ($srcMTime === false || $dstMTime === false) {
                        $adapter->unlink($path); // best effort cleanup of duplicate
                        $dirs[] = $info->getPath();
                        continue;
                    }

                    // Target is newer or same -> keep target, remove source duplicate.
                    if ($dstMTime >= $srcMTime) {
                        $adapter->unlink($path);
                        $dirs[] = $info->getPath();
                        continue;
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

                $dirs[] = $info->getPath();
            }

            // Remove empty directories (deepest first), never remove root.
            $dirs = array_values(array_unique($dirs));
            usort($dirs, static function (string $a, string $b): int {
                return strlen($b) <=> strlen($a);
            });

            foreach ($dirs as $dir) {
                $dir = rtrim($dir, DIRECTORY_SEPARATOR);

                if ($this->pathsEqual($dir, $shardDir)) {
                    continue;
                }

                $directory = new Directory($dir);
                if ($directory->isEmpty()) {
                    $directory->tearDown();
                }
            }
        } finally {
            $storage->getStateHandler()?->disableSafeMode();
        }
    }

    /**
     * Compares two file paths to determine if they are equal, accounting for platform-specific directory separators
     * and case insensitivity on Windows systems.
     *
     * @param string $a The first file path to compare.
     * @param string $b The second file path to compare.
     * @return bool Returns true if the two paths are considered equal, false otherwise.
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