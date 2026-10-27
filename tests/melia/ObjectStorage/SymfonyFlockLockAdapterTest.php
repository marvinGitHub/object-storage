<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Exception\LockException;
use melia\ObjectStorage\Locking\Backends\SymfonyLockAdapter;
use melia\ObjectStorage\Locking\LockAdapterInterface;

final class SymfonyFlockLockAdapterTest extends LockAdapterTestCase
{
    private string $tmpDir;

    protected function skipIfUnavailable(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'objstore_symfony_lock_tests_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
    }

    protected function createAdapter(): LockAdapterInterface
    {
        return SymfonyLockAdapter::createFileLockAdapter($this->tmpDir);
    }

    public function testCreatesLockDirectory(): void
    {
        $this->createAdapter();

        $this->assertDirectoryExists($this->tmpDir);
    }

    public function testIsLockedByOtherProcessReflectsActualLockState(): void
    {
        $uuid = 'uuid-other-process';
        $adapter1 = $this->createAdapter();
        $adapter2 = $this->createAdapter();

        $adapter1->acquireExclusiveLock($uuid, 1);
        $this->assertTrue($adapter2->isLockedByOtherProcess($uuid));

        $adapter1->releaseLock($uuid);
        $this->assertFalse($adapter2->isLockedByOtherProcess($uuid), 'a remaining lock file must not count as a lock');

        $adapter1->acquireSharedLock($uuid, 1);
        $this->assertTrue($adapter2->isLockedByOtherProcess($uuid));
        $adapter1->releaseLock($uuid);
    }

    public function testSharedLocksOfDifferentProcessesDoNotBlockEachOther(): void
    {
        $uuid = 'uuid-shared-shared';
        $adapter1 = $this->createAdapter();
        $adapter2 = $this->createAdapter();

        $adapter1->acquireSharedLock($uuid, 1);
        $adapter2->acquireSharedLock($uuid, 0.15);

        $this->assertTrue($adapter2->hasActiveSharedLock($uuid));

        $adapter1->releaseLock($uuid);
        $adapter2->releaseLock($uuid);
    }

    public function testSharedLockCanBePromotedToExclusiveLock(): void
    {
        $uuid = 'uuid-promotion';
        $adapter1 = $this->createAdapter();
        $adapter2 = $this->createAdapter();

        $adapter1->acquireSharedLock($uuid, 1);
        $adapter1->acquireExclusiveLock($uuid, 0.15);

        $this->assertTrue($adapter1->hasActiveExclusiveLock($uuid));
        $this->assertTrue($adapter2->isLockedByOtherProcess($uuid));

        $adapter1->releaseLock($uuid);
        $this->assertFalse($adapter2->isLockedByOtherProcess($uuid));
    }

    public function testFailedPromotionDoesNotReportStaleSharedLock(): void
    {
        $uuid = 'uuid-failed-promotion';
        $adapter1 = $this->createAdapter();
        $adapter2 = $this->createAdapter();

        $adapter1->acquireSharedLock($uuid, 1);
        $adapter2->acquireSharedLock($uuid, 1);

        try {
            $adapter1->acquireExclusiveLock($uuid, 0.1);
            $this->fail('promotion must time out while another process holds a shared lock');
        } catch (LockException) {
            // expected
        }

        $this->assertFalse($adapter1->hasActiveExclusiveLock($uuid));
        if ($adapter1->isLockedByThisProcess($uuid)) {
            $this->assertTrue($adapter1->hasActiveSharedLock($uuid));
            $adapter1->releaseLock($uuid);
        }
        $adapter2->releaseLock($uuid);
    }
}
