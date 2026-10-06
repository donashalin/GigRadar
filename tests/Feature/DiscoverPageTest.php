<?php

use App\Models\Artist;
use App\Models\DiscoveryEvent;
use App\Models\DismissedArtist;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function discoverUser(array $attrs = []): User
{
    return User::factory()->create([
        'home_lat' => 52.6369, 'home_lng' => -1.1398, 'home_country_code' => 'GB',
        'nearby_mode' => 'radius', 'radius_miles' => 50, ...$attrs,
    ]);
}

it('requires auth and a verified email for the discover page', function () {
    $this->get('/discover')->assertRedirect('/login');
    $this->actingAs(User::factory()->unverified()->create())->get('/discover')->assertRedirect(route('verification.notice'));
});

it('renders discover groups with the follow and area flags', function () {
    $user = discoverUser();
    $artist = Artist::factory()->create(['sub_genre_id' => 'KZazBEonSMnZfZ7vAde', 'sub_genre_name' => 'Indie']);
    $user->artists()->attach($artist, ['last_seen_at' => now()]);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Shame']);

    $this->actingAs($user)->get('/discover')
        ->assertInertia(fn (Assert $page) => $page->component('Discover')
            ->where('hasFollows', true)
            ->where('hasArea', true)
            ->has('groups', 1)
            ->where('groups.0.items.0.attractionName', 'Shame'));
});

it('flags no follows and no area', function () {
    $this->actingAs(discoverUser(['home_lat' => null, 'home_lng' => null]))->get('/discover')
        ->assertInertia(fn (Assert $page) => $page->where('hasFollows', false)->where('hasArea', false)->where('groups', []));
});

it('hides an artist for the current user only and is idempotent', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $payload = ['attraction_ticketmaster_id' => 'K8abc123', 'attraction_name' => 'Shame'];

    $this->actingAs($user)->from('/discover')->post('/dismissed-artists', $payload)->assertRedirect('/discover');
    $this->actingAs($user)->post('/dismissed-artists', $payload)->assertRedirect();

    expect($user->dismissedArtists()->count())->toBe(1);
    expect($other->dismissedArtists()->count())->toBe(0);
});

it('validates dismissals', function (array $payload) {
    $this->actingAs(User::factory()->create())->post('/dismissed-artists', $payload)->assertSessionHasErrors();
    expect(DismissedArtist::count())->toBe(0);
})->with([
    'missing id' => [['attraction_name' => 'X']],
    'non-alphanumeric id' => [['attraction_ticketmaster_id' => 'a/b', 'attraction_name' => 'X']],
    'long id' => [['attraction_ticketmaster_id' => str_repeat('a', 65), 'attraction_name' => 'X']],
    'missing name' => [['attraction_ticketmaster_id' => 'abc']],
    'long name' => [['attraction_ticketmaster_id' => 'abc', 'attraction_name' => str_repeat('a', 256)]],
]);

it('un-hides only the current users row', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    DismissedArtist::create(['user_id' => $user->id, 'attraction_ticketmaster_id' => 'K8abc', 'attraction_name' => 'Shame']);
    DismissedArtist::create(['user_id' => $other->id, 'attraction_ticketmaster_id' => 'K8abc', 'attraction_name' => 'Shame']);

    $this->actingAs($user)->from('/discover')->delete('/dismissed-artists/K8abc')->assertRedirect('/discover');

    expect($user->dismissedArtists()->count())->toBe(0);
    expect($other->dismissedArtists()->count())->toBe(1);
});

it('throttles dismissals at 60 per minute', function () {
    $user = User::factory()->create();
    foreach (range(1, 60) as $i) {
        $this->actingAs($user)->post('/dismissed-artists', ['attraction_ticketmaster_id' => "K8$i", 'attraction_name' => 'X'])->assertRedirect();
    }
    $this->actingAs($user)->post('/dismissed-artists', ['attraction_ticketmaster_id' => 'K8over', 'attraction_name' => 'X'])->assertStatus(429);
});

it('lists hidden artists by name for the current user', function () {
    $user = User::factory()->create();
    DismissedArtist::create(['user_id' => $user->id, 'attraction_ticketmaster_id' => 'K8b', 'attraction_name' => 'Bicep']);
    DismissedArtist::create(['user_id' => $user->id, 'attraction_ticketmaster_id' => 'K8a', 'attraction_name' => 'Arab Strap']);
    DismissedArtist::create(['user_id' => User::factory()->create()->id, 'attraction_ticketmaster_id' => 'K8c', 'attraction_name' => 'Other']);

    $this->actingAs($user)->get('/settings/hidden-artists')
        ->assertInertia(fn (Assert $page) => $page->component('settings/HiddenArtists')
            ->has('artists', 2)
            ->where('artists.0', ['attractionId' => 'K8a', 'name' => 'Arab Strap'])
            ->where('artists.1.name', 'Bicep'));
});

it('shows the hidden count on the settings index', function () {
    $user = User::factory()->create();
    DismissedArtist::create(['user_id' => $user->id, 'attraction_ticketmaster_id' => 'K8a', 'attraction_name' => 'A']);

    $this->actingAs($user)->get('/settings')->assertInertia(fn (Assert $page) => $page->where('hiddenCount', 1));
});

it('redirects guests away from dismissal and hidden-artist routes', function () {
    $this->post('/dismissed-artists', ['attraction_ticketmaster_id' => 'abc', 'attraction_name' => 'X'])->assertRedirect('/login');
    $this->delete('/dismissed-artists/abc')->assertRedirect('/login');
    $this->get('/settings/hidden-artists')->assertRedirect('/login');
});

it('404s when un-hiding with a non-alphanumeric id', function () {
    $this->actingAs(User::factory()->create())->delete('/dismissed-artists/a.b')->assertNotFound();
});
