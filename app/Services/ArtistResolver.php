<?php

namespace App\Services;

use App\Models\Artist;
use App\Services\Ticketmaster\TicketmasterClient;

class ArtistResolver
{
    public function __construct(private readonly TicketmasterClient $ticketmaster) {}

    /** @throws \App\Services\Ticketmaster\TicketmasterException */
    public function resolve(string $ticketmasterId): Artist
    {
        $artist = Artist::firstWhere('ticketmaster_id', $ticketmasterId);
        if ($artist) {
            return $artist;
        }

        $data = $this->ticketmaster->attraction($ticketmasterId);

        return Artist::createOrFirst(
            ['ticketmaster_id' => $ticketmasterId],
            ['name' => $data->name, 'image_url' => $data->imageUrl],
        );
    }
}
