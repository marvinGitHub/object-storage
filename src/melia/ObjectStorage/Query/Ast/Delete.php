<?php

namespace melia\ObjectStorage\Query\Ast;

/** DELETE FROM Class [alias] [WHERE condition] */
final readonly class Delete
{
    public function __construct(public Source $source, public ?Expr $where)
    {
    }
}
