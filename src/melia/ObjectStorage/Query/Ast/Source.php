<?php

namespace melia\ObjectStorage\Query\Ast;

/** a class to read objects of, with its alias */
final readonly class Source
{
    public function __construct(public string $class, public string $alias, public int $position)
    {
    }
}
