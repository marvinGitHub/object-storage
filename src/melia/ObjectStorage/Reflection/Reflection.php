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
        $reflection = new ReflectionObject($this->target);
        if ($reflection->hasProperty($propertyName)) {
            $property = $reflection->getProperty($propertyName);

            $property->setAccessible(true);
            $property->setValue($this->target, $value);
        } else {
            $this->target->$propertyName = $value;
        }
    }

    /**
     * Retrieves a specific property of the target object by name using reflection.
     *
     * @param string $propertyName The name of the property to retrieve.
     *
     * @return ReflectionProperty The ReflectionProperty object representing the specified property of the target object.
     * @throws ReflectionException
     */
    public function getProperty(string $propertyName): ReflectionProperty
    {
        $reflection = new ReflectionObject($this->target);
        return $reflection->getProperty($propertyName);
    }

    /**
     * Retrieves the value of a specified property name from the given object.
     *
     * @param string $propertyName The name of the property name to be accessed.
     * @return mixed The value of the specified property name.
     * @throws ReflectionException
     */
    public function get(string $propertyName): mixed
    {
        $reflection = new ReflectionObject($this->target);
        $property = $reflection->getProperty($propertyName);

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

        try {
            $reflection = new ReflectionObject($this->target);
            $property = $reflection->getProperty($propertyName);

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
        } catch (ReflectionException $e) {
            return false;
        }

        return false;
    }

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

        try {
            $reflection = new ReflectionObject($this->target);
            $property = $reflection->getProperty($propertyName);

            $property->setAccessible(true);
            return $property->isInitialized($this->target);
        } catch (ReflectionException $e) {
            return false;
        }
    }

    /**
     * Retrieves the list of all property names from the target object, including both public properties
     * and those accessible through reflection.
     *
     * @return array An array of unique property names belonging to the target object.
     */
    public function getPropertyNames(): array
    {
        $reflection = new ReflectionObject($this->target);
        return array_unique(
            array_merge(
                array_keys(get_object_vars($this->target)),
                array_map(fn(ReflectionProperty $property) => $property->getName(), $reflection->getProperties())
            )
        );
    }

    /**
     * Retrieves and caches the property type of a given property within an object.
     *
     * If the property is declared within the class of the given object, its type is cached
     * for later calls to improve performance. If the property is dynamic, its type
     * is resolved without caching.
     *
     * @param object $object The object containing the property.
     * @param string $propertyName The name of the property whose type is being retrieved.
     * @return ReflectionType|null The type of the property, or null if the type could not be determined.
     * @throws ReflectionException
     */
    public function getCachedPropertyType(object $object, string $propertyName): ?ReflectionType
    {
        static $classPropertyTypeCache;
        if (null === $classPropertyTypeCache) {
            $classPropertyTypeCache = new WeakMap();
        }

        // Only cache if it's a declared property on the class
        $core = static::getReflectionClass($object::class);
        if (!$core->hasProperty($propertyName)) {
            // dynamic property: do not cache
            return $this->getPropertyType($propertyName);
        }

        $bucket = $classPropertyTypeCache[$core] ??= [];

        if (array_key_exists($propertyName, $bucket)) {
            return $bucket[$propertyName];
        }

        $type = $this->getPropertyType($propertyName);
        $bucket[$propertyName] = $type;
        $classPropertyTypeCache[$core] = $bucket;

        return $type;
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
     * Retrieves the type of the specified property of the target object using reflection.
     *
     * @param string $propertyName The name of the property whose type is to be retrieved.
     * @return ReflectionType|null The type of the property as a ReflectionType object, or null if the property does not exist or does not have a type.
     */
    public function getPropertyType(string $propertyName): ?ReflectionType
    {
        $reflection = new ReflectionObject($this->target);
        return $reflection->hasProperty($propertyName) ? $reflection->getProperty($propertyName)->getType() : null;
    }
}