<?php

namespace melia\ObjectStorage\Exception;

/**
 * A file of the storage holds no valid contents, e.g. no valid JSON: a damaged file, not a passing failure.
 * Whoever reads it decides what that means; the storage enables safe mode.
 */
class CorruptedFileException extends SerializationFailureException
{
    public function __construct(public readonly string $path, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
