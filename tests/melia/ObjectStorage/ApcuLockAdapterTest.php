<?php

namespace Tests\melia\ObjectStorage;

use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Psr16Cache;
use melia\ObjectStorage\Locking\Backends\CachedLockAdapter;
use melia\ObjectStorage\Locking\LockAdapterInterface;

final class ApcuLockAdapterTest extends LockAdapterTestCase
{
    private ?Psr16Cache $cache = null;

    /**
     */
    protected function skipIfUnavailable(): void
    {
        if (!ApcuAdapter::isSupported()) {
            $this->markTestSkipped('Apcu extension not available');
        }

        $this->cache = new Psr16Cache(new ApcuAdapter());
    }

    protected function createAdapter(): LockAdapterInterface
    {
        return new CachedLockAdapter($this->cache);
    }
}