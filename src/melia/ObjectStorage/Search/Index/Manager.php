<?php

namespace melia\ObjectStorage\Search\Index;

use InvalidArgumentException;
use melia\ObjectStorage\Event\Context\ClassnameChangeContext;
use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\Dispatcher;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Search\Engine\EngineInterface;
use melia\ObjectStorage\Search\Exception\EngineNotFoundException;
use melia\ObjectStorage\Search\Exception\IndexAlreadyExistsException;
use melia\ObjectStorage\Search\Exception\IndexNotFoundException;
use melia\ObjectStorage\Search\Exception\InvalidIndexDefinitionException;
use melia\ObjectStorage\Search\Exception\SearchException;
use Throwable;

/**
 * Manages the search indexes of a storage, kept in <storage dir>/indexes:
 *
 *   internal/            an internal object storage with the definition and the state of every index
 *   <engine>/<name>/     the data of an index, see Index
 *
 * The definitions of all engines are kept together, so index names are unique across engines.
 */
final class Manager implements ManagerInterface
{
    public const string DIRECTORY = 'indexes';

    /** the directory of the internal storage, next to those of the engines: no engine may have this name */
    public const string INTERNAL_DIRECTORY = 'internal';

    /** @var array<string, EngineInterface> */
    private array $engines = [];

    private ?ObjectStorage $internal = null;

    /** @var array<string, Index> */
    private array $indexes = [];

    /** @var array<string, true> names of the indexes with changes recorded by this process */
    private array $pending = [];

    /** @var array<string, callable> */
    private array $listeners = [];

    private bool $autoSync = true;

    /**
     * @param ObjectStorage $storage The storage of the indexed objects.
     * @param EngineInterface[] $engines The available engines; the first one is the default for new indexes.
     */
    public function __construct(
        private readonly ObjectStorage $storage,
        array                          $engines,
    )
    {
        if ([] === $engines) {
            throw new InvalidArgumentException('At least one search engine is needed.');
        }

        foreach ($engines as $engine) {
            if (!$engine instanceof EngineInterface) {
                throw new InvalidArgumentException('Engines must implement ' . EngineInterface::class . '.');
            }
            if (!preg_match('/^[a-z][a-z0-9]{0,31}$/', $engine->getName())) {
                throw new InvalidArgumentException(sprintf('Invalid engine name "%s".', $engine->getName()));
            }
            if (self::INTERNAL_DIRECTORY === $engine->getName()) {
                throw new InvalidArgumentException(sprintf('The engine name "%s" is reserved for the internal storage.', self::INTERNAL_DIRECTORY));
            }
            $this->engines[$engine->getName()] = $engine;
        }
    }

    public function getEngines(): array
    {
        return $this->engines;
    }

    public function getEngine(?string $name = null): EngineInterface
    {
        if (null === $name) {
            return reset($this->engines);
        }

        return $this->engines[$name] ?? throw new EngineNotFoundException(sprintf('Search engine "%s" is not available; available: %s.', $name, implode(', ', array_keys($this->engines))));
    }

    public function getDirectory(): string
    {
        return $this->storage->getStorageDir() . DIRECTORY_SEPARATOR . self::DIRECTORY;
    }

    /**
     * The internal storage of the definitions and states of the indexes.
     *
     * @throws SearchException
     */
    public function getInternalStorage(): ObjectStorage
    {
        if (null === $this->internal) {
            try {
                $meta = new ObjectStorage($this->getDirectory() . DIRECTORY_SEPARATOR . self::INTERNAL_DIRECTORY, logger: $this->storage->getLogger());
            } catch (Throwable $e) {
                throw new SearchException(message: 'Unable to open the storage of the search indexes.', previous: $e);
            }
            // other processes change definitions and states: always read the stored state
            $meta->setCache(null);
            $this->internal = $meta;
        }

        return $this->internal;
    }

    /**
     * @return array<string, Index> name => index, sorted by name
     */
    public function list(): array
    {
        if (!$this->internalExists()) {
            return [];
        }

        $meta = $this->getInternalStorage();
        $indexes = [];

        foreach ($meta->list(Definition::class) as $uuid) {
            try {
                $definition = $meta->load($uuid);
                if ($definition instanceof Definition) {
                    $indexes[$definition->name] = $this->indexFor($definition);
                }
            } catch (Throwable $e) {
                // e.g. an index of an engine not available here
                $this->storage->getLogger()?->log($e);
            }
        }

        ksort($indexes);
        return $indexes;
    }

    public function has(string $name): bool
    {
        return $this->internalExists() && $this->getInternalStorage()->exists(Index::definitionUuid($name));
    }

    public function get(string $name): Index
    {
        if (!$this->has($name)) {
            throw new IndexNotFoundException(sprintf('Search index "%s" doesn\'t exist.', $name));
        }

        if (isset($this->indexes[$name])) {
            return $this->indexes[$name];
        }

        try {
            $definition = $this->getInternalStorage()->load(Index::definitionUuid($name));
        } catch (Throwable $e) {
            throw new SearchException(message: sprintf('Unable to load search index "%s".', $name), previous: $e);
        }

        if (!$definition instanceof Definition) {
            throw new IndexNotFoundException(sprintf('Search index "%s" doesn\'t exist.', $name));
        }

        return $this->indexFor($definition);
    }

    /**
     * @return Index[] The indexes of objects of the class.
     */
    public function getIndexesForClass(string $className): array
    {
        $indexes = [];
        try {
            foreach ($this->list() as $name => $index) {
                if ($index->getDefinition()->className === $className) {
                    $indexes[$name] = $index;
                }
            }
        } catch (Throwable $e) {
            $this->storage->getLogger()?->log($e);
        }
        return $indexes;
    }

    public function create(Definition $definition): Index
    {
        $engine = $this->getEngine($definition->engine);
        $definition = $definition->withEngine($engine->getName());
        $engine->validate($definition);

        $name = $definition->name;
        $meta = $this->getInternalStorage();
        $definitionUuid = Index::definitionUuid($name);
        $lockAdapter = $meta->getLockAdapter();

        // the name is unique: the definition is stored under a UUID derived from it, and checking and creating
        // happen under its lock, so of several processes creating an index of the same name only the first succeeds
        try {
            $lockAdapter->acquireExclusiveLock($definitionUuid);
        } catch (Throwable $e) {
            throw new SearchException(message: sprintf('Unable to create search index "%s".', $name), previous: $e);
        }

        try {
            if ($meta->exists($definitionUuid)) {
                throw new IndexAlreadyExistsException(sprintf('Search index "%s" exists already.', $name));
            }

            $directory = $this->indexDirectory($definition);
            if (!@mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new SearchException(sprintf('Unable to create directory %s.', $directory));
            }

            // the state first: an index is listed by its definition, which needs the state to work with
            $meta->store(new State($name), Index::stateUuid($name));
            $meta->store($definition, $definitionUuid);
        } catch (SearchException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SearchException(message: sprintf('Unable to create search index "%s".', $name), previous: $e);
        } finally {
            if ($lockAdapter->isLockedByThisProcess($definitionUuid)) {
                $lockAdapter->releaseLock($definitionUuid);
            }
        }

        return $this->get($name);
    }

    public function update(Definition $definition): Index
    {
        $index = $this->get($definition->name);
        $engine = $index->getEngineName();

        if (null !== $definition->engine && $definition->engine !== $engine) {
            throw new InvalidIndexDefinitionException(sprintf('The engine of search index "%s" is "%s" and can\'t be changed; drop and create the index instead.', $definition->name, $engine));
        }

        $definition = $definition->withEngine($engine);
        $this->getEngine($engine)->validate($definition);
        $index->writeDefinition($definition);

        return $index;
    }

    public function drop(string $name): void
    {
        $this->get($name)->remove();
        unset($this->indexes[$name], $this->pending[$name]);
    }

    public function sync(bool $wait = true): array
    {
        $result = [];
        foreach ($this->list() as $name => $index) {
            $result[$name] = $index->sync($wait);
        }
        $this->pending = [];
        return $result;
    }

    public function attach(bool $autoSync = true): void
    {
        $this->detach();
        $this->autoSync = $autoSync;

        $dispatcher = $this->storage->getEventDispatcher();
        if (null === $dispatcher) {
            $dispatcher = new Dispatcher();
            $this->storage->setEventDispatcher($dispatcher);
        }

        // data written: METADATA_SAVED is dispatched for every object whose data changed, also for referenced objects
        $this->listeners[Events::METADATA_SAVED] = function (?Context $context): void {
            $uuid = $context?->getUUID();
            $className = null === $uuid ? null : $this->storage->getClassName($uuid);
            if (null !== $className) {
                $this->record($uuid, $this->getIndexesForClass($className));
            }
        };

        // the object is no longer of the class of an index
        $this->listeners[Events::CLASSNAME_CHANGED] = function (?ClassnameChangeContext $context): void {
            if (null !== $context?->getUUID()) {
                $this->record($context->getUUID(), $this->getIndexesForClass($context->getPreviousClassName()));
            }
        };

        // the class of a deleted object can't be read anymore: record it for every index, syncing removes it
        $this->listeners[Events::AFTER_DELETE] = function (?Context $context): void {
            if (null !== $context?->getUUID()) {
                try {
                    $this->record($context->getUUID(), $this->list());
                } catch (Throwable $e) {
                    $this->storage->getLogger()?->log($e);
                }
                $this->autoSync();
            }
        };

        $this->listeners[Events::AFTER_STORE] = function (): void {
            $this->autoSync();
        };

        foreach ($this->listeners as $event => $listener) {
            $dispatcher->addListener($event, $listener);
        }
    }

    public function detach(): void
    {
        $dispatcher = $this->storage->getEventDispatcher();
        foreach ($this->listeners as $event => $listener) {
            $dispatcher?->removeListener($event, $listener);
        }
        $this->listeners = [];
    }

    public function notifyChanged(string $uuid, ?string $className = null, ?array $indexes = null): array
    {
        $className ??= $this->storage->getClassName($uuid);
        $candidates = null === $className ? $this->list() : $this->getIndexesForClass($className);

        if (null !== $indexes) {
            $candidates = array_intersect_key($candidates, array_flip($indexes));
        }

        $this->record($uuid, $candidates);
        $this->autoSync();

        return array_keys($candidates);
    }

    /**
     * The index object of a definition, kept for the lifetime of the manager.
     *
     * @throws EngineNotFoundException
     * @throws SearchException
     */
    private function indexFor(Definition $definition): Index
    {
        return $this->indexes[$definition->name] ??= new Index(
            $this->storage,
            $this->getInternalStorage(),
            $this->indexDirectory($definition),
            $this->getEngine($definition->engine),
        );
    }

    /**
     * @throws EngineNotFoundException
     */
    private function indexDirectory(Definition $definition): string
    {
        $engine = $definition->engine ?? $this->getEngine()->getName();
        return $this->getDirectory() . DIRECTORY_SEPARATOR . $engine . DIRECTORY_SEPARATOR . $definition->name;
    }

    /**
     * Nothing created yet: the internal storage isn't created just by looking.
     */
    private function internalExists(): bool
    {
        return null !== $this->internal || is_dir($this->getDirectory() . DIRECTORY_SEPARATOR . self::INTERNAL_DIRECTORY);
    }

    /**
     * @param Index[] $indexes
     */
    private function record(string $uuid, array $indexes): void
    {
        foreach ($indexes as $name => $index) {
            try {
                $index->recordChange($uuid);
                $this->pending[$name] = true;
            } catch (Throwable $e) {
                $this->storage->getLogger()?->log($e);
            }
        }
    }

    /**
     * Applies the changes recorded by this process; never fails the storage operation.
     */
    private function autoSync(): void
    {
        if (!$this->autoSync || [] === $this->pending) {
            return;
        }

        $pending = $this->pending;
        $this->pending = [];

        foreach (array_keys($pending) as $name) {
            try {
                // don't wait for a build or another sync: the changes stay recorded for the next sync
                ($this->indexes[$name] ?? null)?->sync(wait: false);
            } catch (Throwable $e) {
                $this->storage->getLogger()?->log($e);
            }
        }
    }
}
