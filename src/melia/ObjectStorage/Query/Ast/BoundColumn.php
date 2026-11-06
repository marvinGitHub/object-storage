<?php

namespace melia\ObjectStorage\Query\Ast;

/**
 * A column bound to a source: the path into the object of the alias; no path is the object itself (its UUID).
 * $uuid: the UUID of the object at the path (UUID), NULL if there is no reference.
 *
 * @internal Not part of the public API; may change in any release.
 */
final readonly class BoundColumn implements Expr
{
    /**
     * @param list<string> $path
     */
    public function __construct(public string $alias, public array $path, public bool $uuid = false)
    {
    }

    public function children(): array
    {
        return [];
    }
}
