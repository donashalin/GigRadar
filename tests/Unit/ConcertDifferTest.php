<?php

use App\Services\Ticketmaster\ConcertData;
use App\Support\ConcertDiffer;
use Carbon\CarbonImmutable;

function concertData(string $id): ConcertData
{
    return new ConcertData($id, 'Gig', CarbonImmutable::parse('2027-01-01'), '2027-01-01', 'Venue', 'City', 'GB', null, null, 'https://x', 'onsale');
}

it('splits fetched concerts into new and existing by Ticketmaster id', function () {
    $result = ConcertDiffer::diff(['a', 'b'], [concertData('b'), concertData('c'), concertData('d')]);

    expect(array_map(fn ($c) => $c->id, $result['new']))->toBe(['c', 'd'])
        ->and(array_map(fn ($c) => $c->id, $result['existing']))->toBe(['b']);
});

it('treats everything as new when nothing is stored', function () {
    $result = ConcertDiffer::diff([], [concertData('a')]);

    expect($result['new'])->toHaveCount(1)->and($result['existing'])->toBe([]);
});

it('returns nothing when nothing is fetched', function () {
    expect(ConcertDiffer::diff(['a'], []))->toBe(['new' => [], 'existing' => []]);
});

it('keeps only the first occurrence of a repeated fetched id', function () {
    $first = concertData('c');
    $result = ConcertDiffer::diff(['b'], [$first, concertData('c'), concertData('b'), concertData('b')]);

    expect($result['new'])->toHaveCount(1)->and($result['new'][0])->toBe($first)
        ->and($result['existing'])->toHaveCount(1);
});
