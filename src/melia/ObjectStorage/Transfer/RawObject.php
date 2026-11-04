<?php

namespace melia\ObjectStorage\Transfer;

use melia\ObjectStorage\Metadata\Metadata;

/**
 * An object as stored: its data and metadata, unchanged, to write it into another storage
 * (ObjectStorage::readRaw() and writeRaw()).
 */
final readonly class RawObject
{
    /**
     * @param string $uuid The UUID of the object.
     * @param string $data The stored data (JSON), as written.
     * @param Metadata $metadata The metadata: class, checksum, revision, expiration.
     * @param string[] $references The UUIDs of the objects it references.
     */
    public function __construct(
        public string   $uuid,
        public string   $data,
        public Metadata $metadata,
        public array    $references = [],
    )
    {
    }

    /**
     * Whether the object has expired, so it can't be loaded anymore.
     */
    public function isExpired(): bool
    {
        $lifetime = $this->metadata->getLifetime();
        return null !== $lifetime && $lifetime <= 0;
    }
}
