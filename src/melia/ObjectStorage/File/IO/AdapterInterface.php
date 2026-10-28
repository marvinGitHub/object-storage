<?php

namespace melia\ObjectStorage\File\IO;

interface AdapterInterface
{
    /** @return resource|false */
    public function fopen(string $filename, string $mode);

    public function rewind($resource): bool;

    /** @return int|false */
    public function fwrite($resource, string $data);

    public function fflush($resource): bool;

    /**
     * Flushes the content of a file to disk, so it survives a crash or power loss.
     *
     * @return bool Whether the file could be opened and flushed.
     */
    public function fsyncFile(string $filename): bool;

    /**
     * Flushes a directory to disk, so files created or renamed in it survive a crash or power loss.
     * Returns true where directories don't need to (or can't) be flushed explicitly, e.g. on Windows.
     */
    public function fsyncDirectory(string $directory): bool;

    /**
     * Renames a file atomically, replacing an existing file, and flushes the rename to disk, so it survives a
     * crash or power loss. Flushing is best effort: some filesystems don't support flushing directories.
     *
     * @return bool Whether the file was renamed.
     */
    public function renameSync(string $from, string $to): bool;

    /** @return int|false */
    public function ftell($resource);

    public function flock($resource, int $operation, &$wouldblock = null): bool;

    public function ftruncate($resource, int $size): bool;

    public function fclose($resource): bool;

    public function unlink(string $filename): bool;

    public function fileExists(string $filename): bool;

    public function mkdir(string $dir, int $mode = 0777, bool $recursive = true): bool;

    public function touch(string $filename): bool;

    public function fileSize(string $filename): bool|int;

    public function isDir(string $filename): bool;

    public function isFile(string $filename): bool;

    public function fileGetContents(string $filename): false|string;

    public function rename(string $from, string $to, $context = null): bool;

    /**
     * Moves a file into a directory, creating the directory if needed.
     *
     * @param string|null $fileName The new name of the file, defaults to the basename of the source file.
     */
    public function moveFile(string $source, string $targetDir, ?string $fileName = null): bool;

    /**
     * Like moveFile(), but flushes the move to disk, so it survives a crash or power loss (see renameSync()).
     */
    public function moveFileSync(string $source, string $targetDir, ?string $fileName = null): bool;

    public function filePutContents(string $filename, string $data, int $flags = 0, $context = null): int|bool;

    public function tempnam(string $directory, string $prefix): bool|string;
}