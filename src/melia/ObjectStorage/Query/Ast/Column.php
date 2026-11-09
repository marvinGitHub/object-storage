<?php

namespace melia\ObjectStorage\Query\Ast;

use melia\ObjectStorage\Path\PathSegment;

/**
 * A column as written: names separated by dots, e.g. p.category.title; the first may be an alias of a source, the
 * others are a path into the stored data, also into referenced objects. Bound to a source before running.
 * Segments may also be * (all elements: p.friends.*.name), [0] / [-1] (by position) and ['key'] (by key).
 * With the keyword UUID at the end (UUID, p.UUID, p.category.UUID): the UUID of the object there.
 *
 * @internal Not part of the public API; may change in any release.
 */
final readonly class Column implements Expr
{
    /**
     * @param list<string|PathSegment> $segments Names, and PathSegment for *, [0] and ['key']
     */
    public function __construct(public array $segments, public int $position, public bool $uuid = false)
    {
    }

    public function children(): array
    {
        return [];
    }
}
