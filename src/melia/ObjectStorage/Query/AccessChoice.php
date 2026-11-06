<?php

namespace melia\ObjectStorage\Query;

/**
 * How the objects of a source are found (see Access): the UUIDs an index named, or null for all of the class.
 */
final readonly class AccessChoice
{
    /**
     * @param string $kind e.g. "search index", "unique index", "UUID", "all objects of the class"
     * @param string $details Which index, with which condition, how many objects.
     * @param list<string>|null $uuids
     */
    public function __construct(public string $kind, public string $details, public ?array $uuids = null)
    {
    }
}
