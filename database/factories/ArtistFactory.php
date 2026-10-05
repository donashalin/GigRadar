<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Artist> */
class ArtistFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticketmaster_id' => 'K8'.fake()->unique()->bothify('??########'),
            'name' => fake()->unique()->name(),
            'image_url' => null,
            'seeded' => true,
            'last_checked_at' => now(),
        ];
    }

    public function unseeded(): static
    {
        return $this->state(['seeded' => false, 'last_checked_at' => null]);
    }
}
