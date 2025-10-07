<?php

namespace melia\ObjectStorage\Cache;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * A simple in-memory implementation of the CacheInterface.
 *
 * This class provides methods to manage key-value pairs stored in memory,
 * allowing operations such as retrieval, storage, deletion, and checking
 * the existence of keys.
 */
class InMemoryCache implements CacheInterface
{

    /**
     * Constructor method.
     *
     * @param array $data An optional array of data to initialize the instance.
     * @return void
     */
    public function __construct(protected array $data = [])
    {
    }

    /**
     * Retrieves the value associated with the provided key from the data array.
     *
     * @param string $key The key to look up in the data array.
     * @param mixed $default The default value to return if the key is not found.
     * @return mixed The value associated with the key, or the default value if the key does not exist.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Sets a value associated with the provided key in the data array, with an optional time-to-live.
     *
     * @param string $key The key to associate with the value.
     * @param mixed $value The value to store in the data array.
     * @param DateInterval|int|null $ttl The time-to-live for the key-value pair, either as an interval, an integer in seconds, or null for no TTL.
     * @return bool True on successful storage of the key-value pair.
     */
    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $this->data[$key] = $value;
        return true;
    }

    /**
     * Deletes the value associated with the provided key from the data array.
     *
     * @param string $key The key of the element to be deleted from the data array.
     * @return bool True if the deletion is performed.
     */
    public function delete(string $key): bool
    {
        unset($this->data[$key]);
        return true;
    }

    /**
     * Clears all data from the data array and resets it to an empty state.
     *
     * @return bool True if the data array was successfully cleared.
     */
    public function clear(): bool
    {
        $this->data = [];
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        // TODO: Implement getMultiple() method.
    }

    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        // TODO: Implement setMultiple() method.
    }

    public function deleteMultiple(iterable $keys): bool
    {
        // TODO: Implement deleteMultiple() method.
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);

    }
}