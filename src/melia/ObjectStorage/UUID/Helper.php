<?php

namespace melia\ObjectStorage\UUID;

use InvalidArgumentException;
use melia\ObjectStorage\Reflection\Reflection;
use melia\ObjectStorage\UUID\Exception\InvalidUUIDException;
use ReflectionException;

class Helper
{
    /**
     * Retrieves the assigned UUID from the provided object if it meets specific conditions.
     *
     * @param object|null $object $object The object from which the UUID is to be retrieved.
     *                       Must implement AwareInterface and have a `getUUID` method.
     *
     * @return string|null Returns the UUID as a string if the object fulfills the conditions.
     *                     Otherwise, returns null.
     */
    public static function getAssigned(?object $object): ?string
    {
        if (null === $object) {
            return null;
        }

        if ($object instanceof AwareInterface) {
            return $object->getUUID();
        }

        if (method_exists($object, 'getUUID')) {
            return $object->getUUID();
        }

        $reflection = new Reflection($object);
        try {
            $uuid = $reflection->get('uuid');
            if (is_string($uuid) && Validator::validate($uuid)) {
                return $uuid;
            }
        } catch (ReflectionException $e) {
            return null;
        }
        return null;
    }

    /**
     * Assigns a valid UUID to the provided object if it implements the AwareInterface.
     *
     * @param object $object The object to which the UUID will be assigned. The object must implement AwareInterface.
     * @param string $uuid The UUID to assign to the object. The value must be validated as a valid UUID.
     *
     * @return void
     *
     * @throws InvalidUUIDException If the provided UUID is not valid.
     */
    public static function assign(object $object, string $uuid): void
    {
        /* important: only assign if the object implements AwareInterface to not break existing code or introduce unexpected behavior */
        $instanceOfAwareInterface = $object instanceof AwareInterface;

        if (!$instanceOfAwareInterface) {
            return;
        }

        if (Validator::validate($uuid) === false) {
            throw new InvalidUUIDException(sprintf('Invalid UUID: %s', $uuid));
        }
        $object->setUUID($uuid);
    }

    /**
     * Creates a UUID that is always the same for the same input, in the format of version 4, so it passes the
     * Validator: e.g. to store an object under a UUID derived from its name and find it by the name again.
     *
     * The UUID is made from the first 32 hex digits of the hash of the input, with the version and variant set.
     * It isn't random like a real version 4 UUID: use an input that is unique in its context, e.g. prefixed by
     * what it identifies ("search-index:products").
     *
     * @param string $input The input the UUID is derived from.
     * @param string $algorithm A hash algorithm of hash_algos() with at least 128 bits, e.g. "md5", "sha1", "sha256".
     * @return string The UUID, lowercase.
     * @throws InvalidArgumentException If the algorithm is unknown or its hashes are shorter than 128 bits.
     */
    public static function createFixedUuidV4(string $input, string $algorithm = 'md5'): string
    {
        if (!in_array($algorithm, hash_algos(), true)) {
            throw new InvalidArgumentException(sprintf('Unknown hash algorithm "%s".', $algorithm));
        }

        $hash = hash($algorithm, $input);
        if (strlen($hash) < 32) {
            throw new InvalidArgumentException(sprintf('The hash algorithm "%s" yields less than 128 bits.', $algorithm));
        }

        $hash = substr($hash, 0, 32);
        $hash[12] = '4';                                 // version 4
        $hash[16] = '89ab'[hexdec($hash[16]) & 3];       // variant RFC 4122

        return sprintf('%s-%s-%s-%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4), substr($hash, 16, 4), substr($hash, 20, 12));
    }

    /**
     * Removes all hyphens from the provided UUID string.
     *
     * @param string $uuid The input string containing hyphens to be removed.
     * @return string The input string with all hyphens removed.
     */
    public static function removeHyphens(string $uuid): string
    {
        return str_replace('-', '', $uuid);
    }
}