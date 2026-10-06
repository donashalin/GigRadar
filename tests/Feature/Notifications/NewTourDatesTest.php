<?php

use App\Models\Artist;
use App\Models\Concert;
use App\Models\User;
use App\Notifications\NewTourDates;
use Illuminate\Contracts\Queue\ShouldQueue;
use NotificationChannels\WebPush\WebPushChannel;

function tourNotification(?Artist $artist = null, int $count = 2): NewTourDates
{
    $artist ??= Artist::factory()->create(['name' => 'Fontaines D.C.']);
    $concerts = collect(range(1, $count))->map(fn ($i) => Concert::factory()->for($artist)->create([
        'city' => 'Manchester',
        'venue_name' => 'Apollo',
        'local_date' => now()->addMonths($i)->toDateString(),
    ]));

    return new NewTourDates($artist, $concerts);
}

it('chooses channels from email preference and push subscriptions', function (bool $email, bool $subscribed, array $expected) {
    $user = User::factory()->create(['notify_email' => $email]);
    if ($subscribed) {
        $user->updatePushSubscription('https://push.example/a', 'key', 'token', 'aes128gcm');
    }

    expect(tourNotification()->via($user))->toBe($expected);
})->with([
    'email only' => [true, false, ['mail']],
    'push only' => [false, true, [WebPushChannel::class]],
    'both' => [true, true, ['mail', WebPushChannel::class]],
    'neither' => [false, false, []],
]);

it('builds the mail', function () {
    $notification = tourNotification(count: 7);
    $mail = $notification->toMail(User::factory()->create());

    expect($mail->subject)->toBe('Fontaines D.C. announced new dates')
        ->and($mail->greeting)->toBe('Fontaines D.C. announced new dates')
        ->and($mail->actionUrl)->toStartWith(rtrim(config('app.url'), '/').'/')
        ->and($mail->introLines[0])->toBe('7 new dates, including Manchester – '.now()->addMonth()->format('j M'))
        ->and($mail->introLines)->toHaveCount(6)
        ->and($mail->introLines[1])->toBe(now()->addMonth()->format('j M Y').' — Apollo, Manchester')
        ->and($mail->actionText)->toBe('View dates')
        ->and($mail->actionUrl)->toBe(route('artists.show', $notification->artist->ticketmaster_id))
        ->and($mail->outroLines)->toContain("You're getting this because you follow Fontaines D.C. on GigRadar. Change alerts in Settings.");
});

it('builds the web push message', function () {
    $notification = tourNotification(count: 1);
    $payload = $notification->toWebPush(User::factory()->create(), $notification)->toArray();

    expect($payload['title'])->toBe('Fontaines D.C.')
        ->and($payload['body'])->toStartWith('New date: Manchester')
        ->and($payload['icon'])->toBe('/icons/icon-192.png')
        ->and($payload['badge'])->toBe('/icons/badge-72.png')
        ->and($payload['tag'])->toBe('artist-'.$notification->artist->id)
        ->and($payload['data']['url'])->toBe(route('artists.show', $notification->artist->ticketmaster_id, false));
});

it('is queued after commit', function () {
    $notification = tourNotification();

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->afterCommit)->toBeTrue();
});

it('retries a few times with backoff', function () {
    $notification = tourNotification();

    expect($notification->tries)->toBe(3)
        ->and($notification->backoff)->toBe([60, 300]);
});

it('does not send when there are no concerts', function () {
    $notification = new NewTourDates(Artist::factory()->create(), collect());

    expect($notification->shouldSend(User::factory()->create(), 'mail'))->toBeFalse();
});
