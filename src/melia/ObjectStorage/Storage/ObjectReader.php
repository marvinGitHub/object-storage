<?php

namespace melia\ObjectStorage\Storage;

use DateTimeInterface;
use melia\ObjectStorage\Event\Context\ClassAliasCreationContext;
use melia\ObjectStorage\Event\Context\IOContext;
use melia\ObjectStorage\Event\Context\LazyTypeNotSupportedContext;
use melia\ObjectStorage\Event\DispatcherInterface;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\ClassAliasCreationFailureException;
use melia\ObjectStorage\Exception\CorruptedFileException;
use melia\ObjectStorage\Exception\DateTimeRestorationFailureException;
use melia\ObjectStorage\Exception\InvalidFileFormatException;
use melia\ObjectStorage\Exception\ObjectLoadingFailureException;
use melia\ObjectStorage\Exception\PropertyHydrationFailureException;
use melia\ObjectStorage\LazyLoadReference;
use melia\ObjectStorage\Logger\LoggerInterface;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Reflection\Reflection;
use melia\ObjectStorage\Runtime\ClassRenameMap;
use melia\ObjectStorage\Serialization\DateTimeValue;
use melia\ObjectStorage\Serialization\LifecycleGuard;
use melia\ObjectStorage\State\StateHandler;
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
 * Reading objects: an object built from its files, its properties hydrated, references as lazy load references (or
 * loaded right away for __unserialize()), dates restored. The caller holds the lock of the object.
 *
 * Referenced objects are loaded through the storage (the facade): with its cache, locks and events, as loading them
 * by the caller does. That is the one dependency on the facade (see docs/architecture.md).
 */
final readonly class ObjectReader
{
    public function __construct(
        private ObjectStorage        $storage,
        private ObjectFiles          $objectFiles,
        private ObjectSession        $session,
        private ObjectCache          $cache,
        private ?StrategyInterface   $strategy = null,
        private ?ClassRenameMap      $classRenameMap = null,
        private ?StateHandler        $stateHandler = null,
        private ?DispatcherInterface $dispatcher = null,
        private ?LoggerInterface     $logger = null,
    )
    {
    }

    /**
     * Reads an object from its files (see ObjectFiles::read()) and builds it.
     *
     * @param bool $addToCache Whether the object is put into the cache; false to read a stored state without
     *        replacing the cached object, e.g. the previous state of an object being stored.
     * @return object|null The object, or null if nothing is stored under the UUID.
     * @throws InvalidFileFormatException
     * @throws CorruptedFileException If the data can't be decoded (safe mode is enabled).
     * @throws Throwable
     */
    public function read(string $uuid, bool $addToCache = true): ?object
    {
        $stored = $this->objectFiles->read($uuid);
        if (null === $stored) {
            return null;
        }
        [$raw, $metadata, $filename] = $stored;

        // the data is verified by its checksum: data that can't be decoded all the same is a damaged file
        try {
            $data = $this->strategy?->unserialize($raw) ?? [];
        } catch (Throwable $e) {
            $this->logger?->log($e);
            $this->dispatcher?->dispatch(Events::JSON_DECODING_FAILURE, static fn() => new IOContext($filename));
            $this->stateHandler?->enableSafeMode();
            throw new CorruptedFileException($filename, sprintf('The data file %s holds no valid data: %s', $filename, $e->getMessage()), $e);
        }

        return $this->build($uuid, $data, $metadata, $addToCache);
    }

    /**
     * Builds the object from its decoded data and its metadata, and puts it into the cache.
     *
     * @throws Throwable
     */
    private function build(string $uuid, array $data, Metadata $metadata, bool $addToCache): object
    {
        $object = $this->createInstance($metadata->getClassName());

        if (LifecycleGuard::supportsUnserialize($object)) {
            // a cycle back to this object gets this instance, although it is not fully restored yet
            $this->session->restoring($uuid, $object, function () use ($object, $data, $metadata): void {
                LifecycleGuard::unserialize($object, $this->resolveReferences($data, $metadata));
            });
        } else {
            $this->hydrateProperties($object, $data, $metadata);
        }

        Helper::assign($object, $uuid);
        $this->session->recordRevision($object, $metadata->getRevision());
        $this->session->recordLoaded($object);

        if ($addToCache) {
            $this->cache->add($uuid, $object, $metadata->getLifetime());
        }

        return $object;
    }

    /**
     * An instance of a class, without its constructor; for an unknown class (neither renamed nor existing) an
     * alias of a placeholder class is created (CLASS_ALIAS_CREATED).
     *
     * @throws ClassAliasCreationFailureException If the class alias could not be created for an unknown class.
     * @throws ReflectionException If the class cannot be reflected or instantiated.
     */
    private function createInstance(string $className): object
    {
        $className = $this->classRenameMap?->getAlias($className) ?: $className;

        if (false === class_exists($className)) {
            // don't create alias from a stable class, otherwise all unknown aliases will be stored as a placeholder class
            if (false === class_alias(get_class(new #[\AllowDynamicProperties] class {
                }), $className)) {
                throw new ClassAliasCreationFailureException('Unable to create class alias for unknown class ' . $className);
            }
            $this->dispatcher?->dispatch(Events::CLASS_ALIAS_CREATED, static fn() => new ClassAliasCreationContext($className));
        }

        return Reflection::getReflectionClass($className)->newInstanceWithoutConstructor();
    }

    /**
     * Hydrates the properties of an object from its loaded data (references become lazy load references, see
     * hydrateArray()), for objects without __unserialize().
     *
     * @throws InvalidUUIDException
     * @throws ReflectionException
     * @throws PropertyHydrationFailureException
     */
    private function hydrateProperties(object $object, array $data, Metadata $metadata): void
    {
        $className = $metadata->getClassName();
        $reflection = new Reflection($object);
        $byCallback = PropertyHydration::CALLBACK === ($this->strategy?->getPolicyPropertyHydration() ?? StrategyInterface::DEFAULT_POLICY_PROPERTY_HYDRATION);

        foreach ($data as $propertyName => $value) {
            if ($byCallback && true !== $this->strategy->shouldHydrateProperty($reflection, $propertyName, $value)) {
                continue;
            }

            $finalValue = $this->propertyValue($object, $propertyName, $value, $metadata);

            try {
                $this->strategy?->hydrateProperty($reflection, $propertyName, $finalValue);
            } catch (Throwable $e) {
                throw new PropertyHydrationFailureException(
                    message: sprintf('Unable to hydrate property "%s" of class "%s"', $propertyName, $className),
                    previous: $e,
                );
            }
        }
    }

    /**
     * The value of a property as loaded: a date restored (converted to the declared type), a reference as a
     * LazyLoadReference (the referenced object itself if the type of the property doesn't allow one), arrays
     * hydrated (hydrateArray()); other values as stored.
     *
     * @throws InvalidUUIDException
     * @throws ReflectionException
     */
    private function propertyValue(object $object, int|string $propertyName, mixed $value, Metadata $metadata): mixed
    {
        $type = Reflection::getPropertyType($object, $propertyName);
        $reserved = $metadata->getReservedReferenceName();

        if (DateTimeValue::isEncoded($value, $metadata->getReservedDateTimeName())) {
            return $this->restoreDateTime($value, $metadata, $type);
        }
        if (!is_array($value)) {
            return $value;
        }
        if (!isset($value[$reserved])) {
            return $this->hydrateArray($metadata, $object, $value, [$propertyName]);
        }

        $referencedUuid = $value[$reserved];
        if (false === Validator::validate($referencedUuid)) {
            return [$reserved => $referencedUuid]; // not a valid reference: the value as stored
        }

        $reference = new LazyLoadReference($this->storage, $referencedUuid, $object, [$propertyName]);
        if (Reflection::acceptsLazyReference($type)) {
            return $reference;
        }
        $className = $metadata->getClassName();
        $this->dispatcher?->dispatch(Events::LAZY_TYPE_NOT_SUPPORTED, static fn() => new LazyTypeNotSupportedContext($className, $propertyName));
        return $reference->getObject();
    }

    /**
     * Hydrates an array of loaded data: references to other objects become lazy load references, nested arrays are
     * hydrated recursively.
     *
     * @param array $path The path of the array within the object, for the lazy load references.
     * @throws InvalidUUIDException
     */
    private function hydrateArray(Metadata $metadata, object $object, array $array, array $path): array
    {
        $processed = [];
        foreach ($array as $key => $value) {
            $childPath = [...$path, $key];

            if (DateTimeValue::isEncoded($value, $metadata->getReservedDateTimeName())) {
                $processed[$key] = $this->restoreDateTime($value, $metadata);
            } else if (is_array($value) && isset($value[$metadata->getReservedReferenceName()])) {
                $processed[$key] = new LazyLoadReference($this->storage, $value[$metadata->getReservedReferenceName()], $object, $childPath);
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
     * @return array The data with the referenced objects, or null for objects that no longer exist.
     * @throws ObjectLoadingFailureException If a referenced object cannot be loaded.
     */
    private function resolveReferences(array $data, Metadata $metadata): array
    {
        $reserved = $metadata->getReservedReferenceName();

        foreach ($data as $key => $value) {
            if (false === is_array($value)) {
                continue;
            }

            if (DateTimeValue::isEncoded($value, $metadata->getReservedDateTimeName())) {
                $data[$key] = $this->restoreDateTime($value, $metadata);
            } else if (isset($value[$reserved]) && is_string($value[$reserved]) && Validator::validate($value[$reserved])) {
                $data[$key] = $this->session->restored($value[$reserved]) ?? $this->storage->load($value[$reserved]);
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
     * @throws DateTimeRestorationFailureException If the point in time can't be read.
     */
    private function restoreDateTime(array $value, Metadata $metadata, ?ReflectionType $type = null): DateTimeInterface
    {
        $logger = $this->logger;
        $onFailure = static function (Throwable $e) use ($logger): void {
            $logger?->log($e);
        };

        $date = DateTimeValue::decode($value, $metadata->getReservedDateTimeName(), $this->classRenameMap, $onFailure);

        if ($type instanceof ReflectionNamedType && false === $type->isBuiltin()) {
            $className = $type->getName();
            if (false === $date instanceof $className) {
                $date = DateTimeValue::convert($date, $className, $onFailure);
            }
        }

        return $date;
    }
}
