<?php

use App\Models\User;
use App\Notifications\SimilarGigsRoundup;
use Illuminate\Contracts\Queue\ShouldQueue;

function roundupItem(string $artist, string $city, string $date): array
{
    return ['attractionName' => $artist, 'city' => $city, 'localDate' => $date, 'startsAt' => $date.'T19:30:00+00:00'];
}

it('summarises a single gig', function () {
    $n = new SimilarGigsRoundup([roundupItem('Shame', 'Leeds', '2027-03-14')]);

    expect($n->summary())->toBe('New gig that matches your taste: Shame in Leeds – 14 Mar');
});

it('summarises several gigs with the soonest first', function () {
    $n = new SimilarGigsRoundup([
        roundupItem('Later', 'York', '2027-05-01'),
        roundupItem('Shame', 'Leeds', '2027-03-14'),
        roundupItem('Mid', 'Hull', '2027-04-01'),
    ]);

    expect($n->summary())->toBe('3 new gigs that match your taste — Shame in Leeds and 2 more');
});

it('builds the mail with at most ten lines', function () {
    $items = array_map(fn ($i) => roundupItem("Band {$i}", 'Leeds', now()->addDays($i + 1)->toDateString()), range(1, 12));
    $mail = (new SimilarGigsRoundup($items))->toMail(User::factory()->create());

    expect($mail->subject)->toBe('New gigs that match your taste')
        ->and($mail->introLines)->toHaveCount(11)
        ->and($mail->introLines[1])->toBe(now()->addDays(2)->format('j M Y').' — Band 1, Leeds')
        ->and($mail->actionText)->toBe('Open Discover')
        ->and($mail->actionUrl)->toBe(url('/discover'));
});

it('builds the web push message', function () {
    $n = new SimilarGigsRoundup([roundupItem('Shame', 'Leeds', '2027-03-14')]);
    $payload = $n->toWebPush(User::factory()->create(), $n)->toArray();

    expect($payload['title'])->toBe('GigRadar')
        ->and($payload['body'])->toBe($n->summary())
        ->and($payload['tag'])->toBe('similar-roundup')
        ->and($payload['data']['url'])->toBe('/discover');
});

it('is queued after commit with retries', function () {
    $n = new SimilarGigsRoundup([]);

    expect($n)->toBeInstanceOf(ShouldQueue::class)
        ->and($n->afterCommit)->toBeTrue()
        ->and($n->tries)->toBe(3)
        ->and($n->backoff)->toBe([60, 300]);
});

it('does not send when there are no items', function () {
    expect((new SimilarGigsRoundup([]))->shouldSend(User::factory()->create(), 'mail'))->toBeFalse()
        ->and((new SimilarGigsRoundup([roundupItem('A', 'B', '2027-01-01')]))->shouldSend(User::factory()->create(), 'mail'))->toBeTrue();
});
