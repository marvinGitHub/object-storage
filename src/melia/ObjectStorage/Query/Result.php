<?php

namespace melia\ObjectStorage\Query;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The result of a query: the names of its columns and its rows, each a list of values in the order of the columns.
 * Values are as stored: strings, numbers, booleans, null; references as the UUIDs of the objects, dates as their
 * point in time in UTC (also in lists and maps), lists and objects as arrays.
 *
 * @implements IteratorAggregate<int, array<string, mixed>>
 *
 * @experimental OQL is experimental in 1.x: its syntax, semantics and this API may change in a minor release (see docs/query.md).
 */
final readonly class Result implements IteratorAggregate, Countable
{
    /**
     * @param list<string> $columns
     * @param list<list<mixed>> $rows
     * @param string|null $statement INSERT, UPDATE, DELETE or ALTER (DROP ATTRIBUTE): the rows are the UUIDs of the objects written (null in
     *        a dry run of INSERT); null for SELECT
     * @param bool $dryRun Whether nothing was written: the rows are the objects that would be.
     * @param bool $truncated Whether there were more rows than asked for (Oql::query() with $maxRows).
     * @param Statistics|null $statistics What the statement did and cost (set by Oql).
     */
    public function __construct(
        public array       $columns,
        public array       $rows,
        public ?string     $statement = null,
        public bool        $dryRun = false,
        public bool        $truncated = false,
        public ?Statistics $statistics = null,
    )
    {
    }

    /**
     * The result with its statistics.
     */
    public function withStatistics(Statistics $statistics): self
    {
        return new self($this->columns, $this->rows, $this->statement, $this->dryRun, $this->truncated, $statistics);
    }

    /**
     * The number of objects inserted, updated or deleted (or that would be, in a dry run).
     */
    public function affected(): int
    {
        return null === $this->statement ? 0 : count($this->rows);
    }

    /**
     * The rows with the names of the columns as keys; a name used again gets its position: "name (2)".
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        $names = [];
        foreach ($this->columns as $i => $column) {
            $names[] = in_array($column, $names, true) ? sprintf('%s (%d)', $column, $i + 1) : $column;
        }
        return array_map(static fn(array $row): array => array_combine($names, $row), $this->rows);
    }

    /**
     * The values of one column.
     *
     * @return list<mixed>
     */
    public function column(int|string $column = 0): array
    {
        $index = is_int($column) ? $column : array_search($column, $this->columns, true);
        return false === $index ? [] : array_column($this->rows, $index);
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function getIterator(): Traversable
    {
        yield from $this->toArray();
    }
}
