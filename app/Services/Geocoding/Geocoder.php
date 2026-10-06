<?php

namespace App\Services\Geocoding;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Place lookup via OpenStreetMap Nominatim. Results are cached; coordinates are rounded to ~10 m. */
class Geocoder
{
    private const BASE_URL = 'https://nominatim.openstreetmap.org';

    public function __construct(private readonly string $userAgent) {}

    /** @return list<Place> */
    public function search(string $query): array
    {
        $query = trim($query);

        return Cache::remember('geo:search:'.md5(mb_strtolower($query)), now()->addDays(30), function () use ($query) {
            $results = $this->get('/search', ['q' => $query, 'format' => 'jsonv2', 'limit' => 5]);

            return array_values(array_filter(array_map($this->toPlace(...), array_filter($results, 'is_array'))));
        });
    }

    public function reverse(float $lat, float $lng): ?Place
    {
        $key = sprintf('geo:reverse:%.3f,%.3f', $lat, $lng);

        return Cache::remember($key, now()->addDays(30), fn () => $this->toPlace(
            $this->get('/reverse', ['lat' => $lat, 'lon' => $lng, 'format' => 'jsonv2', 'zoom' => 10]),
        ));
    }

    private function get(string $path, array $query): array
    {
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

        return is_array($json) ? $json : [];
    }

    private function toPlace(array $result): ?Place
    {
        if (! isset($result['lat'], $result['lon'], $result['display_name'])) {
            return null;
        }

        return new Place(
            name: $this->label($result['display_name']),
            lat: round((float) $result['lat'], 4),
            lng: round((float) $result['lon'], 4),
        );
    }

    /** "Leicester, Leicestershire, East Midlands, England, United Kingdom" → "Leicester, Leicestershire, United Kingdom" */
    private function label(string $displayName): string
    {
        $parts = array_map('trim', explode(',', $displayName));

        return count($parts) > 3
            ? implode(', ', [$parts[0], $parts[1], end($parts)])
            : implode(', ', $parts);
    }
}
