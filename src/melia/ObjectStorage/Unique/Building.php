<?php

namespace melia\ObjectStorage\Unique;

/**
 * Marks a unique index whose keys are being claimed (create(), rebuild()): until it is removed, the index may lack
 * keys of objects stored before, so lookups may miss them. Left by a crash, it stays until the next rebuild().
 * Stored under a UUID derived from the index (Manager::buildingUuid()).
 *
 * @internal
 */
final class Building
{
    public function __construct(public string $index, public int $startedAt)
    {
    }
}
