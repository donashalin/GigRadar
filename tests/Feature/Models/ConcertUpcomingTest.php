<?php

use App\Models\Concert;

it('returns concerts from the venue-local today onwards, in date order', function () {
    $later = Concert::factory()->create(['local_date' => today()->addDays(10)->toDateString(), 'starts_at' => now()->addDays(10)]);
    $today = Concert::factory()->create(['local_date' => today()->toDateString(), 'starts_at' => now()]);
    Concert::factory()->create(['local_date' => today()->subDay()->toDateString(), 'starts_at' => now()->subDay()]);

    expect(Concert::query()->upcoming()->pluck('id')->all())->toBe([$today->id, $later->id]);
});

it('falls back to starts_at when local_date is missing', function () {
    $future = Concert::factory()->create(['local_date' => null, 'starts_at' => now()->addDay()]);
    Concert::factory()->create(['local_date' => null, 'starts_at' => now()->subDays(2)]);

    expect(Concert::query()->upcoming()->pluck('id')->all())->toBe([$future->id]);
});
