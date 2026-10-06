<?php

use App\Models\User;
use App\Notifications\TestAlert;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;

it('redirects guests', function () {
    $this->post('/settings/test-alert')->assertRedirect('/login');
});

it('requires a verified email', function () {
    $this->actingAs(User::factory()->unverified()->create())->post('/settings/test-alert')
        ->assertRedirect(route('verification.notice'));
});

it('sends a test alert and flashes success', function () {
    Notification::fake();
    $user = User::factory()->create(['notify_email' => true]);

    $this->actingAs($user)->post('/settings/test-alert')->assertRedirect()->assertSessionHas('success', 'Test alert sent.');

    Notification::assertSentTo($user, TestAlert::class, fn ($n, $channels) => $channels === ['mail']);
});

it('uses web push when the user has a subscription', function () {
    Notification::fake();
    $user = User::factory()->create(['notify_email' => false]);
    $user->updatePushSubscription('https://push.example/a', 'k', 't', 'aes128gcm');

    $this->actingAs($user)->post('/settings/test-alert')->assertSessionHas('success');

    Notification::assertSentTo($user, TestAlert::class, fn ($n, $channels) => $channels === [WebPushChannel::class]);
});

it('flashes an error and sends nothing when no channel is on', function () {
    Notification::fake();
    $user = User::factory()->create(['notify_email' => false]);

    $this->actingAs($user)->post('/settings/test-alert')
        ->assertRedirect()
        ->assertSessionHas('error', 'Turn on email alerts or push on this device first.');

    Notification::assertNothingSent();
});

it('builds the expected mail and push content', function () {
    $user = User::factory()->create();
    $n = new TestAlert;

    expect($n->toMail($user)->subject)->toBe('GigRadar test alert')
        ->and($n->toMail($user)->introLines)->toContain('This is a test — real alerts look like this when an artist you follow announces dates.')
        ->and($n->toWebPush($user, $n)->toArray())->toMatchArray(['title' => 'GigRadar', 'body' => 'Test alert — notifications are working.', 'data' => ['url' => '/settings']]);
});

it('throttles after 3 per minute', function () {
    Notification::fake();
    $user = User::factory()->create(['notify_email' => true]);

    foreach (range(1, 3) as $i) {
        $this->actingAs($user)->post('/settings/test-alert')->assertRedirect();
    }
    $this->actingAs($user)->post('/settings/test-alert')->assertStatus(429);
});
