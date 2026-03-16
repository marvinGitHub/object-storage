<?php

namespace melia\ObjectStorage\Reflection;

use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionProperty;
use ReflectionType;
use WeakMap;

/**
 * A utility class that provides reflection-based methods
 * to dynamically interact with property names of an object.
 */
class Reflection
{
    private object $target;

    /**
     * Constructor method to initialize the object with a target.
     *
     * @param object $target The target object to be assigned to the class.
     * @return void
     */
    public function __construct(object $target)
    {
        $this->target = $target;
    }

    /**
     * Retrieves the target object.
     *
     * @return object The target object.
     */
    public function getTarget(): object
    {
        return $this->target;
    }

    /**
     * Sets the value of a specified property name on an object using a dynamically bound closure.
     *
     * @param string $propertyName The name of the property name to be updated on the object.
     * @param mixed $value The value to assign to the specified property name.
     * @return void
     */
    public function set(string $propertyName, mixed $value): void
    {
        $property = static::getProperty($this->target, $propertyName);

        if (null === $property) {
            $this->target->{$propertyName} = $value;
        } else {
            $property->setAccessible(true);
            $property->setValue($this->target, $value);
        }
    }

    /**
     * Retrieves a specific property of the target object by name using reflection.
     *
     * @param string $propertyName The name of the property to retrieve.
     *
     * @return ReflectionProperty|null The ReflectionProperty object representing the specified property of the target object.
     */
    public static function getProperty(object $object, string $propertyName): ?ReflectionProperty
    {
        $reflectionObject = static::getReflectionObject($object);
        return $reflectionObject->hasProperty($propertyName) ? $reflectionObject->getProperty($propertyName) : null;
    }

    /**
     * Retrieves a ReflectionObject instance for the current target object.
     *
     * @return ReflectionObject The ReflectionObject instance associated with the target object.
     */
    public static function getReflectionObject(object $object): ReflectionObject
    {
        static $cache;
        $cache ??= new WeakMap();
        return $cache[$object] ??= new ReflectionObject($object);
    }

    /**
     * Retrieves the value of a specified property name from the given object.
     *
     * @param string $propertyName The name of the property name to be accessed.
     * @return mixed The value of the specified property name.
     */
    public function get(string $propertyName): mixed
    {
        if (isset($this->target->{$propertyName})) {
            return $this->target->{$propertyName};
        }

        $property = static::getProperty($this->target, $propertyName);
        if (null === $property) {
            return null;
        }

        $property->setAccessible(true);
        return $property->isInitialized($this->target) ? $property->getValue($this->target) : null;
    }

    /**
     * Unsets the value of a specified property name from the given object.
     * If the property does not allow null, it will be set to a default value. Either if the default value is defined in class or based on its type.
     *
     * @param string $propertyName The name of the property name to be unset.
     * @return bool
     */
    public function unset(string $propertyName): bool
    {
        if (isset($this->target->{$propertyName})) {
            unset($this->target->{$propertyName});
            return true;
        }

        $property = static::getProperty($this->target, $propertyName);
        if (null === $property) {
            return false;
        }

        $property->setAccessible(true);

        if (false === $property->isInitialized($this->target)) {
            return true;
        }

        $type = $property->getType();

        if ($type === null || $type->allowsNull()) {
            $property->setValue($this->target, null);
            return true;
        }

        if ($property->hasDefaultValue()) {
            $property->setValue($this->target, $property->getDefaultValue());
            return true;
        }

        if ($type instanceof ReflectionNamedType) {
            $defaultValue = $this->getDefaultValueForType($type->getName());
            if ($defaultValue !== null) {
                $property->setValue($this->target, $defaultValue);
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the default value for a given type name.
     *
     * @param string $typeName The name of the type for which the default value is needed.
     * @return mixed The default value corresponding to the specified type name.
     */
    private function getDefaultValueForType(string $typeName): mixed
    {
        return match ($typeName) {
            'string' => '',
            'int' => 0,
            'float' => 0.0,
            'bool' => false,
            'array' => [],
            default => null
        };
    }

    /**
     * Checks if the specified property is read-only.
     *
     * @param string $propertyName The name of the property to be checked.
     * @return bool True if the property is read-only, otherwise false.
     */
    public function isReadonly(string $propertyName): bool
    {
        return static::getProperty($this->target, $propertyName)?->isReadOnly() ?? false;
    }

    /**
     * Checks if a specified property exists and is initialized in the given object.
     *
     * @param string $propertyName The name of the property to check.
     * @return bool True if the property exists and is initialized, false otherwise.
     */
    public function initialized(string $propertyName): bool
    {
        if (isset($this->target->{$propertyName})) {
            return true;
        }

        $property = static::getProperty($this->target, $propertyName);
        if (null === $property) {
            return false;
        }

        $property->setAccessible(true);
        return $property->isInitialized($this->target);
    }

    /**
     * Retrieves the list of all property names from the target object, including both public properties
     * and those accessible through reflection.
     *
     * @return array An array of unique property names belonging to the target object.
     */
    public function getPropertyNames(): array
    {
        static $cache = [];

        // cache property names of the class
        $cache[$classname = $this->target::class] ??= (static function () use ($classname) {
            $properties = [];
            foreach (static::getReflectionClass($classname)->getProperties() as $property) {
                $properties[$property->getName()] = true;
            }
            return $properties;
        })();

        return array_keys($cache[$classname] + get_object_vars($this->target));
    }

    /**
     * @throws ReflectionException
     */
    public static function getReflectionClass(string $className): ReflectionClass
    {
        static $cache = [];
        return $cache[$className] ??= new ReflectionClass($className);
    }

    /**
     * Determines and retrieves the type of specified property from the given object.
     *
     * @param object $object The object whose property type is to be determined.
     * @param string $propertyName The name of the property whose type is to be retrieved.
     * @return ReflectionType|null The ReflectionType of the specified property, or null if the property does not exist or its type cannot be resolved.
     */
    public static function getPropertyType(object $object, string $propertyName): ?ReflectionType
    {
        return static::getProperty($object, $propertyName)?->getType();
    }
}