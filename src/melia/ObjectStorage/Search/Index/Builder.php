<?php

namespace melia\ObjectStorage\Search\Index;

use DateTimeImmutable;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Search\Engine\EngineInterface;
use melia\ObjectStorage\Search\Exception\IndexBuildInProgressException;
use melia\ObjectStorage\Search\Exception\SearchException;
use melia\ObjectStorage\Util\UuidTrie;
use Throwable;

/**
 * Builds a search index step by step into a new generation: the UUIDs to index are stored as a set, so the build
 * can be continued by other requests; at its end the new generation becomes the active one.
 *
 * Runs under the lock of the index, on its state (see IndexRecords::withState()).
 *
 * @internal part of Index
 */
final class Builder
{
    public const int DEFAULT_BATCH_SIZE = 200;

    /** the UUIDs added to the set of a build at once */
    private const int SNAPSHOT_CHUNK = 10000;

    /** the changes taken during a build applied at once at its end */
    private const int REPLAY_BATCH = 500;

    /**
     * @param ObjectStorage $storage The storage of the indexed objects, which lists them.
     * @param EngineInterface $engine The engine, which validates the definition.
     * @param UuidSets $sets The sets of UUIDs of the index.
     * @param Generations $generations The generations of the index.
     * @param DocumentIndexer $indexer Indexes objects in a generation.
     * @param Synchronizer $synchronizer Applies the changes still pending at the end of a build.
     * @param string $name The name of the index, for messages.
     */
    public function __construct(
        private readonly ObjectStorage   $storage,
        private readonly EngineInterface $engine,
        private readonly UuidSets        $sets,
        private readonly Generations     $generations,
        private readonly DocumentIndexer $indexer,
        private readonly Synchronizer    $synchronizer,
        private readonly string          $name,
    )
    {
    }

    /**
     * Starts a build of a definition: creates a new generation and stores the UUIDs of the objects to index.
     *
     * @throws IndexBuildInProgressException
     * @throws SearchException
     */
    public function start(State $state, Definition $definition): void
    {
        if (null !== $state->build) {
            throw new IndexBuildInProgressException(sprintf('Search index "%s" is being built already.', $this->name));
        }

        $this->engine->validate($definition);
        $generation = $this->generations->create($state->generation, $definition);

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

        $this->sets->replace(UuidSets::BUILD, $queue);
        $this->sets->delete(UuidSets::REPLAY);

        $state->build = [
            'generation' => $generation,
            'total' => $queue->count(),
            'processed' => 0,
            'failed' => 0,
            'cursor' => null,
            'startedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];
        $state->errors = [];
    }

    /**
     * Indexes the next objects of the running build; completes it after the last ones.
     *
     * @throws SearchException
     */
    public function step(State $state, int $batchSize): void
    {
        $build = $state->build;
        if (null === $build) {
            return;
        }

        // one more than needed tells whether this is the last step
        $batchSize = max(1, $batchSize);
        $uuids = $this->sets->read(UuidSets::BUILD)->first($batchSize + 1, $build['cursor']);
        $finished = count($uuids) <= $batchSize;
        $uuids = array_slice($uuids, 0, $batchSize);

        if ([] !== $uuids) {
            $result = $this->indexer->apply($build['generation'], $uuids);
            $state->addErrors(...$result['errors']);
            $build['failed'] += $result['failed'];
            $build['processed'] += count($uuids);
            $build['cursor'] = end($uuids);
        }

        $state->build = $build;

        if ($finished) {
            $this->finish($state);
        }
    }

    /**
     * Cancels the running build: removes its generation; the active one stays.
     *
     * @throws SearchException
     */
    public function cancel(State $state): void
    {
        if (null === $state->build) {
            return;
        }

        $this->generations->remove($state->build['generation']);
        $this->sets->delete(UuidSets::BUILD);
        $this->sets->delete(UuidSets::REPLAY);

        $state->build = null;
    }

    /**
     * Completes a build: applies the changes taken meanwhile, activates the new generation and applies the changes
     * still pending to it, so the index is up to date (and used by queries) without a sync. Pending changes are kept
     * until then: a cancelled build leaves them for the generation that stays active.
     *
     * @throws SearchException
     */
    private function finish(State $state): void
    {
        $generation = $state->build['generation'];

        // syncs only add to the replay set under the lock of the index, which this build step holds
        $replay = $this->sets->read(UuidSets::REPLAY);
        $cursor = null;
        while ([] !== ($uuids = $replay->first(self::REPLAY_BATCH, $cursor))) {
            $state->addErrors(...$this->indexer->apply($generation, $uuids)['errors']);
            $cursor = end($uuids);
        }

        $processed = $state->build['processed'];
        $state->generation = $generation;
        $state->build = null;
        $state->lastBuildAt = (new DateTimeImmutable())->format(DATE_ATOM);
        $state->addUpdate(State::UPDATE_BUILD, $processed, 0, $this->generations->open($generation)->count());

        $this->sets->delete(UuidSets::BUILD);
        $this->sets->delete(UuidSets::REPLAY);

        // other generations aren't used anymore
        $this->generations->removeAllBut($generation);

        // changes recorded before or during the build: the new generation reads the objects as they are now; one
        // that fails stays pending for the next sync
        try {
            // (counting them first: taking from a set stores it, also when there is none)
            if (0 !== $this->synchronizer->countPending()) {
                $this->synchronizer->applyPending($state, self::DEFAULT_BATCH_SIZE);
            }
        } catch (Throwable $e) {
            $this->synchronizer->addError($state, 'Pending changes', $e);
        }
    }
}
