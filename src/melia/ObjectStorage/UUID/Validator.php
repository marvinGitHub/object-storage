<?php

namespace melia\ObjectStorage\UUID;

class Validator
{
    const UUID_LENGTH = 36;
    const REGEX_UUID_VALIDATION = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public static function validate(string $uuid): bool
    {
        static $validated = [];

        if (isset($validated[$uuid])) {
            return $validated[$uuid];
        }

        $valid = 1 === preg_match(Validator::REGEX_UUID_VALIDATION, $uuid);
        $validated[$uuid] = $valid;

        return $valid;
    }
}