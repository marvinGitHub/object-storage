<?php

namespace melia\ObjectStorage\Query;

use Generator;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Query\Ast\Between;
use melia\ObjectStorage\Query\Ast\Binary;
use melia\ObjectStorage\Query\Ast\BoundColumn;
use melia\ObjectStorage\Query\Ast\Expr;
use melia\ObjectStorage\Query\Ast\FunctionCall;
use melia\ObjectStorage\Query\Ast\InList;
use melia\ObjectStorage\Query\Ast\IsNull;
use melia\ObjectStorage\Query\Ast\Join;
use melia\ObjectStorage\Query\Ast\Like;
use melia\ObjectStorage\Query\Ast\Literal;
use melia\ObjectStorage\Query\Ast\OutputColumn;
use melia\ObjectStorage\Query\Ast\Source;
use melia\ObjectStorage\Query\Ast\Unary;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Search\DocumentBuilder;
use melia\ObjectStorage\Search\Index\ManagerInterface;
use melia\ObjectStorage\Transfer\RawObject;
use melia\ObjectStorage\Unique\Manager as UniqueManager;
use melia\ObjectStorage\UUID\Validator as UuidValidator;
use SplHeap;
use Throwable;

/**
 * Runs a plan on the objects of a storage, as stored (their data, not loaded objects: the classes aren't needed).
 *
 * Memory stays bounded by what the result needs, not by the number of objects: the objects of the source are read
 * one after another and dropped; WHERE filters them as they come; without ORDER BY, reading stops at LIMIT; ORDER BY
 * with LIMIT keeps only the rows that may be in the result; GROUP BY keeps per group its first row and the running
 * values of the aggregates, not its rows. What is kept: the joined sources (of their properties only those the query
 * reads), the groups, the rows of the result, and a bounded number of referenced objects.
 *
 * The objects of the source are found by an index where one fits the condition (see Access), otherwise all objects
 * of the class are read. A path follows references (at most DocumentBuilder::MAX_REFERENCES_PER_PATH), a referenced
 * object that doesn't exist or has expired gives NULL; expired objects aren't read.
 *
 * @internal Not part of the public API; may change in any release.
 */
final class Executor
{
    /** the most referenced objects kept while a query runs, for paths into them */
    public const int MAX_REFERENCED = 10000;

    /** @var array<string, ?array{uuid: string, data: array, reserved: string}> referenced objects read, by UUID */
    private array $referenced = [];

    /** @var list<string>|null */
    private ?array $classNames = null;

    private \Closure $follow;

    private Access $access;

    public function __construct(
        private readonly ObjectStorage $storage,
        ?ManagerInterface              $search = null,
        ?UniqueManager                 $unique = null,
        bool                           $useIndexes = true,
    )
    {
        $this->access = new Access($storage, $useIndexes ? $search : null, $useIndexes ? $unique : null, $useIndexes);
        $this->follow = function (string $uuid): ?array {
            if (!array_key_exists($uuid, $this->referenced)) {
                if (count($this->referenced) >= self::MAX_REFERENCED) {
                    // the older half goes
                    $this->referenced = array_slice($this->referenced, intdiv(self::MAX_REFERENCED, 2), null, true);
                }
                $this->referenced[$uuid] = $this->read($uuid);
            }
            $object = $this->referenced[$uuid];
            return null === $object ? null : [$object['data'], $object['reserved']];
        };
    }

    /**
     * @param int|null $maxRows At most this many rows (e.g. to show); the result tells whether there were more.
     * @throws QueryException
     */
    public function run(Plan $plan, ?int $maxRows = null): Result
    {
        $this->referenced = [];
        $keep = self::keysUsed($plan);
        $rows = $this->rows($plan, $keep);

        // at most offset + limit rows are needed, one more to tell whether the result has more than $maxRows
        $limit = $plan->limit;
        if (null !== $maxRows) {
            $limit = min($limit ?? PHP_INT_MAX, $maxRows + 1);
        }
        $needed = null === $limit ? null : $plan->offset + $limit;

        $results = $plan->grouped
            ? $this->grouped($plan, $rows, $keep)
            : $this->ungrouped($plan, $rows, $needed);

        if ([] !== $plan->orderBy) {
            usort($results, fn(array $a, array $b): int => $this->compareResults($plan, $a, $b));
        }
        $results = array_slice($results, $plan->offset, $limit);
        $truncated = null !== $maxRows && count($results) > $maxRows;
        if ($truncated) {
            $results = array_slice($results, 0, $maxRows);
        }

        // * and alias.*: the properties of the objects of the result, in the order they come first
        [$names, $values] = $this->project($plan, $results);
        return new Result($names, $values, null, false, $truncated);
    }

    /**
     * How the objects of the source of a plan are found: [source, access, details], e.g. ['p', 'search index', …].
     */
    public function explain(Plan $plan): Result
    {
        $rows = [];
        $access = $this->access->choose($plan->from, $this->classOf($plan->from), $plan->where, true);
        $rows[] = [$plan->from->alias, $this->classOf($plan->from), $access->kind, $access->details];
        foreach ($plan->joins as $join) {
            $rows[] = [$join['source']->alias, $this->classOf($join['source']), 'all objects (joined)', null === $join['hash']
                ? 'every pair is tried'
                : 'by hashing: ' . Printer::print($join['hash'][1]) . ' = ' . Printer::print($join['hash'][0])];
        }
        return new Result(['source', 'class', 'access', 'details'], $rows);
    }

    // ---- rows ----

    /**
     * The rows of a plan, one after another: the objects of the source (by an index or all), joined, filtered.
     *
     * @param array<string, ?array<string, true>> $keep The properties to keep per alias of joined objects (null: all).
     * @return Generator<array<string, ?array>>
     */
    private function rows(Plan $plan, array $keep): Generator
    {
        $rows = $this->objectsOf($plan->from, $plan->where);
        $rows = (static function () use ($rows, $plan): Generator {
            foreach ($rows as $object) {
                yield [$plan->from->alias => $object];
            }
        })();
        foreach ($plan->joins as $join) {
            $alias = $join['source']->alias;
            $rows = $this->join($rows, $join, array_key_exists($alias, $keep) ? $keep[$alias] : []);
        }
        foreach ($rows as $row) {
            if (null === $plan->where || true === Values::truth($this->evaluate($plan->where, $row))) {
                yield $row;
            }
        }
    }

    /**
     * The objects of a source, one after another: those an index finds for the condition (checked by the caller
     * anyway), or all of the class.
     *
     * @return Generator<array{uuid: string, data: array, reserved: string}>
     */
    private function objectsOf(Source $source, ?Expr $where): Generator
    {
        $class = $this->classOf($source);
        $access = $this->access->choose($source, $class, $where);
        $uuids = $access->uuids ?? $this->storage->list($class);
        foreach ($uuids as $uuid) {
            $object = $this->read((string)$uuid);
            // an index may name an object of another class (a unique index of a parent class, a UUID)
            if (null !== $object && (null === $access->uuids || $this->storage->getClassName($object['uuid']) === $class)) {
                yield $object;
            }
        }
    }

    /**
     * The rows of the objects of a source a condition is true for, one after another (for UPDATE and DELETE).
     *
     * @return Generator<array<string, array>>
     */
    public function matching(Source $source, ?Expr $where): Generator
    {
        $this->referenced = [];
        foreach ($this->objectsOf($source, $where) as $object) {
            $row = [$source->alias => $object];
            if (null === $where || true === Values::truth($this->evaluate($where, $row))) {
                yield $row;
            }
        }
    }

    /**
     * An object as stored; null if it doesn't exist, has expired or can't be read. Not kept: reading many objects
     * leaves nothing behind (the caches of the storage are bounded too).
     */
    private function read(string $uuid): ?array
    {
        try {
            return self::objectOf($this->storage->readRaw($uuid));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * An object of a row: its UUID, data and reserved reference name; null if expired or not readable.
     *
     * @return array{uuid: string, data: array, reserved: string}|null
     */
    public static function objectOf(RawObject $raw): ?array
    {
        $data = $raw->isExpired() ? null : json_decode($raw->data, true);
        return is_array($data) ? ['uuid' => $raw->uuid, 'data' => $data, 'reserved' => $raw->metadata->getReservedReferenceName()] : null;
    }

    /**
     * The class of a source: its full name, or its short name if one stored class has it.
     */
    public function classOf(Source $source): string
    {
        $this->classNames ??= $this->storage->getClassNames();
        $name = $source->class;
        if (in_array($name, $this->classNames, true)) {
            return $name;
        }
        $matches = array_values(array_filter($this->classNames, static fn(string $class): bool => 0 === strcasecmp($class, $name)
            || 0 === strcasecmp(substr(strrchr('\\' . $class, '\\'), 1), $name)));
        if (1 === count($matches)) {
            return $matches[0];
        }
        if (count($matches) > 1) {
            throw new QueryException(sprintf('"%s" is ambiguous: %s; write the full name in backticks, e.g. `%s`', $name, implode(', ', $matches), $matches[0]), $source->position);
        }
        if (class_exists($name)) {
            return $name; // no objects of it are stored
        }
        throw new QueryException(sprintf('Unknown class "%s": no objects of it are stored', $name), $source->position);
    }

    // ---- what is kept ----

    /**
     * The properties a plan reads of each alias: the first names of its paths; null for all (alias.*, *).
     *
     * @return array<string, ?array<string, true>>
     */
    private static function keysUsed(Plan $plan): array
    {
        $keys = array_fill_keys($plan->aliases(), []);
        $collect = static function (?Expr $expression) use (&$collect, &$keys): void {
            if (null === $expression) {
                return;
            }
            if ($expression instanceof BoundColumn && [] !== $expression->path && is_array($keys[$expression->alias] ?? null)) {
                $keys[$expression->alias][$expression->path[0]] = true;
            }
            foreach ($expression->children() as $child) {
                $collect($child);
            }
        };
        foreach ($plan->items as $item) {
            if (null === $item['expression']) {
                foreach ('*' === $item['starOf'] ? $plan->aliases() : [$item['starOf']] as $alias) {
                    $keys[$alias] = null;
                }
            }
            $collect($item['expression']);
        }
        foreach ($plan->joins as $join) {
            $collect($join['on']);
        }
        foreach ([$plan->where, $plan->having, ...$plan->groupBy, ...array_column($plan->orderBy, 0)] as $expression) {
            $collect($expression);
        }
        return $keys;
    }

    /**
     * An object with only the properties a query reads (references among them still lead to their objects).
     *
     * @param ?array<string, true> $keys null: all
     */
    private static function stripped(?array $object, ?array $keys): ?array
    {
        if (null === $object || null === $keys) {
            return $object;
        }
        $object['data'] = array_intersect_key($object['data'], $keys);
        return $object;
    }

    // ---- joining ----

    /**
     * Joins the rows with the objects of a source, which are kept (only the properties the query reads): by
     * hashing for an equality of ON, otherwise trying every pair.
     *
     * @param iterable<array<string, ?array>> $rows
     * @param array{type: string, source: Source, on: Expr, hash: ?array{0: Expr, 1: Expr}} $join
     * @param ?array<string, true> $keep
     * @return Generator<array<string, ?array>>
     */
    private function join(iterable $rows, array $join, ?array $keep): Generator
    {
        $alias = $join['source']->alias;
        $objects = [];
        $buckets = null === $join['hash'] ? null : [];
        foreach ($this->objectsOf($join['source'], null) as $object) {
            $object = self::stripped($object, $keep);
            if (null === $buckets) {
                $objects[] = $object;
                continue;
            }
            // the joined objects by the value they are compared with; NULL and arrays never equal anything
            $value = Values::normalize($this->evaluate($join['hash'][1], [$alias => $object]));
            if (null !== $value && !is_array($value)) {
                $buckets[Values::equalityKey($value)][] = $object;
            }
        }

        foreach ($rows as $row) {
            if (null === $buckets) {
                $candidates = $objects;
            } else {
                $value = Values::normalize($this->evaluate($join['hash'][0], $row));
                $candidates = null === $value || is_array($value) ? [] : ($buckets[Values::equalityKey($value)] ?? []);
            }
            $matched = false;
            foreach ($candidates as $object) {
                $candidate = $row + [$alias => $object];
                if (true === Values::truth($this->evaluate($join['on'], $candidate))) {
                    $matched = true;
                    yield $candidate;
                }
            }
            if (!$matched && Join::LEFT === $join['type']) {
                yield $row + [$alias => null];
            }
        }
    }

    // ---- results: [values of the selection, values to sort by, objects for *, position] ----

    /**
     * The results of rows without grouping: all of them, or with LIMIT and without ORDER BY and DISTINCT only the
     * first needed; with ORDER BY and LIMIT only the needed ones that sort first.
     */
    private function ungrouped(Plan $plan, iterable $rows, ?int $needed): array
    {
        $heap = null;
        if (null !== $needed && [] !== $plan->orderBy) {
            // the needed rows that sort first: the greatest is dropped when there are more
            $compare = fn(array $a, array $b): int => $this->compareResults($plan, $a, $b);
            $heap = new class($compare) extends SplHeap {
                public function __construct(private readonly \Closure $compare)
                {
                }

                protected function compare(mixed $value1, mixed $value2): int
                {
                    return ($this->compare)($value1, $value2);
                }
            };
        }

        $results = [];
        $seen = [];
        $position = 0;
        if (0 === $needed) {
            return [];
        }
        foreach ($rows as $row) {
            $result = $this->result($plan, $row, null, $position++);
            if ($plan->distinct) {
                $key = implode("\0", array_map([Values::class, 'key'], $result[0])) . "\0" . json_encode($result[2]);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
            }
            if (null !== $heap) {
                $heap->insert($result);
                if ($heap->count() > $needed) {
                    $heap->extract();
                }
                continue;
            }
            $results[] = $result;
            if (null !== $needed && [] === $plan->orderBy && count($results) >= $needed) {
                break; // enough
            }
        }
        return null === $heap ? $results : iterator_to_array($heap, false);
    }

    /**
     * The results of groups: per group (in the order the groups come first) its first row and the running values
     * of its aggregates, not its rows. Without GROUP BY, one group, also of no rows.
     */
    private function grouped(Plan $plan, iterable $rows, array $keep): array
    {
        $aggregates = [];
        $collect = static function (?Expr $expression) use (&$collect, &$aggregates): void {
            if (null === $expression) {
                return;
            }
            if ($expression instanceof FunctionCall && $expression->isAggregate()) {
                $aggregates[spl_object_id($expression)] = $expression;
                return;
            }
            foreach ($expression->children() as $child) {
                $collect($child);
            }
        };
        foreach ([...array_column($plan->items, 'expression'), $plan->having, ...array_column($plan->orderBy, 0)] as $expression) {
            $collect($expression);
        }

        $groups = [];
        foreach ($rows as $row) {
            $key = implode("\0", array_map(fn(Expr $expression): string => Values::key($this->evaluate($expression, $row)), $plan->groupBy));
            if (!isset($groups[$key])) {
                $first = [];
                foreach ($row as $alias => $object) {
                    $first[$alias] = self::stripped($object, array_key_exists($alias, $keep) ? $keep[$alias] : []);
                }
                $groups[$key] = ['row' => $first, 'states' => array_map(static fn() => self::newState(), $aggregates)];
            }
            foreach ($aggregates as $id => $aggregate) {
                $this->accumulate($aggregate, $groups[$key]['states'][$id], $row);
            }
        }
        if ([] === $plan->groupBy && [] === $groups) {
            $groups[] = ['row' => array_fill_keys($plan->aliases(), null), 'states' => array_map(static fn() => self::newState(), $aggregates)];
        }

        $results = [];
        $position = 0;
        foreach ($groups as $group) {
            if (null === $plan->having || true === Values::truth($this->evaluate($plan->having, $group['row'], $group['states']))) {
                $results[] = $this->result($plan, $group['row'], $group['states'], $position++);
            }
        }
        return $results;
    }

    /**
     * The result of a row or group: the values of the selection (without *), the values to sort by, the objects
     * of the aliases of * and alias.*, the position (equal rows keep their order).
     */
    private function result(Plan $plan, array $row, ?array $states, int $position): array
    {
        $values = [];
        $stars = [];
        foreach ($plan->items as $i => $item) {
            if (null === $item['expression']) {
                foreach ('*' === $item['starOf'] ? $plan->aliases() : [$item['starOf']] as $alias) {
                    $stars[$alias] = $row[$alias]['data'] ?? null;
                }
                continue;
            }
            $values[$i] = $this->evaluate($item['expression'], $row, $states);
        }
        $keys = array_map(fn(array $order): mixed => $order[0] instanceof OutputColumn
            ? $values[$order[0]->index] ?? null
            : $this->evaluate($order[0], $row, $states), $plan->orderBy);
        // the references of * are read with the row
        foreach ($stars as $alias => $data) {
            if (null !== $data) {
                $stars[$alias] = ['data' => $data, 'reserved' => $row[$alias]['reserved'], 'uuid' => $row[$alias]['uuid']];
            }
        }
        return [$values, $keys, $stars, $position];
    }

    private function compareResults(Plan $plan, array $a, array $b): int
    {
        foreach ($plan->orderBy as $i => [, $descending]) {
            $order = Values::sortOrder($a[1][$i], $b[1][$i]);
            if (0 !== $order) {
                return $descending ? -$order : $order;
            }
        }
        return $a[3] <=> $b[3];
    }

    /**
     * The names and rows of the result; * and alias.* as the properties of the objects of the result, in the order
     * they come first.
     *
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function project(Plan $plan, array $results): array
    {
        $aliases = $plan->aliases();
        $columns = []; // [name, item index, alias or null, property or null]
        foreach ($plan->items as $i => $item) {
            if (null !== $item['expression']) {
                $columns[] = [$item['name'], $i, null, null];
                continue;
            }
            foreach ('*' === $item['starOf'] ? $aliases : [$item['starOf']] as $alias) {
                $properties = [];
                foreach ($results as $result) {
                    foreach (array_keys($result[2][$alias]['data'] ?? []) as $property) {
                        $properties[(string)$property] = true;
                    }
                }
                foreach (array_keys($properties) as $property) {
                    $columns[] = [1 === count($aliases) ? (string)$property : $alias . '.' . $property, $i, $alias, (string)$property];
                }
            }
        }

        $rows = [];
        foreach ($results as $result) {
            $row = [];
            foreach ($columns as [, $i, $alias, $property]) {
                if (null === $alias) {
                    $row[] = $result[0][$i];
                    continue;
                }
                $object = $result[2][$alias] ?? null;
                $row[] = null === $object || !array_key_exists($property, $object['data'])
                    ? null
                    : DocumentBuilder::extract($object['data'], [$property], $object['reserved'], $this->follow);
            }
            $rows[] = $row;
        }
        return [array_column($columns, 0), $rows];
    }

    // ---- expressions ----

    /**
     * The value of an expression for a row; for a group (grouped queries), $group holds the running values of its
     * aggregates (by the id of the aggregate) and the row is its first.
     */
    public function evaluate(Expr $expression, array $row, ?array $group = null): mixed
    {
        $value = fn(Expr $child): mixed => $this->evaluate($child, $row, $group);

        switch (true) {
            case $expression instanceof Literal:
                return $expression->value;
            case $expression instanceof BoundColumn:
                $object = $row[$expression->alias] ?? null;
                if (null === $object) {
                    return null;
                }
                if ([] === $expression->path) {
                    return $object['uuid'];
                }
                $found = DocumentBuilder::extract($object['data'], $expression->path, $object['reserved'], $this->follow);
                // p.category.UUID: a reference there is its UUID; anything else isn't one
                if ($expression->uuid) {
                    return is_string($found) && UuidValidator::validate($found) ? $found : null;
                }
                return $found;
            case $expression instanceof FunctionCall:
                if ('REF' === $expression->name) {
                    $uuid = Values::text($value($expression->arguments[0]));
                    return null === $uuid ? null : new Reference($uuid);
                }
                return $expression->isAggregate()
                    ? $this->aggregate($expression, $group ?? throw new QueryException(sprintf('%s needs a group', $expression->name)))
                    : $this->function($expression->name, array_map($value, $expression->arguments));
            case $expression instanceof Unary:
                $operand = $value($expression->operand);
                if ('NOT' === $expression->operator) {
                    return Values::not(Values::truth($operand));
                }
                $number = Values::number($operand);
                return null === $number ? null : -$number;
            case $expression instanceof Binary:
                return $this->binary($expression, $value);
            case $expression instanceof IsNull:
                return (null === $value($expression->operand)) !== $expression->negated;
            case $expression instanceof InList:
                $operand = $value($expression->operand);
                $result = false;
                foreach ($expression->values as $candidate) {
                    $result = Values::or($result, self::equals($operand, $value($candidate)));
                    if (true === $result) {
                        break;
                    }
                }
                return $expression->negated ? Values::not($result) : $result;
            case $expression instanceof Between:
                $operand = $value($expression->operand);
                $low = Values::compare($operand, $value($expression->low));
                $high = Values::compare($operand, $value($expression->high));
                $result = Values::and(null === $low ? null : $low >= 0, null === $high ? null : $high <= 0);
                return $expression->negated ? Values::not($result) : $result;
            case $expression instanceof Like:
                $operand = Values::text($value($expression->operand));
                $pattern = Values::text($value($expression->pattern));
                if (null === $operand || null === $pattern) {
                    return null;
                }
                return Values::like($operand, $pattern) !== $expression->negated;
            default:
                throw new QueryException('Unknown expression ' . $expression::class);
        }
    }

    private static function equals(mixed $a, mixed $b): ?bool
    {
        $order = Values::compare($a, $b);
        return null === $order ? null : 0 === $order;
    }

    private function binary(Binary $expression, \Closure $value): mixed
    {
        $operator = $expression->operator;
        if ('AND' === $operator) {
            $left = Values::truth($value($expression->left));
            return false === $left ? false : Values::and($left, Values::truth($value($expression->right)));
        }
        if ('OR' === $operator) {
            $left = Values::truth($value($expression->left));
            return true === $left ? true : Values::or($left, Values::truth($value($expression->right)));
        }

        $left = $value($expression->left);
        $right = $value($expression->right);
        if (in_array($operator, ['=', '!=', '<', '<=', '>', '>='], true)) {
            $order = Values::compare($left, $right);
            return null === $order ? null : match ($operator) {
                '=' => 0 === $order,
                '!=' => 0 !== $order,
                '<' => $order < 0,
                '<=' => $order <= 0,
                '>' => $order > 0,
                '>=' => $order >= 0,
            };
        }
        if ('||' === $operator) {
            $left = Values::text($left);
            $right = Values::text($right);
            return null === $left || null === $right ? null : $left . $right;
        }

        $left = Values::number($left);
        $right = Values::number($right);
        if (null === $left || null === $right) {
            return null;
        }
        return match ($operator) {
            '+' => $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            // as in MySQL: 7 / 2 is 3.5; NULL for a division by 0
            '/' => 0 == $right ? null : $left / $right,
            default => throw new QueryException('Unknown operator ' . $operator),
        };
    }

    private function function(string $name, array $arguments): mixed
    {
        [$first] = $arguments + [null];
        switch ($name) {
            case 'COALESCE':
                foreach ($arguments as $argument) {
                    if (null !== $argument) {
                        return $argument;
                    }
                }
                return null;
            case 'IFNULL':
                return $arguments[0] ?? $arguments[1];
        }
        if (null === $first) {
            return null;
        }
        switch ($name) {
            case 'LOWER':
                return mb_strtolower(Values::text($first), 'UTF-8');
            case 'UPPER':
                return mb_strtoupper(Values::text($first), 'UTF-8');
            case 'LENGTH':
                return mb_strlen(Values::text($first), 'UTF-8');
            case 'TRIM':
                return trim(Values::text($first), ' ');
            case 'ABS':
                $number = Values::number($first);
                return null === $number ? null : abs($number);
            case 'ROUND':
                $number = Values::number($first);
                $digits = isset($arguments[1]) ? (int)Values::number($arguments[1]) : 0;
                return null === $number ? null : round($number, $digits);
            case 'SUBSTR':
                // from 1; a negative start counts from the end
                $text = Values::text($first);
                $start = (int)Values::number($arguments[1]);
                $length = array_key_exists(2, $arguments) && null !== $arguments[2] ? (int)Values::number($arguments[2]) : null;
                if (null !== $length && $length < 0) {
                    return '';
                }
                if ($start > 0) {
                    return mb_substr($text, $start - 1, $length, 'UTF-8');
                }
                if ($start < 0) {
                    return mb_substr($text, $start, $length, 'UTF-8');
                }
                return null === $length ? $text : mb_substr($text, 0, max(0, $length - 1), 'UTF-8');
        }
        throw new QueryException('Unknown function ' . $name);
    }

    /** the running values of an aggregate of a group */
    private static function newState(): array
    {
        return ['count' => 0, 'sum' => 0, 'value' => null, 'seen' => []];
    }

    /**
     * Adds a row of a group to the running values of an aggregate: NULLs aren't counted; with DISTINCT, a value
     * only once.
     */
    private function accumulate(FunctionCall $call, array &$state, array $row): void
    {
        if ($call->star) {
            $state['count']++;
            return;
        }
        $value = $this->evaluate($call->arguments[0], $row);
        if (null === $value) {
            return;
        }
        if ($call->distinct) {
            $key = Values::key($value);
            if (isset($state['seen'][$key])) {
                return;
            }
            $state['seen'][$key] = true;
        }
        $state['count']++;
        switch ($call->name) {
            case 'SUM':
            case 'AVG':
                $state['sum'] += Values::number($value) ?? 0;
                break;
            case 'MIN':
            case 'MAX':
                if (null === $state['value']) {
                    $state['value'] = $value;
                    break;
                }
                $order = Values::sortOrder($value, $state['value']);
                if (('MIN' === $call->name && $order < 0) || ('MAX' === $call->name && $order > 0)) {
                    $state['value'] = $value;
                }
                break;
        }
    }

    /**
     * COUNT, SUM, AVG, MIN, MAX of a group, from their running values: SUM of integers is an integer, AVG a float;
     * SUM, AVG, MIN and MAX of no values are NULL.
     */
    private function aggregate(FunctionCall $call, array $states): mixed
    {
        $state = $states[spl_object_id($call)] ?? self::newState();
        return match ($call->name) {
            'COUNT' => $state['count'],
            'SUM' => 0 === $state['count'] ? null : $state['sum'],
            'AVG' => 0 === $state['count'] ? null : (float)$state['sum'] / $state['count'],
            'MIN', 'MAX' => $state['value'],
            default => throw new QueryException('Unknown aggregate ' . $call->name),
        };
    }
}
