<?php

namespace melia\ObjectStorage\Query;

use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Path\Path;
use melia\ObjectStorage\Path\PathSegment;
use melia\ObjectStorage\Query\Ast\Between;
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
 * Conditions joined by AND at the top of WHERE, comparing a path of the source with values (literals, parameters):
 * - UUID = 'uuid' or UUID IN (…): those objects, no index needed;
 * - path = 'text' or path IN ('text', …) on the path of a unique index (one path, also case-insensitive);
 * - on a filterable field with the path of a search index of Loupe that is built and has no pending changes (several
 *   conditions are searched together):
 *   - string fields: = and IN with texts; for a path with * (friends.*.name = 'Alex', true if any element is) a
 *     field of a list of strings;
 *   - number fields: =, IN, <, <=, >, >=, BETWEEN with numbers;
 *   - date fields: the same with dates (parameters) or texts that are dates;
 *   - boolean fields: = TRUE, = FALSE (or 1, 0).
 *   Number, date and boolean conditions also name the objects whose value the field couldn't convert (field IS
 *   NULL): OQL compares values of every type (a text is greater than any number), the field only has those of its
 *   type.
 * Texts that are numbers aren't looked up as texts ('12' equals the number 12 in OQL, which the indexes store
 * otherwise), nor are dates as texts, LIKE, OR or negations. An index naming more than MAX_CANDIDATES objects isn't
 * used. Writes the indexes didn't see (without the managers attached) aren't found by them, as by any search.
 *
 * @internal Not part of the public API; may change in any release.
 */
final class Access
{
    /** the most objects an index may name to be used (Loupe returns at most 1,000 hits by default) */
    public const int MAX_CANDIDATES = 999;

    /** the comparison for a value on the left: 10 < price is price > 10 */
    private const array FLIPPED = ['=' => '=', '<' => '>', '<=' => '>=', '>' => '<', '>=' => '<='];

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
        foreach ($lookups as $lookup) {
            if ($lookup['uuid'] && [] === $lookup['path'] && in_array($lookup['operator'], ['=', 'IN'], true)) {
                $uuids = array_values(array_unique(array_filter($lookup['values'], static fn(mixed $v): bool => is_string($v) && UuidValidator::validate($v))));
                return new AccessChoice('UUID', sprintf('%d object(s) by their UUID', count($uuids)), $uuids);
            }
        }
        $lookups = array_values(array_filter($lookups, static fn(array $lookup): bool => !$lookup['uuid']));

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
     * A path of the source compared with values: path = v, v = path, path < v (and the others), v < path,
     * path IN (v, …), path BETWEEN v AND w; UUID = '…' and UUID IN (…).
     *
     * @return array{path: list<string|PathSegment>, uuid: bool, operator: string, values: list<mixed>}|null
     *         operator: =, IN, <, <=, >, >=, BETWEEN; values: the values of the literals
     */
    private static function lookup(Expr $condition, string $alias): ?array
    {
        $column = null;
        $values = [];
        $operator = null;
        if ($condition instanceof Binary && isset(self::FLIPPED[$condition->operator])) {
            if ($condition->left instanceof BoundColumn) {
                [$column, $values, $operator] = [$condition->left, [$condition->right], $condition->operator];
            } else {
                [$column, $values, $operator] = [$condition->right, [$condition->left], self::FLIPPED[$condition->operator]];
            }
        } elseif ($condition instanceof InList && !$condition->negated) {
            [$column, $values, $operator] = [$condition->operand, $condition->values, 'IN'];
        } elseif ($condition instanceof Between && !$condition->negated) {
            [$column, $values, $operator] = [$condition->operand, [$condition->low, $condition->high], 'BETWEEN'];
        }
        if (!$column instanceof BoundColumn || $alias !== $column->alias || ($column->uuid && [] !== $column->path) || (!$column->uuid && [] === $column->path)) {
            return null;
        }
        $literals = [];
        foreach ($values as $value) {
            if (!$value instanceof Literal || null === $value->value) {
                return null;
            }
            $literals[] = $value->value;
        }
        return ['path' => $column->path, 'uuid' => $column->uuid, 'operator' => $operator, 'values' => $literals];
    }

    /**
     * The values of = and IN as texts an index has as they are: not numbers ('12' = 12 in OQL) nor dates.
     *
     * @return list<string>|null
     */
    private static function texts(array $lookup): ?array
    {
        if (!in_array($lookup['operator'], ['=', 'IN'], true)) {
            return null;
        }
        foreach ($lookup['values'] as $value) {
            if (!is_string($value) || is_numeric($value) || null !== DateValue::fromText($value)) {
                return null;
            }
        }
        return array_values(array_unique($lookup['values']));
    }

    private function byUniqueIndex(string $class, array $lookups): ?AccessChoice
    {
        $unique = $this->unique ?? new UniqueManager($this->storage);
        foreach ($unique->getIndexesForClass($class) as $definition) {
            // a single path, and the keys of all objects claimed (not while creating or rebuilding)
            if (1 !== count($definition->paths) || !$unique->isComplete($definition->name)) {
                continue;
            }
            foreach ($lookups as $lookup) {
                $values = self::texts($lookup);
                // unique indexes have single values; a path with * names several
                if (null === $values || Path::hasWildcard($lookup['path']) || Path::toString($lookup['path']) !== $definition->paths[0]) {
                    continue;
                }
                $uuids = [];
                foreach ($values as $value) {
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
            // the filterable fields by their paths, written the same way (friends[0].name, friends.*.name)
            $fields = [];
            foreach ($definition->fields as $field) {
                if (!$field->filterable) {
                    continue;
                }
                try {
                    $fields[Path::toString($field->getPathSegments())][] = $field;
                } catch (Throwable) {
                    continue;
                }
            }
            $filters = [];
            foreach ($lookups as $lookup) {
                foreach ($fields[Path::toString($lookup['path'])] ?? [] as $field) {
                    $filter = self::filter($field, $lookup);
                    if (null !== $filter) {
                        $filters[] = $filter;
                        break;
                    }
                }
            }
            if ([] === $filters) {
                continue;
            }
            $filter = implode(' AND ', array_values(array_unique($filters)));
            $result = $index->search(new Request(filter: $filter, hitsPerPage: self::MAX_CANDIDATES + 1));
            $uuids = $result->getUuids();
            if (count($uuids) > self::MAX_CANDIDATES || $result->totalHits > count($uuids)) {
                return new AccessChoice('all objects of the class', sprintf('search index %s names more than %d objects for %s', $name, self::MAX_CANDIDATES, $filter));
            }
            return new AccessChoice('search index', sprintf('%s: %s, %d object(s)', $name, $filter, count($uuids)), $uuids);
        }
        return null;
    }

    /**
     * The filter of Loupe naming every object the condition can be true for, by the type of the field; null if the
     * field can't tell.
     */
    private static function filter(Field $field, array $lookup): ?string
    {
        $wildcard = Path::hasWildcard($lookup['path']);
        $name = $field->name;
        $operator = $lookup['operator'];

        // texts: exactly as stored; lists of texts for paths with * (true if any element is)
        if ((FieldType::STRING === $field->type && !$wildcard) || (FieldType::STRING_ARRAY === $field->type && $wildcard)) {
            $texts = self::texts($lookup);
            return null === $texts ? null : sprintf('%s IN (%s)', $name, implode(', ', array_map([self::class, 'quote'], $texts)));
        }
        if ($wildcard) {
            return null;
        }

        $values = match ($field->type) {
            FieldType::NUMBER => array_map([self::class, 'number'], $lookup['values']),
            FieldType::DATE => array_map([self::class, 'date'], $lookup['values']),
            FieldType::BOOLEAN => array_map([self::class, 'boolean'], $lookup['values']),
            default => [null],
        };
        if (in_array(null, $values, true)) {
            return null;
        }
        if (FieldType::BOOLEAN === $field->type && ('=' !== $operator || '' === $values[0])) {
            return null;
        }

        $condition = match ($operator) {
            '=' => sprintf('%s = %s', $name, $values[0]),
            'IN' => '(' . implode(' OR ', array_map(static fn(string $v): string => sprintf('%s = %s', $name, $v), array_values(array_unique($values)))) . ')',
            'BETWEEN' => sprintf('(%1$s >= %2$s AND %1$s <= %3$s)', $name, $values[0], $values[1]),
            default => sprintf('%s %s %s', $name, $operator, $values[0]),
        };
        // values the field couldn't convert (a text in a number field) are compared by OQL all the same
        return sprintf('(%s OR %s IS NULL)', $condition, $name);
    }

    private static function quote(string $text): string
    {
        return "'" . str_replace("'", "''", $text) . "'";
    }

    /** a number as Loupe reads it, exactly (no exponent); null for anything else */
    private static function number(mixed $value): ?string
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        if (is_float($value) && !is_finite($value)) {
            return null;
        }
        $text = is_int($value) ? (string)$value : (string)json_encode($value);
        return 1 === preg_match('/^-?\d+(\.\d+)?$/', $text) ? $text : null;
    }

    /** a date (or a text that is one) as a text the engine converts for date fields; null for anything else */
    private static function date(mixed $value): ?string
    {
        $date = $value instanceof DateValue ? $value : (is_string($value) ? DateValue::fromText($value) : null);
        return null === $date ? null : self::quote($date->utc);
    }

    /** true or false; '' for a value that isn't one (2 equals no boolean), null for anything else */
    private static function boolean(mixed $value): ?string
    {
        return match (true) {
            true === $value, 1 === $value => 'true',
            false === $value, 0 === $value => 'false',
            is_int($value) || is_float($value) => '',
            default => null,
        };
    }
}
