<?php

use App\Models\Concert;
use App\Support\AlertSummary;

function summaryConcert(array $attrs): Concert
{
    return new Concert(['city' => 'Manchester', 'venue_name' => 'Apollo', ...$attrs]);
}

it('summarises a single concert', function () {
    expect(AlertSummary::for(collect([summaryConcert(['local_date' => '2027-03-14'])])))->toBe('New date: Manchester – 14 Mar');
});

it('summarises several concerts using the earliest', function () {
    $concerts = collect([
        summaryConcert(['city' => 'Glasgow', 'local_date' => '2027-05-01']),
        summaryConcert(['city' => 'Leeds', 'local_date' => '2027-03-02']),
        summaryConcert(['city' => 'Bristol', 'local_date' => '2027-04-09']),
    ]);

    expect(AlertSummary::for($concerts))->toBe('3 new dates, including Leeds – 2 Mar');
});

it('falls back to starts_at when local_date is missing', function () {
    expect(AlertSummary::for(collect([summaryConcert(['local_date' => null, 'starts_at' => '2027-06-20 19:00:00'])])))
        ->toBe('New date: Manchester – 20 Jun');
});

it('uses the venue name when the city is empty', function () {
    expect(AlertSummary::for(collect([summaryConcert(['city' => '', 'local_date' => '2027-03-14'])])))->toBe('New date: Apollo – 14 Mar');
});
