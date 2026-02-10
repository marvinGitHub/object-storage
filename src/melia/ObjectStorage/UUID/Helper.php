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

        if ($object instanceof AwareInterface) {
            return $object->getUUID();
        }

        if (method_exists($object, 'getUUID')) {
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
        /* important: only assign if the object implements AwareInterface to not break existing code or introduce unexpected behavior */
        $instanceOfAwareInterface = $object instanceof AwareInterface;

        if (!$instanceOfAwareInterface) {
            return;
        }

        if (Validator::validate($uuid) === false) {
            throw new InvalidUUIDException(sprintf('Invalid UUID: %s', $uuid));
        }
        $object->setUUID($uuid);
    }

    /**
     * Removes all hyphens from the provided UUID string.
     *
     * @param string $uuid The input string containing hyphens to be removed.
     * @return string The input string with all hyphens removed.
     */
    public static function removeHyphens(string $uuid): string
    {
        return str_replace('-', '', $uuid);
    }
}