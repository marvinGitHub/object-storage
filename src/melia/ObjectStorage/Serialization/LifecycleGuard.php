<?php

namespace melia\ObjectStorage\Serialization;

use melia\ObjectStorage\Exception\UnsupportedTypeException;

class LifecycleGuard
{
    /**
     * Invokes the __sleep method on the given object if it is callable.
     * This method is typically used to prepare an object for serialization.
     *
     * @param object $object The object to be processed. It must implement a callable __sleep method if serialization preparation is needed.
     * @return iterable|null
     * @throws UnsupportedTypeException
     */
    public static function sleep(object $object): ?iterable
    {
        if (method_exists($object, '__sleep')) {
            $propertyNames = $object->__sleep();

            if ((null !== $propertyNames) && (false === is_iterable($propertyNames))) {
                throw new UnsupportedTypeException('__sleep() must return an iterable or null, ' . gettype($propertyNames) . ' given');
            }

            return $propertyNames;
        }
        return null;
    }

    /**
     * Invokes the __wakeup method on the given object if it is callable.
     * This method is typically used to reinitialize an object after it has been unserialized.
     *
     * @param object $object The object to be processed. It must implement a callable __wakeup method if reinitialization is needed.
     * @return void
     */
    public static function wakeup(object $object): void
    {
        if (is_callable([$object, '__wakeup'])) {
            $object->__wakeup();
        }
    }
}