<?php

namespace App\Support;

final class Geo
{
    private const EARTH_RADIUS_MILES = 3958.8;

    /** Great-circle (haversine) distance between two points, in miles. */
    public static function distanceMiles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_MILES * 2 * asin(min(1.0, sqrt($a)));
    }
}
