<?php
/**
 * Migrates from the *old* layout:
 *   <storageDir>/<a>/<b>/<uuid>.(obj|metadata|stub|...)
 *
 * to the *new* layout:
 *   <storageDir>/shards/<a>/<b>/<uuid>.(obj|metadata|stub|...)
 *
 * It will:
 *  - create <storageDir>/shards if missing
 *  - move files found under 1-char/1-char shard folders into shards/
 *  - (optionally) remove now-empty old shard dirs
 *
 * Usage:
 *   php migrate_old_shards.php --root="C:\path\to\storage" [--dry-run] [--keep-empty-dirs]
 */

declare(strict_types=1);

$options = getopt('', ['root:', 'dry-run', 'keep-empty-dirs']);

$root = isset($options['root']) ? rtrim((string)$options['root'], "/\\") : '';
$dryRun = array_key_exists('dry-run', $options);
$keepEmptyDirs = array_key_exists('keep-empty-dirs', $options);

if ($root === '' || !is_dir($root)) {
    fwrite(STDERR, "Invalid or missing --root\n");
    exit(2);
}

$shardsDir = $root . DIRECTORY_SEPARATOR . 'shards';

function isOneCharShard(string $name): bool
{
    // Old layout usually shards by first chars; accept hex-ish single char (adjust if you used broader charset).
    return (bool)preg_match('/^[a-z0-9]$/i', $name);
}

function ensureDir(string $dir, bool $dryRun): void
{
    if (is_dir($dir)) {
        return;
    }
    if ($dryRun) {
        fwrite(STDOUT, "[dry-run] mkdir {$dir}\n");
        return;
    }
    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Failed to create directory: {$dir}");
    }
}

function isDirEmpty(string $dir): bool
{
    $items = scandir($dir);
    if ($items === false) {
        return false;
    }
    foreach ($items as $i) {
        if ($i !== '.' && $i !== '..') {
            return false;
        }
    }
    return true;
}

function moveFile(string $from, string $to, bool $dryRun): void
{
    if ($dryRun) {
        fwrite(STDOUT, "[dry-run] move {$from} -> {$to}\n");
        return;
    }

    // Ensure destination directory exists
    $destDir = dirname($to);
    if (!is_dir($destDir) && !@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
        throw new RuntimeException("Failed to create directory: {$destDir}");
    }

    // Prefer rename; fallback to copy+unlink if needed (e.g., cross-device move)
    if (@rename($from, $to)) {
        fwrite(STDOUT, "Moved: {$from} -> {$to}\n");
        return;
    }

    if (!@copy($from, $to)) {
        throw new RuntimeException("Failed to copy: {$from} -> {$to}");
    }
    @unlink($from);
    fwrite(STDOUT, "Moved (copy+delete): {$from} -> {$to}\n");
}

// 1) Ensure shards directory exists
try {
    ensureDir($shardsDir, $dryRun);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

// 2) Walk old layout: root/<a>/<b>/<files>
$level1 = scandir($root);
if ($level1 === false) {
    fwrite(STDERR, "Failed to list: {$root}\n");
    exit(1);
}

$moved = 0;
$checkedFiles = 0;
$oldDirsTouched = [];

foreach ($level1 as $a) {
    if ($a === '.' || $a === '..') {
        continue;
    }
    if (strcasecmp($a, 'shards') === 0) {
        continue; // don't re-process new layout
    }

    $aPath = $root . DIRECTORY_SEPARATOR . $a;
    if (!is_dir($aPath) || !isOneCharShard($a)) {
        continue;
    }

    $level2 = scandir($aPath);
    if ($level2 === false) {
        continue;
    }

    foreach ($level2 as $b) {
        if ($b === '.' || $b === '..') {
            continue;
        }

        $bPath = $aPath . DIRECTORY_SEPARATOR . $b;
        if (!is_dir($bPath) || !isOneCharShard($b)) {
            continue;
        }

        $files = scandir($bPath);
        if ($files === false) {
            continue;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $from = $bPath . DIRECTORY_SEPARATOR . $file;
            if (!is_file($from)) {
                continue;
            }

            $checkedFiles++;

            $toDir = $shardsDir . DIRECTORY_SEPARATOR . $a . DIRECTORY_SEPARATOR . $b;
            $to = $toDir . DIRECTORY_SEPARATOR . $file;

            if (file_exists($to)) {
                fwrite(STDOUT, "Skip (target exists): {$from} -> {$to}\n");
                continue;
            }

            try {
                ensureDir($toDir, $dryRun);
                moveFile($from, $to, $dryRun);
                $moved++;
                $oldDirsTouched[$bPath] = true;
                $oldDirsTouched[$aPath] = true;
            } catch (Throwable $e) {
                fwrite(STDERR, "Failed: {$from} -> {$to}: " . $e->getMessage() . "\n");
                exit(1);
            }
        }
    }
}

// 3) Remove empty old shard directories (deepest first), unless disabled
if (!$keepEmptyDirs) {
    $dirs = array_keys($oldDirsTouched);
    usort($dirs, static function (string $x, string $y): int {
        return strlen($y) <=> strlen($x);
    });

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        if ($dryRun) {
            if (isDirEmpty($dir)) {
                fwrite(STDOUT, "[dry-run] rmdir {$dir}\n");
            }
            continue;
        }
        if (isDirEmpty($dir)) {
            @rmdir($dir);
        }
    }
}

fwrite(STDOUT, "Done. Checked files: {$checkedFiles}, moved: {$moved}\n");
exit(0);