<?php

namespace melia\ObjectStorage\UUID;

class Validator
{
    public const UUID_LENGTH = 36;
    public const UUID_HYPHENS_COUNT = 4;

    private const REGEX_UUID_VALIDATION = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public static function validate(string $uuid): bool
    {
        $validity = Cache::getUuidValidity($uuid);
        if (null !== $validity) {
            return $validity;
        }

        $valid = 1 === preg_match(self::REGEX_UUID_VALIDATION, $uuid);
        Cache::setUuidValidity($uuid, $valid);

        return $valid;
    }
}