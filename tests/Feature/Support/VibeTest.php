<?php

use App\Models\Artist;
use App\Support\Vibe;

function vibeArtist(string $name, ?array $sub = null, ?array $genre = null): Artist
{
    return Artist::factory()->make([
        'name' => $name,
        'sub_genre_id' => $sub[0] ?? null, 'sub_genre_name' => $sub[1] ?? null,
        'genre_id' => $genre[0] ?? null, 'genre_name' => $genre[1] ?? null,
    ]);
}

it('weights sub-genres and orders by weight then name', function () {
    $vibe = Vibe::for(collect([
        vibeArtist('A', ['s1', 'Punk']),
        vibeArtist('B', ['s2', 'Indie']),
        vibeArtist('C', ['s2', 'Indie']),
        vibeArtist('D', ['s3', 'Folk']),
    ]));

    expect(array_column($vibe, 'name'))->toBe(['Indie', 'Folk', 'Punk'])
        ->and(array_column($vibe, 'weight'))->toBe([2, 1, 1])
        ->and($vibe[0]['id'])->toBe('s2');
});

it('falls back to the genre when the sub-genre is Undefined, Other, or empty', function () {
    $vibe = Vibe::for(collect([
        vibeArtist('A', ['s1', 'Undefined'], ['g1', 'Rock']),
        vibeArtist('B', ['s2', 'other'], ['g1', 'Rock']),
        vibeArtist('C', ['s3', ''], ['g1', 'Rock']),
        vibeArtist('D', null, ['g1', 'Rock']),
    ]));

    expect($vibe)->toHaveCount(1)
        ->and($vibe[0]['id'])->toBe('g1')
        ->and($vibe[0]['weight'])->toBe(4);
});

it('skips artists with no usable classification', function () {
    $vibe = Vibe::for(collect([
        vibeArtist('A', ['s1', 'Undefined'], ['g1', 'Other']),
        vibeArtist('B'),
        vibeArtist('C', null, ['g1', ' ']),
    ]));

    expect($vibe)->toBe([]);
});

it('lists artist names per bucket sorted by name', function () {
    $vibe = Vibe::for(collect([
        vibeArtist('Zebra', ['s1', 'Punk']),
        vibeArtist('apple', ['s1', 'Punk']),
        vibeArtist('Mango', ['s1', 'Punk']),
    ]));

    expect($vibe[0]['artists'])->toBe(['apple', 'Mango', 'Zebra']);
});
