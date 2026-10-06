<?php

namespace App\Support;

class CountryName
{
    /** Display name for an ISO 3166-1 alpha-2 code, falling back to the code itself. */
    public static function for(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        $name = class_exists(\Locale::class) ? \Locale::getDisplayRegion('-'.$code, 'en') : $code;
        if ($name === '' || $name === 'Unknown Region' || strcasecmp($name, $code) === 0) {
            return $code;
        }

        return $name;
    }
}
