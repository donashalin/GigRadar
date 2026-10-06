<?php

use App\Models\Artist;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects the old alerts page to the settings list', function () {
    $this->actingAs(User::factory()->create())->get('/settings/alerts')->assertRedirect('/settings')->assertStatus(301);
});

it('requires a verified user for the alert sub-pages', function (string $url) {
    $this->get($url)->assertRedirect('/login');
    $this->actingAs(User::factory()->unverified()->create())->get($url)->assertRedirect(route('verification.notice'));
})->with(['/settings', '/settings/location', '/settings/near-me']);

it('shows the home location page', function () {
    $user = User::factory()->create(['home_location_name' => 'Leicester, Leicestershire, United Kingdom', 'home_lat' => 52.6362, 'home_lng' => -1.1331, 'home_country_code' => 'GB']);

    $this->actingAs($user)->get('/settings/location')
        ->assertInertia(fn (Assert $page) => $page->component('settings/Location')
            ->where('homeLocationName', 'Leicester, Leicestershire, United Kingdom'));
});

it('shows the near me page', function () {
    $user = User::factory()->create(['radius_miles' => 100, 'nearby_mode' => 'radius', 'home_country_code' => 'GB']);

    $this->actingAs($user)->get('/settings/near-me')
        ->assertInertia(fn (Assert $page) => $page->component('settings/NearMe')
            ->where('nearbyMode', 'radius')
            ->where('radiusMiles', 100)
            ->where('homeCountryCode', 'GB')
            ->where('radiusOptions', [25, 50, 100, 250]));
});

it('saves alert settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/settings/alerts', [
        'home_location_name' => 'Leicester, Leicestershire, United Kingdom',
        'home_lat' => 52.6362,
        'home_lng' => -1.1331,
        'home_country_code' => 'gb',
        'radius_miles' => 25,
        'nearby_mode' => 'radius',
        'notify_email' => false,
    ])->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->home_location_name)->toBe('Leicester, Leicestershire, United Kingdom')
        ->and($user->home_lat)->toBe(52.6362)
        ->and($user->home_country_code)->toBe('GB')
        ->and($user->nearby_mode)->toBe('radius')
        ->and($user->radius_miles)->toBe(25)
        ->and($user->notify_email)->toBeFalse();
});

it('clears the home location', function () {
    $user = User::factory()->create(['home_location_name' => 'Leicester', 'home_lat' => 52.6, 'home_lng' => -1.1, 'home_country_code' => 'GB']);

    $this->actingAs($user)->patch('/settings/alerts', [
        'home_location_name' => null, 'home_lat' => null, 'home_lng' => null, 'home_country_code' => null, 'radius_miles' => 50, 'nearby_mode' => 'country', 'notify_email' => true,
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->home_lat)->toBeNull()->and($user->fresh()->home_country_code)->toBeNull();
});

it('validates alert settings', function (array $input, string $errorField) {
    $valid = ['home_location_name' => 'Leicester', 'home_lat' => 52.6, 'home_lng' => -1.1, 'home_country_code' => 'GB', 'radius_miles' => 50, 'nearby_mode' => 'radius', 'notify_email' => true];

    $this->actingAs(User::factory()->create())
        ->patch('/settings/alerts', [...$valid, ...$input])
        ->assertSessionHasErrors($errorField);
})->with([
    'radius not offered' => [['radius_miles' => 30], 'radius_miles'],
    'latitude out of range' => [['home_lat' => 91], 'home_lat'],
    'longitude out of range' => [['home_lng' => -181], 'home_lng'],
    'name without coordinates' => [['home_lat' => null, 'home_lng' => null], 'home_lat'],
    'coordinates without name' => [['home_location_name' => null], 'home_location_name'],
    'unknown nearby mode' => [['nearby_mode' => 'planet'], 'nearby_mode'],
    'country code too long' => [['home_country_code' => 'GBR'], 'home_country_code'],
    'country code too short' => [['home_country_code' => 'G'], 'home_country_code'],
    'country code not letters' => [['home_country_code' => 'G1'], 'home_country_code'],
    'email flag not boolean' => [['notify_email' => 'maybe'], 'notify_email'],
]);

it('searches places as JSON', function () {
    Http::fake(['nominatim.openstreetmap.org/search*' => Http::response(nominatimFixture('search'))]);

    $this->actingAs(User::factory()->create())->getJson('/settings/alerts/places?q=Leicester')
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.name', 'Leicester, Leicestershire, United Kingdom')
        ->assertJsonPath('0.countryCode', 'GB')
        ->assertJsonPath('1.countryCode', 'US');
});

it('does not search places for fewer than 3 characters', function () {
    Http::fake();

    $this->actingAs(User::factory()->create())->getJson('/settings/alerts/places?q=Le')->assertOk()->assertExactJson([]);
    Http::assertNothingSent();
});

it('reports place search outages', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 503)]);

    $this->actingAs(User::factory()->create())->getJson('/settings/alerts/places?q=Leicester')
        ->assertStatus(503)->assertJsonPath('message', 'Location search is unavailable right now.');
});

it('reverse-geocodes the current location', function () {
    Http::fake(['nominatim.openstreetmap.org/reverse*' => Http::response(nominatimFixture('reverse'))]);

    $this->actingAs(User::factory()->create())->getJson('/settings/alerts/reverse?lat=52.6362&lng=-1.1331')
        ->assertOk()->assertJsonPath('name', 'Leicester, Leicestershire, United Kingdom')->assertJsonPath('countryCode', 'GB');
});

it('404s when the current location cannot be named', function () {
    Http::fake(['nominatim.openstreetmap.org/reverse*' => Http::response(['error' => 'Unable to geocode'])]);

    $this->actingAs(User::factory()->create())->getJson('/settings/alerts/reverse?lat=0&lng=0')
        ->assertNotFound()->assertJsonPath('message', "Couldn't work out where that is.");
});

it('validates reverse-geocode coordinates', function () {
    $this->actingAs(User::factory()->create())->getJson('/settings/alerts/reverse?lat=200&lng=0')->assertUnprocessable();
});

it('deletes follows when the account is deleted', function () {
    $user = User::factory()->create();
    $user->artists()->attach(Artist::factory()->create(), ['last_seen_at' => now()]);

    $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])->assertRedirect('/');

    expect(DB::table('follows')->count())->toBe(0);
});

it('keeps place search and reverse lookups on separate rate limits', function () {
    Http::fake([
        'nominatim.openstreetmap.org/search*' => Http::response(nominatimFixture('search')),
        'nominatim.openstreetmap.org/reverse*' => Http::response(nominatimFixture('reverse')),
    ]);
    $user = User::factory()->create();

    foreach (range(1, 10) as $i) {
        $this->actingAs($user)->getJson('/settings/alerts/places?q=Leicester'.$i)->assertOk();
    }

    $this->actingAs($user)->getJson('/settings/alerts/reverse?lat=52.6362&lng=-1.1331')->assertOk();
});

it('rejects place queries over 100 characters', function () {
    Http::fake();

    $this->actingAs(User::factory()->create())->getJson('/settings/alerts/places?q='.str_repeat('a', 101))->assertUnprocessable();
    Http::assertNothingSent();
});

it('returns nothing for a missing or blank place query', function () {
    Http::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/settings/alerts/places')->assertOk()->assertExactJson([]);
    $this->actingAs($user)->getJson('/settings/alerts/places?q=%20%20%20')->assertOk()->assertExactJson([]);
    Http::assertNothingSent();
});

it('reports reverse lookup outages', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 503)]);

    $this->actingAs(User::factory()->create())->getJson('/settings/alerts/reverse?lat=52.6&lng=-1.1')
        ->assertStatus(503)->assertJsonPath('message', 'Location lookup is unavailable right now.');
});

it('validates reverse-geocode longitude', function (string $query) {
    $this->actingAs(User::factory()->create())->getJson('/settings/alerts/reverse?lat=52.6'.$query)->assertUnprocessable();
})->with(['missing' => [''], 'non-numeric' => ['&lng=abc']]);

it('keeps other users and their follows when an account is deleted', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $artist = Artist::factory()->create();
    $user->artists()->attach($artist, ['last_seen_at' => now()]);
    $other->artists()->attach($artist, ['last_seen_at' => now()]);

    $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])->assertRedirect('/');

    expect(User::find($user->id))->toBeNull()
        ->and(User::find($other->id))->not->toBeNull()
        ->and(DB::table('follows')->count())->toBe(1);
});

it('keeps the existing location when a patch omits the location keys', function () {
    $user = User::factory()->create(['home_location_name' => 'Leicester', 'home_lat' => 52.6, 'home_lng' => -1.1]);

    $this->actingAs($user)->patch('/settings/alerts', ['radius_miles' => 100, 'nearby_mode' => 'radius', 'notify_email' => true])
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->home_location_name)->toBe('Leicester')->and($user->home_lat)->toBe(52.6)->and($user->radius_miles)->toBe(100);
});

it('allows country mode without a home location', function () {
    $user = User::factory()->create(['nearby_mode' => 'radius']);

    $this->actingAs($user)->patch('/settings/alerts', ['radius_miles' => 50, 'nearby_mode' => 'country', 'notify_email' => true])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->nearby_mode)->toBe('country');
});

it('keeps the existing country code when a patch omits it', function () {
    $user = User::factory()->create(['home_location_name' => 'Leicester', 'home_lat' => 52.6, 'home_lng' => -1.1, 'home_country_code' => 'GB']);

    $this->actingAs($user)->patch('/settings/alerts', ['radius_miles' => 50, 'nearby_mode' => 'country', 'notify_email' => true])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->home_country_code)->toBe('GB');
});

it('drops a stale country code when the location changes without one', function (array $patch) {
    $user = User::factory()->create(['home_location_name' => 'Leicester', 'home_lat' => 52.6, 'home_lng' => -1.1, 'home_country_code' => 'GB']);

    $this->actingAs($user)->patch('/settings/alerts', [
        'home_location_name' => 'Dublin', 'home_lat' => 53.3, 'home_lng' => -6.2,
        'radius_miles' => 50, 'nearby_mode' => 'country', 'notify_email' => true, ...$patch,
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->home_country_code)->toBeNull();
})->with(['missing' => [[]], 'null' => [['home_country_code' => null]], 'empty' => [['home_country_code' => '']]]);

it('forces the country code to null when the location is cleared', function () {
    $user = User::factory()->create(['home_location_name' => 'Leicester', 'home_lat' => 52.6, 'home_lng' => -1.1, 'home_country_code' => 'GB']);

    $this->actingAs($user)->patch('/settings/alerts', [
        'home_location_name' => null, 'home_lat' => null, 'home_lng' => null, 'home_country_code' => 'GB',
        'radius_miles' => 50, 'nearby_mode' => 'country', 'notify_email' => true,
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->home_country_code)->toBeNull();
});

it('saves only the email flag from a partial patch', function () {
    $user = User::factory()->create(['home_location_name' => 'Leicester', 'home_lat' => 52.6, 'home_lng' => -1.1, 'home_country_code' => 'GB', 'nearby_mode' => 'radius', 'radius_miles' => 100]);

    $this->actingAs($user)->patch('/settings/alerts', ['notify_email' => false])->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->notify_email)->toBeFalse()
        ->and($user->home_location_name)->toBe('Leicester')->and($user->home_country_code)->toBe('GB')
        ->and($user->nearby_mode)->toBe('radius')->and($user->radius_miles)->toBe(100);
});

it('saves only the similar artists flag from a partial patch', function () {
    $user = User::factory()->create(['notify_email' => true]);

    $this->actingAs($user)->patch('/settings/alerts', ['notify_similar' => true])->assertSessionHasNoErrors();
    expect($user->fresh()->notify_similar)->toBeTrue()->and($user->fresh()->notify_email)->toBeTrue();

    $this->actingAs($user)->patch('/settings/alerts', ['notify_similar' => 'maybe'])->assertSessionHasErrors('notify_similar');
});

it('saves only the nearby mode and radius from a partial patch', function () {
    $user = User::factory()->create(['home_location_name' => 'Leicester', 'home_lat' => 52.6, 'home_lng' => -1.1, 'home_country_code' => 'GB', 'nearby_mode' => 'country', 'notify_email' => true]);

    $this->actingAs($user)->patch('/settings/alerts', ['nearby_mode' => 'radius', 'radius_miles' => 100])->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->nearby_mode)->toBe('radius')->and($user->radius_miles)->toBe(100)
        ->and($user->home_lat)->toBe(52.6)->and($user->home_country_code)->toBe('GB')->and($user->notify_email)->toBeTrue();
});

it('saves only the location from a partial patch', function () {
    $user = User::factory()->create(['nearby_mode' => 'radius', 'radius_miles' => 25, 'notify_email' => false]);

    $this->actingAs($user)->patch('/settings/alerts', [
        'home_location_name' => 'Dublin', 'home_lat' => 53.3, 'home_lng' => -6.2, 'home_country_code' => 'ie',
    ])->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->home_location_name)->toBe('Dublin')->and($user->home_country_code)->toBe('IE')
        ->and($user->nearby_mode)->toBe('radius')->and($user->radius_miles)->toBe(25)->and($user->notify_email)->toBeFalse();
});

it('allows a radius without a nearby mode', function () {
    $user = User::factory()->create(['nearby_mode' => 'country']);

    $this->actingAs($user)->patch('/settings/alerts', ['radius_miles' => 250])->assertSessionHasNoErrors();

    expect($user->fresh()->radius_miles)->toBe(250)->and($user->fresh()->nearby_mode)->toBe('country');
});

it('rejects invalid partial values', function (array $input, string $errorField) {
    $this->actingAs(User::factory()->create())->patch('/settings/alerts', $input)->assertSessionHasErrors($errorField);
})->with([
    'radius' => [['radius_miles' => 30], 'radius_miles'],
    'mode' => [['nearby_mode' => 'planet'], 'nearby_mode'],
    'email' => [['notify_email' => 'maybe'], 'notify_email'],
    'mode null' => [['nearby_mode' => null], 'nearby_mode'],
    'email null' => [['notify_email' => null], 'notify_email'],
    'lat null alone' => [['home_lat' => null], 'home_location_name'],
    'name null alone' => [['home_location_name' => null], 'home_lat'],
    'lng alone' => [['home_lng' => 1.5], 'home_location_name'],
]);

it('redirects back by default and to the settings list on request', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from('/settings/near-me')->patch('/settings/alerts', ['notify_email' => true])->assertRedirect('/settings/near-me');
    $this->actingAs($user)->from('/settings/location')->patch('/settings/alerts', ['notify_email' => true, 'redirect_to' => 'settings'])->assertRedirect('/settings');
});

it('ignores a lone country code without a location', function () {
    $user = User::factory()->create(['home_location_name' => 'Leicester', 'home_lat' => 52.6, 'home_lng' => -1.1, 'home_country_code' => 'GB']);

    $this->actingAs($user)->patch('/settings/alerts', ['home_country_code' => 'IE'])->assertSessionHasNoErrors();

    expect($user->fresh()->home_country_code)->toBe('GB');
});
