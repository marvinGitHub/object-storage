<?php

namespace melia\ObjectStorage\Query\Ast;

/** INSERT INTO Class (property, ...) VALUES (value, ...), ... */
final readonly class Insert
{
    /**
     * @param list<string> $properties
     * @param list<list<Expr>> $rows
     */
    public function __construct(public Source $into, public array $properties, public array $rows)
    {
    }
}
