<?php

namespace melia\ObjectStorage;

use melia\ObjectStorage\Exception\Exception;
use melia\ObjectStorage\Exception\InvalidOperationException;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\Exception\LockException;
use melia\ObjectStorage\Exception\ObjectNotFoundException;
use melia\ObjectStorage\Exception\SafeModeActivationFailedException;
use melia\ObjectStorage\Exception\TransactionAlreadyActiveException;
use melia\ObjectStorage\Exception\TransactionCommitException;
use melia\ObjectStorage\Exception\TransactionException;
use melia\ObjectStorage\Exception\TransactionLockException;
use melia\ObjectStorage\Exception\TransactionNotActiveException;
use melia\ObjectStorage\Exception\TransactionRollbackException;
use melia\ObjectStorage\File\Writer;
use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\Generator;
use Throwable;

class Transaction
{
    private const TRANSACTION_FILE_SUFFIX = '.txn';

    private ObjectStorage $storage;
    private string $transactionId;
    private array $operations = [];
    private array $lockedObjects = [];
    private bool $isActive = false;
    private bool $isCommitted = false;
    private bool $isRolledBack = false;
    private float $timeout;

    public function __construct(ObjectStorage $storage, float $timeout = 30.0)
    {
        $this->storage = $storage;
        $this->transactionId = $this->generateTransactionId();
        $this->timeout = $timeout;
    }

    private function generateTransactionId(): string
    {
        return 'txn_' . uniqid('', true) . '_' . getmypid();
    }

    /**
     * @throws TransactionException
     *
     * @throws IOException
     */
    public function begin(): self
    {
        if ($this->isActive) {
            throw new TransactionAlreadyActiveException('Transaction is already active');
        }

        if ($this->storage->safeModeEnabled()) {
            throw new TransactionException('Cannot start transaction in safe mode');
        }

        $this->isActive = true;
        $this->createTransactionLog();

        return $this;
    }

    /**
     * Creates a transaction log
     * @throws IOException
     */
    private function createTransactionLog(): void
    {
        $logData = [
            'transaction_id' => $this->transactionId,
            'start_time' => microtime(true),
            'status' => 'active',
            'operations' => []
        ];

        $logFile = $this->getTransactionLogPath();
        (new Writer())->atomicWrite($logFile, serialize($logData));
    }

    private function getTransactionLogPath(): string
    {
        return $this->storage->getStorageDir() . DIRECTORY_SEPARATOR . $this->transactionId . self::TRANSACTION_FILE_SUFFIX;
    }

    /**
     * Commits the transaction
     *
     * @return bool
     * @throws Throwable
     * @throws TransactionCommitException
     * @throws TransactionException
     * @throws TransactionNotActiveException
     */
    public function commit(): bool
    {
        $this->ensureTransactionActive();

        try {
            // Execute all operations
            foreach ($this->operations as $operation) {
                $this->executeOperation($operation);
            }

            $this->isCommitted = true;
            $this->isActive = false;

            // Cleanup
            $this->cleanup();

            return true;

        } catch (Exception $e) {
            // Automatic rollback on error
            $this->rollback();
            throw new TransactionCommitException('Transaction commit failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Helper methods
     *
     * @throws TransactionNotActiveException
     */
    private function ensureTransactionActive(): void
    {
        if (!$this->isActive) {
            throw new TransactionNotActiveException('No active transaction');
        }
    }

    /**
     * Executes an operation
     *
     * @throws Throwable
     */
    private function executeOperation(array $operation): void
    {
        switch ($operation['type']) {
            case 'store':
                $this->storage->store($operation['object'], $operation['uuid']);
                break;

            case 'delete':
                $this->storage->delete($operation['uuid']);
                break;

            default:
                throw new InvalidOperationException('Unknown operation type: ' . $operation['type']);
        }
    }

    /**
     * Stores an object within the transaction
     * @throws Throwable
     */
    public function store(object $object, ?string $uuid = null): string
    {
        $this->ensureTransactionActive();

        $uuid = $uuid ?? ($object instanceof AwareInterface ? $object->getUUID() ?? Generator::generate() : Generator::generate());

        // Lock object for transaction
        $this->lockObject($uuid);

        // Create a backup of an existing object (if exists)
        $backup = null;
        if ($this->storage->exists($uuid)) {
            $backup = $this->createBackup($uuid);
        }

        // Add operation to a transaction list
        $this->operations[] = [
            'type' => 'store',
            'uuid' => $uuid,
            'object' => clone $object,
            'backup' => $backup,
            'timestamp' => microtime(true)
        ];

        return $uuid;
    }

    /**
     * Locks an object for the transaction
     *
     * @throws TransactionLockException
     */
    private function lockObject(string $uuid): void
    {
        if (in_array($uuid, $this->lockedObjects)) {
            return; // Already locked
        }

        try {
            $this->storage->lock($uuid, false, $this->timeout);
            $this->lockedObjects[] = $uuid;
        } catch (LockException $e) {
            throw new TransactionLockException("Could not lock object {$uuid}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Creates a backup of an object
     *
     * @throws Throwable
     */
    private function createBackup(string $uuid): ?array
    {
        if (!$this->storage->exists($uuid)) {
            return null;
        }

        $object = $this->storage->load($uuid);
        if (null === $object) {
            return null;
        }

        return [
            'uuid' => $uuid,
            'object' => clone $object,
            'timestamp' => microtime(true)
        ];
    }

    /**
     * Loads an object within the transaction
     * @throws Throwable
     */
    public function load(string $uuid): ?object
    {
        $this->ensureTransactionActive();

        // Check if there is a pending store operation for this UUID
        $pendingStore = $this->findPendingOperation('store', $uuid);
        if ($pendingStore) {
            return clone $pendingStore['object'];
        }

        // Check if there is a pending delete operation
        $pendingDelete = $this->findPendingOperation('delete', $uuid);
        if ($pendingDelete) {
            return null; // Object was deleted in this transaction
        }

        // Normal load operation
        return $this->storage->load($uuid);
    }

    private function findPendingOperation(string $type, string $uuid): ?array
    {
        foreach ($this->operations as $operation) {
            if ($operation['type'] === $type && $operation['uuid'] === $uuid) {
                return $operation;
            }
        }
        return null;
    }

    /**
     * Deletes an object within the transaction
     *
     * @param string $uuid
     * @return bool
     * @throws ObjectNotFoundException
     * @throws Throwable
     * @throws TransactionLockException
     * @throws TransactionNotActiveException
     */
    public function delete(string $uuid): bool
    {
        $this->ensureTransactionActive();

        if (!$this->storage->exists($uuid)) {
            throw new ObjectNotFoundException("Object with UUID {$uuid} not found");
        }

        // Lock object for transaction
        $this->lockObject($uuid);

        // Create backup
        $backup = $this->createBackup($uuid);

        // Add operation to a transaction list
        $this->operations[] = [
            'type' => 'delete',
            'uuid' => $uuid,
            'backup' => $backup,
            'timestamp' => microtime(true)
        ];

        return true;
    }

    /**
     * Cleanup after transaction
     */
    private function cleanup(): void
    {
        $this->unlockAllObjects();

        // Delete transaction log
        $logFile = $this->getTransactionLogPath();
        if (file_exists($logFile)) {
            unlink($logFile);
        }

        $this->operations = [];
    }

    /**
     * Unlocks all objects
     */
    private function unlockAllObjects(): void
    {
        foreach ($this->lockedObjects as $uuid) {
            try {
                if ($this->storage->hasActiveLock($uuid)) {
                    $this->storage->unlock($uuid);
                }
            } catch (Exception $e) {
                $this->storage->getLogger()->log(new Exception(message: "Failed to unlock object {$uuid}: " . $e->getMessage(), previous: $e));
            }
        }
        $this->lockedObjects = [];
    }

    /**
     * Rolls back the transaction
     *
     * @throws TransactionException
     * @throws SafeModeActivationFailedException
     */
    public function rollback(): bool
    {
        if (!$this->isActive && !$this->isCommitted) {
            throw new TransactionException('No active transaction to rollback');
        }

        try {
            // Execute rollback operations
            $this->executeRollback();

            $this->isRolledBack = true;
            $this->isActive = false;

            // Cleanup
            $this->cleanup();

            return true;

        } catch (Exception $e) {
            $this->storage->enableSafeMode();
            throw new TransactionRollbackException('Transaction rollback failed, enable safe mode. Reason: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Executes rollback operations
     */
    private function executeRollback(): void
    {
        // Reverse operations in reverse order
        $operations = array_reverse($this->operations);

        foreach ($operations as $operation) {
            try {
                switch ($operation['type']) {
                    case 'store':
                        if ($operation['backup']) {
                            // Restore backup
                            $this->restoreBackup($operation['uuid'], $operation['backup']);
                        } else {
                            // Delete an object (was newly created)
                            if ($this->storage->exists($operation['uuid'])) {
                                $this->storage->delete($operation['uuid'], true);
                            }
                        }
                        break;

                    case 'delete':
                        // Restore backup
                        if ($operation['backup']) {
                            $this->restoreBackup($operation['uuid'], $operation['backup']);
                        }
                        break;
                }
            } catch (Throwable $e) {
                // Log rollback errors, but continue
                $this->storage->getLogger()->log(new Exception(message: sprintf("Rollback operation failed for {$operation['uuid']}", previous: $e)));
            }
        }
    }

    /**
     * Restores a backup
     *
     * @throws Throwable
     */
    private function restoreBackup(string $uuid, array $backup): void
    {
        if ($backup && isset($backup['object'])) {
            $this->storage->store($backup['object'], $uuid);
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
            } catch (Exception $e) {
                $this->storage->getLogger()->log(new Exception(message: "Auto-rollback failed in destructor: " . $e->getMessage(), previous: $e));
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

    /**
     * Updates the transaction log
     * @throws IOException
     */
    private function updateTransactionLog(): void
    {
        $logData = [
            'transaction_id' => $this->transactionId,
            'start_time' => microtime(true),
            'status' => $this->isCommitted ? 'committed' : ($this->isRolledBack ? 'rolled_back' : 'active'),
            'operations' => array_map(function ($operation) {
                return [
                    'type' => $operation['type'],
                    'uuid' => $operation['uuid'],
                    'timestamp' => $operation['timestamp']
                ];
            }, $this->operations)
        ];

        $logFile = $this->getTransactionLogPath();
        (new Writer())->atomicWrite($logFile, serialize($logData));
    }
}