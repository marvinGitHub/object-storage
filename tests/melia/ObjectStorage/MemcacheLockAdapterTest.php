<?php

namespace Tests\melia\ObjectStorage;

use Memcache as Cache;
use melia\ObjectStorage\Locking\Backends\Memcache;
use melia\ObjectStorage\Locking\LockAdapterInterface;

final class MemcacheLockAdapterTest extends LockAdapterTestCase
{
    private ?Cache $memcache = null;

    protected function skipIfUnavailable(): void
    {
        $this->memcache = new Cache();
        $connected = @$this->memcache->connect('127.0.0.1', 11211);

        if (!$connected) {
            $this->markTestSkipped('Memcache server not available at 127.0.0.1:11211');
        }

        $this->memcache->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->memcache !== null) {
            $this->memcache->flush();
            $this->memcache->close();
        }
    }

    protected function createAdapter(): LockAdapterInterface
    {
        return new Memcache($this->memcache);
    }
}