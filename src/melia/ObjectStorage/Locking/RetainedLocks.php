<?php

namespace melia\ObjectStorage\Locking;

/**
 * The objects whose exclusive locks are retained until the end of a transaction (see ObjectLocks::retain()).
 *
 * Bookkeeping of one storage instance, kept apart from ObjectLocks so it survives a new lock adapter: ObjectLocks is
 * created again then, the retained locks are not forgotten.
 */
final class RetainedLocks
{
    /** @var array<string, true> */
    private array $uuids = [];

    public function add(string $uuid): void
    {
        $this->uuids[$uuid] = true;
    }

    public function remove(string $uuid): void
    {
        unset($this->uuids[$uuid]);
    }

    public function contains(?string $uuid): bool
    {
        return null !== $uuid && isset($this->uuids[$uuid]);
    }
}
