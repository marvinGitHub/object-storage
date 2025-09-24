<?php

namespace melia\ObjectStorage\Locking;

trait LockAdapterAwareTrait
{
    protected ?LockAdapterInterface $lockAdapter = null;

    /**
     * Retrieves the lock adapter instance.
     *
     * @return LockAdapterInterface|null Returns the lock adapter instance if available, or null if not set.
     */
    public function getLockAdapter(): ?LockAdapterInterface
    {
        return $this->lockAdapter;
    }

    /**
     * Sets the lock adapter instance.
     *
     * @param LockAdapterInterface|null $lockAdapter The locking adapter instance to set, or null to unset it.
     * @return void
     */
    public function setLockAdapter(?LockAdapterInterface $lockAdapter): void
    {
        $this->lockAdapter = $lockAdapter;
    }
}