<?php

namespace melia\ObjectStorage\Query;

use melia\ObjectStorage\Query\Ast\Expr;
use melia\ObjectStorage\Query\Ast\Source;

/**
 * An INSERT, UPDATE or DELETE bound to its source: the properties written and their values (INSERT: one list of
 * values per object; UPDATE: one list, evaluated for every object), the condition of UPDATE and DELETE.
 */
final readonly class WritePlan
{
    public const string INSERT = 'INSERT';
    public const string UPDATE = 'UPDATE';
    public const string DELETE = 'DELETE';

    /**
     * @param list<string> $properties
     * @param list<list<Expr>> $rows
     */
    public function __construct(
        public string $statement,
        public Source $source,
        public array  $properties = [],
        public array  $rows = [],
        public ?Expr  $where = null,
    )
    {
    }
}
