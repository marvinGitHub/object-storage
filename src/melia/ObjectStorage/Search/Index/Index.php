<?php

namespace melia\ObjectStorage\Search\Index;

use DateTimeImmutable;
use Generator;
use JsonException;
use melia\ObjectStorage\Exception\LockException;
use melia\ObjectStorage\File\Directory;
use melia\ObjectStorage\Locking\LockAdapterInterface;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Search\DocumentBuilder;
use melia\ObjectStorage\Search\Engine\EngineIndexInterface;
use melia\ObjectStorage\Search\Engine\EngineInterface;
use melia\ObjectStorage\Search\Exception\IndexBuildInProgressException;
use melia\ObjectStorage\Search\Exception\IndexNotReadyException;
use melia\ObjectStorage\Search\Exception\SearchException;
use melia\ObjectStorage\Search\Request;
use melia\ObjectStorage\Search\Result;
use melia\ObjectStorage\UUID\Helper;
use melia\ObjectStorage\Util\UuidTrie;
use Throwable;

/**
 * A search index of one class; the engine only stores and searches its documents.
 *
 * Its definition, its state and three sets of UUIDs are objects in the internal storage of the indexes
 * (see Manager), each one record; the sets are UuidTries:
 *
 *   pending       the objects changed since the last sync
 *   build         the objects to index while a build is running
 *   replay        the changes applied during a build, applied to the new generation at its end
 *
 * The directory of the index holds the data of the engine, one directory per generation (data-<n>/, with the
 * definition it was built with). Building writes a new generation, so searches keep using the previous one until
 * the build is complete.
 */
final class Index implements IndexInterface
{
    public const int DEFAULT_BATCH_SIZE = 200;

    /** the sets of UUIDs of an index */
    public const string TRIE_PENDING = 'pending';
    public const string TRIE_BUILD = 'build';
    public const string TRIE_REPLAY = 'replay';

    private const string DIR_GENERATION_PREFIX = 'data-';
    private const string FILE_DEFINITION = 'definition.json';
    private const int MAX_ERRORS = 20;
    private const int SNAPSHOT_CHUNK = 10000;

    /** @var array<int, EngineIndexInterface> open generations */
    private array $handles = [];

    /**
     * @param ObjectStorage $storage The storage of the indexed objects.
     * @param ObjectStorage $meta The internal storage of the definitions, states and sets of UUIDs of the indexes.
     * @param string $directory The directory of the data of the index.
     * @param EngineInterface $engine The engine of the index.
     */
    public function __construct(
        private readonly ObjectStorage   $storage,
        private readonly ObjectStorage   $meta,
        private readonly string          $directory,
        private readonly EngineInterface $engine,
    )
    {
    }

    public function getName(): string
    {
        return basename($this->directory);
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
        return static::uuidFor('definition', $name);
    }

    /**
     * The UUID of the state of an index in the internal storage, derived from its name.
     */
    public static function stateUuid(string $name): string
    {
        return static::uuidFor('state', $name);
    }

    /**
     * The UUID locked in the internal storage while a sync or build step of an index runs, derived from its name.
     */
    public static function lockUuid(string $name): string
    {
        return static::uuidFor('lock', $name);
    }

    public function getDefinition(): Definition
    {
        try {
            $definition = $this->meta->load(static::definitionUuid($this->getName()));
        } catch (Throwable $e) {
            throw new SearchException(message: sprintf('Unable to load the definition of search index "%s".', $this->getName()), previous: $e);
        }

        if (!$definition instanceof Definition) {
            throw new SearchException(sprintf('The definition of search index "%s" is missing.', $this->getName()));
        }

        return $definition;
    }

    public function getActiveDefinition(): ?Definition
    {
        $generation = $this->getState()->generation;
        return null === $generation ? null : $this->getGenerationDefinition($generation);
    }

    public function getState(): State
    {
        try {
            $state = $this->meta->load(static::stateUuid($this->getName()));
        } catch (Throwable $e) {
            throw new SearchException(message: sprintf('Unable to load the state of search index "%s".', $this->getName()), previous: $e);
        }

        return $state instanceof State ? $state : new State($this->getName());
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
        return $this->readTrie(self::TRIE_PENDING)->count();
    }

    public function hasPendingChange(string $uuid): bool
    {
        return $this->readTrie(self::TRIE_PENDING)->contains($uuid);
    }

    public function recordChange(string ...$uuids): void
    {
        $this->changeTrie(self::TRIE_PENDING, static fn(UuidTrie $trie): int => $trie->add(...$uuids));
    }

    /**
     * @param bool $purgeExpired Whether to remove the expired objects as well, see purgeExpired(); auto sync after
     *                           each store doesn't, to stay fast.
     * @return int The number of applied changes and removed expired objects.
     */
    public function sync(bool $wait = true, int $batchSize = 500, bool $purgeExpired = true): int
    {
        return $this->withState(function (State $state) use ($batchSize, $purgeExpired): int {
            $applied = 0;

            // taken out before they are applied: a change recorded meanwhile is recorded again and not lost
            while ([] !== ($uuids = $this->changeTrie(self::TRIE_PENDING, static function (UuidTrie $trie) use ($batchSize): array {
                    $uuids = $trie->first($batchSize);
                    $trie->remove(...$uuids);
                    return $uuids;
                }))) {
                try {
                    if (null !== $state->generation) {
                        $state->errors = $this->applyChanges($state->generation, $uuids, $state->errors)['errors'];
                    }
                    // a running build has read some of these objects before: they are applied to it at its end
                    if (null !== $state->build) {
                        $this->changeTrie(self::TRIE_REPLAY, static fn(UuidTrie $trie): int => $trie->add(...$uuids));
                    }
                } catch (Throwable $e) {
                    $this->recordChange(...$uuids);
                    throw $e;
                }

                $applied += count($uuids);
            }

            $expired = $purgeExpired ? $this->removeExpired($state, $batchSize) : 0;

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
            $removed = $this->removeExpired($state, $batchSize);
            if ($removed > 0) {
                $state->addUpdate(State::UPDATE_EXPIRED, 0, $removed, $this->countDocumentsOf($state));
            }
            return $removed;
        }, $wait) ?? 0;
    }

    /**
     * Removes the documents of expired objects: the engine finds the documents whose expiration has passed, and
     * their current state is applied. Expired (or deleted) objects are removed; objects whose lifetime was
     * extended meanwhile are updated instead.
     *
     * @return int The number of removed documents.
     */
    private function removeExpired(State $state, int $batchSize): int
    {
        if (null === $state->generation) {
            return 0;
        }

        $handle = $this->openGeneration($state->generation);
        $removed = 0;
        $seen = [];

        // each round applies the current state, so a document isn't found again; $seen guards against engines
        // reporting a document twice
        for ($round = 0; $round < 10000; $round++) {
            try {
                $uuids = array_values(array_diff($handle->expired(microtime(true), max(1, $batchSize)), array_keys($seen)));
            } catch (Throwable $e) {
                // e.g. a generation built before the expiration was indexed: rebuilding it helps
                $this->storage->getLogger()?->log($e);
                $state->errors = array_slice([...$state->errors, 'Expired objects: ' . $e->getMessage()], -self::MAX_ERRORS);
                break;
            }

            if ([] === $uuids) {
                break;
            }

            foreach ($uuids as $uuid) {
                $seen[$uuid] = true;
            }

            $before = $handle->count();
            $state->errors = $this->applyChanges($state->generation, $uuids, $state->errors)['errors'];
            $removed += max(0, $before - $handle->count());

            // a running build may have read these objects before they expired: they are applied to it at its end
            if (null !== $state->build) {
                $this->changeTrie(self::TRIE_REPLAY, static fn(UuidTrie $trie): int => $trie->add(...$uuids));
            }
        }

        $state->lastExpiredRemoved = $removed;

        return $removed;
    }

    public function startBuild(): array
    {
        return $this->withState(function (State $state): array {
            if (null !== $state->build) {
                throw new IndexBuildInProgressException(sprintf('Search index "%s" is being built already.', $this->getName()));
            }

            $definition = $this->getDefinition();
            $this->engine->validate($definition);

            $generation = max([$state->generation ?? 0, ...$this->listGenerations()]) + 1;
            $generationDir = $this->generationPath($generation);

            Directory::remove($generationDir);
            if (!@mkdir($generationDir, 0777, true) && !is_dir($generationDir)) {
                throw new SearchException(sprintf('Unable to create directory %s.', $generationDir));
            }
            $this->writeGenerationDefinition($generation, $definition);

            // the UUIDs to index, stored as one record, so the build can be continued step by step (and by other requests)
            $queue = new UuidTrie();
            $chunk = [];
            foreach ($this->storage->list($definition->className) as $uuid) {
                $chunk[] = $uuid;
                if (count($chunk) >= self::SNAPSHOT_CHUNK) {
                    $queue->add(...$chunk);
                    $chunk = [];
                }
            }
            $queue->add(...$chunk);
            $total = $queue->count();

            $this->replaceTrie(self::TRIE_BUILD, $queue);
            $this->deleteTrie(self::TRIE_REPLAY);

            $state->build = [
                'generation' => $generation,
                'total' => $total,
                'processed' => 0,
                'failed' => 0,
                'cursor' => null,
                'startedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
            ];
            $state->errors = [];

            return $this->getProgress($state);
        });
    }

    public function buildStep(int $batchSize = self::DEFAULT_BATCH_SIZE): array
    {
        return $this->withState(function (State $state) use ($batchSize): array {
            $build = $state->build;
            if (null === $build) {
                return $this->getProgress($state);
            }

            // one more than needed tells whether this is the last step
            $uuids = $this->readTrie(self::TRIE_BUILD)->first(max(1, $batchSize) + 1, $build['cursor']);
            $finished = count($uuids) <= max(1, $batchSize);
            $uuids = array_slice($uuids, 0, max(1, $batchSize));

            if ([] !== $uuids) {
                $result = $this->applyChanges($build['generation'], $uuids, $state->errors);
                $state->errors = $result['errors'];
                $build['failed'] += $result['failed'];
                $build['processed'] += count($uuids);
                $build['cursor'] = end($uuids);
            }

            $state->build = $build;

            if ($finished) {
                $this->finishBuild($state);
            }

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
            if (null === $state->build) {
                return;
            }

            $this->removeGeneration($state->build['generation']);
            $this->deleteTrie(self::TRIE_BUILD);
            $this->deleteTrie(self::TRIE_REPLAY);

            $state->build = null;
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

        return $this->openGeneration($generation)->search($request);
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
            $this->meta->store($definition, static::definitionUuid($this->getName()));
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
        $this->close();

        foreach ($this->listGenerations() as $generation) {
            try {
                $this->engine->remove($this->generationPath($generation), $this->getGenerationDefinition($generation));
            } catch (Throwable $e) {
                $this->storage->getLogger()?->log($e);
            }
        }

        try {
            $uuids = [
                static::definitionUuid($this->getName()),
                static::stateUuid($this->getName()),
                static::trieUuid($this->getName(), self::TRIE_PENDING),
                static::trieUuid($this->getName(), self::TRIE_BUILD),
                static::trieUuid($this->getName(), self::TRIE_REPLAY),
            ];
            foreach ($uuids as $uuid) {
                if ($this->meta->exists($uuid)) {
                    $this->meta->delete($uuid); // waits for a running change
                }
            }
        } catch (Throwable $e) {
            throw new SearchException(message: sprintf('Unable to remove search index "%s".', $this->getName()), previous: $e);
        }

        Directory::remove($this->directory);
    }

    /**
     * The UUID of a set of UUIDs of an index in the internal storage, derived from its name.
     *
     * @param string $kind One of the TRIE_ constants.
     */
    public static function trieUuid(string $name, string $kind): string
    {
        return static::uuidFor('trie:' . $kind, $name);
    }

    /**
     * Reads a set of UUIDs as stored; an empty one if there is none.
     *
     * @throws SearchException
     */
    private function readTrie(string $kind): UuidTrie
    {
        $uuid = static::trieUuid($this->getName(), $kind);

        try {
            $trie = $this->meta->exists($uuid) ? $this->meta->load($uuid) : null;
        } catch (Throwable $e) {
            throw new SearchException(message: sprintf('Unable to read the %s UUIDs of search index "%s".', $kind, $this->getName()), previous: $e);
        }

        return $trie instanceof UuidTrie ? $trie : new UuidTrie();
    }

    /**
     * Changes a set of UUIDs: loads it exclusively, which locks it also for other processes, runs the callback
     * and stores it, which releases the lock.
     *
     * @param callable(UuidTrie): mixed $change
     * @return mixed The result of the callback.
     * @throws SearchException
     */
    private function changeTrie(string $kind, callable $change): mixed
    {
        $uuid = static::trieUuid($this->getName(), $kind);
        $lockAdapter = $this->meta->getLockAdapter();

        try {
            // an exclusive load keeps the lock also if nothing is stored yet
            $trie = $this->meta->load($uuid, exclusive: true) ?? new UuidTrie();
            if (!$trie instanceof UuidTrie) {
                throw new SearchException(sprintf('The %s UUIDs of search index "%s" are invalid.', $kind, $this->getName()));
            }

            $result = $change($trie);
            $this->meta->store($trie, $uuid);

            return $result;
        } catch (SearchException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SearchException(message: sprintf('Unable to change the %s UUIDs of search index "%s".', $kind, $this->getName()), previous: $e);
        } finally {
            if ($lockAdapter->isLockedByThisProcess($uuid)) {
                $lockAdapter->releaseLock($uuid);
            }
        }
    }

    /**
     * Replaces a set of UUIDs.
     *
     * @throws SearchException
     */
    private function replaceTrie(string $kind, UuidTrie $trie): void
    {
        try {
            $this->meta->store($trie, static::trieUuid($this->getName(), $kind));
        } catch (Throwable $e) {
            throw new SearchException(message: sprintf('Unable to store the %s UUIDs of search index "%s".', $kind, $this->getName()), previous: $e);
        }
    }

    /**
     * Removes a set of UUIDs.
     *
     * @throws SearchException
     */
    private function deleteTrie(string $kind): void
    {
        $uuid = static::trieUuid($this->getName(), $kind);

        try {
            if ($this->meta->exists($uuid)) {
                $this->meta->delete($uuid);
            }
        } catch (Throwable $e) {
            throw new SearchException(message: sprintf('Unable to remove the %s UUIDs of search index "%s".', $kind, $this->getName()), previous: $e);
        }
    }

    public function close(): void
    {
        foreach (array_keys($this->handles) as $generation) {
            $this->closeGeneration($generation);
        }
    }

    /**
     * Runs a change of the state under the lock of the index, which serializes the syncs and build steps of all
     * processes. The lock is a lock of the internal storage under a UUID of its own, not the lock of the state
     * object: that one is only held while the state is written, so searches, which read the state, don't wait for
     * a sync or build step.
     *
     * @param callable(State): mixed $callback
     * @return mixed The result of the callback, null if $wait is false and another process holds the lock.
     * @throws SearchException
     */
    private function withState(callable $callback, bool $wait = true): mixed
    {
        $lockUuid = static::lockUuid($this->getName());
        $stateUuid = static::stateUuid($this->getName());
        $lockAdapter = $this->meta->getLockAdapter();

        try {
            $lockAdapter->acquireExclusiveLock($lockUuid, $wait ? LockAdapterInterface::LOCK_TIMEOUT_DEFAULT : 0);
        } catch (LockException $e) {
            if ($wait) {
                throw new SearchException(message: sprintf('Search index "%s" is locked by another process.', $this->getName()), previous: $e);
            }
            return null;
        }

        try {
            // all changes of the state happen under the lock, so it is read as it is
            $state = $this->meta->load($stateUuid);
            if (!$state instanceof State) {
                throw new SearchException(sprintf('Search index "%s" doesn\'t exist.', $this->getName()));
            }

            $result = $callback($state);

            $this->meta->store($state, $stateUuid);

            return $result;
        } catch (SearchException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SearchException(message: sprintf('Search index "%s": %s', $this->getName(), $e->getMessage()), previous: $e);
        } finally {
            if ($lockAdapter->isLockedByThisProcess($lockUuid)) {
                $lockAdapter->releaseLock($lockUuid);
            }
        }
    }

    private function statusOf(State $state): string
    {
        return match (true) {
            null !== $state->build => self::STATUS_BUILDING,
            null === $state->generation => self::STATUS_NEW,
            !$this->getDefinition()->equals($this->getGenerationDefinition($state->generation)) => self::STATUS_OUTDATED,
            default => self::STATUS_READY,
        };
    }

    private function countDocumentsOf(State $state): ?int
    {
        return null === $state->generation ? null : $this->openGeneration($state->generation)->count();
    }

    /**
     * Indexes the current state of the given objects in a generation: stored objects of the indexed class are
     * added or updated, all others (deleted, expired, of another class) are removed.
     *
     * @param string[] $uuids
     * @param string[] $errors The errors so far.
     * @return array{failed: int, errors: string[]}
     * @throws SearchException
     */
    private function applyChanges(int $generation, array $uuids, array $errors): array
    {
        $definition = $this->getGenerationDefinition($generation);
        $builder = new DocumentBuilder($definition);
        $handle = $this->openGeneration($generation);

        $documents = [];
        $remove = [];
        $failed = 0;

        foreach (array_unique($uuids) as $uuid) {
            try {
                $stored = $this->readStored($uuid);
                if (null === $stored || $stored[0]->getClassName() !== $definition->className) {
                    $remove[] = $uuid;
                    continue;
                }
                $documents[$uuid] = $builder->build($uuid, $stored[1], $stored[0]);
            } catch (Throwable $e) {
                $failed++;
                $remove[] = $uuid;
                $errors[] = sprintf('%s: %s', $uuid, $e->getMessage());
            }
        }

        if ([] !== $documents) {
            // documents the engine rejects (e.g. a type that doesn't match the other documents) are removed,
            // so no outdated version of them stays searchable
            foreach ($handle->upsert($documents) as $uuid => $message) {
                $failed++;
                $remove[] = (string)$uuid;
                $errors[] = sprintf('%s: %s', $uuid, $message);
            }
        }

        if ([] !== $remove) {
            $handle->delete(array_values(array_unique($remove)));
        }

        return ['failed' => $failed, 'errors' => array_slice($errors, -self::MAX_ERRORS)];
    }

    /**
     * Reads the metadata and data of an object as stored, bypassing the caches of the storage, which may be
     * outdated in long-running processes.
     *
     * @return array{0: Metadata, 1: array}|null Null if nothing (or an expired object) is stored.
     * @throws JsonException
     */
    private function readStored(string $uuid): ?array
    {
        $adapter = $this->storage->getIOAdapter();
        $filePathMetadata = $this->storage->getFilePathMetadata($uuid);
        $filePathData = $this->storage->getFilePathData($uuid);

        if (!$adapter->isFile($filePathMetadata) || !$adapter->isFile($filePathData)) {
            return null;
        }

        $metadataRaw = $adapter->fileGetContents($filePathMetadata);
        $dataRaw = $adapter->fileGetContents($filePathData);
        if (false === $metadataRaw || false === $dataRaw) {
            return null;
        }

        $metadata = Metadata::createFromArray(json_decode($metadataRaw, true, 512, JSON_THROW_ON_ERROR));

        $lifetime = $metadata->getLifetime();
        if (null !== $lifetime && $lifetime <= 0) {
            return null;
        }

        $data = json_decode($dataRaw, true, 512, JSON_THROW_ON_ERROR);

        return [$metadata, is_array($data) ? $data : []];
    }

    /**
     * Completes a build: applies the changes taken meanwhile and activates the new generation.
     * Changes still pending are applied to the new generation by the next sync.
     *
     * @throws SearchException
     */
    private function finishBuild(State $state): void
    {
        $generation = $state->build['generation'];

        // syncs only add to the replay set under the lock of the index, which this build step holds
        $replay = $this->readTrie(self::TRIE_REPLAY);
        $cursor = null;
        while ([] !== ($uuids = $replay->first(500, $cursor))) {
            $state->errors = $this->applyChanges($generation, $uuids, $state->errors)['errors'];
            $cursor = end($uuids);
        }

        $processed = $state->build['processed'];
        $state->generation = $generation;
        $state->build = null;
        $state->lastBuildAt = (new DateTimeImmutable())->format(DATE_ATOM);
        $state->addUpdate(State::UPDATE_BUILD, $processed, 0, $this->countDocumentsOf($state));

        $this->deleteTrie(self::TRIE_BUILD);
        $this->deleteTrie(self::TRIE_REPLAY);

        // other generations aren't used anymore; data still open by another process is removed next time
        foreach ($this->listGenerations() as $other) {
            if ($other !== $generation) {
                $this->removeGeneration($other);
            }
        }
    }

    private function removeGeneration(int $generation): void
    {
        $this->closeGeneration($generation);

        try {
            $this->engine->remove($this->generationPath($generation), $this->getGenerationDefinition($generation));
        } catch (Throwable $e) {
            $this->storage->getLogger()?->log($e);
        }

        Directory::remove($this->generationPath($generation));
    }

    /**
     * The definition a generation was built with; kept with its data, as the current one may differ.
     *
     * @throws SearchException
     */
    private function getGenerationDefinition(int $generation): Definition
    {
        $path = $this->generationPath($generation) . DIRECTORY_SEPARATOR . self::FILE_DEFINITION;
        $content = is_file($path) ? @file_get_contents($path) : false;

        try {
            $data = false === $content ? null : json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new SearchException(sprintf('The data of search index "%s" is missing (generation %u).', $this->getName(), $generation));
            }
            return Definition::fromArray($data);
        } catch (SearchException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SearchException(message: sprintf('Invalid definition of search index "%s" (generation %u).', $this->getName(), $generation), previous: $e);
        }
    }

    /**
     * @throws SearchException
     */
    private function writeGenerationDefinition(int $generation, Definition $definition): void
    {
        $path = $this->generationPath($generation) . DIRECTORY_SEPARATOR . self::FILE_DEFINITION;
        $json = json_encode($definition->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === $json || false === @file_put_contents($path, $json)) {
            throw new SearchException(sprintf('Unable to write %s.', $path));
        }
    }

    /**
     * @throws SearchException
     */
    private function openGeneration(int $generation): EngineIndexInterface
    {
        if (!isset($this->handles[$generation])) {
            try {
                $this->handles[$generation] = $this->engine->open($this->generationPath($generation), $this->getGenerationDefinition($generation));
            } catch (SearchException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new SearchException(message: sprintf('Unable to open search index "%s": %s', $this->getName(), $e->getMessage()), previous: $e);
            }
        }

        return $this->handles[$generation];
    }

    private function closeGeneration(int $generation): void
    {
        if (isset($this->handles[$generation])) {
            $this->handles[$generation]->close();
            unset($this->handles[$generation]);
        }
    }

    /**
     * @return int[] The generations present on disk.
     */
    private function listGenerations(): array
    {
        $generations = [];
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . self::DIR_GENERATION_PREFIX . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $suffix = substr(basename($dir), strlen(self::DIR_GENERATION_PREFIX));
            if (ctype_digit($suffix)) {
                $generations[] = (int)$suffix;
            }
        }
        sort($generations);
        return $generations;
    }

    private function generationPath(int $generation): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . self::DIR_GENERATION_PREFIX . $generation;
    }

    /**
     * A UUID derived from a name, so the objects of an index can be found by its name.
     */
    private static function uuidFor(string $kind, string $name): string
    {
        return Helper::createFixedUuidV4('melia/object-storage:search-index:' . $kind . ':' . $name);
    }

    public function __destruct()
    {
        $this->close();
    }
}
