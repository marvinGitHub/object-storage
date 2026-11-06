<?php

namespace melia\ObjectStorage\Query;

/**
 * A reference to an object, the value of REF(uuid): written as a reference by INSERT and UPDATE.
 *
 * @experimental OQL is experimental in 1.x: its syntax, semantics and this API may change in a minor release (see docs/query.md).
 */
final readonly class Reference
{
    public function __construct(public string $uuid)
    {
    }
}
