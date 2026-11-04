<?php

namespace melia\ObjectStorage\Search\Index;

use Generator;
use melia\ObjectStorage\Search\Exception\IndexBuildInProgressException;
use melia\ObjectStorage\Search\Exception\IndexNotReadyException;
use melia\ObjectStorage\Search\Exception\SearchException;
use melia\ObjectStorage\Search\Exception\UnsupportedQueryException;
use melia\ObjectStorage\Search\Request;
use melia\ObjectStorage\Search\Result;

/**
 * A search index of the objects of one class, independent of its engine.
 */
interface IndexInterface
{
    /** never built */
    public const string STATUS_NEW = 'new';
    /** a build is running; searches use the previous build */
    public const string STATUS_BUILDING = 'building';
    /** searchable with the current definition */
    public const string STATUS_READY = 'ready';
    /** the definition changed since the last build; searches use the previous definition until it is rebuilt */
    public const string STATUS_OUTDATED = 'outdated';

    public function getName(): string;

    /**
     * The name of the engine, e.g. "loupe".
     *
     * @throws SearchException
     */
    public function getEngineName(): string;

    /**
     * The definition as last created or updated.
     *
     * @throws SearchException
     */
    public function getDefinition(): Definition;

    /**
     * The definition the searchable build was made with, null before the first build.
     *
     * @throws SearchException
     */
    public function getActiveDefinition(): ?Definition;

    /**
     * @throws SearchException
     */
    public function getState(): State;

    /**
     * One of the STATUS_ constants.
     *
     * @throws SearchException
     */
    public function getStatus(): string;

    /**
     * Whether the index has been built at least once.
     *
     * @throws SearchException
     */
    public function isSearchable(): bool;

    /**
     * The number of indexed documents, null before the first build.
     *
     * @throws SearchException
     */
    public function countDocuments(): ?int;

    /**
     * The space the index occupies on disk in bytes: all generations in its directory, so while it is rebuilt the
     * previous and the new build. Data an engine keeps elsewhere, e.g. on a server, isn't included.
     */
    public function getSize(): int;

    /**
     * Searches the index.
     *
     * @param Request|object $request A Request, or a query object of the engine for its own features.
     * @throws IndexNotReadyException If the index has never been built.
     * @throws UnsupportedQueryException If the engine doesn't accept the query object.
     * @throws SearchException
     */
    public function search(object $request): Result;

    /**
     * Searches the index and loads the found objects, in the order of the hits. Objects deleted or expired since
     * they were indexed are skipped.
     *
     * @param Request|object $request
     * @return Generator<string, object> UUID => object
     * @throws SearchException
     */
    public function searchObjects(object $request): Generator;

    /**
     * Builds the index completely with the current definition.
     *
     * @param callable(array): void|null $onProgress Called with the progress after every step, see getProgress().
     * @return array The final progress.
     * @throws SearchException
     */
    public function build(?callable $onProgress = null, int $batchSize = 200): array;

    /**
     * Starts a build; continue with buildStep() until the progress reports done.
     *
     * @throws IndexBuildInProgressException
     * @throws SearchException
     */
    public function startBuild(): array;

    /**
     * Indexes the next objects of the running build; completes it after the last one.
     *
     * @throws SearchException
     */
    public function buildStep(int $batchSize = 200): array;

    /**
     * Cancels a running build; the previous build stays searchable.
     *
     * @throws SearchException
     */
    public function cancelBuild(): void;

    /**
     * The progress of the running (or last) build.
     *
     * @param State|null $state The state to report, the stored one by default.
     * @return array{status: string, total: int, processed: int, failed: int, percent: int, done: bool, errors: string[], documents: ?int}
     * @throws SearchException
     */
    public function getProgress(?State $state = null): array;

    /**
     * Records a change of an object, to be applied by the next sync.
     *
     * @throws SearchException
     */
    public function recordChange(string ...$uuids): void;

    /**
     * Applies the recorded changes, and removes the objects that have expired since they were indexed.
     *
     * @param bool $wait Whether to wait if another process is working on the index; otherwise nothing is done.
     * @return int The number of applied changes and removed expired objects.
     * @throws SearchException
     */
    public function sync(bool $wait = true): int;

    /**
     * Removes the objects that have expired since they were indexed; sync() does this as well. Objects whose
     * lifetime was extended meanwhile are updated instead.
     *
     * @param bool $wait Whether to wait if another process is working on the index; otherwise nothing is done.
     * @return int The number of removed objects.
     * @throws SearchException
     */
    public function purgeExpired(bool $wait = true): int;

    public function countPendingChanges(): int;

    public function hasPendingChange(string $uuid): bool;

    /**
     * Releases open resources of the engine.
     */
    public function close(): void;
}
