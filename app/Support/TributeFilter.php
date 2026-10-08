<?php

namespace App\Support;

/** Decides whether a discovery attraction is a tribute act or a non-artist "event style". */
class TributeFilter
{
    private const ARTIST_TYPES = ['individual', 'group'];

    private const PHRASES = 'tributes?|the\s+music\s+of|a\s+celebration\s+of|celebrating\s+the\s+music|salute\s+to';

    private const PLACEHOLDER = 'undefined';

    /** Returns null to keep the attraction, otherwise the reason it is excluded. */
    public static function reason(?string $attractionType, ?string $attractionSubType, string $attractionName, string $eventName): ?string
    {
        if ($attractionSubType !== null && mb_stripos($attractionSubType, 'tribute') !== false) {
            return 'tribute_subtype';
        }

        $type = self::clean($attractionType);
        if ($type !== null && ! in_array($type, self::ARTIST_TYPES, true)) {
            return 'not_an_artist';
        }

        // Attraction names get the full pattern; event names are only trusted for phrases when the type is unknown.
        if (preg_match('/\b(?:'.self::PHRASES.')\b|(?<![\d.])2\.0(?![\d.])/iu', $attractionName) === 1) {
            return 'name_pattern';
        }
        if ($type === null && preg_match('/\b(?:'.self::PHRASES.')\b/iu', $eventName) === 1) {
            return 'name_pattern';
        }

        return null;
    }

    /** Lowercased and trimmed; null for missing, empty or Ticketmaster's "Undefined" placeholder. */
    private static function clean(?string $value): ?string
    {
        $value = $value === null ? '' : mb_strtolower(trim($value));

        return $value === '' || $value === self::PLACEHOLDER ? null : $value;
    }
}
