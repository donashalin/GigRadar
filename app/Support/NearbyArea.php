<?php

namespace App\Support;

use App\Models\User;

/** Decides whether a concert falls inside a user's "near me" area. */
final readonly class NearbyArea
{
    public function __construct(
        public string $mode,              // 'country' | 'radius'
        public ?float $homeLat,
        public ?float $homeLng,
        public ?string $homeCountryCode,
        public int $radiusMiles,
    ) {}

    public static function forUser(User $user): self
    {
        return new self(
            mode: $user->nearby_mode,
            homeLat: $user->home_lat,
            homeLng: $user->home_lng,
            homeCountryCode: $user->home_country_code,
            radiusMiles: $user->radius_miles,
        );
    }

    /** True when the user has enough location data for this mode to filter anything. */
    public function isConfigured(): bool
    {
        return $this->mode === 'country'
            ? $this->homeCountryCode !== null
            : $this->homeLat !== null && $this->homeLng !== null;
    }

    /** Returns null when the concert can't be judged (missing data) — callers decide the fallback. */
    public function contains(?string $country, ?float $lat, ?float $lng): ?bool
    {
        if ($this->mode === 'country') {
            return ($this->homeCountryCode === null || $country === null || $country === '')
                ? null
                : strcasecmp($country, $this->homeCountryCode) === 0;
        }

        if ($this->homeLat === null || $this->homeLng === null || $lat === null || $lng === null) {
            return null;
        }

        return Geo::distanceMiles($this->homeLat, $this->homeLng, $lat, $lng) <= $this->radiusMiles;
    }

    public function distanceMiles(?float $lat, ?float $lng): ?float
    {
        return ($this->homeLat === null || $this->homeLng === null || $lat === null || $lng === null)
            ? null
            : Geo::distanceMiles($this->homeLat, $this->homeLng, $lat, $lng);
    }
}
