# GigRadar Plan 2b — "Anywhere in my country" Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a distance option "Anywhere in <country>" (country taken from the user's home location), make it the default for all users, and use it for My Artists' "Upcoming near you".

**Architecture:** Two new user columns: `home_country_code` (ISO alpha-2, from Nominatim's `address.country_code`, upper-cased to match Ticketmaster's `countryCode`) and `nearby_mode` (`country` | `radius`, default `country`). The Geocoder returns a country code with every place. A small pure `NearbyArea` class decides whether a concert is "near" a user, so My Artists now (and the Plan 3 alert selector later) share one rule.

**Tech Stack:** Laravel 12, Pest, Inertia v2 + Vue 3 + TypeScript.

**Spec:** `docs/superpowers/specs/2026-10-05-gigradar-design.md` §4 (Settings distance, My Artists), §5 (users), §7 (nearby rule).

Work directly on `main`. Never commit `.env`.

---

### Task 1: Schema and model

**Files:** Create `database/migrations/2026_10_06_000003_add_nearby_mode_to_users_table.php`; modify `app/Models/User.php`, `database/factories/UserFactory.php` (only if needed), `resources/js/types/index.ts`.

- [ ] Migration `up()`:
```php
Schema::table('users', function (Blueprint $table) {
    $table->char('home_country_code', 2)->nullable()->after('home_lng');
    $table->enum('nearby_mode', ['country', 'radius'])->default('country')->after('radius_miles');
});
```
`down()` drops both columns. (Existing users get `country` from the default — the user asked for their account to switch too.)
- [ ] `User`: add casts? (`nearby_mode` stays a string; no cast needed.) Add both fields to the TS `User` interface: `home_country_code: string | null; nearby_mode: 'country' | 'radius';`.
- [ ] Test (`tests/Feature/Models/RelationshipsTest.php` "gives users sensible alert defaults"): extend to assert `nearby_mode` is `'country'` and `home_country_code` is null after `fresh()`.
- [ ] `php artisan migrate`; commit `feat: nearby_mode and home_country_code on users`.

### Task 2: Geocoder returns the country code

**Files:** `app/Services/Geocoding/Place.php`, `Geocoder.php`, fixtures `tests/Fixtures/nominatim/search.json` + `reverse.json`, `tests/Feature/Geocoding/GeocoderTest.php`, `tests/Feature/Settings/AlertSettingsTest.php`.

- [ ] Tests first: search results have `countryCode` `'GB'` and `'US'`; reverse has `'GB'`; a result without `address.country_code` gives `countryCode` null; requests send `addressdetails=1`; `Place::toArray()` includes `countryCode`; the `/settings/alerts/places` and `/reverse` JSON include `countryCode`.
- [ ] Fixtures: add `"address": { "country_code": "gb" }` to the Leicester entries and `"address": { "country_code": "us" }` to the Massachusetts one.
- [ ] `Place` gains `public ?string $countryCode = null` (last constructor arg) and `toArray()` returns `['name', 'lat', 'lng', 'countryCode']`.
- [ ] `Geocoder`: add `'addressdetails' => 1` to both search and reverse query params; in `toPlace()` read `$result['address']['country_code'] ?? null`, accept only a 2-letter alphabetic string, `strtoupper()` it.
- [ ] Note: cache keys stay the same — bump them to `geo:v2:search:` / `geo:v2:reverse:` so old cached entries without country codes aren't served.
- [ ] Commit `feat: geocoder returns country codes`.

### Task 3: The shared "near" rule

**Files:** Create `app/Support/NearbyArea.php`; test `tests/Unit/NearbyAreaTest.php`.

```php
<?php

namespace App\Support;

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
```
Also add `public static function forUser(\App\Models\User $user): self` building it from the user's columns.

- [ ] Unit tests (no Laravel boot; construct directly): country mode GB vs `'GB'` → true, `'gb'` → true, `'IE'` → false, null/'' country → null, no home country → null and `isConfigured()` false; radius mode Leicester→Leicester within 25 → true, Leicester→Manchester within 50 → false, within 100 → true, missing coords → null; `distanceMiles` null when coords missing.
- [ ] Commit `feat: shared NearbyArea rule`.

### Task 4: My Artists uses the area

**Files:** `app/Http/Controllers/MyArtistsController.php`, `tests/Feature/MyArtistsTest.php`, `resources/js/pages/MyArtists.vue`.

- [ ] Tests first (update `leicesterHome()` to include `'home_country_code' => 'GB'` and `'nearby_mode' => 'radius'` so existing radius tests keep their meaning), add:
  - country mode (`nearby_mode` country, home GB): a Manchester concert (country `GB`, 74 mi) **and** a Glasgow concert (`GB`) are listed; a Dublin concert (`IE`) is not; a concert with null coords but country `GB` **is** listed with `distanceMiles` null.
  - country mode with no `home_country_code` → `hasHomeLocation` reflects only whether lat/lng exist, `nearby` empty, and a new prop `areaLabel` null.
  - props: `nearbyMode` (`'country'|'radius'`), `homeCountryCode`, keep `radiusMiles`.
- [ ] Controller: build `NearbyArea::forUser($user)`; nearby = upcoming non-cancelled concerts where `contains(...) === true`, first 10; each row's `distanceMiles` = `round(distance)` or null. Send `hasHomeLocation` (= `isConfigured()`), `nearbyMode`, `homeCountryCode`, `radiusMiles`.
- [ ] Vue: empty-state text uses the area — country mode: "No upcoming gigs in {countryName} yet." where `countryName = new Intl.DisplayNames(['en'], { type: 'region' }).of(code) ?? code`; radius mode keeps "within N miles". Show "N mi" only when `distanceMiles !== null`. `distanceMiles` type becomes `number | null`.
- [ ] Commit `feat: My Artists nearby supports anywhere in country`.

### Task 5: Alert settings — the option

**Files:** `app/Http/Controllers/Settings/AlertSettingsController.php`, `tests/Feature/Settings/AlertSettingsTest.php`, `resources/js/pages/settings/Alerts.vue`.

- [ ] Tests first: `edit` props include `settings.nearbyMode`, `settings.homeCountryCode`; `update` accepts and saves `home_country_code` (nullable, `size:2`, `alpha`, stored upper-cased) and `nearby_mode` (`required`, in `country,radius`); `nearby_mode=country` without a home location is allowed (nothing to filter yet); `home_country_code` is cleared together with the location; validation error for `nearby_mode=planet` and for `home_country_code=GBR`.
- [ ] Controller: add the two fields to validation + `edit()` props; upper-case the code before saving.
- [ ] Vue:
  - Form gains `home_country_code` and `nearby_mode`. `choose(place)` also sets `home_country_code = place.countryCode`; `clearLocation`/`cancelChange` snapshot and restore it with the other location fields.
  - Distance control becomes 5 options in a wrapping grid (`grid grid-cols-3 sm:grid-cols-5` or a flex-wrap row): first **"Anywhere in {countryName}"** (`nearby_mode='country'`), then 25/50/100/250 mi (each sets `nearby_mode='radius'` and `radius_miles`). `aria-pressed` reflects the active option. Country name via `Intl.DisplayNames` as in Task 4; when there's no country code yet, label it "Anywhere in my country".
  - Under the control, when `nearby_mode='country'` and no `home_country_code`: hint "Set your home location so we know which country." (also shown for an existing location saved before country codes existed: "Re-pick your home location to use this.").
  - Section description: "Which gigs count as near you."
- [ ] Commit `feat: anywhere-in-country distance option`.

### Task 6: Backfill country codes for existing users

**Files:** Create `app/Console/Commands/BackfillCountryCodes.php` (signature `gigradar:backfill-country-codes`), test `tests/Feature/Console/BackfillCountryCodesTest.php`.

- [ ] For each user with `home_lat`/`home_lng` set and `home_country_code` null: `Geocoder::reverse()`; if it returns a country code, save it. Catch `GeocoderException` per user (report, continue). Print a one-line summary (`Updated N of M users.`).
- [ ] Test with faked Nominatim reverse: one user gets `GB`; a user without a location is skipped; an outage leaves the user unchanged and the command still exits 0.
- [ ] Run it locally once: `php artisan gigradar:backfill-country-codes` (one real Nominatim request per affected user — expected 1).
- [ ] Commit `feat: backfill home country codes`.

### Task 7: Check on iPhone

- [ ] Settings → Alerts shows "Anywhere in United Kingdom" selected by default; switching to 50 mi and back saves.
- [ ] My Artists → "Upcoming near you" lists UK gigs anywhere, with miles where known.
- [ ] `git push origin main`.
