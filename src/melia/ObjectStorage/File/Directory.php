<?php

namespace melia\ObjectStorage\File;

class Directory
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function tearDown(): void
    {
        if (false === is_dir($this->path)) {
            return;
        }
        exec(sprintf('rm -rf %s', escapeshellarg($this->path)));
    }
}