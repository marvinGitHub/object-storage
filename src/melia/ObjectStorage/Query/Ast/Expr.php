<?php

namespace melia\ObjectStorage\Query\Ast;

/**
 * An expression of a query.
 *
 * @internal Not part of the public API; may change in any release.
 */
interface Expr
{
    /**
     * @return list<Expr> The expressions it is made of.
     */
    public function children(): array;
}
