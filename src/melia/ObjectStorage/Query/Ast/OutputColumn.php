<?php

namespace melia\ObjectStorage\Query\Ast;

/**
 * a column of the result, by its index: ORDER BY an alias of the selection or a position
 *
 * @internal Not part of the public API; may change in any release.
 */
final readonly class OutputColumn implements Expr
{
    public function __construct(public int $index)
    {
    }

    public function children(): array
    {
        return [];
    }
}
