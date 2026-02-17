<?php

namespace melia\ObjectStorage\UUID;

class Cache
{
    static array $validated = [];

    public static function markUuidAsValidated(string $uuid): void
    {
        static::setUuidValidity($uuid, true);
    }

    public static function markUuidAsInvalid(string $uuid): void
    {
        static::setUuidValidity($uuid, false);
    }

    public static function setUuidValidity(string $uuid, bool $validity): void
    {
        self::$validated[$uuid] = $validity;
    }

    public static function getUuidValidity(string $uuid): ?bool
    {
        return self::$validated[$uuid] ?? null;
    }
}