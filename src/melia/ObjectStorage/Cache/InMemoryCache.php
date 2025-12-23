<?php

namespace melia\ObjectStorage\Cache;

use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;

/**
 * Simple in-memory PSR-16 cache with TTL support.
 *
 * Stores values alongside absolute expiration timestamps and evicts on read/has.
 */
class InMemoryCache implements CacheInterface
{
    /**
     * Internal storage:
     * - key => ['v' => mixed value, 'e' => float|null expiresAtTimestamp]
     *
     * @var array<string, array{v:mixed, e:float|null}>
     */
    protected array $data = [];

    /**
     * @param array $initial Optional initial values (stored without expiry)
     */
    public function __construct(protected array $initial = [])
    {
        foreach ($initial as $k => $v) {
            $this->data[$k] = ['v' => $v, 'e' => null];
        }
    }

    /**
     * @inheritDoc
     */
    public function clear(): bool
    {
        $this->data = [];
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, $this->data)) {
            return $default;
        }
        $entry = $this->data[$key];
        if ($this->isExpired($entry['e'])) {
            unset($this->data[$key]);
            return $default;
        }
        return $entry['v'];
    }

    /**
     * Check if an absolute expiry timestamp is in the past.
     */
    private function isExpired(?float $expiresAt): bool
    {
        return $expiresAt !== null && $expiresAt <= microtime(true);
    }

    /**
     * @inheritDoc
     */
    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $expiresAt = $this->normalizeTtl($ttl);
        if ($expiresAt !== null && $expiresAt <= microtime(true)) {
            // Already expired: drop from cache (no-op success)
            unset($this->data[$key]);
            return true;
        }
        $this->data[$key] = ['v' => $value, 'e' => $expiresAt];
        return true;
    }

    /**
     * Convert PSR-16 TTL formats to an absolute unix timestamp (float seconds) or null.
     * - null => no expiry
     * - int seconds => now + seconds (can be <= 0)
     * - DateInterval => now + interval
     */
    private function normalizeTtl(DateInterval|int|null $ttl): ?float
    {
        if ($ttl === null) {
            return null;
        }
        if ($ttl instanceof DateInterval) {
            $now = new DateTimeImmutable('now');
            $target = $now->add($ttl);
            return (float)$target->getTimestamp();
        }
        return microtime(true) + (int)$ttl;
    }

    /**
     * @inheritDoc
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            if ($this->delete($key) === false) {
                $success = false;
            }
        }
        return $success;
    }

    /**
     * @inheritDoc
     */
    public function delete(string $key): bool
    {
        unset($this->data[$key]);
        return true;
    }

    /**
     * @inheritDoc
     */
    public function has(string $key): bool
    {
        if (!array_key_exists($key, $this->data)) {
            return false;
        }
        $entry = $this->data[$key];
        if ($this->isExpired($entry['e'])) {
            unset($this->data[$key]);
            return false;
        }
        return true;
    }
}