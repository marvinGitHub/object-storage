<?php

namespace melia\ObjectStorage\Storage;

use DateTimeImmutable;
use DateTimeInterface;
use melia\ObjectStorage\Event\Context\ClassAliasCreationContext;
use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\Context\LazyTypeNotSupportedContext;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\ClassAliasCreationFailureException;
use melia\ObjectStorage\Exception\DanglingReferenceException;
use melia\ObjectStorage\Exception\DateTimeRestorationFailureException;
use melia\ObjectStorage\Exception\InvalidFileFormatException;
use melia\ObjectStorage\Exception\ObjectLoadingFailureException;
use melia\ObjectStorage\Exception\PropertyHydrationFailureException;
use melia\ObjectStorage\Exception\SafeModeActivationFailedException;
use melia\ObjectStorage\Exception\SerializationFailureException;
use melia\ObjectStorage\Exception\TypeConversionFailureException;
use melia\ObjectStorage\LazyLoadReference;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\Reflection\Reflection;
use melia\ObjectStorage\Serialization\DateTimeValue;
use melia\ObjectStorage\Serialization\LifecycleGuard;
use melia\ObjectStorage\Strategy\Policy\PropertyHydration;
use melia\ObjectStorage\Strategy\StrategyInterface;
use melia\ObjectStorage\UUID\Exception\InvalidUUIDException;
use melia\ObjectStorage\UUID\Helper;
use melia\ObjectStorage\UUID\Validator;
use ReflectionException;
use ReflectionNamedType;
use ReflectionType;
use Throwable;

/**
 * Loading objects: load() → loadFromFiles() → readObjectFiles() → hydrateObject().
 *
 * Part of ObjectStorage, kept apart for readability.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait LoadingTrait
{
    /**
     * Objects whose __unserialize() is running, so a reference cycle gets the instance being restored
     * instead of loading it again endlessly (as PHP's unserialize() does).
     *
     * @var array<string, object>
     */
    private array $restoring = [];

    /** how many loads of referenced objects are running (loadReferenced()), nested ones included */
    private int $loadingReferences = 0;
    /**
     * Loads an object from persistent storage using its unique identifier.
     * The method optionally applies locking mechanisms during the loading process.
     *
     * @param string $uuid The unique identifier of the object to be loaded.
     * @param bool $exclusive Determines whether the object should remain locked after loading (true) or not (false).
     * @return object|null Returns the loaded object if found, or null if the object does not exist.
     * @throws Throwable If an error occurs during the loading process.
     */
    public function load(string $uuid, bool $exclusive = false): ?object
    {
        $eventDispatcher = $this->getEventDispatcher();
        $lockAdapter = $this->getLockAdapter();

        $eventDispatcher?->dispatch(Events::BEFORE_LOAD, static fn() => new Context($uuid));

        if ($this->expired($uuid)) {
            /* do not delete an expired object since the ttl might be updated later */
            return null;
        }

        $cache = $this->getCache();

        // For non-exclusive reads a cache hit can be served immediately without locking,
        // as no exclusivity guarantee was requested. An exclusive load always reads the
        // stored state under the lock (see below), so it never short-circuits here.
        if (!$exclusive) {
            $cached = $cache?->get($uuid);
            if (null !== $cached) {
                $eventDispatcher?->dispatch(Events::CACHE_HIT, static fn() => new Context($uuid));
                $this->rememberLoaded($cached, $uuid);
                return $cached;
            }
        }

        // a lock held before this call belongs to the caller (e.g. a previous exclusive load or a
        // running store) and must survive this load, both on success and on failure
        $lockedBefore = $lockAdapter?->isLockedByThisProcess($uuid) ?? false;

        try {
            if ($exclusive) {
                // an exclusive load is the start of a read-modify-write: it must return the state
                // stored right now, not a cached copy, which doesn't know about changes of other
                // processes; reading from storage replaces the cached copy as well
                $lockAdapter?->acquireExclusiveLock($uuid);
            } else {
                $lockAdapter?->acquireSharedLock($uuid);
            }

            $object = $this->loadFromFiles($uuid);

            if (!$exclusive && !$lockedBefore) {
                $lockAdapter?->releaseLock($uuid);
            }

            $eventDispatcher?->dispatch(Events::AFTER_LOAD, static fn() => new Context($uuid));

            if (null !== $object) {
                $this->rememberLoaded($object, $uuid);
            }

            return $object;
        } catch (Throwable $e) {
            if (!$lockedBefore && $lockAdapter?->isLockedByThisProcess($uuid)) {
                $lockAdapter?->releaseLock($uuid);
            }
            $eventDispatcher?->dispatch(Events::OBJECT_LOADING_FAILURE, static fn() => new Context($uuid));
            throw new ObjectLoadingFailureException(message: sprintf('Unable to load object with uuid: %s', $uuid), previous: $e);
        }
    }

    /**
     * Loads a referenced object, for a LazyLoadReference or the references passed to __unserialize(). Unlike
     * load() by the caller, the instance isn't remembered under its UUID (see rememberLoaded()).
     *
     * @internal
     * @throws Throwable
     */
    public function loadReferenced(string $uuid): ?object
    {
        $this->loadingReferences++;
        try {
            return $this->load($uuid);
        } finally {
            $this->loadingReferences--;
        }
    }

    /**
     * Remembers the UUID of an instance returned by load(), so storing it again updates the object instead of
     * storing a copy under a new UUID (objects without AwareInterface don't carry their UUID themselves).
     *
     * Not for objects loaded as references of another one: storing the referencing object writes referenced objects
     * by the child persistence policy, which (by default) doesn't write existing ones; they are written as new
     * objects, with their changes, as long as they aren't known (as before).
     */
    private function rememberLoaded(object $object, string $uuid): void
    {
        if (0 === $this->loadingReferences && !isset($this->identityMap[$object])) {
            $this->identityMap[$object] = $uuid;
        }
    }

    /**
     * Loads an object based on a UUID from its files.
     *
     * @param string $uuid The unique ID of the object to be loaded.
     * @param bool $addToCache Whether the loaded object is put into the cache; false to read a stored state
     *                         without replacing the cached object, e.g. the previous state of an object being stored.
     * @return object|null The loaded object or null if it doesn't exist.
     * @throws InvalidFileFormatException
     * @throws InvalidUUIDException
     * @throws ReflectionException
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     * @throws TypeConversionFailureException|Throwable
     */
    protected function loadFromFiles(string $uuid, bool $addToCache = true): ?object
    {
        $stored = $this->readObjectFiles($uuid);
        if (null === $stored) {
            return null;
        }
        [$raw, $metadata, $filename] = $stored;

        $data = $this->getJsonFileReader()->decode($raw, $filename);

        return $this->hydrateObject($uuid, $data, $metadata, $addToCache);
    }

    /**
     * Builds the object from its decoded data and its metadata, and puts it into the cache.
     *
     * @throws Throwable
     */
    protected function hydrateObject(string $uuid, array $data, Metadata $metadata, bool $addToCache): object
    {

        // build object using the single metadata instance
        $object = $this->createInstance($metadata->getClassName());

        if (LifecycleGuard::supportsUnserialize($object)) {
            // a cycle back to this object gets this instance, although it is not fully restored yet
            $this->restoring[$uuid] = $object;
            try {
                LifecycleGuard::unserialize($object, $this->resolveReferences($data, $metadata));
            } finally {
                unset($this->restoring[$uuid]);
            }
        } else {
            $this->hydrateProperties($object, $data, $metadata);
        }

        Helper::assign($object, $uuid);
        $this->revisions[$object] = $metadata->getRevision();

        if ($addToCache) {
            $this->addToCache($uuid, $object, $metadata->getLifetime());
        }

        return $object;
    }

    /**
     * Creates an instance of the specified class name. If the class does not exist,
     * attempts to create a class alias and dispatches an event upon successful creation.
     *
     * @param string $className The name of the class to instantiate.
     * @return object An instance of the specified class.
     * @throws ClassAliasCreationFailureException If the class alias could not be created for an unknown class.
     * @throws ReflectionException If the class cannot be reflected or instantiated.
     */
    protected function createInstance(string $className): object
    {
        $classNameOverride = $this->getClassRenameMap()?->getAlias($className);
        if ($classNameOverride) {
            $className = $classNameOverride;
        }

        if (false === class_exists($className)) {
            // don't create alias from a stable class, otherwise all unknown aliases will be stored as a placeholder class
            if (false === class_alias(get_class(new #[\AllowDynamicProperties] class {
                }), $className)) {
                throw new ClassAliasCreationFailureException('Unable to create class alias for unknown class ' . $className);
            }
            $this->getEventDispatcher()?->dispatch(Events::CLASS_ALIAS_CREATED, static fn() => new ClassAliasCreationContext($className));
        }

        return Reflection::getReflectionClass($className)->newInstanceWithoutConstructor();
    }

    /**
     * Hydrates the properties of an object from its loaded data (references become lazy load references, see
     * hydrateArray()), for objects without __unserialize().
     *
     * @param object $object
     * @param array $data An associative array containing property names and their corresponding values.
     * @param Metadata $metadata
     * @return object An instance of the specified class with its properties populated from the provided data.
     * @throws DanglingReferenceException
     * @throws InvalidUUIDException
     * @throws ReflectionException
     * @throws PropertyHydrationFailureException
     */
    protected function hydrateProperties(object $object, array $data, Metadata $metadata): object
    {
        $strategy = $this->getStrategy();
        $className = $metadata->getClassName();
        $reflection = new Reflection($object);

        foreach ($data as $propertyName => $value) {
            if ((($strategy?->getPolicyPropertyHydration() ?? StrategyInterface::DEFAULT_POLICY_PROPERTY_HYDRATION) === PropertyHydration::CALLBACK) && true !== $strategy->shouldHydrateProperty($reflection, $propertyName, $value)) {
                continue;
            }

            $finalValue = $value;
            $type = Reflection::getPropertyType($object, $propertyName);

            if (DateTimeValue::isEncoded($value, $metadata->getReservedDateTimeName())) {
                $finalValue = $this->restoreDateTime($value, $metadata, $type);
            } else if (is_array($value) && isset($value[$metadata->getReservedReferenceName()])) {
                $referencedUuid = $value[$metadata->getReservedReferenceName()];
                if (false === Validator::validate($referencedUuid)) {
                    /* reference UUID is not valid, so we just set the property to the value */
                    $finalValue = [$metadata->getReservedReferenceName() => $referencedUuid];
                } else {
                    $reference = new LazyLoadReference($this, $referencedUuid, $object, [$propertyName]);

                    if (false === $this->supportsLazyReference($type)) {
                        $this->getEventDispatcher()?->dispatch(Events::LAZY_TYPE_NOT_SUPPORTED, static fn() => new LazyTypeNotSupportedContext($className, $propertyName));
                        $reference = $reference->getObject();
                    }

                    $finalValue = $reference;
                }
            } else if (is_array($value)) {
                $finalValue = $this->hydrateArray($metadata, $object, $value, [$propertyName]);
            }

            try {
                $strategy?->hydrateProperty($reflection, $propertyName, $finalValue);
            } catch (Throwable $e) {
                throw new PropertyHydrationFailureException(
                    message: sprintf('Unable to hydrate property "%s" of class "%s"', $propertyName, $className),
                    previous: $e,
                );
            }
        }

        return $object;
    }

    /**
     * Hydrates an array of loaded data: references to other objects become lazy load references, nested arrays are
     * hydrated recursively, based on the metadata provided.
     *
     * @param Metadata $metadata The metadata object used to identify and process reserved references.
     * @param object $object The object to be used in creating LazyLoadReference instances.
     * @param array $array The input array to be processed.
     * @param array $path An array representing the traversal path within the structure for recursion.
     *
     * @return array The processed array, with applicable elements converted into lazy load references
     *               or recursively processed subarrays.
     * @throws InvalidUUIDException
     */
    protected function hydrateArray(Metadata $metadata, object $object, array $array, array $path): array
    {
        $processed = [];
        foreach ($array as $key => $value) {
            $childPath = $path;
            $childPath[] = $key;

            if (DateTimeValue::isEncoded($value, $metadata->getReservedDateTimeName())) {
                $processed[$key] = $this->restoreDateTime($value, $metadata);
            } else if (is_array($value) && isset($value[$metadata->getReservedReferenceName()])) {
                $processed[$key] = new LazyLoadReference($this, $value[$metadata->getReservedReferenceName()], $object, $childPath);
            } else if (is_array($value)) {
                $processed[$key] = $this->hydrateArray($metadata, $object, $value, $childPath);
            } else {
                $processed[$key] = $value;
            }
        }
        return $processed;
    }

    /**
     * Replaces the references in the data passed to __unserialize() by the referenced objects, so it gets
     * what __serialize() returned. Unlike properties, the data has no types to decide whether a
     * LazyLoadReference fits, so the referenced objects are loaded right away.
     *
     * @param array $data The stored data, nested arrays included.
     * @param Metadata $metadata The metadata holding the reserved reference name.
     * @return array The data with the referenced objects, or null for objects that no longer exist.
     * @throws ObjectLoadingFailureException If a referenced object cannot be loaded.
     */
    protected function resolveReferences(array $data, Metadata $metadata): array
    {
        $reserved = $metadata->getReservedReferenceName();

        foreach ($data as $key => $value) {
            if (false === is_array($value)) {
                continue;
            }

            if (DateTimeValue::isEncoded($value, $metadata->getReservedDateTimeName())) {
                $data[$key] = $this->restoreDateTime($value, $metadata);
            } else if (isset($value[$reserved]) && is_string($value[$reserved]) && Validator::validate($value[$reserved])) {
                $data[$key] = $this->restoring[$value[$reserved]] ?? $this->loadReferenced($value[$reserved]);
            } else {
                $data[$key] = $this->resolveReferences($value, $metadata);
            }
        }

        return $data;
    }

    /**
     * Restores a date stored inline (see Serialization\DateTimeValue). With the declared type of a property, a date of
     * another class is converted to it, e.g. a stored DateTime for a property declared as DateTimeImmutable.
     * What can't be restored as stored (an unknown class or time zone) is logged.
     *
     * @param array $value The encoded date.
     * @param Metadata $metadata The metadata holding the reserved key of dates.
     * @param ReflectionType|null $type The declared type of the property, if any.
     * @throws DateTimeRestorationFailureException If the point in time can't be read.
     */
    protected function restoreDateTime(array $value, Metadata $metadata, ?ReflectionType $type = null): DateTimeInterface
    {
        $logger = $this->getLogger();
        $onFailure = static function (Throwable $e) use ($logger): void {
            $logger?->log($e);
        };

        $date = DateTimeValue::decode($value, $metadata->getReservedDateTimeName(), $this->getClassRenameMap(), $onFailure);

        if ($type instanceof ReflectionNamedType && false === $type->isBuiltin()) {
            $className = $type->getName();
            if (false === $date instanceof $className) {
                $date = DateTimeValue::convert($date, $className, $onFailure);
            }
        }

        return $date;
    }

    /**
     * Determines if the given type supports lazy references based on the provided type reflection.
     * It checks various scenarios, including no type-hint, named types, and union types.
     *
     * @param ReflectionType|null $type The reflection of the type to check, which can be null,
     *                                  a named type, or a union type.
     *
     * @return bool Returns true if the type supports lazy references; otherwise, false.
     * @see Reflection::acceptsLazyReference()
     */
    protected function supportsLazyReference(?ReflectionType $type): bool
    {
        return Reflection::acceptsLazyReference($type);
    }
}
