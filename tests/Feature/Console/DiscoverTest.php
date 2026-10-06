<?php

use App\Models\Artist;
use App\Models\DiscoveryEvent;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function fakeDiscovery(array $failing = []): void
{
    Http::fake(['app.ticketmaster.com/discovery/v2/events.json*' => function (Request $request) use ($failing) {
        return in_array(tmQuery($request)['classificationId'] ?? null, $failing, true)
            ? Http::response([], 500)
            : Http::response(tmFixture('discovery-events'));
    }]);
}

function vibeUser(array $subGenres, ?string $country = 'GB'): User
{
    $user = User::factory()->create(['home_country_code' => $country]);
    foreach ($subGenres as $i => $id) {
        $artist = Artist::factory()->create(['sub_genre_id' => $id, 'sub_genre_name' => "Sub {$id}"]);
        $user->artists()->attach($artist);
    }

    return $user;
}

it('fetches each distinct classification and country pair once', function () {
    fakeDiscovery();
    vibeUser(['S1', 'S2']);
    vibeUser(['S1']);
    vibeUser(['S1'], 'IE');

    $this->artisan('gigradar:discover')
        ->expectsOutput('Fetched 3 classifications, stored 4 new gigs, 0 failures.')
        ->assertSuccessful();

    $pairs = collect(Http::recorded())->map(fn ($p) => tmQuery($p[0])['classificationId'].'|'.tmQuery($p[0])['countryCode'])->sort()->values()->all();
    expect($pairs)->toBe(['S1|GB', 'S1|IE', 'S2|GB']);
});

it('skips users without a home country', function () {
    Http::fake();
    vibeUser(['S1'], null);

    $this->artisan('gigradar:discover')->expectsOutput('Fetched 0 classifications, stored 0 new gigs, 0 failures.')->assertSuccessful();

    Http::assertNothingSent();
});

it('inserts with first_seen_at and keeps it on later runs while refreshing other fields', function () {
    fakeDiscovery();
    vibeUser(['S1']);

    $this->artisan('gigradar:discover');
    $row = DiscoveryEvent::where('ticketmaster_event_id', 'D5vYZ9disc001')->firstOrFail();
    expect(DiscoveryEvent::count())->toBe(2)
        ->and($row->classification_id)->toBe('S1')
        ->and($row->attraction_name)->toBe('Shame')
        ->and($row->first_seen_at)->not->toBeNull();

    $this->travel(2)->days();
    $row->forceFill(['venue_name' => 'Stale'])->save();
    $firstSeen = $row->fresh()->first_seen_at;

    $this->artisan('gigradar:discover')->expectsOutput('Fetched 1 classifications, stored 0 new gigs, 0 failures.');

    $again = $row->fresh();
    expect(DiscoveryEvent::count())->toBe(2)
        ->and($again->first_seen_at->equalTo($firstSeen))->toBeTrue()
        ->and($again->venue_name)->toBe('Brudenell Social Club');
});

it('prunes rows that are no longer upcoming', function () {
    fakeDiscovery();
    vibeUser(['S1']);
    $past = DiscoveryEvent::factory()->create(['local_date' => today()->subDay()->toDateString(), 'starts_at' => now()->subDay()]);
    $pastNoDate = DiscoveryEvent::factory()->create(['local_date' => null, 'starts_at' => now()->subDays(2)]);
    $today = DiscoveryEvent::factory()->create(['local_date' => today()->toDateString(), 'starts_at' => now()->startOfDay()]);

    $this->artisan('gigradar:discover');

    expect($past->fresh())->toBeNull()->and($pastNoDate->fresh())->toBeNull()->and($today->fresh())->not->toBeNull();
});

it('keeps going when one pair fails', function () {
    fakeDiscovery(failing: ['S1']);
    vibeUser(['S1', 'S2']);

    $this->artisan('gigradar:discover')
        ->expectsOutput('Fetched 1 classifications, stored 2 new gigs, 1 failures.')
        ->assertSuccessful();

    expect(DiscoveryEvent::pluck('classification_id')->unique()->all())->toBe(['S2']);
});

it('is scheduled daily at 04:00 Europe/London', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'gigradar:discover'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 4 * * *')
        ->and($event->timezone)->toBe('Europe/London')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});

it('skips one bad event, keeps the rest of the pair and other pairs, and counts the failure', function () {
    fakeDiscovery();
    vibeUser(['S1', 'S2']);
    DiscoveryEvent::saving(function (DiscoveryEvent $e) {
        if ($e->classification_id === 'S1' && $e->ticketmaster_event_id === 'D5vYZ9disc001') {
            throw new RuntimeException('bad row');
        }
    });

    $this->artisan('gigradar:discover')
        ->expectsOutput('Fetched 2 classifications, stored 3 new gigs, 1 failures.')
        ->assertSuccessful();

    expect(DiscoveryEvent::where('classification_id', 'S1')->count())->toBe(1)
        ->and(DiscoveryEvent::where('classification_id', 'S2')->count())->toBe(2);
});

it('still prints the summary when pruning fails', function () {
    fakeDiscovery();
    vibeUser(['S1']);
    DB::beforeExecuting(function (string $query) {
        if (str_starts_with(strtolower($query), 'delete from')) {
            throw new RuntimeException('prune failed');
        }
    });

    $this->artisan('gigradar:discover')
        ->expectsOutput('Fetched 1 classifications, stored 2 new gigs, 1 failures.')
        ->assertSuccessful();
});

it('marks rows from the first-ever fetch of a pair as seed, and later new rows as not', function () {
    fakeDiscovery();
    vibeUser(['S1']);

    $this->artisan('gigradar:discover');
    expect(DiscoveryEvent::where('from_seed', true)->count())->toBe(2);

    // The pair now has rows (GB), so a gig appearing later is genuinely new.
    DiscoveryEvent::where('ticketmaster_event_id', 'D5vYZ9disc001')->delete();
    DiscoveryEvent::factory()->create(['classification_id' => 'S1', 'country' => 'GB', 'from_seed' => true]);
    $this->artisan('gigradar:discover');

    expect(DiscoveryEvent::where('ticketmaster_event_id', 'D5vYZ9disc001')->value('from_seed'))->toBeFalse();
});

it('treats a pair as seeded per classification and country', function () {
    fakeDiscovery();
    vibeUser(['S1']);
    DiscoveryEvent::factory()->create(['classification_id' => 'S1', 'country' => 'IE', 'from_seed' => false]);

    $this->artisan('gigradar:discover');

    expect(DiscoveryEvent::where('classification_id', 'S1')->where('country', 'GB')->where('from_seed', true)->count())->toBeGreaterThan(0);
});
