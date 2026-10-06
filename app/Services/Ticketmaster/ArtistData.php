<?php

namespace App\Services\Ticketmaster;

final readonly class ArtistData
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $imageUrl,
        public ?string $genreId = null,
        public ?string $genreName = null,
        public ?string $subGenreId = null,
        public ?string $subGenreName = null,
    ) {}
}
