<?php

namespace melia\ObjectStorage\Query;

/**
 * A reference to an object, the value of REF(uuid): written as a reference by INSERT and UPDATE.
 */
final readonly class Reference
{
    public function __construct(public string $uuid)
    {
    }
}
