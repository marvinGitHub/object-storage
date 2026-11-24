<?php

namespace melia\ObjectStorage\Storage;

use Closure;
use DateTimeInterface;
use Generator;
use melia\ObjectStorage\Context\GraphBuilderContext;
use melia\ObjectStorage\Event\Context\ClassnameChangeContext;
use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\Context\ObjectPersistenceContext;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\ClosureSerializationNotSupportedException;
use melia\ObjectStorage\Exception\ConstraintViolationException;
use melia\ObjectStorage\Exception\DanglingReferenceException;
use melia\ObjectStorage\Exception\Exception;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\Exception\MaxDepthExceededException;
use melia\ObjectStorage\Exception\MetadataSavingFailureException;
use melia\ObjectStorage\Exception\ObjectSavingFailureException;
use melia\ObjectStorage\Exception\ResourceSerializationNotSupportedException;
use melia\ObjectStorage\Exception\RevisionConflictException;
use melia\ObjectStorage\Exception\SafeModeActivationFailedException;
use melia\ObjectStorage\Exception\SerializationFailureException;
use melia\ObjectStorage\Exception\StubSavingFailureException;
use melia\ObjectStorage\Exception\UnsupportedKeyException;
use melia\ObjectStorage\Exception\UnsupportedTypeException;
use melia\ObjectStorage\LazyLoadReference;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\Reflection\Reflection;
use melia\ObjectStorage\Serialization\DateTimeValue;
use melia\ObjectStorage\Serialization\LifecycleGuard;
use melia\ObjectStorage\Strategy\Policy\ChildPersistence;
use melia\ObjectStorage\Strategy\Policy\PropertyPersistence;
use melia\ObjectStorage\Strategy\StrategyInterface;
use melia\ObjectStorage\UUID\Exception\GenerationFailureException;
use melia\ObjectStorage\UUID\Exception\InvalidUUIDException;
use melia\ObjectStorage\UUID\Helper;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;
use Throwable;
use WeakMap;

/**
 * Storing objects: store() → storeObject() (per object of the graph) → serializeGraph() → writeObjectFiles().
 *
 * Part of ObjectStorage, kept apart for readability.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait StoringTrait
{
    protected WeakMap $processingStack;
    /**
     * Stores a given object in the storage system, assigning a UUID if necessary.
     * If the object is an instance of LazyLoadReference and is not loaded, it directly returns its UUID.
     * Handles locking and ensures proper updates even for previously stored objects.
     *
     * @param object $object The object to be stored.
     * @param string|null $uuid Optional. A specific UUID to assign to the object, or null to auto-generate one.
     * @param int|null $ttl Optional. The time-to-live for
     *
     * @return string
     * @throws DanglingReferenceException
     * @throws Exception
     * @throws GenerationFailureException
     * @throws IOException
     * @throws InvalidArgumentException
     * @throws InvalidUUIDException
     * @throws MaxDepthExceededException
     * @throws MetadataSavingFailureException
     * @throws ObjectSavingFailureException
     * @throws ReflectionException
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     * @throws StubSavingFailureException
     * @throws UnsupportedTypeException
     * @throws Throwable
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
            $this->identityMap[$object] = $uuid;

            // 3) no early return: ALWAYS call storeObject so that updates are detected via checksum
            $lockAdapter?->acquireExclusiveLock($uuid);

            /* if classname change we should remove the previous stub */
            $previousClassname = $this->getClassName($uuid);
            $removePreviousStub = null !== $previousClassname && $previousClassname !== $object::class;

            if ($removePreviousStub) {
                $this->deleteStub($previousClassname, $uuid);
            }

            $this->storeObject($object, $uuid, $ttl);

            $this->releaseLockUnlessRetained($uuid);

            if ($exists) {
                $eventDispatcher?->dispatch(Events::AFTER_UPDATE, static fn() => new ObjectPersistenceContext($uuid, $object));
            }

            $eventDispatcher?->dispatch(Events::AFTER_STORE, static fn() => new Context($uuid));

            return $uuid;
        } catch (Throwable $e) {
            $this->releaseLockUnlessRetained($uuid);
            $eventDispatcher?->dispatch(Events::OBJECT_SAVING_FAILURE, static fn() => new Context($uuid));

            // passed on as they are (ObjectSavingFailureExceptions too), so callers can catch the conflict or the
            // violation itself; a violation may come from a referenced object stored along
            if ($e instanceof RevisionConflictException || $e instanceof ConstraintViolationException) {
                throw $e;
            }

            throw new ObjectSavingFailureException(message: sprintf('Unable to store object with uuid: %s', $uuid), previous: $e);
        }
    }

    /**
     * Stores one object of a graph: serializes it (serializeGraph(), which stores the objects it references first,
     * calling this method for each), and writes its files under its lock (writeObjectFiles()) if its data changed,
     * or only its metadata (writeMetadata()); registers its stub and caches it. Called by store() for the root.
     *
     * @param object $object The object to store.
     * @param string $uuid Its UUID.
     * @param float|int|null $ttl Its lifetime in seconds, null to keep it.
     * @param GraphBuilderContext|null $contextParent The context of the referencing object, null for the root.
     * @throws DanglingReferenceException
     * @throws GenerationFailureException
     * @throws IOException
     * @throws InvalidArgumentException
     * @throws InvalidUUIDException
     * @throws MaxDepthExceededException
     * @throws MetadataSavingFailureException
     * @throws ObjectSavingFailureException
     * @throws ReflectionException
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     * @throws StubSavingFailureException
     * @throws Throwable
     * @throws UnsupportedTypeException
     */
    protected function storeObject(object $object, string $uuid, null|float|int $ttl = null, ?GraphBuilderContext $contextParent = null): void
    {
        $eventDispatcher = $this->getEventDispatcher();

        try {
            if (!isset($this->identityMap[$object])) {
                $this->identityMap[$object] = $uuid;
            }

            Helper::assign($object, $uuid);

            if (isset($this->processingStack[$object])) {
                return;
            }

            $this->processingStack[$object] = true;

            $metadata = new Metadata();
            $metadata->setChecksumAlgorithm($this->getStrategy()?->getChecksumAlgorithm() ?? static::CHECKSUM_ALGORITHM_DEFAULT);
            $now = microtime(true);
            $metadata->setUuid($uuid);
            $metadata->setClassName($className = get_class($object));
            $metadata->setVersion(Metadata::VERSION);
            $metadata->setTimestampExpiresAt($ttl ? $now + $ttl : null);

            $loadedMetadata = $this->loadMetadata($uuid);
            $exists = null !== $loadedMetadata;
            // created when first stored; modified when its data is written (below)
            $metadata->setTimestampCreation($loadedMetadata?->getTimestampCreation() ?? $now);
            $metadata->setTimestampModification($loadedMetadata?->getTimestampModification() ?? $now);

            if (false === $exists) {
                $eventDispatcher?->dispatch(Events::BEFORE_INITIAL_STORE, static fn() => new ObjectPersistenceContext($uuid, $object));
            }

            $context = new GraphBuilderContext($object, $metadata, $contextParent ? $contextParent->getLevel() : 1);

            $graph = $this->serializeGraph($context);
            $strategy = $this->getStrategy();
            $serializedGraph = $strategy?->serialize($graph, $strategy?->getMaxDepth() ?? StrategyInterface::DEFAULT_MAX_DEPTH);

            if (null === $serializedGraph) {
                $eventDispatcher?->dispatch(Events::GRAPH_SERIALIZATION_FAILURE, static fn() => new Context($uuid));
                throw new SerializationFailureException('Unable serialize graph');
            }

            $metadata->setChecksum(static::generateChecksum($serializedGraph, $metadata->getChecksumAlgorithm()));

            $previousClassname = $loadedMetadata?->getClassName();

            // metadata without a checksum (written by other tools or by hand) is written again with it
            $checksumChanged = null === $loadedMetadata || !$loadedMetadata->hasChecksum() || $metadata->getChecksum() !== $loadedMetadata->getChecksum();
            $classNameChanged = null !== $previousClassname && $metadata->getClassName() !== $previousClassname;
            $lifetimeChanged = $ttl !== $loadedMetadata?->getLifetime();

            $writeGraph = $checksumChanged || $classNameChanged;
            // the metadata (and the cached object) also if only the lifetime changed
            $writeMetadata = $writeGraph || $lifetimeChanged;
            $writeStub = !$exists || $classNameChanged;

            // store() locks the object it stores, but not the referenced objects it writes along with it:
            // lock an object while writing it, so concurrent writers can't mix up its files. The lock is only
            // taken now, after its referenced objects are written, so no more than two locks are held at once.
            $lockAdapter = $this->getLockAdapter();
            $lockedHere = false;
            if ($writeMetadata && null !== $lockAdapter && false === $lockAdapter->hasActiveExclusiveLock($uuid)) {
                $lockAdapter->acquireExclusiveLock($uuid);
                $lockedHere = true;
            }

            try {
                $currentMetadata = $writeMetadata ? $this->stampRevision($object, $uuid, $metadata, $exists, $writeGraph) : null;

                if ($writeGraph) {
                    $this->writeGraph($object, $uuid, $serializedGraph, $metadata, $currentMetadata?->getRevision(), $exists);
                }

                if ($classNameChanged) {
                    $eventDispatcher?->dispatch(Events::CLASSNAME_CHANGED, static fn() => new ClassnameChangeContext($uuid, $previousClassname, $metadata->getClassName()));
                }

                if ($writeGraph) {
                    $eventDispatcher?->dispatch(Events::METADATA_SAVED, static fn() => new Context($uuid));
                    $this->cacheMetadata($metadata);
                } elseif ($writeMetadata) {
                    $this->writeMetadata($metadata);
                }

                if ($writeMetadata) {
                    $this->revisions[$object] = $metadata->getRevision();
                    $this->addToCache($uuid, $object, $ttl);
                }

                if ($writeStub) {
                    $this->createStub($className, $uuid);
                }
            } finally {
                // a transaction may have retained the lock while the object was written (track())
                if ($lockedHere && !$this->isLockRetained($uuid)) {
                    $lockAdapter->releaseLock($uuid);
                }
            }
        } finally {
            unset($this->processingStack[$object]);
        }
    }

    /**
     * Whether an object referenced by the object being stored is stored along, by the child persistence policy of
     * the strategy. A callback that fails stores nothing (fail-closed).
     */
    private function shouldWriteChild(GraphBuilderContext $context, object $child, string $uuid, array $path): bool
    {
        $strategy = $this->getStrategy();
        $exists = $this->exists($uuid);

        switch ($strategy?->getPolicyChildPersistence() ?? StrategyInterface::DEFAULT_POLICY_CHILD_PERSISTENCE) {
            case ChildPersistence::IF_NOT_EXIST:
                return !$exists;
            case ChildPersistence::NEVER:
                return false;
            case ChildPersistence::CALLBACK:
                try {
                    return $strategy?->shouldPersistChild($context, $child, $uuid, $exists, $path) ?? false;
                } catch (Throwable $e) {
                    $this->getLogger()?->log($e);
                    return false;
                }
            default: // ChildPersistence::ALWAYS
                return true;
        }
    }

    /**
     * Sets the revision and the timestamps of the metadata to write, from the metadata on disk (read under the lock:
     * the cached metadata may be outdated); a write of the data is a new revision and a modification.
     *
     * @return Metadata|null The metadata on disk, null for a new object.
     * @throws RevisionConflictException With optimistic locking, if the object was stored since this instance loaded
     *         or stored it.
     */
    private function stampRevision(object $object, string $uuid, Metadata $metadata, bool $exists, bool $writeGraph): ?Metadata
    {
        $tracked = isset($this->revisions[$object]);
        $currentMetadata = ($exists || $tracked) ? $this->reloadMetadata($uuid) : null;
        $currentRevision = $currentMetadata?->getRevision() ?? 0;

        // an object deleted in the meantime (by this or another process) is created again, which is no conflict
        if ($tracked && null !== $currentMetadata && true === $this->getStrategy()?->optimisticLockingEnabled() && $this->revisions[$object] !== $currentRevision) {
            $expectedRevision = $this->revisions[$object];
            $this->getEventDispatcher()?->dispatch(Events::REVISION_CONFLICT, static fn() => new Context($uuid));
            throw new RevisionConflictException(sprintf(
                'Object with uuid %s has been changed in the meantime. Expected revision: %u Current: %u',
                $uuid,
                $expectedRevision,
                $currentRevision
            ));
        }

        $metadata->setRevision($writeGraph ? $currentRevision + 1 : $currentRevision);
        // as on disk (the cache may be outdated)
        if (null !== $currentMetadata) {
            $metadata->setTimestampCreation($currentMetadata->getTimestampCreation());
            $metadata->setTimestampModification($currentMetadata->getTimestampModification());
        }
        if ($writeGraph) {
            // a new object: modified when created
            $metadata->setTimestampModification(null === $currentMetadata ? $metadata->getTimestampCreation() : microtime(true));
        }

        return $currentMetadata;
    }

    /**
     * Writes the data and metadata files of an object under its lock: constraints are checked first (a violation
     * prevents the write), OBJECT_SAVED or OBJECT_WRITE_FAILED is dispatched with the previous object.
     *
     * @throws ObjectSavingFailureException
     * @throws ConstraintViolationException
     */
    private function writeGraph(object $object, string $uuid, string $serializedGraph, Metadata $metadata, ?int $previousRevision, bool $exists): void
    {
        $eventDispatcher = $this->getEventDispatcher();

        // the previous object must be loaded before the data file is overwritten, otherwise the new data would be
        // validated against the old metadata (checksum mismatch → safe mode); it is read from the files, not the
        // cache, which holds the object being stored, and is not cached, so it doesn't replace the current object
        $previousObject = null;
        if ($exists && $this->hasListeners(Events::OBJECT_SAVED, Events::OBJECT_WRITE_FAILED)) {
            try {
                $previousObject = $this->loadFromFiles($uuid, addToCache: false);
            } catch (Throwable $e) {
                $this->getLogger()?->log($e);
            }
        }

        $revision = $metadata->getRevision();
        $context = static fn(): ObjectPersistenceContext => new ObjectPersistenceContext($uuid, $object, $previousObject, $revision, $previousRevision);

        // with the data as it will be stored
        $finalizeConstraints = $this->checkConstraints($uuid, $metadata->getClassName(), $serializedGraph, $metadata->getReservedReferenceName());
        $written = false;
        try {
            $this->writeObjectFiles($uuid, $serializedGraph, $metadata);
            $written = true;
            $eventDispatcher?->dispatch(Events::OBJECT_SAVED, $context);
        } catch (Throwable $e) {
            $eventDispatcher?->dispatch(Events::OBJECT_WRITE_FAILED, $context);
            throw new ObjectSavingFailureException(message: sprintf('Unable to save object with uuid: %s', $uuid), previous: $e);
        } finally {
            $finalizeConstraints($written);
        }
    }

    /**
     * Creates a graph representation of the given object's properties and stores
     * referenced child elements, ensuring deterministic property order.
     *
     * @param GraphBuilderContext $context
     * @return array An associative array representing the graph structure of the object's properties.
     * @throws DanglingReferenceException
     * @throws GenerationFailureException
     * @throws IOException
     * @throws InvalidArgumentException
     * @throws InvalidUUIDException
     * @throws MaxDepthExceededException
     * @throws ReflectionException
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     * @throws Throwable
     * @throws UnsupportedTypeException
     */
    protected function serializeGraph(GraphBuilderContext $context): array
    {
        $result = [];
        $target = $context->getTarget();

        /* use __serialize method if available, we don't consider __sleep since this will be deprecated in PHP 8.5 */
        $properties = null;
        try {
            $properties = LifecycleGuard::serialize($target);
        } catch (Throwable $e) {
            $this->getLogger()?->log($e);
        }

        $reflection = new Reflection($target);

        /* if the __serialize method returns null, serialize all properties */
        if (null === $properties) {
            $properties = [];

            foreach ($reflection->getPropertyNames() as $propertyName) {
                if (false === $reflection->initialized($propertyName)) {
                    continue;
                }

                $properties[$propertyName] = $reflection->get($propertyName);
            }
        }

        // ensure deterministic order of properties
        ksort($properties);

        // reserved names (references, dates) no property has
        $context->getMetadata()->reserveNamesAvoiding(array_keys($properties));

        $strategy = $this->getStrategy();

        foreach ($properties as $propertyName => $value) {
            // __serialize() may return int keys (e.g. ArrayObject); JSON stores them as strings anyway
            $propertyName = (string)$propertyName;

            try {
                if ((($strategy?->getPolicyPropertyPersistence() ?? StrategyInterface::DEFAULT_POLICY_PROPERTY_PERSISTENCE) === PropertyPersistence::CALLBACK) && true !== $strategy->shouldPersistProperty($reflection, $propertyName, $value)) {
                    continue;
                }

                $result[$propertyName] = $this->transformValueForGraph($context, $value, [$propertyName]);
            } catch (ResourceSerializationNotSupportedException|ClosureSerializationNotSupportedException|UnsupportedKeyException $e) {
                $this->getLogger()?->log($e);
            }
        }

        return $result;
    }

    /**
     * @param GraphBuilderContext $context
     * @param mixed $value
     * @param array $path
     * @return mixed
     *
     * @throws ClosureSerializationNotSupportedException
     * @throws DanglingReferenceException
     * @throws GenerationFailureException
     * @throws IOException
     * @throws InvalidArgumentException
     * @throws InvalidUUIDException
     * @throws MaxDepthExceededException
     * @throws MetadataSavingFailureException
     * @throws ObjectSavingFailureException
     * @throws ReflectionException
     * @throws ResourceSerializationNotSupportedException
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     * @throws StubSavingFailureException
     * @throws Throwable
     * @throws UnsupportedTypeException
     */
    protected function transformValueForGraph(GraphBuilderContext $context, mixed $value, array $path): mixed
    {
        $strategy = $this->getStrategy();

        $maxDepth = $strategy?->getMaxDepth() ?? StrategyInterface::DEFAULT_MAX_DEPTH;
        if ($context->getLevel() > $maxDepth) {
            throw new MaxDepthExceededException(sprintf('Maximum depth of %u exceeded', $maxDepth));
        }

        if (is_resource($value)) {
            throw new ResourceSerializationNotSupportedException('Resources are not supported');
        }

        /* we could theoretically check the return value of a closure, but this would require to call the closure which could have unknown side effects */
        $isClosure = $value instanceof Closure;

        /* generators should be materialized see the below section with is_iterable */
        $isGenerator = $value instanceof Generator;

        if (is_object($value) && false === $isGenerator) {
            if ($isClosure) {
                throw new ClosureSerializationNotSupportedException('Closures are not supported');
            }

            if ($value instanceof LazyLoadReference) {
                if (!$value->isLoaded()) {
                    $referencedUuid = $value->getUUID();
                    return [$context->getMetadata()->getReservedReferenceName() => $referencedUuid];
                }

                $value = $value->getObject();
            }

            // dates are values: stored inline, not as objects of their own (a date loaded from an older record
            // is stored inline from now on)
            if ($value instanceof DateTimeInterface) {
                return DateTimeValue::encode($value, $context->getMetadata()->getReservedDateTimeName());
            }

            $metadata = $context->getMetadata();
            $referencedUuid = Helper::getAssigned($value) ?? $this->identityMap[$value] ?? $this->getNextAvailableUuid();

            $this->identityMap[$value] = $referencedUuid;

            if (false === isset($this->processingStack[$value])) {
                $context->increaseLevel();
                try {
                    if ($this->shouldWriteChild($context, $value, $referencedUuid, $path)) {
                        $this->storeObject(
                            $value,
                            $referencedUuid,
                            ($strategy?->inheritLifetime($context) ?? false) ? $metadata->getLifetime() : null,
                            $context
                        );
                    }
                } finally {
                    // the level is the depth of the graph, not the number of objects and arrays in it
                    $context->decreaseLevel();
                }
            }

            return [$metadata->getReservedReferenceName() => $referencedUuid];
        }

        if (is_iterable($value)) {
            $out = [];
            $context->increaseLevel();

            try {
                foreach ($value as $k => $v) {
                    try {
                        if (!is_string($k) && !is_int($k)) {
                            throw new UnsupportedKeyException('Only string and integer keys are supported.');
                        }

                        $out[$k] = $this->transformValueForGraph($context, $v, [...$path, $k]);
                    } catch (ResourceSerializationNotSupportedException|ClosureSerializationNotSupportedException|UnsupportedKeyException $e) {
                        $this->getLogger()?->log($e);
                    }
                }
            } finally {
                // the level is the depth of the graph, not the number of objects and arrays in it
                $context->decreaseLevel();
            }
            return $out;
        }

        return $value;
    }
}
