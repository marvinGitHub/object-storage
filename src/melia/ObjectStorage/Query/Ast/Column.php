<?php

namespace melia\ObjectStorage\Query\Ast;

/**
 * A column as written: names separated by dots, e.g. p.category.title; the first may be an alias of a source, the
 * others are a path into the stored data, also into referenced objects. Bound to a source before running.
 * With the keyword UUID at the end (UUID, p.UUID, p.category.UUID): the UUID of the object there.
 */
final readonly class Column implements Expr
{
    /**
     * @param list<string> $segments
     */
    public function __construct(public array $segments, public int $position, public bool $uuid = false)
    {
    }

    public function children(): array
    {
        return [];
    }
}
