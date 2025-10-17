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
     * Assigns the provided UUID to the given object if it meets specific conditions.
     *
     * @param object $object The object to which the UUID will be assigned.
     *                       Must implement AwareInterface and have a `setUUID` method
     *                       to directly set the UUID. If these conditions are not met,
     *                       an attempt will be made to assign the UUID via reflection.
     * @param string $uuid The UUID string to be assigned to the object.
     *
     * @return void
     * @throws InvalidUUIDException
     */
    public static function assign(object $object, string $uuid): void
    {
        if (Validator::validate($uuid) === false) {
            throw new InvalidUUIDException(sprintf('Invalid UUID: %s', $uuid));
        }

        $instanceOfAwareInterface = $object instanceof AwareInterface;
        $hasMethod = method_exists($object, 'setUUID');
        if ($instanceOfAwareInterface || $hasMethod) {
            $object->setUUID($uuid);
            return;
        }

        $reflection = new Reflection($object);
        try {
           $reflection->set('uuid', $uuid);
        } catch (ReflectionException $e) {
            // ignore
        }
    }
}