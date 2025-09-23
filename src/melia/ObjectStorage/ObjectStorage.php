<?php

namespace melia\ObjectStorage;

use GlobIterator;
use FilterIterator;
use Iterator;
use melia\ObjectStorage\Exception\ClassAliasCreationFailureException;
use melia\ObjectStorage\Exception\DanglingReferenceException;
use melia\ObjectStorage\Exception\Exception;
use melia\ObjectStorage\Exception\InvalidFileFormatException;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\Exception\LockException;
use melia\ObjectStorage\Exception\MaxNestingLevelExceededException;
use melia\ObjectStorage\Exception\MetataDeletionFailureException;
use melia\ObjectStorage\Exception\ObjectDeletionFailureException;
use melia\ObjectStorage\Exception\ObjectNotFoundException;
use melia\ObjectStorage\Exception\SafeModeActivationFailedException;
use melia\ObjectStorage\Exception\SerializationFailureException;
use melia\ObjectStorage\Exception\TypeConversionFailureException;
use melia\ObjectStorage\File\Directory;
use melia\ObjectStorage\File\Reader;
use melia\ObjectStorage\File\ReaderInterface;
use melia\ObjectStorage\File\Writer;
use melia\ObjectStorage\File\WriterInterface;
use melia\ObjectStorage\Reflection\Reflection;
use melia\ObjectStorage\Storage\StorageAbstract;
use melia\ObjectStorage\Storage\StorageInterface;
use melia\ObjectStorage\Storage\StorageLockingInterface;
use melia\ObjectStorage\Storage\StorageMemoryConsumptionInterface;
use melia\ObjectStorage\UUID\AwareInterface;
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
class ObjectStorage extends StorageAbstract implements StorageInterface, StorageLockingInterface, StorageMemoryConsumptionInterface
{
    /**
     * Defines the suffix used for lock files to indicate processing or restricted access.
     */
    private const FILE_SUFFIX_LOCK = '.lock';

    /**
     * The suffix used for metadata files.
     */
    private const FILE_SUFFIX_METADATA = '.metadata';

    private const FILE_SUFFIX_STUB = '.stub';
    private const FILE_SUFFIX_OBJECT = '.obj';

    /**
     * An array to store currently active locks.
     */
    private array $activeLocks = [];

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

    private WriterInterface $writer;

    private ReaderInterface $reader;

    /**
     * Constructs the object storage handler by initializing directory, file settings,
     * caching, and maximum nesting level configurations.
     *
     * @param string $storageDir The directory path where objects will be stored.
     * @param string $reservedReferenceName
     * @param bool $enableCache Whether to enable in-memory caching for stored objects. Defaults to true.
     * @param int $maxNestingLevel The maximum allowed depth for object nesting during processing. Defaults to 100.
     * @throws IOException If the storage directory cannot be created.
     */
    public function __construct(
        private string $storageDir,
        private string $reservedReferenceName = '__reference',
        private bool   $enableCache = true,
        private int    $maxNestingLevel = 100
    )
    {
        $this->storageDir = rtrim($storageDir, '/\\');
        $this->createDirectoryIfNotExist($this->storageDir);
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
     * Retrieves the directory path where storage operations are performed.
     *
     * @return string The storage directory path as a string.
     */
    public function getStorageDir(): string
    {
        return $this->storageDir;
    }

    /**
     * Disables safe mode by removing the related safe mode file if it exists.
     *
     * @return bool Returns true if the safe mode file was successfully removed or does not exist.
     */
    public function disableSafeMode(): bool
    {
        if (file_exists($filename = $this->getFilePathSafeMode())) {
            return unlink($filename);
        }
        return true;
    }

    /**
     * Deletes an object based on its UUID.
     *
     * @param string $uuid The unique identifier of the object to be deleted.
     * @param bool $force Determines whether errors should be ignored if the object does not exist. If true, returns false if the object does not exist.
     * @return bool Returns true if the object was successfully deleted, or false if the object does not exist and $force is true.
     * @throws ObjectNotFoundException Thrown when the object is not found and $force is false.
     * @throws ObjectDeletionFailureException|LockException Thrown when the object could not be deleted.
     * @throws MetataDeletionFailureException
     */
    public function delete(string $uuid, bool $force = false): bool
    {
        if ($this->safeModeEnabled()) {
            throw new ObjectDeletionFailureException('Safe mode is enabled. Object cannot be deleted.');
        }

        $filePath = $this->getFilePathData($uuid);

        try {
            $this->lock($uuid, false);

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

            return true;
        } finally {
            if ($this->hasActiveLock($uuid)) {
                $this->unlock($uuid);
            }
        }
    }

    /**
     * Determines whether the safe mode is enabled by checking the existence and content of a specific file.
     *
     * @return bool Returns true if safe mode is enabled, otherwise false.
     */
    public function safeModeEnabled(): bool
    {
        $filename = $this->getFilePathSafeMode();
        return file_exists($filename) && (bool)file_get_contents($filename) === true;
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
     * Retrieves the path to the stub directory.
     *
     * @return string The full path to the stub directory.
     */
    protected function getStubDirectory(): string
    {
        return $this->storageDir . DIRECTORY_SEPARATOR . 'stubs';
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
     * Generates the file path for a stub associated with a specific class and UUID.
     *
     * @param string $classname The name of the class for which the stub is being generated.
     * @param string $uuid The unique identifier used to differentiate the stub file.
     * @return string The full file path for the stub.
     */
    protected function getFilePathStub(string $classname, string $uuid): string
    {
        return $this->getClassStubDirectory($classname) . DIRECTORY_SEPARATOR . $uuid . '.stub';
    }

    /**
     * Acquires a lock for a given resource identified by a unique identifier (UUID).
     * Locks can be exclusive or shared, with a configurable timeout.
     *
     * @param string $uuid A unique identifier for the resource to lock.
     * @param bool $shared Whether the lock should be shared (reader lock) or exclusive (writer lock). Default is exclusive.
     * @param float $timeout The maximum duration (in seconds) to wait for acquiring the lock. Defaults to the class constant LOCK_TIMEOUT.
     * @return void
     * @throws LockException If the lock file cannot be opened, or if the timeout is reached while waiting for the lock.
     */
    public function lock(string $uuid, bool $shared = false, float $timeout = self::LOCK_TIMEOUT_DEFAULT): void
    {
        if ($this->safeModeEnabled()) {
            throw new LockException('Safe mode is enabled. Object cannot be locked.');
        }

        if ($shared && $this->hasActiveSharedLock($uuid)) {
            return;
        }

        if (!$shared && $this->hasActiveExclusiveLock($uuid)) {
            return;
        }

        if ($this->isLocked($uuid)) {
            throw new LockException(sprintf('Lock already acquired from other process for uuid %s', $uuid));
        }

        $lockFile = $this->getLockFilePath($uuid);
        $startTime = microtime(true);
        $lockType = $shared ? LOCK_SH : LOCK_EX;

        if (!file_exists($lockFile)) {
            file_put_contents($lockFile, '');
        }

        $handle = fopen($lockFile, 'r+');
        if ($handle === false) {
            throw new LockException('Unable to open lock file: ' . $lockFile);
        }

        while (!flock($handle, $lockType | LOCK_NB)) {
            if (microtime(true) - $startTime > $timeout) {
                fclose($handle);
                throw new LockException(sprintf('Timeout while waiting for lock: %s (%s)', $uuid, ($shared ? 'shared' : 'exclusive')));
            }
            usleep(100000); // 100ms
        }

        $this->activeLocks[$uuid] = [
            'handle' => $handle,
            'shared' => $shared,
            'exclusive' => !$shared,
        ];
    }

    /**
     * Checks whether a specified resource identified by a UUID has a shared lock.
     *
     * @param string $uuid The unique identifier of the resource to check.
     * @return bool Returns true if the resource has a shared lock; otherwise, returns false.
     */
    public function hasActiveSharedLock(string $uuid): bool
    {
        return isset($this->activeLocks[$uuid]) && $this->activeLocks[$uuid]['shared'];
    }

    /**
     * Checks if an exclusive lock is held for the given unique identifier.
     *
     * @param string $uuid The unique identifier to check for an exclusive lock.
     * @return bool Returns true if an exclusive lock is held for the given identifier; otherwise, false.
     */
    public function hasActiveExclusiveLock(string $uuid): bool
    {
        return isset($this->activeLocks[$uuid]) && $this->activeLocks[$uuid]['exclusive'];
    }

    /**
     * Determines whether a lock exists for the specified unique identifier.
     *
     * @param string $uuid The unique identifier to check for an existing lock.
     * @return bool Returns true if a lock exists for the given identifier, otherwise false.
     */
    public function isLocked(string $uuid): bool
    {
        return file_exists($this->getLockFilePath($uuid));
    }

    /**
     * Generates the full file path for a lock file associated with a given unique identifier.
     * The path includes the storage directory, the UUID, and a predefined lock file suffix.
     *
     * @param string $uuid A unique identifier used to generate the lock file path.
     * @return string Returns the full file path for the lock file.
     */
    private function getLockFilePath(string $uuid): string
    {
        return $this->storageDir . DIRECTORY_SEPARATOR . $uuid . self::FILE_SUFFIX_LOCK;
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
     * Checks if there is an active lock associated with the given unique identifier.
     *
     * @param null|string $uuid The unique identifier to check for an active lock.
     * @return bool Returns true if an active lock exists for the provided identifier, false otherwise.
     */
    public function hasActiveLock(?string $uuid): bool
    {
        if (null === $uuid) {
            return false;
        }
        return isset($this->activeLocks[$uuid]);
    }

    /**
     * Releases an active lock associated with the given unique identifier (UUID).
     * Frees the associated file handle, removes the lock, and deletes the lock file.
     *
     * @param string $uuid The unique identifier for the lock to be released.
     * @return void
     * @throws LockException If no active lock is found for the given UUID.
     */
    public function unlock(string $uuid): void
    {
        $lock = $this->activeLocks[$uuid] ?? null;

        if (null === $lock) {
            throw new LockException('No active lock found for uuid: ' . $uuid);
        }

        if (false === flock($lock['handle'], LOCK_UN)) {
            throw new LockException('Unable to release lock: ' . $uuid);
        }

        if (false === fclose($lock['handle'])) {
            throw new LockException('Unable to close lock file: ' . $uuid);
        }

        $path = $this->getLockFilePath($uuid);

        if (file_exists($path) && false === @unlink($this->getLockFilePath($uuid))) {
            throw new LockException('Unable to delete lock file: ' . $uuid);
        }

        unset($this->activeLocks[$uuid]);
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
        try {
            $this->lock($uuid, !$exclusive);
            $object = $this->loadFromStorage($uuid);
            if (!$exclusive) {
                $this->unlock($uuid);
            }
            return $object;
        } catch (Throwable $e) {
            if ($this->hasActiveLock($uuid)) {
                $this->unlock($uuid);
            }
            throw $e;
        }
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
            $this->enableSafeMode();
            throw new InvalidFileFormatException('Unable to determine className for: ' . $uuid);
        }

        if (false === $data) {
            $this->enableSafeMode();
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
            return null;
        }

        $data = json_decode($data, true, $this->maxNestingLevel);

        if (null === $data) {
            $this->enableSafeMode();
            throw new SerializationFailureException('Unable to decode data from file: ' . $filename);
        }

        return $data;
    }

    public function getReader(): ReaderInterface
    {
        return $this->reader ?? new Reader();
    }

    public function setReader(ReaderInterface $reader): void
    {
        $this->reader = $reader;
    }

    /**
     * Enables safe mode by performing an atomic writing to a designated file.
     *
     * @return bool Returns true if safe mode was successfully enabled, or false if an error occurred during the process.
     * @throws SafeModeActivationFailedException
     */
    public function enableSafeMode(): bool
    {
        try {
            $this->getWriter()->atomicWrite($this->getFilePathSafeMode(), '1');
            return true;
        } catch (Throwable $e) {
            throw new SafeModeActivationFailedException('Unable to enable safe mode', 0, $e);
        }
    }

    public function getWriter(): WriterInterface
    {
        return $this->writer ?? new Writer();
    }

    public function setWriter(WriterInterface $writer): void
    {
        $this->writer = $writer;
    }

    /**
     * Retrieves the file path for safe mode storage based on the storage directory.
     *
     * @return string Returns the full file path for safe mode storage.
     */
    private function getFilePathSafeMode(): string
    {
        return $this->storageDir . DIRECTORY_SEPARATOR . 'safeMode';
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
     * Destructor method that ensures all active locks are released.
     * Logs an error message if unlocking fails for any object.
     *
     * @return void
     */
    public function __destruct()
    {
        foreach (array_keys($this->activeLocks) as $uuid) {
            try {
                $this->unlock($uuid);
            } catch (Throwable $e) {
                $this->getLogger()?->log(new LockException(sprintf('Error while unlocking object %s', $uuid), Exception::CODE_FAILURE_OBJECT_UNLOCK, $e));
            }
        }
    }

    /**
     * @throws DanglingReferenceException
     * @throws GenerationFailureException
     * @throws Throwable
     * @throws Exception
     * @throws LockException
     * @throws MaxNestingLevelExceededException
     * @throws ReflectionException
     */
    public function store(object $object, ?string $uuid = null): string
    {
        if ($this->safeModeEnabled()) {
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
            $assignedUUID = Helper::getAssigned($object);

            // 1) UUID bevorzugt aus Param, sonst aus AwareInterface, sonst aus hashToUuid-Mapping WIEDERVERWENDEN,
            //    nur wenn nichts vorhanden ist, eine neue UUID erzeugen
            $uuid ??= $assignedUUID ?? ($this->hashToUuid[$objectHash] ?? $this->getNextAvailableUuid());

            // 2) Mapping aktualisieren (wichtig für Referenzen und Folge-Store-Aufrufe)
            $this->hashToUuid[$objectHash] = $uuid;

            // 3) Kein Early-Return: serializeAndStore IMMER aufrufen, damit Updates via Checksumme erkannt werden
            $this->lock($uuid, false);
            $this->serializeAndStore($object, $uuid);
            if ($this->hasActiveLock($uuid)) {
                $this->unlock($uuid);
            }

            return $uuid;
        } catch (Throwable $e) {
            if ($this->hasActiveLock($uuid)) {
                $this->unlock($uuid);
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
     *
     * @throws DanglingReferenceException
     * @throws GenerationFailureException
     * @throws IOException
     * @throws MaxNestingLevelExceededException
     * @throws ReflectionException
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     */
    private function serializeAndStore(object $object, string $uuid): void
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

            $processed = json_encode($this->processObjectForStorage($object), depth: $this->maxNestingLevel);

            $metadata = [
                'timestamp' => time(),
                'className' => $classname = get_class($object),
                'uuid' => $uuid,
                'version' => '1.0',
                'checksum' => md5($processed),
            ];

            $loadedMetadata = $this->loadMetadata($uuid);
            $checksumChanged = $loadedMetadata === null || $metadata['checksum'] !== ($loadedMetadata['checksum'] ?? null);

            if ($checksumChanged) {
                $this->getWriter()->atomicWrite($this->getFilePathData($uuid), $processed);
                $this->getWriter()->atomicWrite($this->getFilePathMetadata($uuid), json_encode($metadata, depth: $this->maxNestingLevel));
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
     * @param string $classname
     * @param string $uuid
     * @throws IOException
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     */
    private function createStub(string $classname, string $uuid): void
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
     * @param object $object
     * @return string
     * @throws DanglingReferenceException
     * @throws GenerationFailureException
     * @throws IOException
     * @throws MaxNestingLevelExceededException
     * @throws ReflectionException
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     */
    public function createJSONSchema(object $object): string
    {
        $json = json_encode($this->processObjectForStorage($object), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (false === $json) {
            throw new SerializationFailureException('Unable create JSON schema');
        }
        return $json;
    }

    /**
     * Serialisiert ein Objekt als Array. Objektreferenzen werden als UUID-Referenz gespeichert,
     * sodass keine Rekursion entsteht. Bereits verarbeitete Objekte werden erkannt und
     * als Referenz markiert (__reference), anstatt erneut serialisiert zu werden.
     *
     * @param object $object
     * @return array
     * @throws DanglingReferenceException
     * @throws GenerationFailureException
     * @throws IOException
     * @throws MaxNestingLevelExceededException
     * @throws ReflectionException
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     */
    private function processObjectForStorage(object $object): array
    {
        $result = [];
        $reflection = new Reflection($object);

        // Deterministische Reihenfolge sicherstellen
        $propertyNames = $reflection->getPropertyNames();
        sort($propertyNames, SORT_STRING);

        foreach ($propertyNames as $propertyName) {
            if (false === $reflection->initialized($propertyName)) {
                continue;
            }

            $value = $reflection->get($propertyName);

            /* skip resources */
            if (is_resource($value)) {
                continue;
            }

            $result[$propertyName] = $this->processValueForStorage($value, [$propertyName], 0);
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
     */
    private function processValueForStorage(mixed $value, array $path, int $level): mixed
    {
        if ($level > $this->maxNestingLevel) {
            throw new MaxNestingLevelExceededException('Maximum nesting level of ' . $this->maxNestingLevel . ' exceeded');
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
                $out[$k] = $this->processValueForStorage($v, array_merge($path, [$k]), $level + 1);
            }
            return $out;
        }

        return $value;
    }

    /**
     * Loads metadata associated with the given unique identifier.
     *
     * @param string $uuid The unique identifier for which the metadata is being loaded.
     * @return array|null Returns the metadata as an associative array if available, or null if an error occurs or metadata is not found.
     */
    public function loadMetadata(string $uuid): ?array
    {
        try {
            return $this->loadFromJsonFile($this->getFilePathMetadata($uuid));
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Get a classname for a certain object
     *
     * @param string $uuid
     * @return string|null
     */
    public function getClassname(string $uuid): ?string
    {
        return $this->loadMetadata($uuid)['className'] ?? null;
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
     * Retrieves a list of uuids for all currently active locks.
     *
     * @return array
     */
    public function getActiveLocks(): array
    {
        return array_keys($this->activeLocks);
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
     * Retrieves the file path for the classnames JSON file.
     *
     * @return string The file path of the classnames JSON file.
     */
    protected function getFilePathClassnames(): string
    {
        return $this->getStubDirectory() . DIRECTORY_SEPARATOR . 'classnames.json';
    }
}
