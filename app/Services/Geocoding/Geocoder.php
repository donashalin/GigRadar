<?php

namespace App\Services\Geocoding;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/** Place lookup via OpenStreetMap Nominatim. Results are cached; lookups use ~100 m precision; returned coordinates are rounded to 4dp. */
class Geocoder
{
    private const BASE_URL = 'https://nominatim.openstreetmap.org';

    public function __construct(private readonly string $userAgent) {}

    /** @return list<Place> */
    public function search(string $query): array
    {
        $query = trim($query);

        return Cache::remember('geo:search:'.md5(mb_strtolower($query)), now()->addDays(30), function () use ($query) {
            $results = $this->get('/search', ['q' => $query, 'format' => 'jsonv2', 'limit' => 5, 'accept-language' => 'en']);

            return array_values(array_filter(array_map($this->toPlace(...), array_filter($results, 'is_array'))));
        });
    }

    public function reverse(float $lat, float $lng): ?Place
    {
        $lat = round($lat, 3);
        $lng = round($lng, 3);
        $key = sprintf('geo:reverse:%.3f,%.3f', $lat, $lng);

        // false is a sentinel for "no place here" (Cache::remember treats null as a miss).
        $place = Cache::remember($key, now()->addDays(30), fn () => $this->toPlace(
            $this->get('/reverse', ['lat' => $lat, 'lon' => $lng, 'format' => 'jsonv2', 'zoom' => 10, 'accept-language' => 'en']),
        ) ?? false);

        return $place ?: null;
    }

    private function get(string $path, array $query): array
    {
        // App-wide cap (~1 req/s average); cached lookups never reach here.
        if (! RateLimiter::attempt('nominatim', 60, fn () => true, 60)) {
            throw new GeocoderException('Nominatim rate limit reached');
        }

        try {
            $response = Http::baseUrl(self::BASE_URL)
                ->withUserAgent($this->userAgent)
                ->acceptJson()
                ->timeout(8)
                ->get($path, $query);
        } catch (ConnectionException) {
            throw new GeocoderException('Nominatim unreachable');
        }

        if ($response->failed()) {
            throw new GeocoderException("Nominatim {$path} failed with HTTP {$response->status()}");
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new GeocoderException('Nominatim returned an unexpected response');
        }

        return $json;
    }

    private function toPlace(array $result): ?Place
    {
        if (! isset($result['lat'], $result['lon'], $result['display_name'])
            || ! is_numeric($result['lat'])
            || ! is_numeric($result['lon'])
            || ! is_string($result['display_name'])
            || trim($result['display_name']) === '') {
            return null;
        }

        $name = $this->label($result['display_name']);
        if ($name === '') {
            return null;
        }

        return new Place(
            name: $name,
            lat: round((float) $result['lat'], 4),
            lng: round((float) $result['lon'], 4),
        );
    }

    /** "Leicester, Leicestershire, East Midlands, England, United Kingdom" → "Leicester, Leicestershire, United Kingdom" */
    private function label(string $displayName): string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $displayName)), 'strlen'));

        return count($parts) > 3
            ? implode(', ', [$parts[0], $parts[1], end($parts)])
            : implode(', ', $parts);
    }
}
