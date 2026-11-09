<?php

/**
 * Represents a transaction in an object storage system. Transactions
 * enable multiple operations to be performed atomically, ensuring that
 * either all operations are successfully executed, or none are applied.
 */

namespace melia\ObjectStorage;

use melia\ObjectStorage\Exception\Exception;
use melia\ObjectStorage\Exception\InvalidOperationException;
use melia\ObjectStorage\Exception\ObjectNotFoundException;
use melia\ObjectStorage\Exception\SafeModeActivationFailedException;
use melia\ObjectStorage\Exception\TransactionAlreadyActiveException;
use melia\ObjectStorage\Exception\TransactionCommitException;
use melia\ObjectStorage\Exception\TransactionException;
use melia\ObjectStorage\Exception\TransactionLockException;
use melia\ObjectStorage\Exception\TransactionNotActiveException;
use melia\ObjectStorage\Exception\TransactionRollbackException;
use melia\ObjectStorage\Journal\Journal;
use melia\ObjectStorage\Storage\StorageAwareTrait;
use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\Generator\AwareTrait;
use melia\ObjectStorage\UUID\Generator\Generator;
use Throwable;

/**
 * Stores and deletes several objects together: either all changes are written, or none.
 *
 * The changes are collected and written by commit(). Every object is locked exclusively from its first change in
 * the transaction until the commit or rollback. Its state before the transaction is recorded in a journal
 * (<storage dir>/transactions/<transaction id>/, see TransactionRecovery) before anything is written, so a failed
 * commit is rolled back exactly (data, revision, checksum and expiration as they were), and a commit interrupted by a
 * crash is rolled back when the storage is opened next.
 *
 * While it runs, the transaction is the active transaction of its storage instance: every change of object files
 * through that instance is recorded before it is made (track()) — objects written along by the child persistence
 * policy, objects deleted along by the child deletion policy, store() and delete() of the storage itself, OQL
 * statements — and rolled back with the rest. One transaction per storage instance; another instance can run
 * another transaction. See docs/transactions.md.
 */
class Transaction
{
    use AwareTrait;
    use StorageAwareTrait;

    private const string OPERATION_STORE = 'store';
    private const string OPERATION_DELETE = 'delete';

    private string $transactionId;

    /** @var list<array{type: string, uuid: string, object?: object, timestamp: float}> */
    private array $operations = [];

    /** @var array<string, string> the objects locked (and retained) for the transaction */
    private array $lockedObjects = [];

    /** @var array<string, true> the objects whose state before the transaction is in the journal */
    private array $recorded = [];

    private ?Journal $journal = null;
    private bool $transactionLocked = false;
    private bool $undoing = false;
    private bool $isActive = false;
    private bool $isCommitted = false;
    private bool $isRolledBack = false;
    private float $timeout;

    public function __construct(ObjectStorage $storage, float $timeout = 30.0)
    {
        $this->setGenerator(new Generator());
        $this->setStorage($storage);
        $this->transactionId = $this->generateTransactionId();
        $this->timeout = $timeout;
    }

    /**
     * Generates a unique transaction ID, usable as a directory name.
     */
    private function generateTransactionId(): string
    {
        return 'txn_' . str_replace('.', '_', uniqid('', true)) . '_' . getmypid();
    }

    /**
     * Starts the transaction: takes its lock (so a storage opened meanwhile doesn't take it for interrupted) and
     * starts its journal.
     *
     * @throws TransactionException If it is already active, the storage is in safe mode, or the journal can't be
     *         written.
     */
    public function begin(): self
    {
        if ($this->isActive) {
            throw new TransactionAlreadyActiveException('Transaction is already active');
        }

        $storage = $this->getStorage();
        if (null !== $storage->getActiveTransaction()) {
            // every change through the instance belongs to its transaction: two would be ambiguous
            throw new TransactionAlreadyActiveException('Another transaction is active on this storage instance; use another ObjectStorage instance for a parallel transaction');
        }
        if ($storage->getStateHandler()?->safeModeEnabled()) {
            throw new TransactionException('Cannot start transaction in safe mode');
        }

        try {
            $lockAdapter = $storage->getLockAdapter();
            if (null !== $lockAdapter) {
                $lockAdapter->acquireExclusiveLock(TransactionRecovery::lockUuid($this->transactionId), $this->timeout);
                $this->transactionLocked = true;
            }

            $this->journal = TransactionRecovery::openJournal($storage, $this->transactionId);
            $this->journal->append(TransactionRecovery::ENTRY_BEGIN, [
                'id' => $this->transactionId,
                'pid' => getmypid(),
                'host' => (string)gethostname(),
                'startedAt' => microtime(true),
            ]);
        } catch (Throwable $e) {
            $this->discardJournal();
            $this->releaseTransactionLock();
            throw new TransactionException('The transaction can\'t be started: ' . $e->getMessage(), 0, $e);
        }

        $this->isActive = true;
        $this->isCommitted = false;
        $this->isRolledBack = false;
        $storage->setActiveTransaction($this);

        return $this;
    }

    /**
     * Records an object before it is changed through the storage instance of the transaction: locks it until the
     * end of the transaction and records its state before the transaction, if not yet done. Called by the storage
     * under the exclusive lock of the object, before its files are written or deleted, so every change through the
     * instance is part of the transaction: OQL statements, store() and delete() of the storage, referenced objects
     * written or deleted along.
     *
     * @throws TransactionLockException
     * @throws TransactionException If the state can't be recorded; the change must not be made then.
     * @internal Not part of the public API; may change in any release.
     */
    public function track(string $uuid): void
    {
        // restoring objects while rolling back records nothing
        if (!$this->isActive || $this->undoing) {
            return;
        }

        $this->lockObject($uuid);
        $this->recordBefore($uuid);
    }

    /**
     * Marks the current point of the transaction, to roll back to it with rollbackTo(), e.g. before a statement.
     *
     * @return int The point: the sequence number of the last entry of the journal.
     * @throws TransactionNotActiveException
     * @throws TransactionException If the journal can't be read.
     * @internal Not part of the public API; may change in any release.
     */
    public function savepoint(): int
    {
        $this->ensureTransactionActive();

        try {
            $savepoint = $this->journal?->lastSequence() ?? 0;
        } catch (Throwable $e) {
            throw new TransactionException('The journal of the transaction can\'t be read: ' . $e->getMessage(), 0, $e);
        }

        // objects changed after the savepoint are recorded again, with their state at the savepoint
        $this->recorded = [];

        return $savepoint;
    }

    /**
     * Rolls back the changes made since a savepoint(); the transaction stays active and keeps its locks (as a
     * failed statement in MySQL).
     *
     * @throws TransactionNotActiveException
     * @throws TransactionRollbackException If an object can't be restored; the transaction ends as a failed
     *         rollback() does: safe mode, the journal is kept.
     * @internal Not part of the public API; may change in any release.
     */
    public function rollbackTo(int $savepoint): void
    {
        $this->ensureTransactionActive();

        try {
            $this->undo($savepoint);
        } catch (Throwable $e) {
            $this->failRollback($e);
        }

        // as at the savepoint: the next change of an object records its state again
        $this->recorded = [];
    }

    /**
     * Retrieves the current object storage.
     */
    public function getStorage(): ObjectStorage
    {
        if (!$this->storage instanceof ObjectStorage) {
            throw new InvalidOperationException('A transaction needs an ObjectStorage.');
        }

        return $this->storage;
    }

    /**
     * The directory of the journal of the transaction; it exists from begin() to the end of the commit or rollback.
     */
    public function getJournalDirectory(): string
    {
        return TransactionRecovery::directory($this->getStorage()) . DIRECTORY_SEPARATOR . $this->transactionId;
    }

    /**
     * Writes all changes of the transaction. If one of them fails, those written so far are rolled back.
     *
     * @throws TransactionCommitException If a change failed; the transaction is rolled back.
     * @throws TransactionNotActiveException
     * @throws TransactionRollbackException If rolling back after a failure failed too; safe mode is enabled.
     */
    public function commit(): bool
    {
        $this->ensureTransactionActive();

        try {
            foreach ($this->operations as $operation) {
                $this->executeOperation($operation);
            }

            // from here on, the changes stay even if the process crashes before the journal is removed
            $this->journal?->append(TransactionRecovery::ENTRY_COMMIT);
        } catch (Throwable $e) {
            // Throwable, not only the library's own exceptions: store() and delete() may also throw core
            // exceptions (e.g. ReflectionException), which must roll back and release the locks as well
            $this->rollback();
            throw new TransactionCommitException('Transaction commit failed: ' . $e->getMessage(), 0, $e);
        }

        $this->isCommitted = true;
        $this->isActive = false;
        $this->cleanup();

        return true;
    }

    /**
     * Executes an operation.
     *
     * @param array{type: string, uuid: string, object?: object} $operation
     * @throws Throwable
     */
    private function executeOperation(array $operation): void
    {
        switch ($operation['type']) {
            case self::OPERATION_STORE:
                $this->getStorage()->store($operation['object'], $operation['uuid']);
                break;

            case self::OPERATION_DELETE:
                $this->getStorage()->delete($operation['uuid']);
                break;

            default:
                throw new InvalidOperationException('Unknown operation type: ' . $operation['type']);
        }
    }

    /**
     * Stores an object within the transaction: it is written by commit().
     *
     * @return string The UUID of the object.
     * @throws TransactionNotActiveException
     * @throws TransactionLockException If the object can't be locked.
     * @throws TransactionException If its current state can't be recorded.
     */
    public function store(object $object, ?string $uuid = null): string
    {
        $this->ensureTransactionActive();

        $uuid = $uuid ?? ($object instanceof AwareInterface ? $object->getUUID() ?? $this->getGenerator()->generate() : $this->getGenerator()->generate());

        $this->lockObject($uuid);
        $this->recordBefore($uuid);

        $this->operations[] = [
            'type' => self::OPERATION_STORE,
            'uuid' => $uuid,
            'object' => clone $object,
            'timestamp' => microtime(true),
        ];

        return $uuid;
    }

    /**
     * Locks an object for the transaction, until its end.
     *
     * @throws TransactionLockException
     */
    private function lockObject(string $uuid): void
    {
        if (isset($this->lockedObjects[$uuid])) {
            return; // Already locked
        }

        try {
            $this->getStorage()->retainLock($uuid, $this->timeout);
            $this->lockedObjects[$uuid] = $uuid;
        } catch (Throwable $e) {
            throw new TransactionLockException("Could not lock object {$uuid}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Records the state of an object as stored, before the transaction changes it, in the journal: read from the
     * files under the lock, not from the cache, so it is the state another process may have stored meanwhile.
     *
     * @throws TransactionException
     */
    private function recordBefore(string $uuid): void
    {
        if (isset($this->recorded[$uuid])) {
            return;
        }

        try {
            try {
                $raw = $this->getStorage()->readRaw($uuid);
            } catch (ObjectNotFoundException) {
                $raw = null;
            }

            $this->journal?->append(TransactionRecovery::ENTRY_BEFORE, TransactionRecovery::encodeBefore($uuid, $raw));
        } catch (Throwable $e) {
            throw new TransactionException(sprintf('The state of the object %s can\'t be recorded: %s', $uuid, $e->getMessage()), 0, $e);
        }

        $this->recorded[$uuid] = true;
    }

    /**
     * Loads an object within the transaction: as stored or deleted in it, otherwise from the storage.
     *
     * @throws Throwable
     */
    public function load(string $uuid): ?object
    {
        $this->ensureTransactionActive();

        // the last operation on the object counts
        foreach (array_reverse($this->operations) as $operation) {
            if ($operation['uuid'] !== $uuid) {
                continue;
            }

            return self::OPERATION_STORE === $operation['type'] ? clone $operation['object'] : null;
        }

        return $this->getStorage()->load($uuid);
    }

    /**
     * Deletes an object within the transaction: it is deleted by commit().
     *
     * @throws ObjectNotFoundException If the object doesn't exist, neither stored nor stored in the transaction.
     * @throws TransactionLockException
     * @throws TransactionNotActiveException
     * @throws TransactionException
     */
    public function delete(string $uuid): bool
    {
        $this->ensureTransactionActive();

        $storedInTransaction = false;
        foreach ($this->operations as $operation) {
            if ($operation['uuid'] === $uuid && self::OPERATION_STORE === $operation['type']) {
                $storedInTransaction = true;
            }
        }

        if (!$storedInTransaction && !$this->getStorage()->exists($uuid)) {
            throw new ObjectNotFoundException("Object with UUID {$uuid} not found");
        }

        $this->lockObject($uuid);
        $this->recordBefore($uuid);

        $this->operations[] = [
            'type' => self::OPERATION_DELETE,
            'uuid' => $uuid,
            'timestamp' => microtime(true),
        ];

        return true;
    }

    /**
     * Rolls back the transaction: before commit(), nothing has been written and the changes are discarded; after a
     * failed commit, the objects are restored to their state before the transaction.
     *
     * @throws TransactionException If the transaction isn't active.
     * @throws TransactionRollbackException If an object can't be restored; safe mode is enabled, and the journal is
     *         kept, so the rollback is tried again when the storage is opened next (after disabling safe mode).
     * @throws SafeModeActivationFailedException
     */
    public function rollback(): bool
    {
        if (!$this->isActive) {
            throw new TransactionException($this->isCommitted ? 'The transaction is committed and can\'t be rolled back' : 'No active transaction to rollback');
        }

        try {
            $this->undo(0);
        } catch (Throwable $e) {
            $this->failRollback($e);
        }

        $this->isRolledBack = true;
        $this->isActive = false;
        $this->cleanup();

        return true;
    }

    /**
     * Restores the objects recorded after a point of the journal (0: all), without recording the restoring writes.
     *
     * @throws TransactionRollbackException
     */
    private function undo(int $afterSequence): void
    {
        if (null === $this->journal) {
            return;
        }

        $this->undoing = true;
        try {
            TransactionRecovery::undo($this->getStorage(), $this->journal, $afterSequence);
        } finally {
            $this->undoing = false;
        }
    }

    /**
     * Ends the transaction after a rollback that couldn't restore every object: safe mode is enabled, the journal
     * stays for the next attempt (when the storage is opened again), and the locks are released, so another process
     * can make it.
     *
     * @throws TransactionRollbackException Always.
     * @throws SafeModeActivationFailedException
     */
    private function failRollback(Throwable $e): never
    {
        $this->isActive = false;
        $this->journal = null;
        $this->releaseLocks();
        $this->detach();
        $this->getStorage()->getStateHandler()?->enableSafeMode();

        throw new TransactionRollbackException('Transaction rollback failed, enable safe mode. Reason: ' . $e->getMessage(), 0, $e);
    }

    /**
     * Removes the journal and releases the locks, after a commit or rollback.
     */
    private function cleanup(): void
    {
        $this->discardJournal();
        $this->releaseLocks();
        $this->detach();
        $this->operations = [];
        $this->recorded = [];
    }

    /**
     * Ends recording the changes through the storage instance.
     */
    private function detach(): void
    {
        $storage = $this->getStorage();
        if ($storage->getActiveTransaction() === $this) {
            $storage->setActiveTransaction(null);
        }
    }

    /**
     * Removes the journal; a journal that can't be removed is ignored (committed) or rolled back (nothing written)
     * when the storage is opened next.
     */
    private function discardJournal(): void
    {
        if (null === $this->journal) {
            return;
        }

        try {
            $this->journal->remove();
        } catch (Throwable $e) {
            $this->getStorage()->getLogger()?->log(new Exception(message: 'Failed to remove the journal of transaction ' . $this->transactionId, previous: $e));
        }
        $this->journal = null;
    }

    /**
     * Releases the locks of the objects and of the transaction.
     */
    private function releaseLocks(): void
    {
        foreach ($this->lockedObjects as $uuid) {
            try {
                $this->getStorage()->releaseRetainedLock($uuid);
            } catch (Throwable $e) {
                $this->getStorage()->getLogger()?->log(new Exception(message: "Failed to unlock object {$uuid}: " . $e->getMessage(), previous: $e));
            }
        }
        $this->lockedObjects = [];

        $this->releaseTransactionLock();
    }

    private function releaseTransactionLock(): void
    {
        if (!$this->transactionLocked) {
            return;
        }

        $this->transactionLocked = false;
        $lockUuid = TransactionRecovery::lockUuid($this->transactionId);
        try {
            $lockAdapter = $this->getStorage()->getLockAdapter();
            if (null !== $lockAdapter && $lockAdapter->isLockedByThisProcess($lockUuid)) {
                $lockAdapter->releaseLock($lockUuid);
            }
        } catch (Throwable $e) {
            $this->getStorage()->getLogger()?->log(new Exception(message: 'Failed to release the lock of transaction ' . $this->transactionId, previous: $e));
        }
    }

    /**
     * @throws TransactionNotActiveException
     */
    private function ensureTransactionActive(): void
    {
        if (!$this->isActive) {
            throw new TransactionNotActiveException('No active transaction');
        }
    }

    /**
     * Destructor - automatic rollback if the transaction is still active
     */
    public function __destruct()
    {
        if ($this->isActive && !$this->isCommitted && !$this->isRolledBack) {
            try {
                $this->rollback();
            } catch (Throwable $e) {
                // Must not let anything escape a destructor (fatal); catch Throwable, not just
                // the library's own Exception type.
                $this->getStorage()->getLogger()?->log(new Exception(message: "Auto-rollback failed in destructor: " . $e->getMessage(), previous: $e));
            }
        }
    }

    /**
     * Getters for status information
     */
    public function getTransactionId(): string
    {
        return $this->transactionId;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function isCommitted(): bool
    {
        return $this->isCommitted;
    }

    public function isRolledBack(): bool
    {
        return $this->isRolledBack;
    }

    public function getOperationCount(): int
    {
        return count($this->operations);
    }
}
