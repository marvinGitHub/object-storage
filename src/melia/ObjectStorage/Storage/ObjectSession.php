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
 * - which were loaded (read from their files), so a change of one reached through a reference is stored along,
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
    /** @var WeakMap<object, true> */
    private WeakMap $loaded;
    /** @var array<string, object> objects whose __unserialize() is running, by UUID */
    private array $restoring = [];

    public function __construct()
    {
        $this->clear();
    }

    /**
     * Forgets all objects.
     */
    public function clear(): void
    {
        $this->uuids = new WeakMap();
        $this->revisions = new WeakMap();
        $this->storing = new WeakMap();
        $this->loaded = new WeakMap();
    }

    /**
     * Records that an object was read from its files by this instance (load(), also as a reference of another one).
     */
    public function recordLoaded(object $object): void
    {
        $this->loaded[$object] = true;
    }

    /**
     * Whether an object was read from its files by this instance: storing a referencing object writes it along if it
     * changed (see ObjectWriter::shouldWriteChild()).
     */
    public function wasLoaded(object $object): bool
    {
        return isset($this->loaded[$object]);
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
