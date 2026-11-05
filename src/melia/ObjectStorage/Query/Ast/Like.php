<?php

namespace melia\ObjectStorage\Query\Ast;

/** x [NOT] LIKE pattern: % any characters, _ one, a backslash takes the next one as it is; ignoring case */
final readonly class Like implements Expr
{
    public function __construct(public Expr $operand, public Expr $pattern, public bool $negated)
    {
    }

    public function children(): array
    {
        return [$this->operand, $this->pattern];
    }
}
