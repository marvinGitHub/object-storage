<?php

namespace melia\ObjectStorage\File\IO;

class RealAdapter implements AdapterInterface
{
    public function fileMTime(string $filename): int|false
    {
        return @filemtime($filename);
    }

    public function fopen(string $filename, string $mode)
    {
        return fopen($filename, $mode);
    }

    public function rewind($resource): bool
    {
        return rewind($resource);
    }

    public function fwrite($resource, string $data): int
    {
        return fwrite($resource, $data);
    }

    public function fflush($resource): bool
    {
        return fflush($resource);
    }

    public function fsyncFile(string $filename): bool
    {
        // opened for writing: on Windows, flushing requires write access
        return $this->fsyncPath($filename, 'r+');
    }

    public function renameSync(string $from, string $to): bool
    {
        if (false === $this->rename($from, $to)) {
            return false;
        }

        $this->fsyncDirectory(dirname($to));

        return true;
    }

    public function fsyncDirectory(string $directory): bool
    {
        // directories cannot be opened for flushing on Windows; NTFS journals these changes itself
        if (DIRECTORY_SEPARATOR === '\\') {
            return true;
        }

        return $this->fsyncPath($directory, 'r');
    }

    /**
     * Opens a file or directory, flushes it to disk and closes it again.
     *
     * @param string $path The file or directory to flush.
     * @param string $mode The mode to open it with.
     * @return bool Whether it could be opened and flushed.
     */
    private function fsyncPath(string $path, string $mode): bool
    {
        $handle = @fopen($path, $mode);
        if (false === $handle) {
            return false;
        }

        try {
            return @fsync($handle);
        } finally {
            fclose($handle);
        }
    }

    public function ftell($resource): int
    {
        return ftell($resource);
    }

    public function ftruncate($resource, int $size): bool
    {
        return ftruncate($resource, $size);
    }

    public function fclose($resource): bool
    {
        return fclose($resource);
    }

    public function feof($resource): bool
    {
        return feof($resource);
    }

    public function fread($resource, int $length): string|false
    {
        return fread($resource, $length);
    }

    public function touch(string $filename): bool
    {
        return touch($filename);
    }

    public function fileSize(string $filename): bool|int
    {
        return @filesize($filename);
    }

    public function isFile(string $filename): bool
    {
        return is_file($filename);
    }

    public function flock($resource, int $operation, &$wouldblock = null): bool
    {
        return flock($resource, $operation, $wouldblock);
    }

    public function fileGetContents(string $filename): false|string
    {
        return @file_get_contents($filename);
    }

    public function copy(string $source, string $dest): bool
    {
        return copy($source, $dest);
    }

    /**
     * Moves a file from a source location to a target directory.
     * Optionally, a new name for the file can be provided.
     *
     * @param string $source The path to the source file.
     * @param string $targetDir The target directory where the file will be moved.
     * @param string|null $fileName Optional. The new name for the file in the target directory.
     *                               Defaults to the basename of the source file.
     * @return bool Returns true on success or false on failure.
     */
    public function moveFile(string $source, string $targetDir, ?string $fileName = null): bool
    {
        return $this->rename($source, $this->prepareMoveTarget($source, $targetDir, $fileName));
    }

    /**
     * Like moveFile(), but flushes the move to disk, see renameSync().
     */
    public function moveFileSync(string $source, string $targetDir, ?string $fileName = null): bool
    {
        return $this->renameSync($source, $this->prepareMoveTarget($source, $targetDir, $fileName));
    }

    /**
     * Creates the target directory of a move if it doesn't exist and returns the target path.
     *
     * @param string $source The path to the source file.
     * @param string $targetDir The target directory.
     * @param string|null $fileName The new name of the file, defaults to the basename of the source file.
     * @return string The path of the file in the target directory.
     */
    private function prepareMoveTarget(string $source, string $targetDir, ?string $fileName): string
    {
        if (!$this->isDir($targetDir)) {
            $this->mkdir($targetDir, 0777, true);
        }

        return rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ($fileName ?? basename($source));
    }

    public function isDir(string $filename): bool
    {
        return is_dir($filename);
    }

    public function mkdir(string $dir, int $mode = 0777, bool $recursive = true): bool
    {
        // a failure is reported by the return value; the warning would also be raised when another
        // process created the directory a moment earlier, which callers handle by checking again
        return @mkdir($dir, $mode, $recursive);
    }

    public function rename(string $from, string $to, $context = null): bool
    {
        $result = rename($from, $to, $context);

        // ensure rename is atomic and overrides the file if it already exists
        if (false === $result) {
            if ($this->fileExists($to)) {
                $this->unlink($to);
            }
            $result = rename($from, $to, $context);
        }
        return $result;
    }

    public function fileExists(string $filename): bool
    {
        return file_exists($filename);
    }

    public function unlink(string $filename): bool
    {
        return unlink($filename);
    }

    public function filePutContents(string $filename, string $data, int $flags = 0, $context = null): int|bool
    {
        return file_put_contents($filename, $data, $flags, $context);
    }

    public function tempnam(string $directory, string $prefix): bool|string
    {
        return tempnam($directory, $prefix);
    }
}