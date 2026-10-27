<?php

namespace Tests\melia\ObjectStorage;

use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use melia\ObjectStorage\Locking\Backends\CachedLockAdapter;
use melia\ObjectStorage\Locking\LockAdapterInterface;

/**
 * Runs the cached lock adapter against an in-process cache, so it is covered without APCu/Memcached.
 * The adapter is created with its default prefix on purpose to ensure it produces valid PSR-16 keys.
 */
final class ArrayLockAdapterTest extends LockAdapterTestCase
{
    private ?Psr16Cache $cache = null;

    protected function skipIfUnavailable(): void
    {
        $this->cache = new Psr16Cache(new ArrayAdapter());
    }

    protected function createAdapter(): LockAdapterInterface
    {
        return new CachedLockAdapter($this->cache);
    }
}
