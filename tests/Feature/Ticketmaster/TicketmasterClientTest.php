<?php

use App\Services\Ticketmaster\ArtistData;
use App\Services\Ticketmaster\ConcertData;
use App\Services\Ticketmaster\DiscoveryEventData;
use App\Services\Ticketmaster\TicketmasterClient;
use App\Services\Ticketmaster\TicketmasterException;
use Illuminate\Http\Client\ConnectionException;
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

it('ranks exact name matches first and requests relevance sorting', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response(['_embedded' => ['attractions' => [
        ['id' => 'A1', 'name' => 'Ultimate Coldplay'],
        ['id' => 'A2', 'name' => ' Coldplay '],
        ['id' => 'A3', 'name' => 'Coldplay Tribute'],
    ]]])]);

    $results = app(TicketmasterClient::class)->searchAttractions('coldplay');

    expect(array_map(fn ($a) => $a->id, $results))->toBe(['A2', 'A1', 'A3']);

    Http::assertSent(fn (Request $r) => tmQuery($r)['sort'] === 'relevance,desc');
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
        ->and($manchester->localDate)->toBe('2027-03-14')
        ->and($manchester->venueName)->toBe('O2 Victoria Warehouse')
        ->and($manchester->city)->toBe('Manchester')
        ->and($manchester->country)->toBe('GB')
        ->and($manchester->lat)->toBe(53.4668)
        ->and($manchester->lng)->toBe(-2.285)
        ->and($manchester->status)->toBe('onsale')
        ->and($dublin->startsAt->toDateString())->toBe('2027-04-02')
        ->and($dublin->localDate)->toBe('2027-04-02')
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

it('does not leak the API key when the connection fails', function () {
    Http::fake(['app.ticketmaster.com/*' => fn () => throw new ConnectionException('cURL error 28 for https://app.ticketmaster.com/discovery/v2/attractions.json?keyword=x&apikey=test-key')]);

    try {
        app(TicketmasterClient::class)->searchAttractions('x');
        $this->fail('Expected TicketmasterException');
    } catch (TicketmasterException $e) {
        expect($e->getMessage())->not->toContain('test-key')
            ->and($e->getPrevious())->toBeNull()
            ->and($e->getCode())->toBe(0);
    }
});

it('throws a 502 when the body is not JSON', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response('<html>oops</html>', 200)]);

    try {
        app(TicketmasterClient::class)->searchAttractions('x');
        $this->fail('Expected TicketmasterException');
    } catch (TicketmasterException $e) {
        expect($e->getCode())->toBe(502);
    }
});

it('throws a 502 when an attraction is missing its id or name', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response(['foo' => 'bar'])]);

    try {
        app(TicketmasterClient::class)->attraction('x');
        $this->fail('Expected TicketmasterException');
    } catch (TicketmasterException $e) {
        expect($e->getCode())->toBe(502);
    }
});

it('drops non-http(s) ticket and image URLs', function () {
    Http::fake([
        'app.ticketmaster.com/discovery/v2/events.json*' => Http::response(['_embedded' => ['events' => [[
            'id' => 'E1', 'name' => 'Gig', 'url' => 'javascript:alert(1)',
            'dates' => ['start' => ['localDate' => '2027-01-01']],
        ]]]]),
        'app.ticketmaster.com/discovery/v2/attractions/A1.json*' => Http::response([
            'id' => 'A1', 'name' => 'Band', 'images' => [['url' => 'javascript:alert(1)', 'ratio' => '16_9', 'width' => 100]],
        ]),
    ]);

    $client = app(TicketmasterClient::class);

    expect($client->upcomingEvents('A1')[0]->ticketUrl)->toBe('')
        ->and($client->attraction('A1')->imageUrl)->toBeNull();
});

it('falls back to the UTC date when an event has a dateTime but no localDate', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response(['_embedded' => ['events' => [[
        'id' => 'E1', 'name' => 'Gig', 'dates' => ['start' => ['dateTime' => '2027-05-01T23:30:00Z']],
    ]]]])]);

    expect(app(TicketmasterClient::class)->upcomingEvents('A1')[0]->localDate)->toBe('2027-05-01');
});

it('skips malformed attractions in search results instead of failing', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response(['_embedded' => ['attractions' => [
        ['id' => 'A1', 'name' => 'Good'],
        ['name' => 'No id'],
        ['id' => 'A3'],
    ]]])]);

    $results = app(TicketmasterClient::class)->searchAttractions('good');

    expect($results)->toHaveCount(1)->and($results[0]->id)->toBe('A1');
});

it('skips events missing an id or name', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response(['_embedded' => ['events' => [
        ['id' => 'E1', 'name' => 'Good', 'dates' => ['start' => ['localDate' => '2027-01-01']]],
        ['name' => 'No id', 'dates' => ['start' => ['localDate' => '2027-01-01']]],
        ['id' => 'E3', 'dates' => ['start' => ['localDate' => '2027-01-01']]],
    ]]])]);

    $concerts = app(TicketmasterClient::class)->upcomingEvents('A1');

    expect($concerts)->toHaveCount(1)->and($concerts[0]->id)->toBe('E1');
});

it('maps genre and sub-genre classifications', function () {
    Http::fake(['app.ticketmaster.com/discovery/v2/attractions/K8vZ917G1V0.json*' => Http::response(tmFixture('attraction'))]);

    $artist = app(TicketmasterClient::class)->attraction('K8vZ917G1V0');

    expect($artist->genreId)->toBe('KnvZfZ7vAvv')
        ->and($artist->genreName)->toBe('Alternative')
        ->and($artist->subGenreId)->toBe('KZazBEonSMnZfZ7vAde')
        ->and($artist->subGenreName)->toBe('Alternative Rock');
});

it('leaves classifications null when missing', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response(['id' => 'A1', 'name' => 'Nobody'])]);

    $artist = app(TicketmasterClient::class)->attraction('A1');

    expect($artist->genreId)->toBeNull()->and($artist->subGenreId)->toBeNull();
});

it('ignores malformed classifications', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response([
        'id' => 'A1', 'name' => 'Odd',
        'classifications' => [['genre' => ['id' => 123, 'name' => ['x']], 'subGenre' => 'nope']],
    ])]);

    $artist = app(TicketmasterClient::class)->attraction('A1');

    expect($artist->genreId)->toBeNull()->and($artist->genreName)->toBeNull()
        ->and($artist->subGenreId)->toBeNull()->and($artist->subGenreName)->toBeNull();

    Http::fake(['app.ticketmaster.com/*' => Http::response(['id' => 'A2', 'name' => 'Odder', 'classifications' => 'x'])]);
    expect(app(TicketmasterClient::class)->attraction('A2')->genreId)->toBeNull();
});

it('maps classifications in search results', function () {
    Http::fake(['app.ticketmaster.com/discovery/v2/attractions.json*' => Http::response(tmFixture('attractions-search'))]);

    $results = app(TicketmasterClient::class)->searchAttractions('fontaines');

    expect($results[0]->subGenreName)->toBe('Alternative Rock')->and($results[1]->subGenreId)->toBeNull();
});

it('fetches discovery events by classification and country', function () {
    Http::fake(['app.ticketmaster.com/discovery/v2/events.json*' => Http::response(tmFixture('discovery-events'))]);

    $events = app(TicketmasterClient::class)->eventsByClassification('KZazBEonSMnZfZ7vAde', 'GB');

    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(DiscoveryEventData::class)
        ->and($events[0]->attractionId)->toBe('K8vZShame01')
        ->and($events[0]->attractionName)->toBe('Shame')
        ->and($events[0]->attractionImageUrl)->toBe('https://s1.ticketm.net/dam/a/shame-large.jpg')
        ->and($events[0]->concert->id)->toBe('D5vYZ9disc001')
        ->and($events[0]->concert->city)->toBe('Leeds')
        ->and($events[1]->concert->status)->toBe('cancelled')
        ->and($events[1]->attractionImageUrl)->toBeNull();

    Http::assertSent(fn (Request $r) => tmQuery($r)['classificationId'] === 'KZazBEonSMnZfZ7vAde'
        && tmQuery($r)['countryCode'] === 'GB'
        && tmQuery($r)['segmentId'] === 'KZFzniwnSyZfZ7v7nJ'
        && ! isset(tmQuery($r)['classificationName'])
        && tmQuery($r)['sort'] === 'date,asc'
        && tmQuery($r)['size'] === '200'
        && tmQuery($r)['page'] === '0');
});

it('drops unsafe attraction image URLs and malformed attractions in discovery events', function () {
    $event = fn (string $id, array $attractions) => [
        'id' => $id, 'name' => $id, 'dates' => ['start' => ['localDate' => '2027-01-01']],
        '_embedded' => ['attractions' => $attractions],
    ];
    Http::fake(['app.ticketmaster.com/*' => Http::response(['_embedded' => ['events' => [
        $event('E1', [['id' => 'A1', 'name' => 'Bad Image', 'images' => [['ratio' => '16_9', 'url' => 'javascript:alert(1)', 'width' => 9]]]]),
        $event('E2', ['not-an-array']),
        $event('E3', [['name' => 'No id']]),
        'junk',
    ]]])]);

    $events = app(TicketmasterClient::class)->eventsByClassification('C1', 'GB');

    expect($events)->toHaveCount(1)->and($events[0]->attractionImageUrl)->toBeNull();
});

it('redacts the API key when a discovery request cannot connect', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error for https://x/events.json?apikey=test-key'));

    try {
        app(TicketmasterClient::class)->eventsByClassification('C1', 'GB');
        $this->fail('Expected TicketmasterException');
    } catch (TicketmasterException $e) {
        expect($e->getMessage())->not->toContain('test-key')->and($e->getPrevious())->toBeNull();
    }
});

it('pages through discovery events up to three pages', function () {
    $page = fn (int $n) => ['_embedded' => ['events' => array_map(fn ($i) => [
        'id' => "E{$n}-{$i}", 'name' => 'x', 'dates' => ['start' => ['localDate' => '2027-01-01']],
        '_embedded' => ['attractions' => [['id' => 'A', 'name' => 'A']]],
    ], range(1, 200))], 'page' => ['size' => 200, 'totalPages' => 10, 'number' => $n]];
    Http::fake(['app.ticketmaster.com/*' => Http::sequence()->push($page(0))->push($page(1))->push($page(2))->push($page(3))]);

    $events = app(TicketmasterClient::class)->eventsByClassification('C1', 'GB');

    expect($events)->toHaveCount(600);
    Http::assertSentCount(3);
});

it('stops paging at totalPages or a short page', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response(tmFixture('discovery-events'))]);
    app(TicketmasterClient::class)->eventsByClassification('C1', 'GB');
    Http::assertSentCount(1);

    $full = ['_embedded' => ['events' => array_map(fn ($i) => [
        'id' => "E{$i}", 'name' => 'x', 'dates' => ['start' => ['localDate' => '2027-01-01']],
    ], range(1, 200))], 'page' => ['totalPages' => 1]];
    Http::fake(['app.ticketmaster.com/*' => Http::response($full)]);
    app(TicketmasterClient::class)->eventsByClassification('C1', 'GB');
    Http::assertSentCount(1);
});

it('skips events with an unparseable date', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response(['_embedded' => ['events' => [
        ['id' => 'E1', 'name' => 'Bad', 'dates' => ['start' => ['dateTime' => 'not a date']], '_embedded' => ['attractions' => [['id' => 'A', 'name' => 'A']]]],
        ['id' => 'E2', 'name' => 'Good', 'dates' => ['start' => ['localDate' => '2027-01-01']], '_embedded' => ['attractions' => [['id' => 'A', 'name' => 'A']]]],
    ]]])]);

    $events = app(TicketmasterClient::class)->eventsByClassification('C1', 'GB');

    expect($events)->toHaveCount(1)->and($events[0]->concert->id)->toBe('E2');
});

it('skips unparseable dates in upcoming events too', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response(['_embedded' => ['events' => [
        ['id' => 'E1', 'name' => 'Bad', 'dates' => ['start' => ['localDate' => '2027-13-45']]],
    ]]])]);

    expect(app(TicketmasterClient::class)->upcomingEvents('A'))->toBe([]);
});
