<?php

use App\Models\Artist;
use App\Models\Concert;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

it('resolves and seeds an unknown artist on first view', function () {
    Http::fake([
        'app.ticketmaster.com/discovery/v2/attractions/K8vZ917G1V0.json*' => Http::response(tmFixture('attraction')),
        'app.ticketmaster.com/discovery/v2/events.json*' => Http::response(tmFixture('events')),
    ]);

    $this->actingAs(User::factory()->create())->get('/artists/K8vZ917G1V0')
        ->assertInertia(fn (Assert $page) => $page->component('artists/Show')
            ->where('artist.name', 'Fontaines D.C.')
            ->has('concerts', 2)
            ->where('concerts.0.city', 'Manchester')
            ->where('concerts.0.localDate', '2027-03-14')
            ->where('following', false)
            ->where('alertScope', null)
            ->where('refreshFailed', false));

    expect(Artist::firstWhere('ticketmaster_id', 'K8vZ917G1V0')->seeded)->toBeTrue();
});

it('does not call Ticketmaster when the artist was checked recently', function () {
    Http::fake();
    $concert = Concert::factory()->create();

    $this->actingAs(User::factory()->create())->get("/artists/{$concert->artist->ticketmaster_id}")
        ->assertInertia(fn (Assert $page) => $page->has('concerts', 1));

    Http::assertNothingSent();
});

it('hides past concerts', function () {
    Http::fake();
    $artist = Artist::factory()->create();
    Concert::factory()->for($artist)->create(['starts_at' => now()->subDay()]);
    Concert::factory()->for($artist)->create(['starts_at' => now()->addDay()]);

    $this->actingAs(User::factory()->create())->get("/artists/{$artist->ticketmaster_id}")
        ->assertInertia(fn (Assert $page) => $page->has('concerts', 1));
});

it('shows saved concerts with a notice when a refresh fails', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response([], 500)]);
    $artist = Artist::factory()->create(['last_checked_at' => now()->subDay()]);
    Concert::factory()->for($artist)->create();

    $this->actingAs(User::factory()->create())->get("/artists/{$artist->ticketmaster_id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('concerts', 1)->where('refreshFailed', true));
});

it('returns 404 for an artist Ticketmaster does not know', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response([], 404)]);

    $this->actingAs(User::factory()->create())->get('/artists/UNKNOWN1')->assertNotFound();
});

it('returns 503 when an unknown artist cannot be looked up', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response([], 500)]);

    $this->actingAs(User::factory()->create())->get('/artists/UNKNOWN1')->assertStatus(503);
});

it('marks the artist as seen for a follower', function () {
    Http::fake();
    $user = User::factory()->create();
    $artist = Artist::factory()->create();
    $user->artists()->attach($artist, ['alert_scope' => 'nearby', 'last_seen_at' => now()->subWeek()]);

    $this->actingAs($user)->get("/artists/{$artist->ticketmaster_id}")
        ->assertInertia(fn (Assert $page) => $page->where('following', true)->where('alertScope', 'nearby'));

    expect($user->artists()->first()->pivot->last_seen_at->gt(now()->subMinute()))->toBeTrue();
});
