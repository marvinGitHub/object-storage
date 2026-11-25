<?php

namespace melia\ObjectStorage\Query;

use Closure;
use Generator;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Query\Ast\Expr;
use melia\ObjectStorage\Query\Ast\Join;
use melia\ObjectStorage\Query\Ast\OutputColumn;
use melia\ObjectStorage\Query\Ast\Source;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Search\DocumentBuilder;
use melia\ObjectStorage\Search\Index\ManagerInterface;
use melia\ObjectStorage\Unique\Manager as UniqueManager;
use SplHeap;

/**
 * Runs a plan on the objects of a storage, as stored (their data, not loaded objects: the classes aren't needed).
 *
 * Memory stays bounded by what the result needs, not by the number of objects: the objects of the source are read
 * one after another and dropped; WHERE filters them as they come; without ORDER BY, reading stops at LIMIT; ORDER BY
 * with LIMIT keeps only the rows that may be in the result; GROUP BY keeps per group its first row and the running
 * values of the aggregates, not its rows. What is kept: the joined sources (of their properties only those the query
 * reads), the groups, the rows of the result, and a bounded number of referenced objects.
 *
 * The executor runs one statement, with its components:
 *
 *   ObjectSource   the objects of the sources (by an index or all) and the referenced objects, read as stored
 *   Evaluator      the values of expressions for a row, with Functions (the scalar functions) and Aggregates
 *   PlanAnalysis   what a plan reads: the properties of each alias, the aggregates
 *
 * What it does itself: rows, joins, grouping, sorting, LIMIT and the columns of the result.
 *
 * @internal Not part of the public API; may change in any release.
 */
final class Executor
{
    /** the most referenced objects kept while a query runs, for paths into them */
    public const int MAX_REFERENCED = ObjectSource::MAX_REFERENCED;

    private readonly ObjectSource $source;
    private readonly Evaluator $evaluator;

    /** @var Closure(string): ?array{0: array, 1: string} */
    private readonly Closure $follow;

    /**
     * @param \Closure(): void|null $interrupt Throws a QueryInterruptedException to stop the statement.
     */
    public function __construct(
        ObjectStorage     $storage,
        ?ManagerInterface $search = null,
        ?UniqueManager    $unique = null,
        bool              $useIndexes = true,
        ?Closure          $interrupt = null,
    )
    {
        $this->source = new ObjectSource($storage, $search, $unique, $useIndexes, $interrupt);
        $this->follow = $this->source->follow();
        $this->evaluator = new Evaluator($this->follow, new Functions());
    }

    /**
     * Stops the statement if its interrupt says so (throws a QueryInterruptedException); called for every object
     * read, joined pair and object written.
     */
    public function checkpoint(): void
    {
        $this->source->checkpoint();
    }

    /**
     * The objects read from the storage so far, by this executor (one statement).
     */
    public function objectsRead(): int
    {
        return $this->source->objectsRead();
    }

    /**
     * How the objects of each source were found so far, in the order they were read.
     *
     * @return list<array{source: string, class: string, access: string}>
     */
    public function accessed(): array
    {
        return $this->source->accessed();
    }

    /**
     * The class of a source: its full name, or its short name if one stored class has it.
     */
    public function classOf(Source $source): string
    {
        return $this->source->classOf($source);
    }

    /**
     * The value of an expression for a row, see Evaluator::evaluate().
     */
    public function evaluate(Expr $expression, array $row, ?array $group = null): mixed
    {
        return $this->evaluator->evaluate($expression, $row, $group);
    }

    /**
     * @param int|null $maxRows At most this many rows (e.g. to show); the result tells whether there were more.
     * @throws QueryException
     */
    public function run(Plan $plan, ?int $maxRows = null): Result
    {
        $this->source->forgetReferenced();
        $keep = PlanAnalysis::keysUsed($plan);
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
            usort($results, static fn(array $a, array $b): int => self::compareResults($plan, $a, $b));
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
        $access = $this->source->choose($plan->from, $plan->where);
        $rows[] = [$plan->from->alias, $this->classOf($plan->from), $access->kind, $access->details];
        foreach ($plan->joins as $join) {
            $rows[] = [$join['source']->alias, $this->classOf($join['source']), 'all objects (joined)', null === $join['hash']
                ? 'every pair is tried'
                : 'by hashing: ' . Printer::print($join['hash'][1]) . ' = ' . Printer::print($join['hash'][0])];
        }
        return new Result(['source', 'class', 'access', 'details'], $rows);
    }

    /**
     * The rows of the objects of a source a condition is true for, one after another (for UPDATE and DELETE).
     *
     * @return Generator<array<string, array>>
     */
    public function matching(Source $source, ?Expr $where): Generator
    {
        $this->source->forgetReferenced();
        foreach ($this->source->objectsOf($source, $where) as $object) {
            $row = [$source->alias => $object];
            if ($this->evaluator->holds($where, $row)) {
                yield $row;
            }
        }
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
        $rows = $this->source->objectsOf($plan->from, $plan->where);
        $rows = (static function () use ($rows, $plan): Generator {
            foreach ($rows as $object) {
                yield [$plan->from->alias => $object];
            }
        })();
        foreach ($plan->joins as $join) {
            $rows = $this->join($rows, $join, PlanAnalysis::keepOf($keep, $join['source']->alias));
        }
        foreach ($rows as $row) {
            if ($this->evaluator->holds($plan->where, $row)) {
                yield $row;
            }
        }
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
        foreach ($this->source->objectsOf($join['source'], null) as $object) {
            $object = PlanAnalysis::stripped($object, $keep);
            if (null === $buckets) {
                $objects[] = $object;
                continue;
            }
            // the joined objects by the value they are compared with; NULL never equals anything, a list (of a path
            // with *) is compared element by element
            foreach (self::hashKeys($this->evaluator->evaluate($join['hash'][1], [$alias => $object])) as $key) {
                $buckets[$key][$object['uuid']] = $object;
            }
        }

        foreach ($rows as $row) {
            if (null === $buckets) {
                $candidates = $objects;
            } else {
                $candidates = [];
                foreach (self::hashKeys($this->evaluator->evaluate($join['hash'][0], $row)) as $key) {
                    $candidates += $buckets[$key] ?? [];
                }
            }
            $matched = false;
            foreach ($candidates as $object) {
                $this->checkpoint();
                $candidate = $row + [$alias => $object];
                if (true === Values::truth($this->evaluator->evaluate($join['on'], $candidate))) {
                    $matched = true;
                    yield $candidate;
                }
            }
            if (!$matched && Join::LEFT === $join['type']) {
                yield $row + [$alias => null];
            }
        }
    }

    /**
     * The keys of the hash join for a value: none for NULL, one per element for a list.
     *
     * @return list<string>
     */
    private static function hashKeys(mixed $value): array
    {
        $keys = [];
        foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $element) {
            $element = Values::normalize(is_array($element) ? (DateValue::fromStored($element) ?? $element) : $element);
            if (null !== $element && !is_array($element)) {
                $keys[] = Values::equalityKey($element);
            }
        }
        return array_values(array_unique($keys));
    }

    // ---- results: [values of the selection, values to sort by, objects for *, position] ----

    /**
     * The results of rows without grouping: all of them, or with LIMIT and without ORDER BY and DISTINCT only the
     * first needed; with ORDER BY and LIMIT only the needed ones that sort first.
     */
    private function ungrouped(Plan $plan, iterable $rows, ?int $needed): array
    {
        if (0 === $needed) {
            return [];
        }
        $sorted = [] !== $plan->orderBy;
        // with ORDER BY and LIMIT, the needed rows that sort first: the greatest is dropped when there are more
        $heap = null !== $needed && $sorted ? self::resultHeap($plan) : null;

        $results = [];
        $seen = [];
        $position = 0;
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
            if (null !== $needed && !$sorted && count($results) >= $needed) {
                break; // enough
            }
        }
        return null === $heap ? $results : iterator_to_array($heap, false);
    }

    /** a heap of results with the one that sorts last on top */
    private static function resultHeap(Plan $plan): SplHeap
    {
        $compare = static fn(array $a, array $b): int => self::compareResults($plan, $a, $b);
        return new class($compare) extends SplHeap {
            public function __construct(private readonly Closure $compare)
            {
            }

            protected function compare(mixed $value1, mixed $value2): int
            {
                return ($this->compare)($value1, $value2);
            }
        };
    }

    /**
     * The results of groups: per group (in the order the groups come first) its first row and the running values
     * of its aggregates, not its rows. Without GROUP BY, one group, also of no rows.
     */
    private function grouped(Plan $plan, iterable $rows, array $keep): array
    {
        $aggregates = PlanAnalysis::aggregatesOf($plan);
        $newStates = static fn(): array => array_map(static fn(): array => Aggregates::newState(), $aggregates);

        $groups = [];
        foreach ($rows as $row) {
            $key = implode("\0", array_map(fn(Expr $expression): string => Values::key($this->evaluator->evaluate($expression, $row)), $plan->groupBy));
            if (!isset($groups[$key])) {
                $first = [];
                foreach ($row as $alias => $object) {
                    $first[$alias] = PlanAnalysis::stripped($object, PlanAnalysis::keepOf($keep, $alias));
                }
                $groups[$key] = ['row' => $first, 'states' => $newStates()];
            }
            foreach ($aggregates as $id => $aggregate) {
                // COUNT(*) counts rows, its argument isn't evaluated
                $value = $aggregate->star ? null : $this->evaluator->evaluate($aggregate->arguments[0], $row);
                Aggregates::accumulate($aggregate, $groups[$key]['states'][$id], $value);
            }
        }
        if ([] === $plan->groupBy && [] === $groups) {
            $groups[] = ['row' => array_fill_keys($plan->aliases(), null), 'states' => $newStates()];
        }

        $results = [];
        $position = 0;
        foreach ($groups as $group) {
            if ($this->evaluator->holds($plan->having, $group['row'], $group['states'])) {
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
            if (null !== $item['expression']) {
                $values[$i] = $this->evaluator->evaluate($item['expression'], $row, $states);
                continue;
            }
            // the references of * are read with the row
            foreach (PlanAnalysis::starAliases($plan, $item) as $alias) {
                $object = $row[$alias] ?? null;
                $stars[$alias] = null === ($object['data'] ?? null)
                    ? null
                    : ['data' => $object['data'], 'reserved' => $object['reserved'], 'dateTime' => $object['dateTime'], 'uuid' => $object['uuid']];
            }
        }
        $keys = array_map(fn(array $order): mixed => $order[0] instanceof OutputColumn
            ? $values[$order[0]->index] ?? null
            : $this->evaluator->evaluate($order[0], $row, $states), $plan->orderBy);
        return [$values, $keys, $stars, $position];
    }

    private static function compareResults(Plan $plan, array $a, array $b): int
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
            foreach (PlanAnalysis::starAliases($plan, $item) as $alias) {
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
                    $row[] = self::output($result[0][$i]);
                    continue;
                }
                $object = $result[2][$alias] ?? null;
                $row[] = null === $object || !array_key_exists($property, $object['data'])
                    ? null
                    : self::output(Evaluator::valueOf(DocumentBuilder::extract($object['data'], [$property], $object['reserved'], $this->follow), $object), $object['dateTime'] ?? null);
            }
            $rows[] = $row;
        }
        return [array_column($columns, 0), $rows];
    }

    /**
     * A value as returned in a result: a date as its point in time in UTC as stored, also the dates in lists and maps
     * (references there are already their UUIDs).
     *
     * @param string|null $dateKey The reserved key of dates of the object the value was read from (null: the default).
     */
    private static function output(mixed $value, ?string $dateKey = null): mixed
    {
        if ($value instanceof DateValue) {
            return $value->utc;
        }
        if (!is_array($value)) {
            return $value;
        }
        $date = DateValue::fromStored($value, $dateKey);
        if (null !== $date) {
            return $date->utc;
        }
        foreach ($value as $key => $element) {
            if (is_array($element) || $element instanceof DateValue) {
                $value[$key] = self::output($element, $dateKey);
            }
        }
        return $value;
    }
}
