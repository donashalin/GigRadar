<?php

use App\Services\Ticketmaster\ArtistData;
use App\Services\Ticketmaster\ConcertData;
use App\Services\Ticketmaster\TicketmasterClient;
use App\Services\Ticketmaster\TicketmasterException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('searches music attractions and picks the widest 16:9 image', function () {
    Http::fake(['app.ticketmaster.com/discovery/v2/attractions.json*' => Http::response(tmFixture('attractions-search'))]);

    $results = app(TicketmasterClient::class)->searchAttractions('fontaines');

    expect($results)->toHaveCount(2)
        ->and($results[0])->toBeInstanceOf(ArtistData::class)
        ->and($results[0]->id)->toBe('K8vZ917G1V0')
        ->and($results[0]->name)->toBe('Fontaines D.C.')
        ->and($results[0]->imageUrl)->toBe('https://s1.ticketm.net/dam/a/large.jpg')
        ->and($results[1]->imageUrl)->toBeNull();

    Http::assertSent(fn (Request $r) => tmQuery($r)['keyword'] === 'fontaines'
        && tmQuery($r)['classificationName'] === 'music'
        && tmQuery($r)['apikey'] === 'test-key');
});

it('returns no attractions when Ticketmaster has none', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response(tmFixture('empty'))]);

    expect(app(TicketmasterClient::class)->searchAttractions('zzzz'))->toBe([]);
});

it('looks up a single attraction', function () {
    Http::fake(['app.ticketmaster.com/discovery/v2/attractions/K8vZ917G1V0.json*' => Http::response(tmFixture('attraction'))]);

    $artist = app(TicketmasterClient::class)->attraction('K8vZ917G1V0');

    expect($artist->name)->toBe('Fontaines D.C.')
        ->and($artist->imageUrl)->toBe('https://s1.ticketm.net/dam/a/large.jpg');
});

it('maps upcoming events to concerts and skips dateless ones', function () {
    Http::fake(['app.ticketmaster.com/discovery/v2/events.json*' => Http::response(tmFixture('events'))]);

    $concerts = app(TicketmasterClient::class)->upcomingEvents('K8vZ917G1V0');

    expect($concerts)->toHaveCount(2);

    [$manchester, $dublin] = $concerts;
    expect($manchester)->toBeInstanceOf(ConcertData::class)
        ->and($manchester->id)->toBe('G5vYZ9abc001')
        ->and($manchester->startsAt->toIso8601String())->toBe('2027-03-14T19:30:00+00:00')
        ->and($manchester->venueName)->toBe('O2 Victoria Warehouse')
        ->and($manchester->city)->toBe('Manchester')
        ->and($manchester->country)->toBe('GB')
        ->and($manchester->lat)->toBe(53.4668)
        ->and($manchester->lng)->toBe(-2.285)
        ->and($manchester->status)->toBe('onsale')
        ->and($dublin->startsAt->toDateString())->toBe('2027-04-02')
        ->and($dublin->lat)->toBeNull()
        ->and($dublin->status)->toBe('cancelled');

    Http::assertSent(fn (Request $r) => tmQuery($r)['attractionId'] === 'K8vZ917G1V0'
        && tmQuery($r)['sort'] === 'date,asc'
        && tmQuery($r)['size'] === '200');
});

it('throws with the HTTP status when Ticketmaster fails', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response([], 404)]);

    try {
        app(TicketmasterClient::class)->attraction('nope');
        $this->fail('Expected TicketmasterException');
    } catch (TicketmasterException $e) {
        expect($e->getCode())->toBe(404)->and($e->isNotFound())->toBeTrue();
    }
});

it('throws when Ticketmaster is unreachable', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::failedConnection()]);

    app(TicketmasterClient::class)->searchAttractions('x');
})->throws(TicketmasterException::class);
