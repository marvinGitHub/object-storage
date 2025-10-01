<?php

namespace melia\ObjectStorage\Cache;

class InMemoryCache implements CacheInterface
{
    private array $cache = [];

    public function has(string $uuid): bool
    {
        return isset($this->cache[$uuid]);
    }

    public function get(string $uuid): ?object
    {
        return $this->cache[$uuid] ?? null;
    }

    public function set(string $uuid, object $object): void
    {
        $this->cache[$uuid] = $object;
    }

    public function delete(string $uuid): void
    {
        unset($this->cache[$uuid]);
    }

    public function clear(): void
    {
        $this->cache = [];
    }
}