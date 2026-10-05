<?php

use App\Models\Artist;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

it('requires login', function () {
    $this->get('/search')->assertRedirect('/login');
});

it('requires a verified email', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get('/search')->assertRedirect(route('verification.notice'));
});

it('does not search for fewer than 2 characters', function () {
    Http::fake();

    $this->actingAs(User::factory()->create())->get('/search?q=a')
        ->assertInertia(fn (Assert $page) => $page->component('Search')->where('q', 'a')->has('results', 0));

    Http::assertNothingSent();
});

it('shows results with following flags', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response(tmFixture('attractions-search'))]);
    $user = User::factory()->create();
    $user->artists()->attach(Artist::factory()->create(['ticketmaster_id' => 'K8vZ917G1V0']), ['last_seen_at' => now()]);

    $this->actingAs($user)->get('/search?q=fontaines')
        ->assertInertia(fn (Assert $page) => $page->component('Search')
            ->has('results', 2)
            ->where('results.0.id', 'K8vZ917G1V0')
            ->where('results.0.name', 'Fontaines D.C.')
            ->where('results.0.following', true)
            ->where('results.1.following', false)
            ->where('error', null));
});

it('caches repeated searches', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response(tmFixture('attractions-search'))]);
    $user = User::factory()->create();

    $this->actingAs($user)->get('/search?q=Fontaines');
    $this->actingAs($user)->get('/search?q=fontaines%20');

    Http::assertSentCount(1);
});

it('shows an error when Ticketmaster is down', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response([], 503)]);

    $this->actingAs(User::factory()->create())->get('/search?q=fontaines')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('results', 0)
            ->where('error', 'Search is unavailable right now. Please try again.'));
});
