<?php

use App\Models\Artist;
use App\Models\DiscoveryEvent;
use App\Models\DismissedArtist;
use App\Models\User;
use App\Services\DiscoverFeed;

const DF_INDIE = 'KZazBEonSMnZfZ7vAde';
const DF_ROCK = 'KZazBEonSMnZfZ7v6F1';

function dfUser(array $attrs = []): User
{
    return User::factory()->create([
        'home_lat' => 52.6369, 'home_lng' => -1.1398, 'home_country_code' => 'GB',
        'nearby_mode' => 'radius', 'radius_miles' => 50, ...$attrs,
    ]);
}

function dfFollow(User $user, string $name = 'Followed', string $subId = DF_INDIE, string $subName = 'Indie'): Artist
{
    $artist = Artist::factory()->create(['name' => $name, 'sub_genre_id' => $subId, 'sub_genre_name' => $subName]);
    $user->artists()->attach($artist, ['last_seen_at' => now()]);

    return $artist;
}

function dfFeed(User $user, $since = null): array
{
    return app(DiscoverFeed::class)->for($user, $since);
}

it('returns nothing without follows or a configured area', function () {
    DiscoveryEvent::factory()->create();
    expect(dfFeed(dfUser()))->toBe([]);

    $user = dfUser(['home_lat' => null, 'home_lng' => null]);
    dfFollow($user);
    expect(dfFeed($user))->toBe([]);
});

it('builds a group with item fields for nearby gigs', function () {
    $user = dfUser();
    dfFollow($user, 'Bicep');
    $event = DiscoveryEvent::factory()->create(['attraction_name' => 'Shame', 'attraction_ticketmaster_id' => 'K8shame', 'attraction_image_url' => 'https://img/x.jpg']);

    $groups = dfFeed($user);

    expect($groups)->toHaveCount(1)
        ->and($groups[0])->toMatchArray(['id' => DF_INDIE, 'name' => 'Indie', 'artists' => ['Bicep']])
        ->and($groups[0]['items'][0])->toMatchArray([
            'eventId' => $event->id, 'attractionId' => 'K8shame', 'attractionName' => 'Shame',
            'imageUrl' => 'https://img/x.jpg', 'city' => 'Leicester', 'venueName' => 'O2 Academy',
            'distanceMiles' => 0, 'ticketUrl' => $event->ticket_url,
        ]);
});

it('filters by radius', function () {
    $user = dfUser();
    dfFollow($user);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Near']);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Far', 'lat' => 55.9533, 'lng' => -3.1883, 'city' => 'Edinburgh']);

    expect(array_column(dfFeed($user)[0]['items'], 'attractionName'))->toBe(['Near']);
});

it('filters by country', function () {
    $user = dfUser(['nearby_mode' => 'country']);
    dfFollow($user);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Home', 'lat' => null, 'lng' => null, 'country' => 'GB']);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Abroad', 'country' => 'DE']);

    $items = dfFeed($user)[0]['items'];
    expect(array_column($items, 'attractionName'))->toBe(['Home'])
        ->and($items[0]['distanceMiles'])->toBeNull();
});

it('excludes followed, dismissed and cancelled', function () {
    $user = dfUser();
    $followed = dfFollow($user);
    DismissedArtist::create(['user_id' => $user->id, 'attraction_ticketmaster_id' => 'K8hidden', 'attraction_name' => 'Hidden']);
    DiscoveryEvent::factory()->create(['attraction_ticketmaster_id' => $followed->ticketmaster_id]);
    DiscoveryEvent::factory()->create(['attraction_ticketmaster_id' => 'K8hidden']);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Cancelled', 'status' => 'cancelled']);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Visible']);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Past', 'local_date' => today()->subDay()->toDateString(), 'starts_at' => now()->subDay()]);

    expect(array_column(dfFeed($user)[0]['items'], 'attractionName'))->toBe(['Visible']);
});

it('only dismisses for the user who dismissed', function () {
    $user = dfUser();
    dfFollow($user);
    DismissedArtist::create(['user_id' => User::factory()->create()->id, 'attraction_ticketmaster_id' => 'K8x', 'attraction_name' => 'X']);
    DiscoveryEvent::factory()->create(['attraction_ticketmaster_id' => 'K8x']);

    expect(dfFeed($user)[0]['items'])->toHaveCount(1);
});

it('shows an attraction once, in its highest-weight group, with its soonest non-cancelled gig', function () {
    $user = dfUser();
    dfFollow($user, 'A', DF_INDIE, 'Indie');
    dfFollow($user, 'B', DF_INDIE, 'Indie');
    dfFollow($user, 'C', DF_ROCK, 'Rock');
    DiscoveryEvent::factory()->create(['classification_id' => DF_ROCK, 'attraction_ticketmaster_id' => 'K8dup', 'attraction_name' => 'Dup']);
    DiscoveryEvent::factory()->create(['classification_id' => DF_INDIE, 'attraction_ticketmaster_id' => 'K8dup', 'attraction_name' => 'Dup', 'local_date' => today()->addDays(20)->toDateString(), 'starts_at' => now()->addDays(20), 'city' => 'Later']);
    DiscoveryEvent::factory()->create(['classification_id' => DF_INDIE, 'attraction_ticketmaster_id' => 'K8dup', 'attraction_name' => 'Dup', 'local_date' => today()->addDays(10)->toDateString(), 'starts_at' => now()->addDays(10), 'city' => 'Sooner']);
    DiscoveryEvent::factory()->create(['classification_id' => DF_INDIE, 'attraction_ticketmaster_id' => 'K8dup', 'attraction_name' => 'Dup', 'local_date' => today()->addDays(5)->toDateString(), 'starts_at' => now()->addDays(5), 'status' => 'cancelled']);
    DiscoveryEvent::factory()->create(['classification_id' => DF_ROCK, 'attraction_name' => 'Rocker']);

    $groups = dfFeed($user);

    expect(array_column($groups, 'id'))->toBe([DF_INDIE, DF_ROCK])
        ->and($groups[0]['artists'])->toBe(['A', 'B'])
        ->and($groups[0]['items'])->toHaveCount(1)
        ->and($groups[0]['items'][0]['city'])->toBe('Sooner')
        ->and(array_column($groups[1]['items'], 'attractionName'))->toBe(['Rocker']);
});

it('omits empty groups', function () {
    $user = dfUser();
    dfFollow($user, 'A', DF_INDIE, 'Indie');
    dfFollow($user, 'B', DF_ROCK, 'Rock');
    DiscoveryEvent::factory()->create(['classification_id' => DF_ROCK]);

    expect(array_column(dfFeed($user), 'id'))->toBe([DF_ROCK]);
});

it('limits each group to 10 soonest items in date order', function () {
    $user = dfUser();
    dfFollow($user);
    foreach (range(15, 1) as $days) {
        DiscoveryEvent::factory()->create(['attraction_name' => "Day $days", 'local_date' => today()->addDays($days)->toDateString(), 'starts_at' => now()->addDays($days)]);
    }

    $names = array_column(dfFeed($user)[0]['items'], 'attractionName');

    expect($names)->toHaveCount(10)->and($names[0])->toBe('Day 1')->and($names[9])->toBe('Day 10');
});

it('only considers events first seen since the cutoff', function () {
    $user = dfUser();
    dfFollow($user);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Old', 'first_seen_at' => now()->subDays(10)]);
    DiscoveryEvent::factory()->create(['attraction_name' => 'New', 'first_seen_at' => now()->subDays(2)]);

    expect(array_column(dfFeed($user, now()->subWeek())[0]['items'], 'attractionName'))->toBe(['New'])
        ->and(dfFeed($user)[0]['items'])->toHaveCount(2);
});

it('only marks an attraction as placed when it makes the cap', function () {
    $user = dfUser();
    dfFollow($user, 'A', DF_INDIE, 'Indie');
    dfFollow($user, 'B', DF_INDIE, 'Indie');
    dfFollow($user, 'C', DF_ROCK, 'Rock');
    foreach (range(1, 11) as $i) {
        DiscoveryEvent::factory()->create(['classification_id' => DF_INDIE, 'attraction_ticketmaster_id' => "K8a$i", 'attraction_name' => "Act $i", 'local_date' => today()->addDays($i)->toDateString(), 'starts_at' => now()->addDays($i)]);
    }
    DiscoveryEvent::factory()->create(['classification_id' => DF_ROCK, 'attraction_ticketmaster_id' => 'K8a11', 'attraction_name' => 'Act 11']);

    $groups = dfFeed($user);

    expect($groups[0]['items'])->toHaveCount(10)
        ->and(array_column($groups[1]['items'], 'attractionName'))->toBe(['Act 11']);
});

it('does not show foreign events without coordinates to a radius-mode user', function () {
    $user = dfUser();
    dfFollow($user);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Irish', 'country' => 'IE', 'lat' => null, 'lng' => null]);
    DiscoveryEvent::factory()->create(['attraction_name' => 'British']);

    expect(array_column(dfFeed($user)[0]['items'], 'attractionName'))->toBe(['British']);
});

it('can exclude seed rows', function () {
    $user = dfUser();
    dfFollow($user);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Seeded', 'from_seed' => true]);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Fresh', 'from_seed' => false]);

    $names = fn (array $g) => collect($g)->flatMap(fn ($x) => array_column($x['items'], 'attractionName'))->sort()->values()->all();

    expect($names(dfFeed($user)))->toBe(['Fresh', 'Seeded'])
        ->and($names(app(DiscoverFeed::class)->for($user, null, 10, true)))->toBe(['Fresh']);
});

it('honours a custom group limit', function () {
    $user = dfUser();
    dfFollow($user);
    DiscoveryEvent::factory()->count(12)->create();

    expect(dfFeed($user)[0]['items'])->toHaveCount(10)
        ->and(app(DiscoverFeed::class)->for($user, null, 200)[0]['items'])->toHaveCount(12);
});

it('excludes rows with an exclude_reason', function () {
    $user = dfUser();
    dfFollow($user);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Real Band']);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Tribute Act', 'exclude_reason' => 'tribute_subtype']);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Fan Night', 'exclude_reason' => 'not_an_artist']);

    expect(array_column(dfFeed($user)[0]['items'], 'attractionName'))->toBe(['Real Band']);
});
