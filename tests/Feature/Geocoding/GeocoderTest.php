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
        && str_contains($r->url(), 'accept-language=en')
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

it('sends only ~100 m precision coordinates when reverse-geocoding', function () {
    Http::fake(['nominatim.openstreetmap.org/reverse*' => Http::response(nominatimFixture('reverse'))]);

    $place = app(Geocoder::class)->reverse(52.63621234, -1.13314567);

    expect($place->name)->toBe('Leicester, Leicestershire, United Kingdom');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'lat=52.636&')
        && str_contains($r->url(), 'lon=-1.133&')
        && str_contains($r->url(), 'accept-language=en'));
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

it('throws on a non-JSON 200 body instead of caching it', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response('<html>oops</html>', 200)]);

    app(Geocoder::class)->search('Leicester');
})->throws(GeocoderException::class, 'Nominatim returned an unexpected response');

it('skips malformed results', function () {
    Http::fake(['nominatim.openstreetmap.org/search*' => Http::response([
        ['lat' => '52.6', 'lon' => '-1.1', 'display_name' => 'Leicester, UK'],
        ['lat' => 'abc', 'lon' => '-1.1', 'display_name' => 'Bad lat'],
        ['lat' => '52.6', 'lon' => '-1.1', 'display_name' => ['x']],
        ['lat' => '52.6', 'lon' => '-1.1', 'display_name' => ''],
    ])]);

    expect(app(Geocoder::class)->search('Leicester'))->toHaveCount(1);
});

it('drops empty label segments', function () {
    Http::fake(['nominatim.openstreetmap.org/search*' => Http::sequence()
        ->push([['lat' => '1', 'lon' => '2', 'display_name' => 'A, , B, C, D']])
        ->push([['lat' => '1', 'lon' => '2', 'display_name' => 'Paris, France']])]);

    expect(app(Geocoder::class)->search('first')[0]->name)->toBe('A, B, D')
        ->and(app(Geocoder::class)->search('second')[0]->name)->toBe('Paris, France');
});

it('keeps queries and coordinates out of exception messages', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 503)]);
    $messages = [];

    try {
        app(Geocoder::class)->search('SecretTown');
    } catch (GeocoderException $e) {
        $messages[] = $e->getMessage();
    }
    try {
        app(Geocoder::class)->reverse(51.123456, -0.654321);
    } catch (GeocoderException $e) {
        $messages[] = $e->getMessage();
    }

    expect($messages)->toHaveCount(2);
    foreach ($messages as $m) {
        expect($m)->not->toContain('SecretTown')->not->toContain('51.12')->not->toContain('-0.65');
    }
});
