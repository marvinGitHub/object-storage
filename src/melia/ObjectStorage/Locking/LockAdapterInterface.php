<?php

namespace melia\ObjectStorage\Locking;

interface LockAdapterInterface
{
    /**
     * The timeout duration (in seconds) for acquiring a lock.
     */
    const LOCK_TIMEOUT_DEFAULT = 10;

	const LOCK_TYPE_SHARED = 0;
	const LOCK_TYPE_EXCLUSIVE = 1;

    public function acquireExclusiveLock(string $uuid, int|float $timeout = LockAdapterInterface::LOCK_TIMEOUT_DEFAULT);

    public function releaseLock(string $uuid): void;

    public function isLockedByOtherProcess(?string $uuid): bool;

    public function isLockedByThisProcess(?string $uuid): bool;

    public function releaseActiveLocks(): void;


    public function getActiveLocks(): array;

    public function hasActiveSharedLock(string $uuid): bool;

    public function hasActiveExclusiveLock(string $uuid): bool;
}
