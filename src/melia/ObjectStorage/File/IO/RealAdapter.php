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
}