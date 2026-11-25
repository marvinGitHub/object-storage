<?php

namespace melia\ObjectStorage\Storage;

use Closure;
use DateTimeInterface;
use Generator;
use melia\ObjectStorage\Constraint\ConstraintChecker;
use melia\ObjectStorage\Context\GraphBuilderContext;
use melia\ObjectStorage\Event\Context\ClassnameChangeContext;
use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\Context\ObjectPersistenceContext;
use melia\ObjectStorage\Event\Dispatcher;
use melia\ObjectStorage\Event\DispatcherInterface;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\ClosureSerializationNotSupportedException;
use melia\ObjectStorage\Exception\ConstraintViolationException;
use melia\ObjectStorage\Exception\MaxDepthExceededException;
use melia\ObjectStorage\Exception\ObjectSavingFailureException;
use melia\ObjectStorage\Exception\ResourceSerializationNotSupportedException;
use melia\ObjectStorage\Exception\RevisionConflictException;
use melia\ObjectStorage\Exception\SerializationFailureException;
use melia\ObjectStorage\Exception\UnsupportedKeyException;
use melia\ObjectStorage\LazyLoadReference;
use melia\ObjectStorage\Locking\ObjectLocks;
use melia\ObjectStorage\Logger\LoggerInterface;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Reflection\Reflection;
use melia\ObjectStorage\Serialization\DateTimeValue;
use melia\ObjectStorage\Serialization\LifecycleGuard;
use melia\ObjectStorage\Strategy\Policy\ChildPersistence;
use melia\ObjectStorage\Strategy\Policy\PropertyPersistence;
use melia\ObjectStorage\Strategy\StrategyInterface;
use melia\ObjectStorage\Transaction;
use melia\ObjectStorage\UUID\Helper;
use Throwable;

/**
 * Writing objects: an object serialized into its graph (the objects it references stored first, by the child
 * persistence policy), and written under its lock if its data changed, or only its metadata; with optimistic locking,
 * constraints, its stub and the cache. The caller of write() holds the lock of the root object.
 *
 * The active transaction is passed with each call: it records every object before its files are written.
 */
final readonly class ObjectWriter
{
    public function __construct(
        private ObjectFiles          $objectFiles,
        private MetadataStore        $metadata,
        private StubIndex            $stubs,
        private ObjectCache          $cache,
        private ObjectLocks          $locks,
        private ObjectSession        $session,
        private ObjectReader         $reader,
        private ConstraintChecker    $constraints,
        private ?StrategyInterface   $strategy = null,
        private ?DispatcherInterface $dispatcher = null,
        private ?LoggerInterface     $logger = null,
    )
    {
    }

    /**
     * Stores one object of a graph: serializes it (serializeGraph(), which stores the objects it references first,
     * calling this method for each), and writes its files under its lock if its data changed, or only its metadata;
     * registers its stub and caches it.
     *
     * @param float|int|null $ttl Its lifetime in seconds, null to keep it.
     * @param GraphBuilderContext|null $contextParent The context of the referencing object, null for the root.
     * @throws RevisionConflictException With optimistic locking, if the object was stored since it was loaded.
     * @throws ConstraintViolationException
     * @throws Throwable
     */
    public function write(object $object, string $uuid, null|float|int $ttl, ?Transaction $transaction, ?GraphBuilderContext $contextParent = null): void
    {
        try {
            $this->session->rememberIfUnknown($object, $uuid);

            Helper::assign($object, $uuid);

            if ($this->session->isBeingStored($object)) {
                return;
            }

            $this->session->startStoring($object);

            $metadata = new Metadata();
            $metadata->setChecksumAlgorithm($this->strategy?->getChecksumAlgorithm() ?? ObjectStorage::CHECKSUM_ALGORITHM_DEFAULT);
            $now = microtime(true);
            $metadata->setUuid($uuid);
            $metadata->setClassName($className = get_class($object));
            $metadata->setVersion(Metadata::VERSION);
            $metadata->setTimestampExpiresAt($ttl ? $now + $ttl : null);

            $loadedMetadata = $this->metadata->load($uuid);
            $exists = null !== $loadedMetadata;
            // created when first stored; modified when its data is written (below)
            $metadata->setTimestampCreation($loadedMetadata?->getTimestampCreation() ?? $now);
            $metadata->setTimestampModification($loadedMetadata?->getTimestampModification() ?? $now);

            if (false === $exists) {
                $this->dispatcher?->dispatch(Events::BEFORE_INITIAL_STORE, static fn() => new ObjectPersistenceContext($uuid, $object));
            }

            $context = new GraphBuilderContext($object, $metadata, $contextParent ? $contextParent->getLevel() : 1);

            $graph = $this->serializeGraph($context, $transaction);
            $serializedGraph = $this->strategy?->serialize($graph, $this->strategy?->getMaxDepth() ?? StrategyInterface::DEFAULT_MAX_DEPTH);

            if (null === $serializedGraph) {
                $this->dispatcher?->dispatch(Events::GRAPH_SERIALIZATION_FAILURE, static fn() => new Context($uuid));
                throw new SerializationFailureException('Unable serialize graph');
            }

            $metadata->setChecksum(ObjectFiles::checksum($serializedGraph, $metadata->getChecksumAlgorithm()));

            $previousClassname = $loadedMetadata?->getClassName();

            // metadata without a checksum (written by other tools or by hand) is written again with it
            $checksumChanged = null === $loadedMetadata || !$loadedMetadata->hasChecksum() || $metadata->getChecksum() !== $loadedMetadata->getChecksum();
            $classNameChanged = null !== $previousClassname && $metadata->getClassName() !== $previousClassname;
            $lifetimeChanged = $ttl !== $loadedMetadata?->getLifetime();

            $writeGraph = $checksumChanged || $classNameChanged;
            // the metadata (and the cached object) also if only the lifetime changed
            $writeMetadata = $writeGraph || $lifetimeChanged;
            $writeStub = !$exists || $classNameChanged;

            // the root is locked by the caller, the referenced objects written along with it are not: an object is
            // locked while it is written, so concurrent writers can't mix up its files. The lock is only taken now,
            // after its referenced objects are written, so no more than two locks are held at once. A transaction
            // may retain it while the object is written.
            $this->locks->exclusively($uuid, function () use ($object, $uuid, $metadata, $exists, $writeGraph, $writeMetadata, $writeStub, $serializedGraph, $classNameChanged, $previousClassname, $className, $ttl, $transaction): void {
                $currentMetadata = $writeMetadata ? $this->stampRevision($object, $uuid, $metadata, $exists, $writeGraph) : null;

                if ($writeGraph) {
                    $this->writeGraph($object, $uuid, $serializedGraph, $metadata, $currentMetadata?->getRevision(), $exists, $transaction);
                }

                if ($classNameChanged) {
                    $this->dispatcher?->dispatch(Events::CLASSNAME_CHANGED, static fn() => new ClassnameChangeContext($uuid, $previousClassname, $metadata->getClassName()));
                }

                if ($writeGraph) {
                    $this->dispatcher?->dispatch(Events::METADATA_SAVED, static fn() => new Context($uuid));
                    $this->metadata->cache($metadata);
                } elseif ($writeMetadata) {
                    $transaction?->track($uuid);
                    $this->metadata->write($metadata);
                }

                if ($writeMetadata) {
                    $this->session->recordRevision($object, $metadata->getRevision());
                    $this->cache->add($uuid, $object, $ttl);
                }

                if ($writeStub) {
                    $this->stubs->add($className, $uuid);
                }
            }, $writeMetadata);
        } finally {
            $this->session->stopStoring($object);
        }
    }

    /**
     * Whether an object referenced by the object being stored is stored along, by the child persistence policy of
     * the strategy. A callback that fails stores nothing (fail-closed).
     *
     * IF_NOT_EXIST stores new objects and those this instance loaded, e.g. an object reached through a
     * LazyLoadReference: a change of it isn't lost, and write() writes nothing if there is none. Other existing
     * objects (created and stored by the caller, then changed) are updated on their own.
     */
    private function shouldWriteChild(GraphBuilderContext $context, object $child, string $uuid, array $path): bool
    {
        $exists = $this->objectFiles->exists($uuid);

        switch ($this->strategy?->getPolicyChildPersistence() ?? StrategyInterface::DEFAULT_POLICY_CHILD_PERSISTENCE) {
            case ChildPersistence::IF_NOT_EXIST:
                return !$exists || $this->session->wasLoaded($child);
            case ChildPersistence::NEVER:
                return false;
            case ChildPersistence::CALLBACK:
                try {
                    return $this->strategy->shouldPersistChild($context, $child, $uuid, $exists, $path);
                } catch (Throwable $e) {
                    $this->logger?->log($e);
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
        $expectedRevision = $this->session->revisionOf($object);
        $tracked = null !== $expectedRevision;
        $currentMetadata = ($exists || $tracked) ? $this->metadata->reload($uuid) : null;
        $currentRevision = $currentMetadata?->getRevision() ?? 0;

        // an object deleted in the meantime (by this or another process) is created again, which is no conflict
        if ($tracked && null !== $currentMetadata && true === $this->strategy?->optimisticLockingEnabled() && $expectedRevision !== $currentRevision) {
            $this->dispatcher?->dispatch(Events::REVISION_CONFLICT, static fn() => new Context($uuid));
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
    private function writeGraph(object $object, string $uuid, string $serializedGraph, Metadata $metadata, ?int $previousRevision, bool $exists, ?Transaction $transaction): void
    {
        // the previous object must be loaded before the data file is overwritten, otherwise the new data would be
        // validated against the old metadata (checksum mismatch → safe mode); it is read from the files, not the
        // cache, which holds the object being stored, and is not cached, so it doesn't replace the current object
        $previousObject = null;
        if ($exists && Dispatcher::mayHaveListeners($this->dispatcher, Events::OBJECT_SAVED, Events::OBJECT_WRITE_FAILED)) {
            try {
                $previousObject = $this->reader->read($uuid, addToCache: false);
            } catch (Throwable $e) {
                $this->logger?->log($e);
            }
        }

        $revision = $metadata->getRevision();
        $context = static fn(): ObjectPersistenceContext => new ObjectPersistenceContext($uuid, $object, $previousObject, $revision, $previousRevision);

        // with the data as it will be stored
        $finalizeConstraints = $this->constraints->check($uuid, $metadata->getClassName(), $serializedGraph, $metadata->getReservedReferenceName());
        $written = false;
        try {
            // the state before is recorded first, so the transaction can restore it
            $transaction?->track($uuid);
            $this->objectFiles->write($uuid, $serializedGraph, $metadata);
            $written = true;
            $this->dispatcher?->dispatch(Events::OBJECT_SAVED, $context);
        } catch (Throwable $e) {
            $this->dispatcher?->dispatch(Events::OBJECT_WRITE_FAILED, $context);
            throw new ObjectSavingFailureException(message: sprintf('Unable to save object with uuid: %s', $uuid), previous: $e);
        } finally {
            $finalizeConstraints($written);
        }
    }

    /**
     * The graph of an object: its properties (as __serialize() returns them, else all initialized ones) in a
     * deterministic order, the objects it references stored first.
     *
     * @throws Throwable
     */
    private function serializeGraph(GraphBuilderContext $context, ?Transaction $transaction): array
    {
        $result = [];
        $target = $context->getTarget();

        /* use __serialize method if available, we don't consider __sleep since this will be deprecated in PHP 8.5 */
        $properties = null;
        try {
            $properties = LifecycleGuard::serialize($target);
        } catch (Throwable $e) {
            $this->logger?->log($e);
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

        foreach ($properties as $propertyName => $value) {
            // __serialize() may return int keys (e.g. ArrayObject); JSON stores them as strings anyway
            $propertyName = (string)$propertyName;

            try {
                if ((($this->strategy?->getPolicyPropertyPersistence() ?? StrategyInterface::DEFAULT_POLICY_PROPERTY_PERSISTENCE) === PropertyPersistence::CALLBACK) && true !== $this->strategy->shouldPersistProperty($reflection, $propertyName, $value)) {
                    continue;
                }

                $result[$propertyName] = $this->transformValueForGraph($context, $value, [$propertyName], $transaction);
            } catch (ResourceSerializationNotSupportedException|ClosureSerializationNotSupportedException|UnsupportedKeyException $e) {
                $this->logger?->log($e);
            }
        }

        return $result;
    }

    /**
     * A value of the graph: a referenced object as a reference (stored first by the child persistence policy), a
     * date inline, iterables (generators materialized) value by value; resources, closures and keys other than
     * strings and integers aren't supported.
     *
     * @throws MaxDepthExceededException
     * @throws ResourceSerializationNotSupportedException
     * @throws ClosureSerializationNotSupportedException
     * @throws Throwable
     */
    private function transformValueForGraph(GraphBuilderContext $context, mixed $value, array $path, ?Transaction $transaction): mixed
    {
        $maxDepth = $this->strategy?->getMaxDepth() ?? StrategyInterface::DEFAULT_MAX_DEPTH;
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
            $referencedUuid = Helper::getAssigned($value) ?? $this->session->uuidOf($value) ?? $this->objectFiles->newUuid();

            $this->session->remember($value, $referencedUuid);

            if (false === $this->session->isBeingStored($value)) {
                $context->increaseLevel();
                try {
                    if ($this->shouldWriteChild($context, $value, $referencedUuid, $path)) {
                        $this->write(
                            $value,
                            $referencedUuid,
                            ($this->strategy?->inheritLifetime($context) ?? false) ? $metadata->getLifetime() : null,
                            $transaction,
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

                        $out[$k] = $this->transformValueForGraph($context, $v, [...$path, $k], $transaction);
                    } catch (ResourceSerializationNotSupportedException|ClosureSerializationNotSupportedException|UnsupportedKeyException $e) {
                        $this->logger?->log($e);
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
