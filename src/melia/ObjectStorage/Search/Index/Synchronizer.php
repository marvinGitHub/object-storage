<?php

namespace melia\ObjectStorage\Search\Index;

use melia\ObjectStorage\Logger\LoggerAwareInterface;
use melia\ObjectStorage\Search\Exception\SearchException;
use Throwable;

/**
 * Brings the documents of a search index up to date: applies the pending changes (those of referenced objects
 * too) to the active generation and, at its end, to a running build; removes the documents of expired objects.
 *
 * Runs under the lock of the index, on its state (see IndexRecords::withState()).
 *
 * @internal part of Index
 */
final class Synchronizer
{
    /** the changed referenced objects looked up at once */
    public const int REFERENCED_BATCH = 50;

    /** the rounds of removing expired objects at most */
    private const int MAX_EXPIRED_ROUNDS = 10000;

    /**
     * @param UuidSets $sets The sets of UUIDs of the index.
     * @param Generations $generations The generations of the index.
     * @param DocumentIndexer $indexer Indexes objects in a generation.
     * @param LoggerAwareInterface $logs Where errors are logged, as it is then.
     */
    public function __construct(
        private readonly UuidSets             $sets,
        private readonly Generations          $generations,
        private readonly DocumentIndexer      $indexer,
        private readonly LoggerAwareInterface $logs,
    )
    {
    }

    /**
     * The pending changes, those of referenced objects too.
     *
     * @throws SearchException
     */
    public function countPending(): int
    {
        return $this->sets->count(UuidSets::PENDING) + $this->sets->count(UuidSets::REFERENCED);
    }

    /**
     * Applies the pending changes (those of referenced objects too) to the active generation, and to a running build
     * at its end.
     *
     * @param int|null $maxChanges The number of changes to apply at most; the others stay pending. Null for all.
     * @return int The number of applied changes.
     * @throws SearchException
     */
    public function applyPending(State $state, int $batchSize, ?int $maxChanges = null): int
    {
        $applied = 0;

        $this->expandReferenceChanges($state);

        while (null === $maxChanges || $applied < $maxChanges) {
            // taken out before they are applied: a change recorded meanwhile is recorded again and not lost
            $uuids = $this->sets->take(UuidSets::PENDING, null === $maxChanges ? $batchSize : max(1, min($batchSize, $maxChanges - $applied)));
            if ([] === $uuids) {
                break;
            }
            try {
                if (null !== $state->generation) {
                    $state->addErrors(...$this->indexer->apply($state->generation, $uuids)['errors']);
                }
                $this->replayIfBuilding($state, $uuids);
            } catch (Throwable $e) {
                $this->sets->add(UuidSets::PENDING, $uuids);
                throw $e;
            }

            $applied += count($uuids);
        }

        return $applied;
    }

    /**
     * Removes the documents of expired objects: the engine finds the documents whose expiration has passed, and
     * their current state is applied. Expired (or deleted) objects are removed; objects whose lifetime was
     * extended meanwhile are updated instead.
     *
     * @return int The number of removed documents.
     * @throws SearchException
     */
    public function removeExpired(State $state, int $batchSize): int
    {
        if (null === $state->generation) {
            return 0;
        }

        $handle = $this->generations->open($state->generation);
        $removed = 0;
        $seen = [];

        // each round applies the current state, so a document isn't found again; $seen guards against engines
        // reporting a document twice
        for ($round = 0; $round < self::MAX_EXPIRED_ROUNDS; $round++) {
            try {
                $uuids = array_values(array_diff($handle->expired(microtime(true), max(1, $batchSize)), array_keys($seen)));
            } catch (Throwable $e) {
                // e.g. a generation built before the expiration was indexed: rebuilding it helps
                $this->addError($state, 'Expired objects', $e);
                break;
            }

            if ([] === $uuids) {
                break;
            }

            foreach ($uuids as $uuid) {
                $seen[$uuid] = true;
            }

            $before = $handle->count();
            $state->addErrors(...$this->indexer->apply($state->generation, $uuids)['errors']);
            $removed += max(0, $before - $handle->count());

            $this->replayIfBuilding($state, $uuids);
        }

        $state->lastExpiredRemoved = $removed;

        return $removed;
    }

    /**
     * Logs an error and keeps it in the state.
     */
    public function addError(State $state, string $context, Throwable $e): void
    {
        $this->logs->getLogger()?->log($e);
        $state->addErrors($context . ': ' . $e->getMessage());
    }

    /**
     * Turns the changes of referenced objects into pending changes of the documents built from them, in the active
     * generation and in the one being built.
     *
     * @throws SearchException
     */
    private function expandReferenceChanges(State $state): void
    {
        $generations = array_values(array_filter([$state->generation, $state->build['generation'] ?? null], static fn(?int $generation): bool => null !== $generation));

        while ([] !== ($uuids = $this->sets->take(UuidSets::REFERENCED, self::REFERENCED_BATCH))) {
            $dependents = [];
            try {
                foreach ($generations as $generation) {
                    foreach ($this->indexer->referencing($generation, $uuids) as $uuid) {
                        $dependents[$uuid] = true;
                    }
                }
            } catch (Throwable $e) {
                // e.g. a generation built before documents recorded their referenced objects: rebuilding it helps
                $this->addError($state, 'Referenced objects', $e);
                continue;
            }

            if ([] !== $dependents) {
                $this->sets->add(UuidSets::PENDING, array_map('strval', array_keys($dependents)));
            }
        }
    }

    /**
     * A running build may have read the objects before they changed: they are applied to it at its end.
     *
     * @param string[] $uuids
     * @throws SearchException
     */
    private function replayIfBuilding(State $state, array $uuids): void
    {
        if (null !== $state->build) {
            $this->sets->add(UuidSets::REPLAY, $uuids);
        }
    }
}
