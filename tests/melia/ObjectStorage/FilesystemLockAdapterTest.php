<?php

declare(strict_types=1);

namespace Tests\melia\ObjectStorage\Locking;

use melia\ObjectStorage\Locking\Backends\FileSystem;
use melia\ObjectStorage\Exception\LockException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests that locking works with mixed int|float timeout values.
 */
final class FilesystemLockAdapterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'objstore_lock_tests_' . uniqid('', true);
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Best-effort cleanup
        if (is_dir($this->tmpDir)) {
            $files = glob($this->tmpDir . DIRECTORY_SEPARATOR . '*');
            if (is_array($files)) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($this->tmpDir);
        }
    }

    public function testAcquireSharedLockWithIntegerTimeout(): void
    {
        $uuid = 'uuid-int-shared';
        $adapter = $this->newFileSystemAdapter($this->tmpDir);

        // integer timeout
        $adapter->acquireSharedLock($uuid, 2);

        $this->assertTrue($adapter->hasActiveSharedLock($uuid));
        $this->assertFalse($adapter->hasActiveExclusiveLock($uuid));
        $this->assertTrue($adapter->isLockedByThisProcess($uuid));
        $this->assertFalse($adapter->isLockedByOtherProcess($uuid));

        // release
        $adapter->releaseLock($uuid);
        $this->assertFalse($adapter->isLockedByThisProcess($uuid));
    }

    public function testAcquireExclusiveLockWithFloatTimeout(): void
    {
        $uuid = 'uuid-float-exclusive';
        $adapter = $this->newFileSystemAdapter($this->tmpDir);

        // float timeout
        $adapter->acquireExclusiveLock($uuid, 1.5);

        $this->assertTrue($adapter->hasActiveExclusiveLock($uuid));
        $this->assertFalse($adapter->hasActiveSharedLock($uuid));
        $this->assertTrue($adapter->isLockedByThisProcess($uuid));
        $this->assertFalse($adapter->isLockedByOtherProcess($uuid));

        // release
        $adapter->releaseLock($uuid);
        $this->assertFalse($adapter->isLockedByThisProcess($uuid));
    }

    public function testTimeoutElapsesWithVerySmallFloat(): void
    {
        $uuid = 'uuid-timeout';
        $adapter1 = $this->newFileSystemAdapter($this->tmpDir);
        $adapter2 = $this->newFileSystemAdapter($this->tmpDir);

        // First adapter acquires an exclusive lock and holds it
        $adapter1->acquireExclusiveLock($uuid, 1);

        // Second adapter attempts to acquire with a very small float timeout; should time out
        $this->expectException(LockException::class);
        $adapter2->acquireExclusiveLock($uuid, 0.15); // 150ms
    }

    private function newFileSystemAdapter(string $dir): FileSystem
    {
        // FileSystem requires a lock directory at construction
        $adapter = new FileSystem($dir);

        // Ensure state handler safe mode is not enabled by stubbing if necessary.
        // If FileSystem depends on state/writer/logger accessors from its parent traits/abstracts,
        // they are optional for these tests because the happy path doesn’t require them to be set.
        // We only need to guarantee the lock directory exists.
        $this->assertDirectoryExists($dir);

        // In case the lock dir is private inside the adapter, ensure it is set via public setter if available.
        // Here we verify with reflection that the property was set to our $dir.
        $ref = new ReflectionClass($adapter);
        $prop = $ref->getProperty('lockDir');
        $prop->setAccessible(true);
        $this->assertSame($dir, $prop->getValue($adapter));

        return $adapter;
    }
}