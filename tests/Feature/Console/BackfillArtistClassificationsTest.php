<?php

use App\Models\Artist;
use Illuminate\Support\Facades\Http;

it('fills in classifications for artists without them', function () {
    Http::fake(['app.ticketmaster.com/discovery/v2/attractions/K8A.json*' => Http::response(tmFixture('attraction'))]);
    $artist = Artist::factory()->create(['ticketmaster_id' => 'K8A']);

    $this->artisan('gigradar:backfill-artist-classifications')
        ->expectsOutput('Updated 1 of 1 artists.')
        ->assertSuccessful();

    expect($artist->fresh()->classifications_checked_at)->not->toBeNull()
        ->and($artist->fresh()->genre_name)->toBe('Alternative')
        ->and($artist->fresh()->sub_genre_id)->toBe('KZazBEonSMnZfZ7vAde');
});

it('skips artists already checked', function () {
    Http::fake();
    Artist::factory()->create(['genre_id' => 'G1', 'genre_name' => 'Rock', 'classifications_checked_at' => now()]);

    $this->artisan('gigradar:backfill-artist-classifications')->expectsOutput('Updated 0 of 0 artists.')->assertSuccessful();

    Http::assertNothingSent();
});

it('keeps going when Ticketmaster fails for one artist', function () {
    Http::fake([
        'app.ticketmaster.com/discovery/v2/attractions/BAD.json*' => Http::response([], 500),
        'app.ticketmaster.com/discovery/v2/attractions/K8A.json*' => Http::response(tmFixture('attraction')),
    ]);
    $bad = Artist::factory()->create(['ticketmaster_id' => 'BAD']);
    $good = Artist::factory()->create(['ticketmaster_id' => 'K8A']);

    $this->artisan('gigradar:backfill-artist-classifications')
        ->expectsOutputToContain('Could not look up artist')
        ->expectsOutput('Updated 1 of 2 artists.')
        ->assertSuccessful();

    expect($bad->fresh()->genre_id)->toBeNull()->and($good->fresh()->genre_id)->toBe('KnvZfZ7vAvv');
});

it('marks artists with no classification as checked so they are not retried', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response(['id' => 'K8A', 'name' => 'Nobody'])]);
    $artist = Artist::factory()->create(['ticketmaster_id' => 'K8A']);

    $this->artisan('gigradar:backfill-artist-classifications')->expectsOutput('Updated 0 of 1 artists.')->assertSuccessful();
    expect($artist->fresh()->classifications_checked_at)->not->toBeNull()->and($artist->fresh()->genre_id)->toBeNull();

    Http::fake();
    $this->artisan('gigradar:backfill-artist-classifications')->expectsOutput('Updated 0 of 0 artists.');
    Http::assertNothingSent();
});

it('keeps going on unexpected errors and leaves the artist unchecked', function () {
    Http::fake(['app.ticketmaster.com/*' => fn () => throw new RuntimeException('boom')]);
    $artist = Artist::factory()->create();

    $this->artisan('gigradar:backfill-artist-classifications')->expectsOutput('Updated 0 of 1 artists.')->assertSuccessful();

    expect($artist->fresh()->classifications_checked_at)->toBeNull();
});
