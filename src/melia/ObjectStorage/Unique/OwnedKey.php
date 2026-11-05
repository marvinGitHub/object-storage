<?php

namespace melia\ObjectStorage\Unique;

/**
 * The key an object has in a unique index, to release it when the object changes its values or is deleted:
 * stored under a UUID derived from the index and the object (Manager::ownedKeyUuid()).
 *
 * @internal
 */
final class OwnedKey
{
    public function __construct(
        public string $index,
        public string $owner,
        public string $key,
    )
    {
    }
}
