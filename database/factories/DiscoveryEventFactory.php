<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\DiscoveryEvent> */
class DiscoveryEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticketmaster_event_id' => 'ev'.fake()->unique()->bothify('??##########'),
            'classification_id' => 'KZazBEonSMnZfZ7vAde',
            'attraction_ticketmaster_id' => 'K8'.fake()->unique()->bothify('??########'),
            'attraction_name' => fake()->unique()->name(),
            'attraction_image_url' => null,
            'name' => fake()->words(3, true),
            'starts_at' => now()->addMonths(2),
            'local_date' => now()->addMonths(2)->toDateString(),
            'venue_name' => 'O2 Academy',
            'city' => 'Leicester',
            'country' => 'GB',
            'lat' => 52.6369,
            'lng' => -1.1398,
            'ticket_url' => 'https://www.ticketmaster.co.uk/event/example',
            'status' => 'onsale',
            'first_seen_at' => now(),
        ];
    }
}
