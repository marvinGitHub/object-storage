<?php

namespace melia\ObjectStorage\Search\Index;

use Generator;
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
use melia\ObjectStorage\Search\Exception\NoSuitableIndexException;
use melia\ObjectStorage\Search\Exception\SearchException;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\Request;
use melia\ObjectStorage\Search\Result;
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

    public function explain(string $className, Request $request): Plan
    {
        $usable = [];
        $unusable = [];

        foreach ($this->getIndexesForClass($className) as $name => $index) {
            $candidate = $this->consider($index, $request);
            if ($candidate->isUsable()) {
                $usable[] = $candidate;
            } else {
                $unusable[] = $candidate;
            }
        }

        // like a query planner: indexes searching the current definition first; for full text, the one searching
        // the most fields; then the smallest one; the name keeps the choice stable
        $fullText = '' !== trim($request->query);
        usort($usable, static fn(Candidate $a, Candidate $b): int => [
                IndexInterface::STATUS_READY === $a->status ? 0 : 1,
                $fullText ? -$a->searchableFields : 0,
                $a->fields,
                $a->name,
            ] <=> [
                IndexInterface::STATUS_READY === $b->status ? 0 : 1,
                $fullText ? -$b->searchableFields : 0,
                $b->fields,
                $b->name,
            ]);

        $ranked = [];
        foreach ($usable as $position => $candidate) {
            $ranked[] = $candidate->withRank($position + 1);
        }

        return new Plan($className, [...$ranked, ...$unusable]);
    }

    public function search(string $className, Request $request): Result
    {
        return $this->choose($className, $request)->search($request);
    }

    public function searchObjects(string $className, Request $request): Generator
    {
        return $this->choose($className, $request)->searchObjects($request);
    }

    /**
     * The index that answers a search of a class, see explain().
     *
     * @throws NoSuitableIndexException
     * @throws SearchException
     */
    public function choose(string $className, Request $request): Index
    {
        $plan = $this->explain($className, $request);
        $chosen = $plan->getChosen();

        if (null === $chosen) {
            throw NoSuitableIndexException::forPlan($plan);
        }

        return $this->get($chosen->name);
    }

    /**
     * Checks whether an index can answer a search: the definition it searches (the one of its last build) has to
     * have the fields the search uses.
     */
    private function consider(Index $index, Request $request): Candidate
    {
        $reasons = [];
        $engine = '?';
        $status = '?';
        $searchable = $filterable = $sortable = $fields = [];

        try {
            $engine = $index->getEngineName();
            $status = $index->getStatus();
            $definition = $index->getActiveDefinition();

            if (null === $definition) {
                $reasons[] = 'not built yet';
            } else {
                $fields = $definition->fieldNames(static fn(): bool => true);
                $searchable = $definition->fieldNames(static fn(Field $field): bool => $field->searchable);
                // the UUID and the expiration can always be filtered
                $filterable = ['uuid', 'expiresAt', ...$definition->fieldNames(static fn(Field $field): bool => $field->filterable)];
                $sortable = $definition->fieldNames(static fn(Field $field): bool => $field->sortable);
            }
        } catch (Throwable $e) {
            $reasons[] = 'unavailable: ' . $e->getMessage();
        }

        if ([] === $reasons) {
            if ('' !== trim($request->query)) {
                if ([] === $searchable) {
                    $reasons[] = 'no searchable fields for the query';
                }
                if ([] !== ($missing = array_diff($request->searchOn, $searchable))) {
                    $reasons[] = 'not searchable: ' . implode(', ', $missing);
                }
            }

            if (null !== $request->filter && '' !== trim($request->filter)) {
                try {
                    $used = $this->getEngine($engine)->filterAttributes($request->filter);
                    if ([] !== ($missing = array_diff($used, $filterable))) {
                        $reasons[] = 'not filterable: ' . implode(', ', $missing);
                    }
                } catch (Throwable $e) {
                    // e.g. a filter in the language of another engine
                    $reasons[] = 'filter not understood: ' . $e->getMessage();
                }
            }

            // names starting with "_" are special sorts of the engine, e.g. Loupe's "_relevance"
            $sortFields = array_filter(
                array_map(static fn(string $criterion): string => explode(':', $criterion)[0], $request->sort),
                static fn(string $field): bool => !str_starts_with($field, '_')
            );
            if ([] !== ($missing = array_diff($sortFields, $sortable))) {
                $reasons[] = 'not sortable: ' . implode(', ', $missing);
            }

            if ([] !== ($missing = array_diff($request->facets, $filterable))) {
                $reasons[] = 'no facets for: ' . implode(', ', $missing);
            }
        }

        return new Candidate($index->getName(), $engine, $status, array_values($reasons), null, count($searchable), count($fields));
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

        // a lifetime set to the past removes the object from the indexes, a longer one adds it again
        $this->listeners[Events::LIFETIME_CHANGED] = function (?Context $context): void {
            $uuid = $context?->getUUID();
            $className = null === $uuid ? null : $this->storage->getClassName($uuid);
            if (null !== $className) {
                $this->record($uuid, $this->getIndexesForClass($className));
                $this->autoSync();
            }
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
                ($this->indexes[$name] ?? null)?->sync(wait: false, purgeExpired: false);
            } catch (Throwable $e) {
                $this->storage->getLogger()?->log($e);
            }
        }
    }
}
