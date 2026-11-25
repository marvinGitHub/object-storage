<?php

namespace melia\ObjectStorage\Search\Index;

use melia\ObjectStorage\Exception\LockException;
use melia\ObjectStorage\Locking\LockAdapterInterface;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Search\Exception\SearchException;
use melia\ObjectStorage\UUID\Helper;
use Throwable;

/**
 * The definition and the state of a search index, objects in the internal storage of the indexes under UUIDs derived
 * from its name; and the lock of the index, under which its state is changed.
 *
 * @internal part of Index
 */
final class IndexRecords
{
    /**
     * @param ObjectStorage $meta The internal storage of the indexes.
     * @param string $name The name of the index.
     */
    public function __construct(
        private readonly ObjectStorage $meta,
        private readonly string        $name,
    )
    {
    }

    /**
     * The UUID of the definition of an index in the internal storage, derived from its name.
     */
    public static function definitionUuid(string $name): string
    {
        return self::uuidFor('definition', $name);
    }

    /**
     * The UUID of the state of an index in the internal storage, derived from its name.
     */
    public static function stateUuid(string $name): string
    {
        return self::uuidFor('state', $name);
    }

    /**
     * The UUID locked in the internal storage while a sync or build step of an index runs, derived from its name.
     */
    public static function lockUuid(string $name): string
    {
        return self::uuidFor('lock', $name);
    }

    /**
     * A UUID derived from a name, so the objects of an index can be found by its name.
     */
    public static function uuidFor(string $kind, string $name): string
    {
        return Helper::createFixedUuidV4('melia/object-storage:search-index:' . $kind . ':' . $name);
    }

    /**
     * @throws SearchException If the definition is missing or can't be loaded.
     */
    public function definition(): Definition
    {
        try {
            $definition = $this->meta->load(self::definitionUuid($this->name));
        } catch (Throwable $e) {
            throw new SearchException(message: sprintf('Unable to load the definition of search index "%s".', $this->name), previous: $e);
        }

        if (!$definition instanceof Definition) {
            throw new SearchException(sprintf('The definition of search index "%s" is missing.', $this->name));
        }

        return $definition;
    }

    /**
     * Replaces the definition; call it under the lock of the index (withState()).
     *
     * @throws Throwable
     */
    public function storeDefinition(Definition $definition): void
    {
        $this->meta->store($definition, self::definitionUuid($this->name));
    }

    /**
     * The state as stored, without waiting for a sync or build step; a new state if there is none.
     *
     * @throws SearchException
     */
    public function state(): State
    {
        try {
            $state = $this->meta->load(self::stateUuid($this->name));
        } catch (Throwable $e) {
            throw new SearchException(message: sprintf('Unable to load the state of search index "%s".', $this->name), previous: $e);
        }

        return $state instanceof State ? $state : new State($this->name);
    }

    /**
     * Runs a change of the state under the lock of the index, which serializes the syncs and build steps of all
     * processes, and stores the state afterwards. The lock is a lock of the internal storage under a UUID of its own,
     * not the lock of the state object: that one is only held while the state is written, so searches, which read
     * the state, don't wait for a sync or build step.
     *
     * @template T
     * @param callable(State): T $callback
     * @return T|null The result of the callback, null if $wait is false and another process holds the lock.
     * @throws SearchException
     */
    public function withState(callable $callback, bool $wait = true): mixed
    {
        $lockUuid = self::lockUuid($this->name);
        $stateUuid = self::stateUuid($this->name);
        $lockAdapter = $this->meta->getLockAdapter();

        try {
            $lockAdapter->acquireExclusiveLock($lockUuid, $wait ? LockAdapterInterface::LOCK_TIMEOUT_DEFAULT : 0);
        } catch (LockException $e) {
            if ($wait) {
                throw new SearchException(message: sprintf('Search index "%s" is locked by another process.', $this->name), previous: $e);
            }
            return null;
        }

        try {
            // all changes of the state happen under the lock, so it is read as it is
            $state = $this->meta->load($stateUuid);
            if (!$state instanceof State) {
                throw new SearchException(sprintf('Search index "%s" doesn\'t exist.', $this->name));
            }

            $result = $callback($state);
            $this->meta->store($state, $stateUuid);

            return $result;
        } catch (Throwable $e) {
            // the cache of the storage holds the state as the failed change left it: read as stored next time, so
            // the next change doesn't store it along
            $this->meta->removeFromCache($stateUuid);

            throw $e instanceof SearchException
                ? $e
                : new SearchException(message: sprintf('Search index "%s": %s', $this->name, $e->getMessage()), previous: $e);
        } finally {
            $lockAdapter->releaseLockIfHeld($lockUuid);
        }
    }

    /**
     * Removes the definition and the state.
     *
     * @throws Throwable
     */
    public function remove(): void
    {
        foreach ([self::definitionUuid($this->name), self::stateUuid($this->name)] as $uuid) {
            if ($this->meta->exists($uuid)) {
                $this->meta->delete($uuid); // waits for a running change
            }
        }
    }
}
