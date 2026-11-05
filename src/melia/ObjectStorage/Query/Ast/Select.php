<?php

namespace melia\ObjectStorage\Query\Ast;

/** a SELECT statement */
final readonly class Select
{
    /**
     * @param list<SelectItem> $items
     * @param list<Join> $joins
     * @param list<Expr> $groupBy
     * @param list<OrderItem> $orderBy
     */
    public function __construct(
        public bool   $distinct,
        public array  $items,
        public Source $from,
        public array  $joins = [],
        public ?Expr  $where = null,
        public array  $groupBy = [],
        public ?Expr  $having = null,
        public array  $orderBy = [],
        public ?Expr  $limit = null,
        public ?Expr  $offset = null,
    )
    {
    }
}
