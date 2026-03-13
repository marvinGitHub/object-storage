<?php

namespace melia\ObjectStorage\UUID;

class Cache
{
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