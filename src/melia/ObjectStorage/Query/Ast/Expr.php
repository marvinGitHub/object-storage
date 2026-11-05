<?php

namespace melia\ObjectStorage\Query\Ast;

/**
 * An expression of a query.
 */
interface Expr
{
    /**
     * @return list<Expr> The expressions it is made of.
     */
    public function children(): array;
}
