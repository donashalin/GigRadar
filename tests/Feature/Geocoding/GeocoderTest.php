<?php

use App\Services\Geocoding\Geocoder;
use App\Services\Geocoding\GeocoderException;
use App\Services\Geocoding\Place;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('searches places with short labels and identifies itself', function () {
    Http::fake(['nominatim.openstreetmap.org/search*' => Http::response(nominatimFixture('search'))]);

    $places = app(Geocoder::class)->search('Leicester');

    expect($places)->toHaveCount(2)
        ->and($places[0])->toBeInstanceOf(Place::class)
        ->and($places[0]->name)->toBe('Leicester, Leicestershire, United Kingdom')
        ->and($places[0]->lat)->toBe(52.6362)
        ->and($places[0]->lng)->toBe(-1.1331)
        ->and($places[1]->name)->toBe('Leicester, Worcester County, United States');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'q=Leicester')
        && str_contains($r->url(), 'format=jsonv2')
        && $r->hasHeader('User-Agent', 'GigRadar/1.0 (+https://github.com/donashalin/GigRadar)'));
});

it('caches searches', function () {
    Http::fake(['nominatim.openstreetmap.org/search*' => Http::response(nominatimFixture('search'))]);

    app(Geocoder::class)->search('Leicester');
    app(Geocoder::class)->search(' leicester ');

    Http::assertSentCount(1);
});

it('reverse-geocodes coordinates rounded to 4 decimals', function () {
    Http::fake(['nominatim.openstreetmap.org/reverse*' => Http::response(nominatimFixture('reverse'))]);

    $place = app(Geocoder::class)->reverse(52.63621234, -1.13314567);

    expect($place->name)->toBe('Leicester, Leicestershire, United Kingdom')
        ->and($place->lat)->toBe(52.6362)
        ->and($place->lng)->toBe(-1.1331);
});

it('returns null when a point cannot be reverse-geocoded', function () {
    Http::fake(['nominatim.openstreetmap.org/reverse*' => Http::response(['error' => 'Unable to geocode'])]);

    expect(app(Geocoder::class)->reverse(0.0, 0.0))->toBeNull();
});

it('throws when Nominatim fails', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 503)]);

    app(Geocoder::class)->search('Leicester');
})->throws(GeocoderException::class);

it('throws when Nominatim is unreachable', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::failedConnection()]);

    app(Geocoder::class)->search('Leicester');
})->throws(GeocoderException::class);
