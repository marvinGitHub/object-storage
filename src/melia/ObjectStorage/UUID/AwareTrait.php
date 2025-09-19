<?php

namespace melia\ObjectStorage\UUID;

use melia\ObjectStorage\UUID\Exception\InvalidUUIDException;

/**
 * Provides functionality for handling a UUID property in a consistent manner.
 * Includes validation for proper UUID format and methods for retrieving
 * and setting the UUID.
 */
trait AwareTrait
{
    private ?string $uuid = null;

    public function getUUID(): ?string
    {
        return $this->uuid;
    }

    /**
     * @throws InvalidUUIDException
     */
    public function setUUID(?string $uuid): void
    {
        if (null !== $uuid && false === Validator::validate($uuid)) {
            throw new InvalidUUIDException('Invalid UUID format');
        }

        $this->uuid = $uuid;
    }
}