<?php

use App\Models\Artist;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

it('sends /settings to the alert settings page', function () {
    $this->actingAs(User::factory()->create())->get('/settings')->assertRedirect('/settings/alerts');
});

it('requires a verified user', function () {
    $this->get('/settings/alerts')->assertRedirect('/login');
    $this->actingAs(User::factory()->unverified()->create())
        ->get('/settings/alerts')->assertRedirect(route('verification.notice'));
});

it('shows the current alert settings', function () {
    $user = User::factory()->create(['home_location_name' => 'Leicester, Leicestershire, United Kingdom', 'home_lat' => 52.6362, 'home_lng' => -1.1331, 'radius_miles' => 100, 'notify_email' => false]);

    $this->actingAs($user)->get('/settings/alerts')
        ->assertInertia(fn (Assert $page) => $page->component('settings/Alerts')
            ->where('settings.homeLocationName', 'Leicester, Leicestershire, United Kingdom')
            ->where('settings.homeLat', 52.6362)
            ->where('settings.homeLng', -1.1331)
            ->where('settings.radiusMiles', 100)
            ->where('settings.notifyEmail', false)
            ->where('radiusOptions', [25, 50, 100, 250]));
});

it('saves alert settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/settings/alerts', [
        'home_location_name' => 'Leicester, Leicestershire, United Kingdom',
        'home_lat' => 52.6362,
        'home_lng' => -1.1331,
        'radius_miles' => 25,
        'notify_email' => false,
    ])->assertRedirect('/settings/alerts')->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->home_location_name)->toBe('Leicester, Leicestershire, United Kingdom')
        ->and($user->home_lat)->toBe(52.6362)
        ->and($user->radius_miles)->toBe(25)
        ->and($user->notify_email)->toBeFalse();
});

it('clears the home location', function () {
    $user = User::factory()->create(['home_location_name' => 'Leicester', 'home_lat' => 52.6, 'home_lng' => -1.1]);

    $this->actingAs($user)->patch('/settings/alerts', [
        'home_location_name' => null, 'home_lat' => null, 'home_lng' => null, 'radius_miles' => 50, 'notify_email' => true,
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->home_lat)->toBeNull();
});

it('validates alert settings', function (array $input, string $errorField) {
    $valid = ['home_location_name' => 'Leicester', 'home_lat' => 52.6, 'home_lng' => -1.1, 'radius_miles' => 50, 'notify_email' => true];

    $this->actingAs(User::factory()->create())
        ->patch('/settings/alerts', [...$valid, ...$input])
        ->assertSessionHasErrors($errorField);
})->with([
    'radius not offered' => [['radius_miles' => 30], 'radius_miles'],
    'latitude out of range' => [['home_lat' => 91], 'home_lat'],
    'longitude out of range' => [['home_lng' => -181], 'home_lng'],
    'name without coordinates' => [['home_lat' => null, 'home_lng' => null], 'home_lat'],
    'coordinates without name' => [['home_location_name' => null], 'home_location_name'],
    'email flag missing' => [['notify_email' => null], 'notify_email'],
]);

it('searches places as JSON', function () {
    Http::fake(['nominatim.openstreetmap.org/search*' => Http::response(nominatimFixture('search'))]);

    $this->actingAs(User::factory()->create())->getJson('/settings/alerts/places?q=Leicester')
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.name', 'Leicester, Leicestershire, United Kingdom');
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
        ->assertOk()->assertJsonPath('name', 'Leicester, Leicestershire, United Kingdom');
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

    expect(Illuminate\Support\Facades\DB::table('follows')->count())->toBe(0);
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
        ->and(Illuminate\Support\Facades\DB::table('follows')->count())->toBe(1);
});

it('keeps the existing location when a patch omits the location keys', function () {
    $user = User::factory()->create(['home_location_name' => 'Leicester', 'home_lat' => 52.6, 'home_lng' => -1.1]);

    $this->actingAs($user)->patch('/settings/alerts', ['radius_miles' => 100, 'notify_email' => true])
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->home_location_name)->toBe('Leicester')->and($user->home_lat)->toBe(52.6)->and($user->radius_miles)->toBe(100);
});
