<?php

use App\Models\Concert;

it('backfills from_seed only for concerts whose alerted_at equals first_seen_at', function () {
    $stamp = now()->subWeek()->startOfSecond();
    $seeded = Concert::factory()->create(['alerted_at' => $stamp, 'first_seen_at' => $stamp, 'from_seed' => false]);
    $later = Concert::factory()->create(['alerted_at' => null, 'first_seen_at' => $stamp, 'from_seed' => false]);

    (require database_path('migrations/2026_10_06_000002_backfill_from_seed_on_concerts.php'))->up();

    expect($seeded->fresh()->from_seed)->toBeTrue()
        ->and($later->fresh()->from_seed)->toBeFalse();
});
