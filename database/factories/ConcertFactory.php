<?php

namespace Database\Factories;

use App\Models\Artist;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Concert> */
class ConcertFactory extends Factory
{
    public function definition(): array
    {
        return [
            'artist_id' => Artist::factory(),
            'ticketmaster_id' => 'vv'.fake()->unique()->bothify('??##########'),
            'name' => fake()->words(3, true),
            'starts_at' => now()->addMonths(2),
            'venue_name' => 'O2 Academy',
            'city' => 'Leicester',
            'country' => 'GB',
            'lat' => 52.6369,
            'lng' => -1.1398,
            'ticket_url' => 'https://www.ticketmaster.co.uk/event/example',
            'status' => 'onsale',
            'first_seen_at' => now(),
            'alerted_at' => now(),
        ];
    }
}
