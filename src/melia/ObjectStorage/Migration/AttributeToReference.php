<?php

namespace melia\ObjectStorage\Migration;

use melia\ObjectStorage\LazyLoadReference;
use melia\ObjectStorage\Migration\Exception\Exception;
use melia\ObjectStorage\Reflection\Reflection;
use melia\ObjectStorage\Storage\StorageAwareTrait;
use melia\ObjectStorage\UUID\Helper;
use melia\ObjectStorage\UUID\Validator;
use ReflectionException;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * Links objects afterwards: an attribute holding the UUID of a stored object as text, or a list of them, becomes a
 * reference to that object, e.g. "friend": "3f2a…" becomes {"__reference": "3f2a…"}. Loading the object then yields
 * the referenced object, and search indexes can follow the attribute (paths like "friend.name").
 *
 * Values that aren't UUIDs, and references, stay as they are; UUIDs of objects that don't exist too, see
 * getMissing(). The referenced objects are neither loaded nor written. The attribute must be untyped (e.g. of a
 * stdClass), hold a list, or accept an object (object, mixed or LazyLoadReference).
 */
class AttributeToReference implements MigrationInterface
{
    use StorageAwareTrait;

    /** @var string[] UUIDs without an object found by the last apply() */
    private array $missing = [];

    public function __construct(private readonly string $attribute)
    {
    }

    /**
     * @param object $object The object, e.g. loaded from the storage.
     * @param string|null $uuid The UUID of the object, to store it under; otherwise the one it was stored or loaded
     *        with if the storage knows it, see ObjectStorage::store().
     * @return bool Whether a UUID became a reference; the object has been stored then.
     * @throws Exception|ReflectionException
     */
    public function apply(object $object, ?string $uuid = null): bool
    {
        $storage = $this->getStorage() ?? throw new Exception('No storage to store the object in.');
        $this->missing = [];

        $reflection = new Reflection($object);
        if (false === $reflection->initialized($this->attribute)) {
            return false;
        }

        $value = $reflection->get($this->attribute);
        if (!is_array($value) && !self::acceptsReference(Reflection::getPropertyType($object, $this->attribute))) {
            throw new Exception(sprintf('The attribute "%s" of %s is typed and can\'t hold a reference.', $this->attribute, $object::class));
        }

        $changed = false;
        // unloaded references: storing writes them as references, without reading or writing the referenced objects
        $link = function (mixed $element, array $path) use ($storage, $object, &$changed): mixed {
            if (!is_string($element) || !Validator::validate($element)) {
                return $element;
            }
            if (!$storage->exists($element)) {
                $this->missing[] = $element;
                return $element;
            }
            $changed = true;
            return new LazyLoadReference($storage, $element, $object, $path);
        };

        if (is_array($value)) {
            $linked = [];
            foreach ($value as $key => $element) {
                $linked[$key] = $link($element, [$this->attribute, $key]);
            }
        } else {
            $linked = $link($value, [$this->attribute]);
        }
        if (!$changed) {
            return false;
        }

        $reflection->set($this->attribute, $linked);
        $storage->store($object, $uuid ?? Helper::getAssigned($object));
        return true;
    }

    /**
     * @return string[] The UUIDs the last apply() found no object for; they stay text.
     */
    public function getMissing(): array
    {
        return $this->missing;
    }

    private static function acceptsReference(?ReflectionType $type): bool
    {
        if (null === $type) {
            return true;
        }
        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
        foreach ($types as $named) {
            if ($named instanceof ReflectionNamedType && in_array($named->getName(), ['object', 'mixed', LazyLoadReference::class], true)) {
                return true;
            }
        }
        return false;
    }
}
