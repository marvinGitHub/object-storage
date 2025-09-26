<?php

namespace melia\ObjectStorage\UUID;

use melia\ObjectStorage\Reflection\Reflection;
use ReflectionException;

class Helper
{
    /**
     * Retrieves the assigned UUID from the provided object if it meets specific conditions.
     *
     * @param object $object The object from which the UUID is to be retrieved.
     *                       Must implement AwareInterface and have a `getUUID` method.
     *
     * @return string|null Returns the UUID as a string if the object fulfills the conditions.
     *                     Otherwise, returns null.
     * @throws ReflectionException
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

    public static function assign(object $object, string $uuid): void
    {
        $instanceOfAwareInterface = $object instanceof AwareInterface;
        $hasMethod = method_exists($object, 'setUUID');
        if ($instanceOfAwareInterface && $hasMethod) {
            $object->setUUID($uuid);
        }
    }
}