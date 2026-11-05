<?php

namespace melia\ObjectStorage\Query;

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
use melia\ObjectStorage\Transfer\RawObject;
use melia\ObjectStorage\UUID\Validator as UuidValidator;
use Throwable;

/**
 * Runs a plan on the objects of a storage, as stored (their data, not loaded objects: the classes aren't needed):
 * reads the objects of the sources, joins them (by hashing for equalities, otherwise by trying every pair), filters,
 * groups, computes aggregates, sorts and limits. Rows are the objects of the sources by alias; a path follows
 * references (at most DocumentBuilder::MAX_REFERENCES_PER_PATH), a referenced object that doesn't exist or has
 * expired gives NULL; expired objects aren't read.
 */
final class Executor
{
    /** @var array<string, ?array{uuid: string, data: array, reserved: string}> objects read, by UUID */
    private array $objects = [];

    /** @var list<string>|null */
    private ?array $classNames = null;

    private \Closure $follow;

    public function __construct(private readonly ObjectStorage $storage)
    {
        $this->follow = function (string $uuid): ?array {
            $object = $this->read($uuid);
            return null === $object ? null : [$object['data'], $object['reserved']];
        };
    }

    /**
     * @throws QueryException
     */
    public function run(Plan $plan): Result
    {
        $this->objects = [];

        $rows = array_map(static fn(array $object): array => [$plan->from->alias => $object], $this->scan($plan->from));
        foreach ($plan->joins as $join) {
            $rows = $this->join($rows, $join);
        }
        if (null !== $plan->where) {
            $rows = array_values(array_filter($rows, fn(array $row): bool => true === Values::truth($this->evaluate($plan->where, $row))));
        }

        $items = $this->expandStars($plan, $rows);
        $names = array_map(static fn(array $item): string => $item['name'], $items);

        // what each row of the result is made of: a row, or a group of rows
        if ($plan->grouped) {
            $contexts = [];
            foreach ($this->groups($plan, $rows) as $group) {
                $context = [$group[0] ?? $this->emptyRow($plan), $group];
                if (null === $plan->having || true === Values::truth($this->evaluate($plan->having, ...$context))) {
                    $contexts[] = $context;
                }
            }
        } else {
            $contexts = array_map(static fn(array $row): array => [$row, null], $rows);
        }

        $results = [];
        foreach ($contexts as [$row, $group]) {
            $values = array_map(fn(array $item): mixed => $this->evaluate($item['expression'], $row, $group), $items);
            $keys = array_map(fn(array $order): mixed => $order[0] instanceof OutputColumn
                ? $values[$order[0]->index]
                : $this->evaluate($order[0], $row, $group), $plan->orderBy);
            $results[] = [$values, $keys];
        }

        if ($plan->distinct) {
            $seen = [];
            $results = array_values(array_filter($results, static function (array $result) use (&$seen): bool {
                $key = implode("\0", array_map([Values::class, 'key'], $result[0]));
                if (isset($seen[$key])) {
                    return false;
                }
                return $seen[$key] = true;
            }));
        }

        if ([] !== $plan->orderBy) {
            // stable: equal rows keep their order
            $positions = array_keys($results);
            usort($positions, static function (int $a, int $b) use ($results, $plan): int {
                foreach ($plan->orderBy as $i => [, $descending]) {
                    $order = Values::sortOrder($results[$a][1][$i], $results[$b][1][$i]);
                    if (0 !== $order) {
                        return $descending ? -$order : $order;
                    }
                }
                return $a <=> $b;
            });
            $results = array_map(static fn(int $position): array => $results[$position], $positions);
        }

        $results = array_slice($results, $plan->offset, $plan->limit);
        return new Result($names, array_map(static fn(array $result): array => $result[0], $results));
    }

    // ---- reading ----

    /**
     * @return list<array{uuid: string, data: array, reserved: string}>
     */
    private function scan(Source $source): array
    {
        $objects = [];
        foreach ($this->storage->list($this->classOf($source)) as $uuid) {
            $object = $this->read((string)$uuid);
            if (null !== $object) {
                $objects[] = $object;
            }
        }
        return $objects;
    }

    /**
     * An object as stored; null if it doesn't exist, has expired or can't be read.
     */
    private function read(string $uuid): ?array
    {
        if (!array_key_exists($uuid, $this->objects)) {
            try {
                $this->objects[$uuid] = self::objectOf($this->storage->readRaw($uuid));
            } catch (Throwable) {
                $this->objects[$uuid] = null;
            }
        }
        return $this->objects[$uuid];
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
     * The rows of the objects of a source a condition is true for.
     *
     * @return list<array<string, array>>
     */
    public function matching(Source $source, ?Expr $where): array
    {
        $this->objects = [];
        $rows = array_map(static fn(array $object): array => [$source->alias => $object], $this->scan($source));
        return null === $where ? $rows : array_values(array_filter($rows, fn(array $row): bool => true === Values::truth($this->evaluate($where, $row))));
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

    // ---- joining and grouping ----

    /**
     * @param list<array<string, ?array>> $rows
     * @param array{type: string, source: Source, on: Expr, hash: ?array{0: Expr, 1: Expr}} $join
     * @return list<array<string, ?array>>
     */
    private function join(array $rows, array $join): array
    {
        $alias = $join['source']->alias;
        $objects = $this->scan($join['source']);

        $buckets = null;
        if (null !== $join['hash']) {
            // the joined objects by the value they are compared with; NULL and arrays never equal anything
            $buckets = [];
            foreach ($objects as $object) {
                $value = Values::normalize($this->evaluate($join['hash'][1], [$alias => $object]));
                if (null !== $value && !is_array($value)) {
                    $buckets[Values::equalityKey($value)][] = $object;
                }
            }
        }

        $joined = [];
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
                    $joined[] = $candidate;
                    $matched = true;
                }
            }
            if (!$matched && Join::LEFT === $join['type']) {
                $joined[] = $row + [$alias => null];
            }
        }
        return $joined;
    }

    /**
     * @return list<list<array<string, ?array>>> the rows by the values of GROUP BY, in the order they come first;
     *         without GROUP BY, all rows in one group (also none)
     */
    private function groups(Plan $plan, array $rows): array
    {
        if ([] === $plan->groupBy) {
            return [$rows];
        }
        $groups = [];
        foreach ($rows as $row) {
            $key = implode("\0", array_map(fn(Expr $expression): string => Values::key($this->evaluate($expression, $row)), $plan->groupBy));
            $groups[$key][] = $row;
        }
        return array_values($groups);
    }

    /** a row of no objects: for aggregates over no rows */
    private function emptyRow(Plan $plan): array
    {
        return array_fill_keys($plan->aliases(), null);
    }

    /**
     * * and alias.*: the properties of the objects of the rows, in the order they come first.
     *
     * @return list<array{name: string, expression: Expr}>
     */
    private function expandStars(Plan $plan, array $rows): array
    {
        $aliases = $plan->aliases();
        $items = [];
        foreach ($plan->items as $item) {
            if (null !== $item['expression']) {
                $items[] = $item;
                continue;
            }
            foreach ('*' === $item['starOf'] ? $aliases : [$item['starOf']] as $alias) {
                $properties = [];
                foreach ($rows as $row) {
                    foreach (array_keys($row[$alias]['data'] ?? []) as $property) {
                        $properties[(string)$property] = true;
                    }
                }
                foreach (array_keys($properties) as $property) {
                    $items[] = [
                        'name' => 1 === count($aliases) ? (string)$property : $alias . '.' . $property,
                        'expression' => new BoundColumn($alias, [(string)$property]),
                    ];
                }
            }
        }
        return $items;
    }

    // ---- expressions ----

    /**
     * The value of an expression for a row; for a group of rows (grouped queries), aggregates are computed over the
     * group and columns are read from its first row.
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

    /**
     * COUNT, SUM, AVG, MIN, MAX over a group: NULLs aren't counted; SUM of integers is an integer, AVG a float;
     * SUM, AVG, MIN and MAX of no values are NULL.
     */
    private function aggregate(FunctionCall $call, array $rows): mixed
    {
        if ($call->star) {
            return count($rows);
        }
        $values = [];
        $seen = [];
        foreach ($rows as $row) {
            $value = $this->evaluate($call->arguments[0], $row);
            if (null === $value) {
                continue;
            }
            if ($call->distinct) {
                $key = Values::key($value);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
            }
            $values[] = $value;
        }

        switch ($call->name) {
            case 'COUNT':
                return count($values);
            case 'SUM':
            case 'AVG':
                if ([] === $values) {
                    return null;
                }
                $sum = 0;
                foreach ($values as $value) {
                    $sum += Values::number($value) ?? 0;
                }
                return 'SUM' === $call->name ? $sum : (float)$sum / count($values);
            case 'MIN':
            case 'MAX':
                $result = null;
                foreach ($values as $value) {
                    if (null === $result) {
                        $result = $value;
                        continue;
                    }
                    $order = Values::sortOrder($value, $result);
                    if (('MIN' === $call->name && $order < 0) || ('MAX' === $call->name && $order > 0)) {
                        $result = $value;
                    }
                }
                return $result;
        }
        throw new QueryException('Unknown aggregate ' . $call->name);
    }
}
