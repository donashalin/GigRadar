<?php

use App\Models\Artist;
use App\Models\Concert;
use App\Services\ArtistSync;
use App\Services\Ticketmaster\TicketmasterException;
use Illuminate\Support\Facades\Http;

// Fakes are set per test: when several Http::fake() patterns match, the first registered wins,
// so a shared beforeEach fake could not be overridden by the failure test.

it('stores fetched concerts as new and marks the artist seeded', function () {
    Http::fake(['app.ticketmaster.com/discovery/v2/events.json*' => Http::response(tmFixture('events'))]);
    $artist = Artist::factory()->unseeded()->create(['ticketmaster_id' => 'K8vZ917G1V0']);

    $new = app(ArtistSync::class)->syncEvents($artist);

    expect($new)->toHaveCount(2)
        ->and($artist->concerts()->count())->toBe(2)
        ->and($artist->fresh()->seeded)->toBeTrue()
        ->and($artist->fresh()->last_checked_at)->not->toBeNull();

    $manchester = $artist->concerts()->where('ticketmaster_id', 'G5vYZ9abc001')->first();
    expect($manchester->city)->toBe('Manchester')
        ->and($manchester->lat)->toBe(53.4668)
        ->and($manchester->first_seen_at)->not->toBeNull();
});

it('returns only unseen concerts and updates existing ones', function () {
    Http::fake(['app.ticketmaster.com/discovery/v2/events.json*' => Http::response(tmFixture('events'))]);
    $artist = Artist::factory()->create(['ticketmaster_id' => 'K8vZ917G1V0']);
    $existing = Concert::factory()->for($artist)->create([
        'ticketmaster_id' => 'G5vYZ9abc002',
        'status' => 'onsale',
        'first_seen_at' => now()->subWeek(),
    ]);

    $new = app(ArtistSync::class)->syncEvents($artist);

    expect($new->pluck('ticketmaster_id')->all())->toBe(['G5vYZ9abc001'])
        ->and($existing->fresh()->status)->toBe('cancelled')
        ->and($existing->fresh()->first_seen_at->lt(now()->subDays(6)))->toBeTrue();
});

it('leaves last_checked_at untouched when Ticketmaster fails', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response([], 500)]);
    $artist = Artist::factory()->unseeded()->create();

    expect(fn () => app(ArtistSync::class)->syncEvents($artist))->toThrow(TicketmasterException::class);
    expect($artist->fresh()->last_checked_at)->toBeNull()
        ->and($artist->fresh()->seeded)->toBeFalse();
});

it('is idempotent when synced twice', function () {
    Http::fake(['app.ticketmaster.com/discovery/v2/events.json*' => Http::response(tmFixture('events'))]);
    $artist = Artist::factory()->unseeded()->create(['ticketmaster_id' => 'K8vZ917G1V0']);

    app(ArtistSync::class)->syncEvents($artist);
    $second = app(ArtistSync::class)->syncEvents($artist->fresh());

    expect($second)->toBeEmpty()->and($artist->concerts()->count())->toBe(2);
});

it('handles zero events without touching stored concerts', function () {
    Http::fake(['app.ticketmaster.com/discovery/v2/events.json*' => Http::response(tmFixture('empty'))]);
    $artist = Artist::factory()->unseeded()->create(['ticketmaster_id' => 'K8vZ917G1V0']);
    Concert::factory()->for($artist)->create();

    $new = app(ArtistSync::class)->syncEvents($artist);

    expect($new)->toBeEmpty()
        ->and($artist->concerts()->count())->toBe(1)
        ->and($artist->fresh()->seeded)->toBeTrue()
        ->and($artist->fresh()->last_checked_at)->not->toBeNull();
});
