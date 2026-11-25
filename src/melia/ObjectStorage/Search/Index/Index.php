<?php

namespace melia\ObjectStorage\Search\Index;

use DateTimeImmutable;
use Generator;
use melia\ObjectStorage\File\Directory;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Search\Engine\EngineInterface;
use melia\ObjectStorage\Search\Exception\IndexBuildInProgressException;
use melia\ObjectStorage\Search\Exception\IndexNotReadyException;
use melia\ObjectStorage\Search\Exception\SearchException;
use melia\ObjectStorage\Search\Exception\UnknownPathException;
use melia\ObjectStorage\Search\Request;
use melia\ObjectStorage\Search\Result;
use Throwable;

/**
 * A search index of one class; the engine only stores and searches its documents.
 *
 * The index is the facade of its components (see "Search indexes" in docs/architecture.md):
 *
 *   IndexRecords      its definition and state in the internal storage of the indexes, and its lock
 *   UuidSets          its sets of UUIDs: pending changes, changed referenced objects, the objects of a build
 *   Generations       the data of the engine, one directory per generation, with the definition it was built with
 *   DocumentIndexer   indexes objects in a generation
 *   Synchronizer      applies the pending changes, removes the documents of expired objects
 *   Builder           builds a new generation step by step
 *   PathResolver      fields named by their paths in a search
 *
 * Syncs and build steps change the state under the lock of the index, so they run one after the other in all
 * processes; searches read the state without waiting for them.
 */
final class Index implements IndexInterface
{
    public const int DEFAULT_BATCH_SIZE = Builder::DEFAULT_BATCH_SIZE;

    /** the sets of UUIDs of an index, see UuidSets */
    public const string TRIE_PENDING = UuidSets::PENDING;
    public const string TRIE_REFERENCED = UuidSets::REFERENCED;
    public const string TRIE_BUILD = UuidSets::BUILD;
    public const string TRIE_REPLAY = UuidSets::REPLAY;

    /** The number of errors kept in the state (State::$errors), the last ones. */
    public const int MAX_ERRORS = State::MAX_ERRORS;

    private readonly string $name;
    private readonly IndexRecords $records;
    private readonly UuidSets $sets;
    private readonly Generations $generations;
    private readonly DocumentIndexer $indexer;
    private readonly Synchronizer $synchronizer;
    private readonly Builder $builder;
    private readonly PathResolver $paths;

    /**
     * @param ObjectStorage $storage The storage of the indexed objects.
     * @param ObjectStorage $meta The internal storage of the definitions, states and sets of UUIDs of the indexes.
     * @param string $directory The directory of the data of the index.
     * @param EngineInterface $engine The engine of the index.
     */
    public function __construct(
        private readonly ObjectStorage   $storage,
        ObjectStorage                    $meta,
        private readonly string          $directory,
        private readonly EngineInterface $engine,
    )
    {
        $this->name = basename($directory);
        $this->records = new IndexRecords($meta, $this->name);
        $this->sets = new UuidSets($meta, $this->name);
        $this->generations = new Generations($directory, $this->name, $engine, $storage);
        $this->indexer = new DocumentIndexer($storage, $this->generations);
        $this->synchronizer = new Synchronizer($this->sets, $this->generations, $this->indexer, $storage);
        $this->builder = new Builder($storage, $engine, $this->sets, $this->generations, $this->indexer, $this->synchronizer, $this->name);
        $this->paths = new PathResolver($engine, $this->name);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEngineName(): string
    {
        return $this->engine->getName();
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * The UUID of the definition of an index in the internal storage, derived from its name.
     */
    public static function definitionUuid(string $name): string
    {
        return IndexRecords::definitionUuid($name);
    }

    /**
     * The UUID of the state of an index in the internal storage, derived from its name.
     */
    public static function stateUuid(string $name): string
    {
        return IndexRecords::stateUuid($name);
    }

    /**
     * The UUID locked in the internal storage while a sync or build step of an index runs, derived from its name.
     */
    public static function lockUuid(string $name): string
    {
        return IndexRecords::lockUuid($name);
    }

    /**
     * The UUID of a set of UUIDs of an index in the internal storage, derived from its name.
     *
     * @param string $kind One of the TRIE_ constants.
     */
    public static function trieUuid(string $name, string $kind): string
    {
        return UuidSets::uuid($name, $kind);
    }

    public function getDefinition(): Definition
    {
        return $this->records->definition();
    }

    public function getActiveDefinition(): ?Definition
    {
        $generation = $this->getState()->generation;
        return null === $generation ? null : $this->generations->definition($generation);
    }

    public function getState(): State
    {
        return $this->records->state();
    }

    public function getStatus(): string
    {
        return $this->statusOf($this->getState());
    }

    public function isSearchable(): bool
    {
        return null !== $this->getState()->generation;
    }

    public function countDocuments(): ?int
    {
        return $this->countDocumentsOf($this->getState());
    }

    public function getSize(): int
    {
        return Directory::size($this->getDirectory());
    }

    public function countPendingChanges(): int
    {
        return $this->synchronizer->countPending();
    }

    public function hasPendingChange(string $uuid): bool
    {
        return $this->sets->read(UuidSets::PENDING)->contains($uuid);
    }

    public function listPendingChanges(int $limit): array
    {
        return array_values(array_map('strval', $this->sets->read(UuidSets::PENDING)->first(max(0, $limit))));
    }

    public function countPendingReferenceChanges(): int
    {
        return $this->sets->count(UuidSets::REFERENCED);
    }

    public function recordChange(string ...$uuids): void
    {
        $this->sets->add(UuidSets::PENDING, $uuids);
    }

    /**
     * Records changes of referenced objects: the next sync updates the documents built from them, e.g. the
     * products of a changed category for a field "category.title".
     */
    public function recordReferenceChange(string ...$uuids): void
    {
        $this->sets->add(UuidSets::REFERENCED, $uuids);
    }

    /**
     * The classes of the referenced objects the documents were built from, e.g. the class of the categories for a
     * field "category.title": changes of their objects are recorded with recordReferenceChange().
     *
     * @return string[]
     */
    public function getJoinedClasses(): array
    {
        return $this->getState()->joinedClasses;
    }

    /**
     * @param bool $purgeExpired Whether to remove the expired objects as well, see purgeExpired(); auto sync after
     *                           each store doesn't, to stay fast.
     * @param int|null $maxChanges The number of changes to apply at most, e.g. after a change of an object many
     *                             documents were built from; the others stay pending. Null for all.
     * @return int The number of applied changes and removed expired objects.
     */
    public function sync(bool $wait = true, int $batchSize = 500, bool $purgeExpired = true, ?int $maxChanges = null): int
    {
        return $this->withState(function (State $state) use ($batchSize, $purgeExpired, $maxChanges): int {
            $applied = $this->synchronizer->applyPending($state, $batchSize, $maxChanges);

            $expired = $purgeExpired ? $this->synchronizer->removeExpired($state, $batchSize) : 0;

            $state->lastSyncAt = (new DateTimeImmutable())->format(DATE_ATOM);
            // before the first build there are no documents to update
            if (null !== $state->generation && ($applied > 0 || $expired > 0)) {
                $state->addUpdate(State::UPDATE_SYNC, $applied, $expired, $this->countDocumentsOf($state));
            }

            return $applied + $expired;
        }, $wait) ?? 0;
    }

    public function purgeExpired(bool $wait = true, int $batchSize = 500): int
    {
        return $this->withState(function (State $state) use ($batchSize): int {
            $removed = $this->synchronizer->removeExpired($state, $batchSize);
            if ($removed > 0) {
                $state->addUpdate(State::UPDATE_EXPIRED, 0, $removed, $this->countDocumentsOf($state));
            }
            return $removed;
        }, $wait) ?? 0;
    }

    public function startBuild(): array
    {
        return $this->withState(function (State $state): array {
            $this->builder->start($state, $this->getDefinition());
            return $this->getProgress($state);
        });
    }

    public function buildStep(int $batchSize = self::DEFAULT_BATCH_SIZE): array
    {
        return $this->withState(function (State $state) use ($batchSize): array {
            $this->builder->step($state, $batchSize);
            return $this->getProgress($state);
        });
    }

    public function build(?callable $onProgress = null, int $batchSize = self::DEFAULT_BATCH_SIZE): array
    {
        $progress = null === $this->getState()->build ? $this->startBuild() : $this->getProgress();
        $onProgress && $onProgress($progress);

        while (!$progress['done']) {
            $progress = $this->buildStep($batchSize);
            $onProgress && $onProgress($progress);
        }

        return $progress;
    }

    public function cancelBuild(): void
    {
        $this->withState(function (State $state): void {
            $this->builder->cancel($state);
        });
    }

    /**
     * @param State|null $state The state to report, the stored one by default.
     */
    public function getProgress(?State $state = null): array
    {
        $state ??= $this->getState();
        $build = $state->build;

        if (null === $build) {
            $documents = $this->countDocumentsOf($state);
            return [
                'status' => $this->statusOf($state),
                'total' => $documents ?? 0,
                'processed' => $documents ?? 0,
                'failed' => 0,
                'percent' => 100,
                'done' => true,
                'errors' => $state->errors,
                'documents' => $documents,
            ];
        }

        return [
            'status' => self::STATUS_BUILDING,
            'total' => $build['total'],
            'processed' => $build['processed'],
            'failed' => $build['failed'],
            'percent' => $build['total'] > 0 ? (int)floor($build['processed'] * 100 / $build['total']) : 0,
            'done' => false,
            'errors' => $state->errors,
            'documents' => null,
        ];
    }

    public function search(object $request): Result
    {
        $generation = $this->getState()->generation;
        if (null === $generation) {
            throw new IndexNotReadyException(sprintf('Search index "%s" has not been built yet.', $this->getName()));
        }

        if ($request instanceof Request) {
            $request = $this->paths->resolve($request, $this->generations->definition($generation));
        }

        return $this->generations->open($generation)->search($request);
    }

    /**
     * Fields named by their paths, see PathResolver.
     *
     * @param Definition|null $definition The definition searched; the one of the last build by default.
     * @return Request The request with field names.
     * @throws UnknownPathException If a path isn't the path of a field.
     * @throws SearchException If the filter isn't valid.
     */
    public function resolvePaths(Request $request, ?Definition $definition = null): Request
    {
        return $this->paths->resolve($request, $definition ?? $this->getActiveDefinition() ?? $this->getDefinition());
    }

    public function searchObjects(object $request): Generator
    {
        foreach ($this->search($request)->hits as $hit) {
            $object = $this->storage->load($hit->uuid);
            if (null !== $object) {
                yield $hit->uuid => $object;
            }
        }
    }

    /**
     * Replaces the definition; the index is outdated until it is built again.
     *
     * @throws IndexBuildInProgressException
     * @throws SearchException
     * @internal use ManagerInterface::update()
     */
    public function writeDefinition(Definition $definition): void
    {
        $this->withState(function (State $state) use ($definition): void {
            if (null !== $state->build) {
                throw new IndexBuildInProgressException(sprintf('Search index "%s" is being built; cancel the build first.', $this->getName()));
            }
            $this->records->storeDefinition($definition);
        });
    }

    /**
     * Removes the index with all its data.
     *
     * @throws SearchException
     * @internal use ManagerInterface::drop()
     */
    public function remove(): void
    {
        $this->generations->removeAllEngineData();

        try {
            $this->records->remove();
            $this->sets->deleteAll();
        } catch (Throwable $e) {
            throw new SearchException(message: sprintf('Unable to remove search index "%s".', $this->getName()), previous: $e);
        }

        Directory::remove($this->directory);
    }

    public function close(): void
    {
        $this->generations->closeAll();
    }

    /**
     * Runs a change of the state under the lock of the index (see IndexRecords::withState()) and keeps the classes
     * of the referenced objects read meanwhile in the state.
     *
     * @template T
     * @param callable(State): T $callback
     * @return T|null The result of the callback, null if $wait is false and another process holds the lock.
     * @throws SearchException
     */
    private function withState(callable $callback, bool $wait = true): mixed
    {
        return $this->records->withState(function (State $state) use ($callback): mixed {
            $this->indexer->takeJoinedClasses();
            $result = $callback($state);

            // the classes of referenced objects only grow: documents of the active generation may still have been
            // built from objects of a class the current definition doesn't lead to anymore
            if ([] !== ($joined = array_diff($this->indexer->takeJoinedClasses(), $state->joinedClasses))) {
                $state->joinedClasses = [...$state->joinedClasses, ...$joined];
                sort($state->joinedClasses);
            }

            return $result;
        }, $wait);
    }

    private function statusOf(State $state): string
    {
        return match (true) {
            null !== $state->build => self::STATUS_BUILDING,
            null === $state->generation => self::STATUS_NEW,
            !$this->getDefinition()->equals($this->generations->definition($state->generation)) => self::STATUS_OUTDATED,
            default => self::STATUS_READY,
        };
    }

    private function countDocumentsOf(State $state): ?int
    {
        return null === $state->generation ? null : $this->generations->open($state->generation)->count();
    }

    public function __destruct()
    {
        $this->close();
    }
}
