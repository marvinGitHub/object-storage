<?php

namespace melia\ObjectStorage\Query\Ast;

/** a parameter: ? (by position, from 0) or :name, replaced by its value before running */
final readonly class Parameter implements Expr
{
    public function __construct(public string $name, public int $position)
    {
    }

    public function children(): array
    {
        return [];
    }
}
