<?php

namespace melia\ObjectStorage\Query\Ast;

/** x IS [NOT] NULL */
final readonly class IsNull implements Expr
{
    public function __construct(public Expr $operand, public bool $negated)
    {
    }

    public function children(): array
    {
        return [$this->operand];
    }
}
