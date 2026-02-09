<?php

namespace melia\ObjectStorage\File;

use FilesystemIterator;
use UnexpectedValueException;

class Directory
{
    private ?string $path;

    /**
     * Constructor to initialize the object with an optional path.
     *
     * @param string|null $path Optional file path to initialize.
     * @return void
     */
    public function __construct(?string $path = null)
    {
        $this->setPath($path);
    }

    /**
     * Sets the value of the path property.
     *
     * @param string|null $path The path to be set. Can be null.
     * @return void
     */
    public function setPath(?string $path): void
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
     * Retrieves the current file path.
     *
     * @return string|null The current file path, or null if not set.
     */
    public function getPath(): ?string
    {
        return $this->path;
    }

    /**
     * Performs cleanup by removing the specified directory and its contents if it exists.
     *
     * @return bool
     */
    public function tearDown(): bool
    {
        if (null === $this->path) {
            return false;
        }

        if (false === is_dir($this->path)) {
            return false;
        }

        $exitCode = null;
        exec(command: sprintf('rm -rf %s', escapeshellarg($this->path)), result_code: $exitCode);
        return $exitCode === 0;
    }

    /**
     * Reserves a temporary directory with a random name in the system's temporary directory.
     *
     * @return bool Returns true if the temporary directory was successfully created or reserved, false otherwise.
     */
    public function reserveRandomTemporaryDirectory(): bool
    {
        $path = tempnam(sys_get_temp_dir(), static::class);

        if (file_exists($path)) {
            unlink($path);
        }

        $this->path = $path;
        return $this->createIfNotExists();
    }

    /**
     * Creates a directory at the specified path if it does not already exist.
     *
     * @return bool Returns true if the directory exists or was successfully created, false otherwise.
     */
    public function createIfNotExists(): bool
    {
        if (null === $this->path) {
            return false;
        }

        if (false === is_dir($this->path)) {
            return mkdir($this->path, 0777, true);
        }

        return true;
    }

    /**
     * Checks if the directory is empty.
     *
     * @return bool True if the directory is empty, false otherwise or if it is not readable.
     */
    public function isEmpty() : bool
    {
        try {
            $it = new FilesystemIterator($this->path, FilesystemIterator::SKIP_DOTS);
            return !$it->valid(); // no entries
        } catch (UnexpectedValueException $e) {
            return false; // unreadable / not a directory
        }
    }
}