<?php

namespace App\Support;

/** Decides whether a discovery attraction is a tribute act or a non-artist "event style". */
class TributeFilter
{
    private const ARTIST_TYPES = ['individual', 'group'];

    private const NAME_PATTERN = '/\b(?:tribute|the music of|a celebration of|celebrating the music|salute to|2\.0)\b/iu';

    /** Returns null to keep the attraction, otherwise the reason it is excluded. */
    public static function reason(?string $attractionType, ?string $attractionSubType, string $attractionName, string $eventName): ?string
    {
        if ($attractionSubType !== null && mb_stripos($attractionSubType, 'tribute') !== false) {
            return 'tribute_subtype';
        }

        $type = $attractionType === null ? '' : mb_strtolower(trim($attractionType));
        if ($type !== '' && ! in_array($type, self::ARTIST_TYPES, true)) {
            return 'not_an_artist';
        }

        if (preg_match(self::NAME_PATTERN, $attractionName) === 1 || preg_match(self::NAME_PATTERN, $eventName) === 1) {
            return 'name_pattern';
        }

        return null;
    }
}
