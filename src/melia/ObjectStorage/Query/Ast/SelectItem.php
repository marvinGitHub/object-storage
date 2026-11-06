<?php

namespace melia\ObjectStorage\Query\Ast;

/**
 * An expression of the selection with its name, or * (all properties of all sources) or alias.* (of one source).
 *
 * @internal Not part of the public API; may change in any release.
 */
final readonly class SelectItem
{
    /**
     * @param Expr|null $expression null for *
     * @param string|null $alias AS alias
     * @param string|null $starOf the alias of alias.*
     * @param string $text The expression as written: its name without an alias.
     */
    public function __construct(
        public ?Expr   $expression,
        public ?string $alias,
        public ?string $starOf = null,
        public string  $text = '*',
    )
    {
    }

    public function isStar(): bool
    {
        return null === $this->expression;
    }
}
