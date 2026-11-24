<?php

namespace melia\ObjectStorage\Locking;

use Closure;
use melia\ObjectStorage\Exception\LockException;

/**
 * The locks of objects as operations take them: "lock only if not held, release only what was locked here", and
 * never a lock retained by a transaction (see RetainedLocksTrait for why retaining).
 *
 * Without a lock adapter nothing is locked; the work is done all the same.
 */
final readonly class ObjectLocks
{
    public function __construct(
        private ?LockAdapterInterface $adapter,
        private RetainedLocks         $retained,
    )
    {
    }

    /**
     * Does some work under the exclusive lock of an object: locked here unless this process holds it already, and
     * released afterwards (also on failure) if it was locked here and isn't retained meanwhile: a transaction
     * records an object while it is written, under the lock taken here.
     *
     * @template T
     * @param Closure(): T $work
     * @param bool $lock False to do the work without locking, e.g. where nothing can be locked (safe mode).
     * @return T
     * @throws LockException If the lock can't be acquired; the work isn't done then.
     */
    public function exclusively(string $uuid, Closure $work, bool $lock = true): mixed
    {
        $lockedHere = false;
        if ($lock && null !== $this->adapter && !$this->adapter->hasActiveExclusiveLock($uuid)) {
            $this->adapter->acquireExclusiveLock($uuid);
            $lockedHere = true;
        }

        try {
            return $work();
        } finally {
            if ($lockedHere) {
                $this->releaseUnlessRetained($uuid);
            }
        }
    }

    /**
     * Does some work under a shared lock of an object, unless this process holds a lock of it already (then under
     * that one); a shared lock taken here is released afterwards, also on failure.
     *
     * @template T
     * @param Closure(): T $work
     * @return T
     * @throws LockException If the lock can't be acquired; the work isn't done then.
     */
    public function shared(string $uuid, Closure $work): mixed
    {
        $lockedHere = false;
        if (null !== $this->adapter && !$this->adapter->isLockedByThisProcess($uuid)) {
            $this->adapter->acquireSharedLock($uuid);
            $lockedHere = true;
        }

        try {
            return $work();
        } finally {
            if ($lockedHere) {
                $this->releaseUnlessRetained($uuid);
            }
        }
    }

    /**
     * Locks an object exclusively (unless this process holds the lock already) and retains the lock: operations
     * don't release it until releaseRetained().
     *
     * @param int|float $timeout How long to wait for a lock held by another process, in seconds.
     * @throws LockException If the lock can't be acquired in time; nothing is retained then.
     */
    public function retain(string $uuid, int|float $timeout = LockAdapterInterface::LOCK_TIMEOUT_DEFAULT): void
    {
        if (null !== $this->adapter && !$this->adapter->hasActiveExclusiveLock($uuid)) {
            $this->adapter->acquireExclusiveLock($uuid, $timeout);
        }

        $this->retained->add($uuid);
    }

    /**
     * Stops retaining the lock of an object and releases it.
     */
    public function releaseRetained(string $uuid): void
    {
        $this->retained->remove($uuid);
        $this->adapter?->releaseLockIfHeld($uuid);
    }

    /**
     * Releases the lock of an object if this process holds it, unless it is retained: what operations do when they
     * are done with an object.
     */
    public function releaseUnlessRetained(string $uuid): void
    {
        if (!$this->retained->contains($uuid)) {
            $this->adapter?->releaseLockIfHeld($uuid);
        }
    }

    /**
     * Whether the lock of an object is retained: then it must not be released by the operation asking.
     */
    public function isRetained(?string $uuid): bool
    {
        return $this->retained->contains($uuid);
    }
}
