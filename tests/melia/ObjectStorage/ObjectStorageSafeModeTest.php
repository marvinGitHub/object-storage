<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Exception\ObjectDeletionFailureException;
use melia\ObjectStorage\Exception\ObjectLoadingFailureException;
use melia\ObjectStorage\Exception\LockException;
use melia\ObjectStorage\ObjectStorage;

/**
 * Safe mode must only prevent modifications, reading has to keep working.
 */
final class ObjectStorageSafeModeTest extends TestCase
{
    private string $uuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uuid = $this->storage->store(new ChildObject('child', 1));
        $this->storage->getStateHandler()->enableSafeMode();
        $this->storage->clearCache();
    }

    public function testLoadWorksInSafeMode(): void
    {
        $this->assertSame('child', $this->storage->load($this->uuid)->title);
    }

    public function testLoadWorksInSafeModeEnabledByOtherProcess(): void
    {
        $otherProcess = new ObjectStorage($this->storage->getStorageDir());

        $this->assertTrue($otherProcess->getStateHandler()->safeModeEnabled());
        $this->assertSame('child', $otherProcess->load($this->uuid)->title);
        $this->assertFalse($otherProcess->getLockAdapter()->isLockedByThisProcess($this->uuid), 'the shared lock must be released after loading');
    }

    public function testListAndMatchWorkInSafeMode(): void
    {
        $this->assertSame([$this->uuid], iterator_to_array($this->storage->list(ChildObject::class), false));

        $matches = iterator_to_array($this->storage->match(static fn(ChildObject $object) => $object->value === 1, ChildObject::class));
        $this->assertSame([$this->uuid], array_keys($matches));
    }

    public function testStoreIsBlockedInSafeMode(): void
    {
        $this->expectExceptionMessageMatches('/Safe mode is enabled/');

        $this->storage->store(new ChildObject('other', 2));
    }

    public function testDeleteIsBlockedInSafeMode(): void
    {
        try {
            $this->storage->delete($this->uuid);
            $this->fail('delete must be blocked in safe mode');
        } catch (ObjectDeletionFailureException) {
            // expected
        }

        $this->assertTrue($this->storage->exists($this->uuid));
    }

    public function testSetLifetimeIsBlockedInSafeMode(): void
    {
        $this->expectException(LockException::class);

        $this->storage->setLifetime($this->uuid, 60);
    }

    public function testExclusiveLoadIsBlockedInSafeMode(): void
    {
        $this->expectException(ObjectLoadingFailureException::class);

        $this->storage->load($this->uuid, true);
    }

    public function testExclusiveLockIsBlockedButSharedLockIsGrantedInSafeMode(): void
    {
        $lockAdapter = $this->storage->getLockAdapter();

        $lockAdapter->acquireSharedLock($this->uuid, 0.1);
        $this->assertTrue($lockAdapter->hasActiveSharedLock($this->uuid));
        $lockAdapter->releaseLock($this->uuid);

        $this->expectException(LockException::class);
        $lockAdapter->acquireExclusiveLock($this->uuid, 0.1);
    }
}
