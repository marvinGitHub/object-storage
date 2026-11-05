<?php

namespace melia\ObjectStorage\Query\Ast;

/** x [NOT] IN (a, b, ...) */
final readonly class InList implements Expr
{
    /**
     * @param list<Expr> $values
     */
    public function __construct(public Expr $operand, public array $values, public bool $negated)
    {
    }

    public function children(): array
    {
        return [$this->operand, ...$this->values];
    }
}
