<?php

namespace melia\ObjectStorage\Search\Index;

/**
 * The state of a search index, stored as an object in the internal storage of the indexes. It is only changed
 * under the lock of the index (see Index::lockUuid()), so syncs and build steps of all processes run one
 * after the other, while searches read it without waiting for them.
 */
final class State
{
    /**
     * @param string $name The name of the index.
     * @param int|null $generation The active generation (the Loupe database searched), null before the first build.
     * @param array{generation: int, total: int, processed: int, failed: int, cursor: ?string, startedAt: string}|null $build
     *        The running build, null without one. The cursor is the last indexed UUID of the build trie.
     * @param string[] $errors The last errors, e.g. documents Loupe rejected.
     * @param string|null $lastBuildAt When the last build was completed (ATOM).
     * @param string|null $lastSyncAt When changes were applied last (ATOM).
     */
    public function __construct(
        public string  $name,
        public ?int    $generation = null,
        public ?array  $build = null,
        public array   $errors = [],
        public ?string $lastBuildAt = null,
        public ?string $lastSyncAt = null,
    )
    {
    }
}
