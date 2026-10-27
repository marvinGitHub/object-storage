<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Locking\Backends\SymfonyLockAdapter;
use melia\ObjectStorage\Locking\LockAdapterInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Covers the adapter with a non-file store; adapters sharing one store behave like separate processes.
 */
final class SymfonyInMemoryLockAdapterTest extends LockAdapterTestCase
{
    private InMemoryStore $store;

    protected function skipIfUnavailable(): void
    {
        $this->store = new InMemoryStore();
    }

    protected function createAdapter(): LockAdapterInterface
    {
        return new SymfonyLockAdapter($this->store);
    }

    public function testIsLockedByOtherProcess(): void
    {
        $uuid = 'uuid-other-process';
        $adapter1 = $this->createAdapter();
        $adapter2 = $this->createAdapter();

        $adapter1->acquireExclusiveLock($uuid, 1);
        $this->assertTrue($adapter2->isLockedByOtherProcess($uuid));
        $this->assertFalse($adapter1->isLockedByOtherProcess($uuid));

        $adapter1->releaseLock($uuid);
        $this->assertFalse($adapter2->isLockedByOtherProcess($uuid));
    }

    public function testStoragesWithDifferentPrefixesDoNotShareLocks(): void
    {
        $uuid = 'uuid-prefix';
        $adapter1 = new SymfonyLockAdapter($this->store, prefix: 'storage-a.');
        $adapter2 = new SymfonyLockAdapter($this->store, prefix: 'storage-b.');

        $adapter1->acquireExclusiveLock($uuid, 1);
        $adapter2->acquireExclusiveLock($uuid, 0.15);

        $this->assertTrue($adapter2->hasActiveExclusiveLock($uuid));

        $adapter1->releaseLock($uuid);
        $adapter2->releaseLock($uuid);
    }
}
