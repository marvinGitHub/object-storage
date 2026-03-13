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
}
