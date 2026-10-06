<?php

use App\Models\Artist;
use App\Services\ArtistResolver;
use Illuminate\Support\Facades\Http;

it('returns an existing artist without calling Ticketmaster', function () {
    $artist = Artist::factory()->create();
    Http::fake();

    expect(app(ArtistResolver::class)->resolve($artist->ticketmaster_id)->is($artist))->toBeTrue();
    Http::assertNothingSent();
});

it('creates an unseeded artist from Ticketmaster when unknown', function () {
    Http::fake(['app.ticketmaster.com/discovery/v2/attractions/K8vZ917G1V0.json*' => Http::response(tmFixture('attraction'))]);

    $artist = app(ArtistResolver::class)->resolve('K8vZ917G1V0');

    expect($artist->exists)->toBeTrue()
        ->and($artist->name)->toBe('Fontaines D.C.')
        ->and($artist->image_url)->toBe('https://s1.ticketm.net/dam/a/large.jpg')
        ->and($artist->seeded)->toBeFalse()
        ->and($artist->last_checked_at)->toBeNull();
});

it('stores classifications when creating an artist', function () {
    Http::fake(['app.ticketmaster.com/discovery/v2/attractions/K8vZ917G1V0.json*' => Http::response(tmFixture('attraction'))]);

    $artist = app(ArtistResolver::class)->resolve('K8vZ917G1V0')->fresh();

    expect($artist->classifications_checked_at)->not->toBeNull()
        ->and($artist->genre_id)->toBe('KnvZfZ7vAvv')
        ->and($artist->genre_name)->toBe('Alternative')
        ->and($artist->sub_genre_id)->toBe('KZazBEonSMnZfZ7vAde')
        ->and($artist->sub_genre_name)->toBe('Alternative Rock');
});
