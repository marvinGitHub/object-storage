<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Exception\LockException;
use PHPUnit\Framework\TestCase;
use melia\ObjectStorage\Locking\LockAdapterInterface;

abstract class LockAdapterTestCase extends TestCase
{
    abstract protected function createAdapter(): LockAdapterInterface;

    abstract protected function skipIfUnavailable(): void;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfUnavailable();
    }

    public function testAcquireSharedLockWithIntegerTimeout(): void
    {
        $uuid = 'uuid-int-shared';
        $adapter = $this->createAdapter();

        $adapter->acquireSharedLock($uuid, 2);

        $this->assertTrue($adapter->hasActiveSharedLock($uuid));
        $this->assertFalse($adapter->hasActiveExclusiveLock($uuid));
        $this->assertTrue($adapter->isLockedByThisProcess($uuid));
        $this->assertFalse($adapter->isLockedByOtherProcess($uuid));

        $adapter->releaseLock($uuid);
        $this->assertFalse($adapter->isLockedByThisProcess($uuid));
    }

    public function testAcquireExclusiveLockWithFloatTimeout(): void
    {
        $uuid = 'uuid-float-exclusive';
        $adapter = $this->createAdapter();

        $adapter->acquireExclusiveLock($uuid, 1.5);

        $this->assertTrue($adapter->hasActiveExclusiveLock($uuid));
        $this->assertFalse($adapter->hasActiveSharedLock($uuid));
        $this->assertTrue($adapter->isLockedByThisProcess($uuid));
        $this->assertFalse($adapter->isLockedByOtherProcess($uuid));

        $adapter->releaseLock($uuid);
        $this->assertFalse($adapter->isLockedByThisProcess($uuid));
    }

    public function testTimeoutElapsesWithVerySmallFloat(): void
    {
        $uuid = 'uuid-timeout';
        $adapter1 = $this->createAdapter();
        $adapter2 = $this->createAdapter();

        $adapter1->acquireExclusiveLock($uuid, 1);

        $this->expectException(LockException::class);
        $this->expectExceptionMessageMatches('/Timeout while waiting for lock/');

        $adapter2->acquireExclusiveLock($uuid, 0.15);

        $adapter1->releaseLock($uuid);
    }
}