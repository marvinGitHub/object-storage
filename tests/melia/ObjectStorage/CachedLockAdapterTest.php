<?php

namespace Tests\melia\ObjectStorage;

use Memcached;
use Symfony\Component\Cache\Adapter\MemcachedAdapter;
use Symfony\Component\Cache\Exception\CacheException;
use Symfony\Component\Cache\Psr16Cache;
use melia\ObjectStorage\Locking\Backends\CachedLockAdapter;
use melia\ObjectStorage\Locking\LockAdapterInterface;

final class CachedLockAdapterTest extends LockAdapterTestCase
{
    private ?Psr16Cache $cache = null;
    private ?Memcached $memcached = null;

    /**
     * @throws CacheException
     */
    protected function skipIfUnavailable(): void
    {
        $cache = new Memcached();
        $connected = $cache->addServer('127.0.0.1', 11211);

        if (!$connected) {
            $this->markTestSkipped('Memcache server not available at 127.0.0.1:11211');
        }

        $cache->flush();
        $this->memcached = $cache;
        $this->cache = new Psr16Cache(new MemcachedAdapter($cache));
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->memcached !== null) {
            $this->memcached->flush();
            $this->memcached->quit();
        }
    }

    protected function createAdapter(): LockAdapterInterface
    {
        return new CachedLockAdapter($this->cache);
    }
}