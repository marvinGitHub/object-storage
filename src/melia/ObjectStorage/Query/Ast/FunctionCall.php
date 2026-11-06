<?php

namespace melia\ObjectStorage\Query\Ast;

/**
 * a function or an aggregate, e.g. LOWER(name), COUNT(*), COUNT(DISTINCT city)
 *
 * @internal Not part of the public API; may change in any release.
 */
final readonly class FunctionCall implements Expr
{
    public const array AGGREGATES = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];

    /**
     * @param string $name In capitals.
     * @param list<Expr> $arguments
     * @param bool $star COUNT(*)
     */
    public function __construct(
        public string $name,
        public array  $arguments,
        public bool   $distinct = false,
        public bool   $star = false,
        public int    $position = 0,
    )
    {
    }

    public function isAggregate(): bool
    {
        return in_array($this->name, self::AGGREGATES, true);
    }

    public function children(): array
    {
        return $this->arguments;
    }
}
