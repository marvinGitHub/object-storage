<?php

namespace melia\ObjectStorage\Query\Ast;

/**
 * NOT x, -x
 *
 * @internal Not part of the public API; may change in any release.
 */
final readonly class Unary implements Expr
{
    public function __construct(public string $operator, public Expr $operand)
    {
    }

    public function children(): array
    {
        return [$this->operand];
    }
}
