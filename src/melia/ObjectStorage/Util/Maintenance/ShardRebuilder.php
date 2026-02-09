<?php

namespace melia\ObjectStorage\Util\Maintenance;

use FilesystemIterator;
use melia\ObjectStorage\File\Directory;
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
        /** @var ObjectStorage $storage */
        $storage = $this->getStorage();

        /* enable safe mode */
        $storage->getStateHandler()?->enableSafeMode();

        // Assumption: stored filename equals UUID (adjust if needed).
        // We only relocate files; the storage decides the correct sharded directory.
        $root = rtrim($storage->getStorageDir(), DIRECTORY_SEPARATOR);
        if ($root === '' || !is_dir($root)) {
            throw new RuntimeException('Storage root directory is invalid: ' . $root);
        }

        $dirs = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
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

            $uuid = $info->getBasename(); // <-- adjust if your file naming differs

            $targetDir = $this->storage->getShardedDirectory($uuid);
            $targetPath = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $info->getBasename();

            if ($this->pathsEqual($path, $targetPath)) {
                continue;
            }

            if (file_exists($targetPath)) {
                // Avoid data loss; you can implement stronger collision handling if needed.
                continue;
            }

            if (!@rename($path, $targetPath)) {
                if (!@copy($path, $targetPath)) {
                    throw new RuntimeException('Failed to move file to: ' . $targetPath);
                }
                @unlink($path);
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

            if ($this->pathsEqual($dir, $root)) {
                continue;
            }

            if (is_dir($dir) && (new Directory($dir))->isEmpty()) {
                @rmdir($dir);
            }
        }

        $storage->getStateHandler()?->disableSafeMode();
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