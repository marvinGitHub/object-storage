<?php

namespace melia\ObjectStorage\Storage;

use Closure;
use WeakMap;

/**
 * What a storage instance knows of the objects it loaded and stored, as long as they live (held weakly):
 *
 * - their UUIDs, so storing an object again updates it instead of storing a copy under a new UUID (objects without
 *   AwareInterface don't carry their UUID themselves),
 * - the revision each was loaded or stored with, for optimistic locking,
 * - the objects being stored right now, so cycles in a graph are stored once.
 */
final class ObjectSession
{
    /** @var WeakMap<object, string> */
    private WeakMap $uuids;
    /** @var WeakMap<object, int> */
    private WeakMap $revisions;
    /** @var WeakMap<object, true> */
    private WeakMap $storing;
    /** @var array<string, object> objects whose __unserialize() is running, by UUID */
    private array $restoring = [];
    /** referenced objects being loaded (nested), whose instances aren't remembered (see rememberLoaded()) */
    private int $loadingReferences = 0;

    public function __construct()
    {
        $this->clear();
    }

    /**
     * Forgets all objects (not the references being loaded right now).
     */
    public function clear(): void
    {
        $this->uuids = new WeakMap();
        $this->revisions = new WeakMap();
        $this->storing = new WeakMap();
    }

    /**
     * The UUID an object was loaded or stored under by this instance, if known.
     */
    public function uuidOf(object $object): ?string
    {
        return $this->uuids[$object] ?? null;
    }

    /**
     * Remembers the UUID of an object, replacing one known before.
     */
    public function remember(object $object, string $uuid): void
    {
        $this->uuids[$object] = $uuid;
    }

    /**
     * Remembers the UUID of an object unless one is known already.
     */
    public function rememberIfUnknown(object $object, string $uuid): void
    {
        if (!isset($this->uuids[$object])) {
            $this->uuids[$object] = $uuid;
        }
    }

    /**
     * Remembers the UUID of an instance returned by load(), unless one is known already.
     *
     * Not for objects loaded as references of another one: storing the referencing object writes referenced objects
     * by the child persistence policy, which (by default) doesn't write existing ones; they are written as new
     * objects, with their changes, as long as they aren't known.
     */
    public function rememberLoaded(object $object, string $uuid): void
    {
        if (0 === $this->loadingReferences) {
            $this->rememberIfUnknown($object, $uuid);
        }
    }

    /**
     * Loads referenced objects: the instances loaded meanwhile aren't remembered (see rememberLoaded()).
     *
     * @template T
     * @param Closure(): T $load
     * @return T
     */
    public function loadingReferences(Closure $load): mixed
    {
        $this->loadingReferences++;
        try {
            return $load();
        } finally {
            $this->loadingReferences--;
        }
    }

    /**
     * The revision an object was loaded or stored with by this instance; null if it wasn't.
     */
    public function revisionOf(object $object): ?int
    {
        return $this->revisions[$object] ?? null;
    }

    public function recordRevision(object $object, int $revision): void
    {
        $this->revisions[$object] = $revision;
    }

    /**
     * Whether an object is being stored right now (further up in the graph being stored).
     */
    public function isBeingStored(object $object): bool
    {
        return isset($this->storing[$object]);
    }

    /**
     * Restores an object by its __unserialize(): while it runs, a reference cycle back to the object gets this
     * instance (restored()) instead of loading it again endlessly (as PHP's unserialize() does).
     *
     * @template T
     * @param Closure(): T $restore
     * @return T
     */
    public function restoring(string $uuid, object $object, Closure $restore): mixed
    {
        $this->restoring[$uuid] = $object;
        try {
            return $restore();
        } finally {
            unset($this->restoring[$uuid]);
        }
    }

    /**
     * The object being restored under a UUID right now (restoring()), if any.
     */
    public function restored(string $uuid): ?object
    {
        return $this->restoring[$uuid] ?? null;
    }

    public function startStoring(object $object): void
    {
        $this->storing[$object] = true;
    }

    public function stopStoring(object $object): void
    {
        unset($this->storing[$object]);
    }
}
