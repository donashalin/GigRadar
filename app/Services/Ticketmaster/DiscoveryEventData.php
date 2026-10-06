<?php

namespace App\Services\Ticketmaster;

final readonly class DiscoveryEventData
{
    public function __construct(
        public ConcertData $concert,
        public string $attractionId,
        public string $attractionName,
        public ?string $attractionImageUrl,
    ) {}
}
