<?php

namespace melia\ObjectStorage\Migration;

use melia\ObjectStorage\LazyLoadReference;
use melia\ObjectStorage\Migration\Exception\Exception;
use melia\ObjectStorage\Path\Exception\InvalidPathException;
use melia\ObjectStorage\Path\Path;
use melia\ObjectStorage\Path\PathSegment;
use melia\ObjectStorage\Reflection\Reflection;
use melia\ObjectStorage\Storage\StorageAwareTrait;
use melia\ObjectStorage\UUID\Helper;
use melia\ObjectStorage\UUID\Validator;
use ReflectionException;

/**
 * Links objects afterwards: an attribute holding the UUID of a stored object as text, or a list of them, becomes a
 * reference to that object, e.g. "friend": "3f2a…" becomes {"__reference": "3f2a…"}. Loading the object then yields
 * the referenced object, and search indexes can follow the attribute (paths like "friend.name").
 *
 * The attribute is a name or a path to a value nested in the object (see Path): `address.owner`,
 * `contacts['alex']`, `friends[0]`, `friends[*].partner`. Plain names, keys and positions name exactly one value;
 * a wildcard all elements of a list (or values of a map). A path can't lead into a referenced object (link it
 * there). To name an attribute that isn't a plain name (dots, brackets, quotes), write it as a key: `['a.b']`.
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

    /** @var list<string|PathSegment> */
    private readonly array $segments;

    /**
     * @throws InvalidPathException If the attribute is neither a plain name nor a valid path.
     */
    public function __construct(private readonly string $attribute)
    {
        $this->segments = Path::isPlainName($attribute) ? [$attribute] : Path::parse($attribute);
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
        // the value at the path: a UUID, or a list of them
        $leaf = static function (mixed $value, array $path) use ($link): mixed {
            if (!is_array($value)) {
                return $link($value, $path);
            }
            $linked = [];
            foreach ($value as $key => $element) {
                $linked[$key] = $link($element, [...$path, $key]);
            }
            return $linked;
        };

        $this->walk($object, $this->segments, [], $leaf, false);
        if (!$changed) {
            return false;
        }

        $storage->store($object, $uuid ?? Helper::getAssigned($object));
        return true;
    }

    /**
     * Sets the value at the path to a reference to the object $target, whatever it held; the last segment of the path
     * may be missing yet (a new attribute or key). The path must name one value (no wildcard).
     *
     * @param object $object The object, e.g. loaded from the storage.
     * @param string $target The UUID of the stored object to refer to.
     * @param string|null $uuid The UUID of the object, as for apply().
     * @throws Exception|ReflectionException
     */
    public function linkTo(object $object, string $target, ?string $uuid = null): void
    {
        $storage = $this->getStorage() ?? throw new Exception('No storage to store the object in.');
        $this->missing = [];

        if (Path::hasWildcard($this->segments)) {
            throw new Exception(sprintf('"%s" names more than one value; link one value to an object.', $this->attribute));
        }
        if (!Validator::validate($target)) {
            throw new Exception(sprintf('"%s" is not a UUID.', $target));
        }
        if (!$storage->exists($target)) {
            throw new Exception(sprintf('There is no object %s.', $target));
        }

        $set = false;
        $this->walk($object, $this->segments, [], function (mixed $value, array $path) use ($storage, $object, $target, &$set): LazyLoadReference {
            $set = true;
            return new LazyLoadReference($storage, $target, $object, $path);
        }, true);
        if (!$set) {
            throw new Exception(sprintf('"%s" leads to no value of the object.', $this->attribute));
        }

        $storage->store($object, $uuid ?? Helper::getAssigned($object));
    }

    /**
     * @return string[] The UUIDs the last apply() found no object for; they stay text.
     */
    public function getMissing(): array
    {
        return $this->missing;
    }

    /**
     * Replaces the values at the segments within $container by what $leaf returns for them.
     *
     * @param list<string|PathSegment> $segments The remaining segments, at least one.
     * @param list<string|int> $path The keys from the object to $container, as LazyLoadReference takes them.
     * @param callable(mixed, list<string|int>): mixed $leaf
     * @param bool $create Whether the last segment may name a value that doesn't exist yet.
     * @return mixed The container, changed (arrays are values: the caller sets them back).
     * @throws Exception|ReflectionException
     */
    private function walk(mixed $container, array $segments, array $path, callable $leaf, bool $create): mixed
    {
        $segment = array_shift($segments);
        $last = [] === $segments;

        // references are held unloaded (reading them through Reflection doesn't load them)
        if ($container instanceof LazyLoadReference) {
            throw new Exception(sprintf('"%s" leads into the referenced object at "%s"; link the attribute of that object.', $this->attribute, Path::toString(array_map('strval', $path))));
        }

        $keys = $this->keys($container, $segment, $last && $create, $path);

        foreach ($keys as $key) {
            $exists = $this->has($container, $key);
            $value = $exists ? $this->get($container, $key) : null;
            $at = [...$path, $key];

            if ($last) {
                if (!$exists && !$create) {
                    continue;
                }
                if (is_object($container) && !is_array($value)) {
                    // a property must be able to hold the reference
                    if (!Reflection::acceptsLazyReference(Reflection::getPropertyType($container, (string)$key))) {
                        throw new Exception(sprintf('The attribute "%s" of %s is typed and can\'t hold a reference.', $this->attribute, $container::class));
                    }
                }
                $new = $leaf($value, $at);
            } elseif (is_array($value) || is_object($value)) {
                $new = $this->walk($value, $segments, $at, $leaf, $create);
            } else {
                // nothing nested here
                if ($create) {
                    throw new Exception(sprintf('"%s" leads to no value of the object: nothing at "%s".', $this->attribute, Path::toString(array_map('strval', $at))));
                }
                continue;
            }

            if ($new !== $value || is_array($new)) {
                $this->set($container, $key, $new);
            }
        }

        return $container;
    }

    /**
     * The keys a segment names in a container.
     *
     * @return list<string|int>
     * @throws Exception
     */
    private function keys(mixed $container, string|PathSegment $segment, bool $create, array $path): array
    {
        if (is_string($segment) || PathSegment::KEY === $segment->kind) {
            $key = is_string($segment) ? $segment : (string)$segment->value;
            return ($create || $this->has($container, $key)) ? [$key] : [];
        }

        if (!is_array($container)) {
            if ($segment->isWildcard() && is_object($container)) {
                return array_keys(get_object_vars($container));
            }
            if ($create) {
                throw new Exception(sprintf('"%s": %s needs a list at "%s".', $this->attribute, $segment, Path::toString(array_map('strval', $path))));
            }
            return [];
        }

        if ($segment->isWildcard()) {
            return array_keys($container);
        }

        // a position in a list
        $keys = array_keys($container);
        $index = (int)$segment->value;
        $key = $keys[$index < 0 ? count($keys) + $index : $index] ?? null;
        if (null === $key || !array_is_list($container)) {
            if ($create) {
                throw new Exception(sprintf('"%s": there is no element %s.', $this->attribute, $segment));
            }
            return [];
        }
        return [$key];
    }

    private function has(mixed $container, string|int $key): bool
    {
        if (is_array($container)) {
            return array_key_exists($key, $container);
        }
        return is_object($container) && (new Reflection($container))->initialized((string)$key);
    }

    /**
     * @throws ReflectionException
     */
    private function get(mixed $container, string|int $key): mixed
    {
        return is_array($container) ? $container[$key] : (new Reflection($container))->get((string)$key);
    }

    private function set(mixed &$container, string|int $key, mixed $value): void
    {
        if (is_array($container)) {
            $container[$key] = $value;
            return;
        }
        (new Reflection($container))->set((string)$key, $value);
    }
}
