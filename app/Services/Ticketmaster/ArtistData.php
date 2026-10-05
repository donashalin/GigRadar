<?php

namespace App\Services\Ticketmaster;

final readonly class ArtistData
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $imageUrl,
    ) {}
}
