<?php

use App\Models\Artist;
use App\Models\Concert;
use App\Models\User;
use App\Support\NearbyArea;

it('lets a user follow an artist with default alert scope', function () {
    $user = User::factory()->create();
    $artist = Artist::factory()->create();

    $user->artists()->attach($artist, ['last_seen_at' => now()]);

    $followed = $user->artists()->first();
    expect($followed->is($artist))->toBeTrue()
        ->and($followed->pivot->alert_scope)->toBe('everywhere')
        ->and($artist->followers()->first()->is($user))->toBeTrue();
});

it('gives users sensible alert defaults', function () {
    $user = User::factory()->create()->fresh();

    expect($user->radius_miles)->toBe(50)
        ->and($user->notify_email)->toBeTrue()
        ->and($user->home_lat)->toBeNull()
        ->and($user->nearby_mode)->toBe('country')
        ->and($user->home_country_code)->toBeNull();
});

it('lets the same Ticketmaster event belong to two artists', function () {
    $a = Concert::factory()->create(['ticketmaster_id' => 'SHARED1']);
    $b = Concert::factory()->create(['ticketmaster_id' => 'SHARED1']);

    expect($a->artist->is($b->artist))->toBeFalse()
        ->and($a->artist->concerts)->toHaveCount(1);
});

it('deletes follows and concerts when an artist is deleted', function () {
    $user = User::factory()->create();
    $concert = Concert::factory()->create();
    $user->artists()->attach($concert->artist, ['last_seen_at' => now()]);

    $concert->artist->delete();

    expect(Concert::count())->toBe(0)->and($user->artists()->count())->toBe(0);
});

it('applies alert defaults in memory and builds a NearbyArea from them', function () {
    $user = User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'secret-pass']);

    expect($user->nearby_mode)->toBe('country')
        ->and($user->radius_miles)->toBe(50)
        ->and($user->notify_email)->toBeTrue();

    $area = NearbyArea::forUser($user);
    expect($area->mode)->toBe('country')->and($area->radiusMiles)->toBe(50)->and($area->isConfigured())->toBeFalse();
});
