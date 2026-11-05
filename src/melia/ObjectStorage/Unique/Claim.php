<?php

namespace melia\ObjectStorage\Unique;

/**
 * Which object has a key of a unique index: stored in the internal storage of the unique indexes under a UUID
 * derived from the index and the key (Manager::claimUuid()), so a key exists once.
 *
 * @internal
 */
final class Claim
{
    /**
     * @param string $index The unique index.
     * @param string $key The key (JSON of the normalized values).
     * @param array<string, mixed> $values The values as stored, path => value.
     * @param string $owner The UUID of the object having the values.
     */
    public function __construct(
        public string $index,
        public string $key,
        public array  $values,
        public string $owner,
    )
    {
    }
}
