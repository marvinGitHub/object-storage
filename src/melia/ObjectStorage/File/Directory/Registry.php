<?php

namespace melia\ObjectStorage\File\Directory;

class Registry
{
    /** @var array<string, bool> */
    protected array $verifiedDirectories = [];

    private function __construct()
    {

    }

    public static function getInstance(): self
    {
        static $instance;
        return $instance ??= new self();
    }

    public function isVerified(?string $directory): bool
    {
        if (null === $directory) {
            return false;
        }

        return $this->verifiedDirectories[$directory] ?? false;
    }

    public function markAsVerified(string $directory): void
    {
        $this->verifiedDirectories[$directory] = true;
    }

    public function markAsNotVerified(string $directory): void
    {
        $this->verifiedDirectories[$directory] = false;
    }

    /**
     * Marks a directory and all directories below it as not verified, e.g. after removing the directory: otherwise
     * a removed subdirectory would still count as existing, and files couldn't be created in it.
     */
    public function markTreeAsNotVerified(string $directory): void
    {
        $directory = rtrim($directory, '/\\');
        $this->verifiedDirectories[$directory] = false;

        foreach (array_keys($this->verifiedDirectories) as $path) {
            if (str_starts_with($path, $directory . '/') || str_starts_with($path, $directory . '\\')) {
                $this->verifiedDirectories[$path] = false;
            }
        }
    }
}
