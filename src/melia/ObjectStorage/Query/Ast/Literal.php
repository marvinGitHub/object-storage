<?php

namespace melia\ObjectStorage\Query\Ast;

/** a value: a string, a number, true, false or null */
final readonly class Literal implements Expr
{
    public function __construct(public mixed $value)
    {
    }

    public function children(): array
    {
        return [];
    }
}
