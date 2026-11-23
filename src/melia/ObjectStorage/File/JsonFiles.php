<?php

namespace melia\ObjectStorage\File;

use melia\ObjectStorage\Exception\CorruptedFileException;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\Exception\SerializationFailureException;
use Random\RandomException;

/**
 * JSON files: read() reads and decodes, write() encodes and writes, on top of the files (FilesInterface), so writes
 * stay atomic and flushed to disk.
 */
final class JsonFiles
{
    private readonly Json $json;

    /**
     * @param FilesInterface $files Reads and writes the files.
     * @param int $maxDepth The maximum nesting depth of the JSON.
     * @param int $flags Flags of json_encode(), e.g. JSON_PRETTY_PRINT for files people read.
     */
    public function __construct(
        private readonly FilesInterface $files = new Files(),
        int                             $maxDepth = Json::MAX_DEPTH_DEFAULT,
        int                             $flags = 0,
    )
    {
        $this->json = new Json($maxDepth, $flags);
    }

    /**
     * The decoded JSON of a file.
     *
     * @return array|null Null if there is no such file.
     * @throws IOException If the file exists but can't be read.
     * @throws CorruptedFileException If it holds no valid JSON object or array.
     */
    public function read(string $path): ?array
    {
        $json = $this->files->read($path);
        if (null === $json) {
            return null;
        }

        try {
            return $this->json->decode($json);
        } catch (SerializationFailureException $e) {
            throw new CorruptedFileException($path, sprintf('The file %s holds no valid JSON: %s', $path, $e->getMessage()), $e);
        }
    }

    /**
     * Writes a value as JSON into a file, atomically; a missing directory is created.
     *
     * @throws SerializationFailureException If the value can't be encoded; nothing is written.
     * @throws IOException If the file can't be written; its previous contents are kept.
     * @throws RandomException If no random bytes can be generated for the name of the staging file.
     */
    public function write(string $path, mixed $value): void
    {
        $this->files->write($path, $this->json->encode($value));
    }
}
