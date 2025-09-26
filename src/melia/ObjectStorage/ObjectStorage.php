<?php

namespace melia\ObjectStorage;

use FilterIterator;
use GlobIterator;
use Iterator;
use melia\ObjectStorage\Exception\ClassAliasCreationFailureException;
use melia\ObjectStorage\Exception\DanglingReferenceException;
use melia\ObjectStorage\Exception\Exception;
use melia\ObjectStorage\Exception\InvalidFileFormatException;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\Exception\LockException;
use melia\ObjectStorage\Exception\MaxNestingLevelExceededException;
use melia\ObjectStorage\Exception\MetadataNotFoundException;
use melia\ObjectStorage\Exception\MetataDeletionFailureException;
use melia\ObjectStorage\Exception\ObjectDeletionFailureException;
use melia\ObjectStorage\Exception\ObjectNotFoundException;
use melia\ObjectStorage\Exception\ResourceSerializationNotSupportedException;
use melia\ObjectStorage\Exception\SafeModeActivationFailedException;
use melia\ObjectStorage\Exception\SerializationFailureException;
use melia\ObjectStorage\Exception\TypeConversionFailureException;
use melia\ObjectStorage\File\Directory;
use melia\ObjectStorage\File\ReaderAwareTrait;
use melia\ObjectStorage\File\WriterAwareTrait;
use melia\ObjectStorage\Locking\Backends\FileSystem as FileSystemLockingBackend;
use melia\ObjectStorage\Locking\LockAdapterInterface;
use melia\ObjectStorage\Logger\LoggerInterface;
use melia\ObjectStorage\Reflection\Reflection;
use melia\ObjectStorage\State\StateHandler;
use melia\ObjectStorage\Storage\StorageAbstract;
use melia\ObjectStorage\Storage\StorageInterface;
use melia\ObjectStorage\Storage\StorageMemoryConsumptionInterface;
use melia\ObjectStorage\UUID\Exception\GenerationFailureException;
use melia\ObjectStorage\UUID\Exception\InvalidUUIDException;
use melia\ObjectStorage\UUID\Helper;
use melia\ObjectStorage\UUID\Validator;
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

    /**
     * The suffix used for metadata files.
     */
    private const FILE_SUFFIX_METADATA = '.metadata';

    private const FILE_SUFFIX_STUB = '.stub';
    private const FILE_SUFFIX_OBJECT = '.obj';

    /**
     * An array used to cache objects for reuse, minimizing redundant object creation.
     */
    private array $objectCache = [];

    /**
     * An array used to maintain a stack of items during processing.
     */
    private array $processingStack = [];

    private array $hashToUuid = [];

    /** @var array<string>|null */
    private ?array $registeredClassnamesCache = null;

    /**
     * Constructs the object storage handler by initializing directory, file settings,
     * caching, and maximum nesting level configurations.
     *
     * @param string $storageDir The directory path where objects will be stored.
     * @param LoggerInterface|null $logger
     * @param LockAdapterInterface|null $lockAdapter
     * @param StateHandler|null $stateHandler
     * @param string $reservedReferenceName
     * @param bool $enableCache Whether to enable in-memory caching for stored objects. Defaults to true.
     * @param int $maxNestingLevel The maximum allowed depth for object nesting during processing. Defaults to 100.
     * @throws IOException If the storage directory cannot be created.
     */
    public function __construct(
        private string                  $storageDir,
        protected ?LoggerInterface      $logger = null,
        protected ?LockAdapterInterface $lockAdapter = null,
        protected ?StateHandler         $stateHandler = null,
        private string                  $reservedReferenceName = '__reference',
        private bool                    $enableCache = true,
        private int                     $maxNestingLevel = 100
    )
    {
        if (null === $stateHandler) {
            $stateHandler = new StateHandler($this->storageDir);
            $this->setStateHandler($stateHandler);
        }
        if (null === $lockAdapter) {
            $lockAdapter = new FileSystemLockingBackend($this->storageDir);
            $lockAdapter->setStateHandler($stateHandler);
            $lockAdapter->setLogger($this->logger);
            $this->setLockAdapter($lockAdapter);
        }
        $this->storageDir = rtrim($storageDir, '/\\');
        $this->createDirectoryIfNotExist($this->storageDir);
    }

    /**
     * Deletes an object based on its UUID.
     *
     * @param string $uuid The unique identifier of the object to be deleted.
     * @param bool $force Determines whether errors should be ignored if the object does not exist. If true, returns false if the object does not exist.
     * @return bool Returns true if the object was successfully deleted, or false if the object does not exist and $force is true.
     * @throws ObjectNotFoundException Thrown when the object is not found and $force is false.
     * @throws ObjectDeletionFailureException Thrown when the object could not be deleted.
     * @throws MetataDeletionFailureException
     */
    public function delete(string $uuid, bool $force = false): bool
    {
        if ($this->getStateHandler()->safeModeEnabled()) {
            throw new ObjectDeletionFailureException('Safe mode is enabled. Object cannot be deleted.');
        }

        $className = $this->getClassname($uuid);
        $filePath = $this->getFilePathData($uuid);

        try {
            $this->getLockAdapter()->acquireExclusiveLock($uuid);

            if ($this->enableCache) {
                unset($this->objectCache[$uuid]);
            }

            if (!$this->exists($uuid)) {
                if ($force) {
                    return false;
                }
                throw new ObjectNotFoundException(sprintf('Object with uuid %s not found', $uuid));
            }

            if (!unlink($filePath)) {
                throw new ObjectDeletionFailureException('Object with uuid ' . $uuid . ' could not be deleted');
            }

            $filePathMetadata = $this->getFilePathMetadata($uuid);
            if (file_exists($filePathMetadata)) {
                if (!unlink($filePathMetadata)) {
                    throw new MetataDeletionFailureException('Metadata for uuid ' . $uuid . ' could not be deleted');
                }
            }

            $filePathStub = $this->getFilePathStub($className, $uuid);
            if (file_exists($filePathStub)) {
                if (!unlink($filePathStub)) {
                    throw new MetataDeletionFailureException('Stub for uuid ' . $uuid . ' could not be deleted');
                }
            }

            return true;
        } finally {
            if ($this->getLockAdapter()->isLockedByThisProcess($uuid)) {
                $this->getLockAdapter()->releaseLock($uuid);
            }
        }
    }

    /**
     * Generates the file path for a given UUID.
     *
     * @param string $uuid The unique identifier used to generate the file path.
     * @return string The full file path constructed using the UUID.
     */
    public function getFilePathData(string $uuid): string
    {
        return $this->storageDir . DIRECTORY_SEPARATOR . $uuid . static::FILE_SUFFIX_OBJECT;
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
     * Generates the file path for the metadata associated with a specific UUID.
     *
     * @param string $uuid The unique identifier used to build the metadata file path.
     * @return string Returns the file path of the metadata corresponding to the provided UUID.
     */
    public function getFilePathMetadata(string $uuid): string
    {
        return $this->storageDir . DIRECTORY_SEPARATOR . $uuid . static::FILE_SUFFIX_METADATA;
    }

    /**
     * @throws MetadataNotFoundException
     */
    public function setLifetime(string $uuid, int $ttl): void
    {
        $this->setExpiration($uuid, time() + $ttl);
    }

    /**
     * Updates the expiration timestamp for a given UUID in the metadata storage.
     *
     * @param string $uuid The unique identifier for which the expiration needs to be set.
     * @param int|null $expiresAt The Unix timestamp specifying the expiration time, or null to unset the expiration.
     *
     * @return void
     * @throws MetadataNotFoundException If metadata for the specified UUID cannot be loaded.
     */
    public function setExpiration(string $uuid, ?int $expiresAt): void
    {
        $metadata = $this->loadMetadata($uuid);
        if (null === $metadata) {
            throw new MetadataNotFoundException('Unable to load metadata for uuid: ' . $uuid);
        }
        $metadata->setTimestampExpiresAt($expiresAt);
        $this->saveMetadata($metadata);
    }

    /**
     * Loads metadata associated with a given UUID by reading from a JSON file and validating it.
     * If an error occurs during the process, it is logged, and null is returned.
     *
     * @param string $uuid The unique identifier for the metadata to be loaded.
     * @return Metadata|null The loaded and validated metadata object, or null if loading fails.
     */
    public function loadMetadata(string $uuid): ?Metadata
    {
        try {
            $metadata = $this->loadFromJsonFile($this->getFilePathMetadata($uuid));
            if (is_array($metadata)) {
                $metadata = Metadata::createFromArray($metadata);
                $metadata->validate();
                return $metadata;
            }
        } catch (Throwable $e) {
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
        } catch (IOException $e) {
            $this->getLogger()?->log($e);
            return null;
        }

        $data = json_decode($data, true, $this->maxNestingLevel);

        if (null === $data) {
            $this->getStateHandler()->enableSafeMode();
            throw new SerializationFailureException('Unable to decode data from file: ' . $filename);
        }

        return $data;
    }

    /**
     * Saves the provided metadata to a file by serializing it to JSON and writing it atomically.
     *
     * @param Metadata $metadata The metadata to be serialized and saved.
     * @return void
     */
    private function saveMetadata(Metadata $metadata): void
    {
        $this->getWriter()->atomicWrite($this->getFilePathMetadata($metadata->getUUID()), json_encode($metadata, depth: $this->maxNestingLevel));
    }

    /**
     * Clears the internal object cache by resetting it to an empty array.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->objectCache = [];
        $this->hashToUuid = [];
        $this->registeredClassnamesCache = null;
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
        if ($this->expired($uuid)) {
            /* do not delete an expired object since the ttl might be updated later */
            return null;
        }

        try {
            if ($exclusive) {
                $this->getLockAdapter()->acquireExclusiveLock($uuid);
            } else {
                $this->getLockAdapter()->acquireSharedLock($uuid);
            }
            $object = $this->loadFromStorage($uuid);
            if (!$exclusive) {
                $this->getLockAdapter()->releaseLock($uuid);
            }
            return $object;
        } catch (Throwable $e) {
            if ($this->getLockAdapter()->isLockedByThisProcess($uuid)) {
                $this->getLockAdapter()->releaseLock($uuid);
            }
            throw $e;
        }
    }

    /**
     * Checks if the object associated with the given UUID is expired.
     *
     * @param string $uuid The unique identifier of the object.
     * @return bool Returns true if the object is expired, false otherwise.
     */
    public function expired(string $uuid): bool
    {
        $lifetime = $this->getLifetime($uuid);
        return null !== $lifetime && $lifetime <= 0;
    }

    /**
     * Calculates the remaining lifetime in seconds for the given UUID based on its expiration time.
     *
     * @param string $uuid The unique identifier for which to calculate the remaining lifetime.
     * @return int|null Returns the remaining lifetime in seconds, or null if the expiration time is not set. Negative values represent the lifetime since expiration
     */
    public function getLifetime(string $uuid): ?int
    {
        $expiresAt = $this->getExpiration($uuid);
        if (null === $expiresAt) {
            return null;
        }
        return $expiresAt - time();
    }

    /**
     * Retrieves the expiration timestamp for the given UUID from its metadata.
     * Throws an exception if metadata cannot be loaded for the specified UUID.
     *
     * @param string $uuid The unique identifier used to retrieve metadata.
     * @return int|null The expiration timestamp if available, or null if not set.
     */
    public function getExpiration(string $uuid): ?int
    {
        return $this->loadMetadata($uuid)?->getTimestampExpiresAt();
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
        if ($this->enableCache && isset($this->objectCache[$uuid])) {
            return $this->objectCache[$uuid];
        }

        $data = $this->loadFromJsonFile($filename = $this->getFilePathData($uuid));
        if (false === is_array($data)) {
            return null;
        }

        $classname = $this->getClassname($uuid);
        if (null === $classname) {
            $this->getStateHandler()->enableSafeMode();
            throw new InvalidFileFormatException('Unable to determine className for: ' . $uuid);
        }

        if (false === $data) {
            $this->getStateHandler()->enableSafeMode();
            throw new SerializationFailureException('Unable to deserialize data from file: ' . $filename);
        }

        $object = $this->processLoadedData($data, $classname);

        if ($this->enableCache) {
            $this->objectCache[$uuid] = $object;
        }

        Helper::assign($object, $uuid);

        return $object;
    }

    /**
     * Get a classname for a certain object
     *
     * @param string $uuid
     * @return string|null
     */
    public function getClassname(string $uuid): ?string
    {
        return $this->loadMetadata($uuid)?->getClassName() ?? null;
    }

    /**
     * Converts the provided data array into an object of the specified class
     * by mapping the data to the class's properties.
     *
     * @param array $data An associative array containing property names and their corresponding values.
     * @param string $classname The fully qualified name of the class to create an instance of.
     * @return object An instance of the specified class with its properties populated from the provided data.
     * @throws ReflectionException
     * @throws InvalidUUIDException|TypeConversionFailureException|Exception|DanglingReferenceException
     */
    private function processLoadedData(array $data, string $classname): object
    {
        if (false === class_exists($classname)) {
            if (false === class_alias(get_class(new class {
                }), $classname)) {
                throw new ClassAliasCreationFailureException('Unable to create class alias for unknown class ' . $classname);
            }
        }

        $object = (new ReflectionClass($classname))->newInstanceWithoutConstructor();
        $reflection = new Reflection($object);

        foreach ($data as $propertyName => $value) {
            $type = $reflection->getPropertyType($propertyName);

            if (is_array($value) && isset($value[$this->reservedReferenceName])) {
                $refUUID = $value[$this->reservedReferenceName];
                if (false === Validator::validate($refUUID)) {
                    /* reference UUID is not valid, so we just set the property to the value */
                    $reflection->set($propertyName, [$this->reservedReferenceName => $refUUID]);
                } else {
                    $reference = new LazyLoadReference($this, $refUUID, $object, [$propertyName]);
                    /* if LazyLoadReference is not allowed, then we need to convert the reference to the real object */
                    if ($type instanceof ReflectionNamedType) {
                        if (LazyLoadReference::class !== $type->getName() &&
                            false === in_array($type->getName(), ['object', 'mixed'], true)
                        ) {
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
                            $reference = $reference->getObject();
                        }
                    }
                    $reflection->set($propertyName, $reference);
                }
            } else if (is_array($value)) {
                $reflection->set($propertyName, $this->processLoadedArray($object, $value, [$propertyName]));
            } else {
                /* type conversion of non-union types */
                if ($type instanceof ReflectionNamedType) {
                    $expectedType = $type->getName();
                    $givenType = gettype($value);

                    if ($givenType !== $expectedType && in_array($givenType, ['integer', 'double', 'boolean', 'string'])) {
                        if (false === settype($value, $expectedType)) {
                            throw new TypeConversionFailureException('Unable to convert value to type ' . $expectedType . ' for property ' . $propertyName . ' of class ' . $classname);
                        }
                    }
                }
                $reflection->set($propertyName, $value);
            }
        }

        return $object;
    }

    /**
     * Processes a loaded array and converts specific elements into lazy load references or recursively processes subarrays.
     *
     * @param object $object The related object used in the creation of lazy load references.
     * @param array $array The array to be processed, potentially containing nested arrays or special references.
     * @param array $path The current hierarchy path being processed within the array, used for lazy load reference creation.
     * @return array Returns the processed array with lazy load references or recursively processed subarrays.
     * @throws InvalidUUIDException
     */
    private function processLoadedArray(object $object, array $array, array $path): array
    {
        $processed = [];
        foreach ($array as $key => $value) {
            if (is_array($value) && isset($value[$this->reservedReferenceName])) {
                $processed[$key] = new LazyLoadReference($this, $value[$this->reservedReferenceName], $object, [...$path, $key]);
            } else if (is_array($value)) {
                $processed[$key] = $this->processLoadedArray($object, $value, [...$path, $key]);
            } else {
                $processed[$key] = $value;
            }
        }
        return $processed;
    }

    /**
     * Releases all active locks held by the lock adapter before object destruction.
     *
     * @return void
     */
    public function __destruct()
    {
        $this->getLockAdapter()->releaseActiveLocks();
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
     * @throws LockException
     * @throws MaxNestingLevelExceededException
     * @throws ReflectionException
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     * @throws Throwable
     */
    public function store(object $object, ?string $uuid = null, ?int $ttl = null): string
    {
        if ($this->getStateHandler()->safeModeEnabled()) {
            throw new Exception('Safe mode is enabled. Object cannot be stored.');
        }

        // LazyLoadReference: ungeladen -> nur UUID zurückgeben
        if ($object instanceof LazyLoadReference) {
            if (false === $object->isLoaded()) {
                return $object->getUUID();
            }
            $object = $object->getObject();
            $uuid = $object->getUUID();
        }

        try {
            $objectHash = $this->getObjectHash($object);

            // 1) UUID bevorzugt aus Param, sonst aus AwareInterface, sonst aus hashToUuid-Mapping WIEDERVERWENDEN,
            //    nur wenn nichts vorhanden ist, eine neue UUID erzeugen
            $uuid ??= Helper::getAssigned($object) ?? $this->hashToUuid[$objectHash] ?? $this->getNextAvailableUuid();

            // 2) Mapping aktualisieren (wichtig für Referenzen und Folge-Store-Aufrufe)
            $this->hashToUuid[$objectHash] = $uuid;

            // 3) Kein Early-Return: serializeAndStore IMMER aufrufen, damit Updates via Checksumme erkannt werden
            $this->getLockAdapter()->acquireExclusiveLock($uuid);
            $this->serializeAndStore($object, $uuid, $ttl);
            if ($this->getLockAdapter()->isLockedByThisProcess($uuid)) {
                $this->getLockAdapter()->releaseLock($uuid);
            }

            return $uuid;
        } catch (Throwable $e) {
            if ($this->getLockAdapter()->isLockedByThisProcess($uuid)) {
                $this->getLockAdapter()->releaseLock($uuid);
            }
            throw $e;
        }
    }

    /**
     * Generates a unique hash for the given object.
     *
     * @param object $object The object for which the hash is to be generated.
     * @return string Returns a unique hash representing the given object.
     */
    private function getObjectHash(object $object): string
    {
        return spl_object_hash($object);
    }

    /**
     * @param object $object
     * @param string $uuid
     * @param int|null $ttl
     *
     * @throws DanglingReferenceException
     * @throws GenerationFailureException
     * @throws IOException
     * @throws MaxNestingLevelExceededException
     * @throws ReflectionException
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     * @throws InvalidUUIDException
     */
    private function serializeAndStore(object $object, string $uuid, ?int $ttl = null): void
    {
        try {
            $objectHash = $this->getObjectHash($object);

            // Hash→UUID-Mapping sicherstellen
            if (!isset($this->hashToUuid[$objectHash])) {
                $this->hashToUuid[$objectHash] = $uuid;
            }

            // assign the UUID (stable serialization)
            Helper::assign($object, $uuid);

            // Rekursionsschutz
            if (in_array($objectHash, $this->processingStack, true)) {
                return;
            }

            $this->processingStack[] = $objectHash;

            $jsonGraph = $this->exportGraphAndStoreReferencedChildren($object);

            $metadata = new Metadata();
            $metadata->setTimestampCreation(time());
            $metadata->setUuid($uuid);
            $metadata->setClassName($classname = get_class($object));
            $metadata->setVersion(1);
            $metadata->setChecksum(md5($jsonGraph));
            $metadata->setTimestampExpiresAt($ttl ? time() + $ttl : null);

            $loadedMetadata = $this->loadMetadata($uuid);
            $checksumChanged = $metadata->getChecksum() !== ($loadedMetadata?->getChecksum() ?? null);

            if ($checksumChanged) {
                $this->getWriter()->atomicWrite($this->getFilePathData($uuid), $jsonGraph);
                $this->saveMetadata($metadata);
                $this->createStub($classname, $uuid);
            }

            if ($this->enableCache) {
                $this->objectCache[$uuid] = $object;
            }
        } finally {
            // Rekursionsschutz entfernen
            array_pop($this->processingStack);
        }
    }

    /**
     * @param object $object
     * @return string
     *
     * @throws DanglingReferenceException
     * @throws GenerationFailureException
     * @throws IOException
     * @throws MaxNestingLevelExceededException
     * @throws ReflectionException
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     * @throws InvalidUUIDException
     */
    public function exportGraphAndStoreReferencedChildren(object $object): string
    {
        $json = json_encode($this->createGraphAndStoreReferencedChildren($object), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (false === $json) {
            throw new SerializationFailureException('Unable export object graph to JSON');
        }
        return $json;
    }

    /**
     * Creates a graph representation of the given object's properties and stores
     * referenced child elements, ensuring deterministic property order.
     *
     * @param object $object The object whose properties are to be processed for the graph.
     * @return array An associative array representing the graph structure of the object's properties.
     * @throws DanglingReferenceException
     * @throws GenerationFailureException
     * @throws IOException
     * @throws MaxNestingLevelExceededException
     * @throws ReflectionException
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     * @throws InvalidUUIDException
     */
    private function createGraphAndStoreReferencedChildren(object $object): array
    {
        $result = [];
        $reflection = new Reflection($object);

        // ensure deterministic order of properties
        $propertyNames = $reflection->getPropertyNames();
        sort($propertyNames, SORT_STRING);

        foreach ($propertyNames as $propertyName) {
            if (false === $reflection->initialized($propertyName)) {
                continue;
            }

            $value = $reflection->get($propertyName);

            try {
                $result[$propertyName] = $this->transformValueForGraph($value, [$propertyName], 0);
            } catch (ResourceSerializationNotSupportedException $e) {
                $this->getLogger()?->log($e);
            }
        }

        return $result;
    }

    /**
     * @param mixed $value
     * @param array $path
     * @param int $level
     * @return mixed
     *
     * @throws DanglingReferenceException
     * @throws GenerationFailureException
     * @throws IOException
     * @throws MaxNestingLevelExceededException
     * @throws ReflectionException
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     * @throws ResourceSerializationNotSupportedException
     * @throws InvalidUUIDException
     */
    private function transformValueForGraph(mixed $value, array $path, int $level): mixed
    {
        if ($level > $this->maxNestingLevel) {
            throw new MaxNestingLevelExceededException('Maximum nesting level of ' . $this->maxNestingLevel . ' exceeded');
        }

        if (is_resource($value)) {
            throw new ResourceSerializationNotSupportedException('Resources are not supported');
        }

        if (is_object($value)) {
            if ($value instanceof LazyLoadReference) {
                if (!$value->isLoaded()) {
                    $refUuid = $value->getUUID();
                    return [$this->reservedReferenceName => $refUuid];
                }

                $loaded = $value->getObject();
                $value = $loaded;
            }

            $hash = $this->getObjectHash($value);
            $refUuid = Helper::getAssigned($value) ?? $this->hashToUuid[$hash] ?? $this->getNextAvailableUuid();

            $this->hashToUuid[$hash] = $refUuid;

            if (!in_array($hash, $this->processingStack, true)) {
                $this->serializeAndStore($value, $refUuid);
            }

            return [$this->reservedReferenceName => $refUuid];
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                try {
                    $out[$k] = $this->transformValueForGraph($v, array_merge($path, [$k]), $level + 1);
                } catch (ResourceSerializationNotSupportedException $e) {
                    $this->getLogger()?->log($e);
                }
            }
            return $out;
        }

        return $value;
    }

    /**
     * @param string $classname
     * @param string $uuid
     * @throws IOException
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     */
    public function createStub(string $classname, string $uuid): void
    {
        $this->registerClassname($classname);
        $pathname = $this->getFilePathStub($classname, $uuid);
        $this->createDirectoryIfNotExist(pathinfo($pathname, PATHINFO_DIRNAME));
        $this->createEmptyFile($pathname);
    }

    /**
     * @throws SerializationFailureException
     * @throws SafeModeActivationFailedException
     * @throws IOException
     */
    private function registerClassname(string $classname): void
    {
        $registeredClassnames = $this->getRegisteredClassnames(); // cached in memory

        if (!in_array($classname, $registeredClassnames, true)) {
            $this->registeredClassnamesCache[] = $classname;
            $this->createDirectoryIfNotExist($this->getStubDirectory());
            $this->getWriter()->atomicWrite(
                $this->getFilePathClassnames(),
                json_encode($this->registeredClassnamesCache, JSON_UNESCAPED_SLASHES)
            );
        }
    }

    /**
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     */
    public function getRegisteredClassnames(): ?array
    {
        if ($this->registeredClassnamesCache !== null) {
            return $this->registeredClassnamesCache;
        }

        $filenameClassnames = $this->getFilePathClassnames();
        if (file_exists($filenameClassnames)) {
            $this->registeredClassnamesCache = $this->loadFromJsonFile($filenameClassnames) ?? [];
        } else {
            $this->registeredClassnamesCache = [];
        }

        return $this->registeredClassnamesCache;
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
     * Retrieves the path to the stub directory.
     *
     * @return string The full path to the stub directory.
     */
    protected function getStubDirectory(): string
    {
        return $this->storageDir . DIRECTORY_SEPARATOR . 'stubs';
    }

    /**
     * @throws IOException
     */
    protected function createDirectoryIfNotExist(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0777, true)) {
            throw new IOException('Unable to open storage directory: ' . $directory);
        }
    }

    /**
     * Generates the file path for a stub associated with a specific class and UUID.
     *
     * @param string $classname The name of the class for which the stub is being generated.
     * @param string $uuid The unique identifier used to differentiate the stub file.
     * @return string The full file path for the stub.
     */
    public function getFilePathStub(string $classname, string $uuid): string
    {
        return $this->getClassStubDirectory($classname) . DIRECTORY_SEPARATOR . $uuid . '.stub';
    }

    /**
     * Retrieves the directory path where the class stub for the given class name is stored.
     *
     * @param string $classname The name of the class for which the stub directory path is being generated.
     * @return string The full path to the class stub directory.
     */
    protected function getClassStubDirectory(string $classname): string
    {
        return $this->getStubDirectory() . DIRECTORY_SEPARATOR . md5($classname);
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
     */
    public function getClassnames(?array $subSet = null): array
    {
        if (null === $subSet) {
            $registeredClassnames = $this->getRegisteredClassnames();
            if ($registeredClassnames !== null) {
                return $registeredClassnames;
            }
        }

        $classnames = [];
        foreach ($this->getSelection($subSet) as $uuid) {
            $classname = $this->getClassname($uuid);
            if ($classname) {
                $classnames[$classname] = $classname;
            }
        }
        return array_values($classnames);
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
     * @throws IOException
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     */
    public function rebuildStubs(): void
    {
        $directory = new Directory($this->getStubDirectory());
        $directory->tearDown();

        foreach ($this->list() as $uuid) {
            $classname = $this->getClassname($uuid);
            $this->createStub($classname, $uuid);
        }
    }

    /**
     * Retrieves a list of all available UUIDs by extracting keys from the stored files.
     *
     * @return Traversable  Returns a traversable of UUIDs
     */
    public function list(?string $classname = null): Traversable
    {
        if (null !== $classname && is_dir($pathClassStubs = $this->getClassStubDirectory($classname))) {
            return $this->createStubIterator($pathClassStubs);
        }

        return $this->createObjectIterator($classname);
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
     * Creates an iterator that filters objects based on the provided classname and retrieves them from storage.
     *
     * @param string|null $classname The classname to filter objects by. If null, no filtering is applied.
     * @return Traversable An iterator for traversing filtered objects.
     */
    private function createObjectIterator(?string $classname): Traversable
    {
        $pattern = $this->storageDir . DIRECTORY_SEPARATOR . '*' . static::FILE_SUFFIX_OBJECT;
        return new class (new GlobIterator($pattern), $classname, static::FILE_SUFFIX_OBJECT, $this) extends FilterIterator {

            private ?string $expectedClassname = null;
            private string $extension;
            private ObjectStorage $storage;

            public function __construct(GlobIterator $iterator, ?string $classname, string $extension, ObjectStorage $storage)
            {
                parent::__construct($iterator);
                $this->expectedClassname = $classname;
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
                        $assignedClassname = $this->storage->getClassname($uuid);
                        return $assignedClassname === $this->expectedClassname;
                    } catch (Throwable $e) {
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

    /**
     * Retrieves the directory path where storage operations are performed.
     *
     * @return string The storage directory path as a string.
     */
    public function getStorageDir(): string
    {
        return $this->storageDir;
    }
}
