<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

it('fills in the country code for users with a home location', function () {
    Http::fake(['nominatim.openstreetmap.org/reverse*' => Http::response(nominatimFixture('reverse'))]);
    $user = User::factory()->create(['home_location_name' => 'Leicester', 'home_lat' => 52.6362, 'home_lng' => -1.1331]);

    $this->artisan('gigradar:backfill-country-codes')
        ->expectsOutput('Updated 1 of 1 users.')
        ->assertSuccessful();

    expect($user->fresh()->home_country_code)->toBe('GB');
});

it('skips users without a location or with a country code already', function () {
    Http::fake();
    $noLocation = User::factory()->create();
    $done = User::factory()->create(['home_location_name' => 'Leicester', 'home_lat' => 52.6, 'home_lng' => -1.1, 'home_country_code' => 'IE']);

    $this->artisan('gigradar:backfill-country-codes')->expectsOutput('Updated 0 of 0 users.')->assertSuccessful();

    Http::assertNothingSent();
    expect($noLocation->fresh()->home_country_code)->toBeNull()->and($done->fresh()->home_country_code)->toBe('IE');
});

it('leaves a user unchanged and still succeeds when Nominatim is down', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 503)]);
    $user = User::factory()->create(['home_location_name' => 'Leicester', 'home_lat' => 52.6362, 'home_lng' => -1.1331]);

    $this->artisan('gigradar:backfill-country-codes')
        ->expectsOutputToContain('Could not look up user')
        ->expectsOutput('Updated 0 of 1 users.')
        ->assertSuccessful();

    expect($user->fresh()->home_country_code)->toBeNull();
});

it('keeps going after one user fails', function () {
    Http::fake(['nominatim.openstreetmap.org/reverse*' => Http::sequence()
        ->push('', 503)
        ->push(nominatimFixture('reverse'))]);
    $first = User::factory()->create(['home_lat' => 10.0, 'home_lng' => 10.0, 'home_location_name' => 'A']);
    $second = User::factory()->create(['home_lat' => 52.6362, 'home_lng' => -1.1331, 'home_location_name' => 'B']);

    $this->artisan('gigradar:backfill-country-codes')->expectsOutput('Updated 1 of 2 users.')->assertSuccessful();

    expect($first->fresh()->home_country_code)->toBeNull()->and($second->fresh()->home_country_code)->toBe('GB');
});
