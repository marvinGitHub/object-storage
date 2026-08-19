<?php

namespace melia\ObjectStorage\UUID;

class Cache
{
    /**
     * Upper bound for the number of cached UUID validation results. Without a cap, this
     * process-wide static cache grows indefinitely, which is a real memory leak for
     * long-running processes (workers, daemons, queue consumers) that see many distinct
     * UUIDs over their lifetime. Once the limit is reached, the oldest entry is evicted
     * (simple FIFO) to keep memory usage bounded.
     */
    private const MAX_ENTRIES = 10000;

    private static array $validated = [];

    public static function hasUuidBeenValidated(string $uuid): bool
    {
        return isset(self::$validated[$uuid]);
    }

    public static function markUuidAsValidated(string $uuid): void
    {
        static::setUuidValidity($uuid, true);
    }

    public static function setUuidValidity(string $uuid, bool $validity): void
    {
        if ((self::$validated[$uuid] ?? null) === $validity) {
            return;
        }

        if (count(self::$validated) >= self::MAX_ENTRIES) {
            unset(self::$validated[array_key_first(self::$validated)]);
        }

        self::$validated[$uuid] = $validity;
    }

    public static function markUuidAsInvalid(string $uuid): void
    {
        static::setUuidValidity($uuid, false);
    }

    public static function getUuidValidity(string $uuid): ?bool
    {
        return self::$validated[$uuid] ?? null;
    }
}