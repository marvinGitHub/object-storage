<?php

namespace melia\ObjectStorage\Storage;

use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\Context\ObjectPersistenceContext;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\ConstraintViolationException;
use melia\ObjectStorage\Exception\Exception;
use melia\ObjectStorage\Exception\ObjectSavingFailureException;
use melia\ObjectStorage\Exception\RevisionConflictException;
use melia\ObjectStorage\LazyLoadReference;
use Throwable;

/**
 * Storing objects: store() (UUID, lock, events) → ObjectWriter::write() (per object of the graph).
 *
 * Part of ObjectStorage, kept apart for readability.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait StoringTrait
{
    /**
     * The writing of objects, with the components and the strategy, event dispatcher and logger of the storage as
     * they are now.
     */
    protected function objectWriter(): ObjectWriter
    {
        $objectFiles = $this->objectFiles();
        $metadata = $this->metadataStore();
        $stubs = $this->stubIndex();
        $cache = $this->objectCache();
        $locks = $this->objectLocks();
        $session = $this->session;
        $reader = $this->objectReader();
        $constraints = $this->constraintChecker();
        $strategy = $this->getStrategy();
        $dispatcher = $this->getEventDispatcher();
        $logger = $this->getLogger();

        return $this->component(
            ObjectWriter::class,
            [$objectFiles, $metadata, $stubs, $cache, $locks, $session, $reader, $constraints, $strategy, $dispatcher, $logger],
            static fn(): ObjectWriter => new ObjectWriter($objectFiles, $metadata, $stubs, $cache, $locks, $session, $reader, $constraints, $strategy, $dispatcher, $logger),
        );
    }

    /**
     * Stores a given object in the storage system, assigning a UUID if necessary.
     * If the object is an instance of LazyLoadReference and is not loaded, it directly returns its UUID.
     * Handles locking and ensures proper updates even for previously stored objects.
     *
     * @param object $object The object to be stored.
     * @param string|null $uuid Optional. A specific UUID to assign to the object, or null to auto-generate one.
     * @param int|null $ttl Optional. The time-to-live for
     *
     * @return string The UUID of the object.
     * @throws Exception If safe mode is enabled.
     * @throws RevisionConflictException With optimistic locking, if the object was stored since it was loaded.
     * @throws ConstraintViolationException If a constraint is violated (also by a referenced object stored along).
     * @throws ObjectSavingFailureException If it can't be stored otherwise.
     */
    public function store(object $object, ?string $uuid = null, ?int $ttl = null): string
    {
        if ($this->getStateHandler()?->safeModeEnabled()) {
            throw new Exception('Safe mode is enabled. Object cannot be stored.');
        }

        $eventDispatcher = $this->getEventDispatcher();
        $eventDispatcher?->dispatch(Events::BEFORE_STORE, static fn() => new ObjectPersistenceContext($uuid, $object));

        // LazyLoadReference: not loaded → only return UUID
        if ($object instanceof LazyLoadReference) {
            if (false === $object->isLoaded()) {
                return $object->getUUID();
            }
            // read the UUID from the reference, the referenced object doesn't necessarily provide one
            $uuid = $object->getUUID();
            $object = $object->getObject();
        }

        $lockAdapter = $this->getLockAdapter();

        try {
            // 1) prefer UUID from parameter; otherwise from AwareInterface; otherwise REUSE from objectUuidMap mapping,
            //    only generate a new UUID if none is available
            $uuid ??= $this->getUuidOf($object) ?? $this->getNextAvailableUuid();

            /* use metadata to check for existence, since this will already warm up the metadata cache;
               this has to happen after resolving the UUID, otherwise updates without an explicit UUID go unnoticed */
            $exists = null !== $this->loadMetadata($uuid);

            if ($exists) {
                $eventDispatcher?->dispatch(Events::BEFORE_UPDATE, static fn() => new ObjectPersistenceContext($uuid, $object));
            }

            // 2) update mapping (important for references and later store calls)
            $this->session->remember($object, $uuid);

            // 3) no early return: ALWAYS write, so that updates are detected via checksum
            $lockAdapter?->acquireExclusiveLock($uuid);

            /* if classname change we should remove the previous stub */
            $previousClassname = $this->getClassName($uuid);
            $removePreviousStub = null !== $previousClassname && $previousClassname !== $object::class;

            if ($removePreviousStub) {
                $this->stubIndex()->remove($previousClassname, $uuid);
            }

            $this->objectWriter()->write($object, $uuid, $ttl, $this->getActiveTransaction());

            $this->objectLocks()->releaseUnlessRetained($uuid);

            if ($exists) {
                $eventDispatcher?->dispatch(Events::AFTER_UPDATE, static fn() => new ObjectPersistenceContext($uuid, $object));
            }

            $eventDispatcher?->dispatch(Events::AFTER_STORE, static fn() => new Context($uuid));

            return $uuid;
        } catch (Throwable $e) {
            $this->objectLocks()->releaseUnlessRetained($uuid);
            $eventDispatcher?->dispatch(Events::OBJECT_SAVING_FAILURE, static fn() => new Context($uuid));

            // passed on as they are (ObjectSavingFailureExceptions too), so callers can catch the conflict or the
            // violation itself; a violation may come from a referenced object stored along
            if ($e instanceof RevisionConflictException || $e instanceof ConstraintViolationException) {
                throw $e;
            }

            throw new ObjectSavingFailureException(message: sprintf('Unable to store object with uuid: %s', $uuid), previous: $e);
        }
    }
}
