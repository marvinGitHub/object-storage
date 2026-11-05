<?php

namespace melia\ObjectStorage\Query;

use melia\ObjectStorage\Query\Ast\Expr;
use melia\ObjectStorage\Query\Ast\Source;

/**
 * A SELECT bound to its sources, ready to run: columns bound to aliases, parameters replaced by their values,
 * aliases and positions of ORDER BY resolved, joins with the expressions to hash by. See Planner.
 */
final class Plan
{
    /**
     * @param list<array{name: string, expression: ?Expr, starOf: ?string}> $items starOf: '*' for all sources
     * @param list<array{type: string, source: Source, on: Expr, hash: ?array{0: Expr, 1: Expr}}> $joins hash: the
     *        expression of the joined rows and the one of the joined source to compare with =
     * @param list<Expr> $groupBy
     * @param list<array{0: Expr, 1: bool}> $orderBy the expression and whether descending
     */
    public function __construct(
        public bool   $distinct,
        public array  $items,
        public Source $from,
        public array  $joins,
        public ?Expr  $where,
        public array  $groupBy,
        public bool   $grouped,
        public ?Expr  $having,
        public array  $orderBy,
        public ?int   $limit,
        public int    $offset,
    )
    {
    }

    /**
     * @return list<string> The aliases of the sources, in the order of the query.
     */
    public function aliases(): array
    {
        return [$this->from->alias, ...array_map(static fn(array $join): string => $join['source']->alias, $this->joins)];
    }
}
