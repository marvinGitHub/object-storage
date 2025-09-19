<?php


namespace melia\ObjectStorage\Storage;

use Traversable;

interface StorageInterface
{
    public function exists(string $uuid): bool;

    public function store(object $object, ?string $uuid = null): string;

    public function load(string $uuid): ?object;

    public function delete(string $uuid, bool $force = false): bool;

    public function list(?string $classname = null): Traversable;
}