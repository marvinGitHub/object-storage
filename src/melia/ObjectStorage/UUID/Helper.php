<?php

namespace melia\ObjectStorage\UUID;

use melia\ObjectStorage\Reflection\Reflection;
use melia\ObjectStorage\UUID\Exception\InvalidUUIDException;
use ReflectionException;

class Helper
{
    /**
     * Retrieves the assigned UUID from the provided object if it meets specific conditions.
     *
     * @param object|null $object $object The object from which the UUID is to be retrieved.
     *                       Must implement AwareInterface and have a `getUUID` method.
     *
     * @return string|null Returns the UUID as a string if the object fulfills the conditions.
     *                     Otherwise, returns null.
     */
    public static function getAssigned(?object $object): ?string
    {
        if (null === $object) {
            return null;
        }

        $instanceOfAwareInterface = $object instanceof AwareInterface;
        $hasMethod = method_exists($object, 'getUUID');
        if ($instanceOfAwareInterface && $hasMethod) {
            return $object->getUUID();
        }
        $reflection = new Reflection($object);
        try {
            $uuid = $reflection->get('uuid');
            if (is_string($uuid) && Validator::validate($uuid)) {
                return $uuid;
            }
        } catch (ReflectionException $e) {
            return null;
        }
        return null;
    }

    /**
     * Assigns a valid UUID to the provided object if it implements the AwareInterface.
     *
     * @param object $object The object to which the UUID will be assigned. The object must implement AwareInterface.
     * @param string $uuid The UUID to assign to the object. The value must be validated as a valid UUID.
     *
     * @return void
     *
     * @throws InvalidUUIDException If the provided UUID is not valid.
     */
    public static function assign(object $object, string $uuid): void
    {
        $instanceOfAwareInterface = $object instanceof AwareInterface;

        if ($instanceOfAwareInterface) {
            if (Validator::validate($uuid) === false) {
                throw new InvalidUUIDException(sprintf('Invalid UUID: %s', $uuid));
            }
            $object->setUUID($uuid);
        }
    }
}