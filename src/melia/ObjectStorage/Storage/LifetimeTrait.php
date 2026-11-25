<?php

namespace melia\ObjectStorage\Storage;

use DateTimeImmutable;
use DateTimeInterface;
use Exception as PHPDefaultException;
use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\Context\LifetimeContext;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\MetadataNotFoundException;
use melia\ObjectStorage\Exception\MetadataSavingFailureException;

/**
 * The lifetime of objects: when they expire, touching them.
 *
 * Part of ObjectStorage, kept apart for readability.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait LifetimeTrait
{
    public function getLifetime(string $uuid): ?float
    {
        return $this->loadMetadata($uuid)?->getLifetime();
    }

    /**
     * Retrieves the expiration date and time for the provided UUID.
     *
     * @param string $uuid The unique identifier for which to retrieve the expiration timestamp.
     * @return DateTimeInterface|null The expiration date and time as a DateTimeInterface object, or null if no expiration is set.
     * @throws PHPDefaultException
     */
    public function getExpiration(string $uuid): ?DateTimeInterface
    {
        $expiresAt = $this->loadMetadata($uuid)?->getTimestampExpiresAt();
        if (null === $expiresAt) {
            return null;
        }
        return new DateTimeImmutable('@' . $expiresAt);
    }

    /**
     * Sets the lifetime (time-to-live) for the given UUID, updating its expiration timestamp
     * in the metadata and dispatching an event to notify listeners of the change.
     *
     * @param string $uuid The unique identifier whose lifetime is to be set.
     * @param int|float|null $ttl The time-to-live (in seconds) to set for the UUID.
     *
     * @return void
     * @throws MetadataNotFoundException
     * @throws MetadataSavingFailureException
     */
    public function setLifetime(string $uuid, null|int|float $ttl): void
    {
        $lockAdapter = $this->getLockAdapter();
        $lockAdapter?->acquireExclusiveLock($uuid);

        try {
            // read from disk under the lock: cached metadata may be outdated (checksum, revision) if another
            // process stored the object in the meantime, and writing it back would revert those values
            $metadata = $this->reloadMetadata($uuid);
            if (null === $metadata) {
                throw new MetadataNotFoundException('Unable to load metadata for uuid: ' . $uuid);
            }

            if (null === $ttl) {
                $timestampExpiresAt = null;
            } else {
                $timestampExpiresAt = microtime(true) + $ttl;
                $timestampExpiresAt = (float)$timestampExpiresAt;
            }

            $metadata->setTimestampExpiresAt($timestampExpiresAt);
            $this->writeMetadata($metadata);
            $this->getEventDispatcher()?->dispatch(Events::LIFETIME_CHANGED, static fn() => new LifetimeContext($uuid, $timestampExpiresAt));
        } finally {
            if (!$this->objectLocks()->isRetained($uuid)) {
                $lockAdapter?->releaseLock($uuid);
            }
        }
    }

    /**
     * Sets the expiration date and time for the specified UUID. If no expiration is provided,
     * the lifetime will be unset or considered indefinite.
     *
     * @param string $uuid The unique identifier for which the expiration is being set.
     * @param DateTimeInterface|null $expiresAt The date and time at which the UUID should expire, or null for no expiration.
     * @return void
     * @throws MetadataNotFoundException
     * @throws MetadataSavingFailureException
     */
    public function setExpiration(string $uuid, ?DateTimeInterface $expiresAt): void
    {
        $this->setLifetime($uuid, $expiresAt ? $expiresAt->getTimestamp() - microtime(true) : null);
    }

    /**
     * Touches an object, like touch on a file: sets when it was modified (now, or the point in time given), without
     * changing its data, revision or lifetime. Only the metadata is written; METADATA_SAVED and TOUCHED are
     * dispatched. Expired objects can be touched too (it doesn't extend the lifetime: setLifetime() does).
     *
     * @throws MetadataNotFoundException If there is no such object.
     * @throws MetadataSavingFailureException
     */
    public function touch(string $uuid, ?DateTimeInterface $at = null): void
    {
        $this->objectLocks()->exclusively($uuid, function () use ($uuid, $at): void {
            // read from disk under the lock, as setLifetime() does
            $metadata = $this->reloadMetadata($uuid);
            if (null === $metadata) {
                throw new MetadataNotFoundException('Unable to load metadata for uuid: ' . $uuid);
            }
            $timestamp = null === $at ? microtime(true) : (float)$at->format('U.u');
            $metadata->setTimestampModification($timestamp);
            $this->writeMetadata($metadata);
            $this->getEventDispatcher()?->dispatch(Events::TOUCHED, static fn() => new Context($uuid));
        });
    }

    /**
     * When the object was first stored; null if there is no such object (or neither its metadata nor its files tell).
     */
    public function getCreatedAt(string $uuid): ?DateTimeImmutable
    {
        $timestamp = $this->loadMetadata($uuid)?->getTimestampCreation();
        return null === $timestamp ? null : self::dateOfTimestamp($timestamp);
    }

    /**
     * When the data of the object was written last, or it was touched; null if there is no such object (or neither
     * its metadata nor its files tell).
     */
    public function getModifiedAt(string $uuid): ?DateTimeImmutable
    {
        $timestamp = $this->loadMetadata($uuid)?->getTimestampModification();
        return null === $timestamp ? null : self::dateOfTimestamp($timestamp);
    }

    /** a timestamp with microseconds as a date in UTC */
    private static function dateOfTimestamp(float $timestamp): DateTimeImmutable
    {
        // "U" ignores the time zone given: +00:00, set to UTC by name
        $date = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $timestamp)) ?: new DateTimeImmutable('@' . (int)$timestamp);
        return $date->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Checks if the object associated with the given UUID is expired.
     *
     * @param string $uuid The unique identifier of the object.
     * @return bool Returns true if the object is expired, false otherwise.
     */
    public function expired(string $uuid): bool
    {
        $lifetime = $this->getLifetime($uuid);
        $expired = (null !== $lifetime && $lifetime <= 0);

        if ($expired) {
            $this->getEventDispatcher()?->dispatch(Events::OBJECT_EXPIRED, static fn() => new Context($uuid));
        }

        return $expired;
    }
}
