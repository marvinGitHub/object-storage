<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Exception\ObjectNotFoundException;
use melia\ObjectStorage\Exception\TransactionAlreadyActiveException;
use melia\ObjectStorage\Exception\TransactionException;
use melia\ObjectStorage\Exception\TransactionNotActiveException;
use melia\ObjectStorage\Locking\LockAdapterInterface;
use melia\ObjectStorage\Logger\LoggerInterface;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\State\StateHandler;
use melia\ObjectStorage\Transaction;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Transaction unit tests covering begin, store/load/delete, commit, rollback and state transitions.
 */
class TransactionTest extends TestCase
{
    /** @var LockAdapterInterface&MockObject */
    private LockAdapterInterface $lockAdapter;

    /** @var StateHandler&MockObject */
    private StateHandler $stateHandler;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(ObjectStorage::class);
        $this->lockAdapter = $this->createMock(LockAdapterInterface::class);
        $this->stateHandler = $this->createMock(StateHandler::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->storage->method('getLockAdapter')->willReturn($this->lockAdapter);
        $this->storage->method('getStateHandler')->willReturn($this->stateHandler);
        $this->storage->method('getLogger')->willReturn($this->logger);
        $this->storage->method('getStorageDir')->willReturn(sys_get_temp_dir());

        // Default: lock adapter reports not locked by this process unless we lock in test
        $this->lockAdapter->method('isLockedByThisProcess')->willReturn(true);
    }

    public function testBeginSetsActiveAndCreatesLog(): void
    {
        $txn = new Transaction($this->storage);
        $this->stateHandler->expects($this->once())->method('safeModeEnabled')->willReturn(false);

        $txn->begin();

        $this->assertTrue($txn->isActive());
        $this->assertFalse($txn->isCommitted());
        $this->assertFalse($txn->isRolledBack());
        $this->assertNotEmpty($txn->getTransactionId());
    }

    public function testBeginThrowsWhenAlreadyActive(): void
    {
        $txn = new Transaction($this->storage);
        $txn->begin();

        $this->expectException(TransactionAlreadyActiveException::class);
        $txn->begin();
    }

    public function testBeginThrowsWhenSafeMode(): void
    {
        $this->stateHandler->method('safeModeEnabled')->willReturn(true);

        $txn = new Transaction($this->storage);

        $this->expectException(TransactionException::class);
        $txn->begin();
    }

    public function testStoreThenLoadWithinSameTransactionReturnsPendingObject(): void
    {
        $txn = new Transaction($this->storage);
        $txn->begin();

        $obj = (object)['x' => 1];
        $uuid = 'u-1';

        // exists -> false, so no backup; lock is required
        $this->storage->method('exists')->with($uuid)->willReturn(false);

        // Expect lock
        $this->lockAdapter->expects($this->once())
            ->method('acquireExclusiveLock')
            ->with($uuid, $this->anything());

        // Outside storage load should not be called because pending store should be returned
        $this->storage->expects($this->never())->method('load');

        $txn->store($obj, $uuid);

        $loaded = $txn->load($uuid);
        $this->assertEquals($obj, $loaded);
        $this->assertSame(1, $txn->getOperationCount());
    }

    public function testDeleteQueuesOperationAndPreventsLoadInTransaction(): void
    {
        $txn = new Transaction($this->storage);
        $txn->begin();

        $uuid = 'u-2';

        $this->storage->method('exists')->with($uuid)->willReturn(true);
        $this->storage->method('load')->with($uuid)->willReturn((object)['y' => 2]);

        // Expect lock
        $this->lockAdapter->expects($this->once())
            ->method('acquireExclusiveLock')
            ->with($uuid, $this->anything());

        $this->assertTrue($txn->delete($uuid));

        // After scheduling delete, load should return null (pending delete)
        $this->assertNull($txn->load($uuid));
        $this->assertSame(1, $txn->getOperationCount());
    }

    public function testDeleteThrowsIfObjectNotFound(): void
    {
        $txn = new Transaction($this->storage);
        $txn->begin();

        $uuid = 'u-missing';
        $this->storage->method('exists')->with($uuid)->willReturn(false);

        $this->expectException(ObjectNotFoundException::class);
        $txn->delete($uuid);
    }

    public function testCommitExecutesOperationsAndCleansUp(): void
    {
        $txn = new Transaction($this->storage);
        $txn->begin();

        $uuid1 = 'u-store';
        $uuid2 = 'u-del';

        $obj = (object)['a' => 10];

        // store path: no existing object
        $this->storage->method('exists')->willReturnMap([
            [$uuid1, false],
            [$uuid2, true],
        ]);
        $this->storage->method('load')->with($uuid2)->willReturn((object)['old' => 'data']);

        // locks
        $this->lockAdapter->expects($this->exactly(2))
            ->method('acquireExclusiveLock')
            ->withConsecutive([$uuid1, $this->anything()], [$uuid2, $this->anything()]);

        // Expect store and delete on commit
        $this->storage->expects($this->once())->method('store')->with($this->callback(function ($o) use ($obj) {
            return is_object($o) && (array)$o === (array)$obj;
        }), $uuid1);

        $this->storage->expects($this->once())->method('delete')->with($uuid2);

        // Expected releaseLock during cleanup
        $this->lockAdapter->expects($this->exactly(2))->method('releaseLock')->withConsecutive([$uuid1], [$uuid2]);

        $txn->store($obj, $uuid1);
        $txn->delete($uuid2);

        $this->assertTrue($txn->commit());
        $this->assertTrue($txn->isCommitted());
        $this->assertFalse($txn->isActive());
        $this->assertSame(0, $txn->getOperationCount());
    }

    public function testRollbackRestoresBackupsAndDeletesNewObjects(): void
    {
        $txn = new Transaction($this->storage);
        $txn->begin();

        $uuidNew = 'u-new';
        $uuidExisting = 'u-existing';

        $newObj = (object)['n' => 1];
        $existingObj = (object)['e' => 2];

        // Exists matrix
        $this->storage->method('exists')->willReturnMap([
            [$uuidNew, false],
            [$uuidExisting, true],
        ]);

        // Backup for existing
        $this->storage->method('load')->with($uuidExisting)->willReturn(clone $existingObj);

        // Locks for both
        $this->lockAdapter->expects($this->exactly(2))->method('acquireExclusiveLock');

        // When rolling back:
        // - store of new -> should delete if exists
        // - store of existing -> should restore backup via store
        // We'll simulate that the new object would exist at rollback time by returning true for exists check
        $this->storage->method('exists')->willReturnCallback(function (string $id) use ($uuidNew, $uuidExisting) {
            // Before rollback we said new doesn't exist; during rollback path for 'new' branch checks exists again.
            // Return true for $uuidNew to trigger delete on rollback path.
            return $id === $uuidNew ? true : ($id === $uuidExisting);
        });

        // Expect delete of the new object during rollback
        $this->storage->expects($this->once())->method('delete')->with($uuidNew, true);

        // Expect restoring backup of existing object
        $this->storage->expects($this->once())->method('store')->with(
            $this->callback(fn($o) => is_object($o) && (array)$o === (array)$existingObj),
            $uuidExisting
        );

        // Expected releaseLock during cleanup
        $this->lockAdapter->expects($this->exactly(2))->method('releaseLock');

        // Queue operations
        $txn->store($newObj, $uuidNew);        // no backup, treated as brand new
        $txn->store($existingObj, $uuidExisting); // backup captured

        $this->assertTrue($txn->rollback());
        $this->assertTrue($txn->isRolledBack());
        $this->assertFalse($txn->isActive());
        $this->assertSame(0, $txn->getOperationCount());
    }

    public function testOperationsRequireActiveTransaction(): void
    {
        $txn = new Transaction($this->storage);

        $this->expectException(TransactionNotActiveException::class);
        $txn->store((object)['a' => 1], 'x');
    }

    public function testCommitRequiresActiveTransaction(): void
    {
        $txn = new Transaction($this->storage);

        $this->expectException(TransactionNotActiveException::class);
        $txn->commit();
    }

    public function testRollbackWithoutActiveOrCommittedThrows(): void
    {
        $txn = new Transaction($this->storage);

        $this->expectException(TransactionException::class);
        $txn->rollback();
    }
}