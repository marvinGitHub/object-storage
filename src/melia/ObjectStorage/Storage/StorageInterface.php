<?php


namespace melia\ObjectStorage\Storage;

use Traversable;

interface StorageInterface
{
    public function exists(string $uuid): bool;

    public function store(object $object, ?string $uuid = null, ?int $ttl = null): string;

    public function load(string $uuid): ?object;

    public function delete(string $uuid): void;

    public function list(?string $className = null): Traversable;

    public function expired(string $uuid): bool;

    public function setExpiration(string $uuid, null|int|float $expiresAt): void;

    public function getExpiration(string $uuid): ?float;

    public function setLifetime(string $uuid, int|float $ttl): void;

    public function getLifetime(string $uuid): ?float;

    public function getClassName(string $uuid): ?string;

    public function match(callable $matcher, ?string $className = null, ?int $limit = null, ?array $subSet = null): Traversable;
}