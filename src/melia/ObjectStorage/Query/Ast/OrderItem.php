<?php

namespace melia\ObjectStorage\Query\Ast;

/** an expression to sort by */
final readonly class OrderItem
{
    public function __construct(public Expr $expression, public bool $descending)
    {
    }
}
