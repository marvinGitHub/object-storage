<?php

namespace Tests\melia\ObjectStorage;

use FilesystemIterator;
use melia\ObjectStorage\Event\Context\ObjectPersistenceContext;
use melia\ObjectStorage\Event\Context\TransactionContext;
use melia\ObjectStorage\Event\Dispatcher;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Journal\Journal;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Query\Oql;
use melia\ObjectStorage\Strategy\Policy\ChildDeletion;
use melia\ObjectStorage\Transaction;
use melia\ObjectStorage\TransactionRecovery;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\melia\ObjectStorage\Search\Category;
use Tests\melia\ObjectStorage\Search\Product;

/**
 * A crash during a commit leaves some changes written and the journal of the transaction. These tests take a copy
 * of the storage directory at that moment — the state a crash leaves on disk; locks die with the process — and open
 * a storage on it, which must roll the transaction back.
 */
final class TransactionCrashRecoveryTest extends TestCase
{
    /**
     * Copies a storage directory without its lock files, as a crash leaves it.
     */
    private function snapshot(string $source): string
    {
        $target = $this->createTemporaryDirectory();
        $this->storageDirs[] = $target;

        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            $relative = substr($item->getPathname(), strlen($source) + 1);
            if ('locks' === $relative || str_starts_with($relative, 'locks' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $path = $target . DIRECTORY_SEPARATOR . $relative;
            if ($item->isDir()) {
                @mkdir($path, 0777, true);
            } else {
                copy($item->getPathname(), $path);
            }
        }

        return $target;
    }

    /**
     * Commits a transaction storing the given objects and takes a snapshot once $crashAfter of them are written.
     *
     * @param array<int|string, object> $objects UUID (or a numeric key for new objects) => object
     * @return string The snapshot directory.
     */
    private function commitAndCrash(array $objects, int $crashAfter): string
    {
        $snapshot = null;
        $written = 0;
        $listener = function (ObjectPersistenceContext $context) use (&$snapshot, &$written, $crashAfter) {
            if (++$written === $crashAfter && null === $snapshot) {
                $snapshot = $this->snapshot($this->storage->getStorageDir());
            }
        };
        $this->storage->getEventDispatcher()->addListener(Events::OBJECT_SAVED, $listener);

        $txn = (new Transaction($this->storage))->begin();
        foreach ($objects as $uuid => $object) {
            $txn->store($object, is_string($uuid) ? $uuid : null);
        }
        $txn->commit();

        $this->storage->getEventDispatcher()->removeListener(Events::OBJECT_SAVED, $listener);
        $this->assertNotNull($snapshot, 'the crash point must be reached');

        return $snapshot;
    }

    private function transactionDirectories(string $storageDir): array
    {
        return glob($storageDir . DIRECTORY_SEPARATOR . TransactionRecovery::DIRECTORY . DIRECTORY_SEPARATOR . '*') ?: [];
    }

    public function testACommitInterruptedByACrashIsRolledBackWhenTheStorageIsOpened(): void
    {
        $first = $this->storage->store(new ChildObject('first before', 1));
        $second = $this->storage->store(new ChildObject('second before', 2));
        $firstBefore = $this->storage->readRaw($first);
        $secondBefore = $this->storage->readRaw($second);

        $snapshot = $this->commitAndCrash([
            $first => new ChildObject('first after', 10),
            0 => (object)['name' => 'created by the transaction'],
            $second => new ChildObject('second after', 20),
        ], crashAfter: 2);

        $this->assertCount(1, $this->transactionDirectories($snapshot), 'the crash leaves the journal');

        $recovered = [];
        $dispatcher = new Dispatcher();
        $dispatcher->addListener(Events::TRANSACTION_RECOVERED, static function (TransactionContext $context) use (&$recovered) {
            $recovered[] = $context;
        });
        $restarted = new ObjectStorage($snapshot, eventDispatcher: $dispatcher);

        $this->assertSame([], $this->transactionDirectories($snapshot));
        $this->assertCount(1, $recovered);
        $this->assertCount(3, $recovered[0]->getUuids());

        foreach ([$first => $firstBefore, $second => $secondBefore] as $uuid => $before) {
            $after = $restarted->readRaw($uuid);
            $this->assertSame($before->data, $after->data);
            $this->assertSame($before->metadata->getRevision(), $after->metadata->getRevision());
            $this->assertSame($before->metadata->getChecksum(), $after->metadata->getChecksum());
        }
        $this->assertSame('first before', $restarted->load($first)->title);

        // the object created by the transaction is gone
        $created = array_values(array_diff($recovered[0]->getUuids(), [$first, $second]));
        $this->assertCount(1, $created);
        $this->assertFalse($restarted->exists($created[0]));
        $this->assertSame(0, $restarted->count(\stdClass::class));
        $this->assertSame(2, $restarted->count(ChildObject::class));
        $this->assertFalse($restarted->getStateHandler()->safeModeEnabled());
    }

    public function testAnOqlTransactionWithObjectsDeletedAlongIsRolledBackAfterACrash(): void
    {
        $this->strategy()->setPolicyChildDeletion(ChildDeletion::ALWAYS);
        $uuid = $this->storage->store(new Product('Apple', price: 1.5, category: new Category('Fruit')));

        $oql = new Oql($this->storage);
        $oql->query('START TRANSACTION');
        $oql->query("DELETE FROM Product WHERE name = 'Apple'");
        $oql->query("INSERT INTO Product (name) VALUES ('Pear')");
        // the process dies here
        $snapshot = $this->snapshot($this->storage->getStorageDir());
        $oql->query('ROLLBACK');

        $restarted = new ObjectStorage($snapshot);

        $this->assertSame([], $this->transactionDirectories($snapshot));
        $this->assertSame('Fruit', $restarted->load($uuid)->category->title);
        $this->assertSame([['Apple']], (new Oql($restarted))->query('SELECT name FROM Product')->rows);
        $this->assertSame(1, $restarted->count(Category::class));
    }

    public function testACrashBeforeTheFirstWriteChangesNothing(): void
    {
        $uuid = $this->storage->store(new ChildObject('before', 1));
        $before = $this->storage->readRaw($uuid);

        $txn = (new Transaction($this->storage))->begin();
        $txn->store(new ChildObject('after', 2), $uuid);
        $snapshot = $this->snapshot($this->storage->getStorageDir());
        $txn->rollback();

        $restarted = new ObjectStorage($snapshot);

        $this->assertSame([], $this->transactionDirectories($snapshot));
        $this->assertSame($before->metadata->getRevision(), $restarted->readRaw($uuid)->metadata->getRevision());
    }

    public function testACommittedTransactionWhoseJournalRemainedIsKept(): void
    {
        $uuid = $this->storage->store(new ChildObject('before', 1));
        $before = $this->storage->readRaw($uuid);
        $this->storage->store(new ChildObject('after', 2), $uuid);

        // a crash after the commit entry, before the journal was removed
        $journal = new Journal($this->storage->getStorageDir() . DIRECTORY_SEPARATOR . TransactionRecovery::DIRECTORY . DIRECTORY_SEPARATOR . 'txn_committed');
        $journal->append(TransactionRecovery::ENTRY_BEGIN);
        $journal->append(TransactionRecovery::ENTRY_BEFORE, TransactionRecovery::encodeBefore($uuid, $before));
        $journal->append(TransactionRecovery::ENTRY_COMMIT);

        $restarted = new ObjectStorage($this->storage->getStorageDir());

        $this->assertFalse($journal->exists());
        $this->assertSame('after', $restarted->load($uuid)->title);
    }

    public function testARunningTransactionIsntRolledBackByAnotherStorage(): void
    {
        $uuid = $this->storage->store(new ChildObject('before', 1));

        $txn = (new Transaction($this->storage))->begin();
        $txn->store(new ChildObject('after', 2), $uuid);

        // e.g. another request opening the storage meanwhile
        new ObjectStorage($this->storage->getStorageDir());

        $this->assertDirectoryExists($txn->getJournalDirectory());
        $txn->commit();

        $this->storage->clearCache();
        $this->assertSame('after', $this->storage->load($uuid)->title);
    }

    public function testAFailingRollbackEnablesSafeModeAndKeepsTheJournal(): void
    {
        $uuid = $this->storage->store(new ChildObject('before', 1));

        $journal = new Journal($this->storage->getStorageDir() . DIRECTORY_SEPARATOR . TransactionRecovery::DIRECTORY . DIRECTORY_SEPARATOR . 'txn_damaged');
        $journal->append(TransactionRecovery::ENTRY_BEGIN);
        $journal->append(TransactionRecovery::ENTRY_BEFORE, ['uuid' => $uuid, 'raw' => ['data' => 'no metadata']]);

        $failures = [];
        $dispatcher = new Dispatcher();
        $dispatcher->addListener(Events::TRANSACTION_RECOVERY_FAILED, static function (TransactionContext $context) use (&$failures) {
            $failures[] = $context;
        });
        $restarted = new ObjectStorage($this->storage->getStorageDir(), eventDispatcher: $dispatcher);

        $this->assertTrue($restarted->getStateHandler()->safeModeEnabled());
        $this->assertTrue($journal->exists());
        $this->assertCount(1, $failures);
        $this->assertSame('txn_damaged', $failures[0]->getTransactionId());
        $this->assertNotNull($failures[0]->getError());

        // in safe mode, the rollback waits; the object is untouched
        new ObjectStorage($this->storage->getStorageDir());
        $this->assertTrue($journal->exists());
        $this->assertSame('before', $restarted->load($uuid)->title);
    }

    /**
     * Logs of the former format (transactions/<id>.txn) are files, not journals: the storage leaves them alone,
     * migrations/003_004.php lists and removes them.
     */
    public function testLogsOfTheFormerFormatAreIgnored(): void
    {
        $legacy = $this->storage->getStorageDir() . DIRECTORY_SEPARATOR . TransactionRecovery::DIRECTORY . DIRECTORY_SEPARATOR . 'txn_old.txn';
        $this->putFile($legacy, serialize(['transaction_id' => 'txn_old', 'status' => 'active', 'operations' => []]));

        $restarted = new ObjectStorage($this->storage->getStorageDir());

        $this->assertFileExists($legacy);
        $this->assertFalse($restarted->getStateHandler()->safeModeEnabled());
        $txn = (new Transaction($restarted))->begin();
        $txn->store((object)['a' => 1]);
        $this->assertTrue($txn->commit());
    }
}
