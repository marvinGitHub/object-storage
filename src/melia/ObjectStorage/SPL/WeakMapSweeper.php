<?php

namespace melia\ObjectStorage\SPL;

use WeakMap;

class WeakMapSweeper
{
    public static function clear(WeakMap $weakMap): void
    {
        foreach ($weakMap as $key => $value) {
            $weakMap->offsetUnset($key);
        }
    }
}