<?php

use App\Models\Artist;
use App\Models\Concert;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function leicesterHome(): array
{
    return ['home_location_name' => 'Leicester, Leicestershire, United Kingdom', 'home_lat' => 52.6369, 'home_lng' => -1.1398, 'radius_miles' => 50];
}

function followArtist(User $user, Artist $artist, array $pivot = []): void
{
    $user->artists()->attach($artist, ['last_seen_at' => now()->subDay(), ...$pivot]);
}

it('redirects guests to login', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('lists followed artists by name with their next concert and scope', function () {
    $user = User::factory()->create();
    $b = Artist::factory()->create(['name' => 'Bicep']);
    $a = Artist::factory()->create(['name' => 'Arctic Monkeys']);
    followArtist($user, $b, ['alert_scope' => 'nearby']);
    followArtist($user, $a);
    Concert::factory()->for($a)->create(['local_date' => today()->addDays(5)->toDateString(), 'city' => 'Sheffield', 'first_seen_at' => now()->subWeek()]);
    Artist::factory()->create(['name' => 'Not Followed']);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->component('MyArtists')
            ->has('artists', 2)
            ->where('artists.0.name', 'Arctic Monkeys')
            ->where('artists.0.nextConcert.city', 'Sheffield')
            ->where('artists.0.nextConcert.localDate', today()->addDays(5)->toDateString())
            ->where('artists.0.alertScope', 'everywhere')
            ->where('artists.1.name', 'Bicep')
            ->where('artists.1.nextConcert', null)
            ->where('artists.1.alertScope', 'nearby'));
});

it('marks an artist as new only for non-seed concerts first seen after the last visit', function () {
    $user = User::factory()->create();
    [$fresh, $seedOnly, $seenBefore] = Artist::factory()->count(3)->sequence(['name' => 'A'], ['name' => 'B'], ['name' => 'C'])->create();
    foreach ([$fresh, $seedOnly, $seenBefore] as $artist) {
        followArtist($user, $artist); // last_seen_at = yesterday
    }
    Concert::factory()->for($fresh)->create(['first_seen_at' => now()]);
    Concert::factory()->for($seedOnly)->create(['first_seen_at' => now(), 'from_seed' => true]);
    Concert::factory()->for($seenBefore)->create(['first_seen_at' => now()->subWeek()]);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->where('artists.0.hasNew', true)
            ->where('artists.1.hasNew', false)
            ->where('artists.2.hasNew', false));
});

it('ignores past concerts for new badges and next concert', function () {
    $user = User::factory()->create();
    $artist = Artist::factory()->create();
    followArtist($user, $artist);
    Concert::factory()->for($artist)->create(['local_date' => today()->subDay()->toDateString(), 'starts_at' => now()->subDay(), 'first_seen_at' => now()]);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('artists.0.hasNew', false)->where('artists.0.nextConcert', null));
});

it('shows no nearby section without a home location', function () {
    $user = User::factory()->create();
    followArtist($user, Concert::factory()->create()->artist);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('hasHomeLocation', false)->has('nearby', 0));
});

it('lists upcoming concerts within the radius, excluding cancelled', function () {
    $user = User::factory()->create(leicesterHome());
    $artist = Artist::factory()->create(['name' => 'Local Band']);
    followArtist($user, $artist);
    $leicester = Concert::factory()->for($artist)->create(['city' => 'Leicester', 'lat' => 52.6369, 'lng' => -1.1398, 'local_date' => today()->addDays(3)->toDateString()]);
    Concert::factory()->for($artist)->create(['city' => 'Manchester', 'lat' => 53.4808, 'lng' => -2.2426, 'local_date' => today()->addDays(4)->toDateString()]);
    Concert::factory()->for($artist)->create(['city' => 'Leicester', 'lat' => 52.6369, 'lng' => -1.1398, 'status' => 'cancelled']);
    Concert::factory()->for($artist)->create(['city' => 'Nowhere', 'lat' => null, 'lng' => null]);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('hasHomeLocation', true)
            ->where('radiusMiles', 50)
            ->has('nearby', 1)
            ->where('nearby.0.id', $leicester->id)
            ->where('nearby.0.artistName', 'Local Band')
            ->where('nearby.0.distanceMiles', 0));
});

it('limits nearby to the next 10 concerts', function () {
    $user = User::factory()->create(leicesterHome());
    $artist = Artist::factory()->create();
    followArtist($user, $artist);
    foreach (range(1, 12) as $day) {
        Concert::factory()->for($artist)->create(['local_date' => today()->addDays($day)->toDateString(), 'starts_at' => now()->addDays($day)]);
    }

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->has('nearby', 10)
            ->where('nearby.0.localDate', today()->addDay()->toDateString()));
});

it('does not mark an artist as new when its only new concert is cancelled', function () {
    $user = User::factory()->create();
    $artist = Artist::factory()->create();
    followArtist($user, $artist);
    Concert::factory()->for($artist)->create(['first_seen_at' => now(), 'from_seed' => false, 'status' => 'cancelled']);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('artists.0.hasNew', false));
});

it('marks a new non-seed concert as new when the follow has never been seen', function () {
    $user = User::factory()->create();
    $artist = Artist::factory()->create();
    followArtist($user, $artist, ['last_seen_at' => null]);
    Concert::factory()->for($artist)->create(['first_seen_at' => now(), 'from_seed' => false]);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('artists.0.hasNew', true));
});

it('orders nearby concerts across artists by date and includes artist id and venue', function () {
    $user = User::factory()->create(leicesterHome());
    $a = Artist::factory()->create(['name' => 'A', 'ticketmaster_id' => 'tm-a']);
    $b = Artist::factory()->create(['name' => 'B', 'ticketmaster_id' => 'tm-b']);
    followArtist($user, $a);
    followArtist($user, $b);
    $place = ['lat' => 52.6369, 'lng' => -1.1398];
    Concert::factory()->for($a)->create([...$place, 'venue_name' => 'Late Hall', 'local_date' => today()->addDays(9)->toDateString(), 'starts_at' => now()->addDays(9)]);
    Concert::factory()->for($b)->create([...$place, 'venue_name' => 'Mid Hall', 'local_date' => today()->addDays(5)->toDateString(), 'starts_at' => now()->addDays(5)]);
    Concert::factory()->for($a)->create([...$place, 'venue_name' => 'Early Hall', 'local_date' => today()->addDays(2)->toDateString(), 'starts_at' => now()->addDays(2)]);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->has('nearby', 3)
            ->where('nearby.0.venueName', 'Early Hall')
            ->where('nearby.0.artistTicketmasterId', 'tm-a')
            ->where('nearby.1.venueName', 'Mid Hall')
            ->where('nearby.1.artistTicketmasterId', 'tm-b')
            ->where('nearby.2.venueName', 'Late Hall'));
});
