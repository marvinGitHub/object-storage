<?php

namespace melia\ObjectStorage\Search\Index;

use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Search\Exception\SearchException;
use melia\ObjectStorage\Util\UuidTrie;
use Throwable;

/**
 * The sets of UUIDs of a search index, each one object (a UuidTrie) in the internal storage of the indexes under a
 * UUID derived from the name of the index:
 *
 *   pending       the objects changed since the last sync
 *   referenced    the referenced objects changed since the last sync: the documents built from them (see
 *                 DocumentBuilder::ATTRIBUTE_REFERENCED) become pending changes
 *   build         the objects to index while a build is running
 *   replay        the changes applied during a build, applied to the new generation at its end
 *
 * A set is changed under its own lock (an exclusive load), so changes are recorded by any process at any time,
 * also while a sync or build step runs.
 *
 * @internal part of Index
 */
final class UuidSets
{
    public const string PENDING = 'pending';
    public const string REFERENCED = 'referenced';
    public const string BUILD = 'build';
    public const string REPLAY = 'replay';

    /** all sets */
    public const array KINDS = [self::PENDING, self::REFERENCED, self::BUILD, self::REPLAY];

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
     * The UUID of a set of UUIDs of an index in the internal storage, derived from its name.
     *
     * @param string $kind One of KINDS.
     */
    public static function uuid(string $name, string $kind): string
    {
        return IndexRecords::uuidFor('trie:' . $kind, $name);
    }

    /**
     * A set as stored; an empty one if there is none.
     *
     * @throws SearchException
     */
    public function read(string $kind): UuidTrie
    {
        $uuid = self::uuid($this->name, $kind);

        try {
            $trie = $this->meta->exists($uuid) ? $this->meta->load($uuid) : null;
        } catch (Throwable $e) {
            throw new SearchException(message: sprintf('Unable to read the %s UUIDs of search index "%s".', $kind, $this->name), previous: $e);
        }

        return $trie instanceof UuidTrie ? $trie : new UuidTrie();
    }

    /**
     * @throws SearchException
     */
    public function count(string $kind): int
    {
        return $this->read($kind)->count();
    }

    /**
     * Adds UUIDs to a set.
     *
     * @param string[] $uuids
     * @throws SearchException
     */
    public function add(string $kind, array $uuids): void
    {
        $this->change($kind, static fn(UuidTrie $trie): int => $trie->add(...$uuids));
    }

    /**
     * Takes the first UUIDs out of a set.
     *
     * @return string[]
     * @throws SearchException
     */
    public function take(string $kind, int $count): array
    {
        return $this->change($kind, static function (UuidTrie $trie) use ($count): array {
            $uuids = $trie->first($count);
            $trie->remove(...$uuids);
            return $uuids;
        });
    }

    /**
     * Replaces a set.
     *
     * @throws SearchException
     */
    public function replace(string $kind, UuidTrie $trie): void
    {
        try {
            $this->meta->store($trie, self::uuid($this->name, $kind));
        } catch (Throwable $e) {
            throw new SearchException(message: sprintf('Unable to store the %s UUIDs of search index "%s".', $kind, $this->name), previous: $e);
        }
    }

    /**
     * Removes a set.
     *
     * @throws SearchException
     */
    public function delete(string $kind): void
    {
        $uuid = self::uuid($this->name, $kind);

        try {
            if ($this->meta->exists($uuid)) {
                $this->meta->delete($uuid);
            }
        } catch (Throwable $e) {
            throw new SearchException(message: sprintf('Unable to remove the %s UUIDs of search index "%s".', $kind, $this->name), previous: $e);
        }
    }

    /**
     * Removes all sets.
     *
     * @throws SearchException
     */
    public function deleteAll(): void
    {
        foreach (self::KINDS as $kind) {
            $this->delete($kind);
        }
    }

    /**
     * Changes a set: loads it exclusively, which locks it also for other processes, runs the callback and stores
     * it, which releases the lock.
     *
     * @template T
     * @param callable(UuidTrie): T $change
     * @return T The result of the callback.
     * @throws SearchException
     */
    private function change(string $kind, callable $change): mixed
    {
        $uuid = self::uuid($this->name, $kind);
        $lockAdapter = $this->meta->getLockAdapter();

        try {
            // an exclusive load keeps the lock also if nothing is stored yet
            $trie = $this->meta->load($uuid, exclusive: true) ?? new UuidTrie();
            if (!$trie instanceof UuidTrie) {
                throw new SearchException(sprintf('The %s UUIDs of search index "%s" are invalid.', $kind, $this->name));
            }

            $result = $change($trie);
            $this->meta->store($trie, $uuid);

            return $result;
        } catch (SearchException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SearchException(message: sprintf('Unable to change the %s UUIDs of search index "%s".', $kind, $this->name), previous: $e);
        } finally {
            $lockAdapter->releaseLockIfHeld($uuid);
        }
    }
}
