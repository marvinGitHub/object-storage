<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Event\Context\ObjectPersistenceContext;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\ObjectNotFoundException;
use melia\ObjectStorage\Exception\TransactionAlreadyActiveException;
use melia\ObjectStorage\Exception\TransactionCommitException;
use melia\ObjectStorage\Exception\TransactionException;
use melia\ObjectStorage\Exception\TransactionLockException;
use melia\ObjectStorage\Exception\TransactionNotActiveException;
use melia\ObjectStorage\Journal\Journal;
use melia\ObjectStorage\Logger\LoggerInterface;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Strategy\Policy\ChildDeletion;
use melia\ObjectStorage\Transaction;
use melia\ObjectStorage\TransactionRecovery;
use ReflectionException;
use Tests\melia\ObjectStorage\Search\Category;
use Tests\melia\ObjectStorage\Search\Product;
use RuntimeException;
use Throwable;

/**
 * Transactions on a real storage: begin, store/load/delete, commit, rollback, locks and the journal.
 */
class TransactionTest extends TestCase
{
    /**
     * A second storage on the same directory whose store() fails for the UUID set in $failFor.
     */
    private function failingStorage(): FailingStoreStorage
    {
        return new FailingStoreStorage($this->storage->getStorageDir());
    }

    private function entryTypes(Transaction $transaction): array
    {
        $types = [];
        foreach ((new Journal($transaction->getJournalDirectory()))->read() as $entry) {
            $types[] = $entry->type;
        }

        return $types;
    }

    public function testBeginStartsTheJournal(): void
    {
        $txn = (new Transaction($this->storage))->begin();

        $this->assertTrue($txn->isActive());
        $this->assertFalse($txn->isCommitted());
        $this->assertFalse($txn->isRolledBack());
        $this->assertStringStartsWith('txn_', $txn->getTransactionId());
        $this->assertSame(
            $this->storage->getStorageDir() . DIRECTORY_SEPARATOR . TransactionRecovery::DIRECTORY . DIRECTORY_SEPARATOR . $txn->getTransactionId(),
            $txn->getJournalDirectory(),
        );
        $this->assertSame([TransactionRecovery::ENTRY_BEGIN], $this->entryTypes($txn));

        $txn->rollback();
    }

    public function testBeginThrowsWhenAlreadyActive(): void
    {
        $txn = (new Transaction($this->storage))->begin();

        try {
            $this->expectException(TransactionAlreadyActiveException::class);
            $txn->begin();
        } finally {
            $txn->rollback();
        }
    }

    public function testBeginThrowsInSafeMode(): void
    {
        $this->storage->getStateHandler()->enableSafeMode();

        $this->expectException(TransactionException::class);
        (new Transaction($this->storage))->begin();
    }

    public function testStoreThenLoadWithinTheTransactionReturnsThePendingObject(): void
    {
        $txn = (new Transaction($this->storage))->begin();

        $uuid = $txn->store((object)['x' => 1]);

        $this->assertEquals((object)['x' => 1], $txn->load($uuid));
        $this->assertFalse($this->storage->exists($uuid), 'nothing is written before the commit');
        $this->assertSame(1, $txn->getOperationCount());

        $txn->rollback();
    }

    public function testDeleteWithinTheTransactionHidesTheObjectAndKeepsItUntilTheCommit(): void
    {
        $uuid = $this->storage->store((object)['y' => 2]);
        $txn = (new Transaction($this->storage))->begin();

        $this->assertTrue($txn->delete($uuid));

        $this->assertNull($txn->load($uuid));
        $this->assertTrue($this->storage->exists($uuid));
        $this->assertSame(1, $txn->getOperationCount());

        $txn->commit();
        $this->assertFalse($this->storage->exists($uuid));
    }

    public function testStoringAgainAfterDeletingInTheTransactionLoadsTheStoredObject(): void
    {
        $uuid = $this->storage->store((object)['v' => 1]);
        $txn = (new Transaction($this->storage))->begin();

        $txn->delete($uuid);
        $txn->store((object)['v' => 2], $uuid);

        $this->assertEquals((object)['v' => 2], $txn->load($uuid));
        $txn->rollback();
    }

    public function testDeleteThrowsIfTheObjectDoesntExist(): void
    {
        $txn = (new Transaction($this->storage))->begin();

        try {
            $this->expectException(ObjectNotFoundException::class);
            $txn->delete('6f9619ff-8b86-4011-b42d-00c04fc964ff');
        } finally {
            $txn->rollback();
        }
    }

    public function testCommitWritesAllChangesAndRemovesTheJournal(): void
    {
        $existing = $this->storage->store((object)['name' => 'old']);
        $deleted = $this->storage->store((object)['name' => 'gone']);

        $txn = (new Transaction($this->storage))->begin();
        $new = $txn->store((object)['name' => 'new']);
        $txn->store((object)['name' => 'changed'], $existing);
        $txn->delete($deleted);
        $journal = $txn->getJournalDirectory();

        $this->assertTrue($txn->commit());

        $this->assertTrue($txn->isCommitted());
        $this->assertFalse($txn->isActive());
        $this->assertSame(0, $txn->getOperationCount());
        $this->assertDirectoryDoesNotExist($journal);

        $this->storage->clearCache();
        $this->assertSame('new', $this->storage->load($new)->name);
        $this->assertSame('changed', $this->storage->load($existing)->name);
        $this->assertFalse($this->storage->exists($deleted));
    }

    public function testCommitReleasesAllLocks(): void
    {
        $uuid = $this->storage->store((object)['a' => 1]);
        $txn = (new Transaction($this->storage))->begin();
        $txn->store((object)['a' => 2], $uuid);

        $this->assertTrue($this->storage->getLockAdapter()->isLockedByThisProcess($uuid));
        $txn->commit();

        $this->assertFalse($this->storage->getLockAdapter()->isLockedByThisProcess($uuid));
        $this->assertFalse($this->storage->isLockRetained($uuid));
        $this->assertFalse($this->storage->getLockAdapter()->isLockedByThisProcess(TransactionRecovery::lockUuid($txn->getTransactionId())));
    }

    public function testTheObjectsStayLockedWhileTheCommitWritesTheOthers(): void
    {
        $first = $this->storage->store((object)['n' => 1]);
        $second = $this->storage->store((object)['n' => 2]);

        $txn = (new Transaction($this->storage))->begin();
        $txn->store((object)['n' => 10], $first);
        $txn->store((object)['n' => 20], $second);

        $lockedWhileWritingSecond = null;
        $this->storage->getEventDispatcher()->addListener(Events::OBJECT_SAVED, function (ObjectPersistenceContext $context) use ($first, $second, &$lockedWhileWritingSecond) {
            if ($context->getUUID() === $second) {
                $lockedWhileWritingSecond = $this->storage->getLockAdapter()->isLockedByThisProcess($first);
            }
        });

        $txn->commit();

        $this->assertTrue($lockedWhileWritingSecond, 'store() of the first object must not release the lock of the transaction');
    }

    public function testRollbackBeforeCommitWritesNothing(): void
    {
        $existing = $this->storage->store((object)['name' => 'old']);
        $revision = $this->storage->getRevision($existing);

        $txn = (new Transaction($this->storage))->begin();
        $new = $txn->store((object)['name' => 'new']);
        $txn->store((object)['name' => 'changed'], $existing);
        $journal = $txn->getJournalDirectory();

        $writesBefore = $this->writerSpy->getAtomicWriteCallCount();
        $this->assertTrue($txn->rollback());

        $this->assertSame($writesBefore, $this->writerSpy->getAtomicWriteCallCount(), 'nothing was written, so nothing is restored');
        $this->assertTrue($txn->isRolledBack());
        $this->assertFalse($txn->isActive());
        $this->assertSame(0, $txn->getOperationCount());
        $this->assertDirectoryDoesNotExist($journal);
        $this->assertFalse($this->storage->exists($new));
        $this->assertSame($revision, $this->storage->getRevision($existing));
        $this->assertFalse($this->storage->getLockAdapter()->isLockedByThisProcess($existing));
    }

    public function testAFailedCommitRestoresTheObjectsExactly(): void
    {
        $existing = $this->storage->store(new ChildObject('before', 1));
        $this->storage->setLifetime($existing, 3600);
        $before = $this->storage->readRaw($existing);

        $storage = $this->failingStorage();
        $txn = (new Transaction($storage))->begin();
        $new = $txn->store((object)['name' => 'new']);
        $txn->store(new ChildObject('after', 2), $existing);
        // the third write fails after the first two were written
        $failingUuid = $txn->store((object)['name' => 'fails']);
        $storage->failFor = $failingUuid;

        try {
            $txn->commit();
            $this->fail('the commit must fail');
        } catch (TransactionCommitException) {
            // expected
        }

        $this->assertTrue($txn->isRolledBack());
        $this->assertFalse($this->storage->exists($new));
        $this->assertFalse($this->storage->exists($failingUuid));

        $after = $this->storage->readRaw($existing);
        $this->assertSame($before->data, $after->data);
        $this->assertSame($before->metadata->getRevision(), $after->metadata->getRevision());
        $this->assertSame($before->metadata->getChecksum(), $after->metadata->getChecksum());
        $this->assertSame($before->metadata->getTimestampExpiresAt(), $after->metadata->getTimestampExpiresAt());
        $this->storage->clearCache();
        $this->assertSame('before', $this->storage->load($existing)->title);
    }

    public function testTheStateBeforeIsReadFromTheFilesNotFromTheCache(): void
    {
        $uuid = $this->storage->store((object)['v' => 'cached']);

        // the storage of the transaction caches the object; another process stores it meanwhile
        $storage = $this->failingStorage();
        $this->assertSame('cached', $storage->load($uuid)->v);
        $other = new ObjectStorage($this->storage->getStorageDir());
        $other->store((object)['v' => 'other process'], $uuid);
        unset($other);

        $txn = (new Transaction($storage))->begin();
        $txn->store((object)['v' => 'transaction'], $uuid);
        $storage->failFor = $txn->store((object)['v' => 'fails']);

        try {
            $txn->commit();
        } catch (TransactionCommitException) {
            // expected
        }

        $this->storage->clearCache();
        $this->assertSame('other process', $this->storage->load($uuid)->v);
    }

    public function testACommitFailingWithACoreExceptionIsRolledBackAndWrapped(): void
    {
        $storage = new class ($this->storage->getStorageDir()) extends ObjectStorage {
            public function store(object $object, ?string $uuid = null, ?int $ttl = null): string
            {
                throw new ReflectionException('simulated non-library failure during store()');
            }
        };

        $txn = (new Transaction($storage))->begin();
        $uuid = $txn->store((object)['a' => 1]);

        try {
            $txn->commit();
            $this->fail('Expected TransactionCommitException was not thrown');
        } catch (TransactionCommitException $e) {
            $this->assertInstanceOf(ReflectionException::class, $e->getPrevious());
        }

        $this->assertFalse($txn->isActive());
        $this->assertTrue($txn->isRolledBack());
        $this->assertFalse($storage->getLockAdapter()->isLockedByThisProcess($uuid));
    }

    public function testOperationsRequireAnActiveTransaction(): void
    {
        $this->expectException(TransactionNotActiveException::class);
        (new Transaction($this->storage))->store((object)['a' => 1], 'x');
    }

    public function testCommitRequiresAnActiveTransaction(): void
    {
        $this->expectException(TransactionNotActiveException::class);
        (new Transaction($this->storage))->commit();
    }

    public function testRollbackWithoutActiveTransactionThrows(): void
    {
        $this->expectException(TransactionException::class);
        (new Transaction($this->storage))->rollback();
    }

    public function testACommittedTransactionCantBeRolledBack(): void
    {
        $txn = (new Transaction($this->storage))->begin();
        $txn->store((object)['a' => 1]);
        $txn->commit();

        $this->expectException(TransactionException::class);
        $txn->rollback();
    }

    public function testAnObjectLockedByAnotherProcessCantBeChanged(): void
    {
        $uuid = $this->storage->store((object)['a' => 1]);
        $other = new ObjectStorage($this->storage->getStorageDir());
        $other->getLockAdapter()->acquireExclusiveLock($uuid);

        $txn = (new Transaction($this->storage, timeout: 0.05))->begin();
        try {
            $this->expectException(TransactionLockException::class);
            $txn->store((object)['a' => 2], $uuid);
        } finally {
            $txn->rollback();
            $other->getLockAdapter()->releaseLock($uuid);
        }
    }

    public function testOneTransactionPerStorageInstance(): void
    {
        $txn = (new Transaction($this->storage))->begin();
        $this->assertSame($txn, $this->storage->getActiveTransaction());

        try {
            (new Transaction($this->storage))->begin();
            $this->fail('a second transaction on the same instance');
        } catch (TransactionAlreadyActiveException $e) {
            $this->assertStringContainsString('use another ObjectStorage instance', $e->getMessage());
        }

        // another instance runs its own
        $parallel = (new Transaction(new ObjectStorage($this->storage->getStorageDir())))->begin();
        $parallel->rollback();

        $txn->rollback();
        $this->assertNull($this->storage->getActiveTransaction());
    }

    public function testWritesThroughTheStorageDuringTheTransactionAreRolledBack(): void
    {
        $existing = $this->storage->store((object)['v' => 'before']);
        $txn = (new Transaction($this->storage))->begin();

        // not through the transaction, but through its storage instance
        $created = $this->storage->store((object)['v' => 'new']);
        $this->storage->store((object)['v' => 'changed'], $existing);
        $this->storage->setLifetime($existing, 10);

        $txn->rollback();

        $this->assertFalse($this->storage->exists($created));
        $this->storage->clearCache();
        $this->assertSame('before', $this->storage->load($existing)->v);
        $this->assertNull($this->storage->readRaw($existing)->metadata->getTimestampExpiresAt());
    }

    public function testReferencedObjectsWrittenAlongAreRolledBack(): void
    {
        $uuid = $this->storage->store(new Product('Apple', category: new Category('Fruit')));
        $categoryUuid = iterator_to_array($this->storage->list(Category::class), false)[0];

        $storage = $this->failingStorage();
        $product = $storage->load($uuid);
        $product->category->title = 'Renamed';
        $txn = (new Transaction($storage))->begin();
        $txn->store($product, $uuid);
        $storage->failFor = $txn->store((object)['fails' => true]);

        try {
            $txn->commit();
            $this->fail('the commit must fail');
        } catch (TransactionCommitException) {
            // expected
        }

        $this->storage->clearCache();
        $this->assertSame('Fruit', $this->storage->load($uuid)->category->title);
        $this->assertFalse($this->storage->getLockAdapter()->isLockedByThisProcess((string)$categoryUuid));
    }

    public function testObjectsDeletedAlongAreRolledBack(): void
    {
        $uuid = $this->storage->store(new Product('Apple', category: new Category('Fruit')));

        $storage = $this->failingStorage();
        $this->strategy($storage)->setPolicyChildDeletion(ChildDeletion::ALWAYS);
        $txn = (new Transaction($storage))->begin();
        $txn->delete($uuid);
        $storage->failFor = $txn->store((object)['fails' => true]);

        try {
            $txn->commit();
            $this->fail('the commit must fail');
        } catch (TransactionCommitException) {
            // expected
        }

        $this->storage->clearCache();
        $this->assertSame('Fruit', $this->storage->load($uuid)->category->title);
        $this->assertSame(1, $this->storage->count(Category::class));
    }

    public function testTheDestructorRollsBackAnActiveTransaction(): void
    {
        $txn = (new Transaction($this->storage))->begin();
        $uuid = $txn->store((object)['a' => 1]);
        $journal = $txn->getJournalDirectory();

        unset($txn);

        $this->assertDirectoryDoesNotExist($journal);
        $this->assertFalse($this->storage->exists($uuid));
        $this->assertFalse($this->storage->getLockAdapter()->isLockedByThisProcess($uuid));
    }

    /**
     * A destructor must never let an exception escape (PHP treats that as fatal): a non-library Throwable raised
     * while auto-rolling-back is logged instead.
     */
    public function testTheDestructorLogsAFailingRollback(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('log')->with($this->isInstanceOf(Throwable::class));
        $this->storage->setLogger($logger);

        $txn = $this->getMockBuilder(Transaction::class)
            ->setConstructorArgs([$this->storage])
            ->onlyMethods(['rollback'])
            ->getMock();
        $txn->expects($this->atLeastOnce())->method('rollback')->willThrowException(new RuntimeException('simulated non-library failure during rollback()'));

        $txn->begin();
        $this->assertTrue($txn->isActive());

        $txn->__destruct();
    }
}

/**
 * A storage whose store() fails for one UUID, to make a commit fail after some of its changes were written.
 */
class FailingStoreStorage extends ObjectStorage
{
    public ?string $failFor = null;

    public function store(object $object, ?string $uuid = null, ?int $ttl = null): string
    {
        if (null !== $uuid && $uuid === $this->failFor) {
            throw new RuntimeException('simulated failure while storing ' . $uuid);
        }

        return parent::store($object, $uuid, $ttl);
    }
}
