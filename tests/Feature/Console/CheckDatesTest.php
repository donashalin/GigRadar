<?php

use App\Models\Artist;
use App\Models\Concert;
use App\Models\User;
use App\Notifications\NewTourDates;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/** Fake Ticketmaster: events per attractionId (fixture by default), 500 for ids in $failing. */
function fakeTicketmaster(array $failing = [], ?array $payload = null): void
{
    Http::fake(['app.ticketmaster.com/discovery/v2/events.json*' => function (Request $request) use ($failing, $payload) {
        return in_array(tmQuery($request)['attractionId'] ?? null, $failing, true)
            ? Http::response([], 500)
            : Http::response($payload ?? tmFixture('events'));
    }]);
}

function followedArtist(string $id, string $scope = 'everywhere', array $user = [], bool $seeded = true): array
{
    $artist = Artist::factory()->create(['ticketmaster_id' => $id, 'seeded' => $seeded]);
    $follower = User::factory()->create($user);
    $artist->followers()->attach($follower, ['alert_scope' => $scope]);

    return [$artist, $follower];
}

beforeEach(fn () => Notification::fake());

it('alerts an everywhere follower once about a new concert and stamps it', function () {
    fakeTicketmaster();
    [$artist, $user] = followedArtist('K8A');

    $this->artisan('gigradar:check-dates')->assertSuccessful();

    Notification::assertSentToTimes($user, NewTourDates::class, 1);
    Notification::assertSentTo($user, NewTourDates::class, function (NewTourDates $n) use ($artist) {
        return $n->artist->is($artist)
            && $n->concerts->pluck('ticketmaster_id')->all() === ['G5vYZ9abc001'];
    });
    expect($artist->concerts()->whereNull('alerted_at')->count())->toBe(0);
});

it('sends nothing on a second run', function () {
    fakeTicketmaster();
    [, $user] = followedArtist('K8A');

    $this->artisan('gigradar:check-dates');
    $this->artisan('gigradar:check-dates');

    Notification::assertSentToTimes($user, NewTourDates::class, 1);
});

it('seeds an unseeded artist without alerting', function () {
    fakeTicketmaster();
    [$artist, $user] = followedArtist('K8A', seeded: false);

    $this->artisan('gigradar:check-dates');

    Notification::assertNothingSent();
    expect($artist->fresh()->seeded)->toBeTrue()
        ->and($artist->concerts()->count())->toBe(2);
});

it('sends one notification containing both new concerts', function () {
    $payload = tmFixture('events');
    $payload['_embedded']['events'][1]['dates']['status']['code'] = 'onsale';
    fakeTicketmaster(payload: $payload);
    [, $user] = followedArtist('K8A');

    $this->artisan('gigradar:check-dates');

    Notification::assertSentToTimes($user, NewTourDates::class, 1);
    Notification::assertSentTo($user, NewTourDates::class, fn (NewTourDates $n) => $n->concerts->count() === 2);
});

it('does not alert a nearby follower about concerts outside their radius but still stamps them', function () {
    fakeTicketmaster();
    [$artist, $user] = followedArtist('K8A', 'nearby', ['nearby_mode' => 'radius', 'radius_miles' => 10, 'home_lat' => 51.5074, 'home_lng' => -0.1278]);

    $this->artisan('gigradar:check-dates');

    Notification::assertNothingSent();
    expect($artist->concerts()->whereNull('alerted_at')->count())->toBe(0);
});

it('stamps cancelled pending concerts without notifying anyone', function () {
    fakeTicketmaster();
    [$artist] = followedArtist('K8A');
    $cancelled = Concert::factory()->for($artist)->create(['status' => 'cancelled', 'alerted_at' => null, 'ticketmaster_id' => 'G5vYZ9abc002']);

    $this->artisan('gigradar:check-dates');

    // The fixture re-reports abc002 as cancelled, so only abc001 is new.
    Notification::assertSentTo(User::first(), NewTourDates::class, fn (NewTourDates $n) => $n->concerts->pluck('ticketmaster_id')->all() === ['G5vYZ9abc001']);
    expect($cancelled->fresh()->alerted_at)->not->toBeNull();
});

it('stamps a lone cancelled concert and notifies nobody', function () {
    fakeTicketmaster(payload: tmFixture('empty'));
    [$artist] = followedArtist('K8A');
    $cancelled = Concert::factory()->for($artist)->create(['status' => 'cancelled', 'alerted_at' => null]);

    $this->artisan('gigradar:check-dates');

    Notification::assertNothingSent();
    expect($cancelled->fresh()->alerted_at)->not->toBeNull();
});

it('keeps going when Ticketmaster fails for one artist and still alerts its already-pending concerts', function () {
    fakeTicketmaster(failing: ['K8A']);
    [$a, $userA] = followedArtist('K8A');
    [, $userB] = followedArtist('K8B');
    $pending = Concert::factory()->for($a)->create(['from_seed' => false, 'alerted_at' => null]);

    $this->artisan('gigradar:check-dates')->expectsOutputToContain('1 failure')->assertSuccessful();

    Notification::assertSentTo($userB, NewTourDates::class);
    Notification::assertSentTo($userA, NewTourDates::class, fn (NewTourDates $n) => $n->concerts->pluck('id')->all() === [$pending->id]);
});

it('does not fetch artists without followers', function () {
    fakeTicketmaster();
    followedArtist('K8A');
    Artist::factory()->create(['ticketmaster_id' => 'K8LONELY']);

    $this->artisan('gigradar:check-dates');

    Http::assertNotSent(fn (Request $r) => (tmQuery($r)['attractionId'] ?? null) === 'K8LONELY');
    Http::assertSent(fn (Request $r) => (tmQuery($r)['attractionId'] ?? null) === 'K8A');
});

it('prunes past concerts and reports a summary', function () {
    fakeTicketmaster();
    [$artist] = followedArtist('K8A');
    followedArtist('K8B');
    $past = Concert::factory()->for($artist)->create(['local_date' => today()->subDay()->toDateString(), 'starts_at' => now()->subDay()]);
    $pastNoDate = Concert::factory()->for($artist)->create(['local_date' => null, 'starts_at' => now()->subDays(2)]);
    $future = Concert::factory()->for($artist)->create();

    $this->artisan('gigradar:check-dates')->expectsOutputToContain('Checked 2 artists')->assertSuccessful();

    expect(Concert::whereKey([$past->id, $pastNoDate->id])->count())->toBe(0)
        ->and($future->fresh())->not->toBeNull();
});

it('is scheduled every six hours', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'gigradar:check-dates'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 */6 * * *')
        ->and($event->timezone)->toBe('Europe/London')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});

it('stamps a pending past concert without alerting', function () {
    fakeTicketmaster(payload: tmFixture('empty'));
    [$artist] = followedArtist('K8A');
    $past = Concert::factory()->for($artist)->create(['local_date' => today()->subDay()->toDateString(), 'starts_at' => now()->subDay(), 'alerted_at' => null]);

    $this->artisan('gigradar:check-dates');

    Notification::assertNothingSent();
    // Pruned at the end of the run, so only the absence of an alert is observable here.
    expect(Concert::find($past->id))->toBeNull();
});

it('leaves concerts unstamped when alerting throws', function () {
    fakeTicketmaster(payload: tmFixture('empty'));
    [$artist] = followedArtist('K8A');
    $pending = Concert::factory()->for($artist)->create(['alerted_at' => null]);
    app()->instance(Dispatcher::class, new class implements Dispatcher
    {
        public function send($notifiables, $notification)
        {
            throw new RuntimeException('boom');
        }

        public function sendNow($notifiables, $notification, ?array $channels = null)
        {
            throw new RuntimeException('boom');
        }
    });

    $this->artisan('gigradar:check-dates')->expectsOutputToContain('1 failure')->assertSuccessful();

    expect($pending->fresh()->alerted_at)->toBeNull();
});

it('counts only alerts that have a channel', function () {
    fakeTicketmaster();
    followedArtist('K8A', user: ['notify_email' => false]);

    $this->artisan('gigradar:check-dates')->expectsOutputToContain('sent 0 alerts');
});
