<?php

namespace melia\ObjectStorage\File;

use melia\ObjectStorage\Exception\SerializationFailureException;
use Throwable;

/**
 * Encodes and decodes JSON as the storage writes it: errors throw (JSON_THROW_ON_ERROR), the nesting depth is
 * limited, and decoded JSON is always an object or array.
 */
final class Json
{
    /** The maximum nesting depth, unless given. */
    public const int MAX_DEPTH_DEFAULT = 512;

    /**
     * @param int $maxDepth The maximum nesting depth of encoded and decoded values.
     * @param int $flags Flags of json_encode(), e.g. JSON_PRETTY_PRINT for files people read.
     */
    public function __construct(
        private readonly int $maxDepth = self::MAX_DEPTH_DEFAULT,
        private readonly int $flags = 0,
    )
    {
    }

    /**
     * @throws SerializationFailureException If the value can't be encoded, e.g. a text that isn't valid UTF-8, NAN,
     *         or nested too deep.
     */
    public function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | $this->flags, $this->maxDepth);
        } catch (Throwable $e) {
            throw new SerializationFailureException(message: 'Unable to encode JSON: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * @throws SerializationFailureException If it is no valid JSON object or array.
     */
    public function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, $this->maxDepth, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new SerializationFailureException(message: 'Unable to decode JSON: ' . $e->getMessage(), previous: $e);
        }

        if (!is_array($decoded)) {
            throw new SerializationFailureException('Unable to decode JSON: no object or array');
        }

        return $decoded;
    }
}
