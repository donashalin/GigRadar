<?php

use App\Support\TributeFilter;

it('keeps and excludes the right acts', function (?string $type, ?string $sub, string $name, string $event, ?string $expected) {
    expect(TributeFilter::reason($type, $sub, $name, $event))->toBe($expected);
})->with([
    'Dua Lipa' => ['Individual', 'Musician', 'Dua Lipa', 'Dua Lipa Live', null],
    'Tina tribute subtype' => ['Group', 'Tribute Band', 'TINA LIVE - The Tina Turner Experience', 'TINA LIVE', 'tribute_subtype'],
    'Rob Lamberti' => ['Group', 'Tribute Band', 'Rob Lamberti', 'Rob Lamberti', 'tribute_subtype'],
    'subtype is case-insensitive' => ['Group', 'TRIBUTE act', 'X', 'X', 'tribute_subtype'],
    'Simply Billy Joel' => ['Event Style', 'Fan Experiences', 'Simply Billy Joel', 'Simply Billy Joel', 'not_an_artist'],
    'Swiftogeddon' => ['Event Style', 'Concert', 'Swiftogeddon', 'Swiftogeddon', 'not_an_artist'],
    'type is case-insensitive' => ['group', 'Band', 'Shame', 'Shame', null],
    'The Wanted 2.0' => ['Group', 'Band', 'The Wanted 2.0', 'The Wanted 2.0 Live', 'name_pattern'],
    'the music of' => ['Group', 'Band', 'The Music of Queen', 'x', 'name_pattern'],
    'a celebration of in event name' => ['Group', 'Band', 'Some Band', 'A Celebration of ABBA', 'name_pattern'],
    'celebrating the music' => ['Group', 'Band', 'X', 'Celebrating the Music of Prince', 'name_pattern'],
    'salute to' => ['Group', 'Band', 'A Salute To Elvis', 'x', 'name_pattern'],
    'tribute in name' => [null, null, 'Pink Floyd Tribute', 'x', 'name_pattern'],
    'Jimi Hendrix Experience kept' => ['Group', 'Band', 'The Jimi Hendrix Experience', 'The Jimi Hendrix Experience', null],
    'Simply Red kept' => ['Group', null, 'Simply Red', 'Simply Red Live', null],
    'missing type and subtype kept' => [null, null, 'Idles', 'Idles', null],
    'tribute needs word boundary' => ['Group', 'Band', 'Attributes', 'x', null],
    '2.0 needs boundaries' => ['Group', 'Band', 'Band 12.05', 'Tour 2.05', null],
]);
