<?php

namespace melia\ObjectStorage\File;

class Directory
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Retrieves the directory name from a given file path.
     *
     * @param string $path The file path from which to extract the directory name.
     * @return string Returns the directory portion of the path.
     */
    public static function getDirectoryName(string $path): string
    {
        return pathinfo($path, PATHINFO_DIRNAME);
    }

    /**
     * Performs cleanup by removing the specified directory and its contents if it exists.
     *
     * @return void
     */
    public function tearDown(): void
    {
        if (false === is_dir($this->path)) {
            return;
        }
        exec(sprintf('rm -rf %s', escapeshellarg($this->path)));
    }

    /**
     * Creates a directory at the specified path if it does not already exist.
     *
     * @return bool Returns true if the directory exists or was successfully created, false otherwise.
     */
    public function createIfNotExists(): bool
    {
        if (false === is_dir($this->path)) {
            return mkdir($this->path, 0777, true);
        }
        return true;
    }
}