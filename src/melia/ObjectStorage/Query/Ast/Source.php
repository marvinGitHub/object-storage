<?php

namespace melia\ObjectStorage\Query\Ast;

/**
 * a class to read objects of, with its alias
 *
 * @internal Not part of the public API; may change in any release.
 */
final readonly class Source
{
    public function __construct(public string $class, public string $alias, public int $position)
    {
    }
}
