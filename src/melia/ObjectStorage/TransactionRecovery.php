<?php

namespace melia\ObjectStorage;

use melia\ObjectStorage\Event\Context\TransactionContext;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\Exception;
use melia\ObjectStorage\Exception\LockException;
use melia\ObjectStorage\Exception\ObjectNotFoundException;
use melia\ObjectStorage\Exception\TransactionRollbackException;
use melia\ObjectStorage\Journal\Journal;
use melia\ObjectStorage\Journal\JournalInterface;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\Transfer\RawObject;
use melia\ObjectStorage\UUID\Helper;
use Throwable;

/**
 * Rolls back transactions: a failed commit (Transaction), and transactions interrupted by a crash, when a storage
 * is opened.
 *
 * A transaction keeps a journal in <storage dir>/transactions/<transaction id>/: "begin", then a "before" entry per
 * object with the object as stored before the transaction changed it (RawObject, or null if it didn't exist), and
 * "commit" once all changes are written. Rolling back writes the "before" states back as they were stored (same
 * data, revision, checksum, expiration) and deletes the objects that didn't exist, unless the journal ends with
 * "commit".
 *
 * @internal Not part of the public API; may change in any release.
 */
final class TransactionRecovery
{
    public const string DIRECTORY = 'transactions';
    public const string ENTRY_BEGIN = 'begin';
    public const string ENTRY_BEFORE = 'before';
    public const string ENTRY_COMMIT = 'commit';

    /**
     * The directory of the journals of the transactions of a storage.
     */
    public static function directory(ObjectStorage $storage): string
    {
        return $storage->getStorageDir() . DIRECTORY_SEPARATOR . self::DIRECTORY;
    }

    /**
     * The UUID locked while a transaction is running, so a storage opened meanwhile doesn't roll it back.
     */
    public static function lockUuid(string $transactionId): string
    {
        return Helper::createFixedUuidV4('melia/object-storage:transaction:' . $transactionId);
    }

    /**
     * Opens the journal of a transaction with the IO adapter and the fsync setting of the storage.
     */
    public static function openJournal(ObjectStorage $storage, string $transactionId): Journal
    {
        return new Journal(
            self::directory($storage) . DIRECTORY_SEPARATOR . $transactionId,
            $storage->getIOAdapter(),
            $storage->getWriter()->syncEnabled(),
        );
    }

    /**
     * The payload of a "before" entry.
     *
     * @return array{uuid: string, raw: array{data: string, metadata: array<string, mixed>, references: string[]}|null}
     */
    public static function encodeBefore(string $uuid, ?RawObject $raw): array
    {
        return [
            'uuid' => $uuid,
            'raw' => null === $raw ? null : [
                'data' => $raw->data,
                'metadata' => $raw->metadata->jsonSerialize(),
                'references' => $raw->references,
            ],
        ];
    }

    /**
     * The "before" states of a journal, by UUID, in the order they were recorded; null for objects that didn't
     * exist. Null instead of the states if the journal ends with "commit": nothing to roll back.
     *
     * The first state recorded for an object counts: its state before the transaction, or, with $afterSequence,
     * before its first change after that point (a transaction records an object again after a savepoint).
     *
     * @param int $afterSequence Only entries after this sequence number (Transaction::savepoint()).
     * @return array<string, RawObject|null>|null
     * @throws Throwable If the journal or an entry can't be read.
     */
    public static function readBefores(JournalInterface $journal, int $afterSequence = 0): ?array
    {
        $befores = [];
        $committed = false;

        foreach ($journal->read($afterSequence) as $entry) {
            if (self::ENTRY_COMMIT === $entry->type) {
                $committed = true;
            } elseif (self::ENTRY_BEFORE === $entry->type) {
                $uuid = $entry->payload['uuid'] ?? null;
                if (!is_string($uuid) || '' === $uuid) {
                    throw new TransactionRollbackException(sprintf('The journal entry %d names no object.', $entry->sequence));
                }
                if (!array_key_exists($uuid, $befores)) {
                    $befores[$uuid] = self::decodeRaw($uuid, $entry->payload['raw'] ?? null);
                }
            }
        }

        return $committed ? null : $befores;
    }

    /**
     * Rolls back the changes recorded in a journal; the objects must be locked by the caller.
     *
     * @param int $afterSequence Only the changes after this sequence number (Transaction::savepoint()).
     * @throws TransactionRollbackException If an object can't be restored; the others are restored nevertheless.
     */
    public static function undo(ObjectStorage $storage, JournalInterface $journal, int $afterSequence = 0): void
    {
        try {
            $befores = self::readBefores($journal, $afterSequence);
        } catch (TransactionRollbackException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TransactionRollbackException('The journal of the transaction can\'t be read: ' . $e->getMessage(), 0, $e);
        }

        if (null === $befores) {
            return; // committed
        }

        $failed = [];
        $lastError = null;

        // in reverse order of the changes, as they were made
        foreach (array_reverse($befores, true) as $uuid => $before) {
            try {
                self::restore($storage, (string)$uuid, $before);
            } catch (Throwable $e) {
                $failed[] = (string)$uuid;
                $lastError = $e;
                $storage->getLogger()?->log(new Exception(message: sprintf('Rolling back the object %s failed', $uuid), previous: $e));
            }
        }

        if ([] !== $failed) {
            throw new TransactionRollbackException(sprintf('Rolling back failed for %d object(s): %s', count($failed), implode(', ', $failed)), 0, $lastError);
        }
    }

    /**
     * Rolls back the transactions of a storage that were interrupted by a crash: those whose journal remained but
     * whose lock isn't held by a running process. Called when a storage is opened; it doesn't throw: a rollback
     * that fails enables safe mode and keeps the journal, so it is tried again when the storage is opened next,
     * after safe mode has been disabled.
     *
     * @return int The number of transactions rolled back.
     */
    public static function recoverAll(ObjectStorage $storage): int
    {
        $directory = self::directory($storage);
        $io = $storage->getIOAdapter();
        if (!$io->isDir($directory)) {
            return 0;
        }

        $names = @scandir($directory);
        if (false === $names) {
            return 0;
        }

        $recovered = 0;
        foreach ($names as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }

            // a journal is a directory; files are left alone, e.g. logs of the former format (migrations/003_004.php)
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if ($io->isDir($path) && self::recover($storage, $name)) {
                $recovered++;
            }
        }

        return $recovered;
    }

    /**
     * Rolls back one transaction, unless it is still running.
     *
     * @return bool Whether it was rolled back.
     */
    private static function recover(ObjectStorage $storage, string $transactionId): bool
    {
        $lockAdapter = $storage->getLockAdapter();
        $logger = $storage->getLogger();

        // without locks, a running transaction can't be told from an interrupted one; in safe mode, nothing can be
        // locked for writing: the rollback is left for later
        if (null === $lockAdapter || $storage->getStateHandler()?->safeModeEnabled()) {
            return false;
        }

        $transactionLock = self::lockUuid($transactionId);
        try {
            $lockAdapter->acquireExclusiveLock($transactionLock, 0);
        } catch (LockException) {
            return false; // still running
        }

        $journal = self::openJournal($storage, $transactionId);
        $uuids = [];
        try {
            $befores = self::readBefores($journal) ?? [];
            $uuids = array_map('strval', array_keys($befores));
            foreach ($uuids as $uuid) {
                $storage->retainLock($uuid);
            }

            self::undo($storage, $journal);
            $journal->remove();

            $storage->getEventDispatcher()?->dispatch(Events::TRANSACTION_RECOVERED, static fn() => new TransactionContext($transactionId, $uuids));

            return true;
        } catch (Throwable $e) {
            $logger?->log(new Exception(message: sprintf('Rolling back the interrupted transaction %s failed, enabling safe mode', $transactionId), previous: $e));
            try {
                $storage->getStateHandler()?->enableSafeMode();
            } catch (Throwable $safeModeError) {
                $logger?->log($safeModeError);
            }
            $storage->getEventDispatcher()?->dispatch(Events::TRANSACTION_RECOVERY_FAILED, static fn() => new TransactionContext($transactionId, $uuids, $e));

            return false;
        } finally {
            foreach ($uuids as $uuid) {
                try {
                    $storage->releaseRetainedLock($uuid);
                } catch (Throwable $e) {
                    $logger?->log($e);
                }
            }
            try {
                $lockAdapter->releaseLock($transactionLock);
            } catch (Throwable $e) {
                $logger?->log($e);
            }
        }
    }

    /**
     * Brings an object back to the state before the transaction.
     *
     * @throws Throwable
     */
    private static function restore(ObjectStorage $storage, string $uuid, ?RawObject $before): void
    {
        $storage->removeFromCache($uuid);

        if (null === $before) {
            // created by the transaction; the objects it referenced and that were written along stay
            if ($storage->exists($uuid)) {
                $storage->delete($uuid, applyChildDeletionPolicy: false);
            }
            return;
        }

        try {
            $current = $storage->readRaw($uuid);
        } catch (ObjectNotFoundException) {
            $current = null;
        }

        // unchanged, e.g. the commit failed before getting to it
        if (null !== $current
            && $current->metadata->getChecksum() === $before->metadata->getChecksum()
            && $current->metadata->getRevision() === $before->metadata->getRevision()
            && $current->data === $before->data) {
            return;
        }

        $storage->writeRaw($before, overwrite: true);
    }

    /**
     * @throws TransactionRollbackException If the state is malformed.
     */
    private static function decodeRaw(string $uuid, mixed $raw): ?RawObject
    {
        if (null === $raw) {
            return null;
        }

        if (!is_array($raw) || !is_string($raw['data'] ?? null) || !is_array($raw['metadata'] ?? null)) {
            throw new TransactionRollbackException(sprintf('The journal holds no valid state of the object %s.', $uuid));
        }

        try {
            $metadata = Metadata::createFromArray($raw['metadata']);
        } catch (Throwable $e) {
            throw new TransactionRollbackException(sprintf('The journal holds no valid metadata of the object %s.', $uuid), 0, $e);
        }
        $metadata->setUUID($uuid);

        $references = array_values(array_filter($raw['references'] ?? [], 'is_string'));

        return new RawObject($uuid, $raw['data'], $metadata, $references);
    }
}
