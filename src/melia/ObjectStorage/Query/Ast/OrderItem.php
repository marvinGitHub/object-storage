<?php

namespace melia\ObjectStorage\Query\Ast;

/**
 * an expression to sort by
 *
 * @internal Not part of the public API; may change in any release.
 */
final readonly class OrderItem
{
    public function __construct(public Expr $expression, public bool $descending)
    {
    }
}
