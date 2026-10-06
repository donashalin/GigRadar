<?php

namespace App\Services\Ticketmaster;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TicketmasterClient
{
    private const BASE_URL = 'https://app.ticketmaster.com/discovery/v2';

    private const STATUSES = ['onsale', 'offsale', 'cancelled', 'postponed', 'rescheduled'];

    private int $lastRequestNs = 0;

    public function __construct(
        private readonly string $apiKey,
        private readonly int $throttleMs,
    ) {}

    /** @return list<ArtistData> */
    public function searchAttractions(string $keyword): array
    {
        $json = $this->get('/attractions.json', [
            'keyword' => $keyword,
            'classificationName' => 'music',
            'sort' => 'relevance,desc',
            'size' => 20,
        ]);

        // One malformed attraction shouldn't fail the whole search, so skip it.
        $valid = array_filter($json['_embedded']['attractions'] ?? [], fn ($a) => is_array($a) && isset($a['id'], $a['name']));
        $artists = array_values(array_map($this->toArtist(...), $valid));

        // Exact name matches first; usort is stable (PHP 8+) so Ticketmaster's order is otherwise kept.
        $needle = mb_strtolower(trim($keyword));
        usort($artists, fn (ArtistData $a, ArtistData $b) => (mb_strtolower(trim($b->name)) === $needle) <=> (mb_strtolower(trim($a->name)) === $needle));

        return $artists;
    }

    public function attraction(string $id): ArtistData
    {
        return $this->toArtist($this->get('/attractions/'.rawurlencode($id).'.json'));
    }

    /** @return list<ConcertData> */
    public function upcomingEvents(string $attractionId): array
    {
        $json = $this->get('/events.json', [
            'attractionId' => $attractionId,
            'classificationName' => 'music',
            'sort' => 'date,asc',
            'size' => 200,
        ]);

        $concerts = array_map($this->toConcert(...), $json['_embedded']['events'] ?? []);

        return array_values(array_filter($concerts));
    }

    /** @return list<DiscoveryEventData> */
    public function eventsByClassification(string $classificationId, string $countryCode): array
    {
        $json = $this->get('/events.json', [
            'classificationId' => $classificationId,
            'countryCode' => $countryCode,
            'classificationName' => 'music',
            'sort' => 'date,asc',
            'size' => 200,
        ]);

        $events = [];
        foreach ($json['_embedded']['events'] ?? [] as $event) {
            if (is_array($event) && ($discovery = $this->toDiscoveryEvent($event)) !== null) {
                $events[] = $discovery;
            }
        }

        return $events;
    }

    private function get(string $path, array $query = []): array
    {
        $this->throttle();

        try {
            $response = Http::baseUrl(self::BASE_URL)
                ->acceptJson()
                ->timeout(10)
                ->get($path, [...$query, 'apikey' => $this->apiKey]);
        } catch (ConnectionException $e) {
            // The original message contains the request URL, including the API key, so redact it
            // and deliberately do not chain the original exception.
            $message = $this->apiKey === '' ? $e->getMessage() : str_replace($this->apiKey, '[redacted]', $e->getMessage());

            throw new TicketmasterException('Ticketmaster unreachable: '.$message, 0);
        }

        if ($response->failed()) {
            throw new TicketmasterException("Ticketmaster {$path} failed with HTTP {$response->status()}", $response->status());
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new TicketmasterException("Ticketmaster {$path} returned an invalid response", 502);
        }

        return $json;
    }

    /** Per-process only: fine for the single check-dates process, not a cross-process rate limit. */
    private function throttle(): void
    {
        if ($this->throttleMs <= 0) {
            return;
        }

        if ($this->lastRequestNs > 0) {
            $elapsedMs = (hrtime(true) - $this->lastRequestNs) / 1_000_000;
            if ($elapsedMs < $this->throttleMs) {
                usleep((int) (($this->throttleMs - $elapsedMs) * 1000));
            }
        }

        $this->lastRequestNs = hrtime(true);
    }

    private function toArtist(array $attraction): ArtistData
    {
        if (! isset($attraction['id'], $attraction['name'])) {
            throw new TicketmasterException('Ticketmaster returned an attraction without an id or name', 502);
        }

        $images = collect($attraction['images'] ?? []);
        $image = $images->where('ratio', '16_9')->sortByDesc('width')->first() ?? $images->first();

        $classification = is_array($attraction['classifications'][0] ?? null) ? $attraction['classifications'][0] : [];

        return new ArtistData(
            $attraction['id'],
            $attraction['name'],
            $this->bestImageUrl($attraction['images'] ?? []),
            $this->stringOrNull($classification['genre']['id'] ?? null),
            $this->stringOrNull($classification['genre']['name'] ?? null),
            $this->stringOrNull($classification['subGenre']['id'] ?? null),
            $this->stringOrNull($classification['subGenre']['name'] ?? null),
        );
    }

    private function bestImageUrl(mixed $images): ?string
    {
        $images = collect(is_array($images) ? array_filter($images, 'is_array') : []);
        $image = $images->where('ratio', '16_9')->sortByDesc('width')->first() ?? $images->first();

        return $this->safeUrl($image['url'] ?? null);
    }

    private function toDiscoveryEvent(array $event): ?DiscoveryEventData
    {
        $attraction = $event['_embedded']['attractions'][0] ?? null;
        if (! is_array($attraction) || ! isset($attraction['id'], $attraction['name'])
            || ! is_string($attraction['id']) || ! is_string($attraction['name'])) {
            return null;
        }

        $concert = $this->toConcert($event);

        return $concert === null ? null : new DiscoveryEventData(
            $concert,
            $attraction['id'],
            $attraction['name'],
            $this->bestImageUrl($attraction['images'] ?? []),
        );
    }

    private function toConcert(array $event): ?ConcertData
    {
        if (! isset($event['id'], $event['name'])) {
            return null;
        }

        $start = $event['dates']['start'] ?? [];
        $startsAt = match (true) {
            isset($start['dateTime']) => CarbonImmutable::parse($start['dateTime'])->utc(),
            // Date-only event: stored as UTC midnight, so don't treat it as a real start time.
            isset($start['localDate']) => CarbonImmutable::parse($start['localDate'], 'UTC'),
            default => null,
        };

        if ($startsAt === null) {
            return null;
        }

        $venue = $event['_embedded']['venues'][0] ?? [];
        $status = $event['dates']['status']['code'] ?? 'onsale';
        $status = $status === 'canceled' ? 'cancelled' : $status; // Ticketmaster uses the US spelling

        return new ConcertData(
            id: $event['id'],
            name: $event['name'],
            startsAt: $startsAt,
            localDate: $start['localDate'] ?? $startsAt->toDateString(),
            venueName: $venue['name'] ?? 'Venue TBA',
            city: $venue['city']['name'] ?? '',
            country: $venue['country']['countryCode'] ?? '',
            lat: isset($venue['location']['latitude']) ? (float) $venue['location']['latitude'] : null,
            lng: isset($venue['location']['longitude']) ? (float) $venue['location']['longitude'] : null,
            ticketUrl: $this->safeUrl($event['url'] ?? null) ?? '',
            status: in_array($status, self::STATUSES, true) ? $status : 'onsale',
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Only http(s) URLs are trusted; anything else (e.g. javascript:) is dropped. */
    private function safeUrl(mixed $url): ?string
    {
        return is_string($url) && preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }
}
