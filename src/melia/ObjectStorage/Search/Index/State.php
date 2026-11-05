<?php

namespace melia\ObjectStorage\Search\Index;

/**
 * The state of a search index, stored as an object in the internal storage of the indexes. It is only changed
 * under the lock of the index (see Index::lockUuid()), so syncs and build steps of all processes run one
 * after the other, while searches read it without waiting for them.
 */
final class State
{
    public const string UPDATE_BUILD = 'build';
    public const string UPDATE_SYNC = 'sync';
    public const string UPDATE_EXPIRED = 'expired';

    /** The number of updates kept in $updates. */
    public const int MAX_UPDATES = 20;

    /**
     * The last updates of the documents, newest first: completed builds, syncs that changed documents and removals
     * of expired objects. A plain property with a default, so states stored before have no updates instead of
     * failing to load.
     *
     * @var list<array{at: string, kind: string, changed: int, expired: int, documents: ?int}>
     */
    public array $updates = [];

    /**
     * The classes of the referenced objects documents were built from (paths into referenced objects, e.g.
     * "category.title"): changes of their objects update the documents built from them. Only grows, until the
     * index is dropped.
     *
     * @var string[]
     */
    public array $joinedClasses = [];

    /**
     * @param string $name The name of the index.
     * @param int|null $generation The active generation (the Loupe database searched), null before the first build.
     * @param array{generation: int, total: int, processed: int, failed: int, cursor: ?string, startedAt: string}|null $build
     *        The running build, null without one. The cursor is the last indexed UUID of the build trie.
     * @param string[] $errors The last errors, e.g. documents Loupe rejected.
     * @param string|null $lastBuildAt When the last build was completed (ATOM).
     * @param string|null $lastSyncAt When changes were applied last (ATOM).
     * @param int $lastExpiredRemoved The number of expired objects removed by the last sync.
     */
    public function __construct(
        public string  $name,
        public ?int    $generation = null,
        public ?array  $build = null,
        public array   $errors = [],
        public ?string $lastBuildAt = null,
        public ?string $lastSyncAt = null,
        public int     $lastExpiredRemoved = 0,
    )
    {
    }

    /**
     * Records an update of the documents; only the last MAX_UPDATES are kept.
     *
     * @param string $kind One of the UPDATE_* constants.
     * @param int $changed The number of objects whose documents were added, updated or removed (for a build:
     *        indexed).
     * @param int $expired The number of documents of expired objects removed.
     * @param int|null $documents The number of documents afterwards.
     */
    public function addUpdate(string $kind, int $changed, int $expired = 0, ?int $documents = null): void
    {
        array_unshift($this->updates, [
            'at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'kind' => $kind,
            'changed' => $changed,
            'expired' => $expired,
            'documents' => $documents,
        ]);
        $this->updates = array_slice($this->updates, 0, self::MAX_UPDATES);
    }

    /**
     * The last update of the documents, null if there is none.
     *
     * @return array{at: string, kind: string, changed: int, expired: int, documents: ?int}|null
     */
    public function getLastUpdate(): ?array
    {
        return $this->updates[0] ?? null;
    }
}
