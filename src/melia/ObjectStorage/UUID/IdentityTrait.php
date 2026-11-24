<?php

namespace melia\ObjectStorage\UUID;

use melia\ObjectStorage\LazyLoadReference;

/**
 * The UUID of an object instance, as store() determines it without an explicit UUID.
 *
 * Part of ObjectStorage, kept apart for readability.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait IdentityTrait
{
    /**
     * The UUID an object instance is stored under, as store() determines it without an explicit UUID: the one it
     * carries itself (AwareInterface, getUUID(), a property "uuid"), or the one this storage instance loaded or
     * stored it with.
     *
     * @return string|null The UUID, null if the instance is unknown (store() would store it as a new object).
     */
    public function getUuidOf(object $object): ?string
    {
        if ($object instanceof LazyLoadReference) {
            return $object->getUUID();
        }

        return Helper::getAssigned($object) ?? $this->session->uuidOf($object);
    }
}
