<?php

namespace melia\ObjectStorage;

use melia\ObjectStorage\Cache\InMemoryCache;
use melia\ObjectStorage\Configuration\Configuration;
use melia\ObjectStorage\Configuration\Exception\ConfigurationException;
use melia\ObjectStorage\Configuration\Factory as ConfigurationFactory;
use melia\ObjectStorage\Constraint\ConstraintsTrait;
use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\Dispatcher;
use melia\ObjectStorage\Event\DispatcherAwareTrait;
use melia\ObjectStorage\Event\DispatcherInterface;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\File\IO\AdapterAwareTrait;
use melia\ObjectStorage\File\IO\AdapterInterface;
use melia\ObjectStorage\File\IO\RealAdapter;
use melia\ObjectStorage\File\Reader;
use melia\ObjectStorage\File\ReaderAwareTrait;
use melia\ObjectStorage\File\ReaderInterface;
use melia\ObjectStorage\File\Writer;
use melia\ObjectStorage\File\WriterAwareTrait;
use melia\ObjectStorage\File\WriterInterface;
use melia\ObjectStorage\Locking\Backends\SymfonyLockAdapter;
use melia\ObjectStorage\Locking\LockAdapterInterface;
use melia\ObjectStorage\Locking\RetainedLocksTrait;
use melia\ObjectStorage\Logger\LoggerInterface;
use melia\ObjectStorage\Metadata\Cache\AwareTrait as MetadataCacheAwareTrait;
use melia\ObjectStorage\Runtime\ClassRenameMap;
use melia\ObjectStorage\Runtime\ClassRenameMapAwareTrait;
use melia\ObjectStorage\State\StateHandler;
use melia\ObjectStorage\Storage\DeletingTrait;
use melia\ObjectStorage\Storage\DurabilityTrait;
use melia\ObjectStorage\Storage\LifetimeTrait;
use melia\ObjectStorage\Storage\ListingTrait;
use melia\ObjectStorage\Storage\LoadingTrait;
use melia\ObjectStorage\Storage\MetadataTrait;
use melia\ObjectStorage\Storage\PathsTrait;
use melia\ObjectStorage\Storage\StorageAbstract;
use melia\ObjectStorage\Storage\StorageMemoryConsumptionInterface;
use melia\ObjectStorage\Storage\StoringTrait;
use melia\ObjectStorage\Storage\StubsTrait;
use melia\ObjectStorage\Strategy\Standard;
use melia\ObjectStorage\Strategy\StrategyInterface;
use melia\ObjectStorage\Transfer\RawAccessTrait;
use melia\ObjectStorage\UUID\IdentityTrait;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Throwable;
use WeakMap;
use WeakReference;

/**
 * Stores, loads and deletes objects as files: one data file (the serialized object graph) and one metadata file
 * (class, checksum, revision, expiration) per object, sharded by UUID, with locks, caches and events.
 *
 * The class holds the construction, the configuration and the caches; the rest is grouped by concern in traits of
 * the namespace Storage (each kept apart for readability, together one class):
 *
 * - PathsTrait: the paths of the files, sharded by UUID
 * - StoringTrait, LoadingTrait, DeletingTrait: objects, with caches, locks and events
 * - DurabilityTrait: the files of objects as stored (both files as a group, checksums, interrupted writes)
 * - MetadataTrait, LifetimeTrait: metadata, expiration and touching
 * - ListingTrait, StubsTrait: existence, listing, the index of objects by class and the registry of class names
 * - IdentityTrait (UUID), RawAccessTrait (Transfer), RetainedLocksTrait (Locking), ConstraintsTrait (Constraint)
 *
 * The verbs have one meaning each:
 *
 * - load / store / delete: objects, with caches, locks and events — the public API
 *     load()   → loadFromFiles() → readObjectFiles() → hydrateObject() → hydrateProperties() / hydrateArray()
 *     store()  → storeObject() (per object of the graph) → serializeGraph() → writeObjectFiles() / writeMetadata()
 *     delete() → deleteObject() (per object, following the child deletion policy)
 * - readRaw / writeRaw: objects as stored, unchanged, e.g. to copy them (Transfer\RawAccessTrait)
 * - read… / write…: files, without caches: readObjectFiles() and writeObjectFiles() are shared by load() and
 *   readRaw(), store() and writeRaw(); they verify checksums and complete interrupted writes
 * - hydrate… / serialize…: converting between stored data and objects
 * - loadMetadata() reads metadata through its cache, reloadMetadata() from the file
 *
 * See docs/internals.md for the details.
 */
class ObjectStorage extends StorageAbstract implements StorageMemoryConsumptionInterface
{
    use AdapterAwareTrait;

    use WriterAwareTrait;

    use ReaderAwareTrait;

    use DispatcherAwareTrait;

    use Cache\AwareTrait;

    use MetadataCacheAwareTrait;

    use ClassRenameMapAwareTrait;

    // the storage itself, by concern
    use PathsTrait; // getFilePathData(), getFilePathMetadata(), getFilePathStub(): the files, sharded by UUID
    use StoringTrait; // store(): objects and the objects they reference
    use LoadingTrait; // load(): objects, references loaded lazily
    use DurabilityTrait; // readObjectFiles(), writeObjectFiles(): data and metadata as a group, checksums, recovery
    use MetadataTrait; // loadMetadata(), writeMetadata(), getRevision(), getClassName()
    use LifetimeTrait; // setLifetime(), setExpiration(), expired(), touch()
    use DeletingTrait; // delete(), deleteIfExpired(): following the child deletion policy
    use ListingTrait; // exists(), list(): by the stubs or the files
    use StubsTrait; // createStub(), the registry of class names, rebuildStubs(), markAsCorrupted()

    use IdentityTrait; // getUuidOf(): the UUID store() uses for an instance
    use RawAccessTrait; // readRaw(), writeRaw(): objects as stored, e.g. to copy them into another storage
    use RetainedLocksTrait; // retainLock(): exclusive locks kept across store()/delete(), for transactions
    use ConstraintsTrait; // addConstraint(), checkConstraints(): rules written objects have to satisfy, e.g. unique values

    /**
     * The writer and reader created by default, which follow the IO adapter of the storage (see setIOAdapter()).
     */
    private ?Writer $defaultWriter = null;

    private ?Reader $defaultReader = null;

    /**
     * The default checksum algorithm used for checksum validation.
     *
     * @var string
     */
    public const string CHECKSUM_ALGORITHM_DEFAULT = 'crc32b';

    /**
     * The suffix used for metadata files.
     *
     * @var string
     */
    protected const string FILE_SUFFIX_METADATA = '.metadata';

    /**
     * The lock resource guarding the registry of class names (classnames.json).
     */
    protected const string LOCK_CLASSNAMES = 'classnames';

    /**
     * The suffix used for stub files.
     *
     * @var string
     */
    protected const string FILE_SUFFIX_STUB = '.stub';

    /**
     * The suffix used for object files.
     *
     * @var string
     */
    protected const string FILE_SUFFIX_OBJECT = '.obj';

    /**
     * The revision each object was loaded or stored with by this instance, for optimistic locking.
     *
     * @var WeakMap<object, int>
     */
    protected WeakMap $revisions;

    /**
     * The transaction running on this storage instance: every change of object files through this instance is
     * recorded in it before it is made (writeObjectFiles(), writeMetadata(), deleteObject()), see
     * docs/transactions.md. One at a time; another instance can run another transaction. Held weakly, so a
     * transaction that is dropped is destructed (and rolled back) as before.
     *
     * @var WeakReference<Transaction>|null
     */
    private ?WeakReference $activeTransaction = null;

    // ---------------------------------------------------------------------------------------------------------
    // Creating and configuring the storage
    // ---------------------------------------------------------------------------------------------------------

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
     * @param CacheInterface|null $metadataCache
     * @param AdapterInterface|null $adapter
     * @param StrategyInterface|null $strategy
     * @param Configuration|null $configuration Settings to create the lock adapter and the caches from, and to
     *        apply to the strategy and the writer; arguments passed explicitly take precedence. See Registry for
     *        named configurations.
     * @throws IOException If the storage directory cannot be created.
     * @throws ConfigurationException If the lock adapter or a cache of the configuration can't be created.
     */
    public function __construct(
        private string                  $storageDir,
        protected ?LoggerInterface      $logger = null,
        protected ?LockAdapterInterface $lockAdapter = null,
        protected ?StateHandler         $stateHandler = null,
        ?DispatcherInterface            $eventDispatcher = null,
        ?CacheInterface                 $cache = null,
        ?CacheInterface                 $metadataCache = null,
        ?AdapterInterface               $adapter = null,
        ?StrategyInterface              $strategy = null,
        private ?Configuration          $configuration = null,
    )
    {
        $factory = null === $configuration ? null : new ConfigurationFactory();
        $strategyPassed = null !== $strategy;
        $this->identityMap = new WeakMap();
        $this->processingStack = new WeakMap();
        $this->revisions = new WeakMap();

        $this->setStorageDir($storageDir);
        $this->setClassRenameMap(new ClassRenameMap());

        if (null === $adapter) {
            $adapter = new RealAdapter();
        }
        $this->setIOAdapter($adapter);

        if (null === $strategy) {
            $strategy = new Standard();
        }
        $this->setStrategy($strategy);

        if (null === $cache) {
            $cache = null === $configuration ? new InMemoryCache(maxEntries: InMemoryCache::DEFAULT_MAX_ENTRIES) : $factory->createCache($configuration->cache, $this->getStorageDir(), ConfigurationFactory::CACHE_OBJECTS);
        }
        $this->setCache($cache);

        if (null === $metadataCache) {
            $metadataCache = null === $configuration ? new InMemoryCache(maxEntries: InMemoryCache::DEFAULT_MAX_ENTRIES) : $factory->createCache($configuration->cache, $this->getStorageDir(), ConfigurationFactory::CACHE_METADATA);
        }
        $this->setMetadataCache($metadataCache);

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
            $lockAdapter = null === $configuration
                ? SymfonyLockAdapter::createFileLockAdapter($this->getLockDir())
                : $factory->createLockAdapter($configuration->lock, $this->getStorageDir());
            $lockAdapter->setStateHandler($stateHandler);
            $lockAdapter->setLogger($this->logger);
            $lockAdapter->setEventDispatcher($eventDispatcher);
        }
        $this->setLockAdapter($lockAdapter);

        // a strategy passed explicitly keeps its settings
        $factory?->apply($this, $configuration, applyStrategy: !$strategyPassed);

        // transactions interrupted by a crash are rolled back; without any, this is a single directory lookup
        TransactionRecovery::recoverAll($this);
    }

    /**
     * The transaction running on this storage instance, null if none.
     */
    public function getActiveTransaction(): ?Transaction
    {
        return $this->activeTransaction?->get();
    }

    /**
     * Sets the transaction running on this storage instance (Transaction::begin()), or null when it ends.
     *
     * @internal Not part of the public API; may change in any release.
     */
    public function setActiveTransaction(?Transaction $transaction): void
    {
        $this->activeTransaction = null === $transaction ? null : WeakReference::create($transaction);
    }

    /**
     * The configuration the storage was created with, null if none.
     */
    public function getConfiguration(): ?Configuration
    {
        return $this->configuration;
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
     * Retrieves the directory of the storage.
     *
     * @return string The storage directory path as a string.
     */
    public function getStorageDir(): string
    {
        return $this->storageDir;
    }

    /**
     * Sets the IO adapter of the storage, which the default writer and reader use as well. A writer or reader set
     * explicitly keeps its own adapter.
     *
     * @param AdapterInterface|null $ioAdapter
     * @return void
     */
    public function setIOAdapter(?AdapterInterface $ioAdapter): void
    {
        $this->ioAdapter = $ioAdapter;

        if (null !== $this->defaultWriter && $this->writer === $this->defaultWriter) {
            $this->defaultWriter->setIOAdapter($this->getIOAdapter());
        }

        if (null !== $this->defaultReader && $this->reader === $this->defaultReader) {
            $this->defaultReader->setIOAdapter($this->getIOAdapter());
        }
    }

    /**
     * Returns the writer; unless one was set, a default writer is created once, using the IO adapter of the storage.
     *
     * @return WriterInterface
     */
    public function getWriter(): WriterInterface
    {
        if (null === $this->writer) {
            $this->defaultWriter = new Writer();
            $this->defaultWriter->setIOAdapter($this->getIOAdapter());
            $this->writer = $this->defaultWriter;
        }

        return $this->writer;
    }

    /**
     * Returns the reader; unless one was set, a default reader is created once, using the IO adapter of the storage.
     *
     * @return ReaderInterface
     */
    public function getReader(): ReaderInterface
    {
        if (null === $this->reader) {
            $this->defaultReader = new Reader();
            $this->defaultReader->setIOAdapter($this->getIOAdapter());
            $this->reader = $this->defaultReader;
        }

        return $this->reader;
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

    // ---------------------------------------------------------------------------------------------------------
    // Cache
    // ---------------------------------------------------------------------------------------------------------

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
     */
    protected function addToCache(string $uuid, object $object, null|int|float $ttl = null): void
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
                $this->getEventDispatcher()?->dispatch(Events::CACHE_ENTRY_ADDED, static fn() => new Context($uuid));
            }
        } catch (Throwable $e) {
            $this->getLogger()?->log($e);
            $this->getEventDispatcher()?->dispatch(Events::CACHE_WRITE_FAILED, static fn() => new Context($uuid));
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public function removeFromCache(string $uuid): void
    {
        $this->getCache()?->delete($uuid);
        $this->getEventDispatcher()?->dispatch(Events::CACHE_ENTRY_REMOVED, static fn() => new Context($uuid));
    }

    /**
     * Clears the internal object cache by resetting it to an empty array.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->getCache()?->clear();
        $this->identityMap = new WeakMap();
        $this->processingStack = new WeakMap();
        $this->revisions = new WeakMap();
        $this->registeredClassNamesCache = null;
        $this->getMetadataCache()?->clear();
        $this->getEventDispatcher()?->dispatch(Events::CACHE_CLEARED);
    }
}