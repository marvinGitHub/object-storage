<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Exception\ChecksumMismatchException;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\Exception\ObjectLoadingFailureException;
use melia\ObjectStorage\File\Writer;
use melia\ObjectStorage\ObjectStorage;
use RuntimeException;
use Throwable;

/**
 * Data and metadata live in two files, written as a group by the writer. These tests interrupt that write the way
 * a crash (process killed, power loss) would, and make sure the storage recovers instead of enabling safe mode.
 */
final class ObjectStorageCrashRecoveryTest extends TestCase
{
    /**
     * Stores the object with a writer that "crashes" (throws without cleaning up) at the given commit:
     * 1 = before anything is committed, 2 = after the data, before the metadata is committed.
     */
    private function storeAndCrashAtCommit(object $object, ?string $uuid, int $crashAt): void
    {
        $crashingWriter = new class ($crashAt) extends Writer {
            private int $commits = 0;

            public function __construct(private readonly int $crashAt)
            {
                parent::__construct();
            }

            protected function commit(string $filename): void
            {
                if (++$this->commits === $this->crashAt) {
                    throw new RuntimeException('simulated crash');
                }
                parent::commit($filename);
            }
        };

        $crashingStorage = new ObjectStorage($this->storage->getStorageDir());
        $crashingStorage->setWriter($crashingWriter);

        try {
            $crashingStorage->store($object, $uuid);
            $this->fail('the simulated crash must interrupt the store');
        } catch (Throwable) {
            // expected
        }
    }

    private function restart(): ObjectStorage
    {
        return new ObjectStorage($this->storage->getStorageDir());
    }

    private function assertNoPendingFiles(string $uuid): void
    {
        $this->assertFileDoesNotExist($this->storage->getFilePathData($uuid) . Writer::SUFFIX_PENDING);
        $this->assertFileDoesNotExist($this->storage->getFilePathMetadata($uuid) . Writer::SUFFIX_PENDING);
    }

    public function testUpdateInterruptedBetweenCommitsIsRecovered(): void
    {
        $uuid = $this->storage->store(new ChildObject('before', 1));

        $this->storeAndCrashAtCommit(new ChildObject('after', 2), $uuid, 2);

        $afterRestart = $this->restart();
        $this->assertSame('after', $afterRestart->load($uuid)->title);
        $this->assertFalse($afterRestart->getStateHandler()->safeModeEnabled());

        // the recovery completed the write on disk, not only in memory
        $this->assertNoPendingFiles($uuid);
        $this->assertSame('after', $this->restart()->load($uuid)->title);
    }

    public function testFirstStoreInterruptedBetweenCommitsIsRecovered(): void
    {
        $uuid = $this->storage->getNextAvailableUuid();

        $this->storeAndCrashAtCommit(new ChildObject('new', 1), $uuid, 2);
        $this->assertFileDoesNotExist($this->storage->getFilePathMetadata($uuid));

        $afterRestart = $this->restart();
        $this->assertSame('new', $afterRestart->load($uuid)->title);
        $this->assertFalse($afterRestart->getStateHandler()->safeModeEnabled());
        $this->assertFileExists($this->storage->getFilePathMetadata($uuid));
    }

    public function testUpdateInterruptedBeforeAnyCommitKeepsPreviousState(): void
    {
        $uuid = $this->storage->store(new ChildObject('before', 1));

        $this->storeAndCrashAtCommit(new ChildObject('after', 2), $uuid, 1);

        $afterRestart = $this->restart();
        $this->assertSame('before', $afterRestart->load($uuid)->title);
        $this->assertFalse($afterRestart->getStateHandler()->safeModeEnabled());

        // a later store overwrites the leftovers and commits both files
        $afterRestart->store(new ChildObject('later', 3), $uuid);
        $this->assertNoPendingFiles($uuid);
        $this->assertSame('later', $this->restart()->load($uuid)->title);
    }

    public function testFailedWriteKeepsPreviousStateWithoutLeftovers(): void
    {
        $uuid = $this->storage->store(new ChildObject('before', 1));

        $failingWriter = new class extends Writer {
            protected function writePending(string $filename, ?string $data = null): void
            {
                if (str_ends_with($filename, '.metadata')) {
                    throw new IOException('simulated write failure');
                }
                parent::writePending($filename, $data);
            }
        };

        $failingStorage = $this->restart();
        $failingStorage->setWriter($failingWriter);

        try {
            $failingStorage->store(new ChildObject('after', 2), $uuid);
            $this->fail('the write failure must interrupt the store');
        } catch (Throwable) {
            // expected
        }

        $this->assertNoPendingFiles($uuid);
        $this->assertSame('before', $this->restart()->load($uuid)->title);
    }

    public function testCorruptedDataStillEnablesSafeModeDespiteInterruptedWrite(): void
    {
        $uuid = $this->storage->store(new ChildObject('before', 1));
        $this->storeAndCrashAtCommit(new ChildObject('after', 2), $uuid, 2);

        file_put_contents($this->storage->getFilePathData($uuid), json_encode(['title' => 'tampered', 'value' => 3]));

        $afterRestart = $this->restart();

        try {
            $afterRestart->load($uuid);
            $this->fail('loading corrupted data must fail');
        } catch (ObjectLoadingFailureException $e) {
            $this->assertInstanceOf(ChecksumMismatchException::class, $e->getPrevious());
        }

        $this->assertTrue($afterRestart->getStateHandler()->safeModeEnabled());
    }

    public function testSuccessfulStoreLeavesNoPendingFiles(): void
    {
        $object = new ChildObject('before', 1);
        $uuid = $this->storage->store($object);

        $object->title = 'after';
        $this->storage->store($object);

        $this->assertNoPendingFiles($uuid);
        $this->assertFileExists($this->storage->getFilePathMetadata($uuid));
        $this->assertFileExists($this->storage->getFilePathData($uuid));
    }

    public function testDeleteRemovesLeftoversOfInterruptedWrite(): void
    {
        $uuid = $this->storage->store(new ChildObject('before', 1));
        $this->storeAndCrashAtCommit(new ChildObject('after', 2), $uuid, 2);

        $this->restart()->delete($uuid);

        $this->assertNoPendingFiles($uuid);
        $this->assertFileDoesNotExist($this->storage->getFilePathData($uuid));
        $this->assertFileDoesNotExist($this->storage->getFilePathMetadata($uuid), 'leftovers must not be committed after deleting');
    }
}
