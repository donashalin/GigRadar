<?php

use App\Support\NearbyArea;

function countryArea(?string $code = 'GB'): NearbyArea
{
    return new NearbyArea('country', 52.6369, -1.1398, $code, 50);
}

function radiusArea(int $miles, bool $withHome = true): NearbyArea
{
    return new NearbyArea('radius', $withHome ? 52.6369 : null, $withHome ? -1.1398 : null, 'GB', $miles);
}

it('matches the home country case-insensitively in country mode', function (string $country, bool $expected) {
    expect(countryArea()->contains($country, null, null))->toBe($expected);
})->with([
    'same code' => ['GB', true],
    'lower case' => ['gb', true],
    'other country' => ['IE', false],
]);

it('cannot judge a concert without a country in country mode', function (?string $country) {
    expect(countryArea()->contains($country, 52.6, -1.1))->toBeNull();
})->with([null, '']);

it('cannot judge anything without a home country, and is not configured', function () {
    $area = countryArea(null);

    expect($area->contains('GB', 52.6, -1.1))->toBeNull()
        ->and($area->isConfigured())->toBeFalse()
        ->and(countryArea()->isConfigured())->toBeTrue();
});

it('uses distance in radius mode', function () {
    $manchester = [53.4808, -2.2426];

    expect(radiusArea(25)->contains('GB', 52.6369, -1.1398))->toBeTrue()
        ->and(radiusArea(50)->contains('GB', ...$manchester))->toBeFalse()
        ->and(radiusArea(100)->contains('GB', ...$manchester))->toBeTrue();
});

it('cannot judge radius mode with missing coordinates', function () {
    expect(radiusArea(50)->contains('GB', null, null))->toBeNull()
        ->and(radiusArea(50)->contains('GB', 52.6, null))->toBeNull()
        ->and(radiusArea(50, withHome: false)->contains('GB', 52.6, -1.1))->toBeNull();
});

it('is configured in radius mode only with home coordinates', function () {
    expect(radiusArea(50)->isConfigured())->toBeTrue()
        ->and(radiusArea(50, withHome: false)->isConfigured())->toBeFalse();
});

it('reports distance only when both ends have coordinates', function () {
    expect(radiusArea(50)->distanceMiles(52.6369, -1.1398))->toBe(0.0)
        ->and(radiusArea(50)->distanceMiles(null, -1.1))->toBeNull()
        ->and(radiusArea(50)->distanceMiles(52.6, null))->toBeNull()
        ->and(radiusArea(50, withHome: false)->distanceMiles(52.6, -1.1))->toBeNull();
});
