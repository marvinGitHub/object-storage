<?php

namespace melia\ObjectStorage\Query\Ast;

/**
 * OR, AND, comparisons (= != < <= > >=), arithmetic (+ - * /) and concatenation (||)
 *
 * @internal Not part of the public API; may change in any release.
 */
final readonly class Binary implements Expr
{
    public function __construct(public string $operator, public Expr $left, public Expr $right)
    {
    }

    public function children(): array
    {
        return [$this->left, $this->right];
    }
}
