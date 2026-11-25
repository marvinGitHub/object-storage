<?php

namespace melia\ObjectStorage\Locking;

use melia\ObjectStorage\Exception\LockException;

/**
 * Retained locks: exclusive locks of objects kept until the end of a transaction, across the operations that
 * otherwise release the lock of the object when they are done. Transactions use them to hold every object they
 * changed from its first change to the commit or rollback (and the crash recovery while it rolls them back).
 *
 * Why retaining instead of something more general:
 *
 * - The lock adapter (acquireExclusiveLock(), acquireSharedLock(), releaseLock(), releaseLockIfHeld()) stays the
 *   only way to lock; it gets no reentrant or counting locks: code locking objects follows "lock only if not held,
 *   release only what was locked here" (ObjectLocks: ObjectWriter, touch(), readRaw(), writeRaw(), OQL writes,
 *   moving objects).
 * - A transaction records an object while it is written (Transaction::track(), called under the lock the writing
 *   code took), so a lock taken "here" can become retained meanwhile: the releases of that code also ask
 *   isLockRetained(). store(), deleting and setLifetime() release a lock they didn't take (for store()
 *   intended: an exclusive load holds the lock until the object is stored, docs/concurrency.md) and ask it as well.
 * - Reentrant (counting) locks in the adapter would make retaining unnecessary, but they change the behavior of the
 *   public LockAdapterInterface and need a special rule for exclusive loads, which hand their lock over to store().
 *   That is more risk for the same result while transactions are the only code holding locks across operations.
 * - Locks of resources (class name registry, index managers, maintenance, the lock of a transaction itself) don't
 *   belong to objects; retaining doesn't apply to them.
 *
 * Retaining is bookkeeping of this storage instance: the lock itself is an ordinary lock of the lock adapter, which
 * other processes wait for as usual.
 *
 * The locks themselves are taken and released by the component ObjectLocks.
 *
 * Part of ObjectStorage, kept apart for readability.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait RetainedLocksTrait
{
    /**
     * The UUIDs whose exclusive locks are retained; kept by the storage, so they survive a new lock adapter.
     */
    private ?RetainedLocks $retainedLocks = null;

    /**
     * The locks of objects, with the lock adapter of the storage as it is now and its retained locks.
     *
     * @internal Not part of the public API; may change in any release.
     */
    public function objectLocks(): ObjectLocks
    {
        $adapter = $this->getLockAdapter();
        $retained = $this->retainedLocks ??= new RetainedLocks();

        return $this->component(
            ObjectLocks::class,
            [$adapter, $retained],
            static fn(): ObjectLocks => new ObjectLocks($adapter, $retained),
        );
    }

    /**
     * Locks an object exclusively and keeps it locked across store(), delete() and setLifetime(), which otherwise
     * release the lock when they are done, until releaseRetainedLock().
     *
     * @param string $uuid The object to lock; it doesn't need to exist.
     * @param int|float $timeout How long to wait for a lock held by another process, in seconds.
     * @throws LockException If the lock can't be acquired in time.
     * @internal Not part of the public API; may change in any release.
     */
    public function retainLock(string $uuid, int|float $timeout = LockAdapterInterface::LOCK_TIMEOUT_DEFAULT): void
    {
        $this->objectLocks()->retain($uuid, $timeout);
    }

    /**
     * Stops retaining the lock of an object (retainLock()) and releases it.
     *
     * @internal Not part of the public API; may change in any release.
     */
    public function releaseRetainedLock(string $uuid): void
    {
        $this->objectLocks()->releaseRetained($uuid);
    }

    /**
     * Releases the lock of an object if this process holds it, unless it is retained (retainLock()): what operations
     * do when they are done with an object.
     *
     * @internal Not part of the public API; may change in any release.
     */
    public function releaseLockUnlessRetained(string $uuid): void
    {
        $this->objectLocks()->releaseUnlessRetained($uuid);
    }

    /**
     * Whether the lock of an object is retained (retainLock()): then it must not be released by the operation
     * asking.
     *
     * @internal Not part of the public API; may change in any release.
     */
    public function isLockRetained(?string $uuid): bool
    {
        return $this->objectLocks()->isRetained($uuid);
    }
}
