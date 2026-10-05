<?php

use App\Models\Artist;
use App\Models\User;
use Illuminate\Support\Facades\Http;

it('follows an artist and seeds its concerts without alerting', function () {
    Http::fake([
        'app.ticketmaster.com/discovery/v2/attractions/K8vZ917G1V0.json*' => Http::response(tmFixture('attraction')),
        'app.ticketmaster.com/discovery/v2/events.json*' => Http::response(tmFixture('events')),
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)->from('/search?q=fontaines')
        ->post('/artists/K8vZ917G1V0/follow')
        ->assertRedirect('/search?q=fontaines');

    $artist = $user->artists()->first();
    expect($artist->ticketmaster_id)->toBe('K8vZ917G1V0')
        ->and($artist->pivot->alert_scope)->toBe('everywhere')
        ->and($artist->seeded)->toBeTrue()
        ->and($artist->concerts()->count())->toBe(2);
});

it('does not duplicate a follow or reset its scope', function () {
    Http::fake();
    $user = User::factory()->create();
    $artist = Artist::factory()->create();
    $user->artists()->attach($artist, ['alert_scope' => 'nearby', 'last_seen_at' => now()]);

    $this->actingAs($user)->post("/artists/{$artist->ticketmaster_id}/follow")->assertRedirect();

    expect($user->artists()->count())->toBe(1)
        ->and($user->artists()->first()->pivot->alert_scope)->toBe('nearby');
});

it('still follows when seeding fails', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response([], 500)]);
    $user = User::factory()->create();
    $artist = Artist::factory()->unseeded()->create();

    $this->actingAs($user)->post("/artists/{$artist->ticketmaster_id}/follow")->assertRedirect();

    expect($user->artists()->count())->toBe(1)->and($artist->fresh()->seeded)->toBeFalse();
});

it('changes the alert scope', function () {
    $user = User::factory()->create();
    $artist = Artist::factory()->create();
    $user->artists()->attach($artist, ['last_seen_at' => now()]);

    $this->actingAs($user)->patch("/artists/{$artist->ticketmaster_id}/follow", ['alert_scope' => 'nearby'])->assertRedirect();

    expect($user->artists()->first()->pivot->alert_scope)->toBe('nearby');
});

it('rejects an invalid alert scope', function () {
    $user = User::factory()->create();
    $artist = Artist::factory()->create();
    $user->artists()->attach($artist, ['last_seen_at' => now()]);

    $this->actingAs($user)->patch("/artists/{$artist->ticketmaster_id}/follow", ['alert_scope' => 'mars'])
        ->assertSessionHasErrors('alert_scope');
});

it('404s when changing scope for an artist not followed', function () {
    $artist = Artist::factory()->create();

    $this->actingAs(User::factory()->create())
        ->patch("/artists/{$artist->ticketmaster_id}/follow", ['alert_scope' => 'nearby'])
        ->assertNotFound();
});

it('unfollows an artist', function () {
    $user = User::factory()->create();
    $artist = Artist::factory()->create();
    $user->artists()->attach($artist, ['last_seen_at' => now()]);

    $this->actingAs($user)->delete("/artists/{$artist->ticketmaster_id}/follow")->assertRedirect();

    expect($user->artists()->count())->toBe(0);
});

it('requires a verified user to follow', function () {
    $this->post('/artists/K8vZ917G1V0/follow')->assertRedirect('/login');
});

it('marks concerts stored while following as already alerted', function () {
    Http::fake([
        'app.ticketmaster.com/discovery/v2/events.json*' => Http::response(tmFixture('events')),
    ]);
    $user = User::factory()->create();
    $artist = Artist::factory()->unseeded()->create(['ticketmaster_id' => 'K8vZ917G1V0']);

    $this->actingAs($user)->post('/artists/K8vZ917G1V0/follow')->assertRedirect();

    $concerts = $artist->concerts()->get();
    expect($concerts)->not->toBeEmpty()
        ->and($concerts->whereNull('alerted_at'))->toBeEmpty();
});

it('flashes an error and does not follow when the artist is unknown', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response([], 404)]);
    $user = User::factory()->create();

    $this->actingAs($user)->from('/search')->post('/artists/UNKNOWN1/follow')
        ->assertRedirect('/search')
        ->assertSessionHas('error', "We couldn't find that artist.");

    expect($user->artists()->count())->toBe(0);
});

it('flashes an error and does not follow when Ticketmaster fails', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response([], 500)]);
    $user = User::factory()->create();

    $this->actingAs($user)->from('/search')->post('/artists/UNKNOWN1/follow')
        ->assertRedirect('/search')
        ->assertSessionHas('error', "Couldn't follow right now. Please try again.");

    expect($user->artists()->count())->toBe(0);
});

it('redirects unverified users to the verification notice when following', function () {
    $this->actingAs(User::factory()->unverified()->create())->post('/artists/K8vZ917G1V0/follow')
        ->assertRedirect(route('verification.notice'));
});

it('404s when unfollowing an unknown artist', function () {
    $this->actingAs(User::factory()->create())->delete('/artists/UNKNOWN1/follow')->assertNotFound();
});

it('quietly redirects when unfollowing an artist that is not followed', function () {
    $artist = Artist::factory()->create();

    $this->actingAs(User::factory()->create())->delete("/artists/{$artist->ticketmaster_id}/follow")
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('error');
});

it('leaves another user\'s follow untouched when changing scope or unfollowing', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $artist = Artist::factory()->create();
    $a->artists()->attach($artist, ['last_seen_at' => now()]);
    $b->artists()->attach($artist, ['alert_scope' => 'everywhere', 'last_seen_at' => now()]);

    $this->actingAs($a)->patch("/artists/{$artist->ticketmaster_id}/follow", ['alert_scope' => 'nearby'])->assertRedirect();
    expect($b->artists()->first()->pivot->alert_scope)->toBe('everywhere');

    $this->actingAs($a)->delete("/artists/{$artist->ticketmaster_id}/follow")->assertRedirect();
    expect($a->artists()->count())->toBe(0)->and($b->artists()->count())->toBe(1);
});

it('shares the flash error with Inertia pages', function () {
    $this->actingAs(User::factory()->create())->withSession(['error' => 'Boom'])->get('/search')
        ->assertInertia(fn ($page) => $page->where('flash.error', 'Boom'));
});
