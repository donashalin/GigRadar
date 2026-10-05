<?php

namespace App\Services\Ticketmaster;

use Carbon\CarbonImmutable;

final readonly class ConcertData
{
    public function __construct(
        public string $id,
        public string $name,
        public CarbonImmutable $startsAt,
        public string $localDate,
        public string $venueName,
        public string $city,
        public string $country,
        public ?float $lat,
        public ?float $lng,
        public string $ticketUrl,
        public string $status,
    ) {}
}
