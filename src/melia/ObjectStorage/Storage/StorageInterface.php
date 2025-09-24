<?php


namespace melia\ObjectStorage\Storage;

use Traversable;

interface StorageInterface
{
    public function exists(string $uuid): bool;

    public function store(object $object, ?string $uuid = null, ?int $ttl = null): string;

    public function load(string $uuid): ?object;

    public function delete(string $uuid, bool $force = false): bool;

    public function list(?string $classname = null): Traversable;
    public function expired(string $uuid): bool;
    public function setExpiration(string $uuid, ?int $expiresAt): void;
    public function getExpiration(string $uuid): ?int;
    public function setLifetime(string $uuid, int $ttl): void;
    public function getLifetime(string $uuid): ?int;
}