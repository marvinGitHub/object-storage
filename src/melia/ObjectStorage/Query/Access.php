<?php

namespace melia\ObjectStorage\Query;

use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Query\Ast\Binary;
use melia\ObjectStorage\Query\Ast\BoundColumn;
use melia\ObjectStorage\Query\Ast\Expr;
use melia\ObjectStorage\Query\Ast\InList;
use melia\ObjectStorage\Query\Ast\Literal;
use melia\ObjectStorage\Query\Ast\Source;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\IndexInterface;
use melia\ObjectStorage\Search\Index\ManagerInterface;
use melia\ObjectStorage\Search\Request;
use melia\ObjectStorage\Unique\Manager as UniqueManager;
use melia\ObjectStorage\UUID\Validator as UuidValidator;
use Throwable;

/**
 * How the objects of a source are found: instead of reading all objects of the class, those an index names for the
 * condition. An index is only used where it names every object the condition is true for (more don't matter: every
 * object is checked against the whole condition after), so a query finds the same with or without it.
 *
 * Conditions joined by AND at the top of WHERE are looked at:
 * - UUID = 'uuid' or UUID IN (…): those objects, no index needed;
 * - path = 'text' or path IN ('text', …) on the path of a unique index (one path, also case-insensitive);
 * - the same on a filterable string field of a search index of Loupe that is built and has no pending changes
 *   (several such conditions are searched together).
 * Texts that are numbers aren't looked up ('12' equals the number 12 in OQL, which the indexes store otherwise),
 * nor are other comparisons, LIKE or number fields: the indexes convert values differently than OQL compares them.
 * An index naming more than MAX_CANDIDATES objects isn't used. Writes the indexes didn't see (without the managers
 * attached) aren't found by them, as by any search.
 *
 * @internal Not part of the public API; may change in any release.
 */
final class Access
{
    /** the most objects an index may name to be used (Loupe returns at most 1,000 hits by default) */
    public const int MAX_CANDIDATES = 999;

    public function __construct(
        private readonly ObjectStorage     $storage,
        private readonly ?ManagerInterface $search,
        private readonly ?UniqueManager    $unique,
        private readonly bool              $enabled = true,
    )
    {
    }

    /**
     * @param bool $explain Whether only to describe it: the uuids are still looked up, to tell how many.
     */
    public function choose(Source $source, string $class, ?Expr $where, bool $explain = false): AccessChoice
    {
        $all = new AccessChoice('all objects of the class', null === $where ? 'no condition' : 'no index fits the condition');
        if (!$this->enabled) {
            return new AccessChoice('all objects of the class', 'indexes not used');
        }
        if (null === $where) {
            return $all;
        }

        // [path, list of values] of the conditions an index may answer; path [] is the UUID
        $lookups = [];
        foreach (self::conditions($where) as $condition) {
            $lookup = self::lookup($condition, $source->alias);
            if (null !== $lookup) {
                $lookups[] = $lookup;
            }
        }
        if ([] === $lookups) {
            return $all;
        }

        // the UUIDs themselves
        foreach ($lookups as [$path, $values]) {
            if ([] === $path) {
                $uuids = array_values(array_unique(array_filter($values, [UuidValidator::class, 'validate'])));
                return new AccessChoice('UUID', sprintf('%d object(s) by their UUID', count($uuids)), $uuids);
            }
        }

        // texts that are numbers are equal to numbers in OQL, which the indexes keep apart
        $lookups = array_values(array_filter($lookups, static fn(array $lookup): bool => [] === array_filter($lookup[1], 'is_numeric')));

        try {
            $choice = $this->byUniqueIndex($class, $lookups) ?? $this->bySearchIndex($class, $lookups);
        } catch (Throwable $e) {
            $this->storage->getLogger()?->log($e);
            $choice = null;
        }
        return $choice ?? $all;
    }

    /**
     * The conditions joined by AND at the top.
     *
     * @return list<Expr>
     */
    private static function conditions(Expr $where): array
    {
        return $where instanceof Binary && 'AND' === $where->operator
            ? [...self::conditions($where->left), ...self::conditions($where->right)]
            : [$where];
    }

    /**
     * path = 'text', 'text' = path, path IN ('text', …) of the source; UUID = '…' and UUID IN (…).
     *
     * @return array{0: list<string>, 1: list<string>}|null
     */
    private static function lookup(Expr $condition, string $alias): ?array
    {
        $column = null;
        $values = [];
        if ($condition instanceof Binary && '=' === $condition->operator) {
            [$column, $value] = $condition->left instanceof BoundColumn ? [$condition->left, $condition->right] : [$condition->right, $condition->left];
            $values = [$value];
        } elseif ($condition instanceof InList && !$condition->negated) {
            [$column, $values] = [$condition->operand, $condition->values];
        }
        if (!$column instanceof BoundColumn || $alias !== $column->alias || ($column->uuid && [] !== $column->path)) {
            return null;
        }
        $texts = [];
        foreach ($values as $value) {
            if (!$value instanceof Literal || !is_string($value->value)) {
                return null;
            }
            $texts[] = $value->value;
        }
        return [$column->path, $texts];
    }

    private function byUniqueIndex(string $class, array $lookups): ?AccessChoice
    {
        $unique = $this->unique ?? new UniqueManager($this->storage);
        foreach ($unique->getIndexesForClass($class) as $definition) {
            // a single path, and the keys of all objects claimed (not while creating or rebuilding)
            if (1 !== count($definition->paths) || !$unique->isComplete($definition->name)) {
                continue;
            }
            foreach ($lookups as [$path, $values]) {
                if (implode('.', $path) !== $definition->paths[0]) {
                    continue;
                }
                $uuids = [];
                foreach (array_unique($values) as $value) {
                    $owner = $unique->findOwner($definition->name, $value);
                    if (null !== $owner) {
                        $uuids[] = $owner;
                    }
                }
                return new AccessChoice('unique index', sprintf('%s: %s IN (%d value(s)), %d object(s)', $definition->name, $definition->paths[0], count($values), count($uuids)), array_values(array_unique($uuids)));
            }
        }
        return null;
    }

    private function bySearchIndex(string $class, array $lookups): ?AccessChoice
    {
        if (null === $this->search) {
            return null;
        }
        foreach ($this->search->list() as $name => $index) {
            // only an index of the class that knows of every change: built, nothing pending
            if ($index->getDefinition()->className !== $class || 'loupe' !== $index->getEngineName() || IndexInterface::STATUS_READY !== $index->getStatus() || 0 !== $index->countPendingChanges()) {
                continue;
            }
            $definition = $index->getActiveDefinition();
            if (null === $definition) {
                continue;
            }
            $fields = [];
            foreach ($definition->fields as $field) {
                if ($field->filterable && FieldType::STRING === $field->type) {
                    $fields[$field->path] = $field;
                }
            }
            $filters = [];
            foreach ($lookups as [$path, $values]) {
                $field = $fields[implode('.', $path)] ?? null;
                if ($field instanceof Field) {
                    $quoted = array_map(static fn(string $value): string => "'" . str_replace("'", "''", $value) . "'", array_values(array_unique($values)));
                    $filters[] = sprintf('%s IN (%s)', $field->name, implode(', ', $quoted));
                }
            }
            if ([] === $filters) {
                continue;
            }
            $filter = implode(' AND ', $filters);
            $result = $index->search(new Request(filter: $filter, hitsPerPage: self::MAX_CANDIDATES + 1));
            $uuids = $result->getUuids();
            if (count($uuids) > self::MAX_CANDIDATES || $result->totalHits > count($uuids)) {
                return new AccessChoice('all objects of the class', sprintf('search index %s names more than %d objects for %s', $name, self::MAX_CANDIDATES, $filter));
            }
            return new AccessChoice('search index', sprintf('%s: %s, %d object(s)', $name, $filter, count($uuids)), $uuids);
        }
        return null;
    }
}
