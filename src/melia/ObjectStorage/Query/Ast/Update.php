<?php

namespace melia\ObjectStorage\Query\Ast;

/**
 * UPDATE Class [alias] SET property = value, ... [WHERE condition]
 *
 * @internal Not part of the public API; may change in any release.
 */
final readonly class Update
{
    /**
     * @param list<array{0: string, 1: Expr}> $assignments The property and its new value.
     */
    public function __construct(public Source $source, public array $assignments, public ?Expr $where)
    {
    }
}
