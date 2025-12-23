<?php

namespace melia\ObjectStorage;

use JsonSerializable;
use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\DanglingReferenceException;
use melia\ObjectStorage\Exception\ObjectLoadingFailureException;
use melia\ObjectStorage\Reflection\Reflection;
use melia\ObjectStorage\Storage\StorageAwareTrait;
use melia\ObjectStorage\Storage\StorageInterface;
use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\AwareTrait;
use melia\ObjectStorage\UUID\Exception\InvalidUUIDException;
use melia\ObjectStorage\UUID\Helper;
use ReflectionException;
use RuntimeException;
use Throwable;

/**
 * Class responsible for deferring the loading of an object from persistent storage
 * until it is accessed. This enables delayed initialization and improves performance
 * by avoiding unnecessary data fetching.
 */
class LazyLoadReference implements AwareInterface, JsonSerializable
{
    use AwareTrait;
    use StorageAwareTrait;

    private ?object $loadedObject = null;
    private ?object $root = null;
    private array $path;

    /**
     * @throws InvalidUUIDException
     */
    public function __construct(
        StorageInterface $storage,
                         $uuid,
        object           $root,
        array            $path

    )
    {
        $this->setStorage($storage);
        $this->setUUID($uuid);
        $this->root = $root;
        $this->path = $path;
    }

    /**
     * Dynamically retrieves the value of a property from the loaded object.
     *
     * @param string $name The name of the property to access.
     * @return mixed The value of the specified property, or null if not available.
     * @throws DanglingReferenceException
     * @throws ReflectionException
     */
    public function __get(string $name): mixed
    {
        $this->loadIfNeeded();
        $reflection = new Reflection($this->loadedObject);
        if ($reflection->initialized($name)) {
            return $reflection->get($name);
        }
        return null;
    }

    /**
     * @throws ReflectionException
     * @throws DanglingReferenceException
     */
    public function __set(string $name, mixed $value): void
    {
        $this->loadIfNeeded();
        $reflection = new Reflection($this->loadedObject);
        $reflection->set($name, $value);
    }

    /**
     * Ensures the object is loaded from storage if it has not already been loaded.
     *
     * @return void
     * @throws DanglingReferenceException
     * @throws ReflectionException
     */
    private function loadIfNeeded(): void
    {
        if ($this->loadedObject === null) {
            $storage = $this->getStorage();

            static $listener;
            if (null === $listener) {
                $listener = function (Context $context) {
                    if ($this->uuid === $context->getUuid()) {
                        throw new DanglingReferenceException(sprintf('Reference to object with UUID "%s" is expired', $this->uuid ?? ''));
                    }
                };
            }

            if ($storage instanceof ObjectStorage) {
                $storage->getEventDispatcher()?->addListener(Events::OBJECT_EXPIRED, $listener);
            }

            try {
                $this->loadedObject = $storage->load($this->uuid);
            } catch (Throwable $e) {
                $storage->getLogger()?->log($e);
            } finally {
                if ($storage instanceof ObjectStorage) {
                    $storage->getEventDispatcher()?->removeListener(Events::OBJECT_EXPIRED, $listener);
                }
                if (null === $this->loadedObject) {
                    throw new DanglingReferenceException(sprintf('Reference to object with UUID "%s" could not be loaded', $this->uuid ?? ''));
                }
            }

            $this->updateParent();
        }
    }


    /**
     * Updates the parent object by setting a specified property to the loaded object.
     *
     * @return void
     * @throws ReflectionException
     */
    private function updateParent(): void
    {
        $this->setValueAtPath($this->root, $this->path, $this->loadedObject);
    }

    /**
     * @throws ReflectionException
     */
    private function setValueAtPath(object|array &$current, array $path, mixed $value): void
    {
        if (empty($path)) {
            return;
        }

        $segment = array_shift($path);

        if (empty($path)) {
            // last segment - set the value
            $this->setFinalValue($current, $segment, $value);
        } else {
            // navigate deeper into the structure
            $nextLevel = $this->getNextLevel($current, $segment);

            if ($nextLevel !== null) {
                $this->setValueAtPath($nextLevel, $path, $value);

                // if $nextLevel was an array, we need to set it back into the parent
                if (is_array($nextLevel)) {
                    $this->setFinalValue($current, $segment, $nextLevel);
                }
            }
        }
    }

    /**
     * Sets the final value at the specified segment.
     *
     * @param object|array $current The current object or array
     * @param string $segment The property name or array key
     * @param mixed $value The value to set
     * @return void
     */
    private function setFinalValue(object|array &$current, string $segment, mixed $value): void
    {
        if (is_object($current)) {
            $reflection = new Reflection($current);
            $reflection->set($segment, $value);
        } else if (is_array($current)) {
            $current[$segment] = $value;
        }
    }

    /**
     * Gets the next level in the path traversal.
     *
     * @param object|array $current The current object or array
     * @param string $segment The property name or array key
     * @return object|array|null The next level object/array or null if not found
     * @throws ReflectionException
     */
    private function getNextLevel(object|array $current, string $segment): object|array|null
    {
        if (is_object($current)) {
            $reflection = new Reflection($current);
            if ($reflection->initialized($segment)) {
                return $reflection->get($segment);
            }
        } else if (is_array($current)) {
            return $current[$segment] ?? null;
        }

        return null;
    }

    /**
     * Determines if a property is set on the loaded object.
     *
     * @param string $name The name of the property to check.
     * @return bool True if the property is set, false otherwise.
     * @throws ReflectionException If there is an error with reflection during execution.
     * @throws DanglingReferenceException If the loaded object reference is not properly initialized.
     */
    public function __isset(string $name): bool
    {
        $this->loadIfNeeded();
        $reflection = new Reflection($this->loadedObject);
        return $reflection->initialized($name);
    }

    /**
     * Handles dynamic method calls on the object.
     *
     * @param string $name The name of the method being called.
     * @param array $arguments The arguments passed to the method being called.
     * @return mixed The result of the method call on the loaded object.
     * @throws RuntimeException If the object is not properly loaded or the method does not exist.
     * @throws DanglingReferenceException|ReflectionException
     */
    public function __call(string $name, array $arguments): mixed
    {
        $this->loadIfNeeded();
        return $this->loadedObject?->$name(...$arguments);
    }

    /**
     * Determines if the object is loaded.
     *
     * @return bool True if the object is loaded, false otherwise.
     */
    public function isLoaded(): bool
    {
        return $this->loadedObject !== null;
    }

    /**
     * Retrieves the loaded object if available.
     *
     * @return object|null The loaded object, or null if not available.
     * @throws DanglingReferenceException|ReflectionException
     */
    public function getObject(): ?object
    {
        $this->loadIfNeeded();
        return $this->loadedObject;
    }

    /**
     * Retrieves the root object.
     *
     * @return object|null The root object if available, or null if not set.
     */
    public function getRoot(): ?object
    {
        return $this->root;
    }

    /**
     * @throws ReflectionException
     * @throws DanglingReferenceException
     */
    public function jsonSerialize(): ?object
    {
        $this->loadIfNeeded();
        return $this->loadedObject;
    }

    /**
     * Serializes the object into an array representation.
     *
     * @return array An associative array containing the serialized properties of the object.
     */
    public function __serialize()
    {
        return [
            'uuid' => $this->uuid,
            'root' => Helper::getAssigned($this->root),
            'path' => $this->path,
        ];
    }

    /**
     * Reconstructs the object from the provided serialized data.
     *
     * @param array $data The serialized data used to restore the object's state.
     *                     Includes keys such as 'root', 'uuid', and 'path'.
     *
     * @return void
     */
    public function __unserialize(array $data)
    {
        try {
            if ($data['root']) {
                $this->root = $this->getStorage()->load($data['root']);
            }
        } catch (Throwable $e) {
            $this->getStorage()?->getLogger()?->log($e);
            $this->root = null;
        }

        $this->uuid = $data['uuid'] ?? null;
        $this->path = $data['path'] ?? [];
    }
}