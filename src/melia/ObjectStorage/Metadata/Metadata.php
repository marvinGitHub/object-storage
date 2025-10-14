<?php

namespace melia\ObjectStorage\Metadata;

use JsonSerializable;
use melia\ObjectStorage\UUID\AwareTrait;
use melia\ObjectStorage\UUID\Exception\InvalidUUIDException;

class Metadata implements JsonSerializable
{
    const RESERVED_REFERENCE_NAME_DEFAULT = '__reference';

    use AwareTrait;

    private string $className;
    private float $timestampCreation;
    private int $version;
    private string $checksum;
    private null|float $timestampExpiresAt = null;

    private array $references = [];
    private string $reservedReferenceName = Metadata::RESERVED_REFERENCE_NAME_DEFAULT;

    /**
     * @throws InvalidUUIDException
     */
    public static function createFromArray(array $data): Metadata
    {
        $metadata = new Metadata();
        if (array_key_exists('className', $data)) {
            $metadata->setClassName($data['className']);
        }
        if (array_key_exists('timestampCreation', $data)) {
            $metadata->setTimestampCreation($data['timestampCreation']);
        }
        if (array_key_exists('version', $data)) {
            $metadata->setVersion($data['version']);
        }
        if (array_key_exists('checksum', $data)) {
            $metadata->setChecksum($data['checksum']);
        }
        if (array_key_exists('timestampExpiresAt', $data)) {
            $metadata->setTimestampExpiresAt($data['timestampExpiresAt']);
        }
        if (array_key_exists('uuid', $data)) {
            $metadata->setUuid($data['uuid']);
        }
        if (array_key_exists('reservedReferenceName', $data)) {
            $metadata->setReservedReferenceName($data['reservedReferenceName']);
        }
        return $metadata;
    }

    /**
     * Retrieves the class name of the current instance.
     *
     * @return string The name of the class.
     */
    public function getClassName(): string
    {
        return $this->className;
    }

    /**
     * Sets the class name for the instance.
     *
     * @param string $className The name of the class to be set.
     * @return void
     */
    public function setClassName(string $className): void
    {
        $this->className = $className;
    }

    /**
     * Retrieves the timestamp of creation.
     *
     * @return float The creation timestamp.
     */
    public function getTimestampCreation(): float
    {
        return $this->timestampCreation;
    }

    /**
     * Sets the creation timestamp for the instance.
     *
     * @param int|float $timestampCreation The timestamp to be set as the creation time.
     * @return void
     */
    public function setTimestampCreation(int|float $timestampCreation): void
    {
        $this->timestampCreation = (float)$timestampCreation;
    }

    /**
     * Retrieves the version of the instance.
     *
     * @return int The current version number.
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Sets the version number.
     *
     * @param int $version The version number to be set.
     * @return void
     */
    public function setVersion(int $version): void
    {
        $this->version = $version;
    }

    /**
     * Retrieves the checksum value.
     *
     * @return string The checksum value.
     */
    public function getChecksum(): string
    {
        return $this->checksum;
    }

    /**
     * Sets the checksum value.
     *
     * @param string $checksum The checksum value to be set.
     * @return void
     */
    public function setChecksum(string $checksum): void
    {
        $this->checksum = $checksum;
    }

    /**
     * Retrieves the timestamp indicating when an event or action expires.
     *
     * @return float|null The expiration timestamp as a Unix timestamp or null if not set.
     */
    public function getTimestampExpiresAt(): ?float
    {
        return $this->timestampExpiresAt;
    }

    /**
     * Sets the timestamp indicating when an event or action expires.
     *
     * @param int|null|float $timestampExpiresAt The expiration timestamp as a Unix timestamp or null to unset it.
     * @return void
     */
    public function setTimestampExpiresAt(null|int|float $timestampExpiresAt): void
    {
        if (null !== $timestampExpiresAt) {
            $timestampExpiresAt = (float)$timestampExpiresAt;
        }
        $this->timestampExpiresAt = $timestampExpiresAt;
    }

    /**
     * Calculates the remaining lifetime in seconds.
     *
     * @return float|null The remaining lifetime in seconds if the expiration timestamp is set, or null if not available.
     */
    public function getLifetime(): ?float
    {
        return $this->timestampExpiresAt !== null ? $this->timestampExpiresAt - microtime(true): null;
    }

    /**
     * Retrieves the reserved reference name.
     *
     * @return string The reserved reference name associated with this instance.
     */
    public function getReservedReferenceName(): string
    {
        return $this->reservedReferenceName;
    }

    /**
     * Sets the reserved reference name.
     *
     * @param string $reservedReferenceName The reserved reference name to set.
     * @return void
     */
    public function setReservedReferenceName(string $reservedReferenceName): void
    {
        $this->reservedReferenceName = $reservedReferenceName;
    }

    /**
     * Prepares data for JSON serialization.
     *
     * @return array An associative array containing the object's properties to be serialized.
     */
    public function jsonSerialize(): array
    {
        return [
            'className' => $this->className,
            'timestampCreation' => $this->timestampCreation,
            'version' => $this->version,
            'checksum' => $this->checksum,
            'timestampExpiresAt' => $this->timestampExpiresAt,
            'uuid' => $this->uuid,
            'reservedReferenceName' => $this->reservedReferenceName,
        ];
    }
}