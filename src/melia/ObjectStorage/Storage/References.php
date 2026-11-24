<?php

namespace melia\ObjectStorage\Storage;

use Generator;
use melia\ObjectStorage\UUID\Validator;

/**
 * The references to other objects in the stored data of an object: arrays holding a valid UUID under the reserved
 * reference name the data was written with.
 */
final class References
{
    /**
     * @param array $data The decoded data of the object.
     * @param string $reserved The reserved reference name the data was written with.
     * @param array $path The path of $data within the data of the object.
     * @return Generator<array{0: string, 1: array}> Pairs of the referenced UUID and the path of the reference.
     */
    public static function in(array $data, string $reserved, array $path = []): Generator
    {
        foreach ($data as $key => $value) {
            if (false === is_array($value)) {
                continue;
            }

            $childPath = [...$path, $key];

            if (isset($value[$reserved]) && is_string($value[$reserved]) && Validator::validate($value[$reserved])) {
                yield [$value[$reserved], $childPath];
            } else {
                yield from self::in($value, $reserved, $childPath);
            }
        }
    }
}
