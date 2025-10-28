<?php

namespace melia\ObjectStorage;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use FilterIterator;
use Generator;
use GlobIterator;
use Iterator;
use Exception as PHPDefaultException;
use melia\ObjectStorage\Cache\InMemoryCache;
use melia\ObjectStorage\Context\GraphBuilderContext;
use melia\ObjectStorage\Event\AwareTrait;
use melia\ObjectStorage\Event\Context\ClassAliasCreationContext;
use melia\ObjectStorage\Event\Context\ClassnameChangeContext;
use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\Context\IOContext;
use melia\ObjectStorage\Event\Context\LazyTypeNotSupportedContext;
use melia\ObjectStorage\Event\Context\LifetimeContext;
use melia\ObjectStorage\Event\Context\ObjectPersistenceContext;
use melia\ObjectStorage\Event\Context\StubContext;
use melia\ObjectStorage\Event\Context\TypeConversionContext;
use melia\ObjectStorage\Event\Dispatcher;
use melia\ObjectStorage\Event\DispatcherInterface;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\ClassAliasCreationFailureException;
use melia\ObjectStorage\Exception\ClosureSerializationNotSupportedException;
use melia\ObjectStorage\Exception\DanglingReferenceException;
use melia\ObjectStorage\Exception\Exception;
use melia\ObjectStorage\Exception\InvalidFileFormatException;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\Exception\MaxNestingLevelExceededException;
use melia\ObjectStorage\Exception\MetadataNotFoundException;
use melia\ObjectStorage\Exception\MetadataSavingFailureException;
use melia\ObjectStorage\Exception\MetataDeletionFailureException;
use melia\ObjectStorage\Exception\ObjectDeletionFailureException;
use melia\ObjectStorage\Exception\ObjectLoadingFailureException;
use melia\ObjectStorage\Exception\ObjectNotFoundException;
use melia\ObjectStorage\Exception\ObjectSavingFailureException;
use melia\ObjectStorage\Exception\ResourceSerializationNotSupportedException;
use melia\ObjectStorage\Exception\SafeModeActivationFailedException;
use melia\ObjectStorage\Exception\SerializationFailureException;
use melia\ObjectStorage\Exception\StubDeletionFailureException;
use melia\ObjectStorage\Exception\StubSavingFailureException;
use melia\ObjectStorage\Exception\TypeConversionFailureException;
use melia\ObjectStorage\Exception\UnsupportedKeyException;
use melia\ObjectStorage\Exception\UnsupportedTypeException;
use melia\ObjectStorage\File\Directory;
use melia\ObjectStorage\File\ReaderAwareTrait;
use melia\ObjectStorage\File\WriterAwareTrait;
use melia\ObjectStorage\Locking\Backends\FileSystem as FileSystemLockingBackend;
use melia\ObjectStorage\Locking\LockAdapterInterface;
use melia\ObjectStorage\Logger\LoggerInterface;
use melia\ObjectStorage\Metadata\Cache\AwareTrait as MetadataCacheAwareTrait;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\Reflection\Reflection;
use melia\ObjectStorage\Runtime\ClassRenameMap;
use melia\ObjectStorage\Runtime\ClassRenameMapAwareTrait;
use melia\ObjectStorage\Serialization\LifecycleGuard;
use melia\ObjectStorage\SPL\SplObjectStorage;
use melia\ObjectStorage\State\StateHandler;
use melia\ObjectStorage\Storage\StorageAbstract;
use melia\ObjectStorage\Storage\StorageInterface;
use melia\ObjectStorage\Storage\StorageMemoryConsumptionInterface;
use melia\ObjectStorage\UUID\Exception\GenerationFailureException;
use melia\ObjectStorage\UUID\Exception\InvalidUUIDException;
use melia\ObjectStorage\UUID\Generator\Generator as UUIDGenerator;
use melia\ObjectStorage\UUID\Generator\GeneratorInterface;
use melia\ObjectStorage\UUID\Helper;
use melia\ObjectStorage\UUID\Validator;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Throwable;
use Traversable;

/**
 * Class responsible for storing, caching, and retrieving objects in a persistent storage system.
 * Handles serialization, metadata processing, and nested structures.
 * Supports handling circular references, maximum nesting levels, and in-memory caching.
 */
class ObjectStorage extends StorageAbstract implements StorageInterface, StorageMemoryConsumptionInterface
{
    use WriterAwareTrait;
    use ReaderAwareTrait;
    use AwareTrait;
    use Cache\AwareTrait;
    use MetadataCacheAwareTrait;
    use ClassRenameMapAwareTrait;

    /**
     * The suffix used for metadata files.
     */
    private const FILE_SUFFIX_METADATA = '.metadata';

    /**
     * The suffix used for stub files.
     */
    private const FILE_SUFFIX_STUB = '.stub';

    /**
     * The suffix used for object files.
     */
    private const FILE_SUFFIX_OBJECT = '.obj';

    private SplObjectStorage $processingStack;

    private SplObjectStorage $objectUuidMap;

    /** @var array<string>|null */
    private ?array $registeredClassNamesCache = null;

    /**
     * Sets the lifetime (time-to-live) for the given UUID, updating its expiration timestamp
     * in the metadata and dispatching an event to notify listeners of the change.
     *
     * @param string $uuid The unique identifier whose lifetime is to be set.
     * @param int|float|null $ttl The time-to-live (in seconds) to set for the UUID.
     *
     * @return void
     * @throws InvalidUUIDException
     * @throws MetadataNotFoundException If metadata for the specified UUID cannot be loaded.
     * @throws MetadataSavingFailureException
     */
    public function setLifetime(string $uuid, null|int|float $ttl): void
    {
        $this->getLockAdapter()?->acquireExclusiveLock($uuid);

        $metadata = $this->loadMetadata($uuid);
        if (null === $metadata) {
            throw new MetadataNotFoundException('Unable to load metadata for uuid: ' . $uuid);
        }

        if (null === $ttl) {
            $timestampExpiresAt = null;
        } else {
            $timestampExpiresAt = microtime(true) + $ttl;
            $timestampExpiresAt = (float)$timestampExpiresAt;
        }

        $metadata->setTimestampExpiresAt($timestampExpiresAt);
        $this->saveMetadata($metadata);
        $this->getEventDispatcher()?->dispatch(Events::LIFETIME_CHANGED, new LifetimeContext($uuid, $timestampExpiresAt));

        $this->getLockAdapter()?->releaseLock($uuid);
    }

    /**
     * Sets the expiration date and time for the specified UUID. If no expiration is provided,
     * the lifetime will be unset or considered indefinite.
     *
     * @param string $uuid The unique identifier for which the expiration is being set.
     * @param DateTimeInterface|null $expiresAt The date and time at which the UUID should expire, or null for no expiration.
     * @return void
     * @throws InvalidUUIDException
     * @throws MetadataNotFoundException
     * @throws MetadataSavingFailureException
     */
    public function setExpiration(string $uuid, ?DateTimeInterface $expiresAt): void
    {
        $this->setLifetime($uuid, $expiresAt ? $expiresAt->getTimestamp() - microtime(true) : null);
    }

    /**
     * Loads metadata associated with a given UUID by reading from a JSON file and validating it.
     * If an error occurs during the process, it is logged, and null is returned.
     *
     * @param null|string $uuid The unique identifier for the metadata to be loaded.
     * @return Metadata|null The loaded and validated metadata object, or null if loading fails.
     * @throws InvalidUUIDException
     */
    public function loadMetadata(?string $uuid): ?Metadata
    {
        if (null === $uuid) {
            return null;
        }

        try {
            $cached = $this->getMetadataCache()?->get($uuid);
            if ($cached instanceof Metadata) {
                return $cached;
            }
        } catch (Throwable $e) {
            $this->getLogger()?->log($e);
        }

        try {
            $metadata = $this->loadFromJsonFile($this->getFilePathMetadata($uuid));
            if (null === $metadata) {
                throw new MetadataNotFoundException('Unable to load metadata for uuid: ' . $uuid);
            }

            $metadata = Metadata::createFromArray($metadata);
            $this->getMetadataCache()?->set($uuid, $metadata);
            return $metadata;
        } catch (Throwable $e) {
            $this->getEventDispatcher()?->dispatch(Events::METADATA_LOADING_FAILURE, new Context($uuid));
            $this->getLogger()?->log($e);
        }
        return null;
    }

    /**
     * Reads and decodes data from a specified file, handling errors and enabling safe mode upon failure.
     *
     * @param string $filename The path to the file to read and decode data from.
     * @return array|null Returns the decoded data as an associative array.
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException If the file content cannot be decoded.
     */
    private function loadFromJsonFile(string $filename): ?array
    {
        try {
            $data = $this->getReader()->read($filename);
        } catch (Throwable $e) {
            $this->getLogger()?->log($e);
            $this->getEventDispatcher()?->dispatch(Events::IO_READ_FAILURE, new IOContext($filename));
            return null;
        }

        $data = json_decode($data, true, $this->maxNestingLevel);

        if (null === $data) {
            $this->getEventDispatcher()?->dispatch(Events::JSON_DECODING_FAILURE, new IOContext($filename));
            $this->getStateHandler()?->enableSafeMode();
            throw new SerializationFailureException('Unable to decode data from file: ' . $filename);
        }

        return $data;
    }

    /**
     * Generates the file path for the metadata associated with a specific UUID.
     *
     * @param string $uuid The unique identifier used to build the metadata file path.
     * @return string Returns the file path of the metadata corresponding to the provided UUID.
     */
    public function getFilePathMetadata(string $uuid): string
    {
        return $this->getStorageDir() . DIRECTORY_SEPARATOR . $uuid . static::FILE_SUFFIX_METADATA;
    }

    /**
     * Retrieves the directory path where storage operations are performed.
     *
     * @return string The storage directory path as a string.
     */
    public function getStorageDir(): string
    {
        return rtrim($this->storageDir, DIRECTORY_SEPARATOR);
    }

    /**
     * Saves the provided metadata to a file by serializing it to JSON and writing it atomically.
     *
     * @param Metadata $metadata The metadata to be serialized and saved.
     * @return void
     * @throws MetadataSavingFailureException
     * @throws InvalidUUIDException
     */
    private function saveMetadata(Metadata $metadata): void
    {
        try {
            $this->getWriter()->atomicWrite($this->getFilePathMetadata($metadata->getUUID()), json_encode($metadata, depth: $this->maxNestingLevel));
            $this->getEventDispatcher()?->dispatch(Events::METADATA_SAVED, new Context($metadata->getUUID()));
        } catch (Throwable $e) {
            $this->getEventDispatcher()?->dispatch(Events::METADATA_WRITE_FAILED, new Context($metadata->getUUID()));
            $this->getLogger()?->log($e);
            throw new MetadataSavingFailureException(message: 'Unable to save metadata for uuid: ' . $metadata->getUUID(), previous: $e);
        }

        try {
            $this->getMetadataCache()?->set($metadata->getUUID(), $metadata);
        } catch (Throwable $e) {
            $this->getLogger()?->log($e);
        }
    }

    /**
     * Clears the internal object cache by resetting it to an empty array.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->getCache()?->clear();
        $this->objectUuidMap->clear();
        $this->processingStack->clear();
        $this->registeredClassNamesCache = null;
        $this->getMetadataCache()?->clear();
        $this->getEventDispatcher()?->dispatch(Events::CACHE_CLEARED);
    }

    /**
     * Retrieves the expiration date and time for the provided UUID.
     *
     * @param string $uuid The unique identifier for which to retrieve the expiration timestamp.
     * @return DateTimeInterface|null The expiration date and time as a DateTimeInterface object, or null if no expiration is set.
     * @throws InvalidUUIDException If the provided UUID is not valid.
     * @throws PHPDefaultException
     */
    public function getExpiration(string $uuid): ?DateTimeInterface
    {
        $expiresAt = $this->loadMetadata($uuid)?->getTimestampExpiresAt();
        if (null === $expiresAt) {
            return null;
        }
        return new DateTimeImmutable('@' . $expiresAt);
    }

    /**
     * Releases all active locks held by the lock adapter before object destruction.
     *
     * @return void
     */
    public function __destruct()
    {
        $this->getLockAdapter()?->releaseActiveLocks();
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
     * @return string
     * @throws DanglingReferenceException
     * @throws Exception
     * @throws GenerationFailureException
     * @throws IOException
     * @throws InvalidArgumentException
     * @throws InvalidUUIDException
     * @throws MaxNestingLevelExceededException
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

        $this->getEventDispatcher()?->dispatch(Events::BEFORE_STORE, new ObjectPersistenceContext($uuid, $object));

        /* use metadata to check for existence, since this will already warm up the metadata cache */
        $metadata = $this->loadMetadata($uuid);
        $exists = null !== $metadata;

        if ($exists) {
            $this->getEventDispatcher()?->dispatch(Events::BEFORE_UPDATE, new ObjectPersistenceContext($uuid, $object));
        }

        // LazyLoadReference: ungeladen → nur UUID zurückgeben
        if ($object instanceof LazyLoadReference) {
            if (false === $object->isLoaded()) {
                return $object->getUUID();
            }
            $object = $object->getObject();
            $uuid = $object->getUUID();
        }

        try {
            // 1) prefer UUID from parameter; otherwise from AwareInterface; otherwise REUSE from objectUuidMap mapping,
            //    only generate a new UUID if none is available
            $uuid ??= Helper::getAssigned($object) ?? $this->objectUuidMap[$object] ?? $this->getNextAvailableUuid();

            // 2) update mapping (important for references and later store calls)
            $this->objectUuidMap[$object] = $uuid;

            // 3) no early return: ALWAYS call serializeAndStore so that updates are detected via checksum
            $this->getLockAdapter()?->acquireExclusiveLock($uuid);

            /* if classname change we should remove the previous stub */
            $previousClassname = $this->getClassName($uuid);
            $removePreviousStub = null !== $previousClassname && $previousClassname !== $object::class;

            if ($removePreviousStub) {
                $this->deleteStub($previousClassname, $uuid);
            }

            $this->serializeAndStore($object, $uuid, $ttl);

            if ($this->getLockAdapter()?->isLockedByThisProcess($uuid)) {
                $this->getLockAdapter()?->releaseLock($uuid);
            }

            if ($exists) {
                $this->getEventDispatcher()?->dispatch(Events::AFTER_UPDATE, new ObjectPersistenceContext($uuid, $object));
            }

            $this->getEventDispatcher()?->dispatch(Events::AFTER_STORE, new Context($uuid));

            return $uuid;
        } catch (Throwable $e) {
            if ($this->getLockAdapter()?->isLockedByThisProcess($uuid)) {
                $this->getLockAdapter()?->releaseLock($uuid);
            }
            $this->getEventDispatcher()?->dispatch(Events::OBJECT_SAVING_FAILURE, new Context($uuid));
            throw new ObjectSavingFailureException(message: sprintf('Unable to store object with uuid: %s', $uuid), previous: $e);
        }
    }

    /**
     * Get a classname for a certain object
     *
     * @param string $uuid
     * @return string|null
     * @throws InvalidUUIDException
     */
    public function getClassName(string $uuid): ?string
    {
        return $this->loadMetadata($uuid)?->getClassName() ?? null;
    }

    /**
     * Deletes a stub file for the specified class name and UUID if it exists.
     *
     * @param string $className The name of the class associated with the stub.
     * @param string $uuid The unique identifier associated with the stub.
     * @return void
     * @throws StubDeletionFailureException If the stub file could not be deleted.
     * @throws InvalidUUIDException
     */
    private function deleteStub(string $className, string $uuid): void
    {
        $filePathStub = $this->getFilePathStub($className, $uuid);
        if (file_exists($filePathStub)) {
            if (!unlink($filePathStub)) {
                throw new StubDeletionFailureException(sprintf('Stub for uuid %s and classname %s could not be deleted', $uuid, $className));
            }
            $this->getEventDispatcher()?->dispatch(Events::STUB_REMOVED, new StubContext($uuid, $className));
        }
    }

    /**
     * Generates the file path for a stub associated with a specific class and UUID.
     *
     * @param string $className The name of the class for which the stub is being generated.
     * @param string $uuid The unique identifier used to differentiate the stub file.
     * @return string The full file path for the stub.
     */
    public function getFilePathStub(string $className, string $uuid): string
    {
        return $this->getClassStubDirectory($className) . DIRECTORY_SEPARATOR . $uuid . '.stub';
    }

    /**
     * Retrieves the directory path where the class stub for the given class name is stored.
     *
     * @param string $className The name of the class for which the stub directory path is being generated.
     * @return string The full path to the class stub directory.
     */
    protected function getClassStubDirectory(string $className): string
    {
        return $this->getStubDirectory() . DIRECTORY_SEPARATOR . md5($className);
    }

    /**
     * Retrieves the path to the stub directory.
     *
     * @return string The full path to the stub directory.
     */
    protected function getStubDirectory(): string
    {
        return $this->getStorageDir() . DIRECTORY_SEPARATOR . 'stubs';
    }

    /**
     * @param object $object
     * @param string $uuid
     * @param float|int|null $ttl
     *
     * @throws DanglingReferenceException
     * @throws GenerationFailureException
     * @throws IOException
     * @throws InvalidArgumentException
     * @throws InvalidUUIDException
     * @throws MaxNestingLevelExceededException
     * @throws MetadataSavingFailureException
     * @throws ObjectSavingFailureException
     * @throws ReflectionException
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     * @throws StubSavingFailureException
     * @throws Throwable
     * @throws UnsupportedTypeException
     */
    private function serializeAndStore(object $object, string $uuid, null|float|int $ttl = null): void
    {
        try {
            if (!isset($this->objectUuidMap[$object])) {
                $this->objectUuidMap[$object] = $uuid;
            }

            Helper::assign($object, $uuid);

            if (isset($this->processingStack[$object])) {
                return;
            }

            $this->processingStack[$object] = true;

            $metadata = new Metadata();
            $metadata->setTimestampCreation($timestampCreation = microtime(true));
            $metadata->setUuid($uuid);
            $metadata->setClassName($className = get_class($object));
            $metadata->setVersion(1);
            $metadata->setTimestampExpiresAt($ttl ? $timestampCreation + $ttl : null);

            $loadedMetadata = $this->loadMetadata($uuid);
            $exists = null !== $loadedMetadata;

            if (false === $exists) {
                $this->getEventDispatcher()?->dispatch(Events::BEFORE_INITIAL_STORE, new ObjectPersistenceContext($uuid, $object));
            }

            /* ensure that the object is going to sleep before creating the graph;
               to ensure the object stays untouched, we create a graph from a copy (clone)
            */
            $clone = clone $object;

            $jsonGraph = json_encode(
                $this->createGraphAndStoreReferencedChildren(new GraphBuilderContext($clone, $metadata)),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            );

            if (false === $jsonGraph) {
                $this->getEventDispatcher()?->dispatch(Events::JSON_ENCODING_FAILURE, new Context($uuid));
                throw new SerializationFailureException('Unable export object graph to JSON');
            }

            $metadata->setChecksum(md5($jsonGraph));

            $previousClassname = $loadedMetadata?->getClassName() ?? null;

            $checksumChanged = $metadata->getChecksum() !== ($loadedMetadata?->getChecksum() ?? null);
            $classNameChanged = $metadata->getClassName() !== $previousClassname;

            if ($checksumChanged || $classNameChanged) {
                try {
                    $previousObject = $this->exists($uuid) ? $this->load($uuid) : null;
                } catch (Throwable $e) {
                    $this->getLogger()?->log($e);
                    $previousObject = null;
                }

                try {
                    $this->getWriter()->atomicWrite($this->getFilePathData($uuid), $jsonGraph);
                    $this->getEventDispatcher()?->dispatch(Events::OBJECT_SAVED, new ObjectPersistenceContext($uuid, $object, $previousObject));
                } catch (Throwable $e) {
                    $this->getEventDispatcher()?->dispatch(Events::OBJECT_WRITE_FAILED, new ObjectPersistenceContext($uuid, $object, $previousObject));
                    throw new ObjectSavingFailureException(message: sprintf('Unable to save object with uuid: %s', $uuid), previous: $e);
                }

                $this->saveMetadata($metadata);
                $this->createStub($className, $uuid);
            }

            if ((null !== $previousClassname) && $classNameChanged) {
                $this->getEventDispatcher()?->dispatch(Events::CLASSNAME_CHANGED, new ClassnameChangeContext($uuid, $previousClassname, $metadata->getClassName()));
            }

            $this->addToCache($uuid, $object, $ttl);
        } finally {
            if (isset($this->processingStack[$object])) {
                unset($this->processingStack[$object]);
            }
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
     * @throws InvalidUUIDException
     * @throws MaxNestingLevelExceededException
     * @throws ReflectionException
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     * @throws InvalidArgumentException
     * @throws UnsupportedTypeException
     * @throws Throwable
     */
    private function createGraphAndStoreReferencedChildren(GraphBuilderContext $context): array
    {
        $result = [];
        $target = $context->getTarget();

        /* check if the sleep methods return a list of property names to serialize */
        $propertyNames = null;
        try {
            $propertyNames = LifecycleGuard::sleep($target);
        } catch (Throwable $e) {
            $this->getLogger()?->log($e);
        }

        $reflection = new Reflection($target);

        /* if the sleep method returns null, serialize all properties */
        if (null === $propertyNames) {
            $propertyNames = $reflection->getPropertyNames();
        }

        // ensure deterministic order of properties
        sort($propertyNames, SORT_STRING);

        // pre-scan property names to avoid reserved reference name collision
        $reserved = $context->getMetadata()->getReservedReferenceName();
        while (in_array($reserved, $propertyNames, true)) {
            $reserved = uniqid(Metadata::RESERVED_REFERENCE_NAME_DEFAULT);
        }
        $context->getMetadata()->setReservedReferenceName($reserved);

        foreach ($propertyNames as $propertyName) {
            if (false === is_string($propertyName)) {
                throw new UnsupportedTypeException(sprintf('Property name must be a string. %s given.', gettype($propertyName)));
            }

            if (false === $reflection->initialized($propertyName)) {
                continue;
            }

            $value = $reflection->get($propertyName);

            try {
                $result[$propertyName] = $this->transformValueForGraph($context, $value, [$propertyName], 0);
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
     * @param int $level
     * @return mixed
     *
     * @throws DanglingReferenceException
     * @throws GenerationFailureException
     * @throws IOException
     * @throws InvalidUUIDException
     * @throws MaxNestingLevelExceededException
     * @throws ReflectionException
     * @throws ResourceSerializationNotSupportedException
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     * @throws InvalidArgumentException
     * @throws ClosureSerializationNotSupportedException
     * @throws UnsupportedTypeException
     * @throws Throwable
     */
    private function transformValueForGraph(GraphBuilderContext $context, mixed $value, array $path, int $level): mixed
    {
        if ($level > $this->maxNestingLevel) {
            throw new MaxNestingLevelExceededException('Maximum nesting level of ' . $this->maxNestingLevel . ' exceeded');
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
                    $refUuid = $value->getUUID();
                    return [$context->getMetadata()->getReservedReferenceName() => $refUuid];
                }

                $loaded = $value->getObject();
                $value = $loaded;
            }

            $refUuid = Helper::getAssigned($value) ?? $this->objectUuidMap[$value] ?? $this->getNextAvailableUuid();

            $this->objectUuidMap[$value] = $refUuid;

            if (false === isset($this->processingStack[$value])) {
                $this->serializeAndStore($value, $refUuid);
            }

            return [$context->getMetadata()->getReservedReferenceName() => $refUuid];
        }

        if (is_iterable($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                try {
                    $isSupportedKey = is_string($k) || is_int($k);

                    if (false === $isSupportedKey) {
                        throw new UnsupportedKeyException('Only string and integer keys are supported.');
                    }

                    $out[$k] = $this->transformValueForGraph($context, $v, array_merge($path, [$k]), $level + 1);
                } catch (ResourceSerializationNotSupportedException|ClosureSerializationNotSupportedException|UnsupportedKeyException $e) {
                    $this->getLogger()?->log($e);
                }
            }
            return $out;
        }

        return $value;
    }

    /**
     * Checks if a file exists for a specific UUID.
     *
     * @param string $uuid The UUID to check if the associated file exists.
     * @return bool True if the file exists, false otherwise.
     */
    public function exists(string $uuid): bool
    {
        return file_exists($this->getFilePathData($uuid));
    }

    /**
     * Generates the file path for a given UUID.
     *
     * @param string $uuid The unique identifier used to generate the file path.
     * @return string The full file path constructed using the UUID.
     */
    public function getFilePathData(string $uuid): string
    {
        return $this->getStorageDir() . DIRECTORY_SEPARATOR . $uuid . static::FILE_SUFFIX_OBJECT;
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
        $this->getEventDispatcher()?->dispatch(Events::BEFORE_LOAD, new Context($uuid));

        if ($this->expired($uuid)) {
            /* do not delete an expired object since the ttl might be updated later */
            return null;
        }

        $cached = $this->getCache()?->get($uuid, null);
        if (null !== $cached) {
            $this->getEventDispatcher()?->dispatch(Events::CACHE_HIT, new Context($uuid));
            return $cached;
        }

        try {
            if ($exclusive) {
                $this->getLockAdapter()?->acquireExclusiveLock($uuid);
            } else {
                $this->getLockAdapter()?->acquireSharedLock($uuid);
            }

            $object = $this->loadFromStorage($uuid);

            if (!$exclusive) {
                $this->getLockAdapter()?->releaseLock($uuid);
            }

            $this->getEventDispatcher()?->dispatch(Events::AFTER_LOAD, new Context($uuid));

            return $object;
        } catch (Throwable $e) {
            if ($this->getLockAdapter()?->isLockedByThisProcess($uuid)) {
                $this->getLockAdapter()?->releaseLock($uuid);
            }
            $this->getEventDispatcher()?->dispatch(Events::OBJECT_LOADING_FAILURE, new Context($uuid));
            throw new ObjectLoadingFailureException(message: sprintf('Unable to load object with uuid: %s', $uuid), previous: $e);
        }
    }

    /**
     * Checks if the object associated with the given UUID is expired.
     *
     * @param string $uuid The unique identifier of the object.
     * @return bool Returns true if the object is expired, false otherwise.
     * @throws InvalidUUIDException
     */
    public function expired(string $uuid): bool
    {
        $lifetime = $this->getLifetime($uuid);
        $expired = (null !== $lifetime && $lifetime <= 0);

        if ($expired) {
            $this->getEventDispatcher()?->dispatch(Events::OBJECT_EXPIRED, new Context($uuid));
        }

        return $expired;
    }

    /**
     * Retrieves the lifetime of the metadata associated with the specified UUID.
     *
     * @param string $uuid The unique identifier used to load the metadata.
     * @return float|null The lifetime of the metadata in seconds, or null if no metadata is found.
     * @throws InvalidUUIDException
     */
    public function getLifetime(string $uuid): ?float
    {
        return $this->loadMetadata($uuid)?->getLifetime();
    }

    /**
     * Loads an object based on a UUID from a cache or file.
     *
     * @param string $uuid The unique ID of the object to be loaded.
     * @return object|null The loaded object or null if it doesn't exist.
     * @throws InvalidFileFormatException
     * @throws InvalidUUIDException
     * @throws ReflectionException
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     * @throws TypeConversionFailureException|Throwable
     */
    private function loadFromStorage(string $uuid): ?object
    {
        $data = $this->loadFromJsonFile($this->getFilePathData($uuid));
        if (false === is_array($data)) {
            return null;
        }

        // Load metadata once and derive class name and lifetime from it
        $metadata = $this->loadMetadata($uuid);
        if (null === $metadata) {
            $this->getStateHandler()?->enableSafeMode();
            throw new InvalidFileFormatException('Unable to load metadata for: ' . $uuid);
        }

        // Build object using the single metadata instance
        $object = $this->processLoadedData($data, $metadata);

        LifecycleGuard::wakeup($object);
        Helper::assign($object, $uuid);

        $this->addToCache($uuid, $object, $metadata->getLifetime());

        return $object;
    }

    /**
     * Converts the provided data array into an object of the specified class
     * by mapping the data to the class's properties.
     *
     * @param array $data An associative array containing property names and their corresponding values.
     * @param Metadata $metadata
     * @return object An instance of the specified class with its properties populated from the provided data.
     * @throws ClassAliasCreationFailureException
     * @throws DanglingReferenceException
     * @throws InvalidUUIDException
     * @throws ReflectionException
     * @throws TypeConversionFailureException
     */
    private function processLoadedData(array $data, Metadata $metadata): object
    {
        $className = $metadata->getClassName();

        $classNameOverride = $this->getClassRenameMap()?->getAlias($className);
        if ($classNameOverride) {
            $className = $classNameOverride;
        }

        if (false === class_exists($className)) {
            if (false === class_alias(get_class(new class {
                }), $className)) {
                throw new ClassAliasCreationFailureException('Unable to create class alias for unknown class ' . $className);
            }
            $this->getEventDispatcher()?->dispatch(Events::CLASS_ALIAS_CREATED, new ClassAliasCreationContext($className));
        }

        $object = (new ReflectionClass($className))->newInstanceWithoutConstructor();
        $reflection = new Reflection($object);

        foreach ($data as $propertyName => $value) {
            $type = $reflection->getPropertyType($propertyName);

            if (is_array($value) && isset($value[$metadata->getReservedReferenceName()])) {
                $refUUID = $value[$metadata->getReservedReferenceName()];
                if (false === Validator::validate($refUUID)) {
                    /* reference UUID is not valid, so we just set the property to the value */
                    $reflection->set($propertyName, [$metadata->getReservedReferenceName() => $refUUID]);
                } else {
                    $reference = new LazyLoadReference($this, $refUUID, $object, [$propertyName]);
                    /* if LazyLoadReference is not allowed, then we need to convert the reference to the real object */
                    if ($type instanceof ReflectionNamedType) {
                        if (LazyLoadReference::class !== $type->getName() &&
                            false === in_array($type->getName(), ['object', 'mixed'], true)
                        ) {
                            $this->getEventDispatcher()?->dispatch(Events::LAZY_TYPE_NOT_SUPPORTED, new LazyTypeNotSupportedContext($className, $propertyName));
                            $reference = $reference->getObject();
                        }
                    } else if ($type instanceof ReflectionUnionType) {
                        $supportedTypes = array_map(function (ReflectionNamedType $type) {
                            return $type->getName();
                        }, array_filter($type->getTypes(), function (ReflectionType $type) {
                            return $type instanceof ReflectionNamedType;
                        }));
                        if (false === in_array(LazyLoadReference::class, $supportedTypes, true) &&
                            false === in_array('object', $supportedTypes, true)
                        ) {
                            $this->getEventDispatcher()?->dispatch(Events::LAZY_TYPE_NOT_SUPPORTED, new LazyTypeNotSupportedContext($className, $propertyName));
                            $reference = $reference->getObject();
                        }
                    }
                    $reflection->set($propertyName, $reference);
                }
            } else if (is_array($value)) {
                $reflection->set($propertyName, $this->processLoadedArray($metadata, $object, $value, [$propertyName]));
            } else {
                /* type conversion of non-union types */
                if ($type instanceof ReflectionNamedType) {
                    $expectedType = $type->getName();
                    $givenType = gettype($value);

                    if ($givenType !== $expectedType && in_array($givenType, ['integer', 'double', 'boolean', 'string'])) {
                        $this->getEventDispatcher()?->dispatch(Events::BEFORE_TYPE_CONVERSION,
                            new TypeConversionContext($object, $propertyName, $value, $givenType, $expectedType));

                        if (false === settype($value, $expectedType)) {
                            throw new TypeConversionFailureException('Unable to convert value to type ' . $expectedType . ' for property ' . $propertyName . ' of class ' . $className);
                        }
                    }
                }
                $reflection->set($propertyName, $value);
            }
        }

        return $object;
    }

    /**
     * Processes a loaded array by iterating through its elements and converting specific
     * nested structures into lazy load references or recursively processing subarrays,
     * based on the metadata provided.
     *
     * @param Metadata $metadata The metadata object used to identify and process reserved references.
     * @param object $object The object to be used in creating LazyLoadReference instances.
     * @param array $array The input array to be processed.
     * @param array $path An array representing the traversal path within the structure for recursion.
     *
     * @return array The processed array, with applicable elements converted into lazy load references
     *               or recursively processed subarrays.
     * @throws InvalidUUIDException
     */
    private function processLoadedArray(Metadata $metadata, object $object, array $array, array $path): array
    {
        $processed = [];
        foreach ($array as $key => $value) {
            if (is_array($value) && isset($value[$metadata->getReservedReferenceName()])) {
                $processed[$key] = new LazyLoadReference($this, $value[$metadata->getReservedReferenceName()], $object, [...$path, $key]);
            } else if (is_array($value)) {
                $processed[$key] = $this->processLoadedArray($metadata, $object, $value, [...$path, $key]);
            } else {
                $processed[$key] = $value;
            }
        }
        return $processed;
    }

    /**
     * Adds an object to the cache with the specified TTL (Time-To-Live) or removes it
     * from the cache if the TTL is less than or equal to zero.
     *
     *  Cache policy:
     *  $ttl === null (cache without expiration)
     *  $ttl > 0 (cache with TTL)
     *  $ttl <= 0 (remove from cache - expired)
     *
     * @param string $uuid The unique identifier for the cached object.
     * @param object $object The object to be cached.
     * @param int|float|null $ttl The time-to-live for the cache entry in seconds.
     *                            If null, the object is cached indefinitely. If less
     *                            than or equal to 0, the object is removed from the cache.
     *
     * @return void
     * @throws InvalidUUIDException
     */
    private function addToCache(string $uuid, object $object, null|int|float $ttl = null): void
    {
        try {
            $cache = $this->getCache();

            if (null === $cache) {
                return;
            }

            if (null !== $ttl && $ttl <= 0) {
                $this->removeFromCache($uuid);
            } else {
                if (null !== $ttl) {
                    $ttl = (int)$ttl;
                }
                $cache->set($uuid, $object, $ttl);
                $this->getEventDispatcher()?->dispatch(Events::CACHE_ENTRY_ADDED, new Context($uuid));
            }
        } catch (Throwable $e) {
            $this->getLogger()?->log($e);
            $this->getEventDispatcher()?->dispatch(Events::CACHE_WRITE_FAILED, new Context($uuid));
        }
    }

    /**
     * @throws InvalidUUIDException
     * @throws InvalidArgumentException
     */
    private function removeFromCache(string $uuid): void
    {
        $this->getCache()?->delete($uuid);
        $this->getEventDispatcher()?->dispatch(Events::CACHE_ENTRY_REMOVED, new Context($uuid));
    }

    /**
     * Deletes an object based on its UUID.
     *
     * @param string $uuid The unique identifier of the object to be deleted.
     * @return void
     * @throws InvalidUUIDException
     * @throws ObjectDeletionFailureException Thrown when the object could not be deleted.
     */
    public function delete(string $uuid): void
    {
        $this->getEventDispatcher()?->dispatch(Events::BEFORE_DELETE, new Context($uuid));

        if ($this->getStateHandler()?->safeModeEnabled()) {
            throw new ObjectDeletionFailureException('Safe mode is enabled. Object cannot be deleted.');
        }

        try {
            $this->getLockAdapter()?->acquireExclusiveLock($uuid);

            $this->removeFromCache($uuid);

            if (!$this->exists($uuid)) {
                throw new ObjectNotFoundException(sprintf('Object with uuid %s not found', $uuid));
            }

            $className = $this->getClassName($uuid);
            $filePath = $this->getFilePathData($uuid);

            if (!unlink($filePath)) {
                throw new ObjectDeletionFailureException('Object with uuid ' . $uuid . ' could not be deleted');
            }

            $filePathMetadata = $this->getFilePathMetadata($uuid);
            if (file_exists($filePathMetadata)) {
                if (!unlink($filePathMetadata)) {
                    throw new MetataDeletionFailureException('Metadata for uuid ' . $uuid . ' could not be deleted');
                }
            }

            $this->getMetadataCache()?->delete($uuid);

            $this->deleteStub($className, $uuid);

            $this->getEventDispatcher()?->dispatch(Events::AFTER_DELETE, new Context($uuid));
        } catch (Throwable $e) {
            $this->getEventDispatcher()?->dispatch(Events::OBJECT_DELETION_FAILURE, new Context($uuid));
            throw new ObjectDeletionFailureException(message: 'Object with uuid ' . $uuid . ' could not be deleted', previous: $e);
        } finally {
            if ($this->getLockAdapter()?->isLockedByThisProcess($uuid)) {
                $this->getLockAdapter()?->releaseLock($uuid);
            }
        }
    }

    /**
     * @param string $className
     * @param string $uuid
     * @throws InvalidUUIDException
     * @throws StubSavingFailureException
     */
    public function createStub(string $className, string $uuid): void
    {
        try {
            $this->registerClassname($className);
            $pathname = $this->getFilePathStub($className, $uuid);
            $this->createDirectoryIfNotExist(pathinfo($pathname, PATHINFO_DIRNAME));
            $this->createEmptyFile($pathname);
            $this->getEventDispatcher()?->dispatch(Events::STUB_CREATED, new StubContext($uuid, $className));
        } catch (Throwable $e) {
            $this->getLogger()?->log($e);
            $this->getEventDispatcher()?->dispatch(Events::STUB_WRITE_FAILED, new StubContext($uuid, $className));
            throw new StubSavingFailureException(message: sprintf('Stub creation failed for uuid: %s and class: %s', $uuid, $className), previous: $e);
        }
    }

    /**
     * @throws SerializationFailureException
     * @throws SafeModeActivationFailedException
     * @throws IOException
     */
    private function registerClassname(string $className): void
    {
        $registeredClassnames = $this->getRegisteredClassnames(); // cached in memory

        if (!in_array($className, $registeredClassnames, true)) {
            $this->registeredClassNamesCache[] = $className;
            $this->createDirectoryIfNotExist($this->getStubDirectory());
            $this->getWriter()->atomicWrite(
                $this->getFilePathClassnames(),
                json_encode($this->registeredClassNamesCache, JSON_UNESCAPED_SLASHES)
            );
        }
    }

    /**
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     */
    public function getRegisteredClassnames(): ?array
    {
        if ($this->registeredClassNamesCache !== null) {
            return $this->registeredClassNamesCache;
        }

        $filenameClassnames = $this->getFilePathClassnames();
        if (file_exists($filenameClassnames)) {
            $this->registeredClassNamesCache = $this->loadFromJsonFile($filenameClassnames) ?? [];
        } else {
            $this->registeredClassNamesCache = [];
        }

        return $this->registeredClassNamesCache;
    }

    /**
     * Retrieves the file path for the classnames JSON file.
     *
     * @return string The file path of the classnames JSON file.
     */
    protected function getFilePathClassnames(): string
    {
        return $this->getStubDirectory() . DIRECTORY_SEPARATOR . 'classnames.json';
    }

    /**
     * @throws IOException
     */
    protected function createDirectoryIfNotExist(string $directory): void
    {
        if (false === (new Directory($directory))->createIfNotExists()) {
            throw new IOException('Unable to create directory: ' . $directory);
        }
    }

    /**
     * Creates an empty file with the specified filename.
     *
     * @param string $filename The name of the file to be created.
     * @return void
     */
    protected function createEmptyFile(string $filename): void
    {
        $this->getWriter()->atomicWrite($filename);
    }

    /**
     * Get a list of classnames for a subset of objects
     *
     * @param array|null $subSet
     * @return array
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     * @throws InvalidUUIDException
     */
    public function getClassNames(?array $subSet = null): array
    {
        if (null === $subSet) {
            $registeredClassnames = $this->getRegisteredClassnames();
            if ($registeredClassnames !== null) {
                return $registeredClassnames;
            }
        }

        $classNames = [];
        foreach ($this->getSelection($subSet) as $uuid) {
            $className = $this->getClassName($uuid);
            if ($className) {
                $classNames[$className] = $className;
            }
        }
        return array_values($classNames);
    }

    /**
     * @throws IOException
     */
    public function getMemoryConsumption(string $uuid): int
    {
        if (false === $fileSizeData = @filesize($this->getFilePathData($uuid))) {
            throw new IOException('Unable to determine file size for file ' . $this->getFilePathData($uuid));
        }

        if (false === $fileSizeMetadata = @filesize($this->getFilePathMetadata($uuid))) {
            throw new IOException('Unable to determine file size for file ' . $this->getFilePathMetadata($uuid));
        }

        return $fileSizeData + $fileSizeMetadata;
    }

    /**
     * Rebuilds all stub files in the stub directory by tearing down existing stubs
     * and recreating them for each listed UUID.
     *
     * @return void
     * @throws InvalidUUIDException
     * @throws StubSavingFailureException
     */
    public function rebuildStubs(): void
    {
        $directory = new Directory($this->getStubDirectory());
        $directory->tearDown();

        foreach ($this->list() as $uuid) {
            $className = $this->getClassName($uuid);
            $this->createStub($className, $uuid);
        }
    }

    /**
     * Retrieves a list of all available UUIDs by extracting keys from the stored files.
     *
     * @return Traversable  Returns a traversable of UUIDs
     */
    public function list(?string $className = null): Traversable
    {
        if (null !== $className && is_dir($pathClassStubs = $this->getClassStubDirectory($className))) {
            return $this->createStubIterator($pathClassStubs);
        }

        return $this->createObjectIterator($className);
    }

    /**
     * Creates an iterator for traversing through stub files located in the specified
     * class stubs directory, applying a filter to match files with the stub file suffix.
     *
     * @param string $pathClassStubs The path to the directory containing class stub files.
     *
     * @return Traversable An iterator that provides filtered access to stub files in the directory.
     */
    private function createStubIterator(string $pathClassStubs): Traversable
    {
        $pattern = $pathClassStubs . DIRECTORY_SEPARATOR . '*' . static::FILE_SUFFIX_STUB;

        return new class (new GlobIterator($pattern), static::FILE_SUFFIX_STUB, $pathClassStubs) extends FilterIterator {
            private string $extension;
            private string $pathClassStubs;

            public function __construct(Iterator $iterator, string $extension, string $pathClassStubs)
            {
                parent::__construct($iterator);
                $this->extension = $extension;
                $this->pathClassStubs = $pathClassStubs;
            }

            public function rewind(): void
            {
                clearstatcache(true, $this->pathClassStubs);
                parent::rewind();
            }

            public function accept(): bool
            {
                return true;
            }

            public function current(): string
            {
                return basename(parent::current(), $this->extension);
            }

            public function key(): string
            {
                return $this->current();
            }
        };
    }

    /**
     * Constructs the object storage handler by initializing directory, file settings,
     * caching, and maximum nesting level configurations.
     *
     * @param string $storageDir The directory path where objects will be stored.
     * @param LoggerInterface|null $logger
     * @param LockAdapterInterface|null $lockAdapter
     * @param StateHandler|null $stateHandler
     * @param DispatcherInterface|null $eventDispatcher
     * @param CacheInterface|null $cache
     * @param GeneratorInterface|null $generator
     * @param CacheInterface|null $metadataCache
     * @param int $maxNestingLevel The maximum allowed depth for object nesting during processing. Defaults to 100.
     * @throws IOException If the storage directory cannot be created.
     */
    public function __construct(
        private string                  $storageDir,
        protected ?LoggerInterface      $logger = null,
        protected ?LockAdapterInterface $lockAdapter = null,
        protected ?StateHandler         $stateHandler = null,
        ?DispatcherInterface            $eventDispatcher = null,
        ?CacheInterface                 $cache = null,
        ?GeneratorInterface             $generator = null,
        ?CacheInterface                 $metadataCache = null,
        private int                     $maxNestingLevel = 100
    )
    {
        $this->objectUuidMap = new SplObjectStorage();
        $this->processingStack = new SplObjectStorage();

        $this->setStorageDir($storageDir);
        $this->setClassRenameMap(new ClassRenameMap());

        if (null === $cache) {
            $cache = new InMemoryCache();
        }
        $this->setCache($cache);

        if (null === $metadataCache) {
            $metadataCache = new InMemoryCache();
        }
        $this->setMetadataCache($metadataCache);

        if (null === $generator) {
            $generator = new UUIDGenerator();
        }
        $this->setGenerator($generator);

        if (null === $eventDispatcher) {
            $eventDispatcher = new Dispatcher();
        }
        $this->setEventDispatcher($eventDispatcher);

        if (null === $stateHandler) {
            $stateHandler = new StateHandler($this->getStorageDir());
            $stateHandler->setEventDispatcher($eventDispatcher);
        }
        $this->setStateHandler($stateHandler);

        if (null === $lockAdapter) {
            $lockAdapter = new FileSystemLockingBackend($this->getStorageDir());
            $lockAdapter->setStateHandler($stateHandler);
            $lockAdapter->setLogger($this->logger);
            $lockAdapter->setEventDispatcher($eventDispatcher);
        }
        $this->setLockAdapter($lockAdapter);
    }

    /**
     * @param string $storageDir
     * @return void
     * @throws IOException
     */
    public function setStorageDir(string $storageDir): void
    {
        $this->storageDir = rtrim($storageDir, DIRECTORY_SEPARATOR);
        $this->createDirectoryIfNotExist($this->storageDir);
    }

    /**
     * Creates an iterator that filters objects based on the provided classname and retrieves them from storage.
     *
     * @param string|null $className The classname to filter objects by. If null, no filtering is applied.
     * @return Traversable An iterator for traversing filtered objects.
     */
    private function createObjectIterator(?string $className): Traversable
    {
        $pattern = $this->getStorageDir() . DIRECTORY_SEPARATOR . '*' . static::FILE_SUFFIX_OBJECT;
        return new class (new GlobIterator($pattern), $className, static::FILE_SUFFIX_OBJECT, $this) extends FilterIterator {

            private ?string $expectedClassname = null;
            private string $extension;
            private ObjectStorage $storage;

            public function __construct(GlobIterator $iterator, ?string $className, string $extension, ObjectStorage $storage)
            {
                parent::__construct($iterator);
                $this->expectedClassname = $className;
                $this->storage = $storage;
                $this->extension = $extension;
            }

            public function rewind(): void
            {
                clearstatcache(true, $this->storage->getStorageDir());
                parent::rewind();
            }

            public function accept(): bool
            {
                if (null !== $this->expectedClassname) {
                    try {
                        $uuid = $this->current();
                        $assignedClassname = $this->storage->getClassName($uuid);
                        return $assignedClassname === $this->expectedClassname;
                    } catch (Throwable $e) {
                        $this->storage->getLogger()?->log($e);
                        return false;
                    }
                }
                return true;
            }

            public function current(): string
            {
                return basename(parent::current(), $this->extension);
            }

            public function key(): string
            {
                return $this->current();
            }
        };
    }
}