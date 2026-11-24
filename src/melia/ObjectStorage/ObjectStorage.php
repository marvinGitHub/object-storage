<?php

namespace melia\ObjectStorage;

use Closure;
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
use melia\ObjectStorage\File\Files;
use melia\ObjectStorage\File\FilesAwareTrait;
use melia\ObjectStorage\File\FilesInterface;
use melia\ObjectStorage\File\IO\AdapterAwareTrait;
use melia\ObjectStorage\File\IO\AdapterInterface;
use melia\ObjectStorage\File\IO\RealAdapter;
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
use melia\ObjectStorage\Storage\JsonTrait;
use melia\ObjectStorage\Storage\LifetimeTrait;
use melia\ObjectStorage\Storage\ListingTrait;
use melia\ObjectStorage\Storage\LoadingTrait;
use melia\ObjectStorage\Storage\MetadataTrait;
use melia\ObjectStorage\Storage\ObjectCache;
use melia\ObjectStorage\Storage\ObjectPaths;
use melia\ObjectStorage\Storage\ObjectSession;
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
use WeakReference;

/**
 * Stores, loads and deletes objects as files: one data file (the serialized object graph) and one metadata file
 * (class, checksum, revision, expiration) per object, sharded by UUID, with locks, caches and events.
 *
 * The class holds the construction, the configuration and the caches; the rest is grouped by concern in traits of
 * the namespace Storage (each kept apart for readability, together one class):
 *
 * - PathsTrait: the paths of the files, sharded by UUID, delegated to the component ObjectPaths
 *
 * The traits are being replaced by components with dependencies of their own, see docs/architecture.md.
 * - StoringTrait, LoadingTrait, DeletingTrait: objects, with caches, locks and events
 * - DurabilityTrait: the files of objects as stored (both files as a group, checksums, interrupted writes)
 * - JsonTrait: readJson() and writeJson(), the JSON files of the storage
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
 *   readRaw(), store() and writeRaw(); they verify checksums and complete interrupted writes. Files are read and
 *   written in layers: getFiles() (bytes, atomic, fsync) → readJson()/writeJson() (JSON) → the object files
 * - hydrate… / serialize…: converting between stored data and objects
 * - loadMetadata() reads metadata through its cache, reloadMetadata() from the file
 *
 * See docs/internals.md for the details.
 */
class ObjectStorage extends StorageAbstract implements StorageMemoryConsumptionInterface
{
    use AdapterAwareTrait;

    use FilesAwareTrait;

    use DispatcherAwareTrait;

    use Cache\AwareTrait;

    use MetadataCacheAwareTrait;

    use ClassRenameMapAwareTrait;

    // the storage itself, by concern
    use PathsTrait; // getFilePathData(), getFilePathMetadata(), getFilePathStub(): the files, sharded by UUID
    use StoringTrait; // store(): objects and the objects they reference
    use LoadingTrait; // load(): objects, references loaded lazily
    use DurabilityTrait; // readObjectFiles(), writeObjectFiles(): data and metadata as a group, checksums, recovery
    use JsonTrait; // readJson(), writeJson(): the JSON files of the storage (metadata, registry of class names)
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
     * The files created by default, which follow the IO adapter of the storage (see setIOAdapter()).
     */
    private ?Files $defaultFiles = null;

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
    protected const string FILE_SUFFIX_METADATA = ObjectPaths::SUFFIX_METADATA;

    /**
     * The lock resource guarding the registry of class names (classnames.json).
     */
    protected const string LOCK_CLASSNAMES = 'classnames';

    /**
     * The suffix used for stub files.
     *
     * @var string
     */
    protected const string FILE_SUFFIX_STUB = ObjectPaths::SUFFIX_STUB;

    /**
     * The suffix used for object files.
     *
     * @var string
     */
    protected const string FILE_SUFFIX_OBJECT = ObjectPaths::SUFFIX_OBJECT;

    /**
     * What this instance knows of the objects it loaded and stored: their UUIDs, their revisions (for optimistic
     * locking), the objects being stored.
     */
    private ObjectSession $session;

    /**
     * The transaction running on this storage instance: every change of object files through this instance is
     * recorded in it before it is made (writeObjectFiles(), writeMetadata(), deleteObject()), see
     * docs/transactions.md. One at a time; another instance can run another transaction. Held weakly, so a
     * transaction that is dropped is destructed (and rolled back) as before.
     *
     * @var WeakReference<Transaction>|null
     */
    private ?WeakReference $activeTransaction = null;

    /**
     * The components of the storage, by name, with the key of the inputs they were created from (see component()).
     *
     * @var array<string, array{key: string, component: object}>
     */
    private array $components = [];

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
     *        apply to the strategy and the files; arguments passed explicitly take precedence. See Registry for
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
        $this->session = new ObjectSession();

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
     * A component of the storage (see docs/architecture.md), created when first needed and created again when one
     * of its inputs has changed, e.g. by a setter of the storage: components don't follow changes themselves.
     *
     * @template T of object
     * @param list<mixed> $inputs What the component is created from: objects compared by identity, other values by
     *        value. Only what the component holds: an object it doesn't keep could be freed and its id reused.
     * @param Closure(): T $create
     * @return T
     */
    private function component(string $name, array $inputs, Closure $create): object
    {
        $key = serialize(array_map(static fn(mixed $input): mixed => is_object($input) ? '#' . spl_object_id($input) : $input, $inputs));
        if (($this->components[$name]['key'] ?? null) !== $key) {
            $this->components[$name] = ['key' => $key, 'component' => $create()];
        }

        return $this->components[$name]['component'];
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
     * Sets the IO adapter of the storage, which the default files use as well. Files set explicitly keep their own
     * adapter.
     *
     * @param AdapterInterface|null $ioAdapter
     * @return void
     */
    public function setIOAdapter(?AdapterInterface $ioAdapter): void
    {
        $this->ioAdapter = $ioAdapter;

        if (null !== $this->defaultFiles && $this->files === $this->defaultFiles) {
            $this->defaultFiles->setIOAdapter($this->getIOAdapter());
        }
    }

    /**
     * The files of the storage, which reads and writes them through it (see FilesInterface); unless set, default
     * files are created once, using the IO adapter of the storage. E.g. `getFiles()->setSyncEnabled(false)` where
     * durability doesn't matter.
     */
    public function getFiles(): FilesInterface
    {
        if (null === $this->files) {
            $this->defaultFiles = new Files();
            $this->defaultFiles->setIOAdapter($this->getIOAdapter());
            $this->files = $this->defaultFiles;
        }

        return $this->files;
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
     * The cache of loaded objects, with the cache, event dispatcher and logger of the storage as they are now.
     */
    protected function objectCache(): ObjectCache
    {
        $cache = $this->getCache();
        $dispatcher = $this->getEventDispatcher();
        $logger = $this->getLogger();

        return $this->component(
            ObjectCache::class,
            [$cache, $dispatcher, $logger],
            static fn(): ObjectCache => new ObjectCache($cache, $dispatcher, $logger),
        );
    }

    /**
     * Caches an object for its lifetime, see ObjectCache::add(): without expiration if null, removed if it has
     * expired (0 or less). A failing cache is logged, not thrown.
     *
     * @param int|float|null $ttl The lifetime in seconds.
     */
    protected function addToCache(string $uuid, object $object, null|int|float $ttl = null): void
    {
        $this->objectCache()->add($uuid, $object, $ttl);
    }

    /**
     * Removes an object from the cache, see ObjectCache::remove().
     *
     * @throws InvalidArgumentException
     */
    public function removeFromCache(string $uuid): void
    {
        $this->objectCache()->remove($uuid);
    }

    /**
     * Clears the internal object cache by resetting it to an empty array.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->objectCache()->clear();
        $this->session->clear();
        $this->classNameRegistry()->forget();
        $this->getMetadataCache()?->clear();
        $this->getEventDispatcher()?->dispatch(Events::CACHE_CLEARED);
    }
}