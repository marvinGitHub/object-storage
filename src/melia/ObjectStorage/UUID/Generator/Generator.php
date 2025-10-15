<?php

namespace melia\ObjectStorage\UUID\Generator;

use melia\ObjectStorage\UUID\Exception\GenerationFailureException;
use Throwable;

class Generator implements GeneratorInterface
{
    /**
     * Generates a Universally Unique Identifier (UUID) according to the RFC 4122 version 4 specification.
     *
     * @return string A string representation of the generated UUID.
     * @throws GenerationFailureException If the UUID generation fails.
     */
    public function generate(): string
    {
        static $generated = [];

        try {
            do {
                $data = random_bytes(16);
                $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
                $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
                $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
            } while (isset($generated[$uuid]));

            $generated[$uuid] = true;
            return $uuid;
        } catch (Throwable $e) {
            throw new GenerationFailureException('Unable to generate UUID: ' . $e->getMessage(), 0, $e);
        }
    }
}
