<?php

namespace App\Services\Geocoding;

final readonly class Place
{
    public function __construct(
        public string $name,
        public float $lat,
        public float $lng,
    ) {}

    /** @return array{name: string, lat: float, lng: float} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'lat' => $this->lat, 'lng' => $this->lng];
    }
}
