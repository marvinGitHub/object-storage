<?php

namespace melia\ObjectStorage\Query\Ast;

/**
 * x IS [NOT] NULL
 *
 * @internal Not part of the public API; may change in any release.
 */
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
