<?php

namespace melia\ObjectStorage\File;

use melia\ObjectStorage\Exception\SerializationFailureException;
use Throwable;

/**
 * Encodes values as JSON and writes them atomically, the way the storage writes its files: readers see the
 * previous or the new contents, never a part (WriterInterface::atomicWrite()).
 */
final class JsonFileWriter
{
    /** The maximum nesting depth of encoded values, unless given. */
    public const int MAX_DEPTH_DEFAULT = 512;

    /**
     * @param WriterInterface $writer Writes the files.
     * @param int $maxDepth The maximum nesting depth of encoded values.
     * @param int $flags Flags of json_encode(), e.g. JSON_PRETTY_PRINT for files people read.
     */
    public function __construct(
        private readonly WriterInterface $writer = new Writer(),
        private readonly int             $maxDepth = self::MAX_DEPTH_DEFAULT,
        private readonly int             $flags = 0,
    )
    {
    }

    /**
     * Encodes a value as JSON.
     *
     * @throws SerializationFailureException If it can't be encoded, e.g. nested too deep.
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
     * Writes a value as JSON into a file, atomically; its directory is created if needed.
     *
     * @throws SerializationFailureException If the value can't be encoded.
     * @throws Throwable If the file can't be written.
     */
    public function write(string $filename, mixed $value): void
    {
        $this->writer->atomicWrite($filename, $this->encode($value));
    }
}
