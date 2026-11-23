<?php

namespace melia\ObjectStorage\UUID;

use melia\ObjectStorage\LazyLoadReference;
use WeakMap;

/**
 * The identity map and the UUID of an object instance, as store() determines it without an explicit UUID.
 *
 * Part of ObjectStorage, kept apart for readability. The map is created by the class using the trait (in its
 * constructor, and anew when its caches are cleared): objects can't be default values of properties.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait IdentityTrait
{
    /**
     * The identity map: the UUIDs of the object instances this storage instance loaded or stored. Weak, so an
     * instance no longer used elsewhere is dropped from it.
     *
     * @var WeakMap<object, string>
     */
    protected WeakMap $identityMap;

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

        return Helper::getAssigned($object) ?? $this->identityMap[$object] ?? null;
    }
}
