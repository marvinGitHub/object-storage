<?php

namespace melia\ObjectStorage\Storage;

interface StorageLockingInterface
{
    /**
     * The timeout duration (in seconds) for acquiring a lock.
     */
    const LOCK_TIMEOUT_DEFAULT = 10;

    public function lock(string $uuid, bool $shared = false, float $timeout = self::LOCK_TIMEOUT_DEFAULT);

    public function unlock(string $uuid);
}