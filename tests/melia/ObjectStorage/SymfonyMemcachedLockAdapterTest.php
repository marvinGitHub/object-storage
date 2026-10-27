<?php

namespace Tests\melia\ObjectStorage;

use Memcached;
use melia\ObjectStorage\Locking\Backends\SymfonyLockAdapter;
use melia\ObjectStorage\Locking\LockAdapterInterface;
use Symfony\Component\Lock\Store\MemcachedStore;

final class SymfonyMemcachedLockAdapterTest extends LockAdapterTestCase
{
    private ?Memcached $memcached = null;

    protected function skipIfUnavailable(): void
    {
        if (!MemcachedStore::isSupported()) {
            $this->markTestSkipped('Memcached extension not available');
        }

        $memcached = new Memcached();
        $memcached->addServer('127.0.0.1', 11211);

        // addServer() doesn't connect, so check the server actually answers
        if (false === $memcached->getVersion()) {
            $this->markTestSkipped('Memcache server not available at 127.0.0.1:11211');
        }

        $memcached->flush();
        $this->memcached = $memcached;
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
        return new SymfonyLockAdapter(new MemcachedStore($this->memcached));
    }
}
