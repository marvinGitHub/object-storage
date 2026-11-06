<?php

namespace melia\ObjectStorage\Query\Ast;

/**
 * DELETE FROM Class [alias] [WHERE condition]
 *
 * @internal Not part of the public API; may change in any release.
 */
final readonly class Delete
{
    public function __construct(public Source $source, public ?Expr $where)
    {
    }
}
