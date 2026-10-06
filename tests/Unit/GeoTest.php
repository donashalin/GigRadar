<?php

use App\Support\Geo;

it('is zero for the same point', function () {
    expect(Geo::distanceMiles(52.6369, -1.1398, 52.6369, -1.1398))->toBe(0.0);
});

it('measures Leicester to Manchester as about 74 miles', function () {
    expect(Geo::distanceMiles(52.6369, -1.1398, 53.4808, -2.2426))
        ->toBeGreaterThan(70.0)->toBeLessThan(80.0);
});

it('measures London to Paris as about 213 miles', function () {
    expect(Geo::distanceMiles(51.5074, -0.1278, 48.8566, 2.3522))
        ->toBeGreaterThan(205.0)->toBeLessThan(220.0);
});

it('is symmetric', function () {
    expect(Geo::distanceMiles(51.5074, -0.1278, 48.8566, 2.3522))
        ->toBe(Geo::distanceMiles(48.8566, 2.3522, 51.5074, -0.1278));
});
