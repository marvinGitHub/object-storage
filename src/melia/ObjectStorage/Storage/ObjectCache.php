<?php

namespace melia\ObjectStorage\Storage;

use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\DispatcherInterface;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Logger\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Throwable;

/**
 * The cache of loaded objects, with its events. Without a cache nothing is cached; the events of removing and
 * clearing are dispatched all the same.
 */
final readonly class ObjectCache
{
    public function __construct(
        private ?CacheInterface      $cache = null,
        private ?DispatcherInterface $dispatcher = null,
        private ?LoggerInterface     $logger = null,
    )
    {
    }

    /**
     * The cached object (CACHE_HIT dispatched), or null if it isn't cached.
     *
     * @throws InvalidArgumentException
     */
    public function get(string $uuid): ?object
    {
        $cached = $this->cache?->get($uuid);
        if (null !== $cached) {
            $this->dispatcher?->dispatch(Events::CACHE_HIT, static fn() => new Context($uuid));
        }

        return $cached;
    }

    /**
     * Caches an object (CACHE_ENTRY_ADDED dispatched) for its lifetime: without expiration if null, removed if it
     * has expired (0 or less). A failing cache is logged and reported (CACHE_WRITE_FAILED), not thrown: the object
     * is then read from its files.
     *
     * @param int|float|null $ttl The lifetime in seconds, cached as whole seconds.
     */
    public function add(string $uuid, object $object, null|int|float $ttl = null): void
    {
        try {
            if (null === $this->cache) {
                return;
            }

            if (null !== $ttl && $ttl <= 0) {
                $this->remove($uuid);
                return;
            }

            $this->cache->set($uuid, $object, null === $ttl ? null : (int)$ttl);
            $this->dispatcher?->dispatch(Events::CACHE_ENTRY_ADDED, static fn() => new Context($uuid));
        } catch (Throwable $e) {
            $this->logger?->log($e);
            $this->dispatcher?->dispatch(Events::CACHE_WRITE_FAILED, static fn() => new Context($uuid));
        }
    }

    /**
     * Removes an object from the cache (CACHE_ENTRY_REMOVED dispatched).
     *
     * @throws InvalidArgumentException
     */
    public function remove(string $uuid): void
    {
        $this->cache?->delete($uuid);
        $this->dispatcher?->dispatch(Events::CACHE_ENTRY_REMOVED, static fn() => new Context($uuid));
    }

    /**
     * Removes all objects from the cache; the event CACHE_CLEARED is the facade's, which clears more.
     */
    public function clear(): void
    {
        $this->cache?->clear();
    }
}
