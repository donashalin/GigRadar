<?php

use App\Models\Artist;
use App\Models\Concert;
use App\Models\User;
use App\Support\RecipientSelector;

function addFollower(string $scope, array $attrs = []): User
{
    $user = User::factory()->create($attrs);
    test()->artist->followers()->attach($user, ['alert_scope' => $scope]);

    return $user;
}

function makePending(array $attrs = []): Concert
{
    return Concert::factory()->for(test()->artist)->create(['alerted_at' => null, ...$attrs]);
}

function selectFor(array $concerts): array
{
    return RecipientSelector::select(collect($concerts), test()->artist->followers()->get());
}

beforeEach(function () {
    $this->artist = Artist::factory()->create();
    $this->leicester = makePending(['city' => 'Leicester', 'country' => 'GB', 'lat' => 52.6369, 'lng' => -1.1398]);
    $this->manchester = makePending(['city' => 'Manchester', 'country' => 'GB', 'lat' => 53.4808, 'lng' => -2.2426]);
});

it('gives an everywhere follower all concerts in order', function () {
    $user = addFollower('everywhere');

    $result = selectFor([$this->manchester, $this->leicester]);

    expect(array_keys($result))->toBe([$user->id])
        ->and($result[$user->id]->pluck('id')->all())->toBe([$this->manchester->id, $this->leicester->id]);
});

it('filters a radius-mode nearby follower by distance', function () {
    $user = addFollower('nearby', ['nearby_mode' => 'radius', 'radius_miles' => 50, 'home_lat' => 52.6369, 'home_lng' => -1.1398]);

    $result = selectFor([$this->leicester, $this->manchester]);

    expect($result[$user->id]->pluck('id')->all())->toBe([$this->leicester->id]);
});

it('filters a country-mode nearby follower by country', function () {
    $user = addFollower('nearby', ['nearby_mode' => 'country', 'home_country_code' => 'GB']);
    $glasgow = makePending(['city' => 'Glasgow', 'country' => 'GB', 'lat' => 55.86, 'lng' => -4.25]);
    $dublin = makePending(['city' => 'Dublin', 'country' => 'IE', 'lat' => 53.35, 'lng' => -6.26]);

    $result = selectFor([$glasgow, $dublin]);

    expect($result[$user->id]->pluck('id')->all())->toBe([$glasgow->id]);
});

it('alerts a nearby follower with no home location about everything', function () {
    $user = addFollower('nearby', ['nearby_mode' => 'radius', 'home_lat' => null, 'home_lng' => null]);

    expect(selectFor([$this->leicester, $this->manchester])[$user->id])->toHaveCount(2);
});

it('includes concerts with an unknown country in country mode', function () {
    $user = addFollower('nearby', ['nearby_mode' => 'country', 'home_country_code' => 'GB']);
    $unknown = makePending(['country' => '']);

    expect(selectFor([$unknown])[$user->id]->pluck('id')->all())->toBe([$unknown->id]);
});

it('never includes cancelled concerts', function () {
    $user = addFollower('everywhere');
    $cancelled = makePending(['status' => 'cancelled']);

    expect(selectFor([$cancelled, $this->leicester])[$user->id]->pluck('id')->all())->toBe([$this->leicester->id]);
});

it('omits users with nothing to receive', function () {
    addFollower('nearby', ['nearby_mode' => 'radius', 'radius_miles' => 10, 'home_lat' => 51.5, 'home_lng' => -0.12]);

    expect(selectFor([$this->leicester]))->toBe([]);
});
