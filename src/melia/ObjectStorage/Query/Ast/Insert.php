<?php

namespace melia\ObjectStorage\Query\Ast;

/**
 * INSERT INTO Class (property, ...) VALUES (value, ...), ...
 *
 * @internal Not part of the public API; may change in any release.
 */
final readonly class Insert
{
    /**
     * @param list<string> $properties
     * @param list<list<Expr>> $rows
     */
    public function __construct(public Source $into, public array $properties, public array $rows)
    {
    }
}
