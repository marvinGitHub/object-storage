<?php

namespace melia\ObjectStorage\Query\Ast;

/**
 * UPDATE Class [alias] [SET target = value, ...] [REMOVE target, ...] [WHERE condition]
 * (at least SET or REMOVE; a target is a property or a path into it, see Target)
 *
 * @internal Not part of the public API; may change in any release.
 */
final readonly class Update
{
    /**
     * @param list<array{0: Target, 1: Expr}> $assignments What is set and its new value.
     * @param list<Target> $removals The paths removed (keys and elements of lists and maps).
     */
    public function __construct(public Source $source, public array $assignments, public ?Expr $where, public array $removals = [])
    {
    }
}
