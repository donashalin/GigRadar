<?php

namespace App\Services;

use App\Models\Artist;
use App\Services\Ticketmaster\TicketmasterClient;
use App\Services\Ticketmaster\TicketmasterException;

class ArtistResolver
{
    public function __construct(private readonly TicketmasterClient $ticketmaster) {}

    /** @throws TicketmasterException */
    public function resolve(string $ticketmasterId): Artist
    {
        $artist = Artist::firstWhere('ticketmaster_id', $ticketmasterId);
        if ($artist) {
            return $artist;
        }

        $data = $this->ticketmaster->attraction($ticketmasterId);

        return Artist::createOrFirst(
            ['ticketmaster_id' => $ticketmasterId],
            [
                'name' => $data->name,
                'image_url' => $data->imageUrl,
                'genre_id' => $data->genreId,
                'genre_name' => $data->genreName,
                'sub_genre_id' => $data->subGenreId,
                'sub_genre_name' => $data->subGenreName,
                'classifications_checked_at' => now(),
            ],
        );
    }
}
