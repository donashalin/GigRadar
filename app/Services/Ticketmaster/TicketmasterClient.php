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
            'size' => 20,
        ]);

        return array_map($this->toArtist(...), $json['_embedded']['attractions'] ?? []);
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

    private function get(string $path, array $query = []): array
    {
        $this->throttle();

        try {
            $response = Http::baseUrl(self::BASE_URL)
                ->acceptJson()
                ->timeout(10)
                ->get($path, [...$query, 'apikey' => $this->apiKey]);
        } catch (ConnectionException $e) {
            throw new TicketmasterException('Ticketmaster unreachable: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new TicketmasterException("Ticketmaster {$path} failed with HTTP {$response->status()}", $response->status());
        }

        return $response->json() ?? [];
    }

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
        $images = collect($attraction['images'] ?? []);
        $image = $images->where('ratio', '16_9')->sortByDesc('width')->first() ?? $images->first();

        return new ArtistData($attraction['id'], $attraction['name'], $image['url'] ?? null);
    }

    private function toConcert(array $event): ?ConcertData
    {
        $start = $event['dates']['start'] ?? [];
        $startsAt = match (true) {
            isset($start['dateTime']) => CarbonImmutable::parse($start['dateTime'])->utc(),
            isset($start['localDate']) => CarbonImmutable::parse($start['localDate'], 'UTC'),
            default => null,
        };

        if ($startsAt === null) {
            return null;
        }

        $venue = $event['_embedded']['venues'][0] ?? [];
        $status = $event['dates']['status']['code'] ?? 'onsale';

        return new ConcertData(
            id: $event['id'],
            name: $event['name'],
            startsAt: $startsAt,
            venueName: $venue['name'] ?? 'Venue TBA',
            city: $venue['city']['name'] ?? '',
            country: $venue['country']['countryCode'] ?? '',
            lat: isset($venue['location']['latitude']) ? (float) $venue['location']['latitude'] : null,
            lng: isset($venue['location']['longitude']) ? (float) $venue['location']['longitude'] : null,
            ticketUrl: $event['url'] ?? '',
            status: in_array($status, self::STATUSES, true) ? $status : 'onsale',
        );
    }
}
