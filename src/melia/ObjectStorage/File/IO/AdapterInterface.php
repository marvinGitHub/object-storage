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

    public function ftruncate($resource, int $size): bool;

    public function fclose($resource): bool;

    public function unlink(string $filename): bool;

    public function fileExists(string $filename): bool;

    public function mkdir(string $dir, int $mode = 0777, bool $recursive = true): bool;
}