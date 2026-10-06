<?php

use App\Models\Artist;
use App\Models\DiscoveryEvent;
use App\Models\User;
use App\Notifications\SimilarGigsRoundup;
use App\Services\DiscoverFeed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;

function roundupUser(array $attrs = []): User
{
    $user = User::factory()->create([
        'home_lat' => 52.6369, 'home_lng' => -1.1398, 'home_country_code' => 'GB',
        'nearby_mode' => 'radius', 'radius_miles' => 50, 'notify_similar' => true, ...$attrs,
    ]);
    $user->artists()->attach(Artist::factory()->create(['sub_genre_id' => 'KZazBEonSMnZfZ7vAde', 'sub_genre_name' => 'Indie']), ['last_seen_at' => now()]);

    return $user;
}

beforeEach(fn () => Notification::fake());

it('notifies only opted-in users', function () {
    $on = roundupUser();
    $off = roundupUser(['notify_similar' => false]);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Shame', 'city' => 'Leeds']);

    $this->artisan('gigradar:similar-roundup')->expectsOutput('Sent 1 roundups.')->assertSuccessful();

    Notification::assertSentTo($on, SimilarGigsRoundup::class, fn ($n) => $n->items[0]['attractionName'] === 'Shame');
    Notification::assertNotSentTo($off, SimilarGigsRoundup::class);
});

it('only includes gigs first seen in the last seven days', function () {
    $user = roundupUser();
    DiscoveryEvent::factory()->create(['attraction_name' => 'Old', 'first_seen_at' => now()->subDays(8)]);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Fresh', 'first_seen_at' => now()->subDays(2)]);

    $this->artisan('gigradar:similar-roundup')->assertSuccessful();

    Notification::assertSentTo($user, SimilarGigsRoundup::class, fn ($n) => array_column($n->items, 'attractionName') === ['Fresh']);
});

it('sends nothing when nothing is new', function () {
    roundupUser();
    DiscoveryEvent::factory()->create(['first_seen_at' => now()->subDays(10)]);

    $this->artisan('gigradar:similar-roundup')->expectsOutput('Sent 0 roundups.')->assertSuccessful();

    Notification::assertNothingSent();
});

it('flattens every group into one notification', function () {
    $user = roundupUser();
    $user->artists()->attach(Artist::factory()->create(['sub_genre_id' => 'S2', 'sub_genre_name' => 'Rock']), ['last_seen_at' => now()]);
    DiscoveryEvent::factory()->create(['classification_id' => 'KZazBEonSMnZfZ7vAde']);
    DiscoveryEvent::factory()->create(['classification_id' => 'S2']);

    $this->artisan('gigradar:similar-roundup')->assertSuccessful();

    Notification::assertSentToTimes($user, SimilarGigsRoundup::class, 1);
    Notification::assertSentTo($user, SimilarGigsRoundup::class, fn ($n) => count($n->items) === 2);
});

it('keeps going when one user fails', function () {
    $first = roundupUser();
    $second = roundupUser();
    DiscoveryEvent::factory()->create();

    $this->mock(DiscoverFeed::class, function ($mock) use ($first) {
        $mock->shouldReceive('for')->andReturnUsing(function ($user) use ($first) {
            if ($user->is($first)) {
                throw new RuntimeException('boom');
            }

            return [['id' => 'x', 'name' => 'x', 'artists' => [], 'items' => [['attractionName' => 'A', 'city' => 'B', 'localDate' => '2027-01-01', 'startsAt' => '2027-01-01T19:00:00+00:00']]]];
        });
    });

    $this->artisan('gigradar:similar-roundup')->expectsOutput('Sent 1 roundups.')->assertSuccessful();

    Notification::assertSentTo($second, SimilarGigsRoundup::class);
    Notification::assertNotSentTo($first, SimilarGigsRoundup::class);
});

it('does not repeat items after an earlier run and records the run', function () {
    $user = roundupUser();
    DiscoveryEvent::factory()->create(['attraction_name' => 'Shame', 'first_seen_at' => now()->subDays(3)]);

    $this->artisan('gigradar:similar-roundup')->assertSuccessful();
    expect($user->fresh()->similar_roundup_at)->not->toBeNull();

    $this->travel(2)->days();
    $this->artisan('gigradar:similar-roundup')->expectsOutput('Sent 0 roundups.')->assertSuccessful();

    Notification::assertSentToTimes($user, SimilarGigsRoundup::class, 1);
});

it('advances the window even when nothing was new', function () {
    $user = roundupUser();

    $this->artisan('gigradar:similar-roundup')->assertSuccessful();

    expect($user->fresh()->similar_roundup_at)->not->toBeNull();
});

it('covers a skipped week but never more than 14 days', function () {
    $user = roundupUser(['similar_roundup_at' => now()->subDays(12)]);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Gap', 'first_seen_at' => now()->subDays(10)]);
    DiscoveryEvent::factory()->create(['attraction_name' => 'TooOld', 'first_seen_at' => now()->subDays(13), 'attraction_ticketmaster_id' => 'K8old']);
    $this->artisan('gigradar:similar-roundup')->assertSuccessful();
    Notification::assertSentTo($user, SimilarGigsRoundup::class, fn ($n) => array_column($n->items, 'attractionName') === ['Gap']);

    $stale = roundupUser(['similar_roundup_at' => now()->subDays(40)]);
    DiscoveryEvent::factory()->create(['attraction_name' => 'Ancient', 'first_seen_at' => now()->subDays(20)]);
    $this->artisan('gigradar:similar-roundup')->assertSuccessful();
    Notification::assertNotSentTo($stale, SimilarGigsRoundup::class, fn ($n) => in_array('Ancient', array_column($n->items, 'attractionName')));
});

it('excludes seed rows and is not capped by the display limit', function () {
    $user = roundupUser();
    DiscoveryEvent::factory()->create(['attraction_name' => 'Seeded', 'from_seed' => true]);
    DiscoveryEvent::factory()->count(12)->create();

    $this->artisan('gigradar:similar-roundup')->assertSuccessful();

    Notification::assertSentTo($user, SimilarGigsRoundup::class, fn ($n) => count($n->items) === 12 && ! in_array('Seeded', array_column($n->items, 'attractionName')));
});

it('is scheduled Fridays at 18:00 Europe/London', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'gigradar:similar-roundup'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 18 * * 5')
        ->and($event->timezone)->toBe('Europe/London')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
