<?php

namespace melia\ObjectStorage\Util;

use Countable;
use Generator;
use IteratorAggregate;
use melia\ObjectStorage\UUID\Exception\InvalidUUIDException;
use melia\ObjectStorage\UUID\Validator;

/**
 * A set of UUIDs as a radix trie, stored as one object: store() it like any other object, it is written as a
 * single record.
 *
 * The UUIDs are keyed by their 32 hex digits. A node maps edges (runs of digits) to child nodes; chains without
 * branches are merged into one edge, so the trie has at most about two nodes per UUID. As all keys have the same
 * length, every complete key ends in a leaf, marked with true:
 *
 *   ['k3f' => ['k1c…' => true, 'k9a…' => true], 'k70e1…' => true]
 *
 * Edges are prefixed with "k", as PHP would turn numeric keys such as "12" into integers.
 *
 * Looking up a UUID follows its digits; iterating yields the UUIDs in ascending order and can start after a given
 * UUID, so a long iteration can be continued later.
 *
 * @implements IteratorAggregate<int, string>
 */
final class UuidTrie implements IteratorAggregate, Countable
{
    private const string EDGE_PREFIX = 'k';

    /** @var array<string, array|true> */
    private array $root = [];

    private int $count = 0;

    /**
     * @param string ...$uuids The initial UUIDs.
     * @throws InvalidUUIDException
     */
    public function __construct(string ...$uuids)
    {
        $this->add(...$uuids);
    }

    /**
     * Adds UUIDs; UUIDs already contained are ignored.
     *
     * @return int The number of added UUIDs.
     * @throws InvalidUUIDException
     */
    public function add(string ...$uuids): int
    {
        $added = 0;
        foreach (static::keys($uuids) as $key) {
            if ($this->insert($this->root, $key)) {
                $added++;
            }
        }
        $this->count += $added;

        return $added;
    }

    /**
     * Removes UUIDs; UUIDs not contained are ignored.
     *
     * @return int The number of removed UUIDs.
     * @throws InvalidUUIDException
     */
    public function remove(string ...$uuids): int
    {
        $removed = 0;
        foreach (static::keys($uuids) as $key) {
            if ($this->delete($this->root, $key)) {
                $removed++;
            }
        }
        $this->count -= $removed;

        return $removed;
    }

    /**
     * @throws InvalidUUIDException
     */
    public function contains(string $uuid): bool
    {
        $key = static::keys([$uuid])[0];
        $node = $this->root;

        while ('' !== $key) {
            $next = null;
            foreach ($node as $label => $child) {
                $edge = substr($label, 1);
                if (str_starts_with($key, $edge)) {
                    $next = $child;
                    $key = substr($key, strlen($edge));
                    break;
                }
            }
            if (null === $next) {
                return false;
            }
            if (true === $next) {
                return '' === $key;
            }
            $node = $next;
        }

        return false;
    }

    /**
     * Yields the UUIDs in ascending order.
     *
     * @param string|null $after Only UUIDs after this one, e.g. the last one of a previous iteration.
     * @return Generator<int, string>
     * @throws InvalidUUIDException
     */
    public function iterate(?string $after = null): Generator
    {
        $after = null === $after ? null : static::keys([$after])[0];
        foreach ($this->walk($this->root, '', $after) as $key) {
            yield static::format($key);
        }
    }

    /**
     * Returns the first UUIDs in ascending order.
     *
     * @param int $limit The maximum number of UUIDs.
     * @param string|null $after Only UUIDs after this one.
     * @return string[]
     * @throws InvalidUUIDException
     */
    public function first(int $limit, ?string $after = null): array
    {
        $result = [];
        if ($limit < 1) {
            return $result;
        }

        foreach ($this->iterate($after) as $uuid) {
            $result[] = $uuid;
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * @return Generator<int, string>
     * @throws InvalidUUIDException
     */
    public function getIterator(): Generator
    {
        return $this->iterate();
    }

    public function count(): int
    {
        return $this->count;
    }

    public function isEmpty(): bool
    {
        return 0 === $this->count;
    }

    /**
     * Removes all UUIDs.
     */
    public function clear(): void
    {
        $this->root = [];
        $this->count = 0;
    }

    /**
     * Stored as the trie itself, e.g. {"count": 2, "root": {"k3f": {"k1c…": true, "k9a…": true}}}.
     */
    public function __serialize(): array
    {
        return ['count' => $this->count, 'root' => $this->root];
    }

    public function __unserialize(array $data): void
    {
        $this->root = is_array($data['root'] ?? null) ? $data['root'] : [];
        $this->count = (int)($data['count'] ?? 0);
    }

    /**
     * Inserts the remaining digits of a key below a node.
     *
     * @return bool Whether the key was added (false if it was contained).
     */
    private function insert(array &$node, string $key): bool
    {
        foreach ($node as $label => &$child) {
            $edge = substr($label, 1);
            if ($edge[0] !== $key[0]) {
                continue; // the edges of a node start with different digits
            }

            $common = strspn($edge ^ substr($key, 0, strlen($edge)), "\0");

            if ($common === strlen($edge)) {
                if (true === $child) {
                    unset($child);
                    return false; // keys have the same length: the edge to a leaf is the rest of the key
                }
                $added = $this->insert($child, substr($key, $common));
                unset($child);
                return $added;
            }

            // the key leaves the edge in its middle: split the edge
            $split = [
                self::EDGE_PREFIX . substr($edge, $common) => $child,
                self::EDGE_PREFIX . substr($key, $common) => true,
            ];
            unset($child, $node[$label]);
            $node[self::EDGE_PREFIX . substr($edge, 0, $common)] = $split;

            return true;
        }
        unset($child);

        $node[self::EDGE_PREFIX . $key] = true;
        return true;
    }

    /**
     * Removes the remaining digits of a key below a node, merging a node left with one child into its edge.
     *
     * @return bool Whether the key was removed (false if it wasn't contained).
     */
    private function delete(array &$node, string $key): bool
    {
        foreach ($node as $label => &$child) {
            $edge = substr($label, 1);
            if ($edge[0] !== $key[0]) {
                continue;
            }

            if (!str_starts_with($key, $edge)) {
                unset($child);
                return false;
            }

            if (true === $child) {
                unset($child, $node[$label]);
                return true;
            }

            $removed = $this->delete($child, substr($key, strlen($edge)));

            // an inner node has at least two children: left with one, it is merged into the edge above it
            if ($removed && 1 === count($child)) {
                $onlyLabel = array_key_first($child);
                $only = $child[$onlyLabel];
                unset($child, $node[$label]);
                $node[self::EDGE_PREFIX . $edge . substr($onlyLabel, 1)] = $only;
                return true;
            }

            unset($child);
            return $removed;
        }
        unset($child);

        return false;
    }

    /**
     * @return Generator<int, string> The keys below a node in ascending order.
     */
    private function walk(array $node, string $prefix, ?string $after): Generator
    {
        $labels = array_keys($node);
        sort($labels, SORT_STRING);

        foreach ($labels as $label) {
            $path = $prefix . substr($label, 1);
            $childAfter = null;

            if (null !== $after) {
                $comparison = strcmp($path, substr($after, 0, strlen($path)));
                if ($comparison < 0) {
                    continue; // completely before $after
                }
                // only the branch on the path of $after contains keys before it, all later ones are after it
                $childAfter = 0 === $comparison ? $after : null;
            }

            if (true === $node[$label]) {
                if (null === $childAfter) {
                    yield $path;
                }
                continue; // a leaf equal to $after is skipped
            }

            yield from $this->walk($node[$label], $path, $childAfter);
        }
    }

    /**
     * The 32 hex digits of UUIDs, unique and sorted.
     *
     * @param string[] $uuids
     * @return string[]
     * @throws InvalidUUIDException
     */
    private static function keys(array $uuids): array
    {
        $keys = [];
        foreach ($uuids as $uuid) {
            $uuid = strtolower(trim($uuid));
            if (!Validator::validate($uuid)) {
                throw new InvalidUUIDException(sprintf('Invalid UUID: %s', $uuid));
            }
            $keys[str_replace('-', '', $uuid)] = true;
        }

        $keys = array_map('strval', array_keys($keys));
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * A UUID from its 32 hex digits.
     */
    private static function format(string $key): string
    {
        return sprintf('%s-%s-%s-%s-%s', substr($key, 0, 8), substr($key, 8, 4), substr($key, 12, 4), substr($key, 16, 4), substr($key, 20));
    }
}
