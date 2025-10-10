<?php

namespace melia\ObjectStorage\Serialization;

class LifecycleGuard
{
    /**
     * Invokes the __sleep method on the given object if it is callable.
     * This method is typically used to prepare an object for serialization.
     *
     * @param object $object The object to be processed. It must implement a callable __sleep method if serialization preparation is needed.
     * @return void
     */
    public static function sleep(object $object): void
    {
        if (is_callable([$object, '__sleep'])) {
            $object->__sleep();
        }
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