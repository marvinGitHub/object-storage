<?php

namespace melia\ObjectStorage\Reflection;

use ReflectionException;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionProperty;
use ReflectionType;

/**
 * A utility class that provides reflection-based methods
 * to dynamically interact with property names of an object.
 */
class Reflection
{
    private object $target;

    /**
     * Small per-class reflection cache to avoid repeated ReflectionObject/ReflectionProperty creation.
     * Structure:
     *  - self::$cache[$class]['properties'][$propertyName] = ReflectionProperty
     *  - self::$cache[$class]['hasProperty'][$propertyName] = bool
     *  - self::$cache[$class]['propertyNames'] = array<string>
     */
    private static array $cache = [];

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
     * Sets the value of a specified property name on an object using reflection cache.
     *
     * @param string $propertyName
     * @param mixed $value
     * @return void
     */
    public function set(string $propertyName, mixed $value): void
    {
        $class = get_class($this->target);

        // fast path for dynamic/public properties
        // if we previously learned it is not a declared property, set directly
        if ($this->hasDeclaredPropertyCached($class, $propertyName) === false) {
            $this->target->$propertyName = $value;
            return;
        }

        $property = $this->getPropertyCached($class, $propertyName);
        if ($property) {
            $property->setValue($this->target, $value);
            return;
        }

        // not a declared property, set dynamically
        $this->target->$propertyName = $value;
        $this->rememberHasProperty($class, $propertyName, false);
    }

    /**
     * Retrieves a specific property of the target object by name using reflection cache.
     *
     * @param string $propertyName
     * @return ReflectionProperty
     * @throws ReflectionException
     */
    public function getProperty(string $propertyName): ReflectionProperty
    {
        $class = get_class($this->target);
        $property = $this->getPropertyCached($class, $propertyName);

        if (!$property) {
            // Will throw ReflectionException if it doesn't exist, matching original behavior
            $reflection = new ReflectionObject($this->target);
            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);
            $this->rememberProperty($class, $propertyName, $property, true);
        }

        return $property;
    }

    /**
     * Retrieves the value of a specified property name from the given object.
     *
     * @param string $propertyName
     * @return mixed
     * @throws ReflectionException
     */
    public function get(string $propertyName): mixed
    {
        $property = $this->getProperty($propertyName);
        return $property->isInitialized($this->target) ? $property->getValue($this->target) : null;
    }

    /**
     * Unsets the value of a specified property name from the given object.
     * If the property does not allow null, it will be set to a default value.
     *
     * @param string $propertyName
     * @return bool
     */
    public function unset(string $propertyName): bool
    {
        if (isset($this->target->{$propertyName})) {
            unset($this->target->{$propertyName});
            return true;
        }

        try {
            $property = $this->getProperty($propertyName);

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

    /**
     * Determines the default value for a given type name.
     *
     * @param string $typeName The name of the type for which a default value is required.
     * @return mixed The default value corresponding to the specified type. For unknown types, null is returned.
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
     * Checks if a specified property exists and is initialized in the given object.
     *
     * @param string $propertyName
     * @return bool
     */
    public function initialized(string $propertyName): bool
    {
        if (isset($this->target->{$propertyName})) {
            return true;
        }

        try {
            $class = get_class($this->target);
            $property = $this->getPropertyCached($class, $propertyName);

            if (!$property) {
                // If class definitely has no such property, it's not initialized
                if ($this->hasDeclaredPropertyCached($class, $propertyName) === false) {
                    return false;
                }
                // Try to resolve and cache once
                $reflection = new ReflectionObject($this->target);
                if (!$reflection->hasProperty($propertyName)) {
                    $this->rememberHasProperty($class, $propertyName, false);
                    return false;
                }
                $property = $reflection->getProperty($propertyName);
                $property->setAccessible(true);
                $this->rememberProperty($class, $propertyName, $property, true);
            }

            return $property->isInitialized($this->target);
        } catch (ReflectionException $e) {
            return false;
        }
    }

    /**
     * Retrieves the list of all property names from the target object.
     *
     * @return array
     */
    public function getPropertyNames(): array
    {
        $class = get_class($this->target);
        $declared = $this->getDeclaredPropertyNamesCached($class);

        return array_unique(
            array_merge(
                array_keys(get_object_vars($this->target)),
                $declared
            )
        );
    }

    /**
     * Retrieves the type of the specified property of the target object using reflection cache.
     *
     * @param string $propertyName
     * @return ReflectionType|null
     */
    public function getPropertyType(string $propertyName): ?ReflectionType
    {
        $class = get_class($this->target);
        $property = $this->getPropertyCached($class, $propertyName);

        if (!$property) {
            // avoid creating ReflectionObject if we know it doesn't exist
            $has = $this->hasDeclaredPropertyCached($class, $propertyName);
            if ($has === false) {
                return null;
            }
            $reflection = new ReflectionObject($this->target);
            if (!$reflection->hasProperty($propertyName)) {
                $this->rememberHasProperty($class, $propertyName, false);
                return null;
            }
            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);
            $this->rememberProperty($class, $propertyName, $property, true);
        }

        return $property->getType();
    }

    /**
     * Retrieves a cached property reflection for a given class and property name.
     * If not already cached, it attempts to resolve and cache the property.
     *
     * @param string $class The name of the class containing the property.
     * @param string $name The name of the property to retrieve.
     * @return ReflectionProperty|null The reflection of the requested property, or null if the property does not exist or cannot be accessed.
     */
    private function getPropertyCached(string $class, string $name): ?ReflectionProperty
    {
        if (isset(self::$cache[$class]['properties'][$name])) {
            return self::$cache[$class]['properties'][$name];
        }

        if (isset(self::$cache[$class]['hasProperty'][$name]) && self::$cache[$class]['hasProperty'][$name] === false) {
            return null;
        }

        $reflection = new ReflectionObject($this->target);
        if (!$reflection->hasProperty($name)) {
            $this->rememberHasProperty($class, $name, false);
            return null;
        }

        $prop = $reflection->getProperty($name);
        $prop->setAccessible(true);
        $this->rememberProperty($class, $name, $prop, true);
        return $prop;
    }

    /**
     * Determines if a given class has a declared property, utilizing a cached result.
     *
     * @param string $class The name of the class to check.
     * @param string $name The name of the property to look for.
     * @return bool|null Returns true if the property is declared, false if not, or null if the result is not cached.
     */
    private function hasDeclaredPropertyCached(string $class, string $name): ?bool
    {
        if (isset(self::$cache[$class]['hasProperty'][$name])) {
            return self::$cache[$class]['hasProperty'][$name];
        }
        return null;
    }

    /**
     * Caches the reflection property and its existence information for a specified class and property name.
     *
     * @param string $class The name of the class containing the property.
     * @param string $name The name of the property to cache.
     * @param ReflectionProperty $prop The reflection property to be cached.
     * @param bool $has A flag indicating whether the property exists in the class.
     * @return void
     */
    private function rememberProperty(string $class, string $name, ReflectionProperty $prop, bool $has): void
    {
        self::$cache[$class]['properties'] ??= [];
        self::$cache[$class]['hasProperty'] ??= [];

        self::$cache[$class]['properties'][$name] = $prop;
        self::$cache[$class]['hasProperty'][$name] = $has;

        unset(self::$cache[$class]['propertyNames']);
    }

    /**
     * Caches whether a given class has a specific property.
     *
     * @param string $class The name of the class to associate with the property.
     * @param string $name The name of the property to check.
     * @param bool $has A boolean indicating whether the property exists in the class.
     * @return void
     */
    private function rememberHasProperty(string $class, string $name, bool $has): void
    {
        self::$cache[$class]['hasProperty'] ??= [];
        self::$cache[$class]['hasProperty'][$name] = $has;
        unset(self::$cache[$class]['propertyNames']);
    }

    /**
     * Retrieves a cached list of declared property names for a given class.
     * If not already cached, it resolves the property names, caches them,
     * and ensures each property's reflection is accessible and stored in the cache.
     *
     * @param string $class The name of the class whose declared property names should be retrieved.
     * @return array An array of property names declared in the specified class.
     */
    private function getDeclaredPropertyNamesCached(string $class): array
    {
        if (isset(self::$cache[$class]['propertyNames'])) {
            return self::$cache[$class]['propertyNames'];
        }

        $reflection = new ReflectionObject($this->target);
        $names = array_map(
            static fn(ReflectionProperty $p) => $p->getName(),
            $reflection->getProperties()
        );

        self::$cache[$class]['properties'] ??= [];
        self::$cache[$class]['hasProperty'] ??= [];
        foreach ($reflection->getProperties() as $p) {
            $name = $p->getName();
            if (!isset(self::$cache[$class]['properties'][$name])) {
                $p->setAccessible(true);
                self::$cache[$class]['properties'][$name] = $p;
            }
            self::$cache[$class]['hasProperty'][$name] = true;
        }

        self::$cache[$class]['propertyNames'] = $names;
        return $names;
    }
}