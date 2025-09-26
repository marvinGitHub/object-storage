<?php

namespace melia\ObjectStorage;

use JsonSerializable;
use melia\ObjectStorage\UUID\AwareTrait;
use melia\ObjectStorage\UUID\Exception\InvalidUUIDException;

class Metadata implements JsonSerializable
{
    use AwareTrait;

    private string $className;
    private int $timestampCreation;
    private int $version;
    private string $checksum;
    private ?int $timestampExpiresAt = null;

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
     * @return int The creation timestamp.
     */
    public function getTimestampCreation(): int
    {
        return $this->timestampCreation;
    }

    /**
     * Sets the creation timestamp for the instance.
     *
     * @param int $timestampCreation The timestamp to be set as the creation time.
     * @return void
     */
    public function setTimestampCreation(int $timestampCreation): void
    {
        $this->timestampCreation = $timestampCreation;
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
     * @return int|null The expiration timestamp as a Unix timestamp or null if not set.
     */
    public function getTimestampExpiresAt(): ?int
    {
        return $this->timestampExpiresAt;
    }

    /**
     * Sets the timestamp indicating when an event or action expires.
     *
     * @param int|null $timestampExpiresAt The expiration timestamp as a Unix timestamp or null to unset it.
     * @return void
     */
    public function setTimestampExpiresAt(?int $timestampExpiresAt): void
    {
        $this->timestampExpiresAt = $timestampExpiresAt;
    }

    public function validate(): void
    {
        /* TODO */
    }

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
        return $metadata;
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
        ];
    }
}