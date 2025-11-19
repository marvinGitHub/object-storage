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
}