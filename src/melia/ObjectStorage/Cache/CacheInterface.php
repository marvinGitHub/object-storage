<?php

namespace melia\ObjectStorage\Cache;

interface CacheInterface {
    public function has(string $uuid): bool;
    public function get(string $uuid): ?object;
    public function set(string $uuid, object $object): void;
    public function delete(string $uuid): void;
    public function clear(): void;
}
