<?php

namespace melia\ObjectStorage\File\IO;

class RealAdapter implements AdapterInterface
{
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

    public function unlink(string $filename): bool
    {
        return unlink($filename);
    }

    public function fileExists(string $filename): bool
    {
        return file_exists($filename);
    }

    public function mkdir(string $dir, int $mode = 0777, bool $recursive = true): bool
    {
        return mkdir($dir, $mode, $recursive);
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

    public function isDir(string $filename): bool
    {
        return is_dir($filename);
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

    public function rename(string $from, string $to, $context = null): bool
    {
        return rename($from, $to, $context);
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
        $fileName = $fileName ?? basename($source);
        if (!$this->isDir($targetDir)) {
            $this->mkdir($targetDir, 0777, true);
        }
        return $this->rename($source, rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName);
    }
}