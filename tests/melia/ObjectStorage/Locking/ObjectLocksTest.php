<?php

namespace Tests\melia\ObjectStorage\Locking;

use melia\ObjectStorage\Locking\Backends\SymfonyLockAdapter;
use melia\ObjectStorage\Locking\LockAdapterInterface;
use melia\ObjectStorage\Locking\ObjectLocks;
use melia\ObjectStorage\Locking\RetainedLocks;
use RuntimeException;
use Tests\melia\ObjectStorage\TestCase;

class ObjectLocksTest extends TestCase
{
    private const string UUID = 'ab12cd34-0000-4000-8000-000000000001';

    private LockAdapterInterface $adapter;
    private RetainedLocks $retained;

    protected function setUp(): void
    {
        parent::setUp();
        $directory = $this->createTemporaryDirectory();
        $this->storageDirs[] = $directory;
        $this->adapter = SymfonyLockAdapter::createFileLockAdapter($directory);
        $this->retained = new RetainedLocks();
    }

    private function locks(): ObjectLocks
    {
        return new ObjectLocks($this->adapter, $this->retained);
    }

    public function testWorkIsDoneUnderTheLockTakenHere(): void
    {
        $result = $this->locks()->exclusively(self::UUID, fn(): bool => $this->adapter->hasActiveExclusiveLock(self::UUID));

        $this->assertTrue($result, 'locked while working');
        $this->assertFalse($this->adapter->isLockedByThisProcess(self::UUID), 'released afterwards');
    }

    public function testALockTakenHereIsReleasedOnFailure(): void
    {
        try {
            $this->locks()->exclusively(self::UUID, static fn() => throw new RuntimeException('failed'));
        } catch (RuntimeException) {
            // the failure is passed on
        }

        $this->assertFalse($this->adapter->isLockedByThisProcess(self::UUID));
    }

    public function testALockHeldBeforeIsKept(): void
    {
        $this->adapter->acquireExclusiveLock(self::UUID);

        $this->locks()->exclusively(self::UUID, static fn() => null);

        $this->assertTrue($this->adapter->hasActiveExclusiveLock(self::UUID), 'it belongs to the caller');
    }

    public function testALockRetainedWhileWorkingIsKept(): void
    {
        $locks = $this->locks();

        // a transaction records the object while it is written, under the lock taken here
        $locks->exclusively(self::UUID, fn() => $locks->retain(self::UUID));

        $this->assertTrue($this->adapter->hasActiveExclusiveLock(self::UUID));
        $locks->releaseUnlessRetained(self::UUID);
        $this->assertTrue($this->adapter->hasActiveExclusiveLock(self::UUID), 'retained');

        $locks->releaseRetained(self::UUID);
        $this->assertFalse($this->adapter->isLockedByThisProcess(self::UUID));
        $this->assertFalse($locks->isRetained(self::UUID));
    }

    public function testRetainedLocksSurviveANewLockAdapter(): void
    {
        $this->locks()->retain(self::UUID);

        $this->assertTrue((new ObjectLocks(null, $this->retained))->isRetained(self::UUID));
    }

    public function testWithoutLockingTheWorkIsDoneAllTheSame(): void
    {
        $this->assertSame('done', $this->locks()->exclusively(self::UUID, fn(): string => $this->adapter->isLockedByThisProcess(self::UUID) ? 'locked' : 'done', lock: false));
        $this->assertSame('done', (new ObjectLocks(null, $this->retained))->exclusively(self::UUID, static fn(): string => 'done'));
    }

    public function testSharedLocks(): void
    {
        $this->assertTrue($this->locks()->shared(self::UUID, fn(): bool => $this->adapter->hasActiveSharedLock(self::UUID)));
        $this->assertFalse($this->adapter->isLockedByThisProcess(self::UUID));

        $this->adapter->acquireExclusiveLock(self::UUID);
        $this->assertFalse($this->locks()->shared(self::UUID, fn(): bool => $this->adapter->hasActiveSharedLock(self::UUID)), 'under the lock held');
        $this->assertTrue($this->adapter->hasActiveExclusiveLock(self::UUID));
    }
}
