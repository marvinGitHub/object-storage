<?php

namespace melia\ObjectStorage\Metadata;

use JsonSerializable;
use melia\ObjectStorage\Exception\MissingChecksumException;
use melia\ObjectStorage\Checksum\AlgorithmAwareTrait;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\UUID\AwareTrait;
use melia\ObjectStorage\UUID\Exception\InvalidUUIDException;

class Metadata implements JsonSerializable
{
    /** version of the metadata format; 2 added the revision */
    public const VERSION = 2;

    use AwareTrait;
    use AlgorithmAwareTrait;
    use ReservedNamesTrait;

    private string $className;
    /** when the object was first stored; null if unknown (see ObjectStorage::loadMetadata(), which takes the file's) */
    private ?float $timestampCreation = null;
    private int $version;
    private string $checksum;

    /** incremented with every write of the data, 0 for objects stored before revisions existed */
    private int $revision = 0;

    private ?float $timestampExpiresAt = null;

    /** when the data was written last, or the object touched (ObjectStorage::touch()); null if unknown */
    private ?float $timestampModification = null;

    /**
     * @throws InvalidUUIDException
     */
    public static function createFromArray(array $data): self
    {
        $metadata = new self();

        if (isset($data['className'])) {
            $metadata->setClassName($data['className']);
        }

        if (isset($data['timestampCreation'])) {
            $metadata->setTimestampCreation($data['timestampCreation']);
        }

        if (isset($data['timestampModification'])) {
            $metadata->setTimestampModification($data['timestampModification']);
        }

        if (isset($data['version'])) {
            $metadata->setVersion($data['version']);
        }

        if (isset($data['revision'])) {
            $metadata->setRevision((int)$data['revision']);
        }

        if (isset($data['checksum'])) {
            $metadata->setChecksum($data['checksum']);
        }

        if (isset($data['checksumAlgorithm'])) {
            $metadata->setChecksumAlgorithm($data['checksumAlgorithm']);
        } elseif (isset($data['checksum']) && 32 === strlen($metadata->getChecksum())) {
            /* backwards compatibility */
            $metadata->setChecksumAlgorithm('md5');
        }

        $timestampExpiresAt = $data['timestampExpiresAt'] ?? null;
        $metadata->setTimestampExpiresAt($timestampExpiresAt);

        if (isset($data['uuid'])) {
            $metadata->setUuid($data['uuid']);
        }

        $metadata->reservedNamesFromArray($data);

        return $metadata;
    }

    /**
     * Retrieves the checksum value.
     *
     * @return string The checksum value.
     * @throws MissingChecksumException If the metadata has none (written by other tools or by hand): the data
     *         can't be verified.
     */
    public function getChecksum(): string
    {
        return $this->checksum ?? throw new MissingChecksumException(sprintf(
            'The metadata%s has no checksum, so the data can\'t be verified',
            null === $this->getUUID() ? '' : ' of ' . $this->getUUID()
        ));
    }

    /**
     * Whether the metadata has a checksum (metadata written by other tools or by hand may lack it).
     */
    public function hasChecksum(): bool
    {
        return isset($this->checksum);
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
     * When the object was first stored; null if the metadata doesn't tell (written by other tools, by hand). Loaded
     * metadata always has it: ObjectStorage::loadMetadata() takes the time of the files then.
     *
     * @return float|null The creation timestamp.
     */
    public function getTimestampCreation(): ?float
    {
        return $this->timestampCreation;
    }

    /**
     * Sets the creation timestamp for the instance.
     *
     * @param int|float|null $timestampCreation The timestamp to be set as the creation time, null if unknown.
     * @return void
     */
    public function setTimestampCreation(int|float|null $timestampCreation): void
    {
        $this->timestampCreation = null === $timestampCreation ? null : (float)$timestampCreation;
    }

    /**
     * When the data of the object was written last (store() with changed data, OQL INSERT and UPDATE) or the object
     * was touched (ObjectStorage::touch()); null if the metadata doesn't tell (written before it existed, by other
     * tools). Loaded metadata always has it: ObjectStorage::loadMetadata() takes the time of the data file then.
     */
    public function getTimestampModification(): ?float
    {
        return $this->timestampModification;
    }

    public function setTimestampModification(int|float|null $timestampModification): void
    {
        $this->timestampModification = null === $timestampModification ? null : (float)$timestampModification;
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
     * Retrieves the revision of the stored data: incremented with every write of the data,
     * not by changes of the metadata only (e.g. the lifetime).
     *
     * @return int The revision, 0 for objects stored before revisions existed.
     */
    public function getRevision(): int
    {
        return $this->revision;
    }

    /**
     * Sets the revision of the stored data.
     *
     * @param int $revision The revision.
     * @return void
     */
    public function setRevision(int $revision): void
    {
        $this->revision = $revision;
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
        return $this->timestampExpiresAt !== null ? $this->timestampExpiresAt - microtime(true) : null;
    }

    /**
     * Prepares data for JSON serialization.
     *
     * @return array An associative array containing the object's properties to be serialized.
     */
    public function jsonSerialize(): array
    {
        return [
            'className' => $this->className ?? '',
            'timestampCreation' => $this->timestampCreation ?? microtime(true),
            'timestampModification' => $this->timestampModification ?? $this->timestampCreation ?? microtime(true),
            'version' => $this->version ?? self::VERSION,
            'revision' => $this->revision,
            'checksum' => $this->checksum ?? '',
            'checksumAlgorithm' => $this->checksumAlgorithm ?? ObjectStorage::CHECKSUM_ALGORITHM_DEFAULT,
            'timestampExpiresAt' => $this->timestampExpiresAt ?? null,
            'uuid' => $this->uuid ?? '',
            ...$this->reservedNamesToArray(),
        ];
    }
}
