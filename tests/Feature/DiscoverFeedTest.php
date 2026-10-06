<?php

use App\Models\Artist;
use App\Models\DiscoveryEvent;
use App\Models\DismissedArtist;
use App\Models\User;
use App\Services\DiscoverFeed;

const INDIE = 'KZazBEonSMnZfZ7vAde';
const ROCK = 'KZazBEonSMnZfZ7v6F1';

function feedUser(array $attrs = []): User
{
    return User::factory()->create([
        'home_lat' => 52.6369, 'home_lng' => -1.1398, 'home_country_code' => 'GB',
        'nearby_mode' => 'radius', 'radius_miles' => 50, ...$attrs,
    ]);
}

function followVibe(User $user, string $name = 'Followed', string $subId = INDIE, string $subName = 'Indie'): Artist
{
    $artist = Artist::factory()->create(['name' => $name, 'sub_genre_id' => $subId, 'sub_genre_name' => $subName]);
    $user->artists()->attach($artist, ['last_seen_at' => now()]);

    return $artist;
}

function feed(User $user, $since = null): array
{
    return app(DiscoverFeed::class)->for($user, $since);
}

it('returns nothing without follows or a configured area', function () {
    DiscoveryEvent::factory()->create();
    expect(feed(feedUser()))->toBe([]);

    $user = feedUser(['home_lat' => null, 'home_lng' => null]);
    followVibe($user);
    expect(feed($user))->toBe([]);
});

it('builds a group with item fields for nearby gigs', function () {
    $user = feedUser();
    followVibe($user, 'Bicep');
    $event = DiscoveryEvent::factory()->create(['attraction_name' => 'Shame', 'attraction_ticketmaster_id' => 'K8shame', 'attraction_image_url' => 'https://img/x.jpg']);

    $groups = feed($user);

    expect($groups)->toHaveCount(1)
        ->and($groups[0])->toMatchArray(['id' => INDIE, 'name' => 'Indie', 'artists' => ['Bicep']])
        ->and($groups[0]['items'][0])->toMatchArray([
            'eventId' => $event->id, 'attractionId' => 'K8shame', 'attractionName' => 'Shame',
            'imageUrl' => 'https://img/x.jpg', 'city' => 'Leicester', 'venueName' => 'O2 Academy',
            'distanceMiles' => 0, 'ticketUrl' => $event->ticket_url,
        ]);
});

it('filters by radius', function () {
    $user = feedUser();
    followVibe($user);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Near']);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Far', 'lat' => 55.9533, 'lng' => -3.1883, 'city' => 'Edinburgh']);

    expect(array_column(feed($user)[0]['items'], 'attractionName'))->toBe(['Near']);
});

it('filters by country', function () {
    $user = feedUser(['nearby_mode' => 'country']);
    followVibe($user);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Home', 'lat' => null, 'lng' => null, 'country' => 'GB']);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Abroad', 'country' => 'DE']);

    $items = feed($user)[0]['items'];
    expect(array_column($items, 'attractionName'))->toBe(['Home'])
        ->and($items[0]['distanceMiles'])->toBeNull();
});

it('excludes followed, dismissed and cancelled', function () {
    $user = feedUser();
    $followed = followVibe($user);
    DismissedArtist::create(['user_id' => $user->id, 'attraction_ticketmaster_id' => 'K8hidden', 'attraction_name' => 'Hidden']);
    DiscoveryEvent::factory()->create(['attraction_ticketmaster_id' => $followed->ticketmaster_id]);
    DiscoveryEvent::factory()->create(['attraction_ticketmaster_id' => 'K8hidden']);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Cancelled', 'status' => 'cancelled']);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Visible']);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Past', 'local_date' => today()->subDay()->toDateString(), 'starts_at' => now()->subDay()]);

    expect(array_column(feed($user)[0]['items'], 'attractionName'))->toBe(['Visible']);
});

it('only dismisses for the user who dismissed', function () {
    $user = feedUser();
    followVibe($user);
    DismissedArtist::create(['user_id' => User::factory()->create()->id, 'attraction_ticketmaster_id' => 'K8x', 'attraction_name' => 'X']);
    DiscoveryEvent::factory()->create(['attraction_ticketmaster_id' => 'K8x']);

    expect(feed($user)[0]['items'])->toHaveCount(1);
});

it('shows an attraction once, in its highest-weight group, with its soonest non-cancelled gig', function () {
    $user = feedUser();
    followVibe($user, 'A', INDIE, 'Indie');
    followVibe($user, 'B', INDIE, 'Indie');
    followVibe($user, 'C', ROCK, 'Rock');
    DiscoveryEvent::factory()->create(['classification_id' => ROCK, 'attraction_ticketmaster_id' => 'K8dup', 'attraction_name' => 'Dup']);
    DiscoveryEvent::factory()->create(['classification_id' => INDIE, 'attraction_ticketmaster_id' => 'K8dup', 'attraction_name' => 'Dup', 'local_date' => today()->addDays(20)->toDateString(), 'city' => 'Later']);
    DiscoveryEvent::factory()->create(['classification_id' => INDIE, 'attraction_ticketmaster_id' => 'K8dup', 'attraction_name' => 'Dup', 'local_date' => today()->addDays(10)->toDateString(), 'city' => 'Sooner']);
    DiscoveryEvent::factory()->create(['classification_id' => INDIE, 'attraction_ticketmaster_id' => 'K8dup', 'attraction_name' => 'Dup', 'local_date' => today()->addDays(5)->toDateString(), 'status' => 'cancelled']);
    DiscoveryEvent::factory()->create(['classification_id' => ROCK, 'attraction_name' => 'Rocker']);

    $groups = feed($user);

    expect(array_column($groups, 'id'))->toBe([INDIE, ROCK])
        ->and($groups[0]['artists'])->toBe(['A', 'B'])
        ->and($groups[0]['items'])->toHaveCount(1)
        ->and($groups[0]['items'][0]['city'])->toBe('Sooner')
        ->and(array_column($groups[1]['items'], 'attractionName'))->toBe(['Rocker']);
});

it('omits empty groups', function () {
    $user = feedUser();
    followVibe($user, 'A', INDIE, 'Indie');
    followVibe($user, 'B', ROCK, 'Rock');
    DiscoveryEvent::factory()->create(['classification_id' => ROCK]);

    expect(array_column(feed($user), 'id'))->toBe([ROCK]);
});

it('limits each group to 10 soonest items in date order', function () {
    $user = feedUser();
    followVibe($user);
    foreach (range(15, 1) as $days) {
        DiscoveryEvent::factory()->create(['attraction_name' => "Day $days", 'local_date' => today()->addDays($days)->toDateString(), 'starts_at' => now()->addDays($days)]);
    }

    $names = array_column(feed($user)[0]['items'], 'attractionName');

    expect($names)->toHaveCount(10)->and($names[0])->toBe('Day 1')->and($names[9])->toBe('Day 10');
});

it('only considers events first seen since the cutoff', function () {
    $user = feedUser();
    followVibe($user);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Old', 'first_seen_at' => now()->subDays(10)]);
    DiscoveryEvent::factory()->create(['attraction_name' => 'New', 'first_seen_at' => now()->subDays(2)]);

    expect(array_column(feed($user, now()->subWeek())[0]['items'], 'attractionName'))->toBe(['New'])
        ->and(feed($user)[0]['items'])->toHaveCount(2);
});
