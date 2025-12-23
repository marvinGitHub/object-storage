<?php

/**
 * Migration script to shard existing .obj and .metadata files into subdirectories.
 * Usage: php 001_002.php --dir=/path/to/storage
 */

$options = getopt("", ["dir:"]);
$storageDir = $options['dir'] ?? null;

if (!$storageDir || !is_dir($storageDir)) {
    die("Error: Please provide a valid storage directory using --dir=/path/to/storage\n");
}

$storageDir = rtrim($storageDir, DIRECTORY_SEPARATOR);
$iterator = new DirectoryIterator($storageDir);

echo "Starting migration in: $storageDir\n";

foreach ($iterator as $fileinfo) {
    if ($fileinfo->isDot() || $fileinfo->isDir()) {
        continue;
    }

    $filename = $fileinfo->getFilename();

    // Process only .obj and .metadata files
    if (!str_ends_with($filename, '.obj') && !str_ends_with($filename, '.metadata')) {
        continue;
    }

    $shard1 = substr($filename, 0, 1);
    $shard2 = substr($filename, 1, 1);
    $targetDir = $storageDir . DIRECTORY_SEPARATOR . $shard1 . DIRECTORY_SEPARATOR . $shard2;

    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0777, true)) {
            echo "Failed to create directory: $targetDir\n";
            continue;
        }
    }

    $oldPath = $fileinfo->getPathname();
    $newPath = $targetDir . DIRECTORY_SEPARATOR . $filename;

    if (rename($oldPath, $newPath)) {
        echo "Moved: $oldPath -> $newPath\n";
    } else {
        echo "Failed to move: $filename\n";
    }
}

echo "Migration complete.\n";