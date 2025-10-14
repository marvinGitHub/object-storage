<?php

/**
 * Represents a transaction in an object storage system. Transactions
 * enable multiple operations to be performed atomically, ensuring that
 * either all operations are successfully executed, or none are applied.
 */

namespace melia\ObjectStorage;

use /**
 *
 */
    melia\ObjectStorage\Exception\Exception;
use /**
 * Exception thrown to indicate that an invalid operation has been attempted.
 *
 * This exception is typically used when an operation is performed
 * that is not allowed or is not valid given the current state or context.
 *
 * It extends from the base Exception class and provides additional
 * context for invalid operations within the ObjectStorage module.
 *
 * Usage of this exception should indicate a clear and specific
 * violation of operational rules or restrictions.
 */
    melia\ObjectStorage\Exception\InvalidOperationException;
use /**
 * Class IOException
 *
 * Represents an exception that is thrown when an I/O operation fails.
 * This can include errors related to file operations, stream handling,
 * or any other input/output operations within the ObjectStorage functionality.
 *
 * This exception is typically used to indicate runtime failures that are
 * related to input/output handling in the context of the ObjectStorage system.
 *
 * It should be extended or used to provide more specific details about
 * what caused the I/O operation to fail.
 */
    melia\ObjectStorage\Exception\IOException;
use /**
 * Exception thrown when an object is not found in the storage.
 *
 * This exception should be used to signal that a requested object could not
 * be found in the object storage system. It is generally used to handle
 * cases where the identifier provided does not match any existing object in
 * the system or the object has been removed.
 *
 * Use this exception to differentiate between a missed object retrieval and
 * other types of storage-related errors.
 */
    melia\ObjectStorage\Exception\ObjectNotFoundException;
use /**
 * Exception thrown when the activation of safe mode fails in the Object Storage system.
 *
 * This exception is specifically used to indicate that an attempt
 * to activate safe mode has been unsuccessful, which may occur due to
 * misconfiguration, insufficient permissions, or other runtime issues.
 *
 * It provides a way to handle errors related to safe mode activation
 * and allows for differentiation from other exceptions in the system.
 */
    melia\ObjectStorage\Exception\SafeModeActivationFailedException;
use /**
 * Exception thrown when attempting to start a new transaction while another transaction
 * is already active within the object storage system. This typically indicates a logic
 * error in the application where nested or concurrent transactions are not allowed
 * or properly handled.
 *
 * This exception should be caught and addressed to ensure proper transaction management
 * and to maintain the integrity of operations performed in the storage layer.
 *
 * Class TransactionAlreadyActiveException
 * @package melia\ObjectStorage\Exception
 */
    melia\ObjectStorage\Exception\TransactionAlreadyActiveException;
use /**
 * Exception thrown when a transaction commit operation fails.
 *
 * This exception indicates a failure during the commit phase of a transaction
 * within the ObjectStorage component. The failure could be due to various reasons
 * such as network issues, data inconsistency, or unexpected errors while attempting
 * to persist changes.
 *
 * By catching this exception, developers can handle and respond to transaction
 * commit failures, such as retrying the operation or rolling back changes where
 * necessary to maintain application stability and data integrity.
 */
    melia\ObjectStorage\Exception\TransactionCommitException;
use /**
 * Represents an exception that occurs during a transaction in the ObjectStorage component.
 *
 * This exception is used to indicate errors or issues specifically related to
 * transactional operations within the ObjectStorage system. It extends the base
 * Exception class to provide additional context or functionality when handling
 * such errors.
 *
 * Instances of this exception can be thrown when a transactional operation fails
 * or encounters unexpected behavior, allowing for better error handling and debugging.
 */
    melia\ObjectStorage\Exception\TransactionException;
use /**
 * Exception thrown when an operation is attempted on an object storage
 * transaction that has been locked, indicating that the transaction cannot
 * proceed because it is already in an invalid or conflicting state.
 *
 * This exception is typically used to signal issues related to concurrency
 * or transactional integrity within the object storage system.
 *
 * It is recommended to handle this exception by implementing appropriate
 * retry mechanisms or user notifications, depending on the specific application logic.
 */
    melia\ObjectStorage\Exception\TransactionLockException;
use /**
 * Exception thrown when an operation is attempted on a transaction that is not active.
 *
 * This exception is typically used in scenarios where a transaction-based
 * context is required but the transaction has not been started or is already
 * completed. It serves as an indicator to the calling code that the operation
 * cannot proceed due to the inactive state of the transaction.
 *
 * Common use cases include:
 * - Verifying the state of a transaction before performing operations.
 * - Handling errors gracefully when attempting actions on an inactive transaction.
 *
 * It is recommended to catch this exception and provide appropriate error
 * handling or retry logic where applicable.
 */
    melia\ObjectStorage\Exception\TransactionNotActiveException;
use /**
 * Class representing an exception that is thrown when a transaction rollback occurs
 * within the Object Storage system.
 *
 * This exception is typically triggered when an operation within a transactional
 * workflow fails and the system has attempted to revert changes to maintain data
 * integrity. The rollback indicates that the entire transaction has been
 * invalidated and must be handled appropriately by the caller.
 *
 * It extends a base exception class and provides additional context for error
 * handling specifically related to transaction rollbacks.
 */
    melia\ObjectStorage\Exception\TransactionRollbackException;
use /**
 * The Writer class is responsible for handling the writing of files
 * to the object storage within the `melia\ObjectStorage\File` namespace.
 *
 * This class typically provides functionality for creating, overwriting,
 * and appending data to files stored in the corresponding object storage.
 * Additionally, it ensures proper handling of file streams and storage consistency.
 *
 * Responsibilities of this class may include:
 * - Writing data to a given file path or storage identifier.
 * - Managing write permissions and access control for object storage files.
 * - Handling exceptions related to file writing or storage issues.
 */
    melia\ObjectStorage\File\Writer;
use /**
 * This interface defines the contract for classes that require awareness of a UUID (Universal Unique Identifier).
 * Implementing classes are expected to provide mechanisms to set and retrieve a UUID.
 */
    melia\ObjectStorage\UUID\AwareInterface;
use /**
 * The Generator interface for creating Universally Unique Identifiers (UUIDs).
 *
 * Provides a contract for generating UUIDs to be used in object storage and
 * related contexts requiring unique identification.
 */
    melia\ObjectStorage\UUID\Generator;
use /**
 * Represents errors and exceptions that can be thrown in the PHP code.
 *
 * The Throwable interface is the base interface for any object that can be thrown via a `throw` statement.
 * This includes both Error and Exception objects, providing a unified way to catch or handle such instances.
 *
 * Classes implementing Throwable must implement certain methods that provide details about the thrown object,
 * such as its message, code, file, and line where it was thrown, as well as its stack trace.
 *
 * Methods:
 * - `getMessage()`: Retrieves the error or exception message.
 * - `getCode()`: Retrieves the error or exception code.
 * - `getFile()`: Retrieves the filename in which the throwable was created.
 * - `getLine()`: Retrieves the line number where the throwable was created.
 * - `getTrace()`: Retrieves an array of the backtrace information.
 * - `getTraceAsString()`: Retrieves the backtrace as a string.
 * - `__toString()`: Converts the throwable to a string representation, typically including message, file, code, and trace details.
 */
    Throwable;

/**
 * Class Transaction
 *
 * Handles a series of operations within a transactional context, providing atomicity, consistency, and isolation
 * for object storage interactions. Operations within a transaction can either all be committed or rolled back.
 */
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

    /**
     * Generates a unique transaction ID.
     *
     * @return string A unique transaction ID composed of a prefix, a unique identifier, and the process ID.
     */
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

        if ($this->storage->getStateHandler()?->safeModeEnabled()) {
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
        (new Writer())->atomicWrite($logFile, serialize($logData), true);
    }

    /**
     * Retrieves the file path for the transaction log.
     *
     * @return string The full path to the transaction log file.
     */
    public function getTransactionLogPath(): string
    {
        return $this->storage->getStorageDir() . DIRECTORY_SEPARATOR . 'transactions' . DIRECTORY_SEPARATOR . $this->transactionId . self::TRANSACTION_FILE_SUFFIX;
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

        $this->updateTransactionLog();

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
            $this->storage->getLockAdapter()->acquireExclusiveLock($uuid, $this->timeout);
            $this->lockedObjects[] = $uuid;
        } catch (Throwable $e) {
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

    /**
     * Finds a pending operation based on its type and UUID.
     *
     * @param string $type The type of the operation to find.
     * @param string $uuid The UUID of the operation to find.
     * @return array|null An array representing the pending operation if found, or null if no matching operation exists.
     */
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

        // If there is a pending store for this UUID in the same transaction,
        // the object should be considered present within the txn context.
        // We allow scheduling a delete without requiring physical existence.
        $pendingStore = $this->findPendingOperation('store', $uuid);
        if (null === $pendingStore) {
            // No pending store: ensure it exists in storage, otherwise this is truly missing
            if (!$this->storage->exists($uuid)) {
                throw new ObjectNotFoundException("Object with UUID {$uuid} not found");
            }
        }

        // Lock object for transaction
        $this->lockObject($uuid);

        // Create backup only if the object actually exists in storage.
        // If it's only pending in this transaction, there is nothing to back up.
        $backup = null;
        if (null === $pendingStore) {
            $backup = $this->createBackup($uuid);
        }

        // Add operation to a transaction list
        $this->operations[] = [
            'type' => 'delete',
            'uuid' => $uuid,
            'backup' => $backup,
            'timestamp' => microtime(true)
        ];

        $this->updateTransactionLog();

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
                if ($this->storage->getLockAdapter()->isLockedByThisProcess($uuid)) {
                    $this->storage->getLockAdapter()->releaseLock($uuid);
                }
            } catch (Throwable $e) {
                $this->storage->getLogger()?->log(new Exception(message: "Failed to unlock object {$uuid}: " . $e->getMessage(), previous: $e));
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

        } catch (Throwable $e) {
            $this->storage->getStateHandler()?->enableSafeMode();
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
                            $this->storage->delete($operation['uuid'], true);
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
                $this->storage->getLogger()?->log(new Exception(message: sprintf("Rollback operation failed for %s", $operation['uuid']), previous: $e));
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
                $this->storage->getLogger()?->log(new Exception(message: "Auto-rollback failed in destructor: " . $e->getMessage(), previous: $e));
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
        (new Writer())->atomicWrite($logFile, serialize($logData), true);
    }
}