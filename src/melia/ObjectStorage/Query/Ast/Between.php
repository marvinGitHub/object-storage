<?php

namespace melia\ObjectStorage\Query\Ast;

/** x [NOT] BETWEEN low AND high */
final readonly class Between implements Expr
{
    public function __construct(public Expr $operand, public Expr $low, public Expr $high, public bool $negated)
    {
    }

    public function children(): array
    {
        return [$this->operand, $this->low, $this->high];
    }
}
