<?php

namespace melia\ObjectStorage\Serialization;

class LifecycleGuard
{
    /**
     * Serializes the given object into an array if the object implements the __serialize method.
     *
     * @param object $object The object to be serialized.
     * @return array|null The serialized array representation of the object, or null if the object does not implement the __serialize method.
     */
    public static function serialize(object $object): ?array
    {
        if (is_callable([$object, '__serialize'])) {
            return $object->__serialize();
        }
        return null;
    }

    /**
     * Unserializes data into the provided object if it supports the `__unserialize` method.
     *
     * @param object $object The object into which the data should be unserialized.
     * @param array $data The data to unserialize into the object.
     * @return void
     */
    public static function unserialize(object $object, array $data): void
    {
        if (static::supportsUnserialize($object)) {
            $object->__unserialize($data);
        }
    }

    /**
     * Determines if the provided object supports the `__unserialize` method.
     *
     * @param object $object The object to check for support of the `__unserialize` method.
     * @return bool True if the object defines a callable `__unserialize` method, false otherwise.
     */
    public static function supportsUnserialize(object $object): bool
    {
        return is_callable([$object, '__unserialize']);
    }
}