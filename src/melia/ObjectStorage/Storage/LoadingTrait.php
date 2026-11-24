<?php

namespace melia\ObjectStorage\Storage;

use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\CorruptedFileException;
use melia\ObjectStorage\Exception\InvalidFileFormatException;
use melia\ObjectStorage\Exception\ObjectLoadingFailureException;
use Throwable;

/**
 * Loading objects: load() (cache, locks, events) → ObjectReader::read() → ObjectFiles::read().
 *
 * Part of ObjectStorage, kept apart for readability.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait LoadingTrait
{
    /**
     * The reading of objects, with the components and the strategy, class rename map, state handler, event
     * dispatcher and logger of the storage as they are now.
     */
    protected function objectReader(): ObjectReader
    {
        $objectFiles = $this->objectFiles();
        $session = $this->session;
        $cache = $this->objectCache();
        $strategy = $this->getStrategy();
        $classRenameMap = $this->getClassRenameMap();
        $stateHandler = $this->getStateHandler();
        $dispatcher = $this->getEventDispatcher();
        $logger = $this->getLogger();

        return $this->component(
            ObjectReader::class,
            [$objectFiles, $session, $cache, $strategy, $classRenameMap, $stateHandler, $dispatcher, $logger],
            fn(): ObjectReader => new ObjectReader($this, $objectFiles, $session, $cache, $strategy, $classRenameMap, $stateHandler, $dispatcher, $logger),
        );
    }

    /**
     * Loads an object from persistent storage using its unique identifier.
     * The method optionally applies locking mechanisms during the loading process.
     *
     * @param string $uuid The unique identifier of the object to be loaded.
     * @param bool $exclusive Determines whether the object should remain locked after loading (true) or not (false).
     * @return object|null Returns the loaded object if found, or null if the object does not exist.
     * @throws Throwable If an error occurs during the loading process.
     */
    public function load(string $uuid, bool $exclusive = false): ?object
    {
        $eventDispatcher = $this->getEventDispatcher();
        $lockAdapter = $this->getLockAdapter();

        $eventDispatcher?->dispatch(Events::BEFORE_LOAD, static fn() => new Context($uuid));

        if ($this->expired($uuid)) {
            /* do not delete an expired object since the ttl might be updated later */
            return null;
        }

        // For non-exclusive reads a cache hit can be served immediately without locking,
        // as no exclusivity guarantee was requested. An exclusive load always reads the
        // stored state under the lock (see below), so it never short-circuits here.
        if (!$exclusive) {
            $cached = $this->objectCache()->get($uuid);
            if (null !== $cached) {
                $this->rememberLoaded($cached, $uuid);
                return $cached;
            }
        }

        // a lock held before this call belongs to the caller (e.g. a previous exclusive load or a
        // running store) and must survive this load, both on success and on failure
        $lockedBefore = $lockAdapter?->isLockedByThisProcess($uuid) ?? false;

        try {
            if ($exclusive) {
                // an exclusive load is the start of a read-modify-write: it must return the state
                // stored right now, not a cached copy, which doesn't know about changes of other
                // processes; reading from storage replaces the cached copy as well
                $lockAdapter?->acquireExclusiveLock($uuid);
            } else {
                $lockAdapter?->acquireSharedLock($uuid);
            }

            $object = $this->loadFromFiles($uuid);

            if (!$exclusive && !$lockedBefore) {
                $lockAdapter?->releaseLock($uuid);
            }

            $eventDispatcher?->dispatch(Events::AFTER_LOAD, static fn() => new Context($uuid));

            if (null !== $object) {
                $this->rememberLoaded($object, $uuid);
            }

            return $object;
        } catch (Throwable $e) {
            if (!$lockedBefore) {
                $lockAdapter?->releaseLockIfHeld($uuid);
            }
            $eventDispatcher?->dispatch(Events::OBJECT_LOADING_FAILURE, static fn() => new Context($uuid));
            throw new ObjectLoadingFailureException(message: sprintf('Unable to load object with uuid: %s', $uuid), previous: $e);
        }
    }

    /**
     * Loads a referenced object, for a LazyLoadReference or the references passed to __unserialize(). Unlike
     * load() by the caller, the instance isn't remembered under its UUID (see rememberLoaded()).
     *
     * @internal
     * @throws Throwable
     */
    public function loadReferenced(string $uuid): ?object
    {
        return $this->session->loadingReferences(fn(): ?object => $this->load($uuid));
    }

    /**
     * Remembers the UUID of an instance returned by load(), so storing it again updates the object instead of
     * storing a copy under a new UUID (objects without AwareInterface don't carry their UUID themselves).
     *
     * Not for objects loaded as references of another one: storing the referencing object writes referenced objects
     * by the child persistence policy, which (by default) doesn't write existing ones; they are written as new
     * objects, with their changes, as long as they aren't known (as before).
     */
    private function rememberLoaded(object $object, string $uuid): void
    {
        $this->session->rememberLoaded($object, $uuid);
    }

    /**
     * Loads an object from its files, see ObjectReader::read().
     *
     * @param bool $addToCache Whether the loaded object is put into the cache; false to read a stored state
     *                         without replacing the cached object, e.g. the previous state of an object being stored.
     * @return object|null The loaded object or null if it doesn't exist.
     * @throws InvalidFileFormatException
     * @throws CorruptedFileException
     * @throws Throwable
     */
    protected function loadFromFiles(string $uuid, bool $addToCache = true): ?object
    {
        return $this->objectReader()->read($uuid, $addToCache);
    }
}
