<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function subPayload(array $override = []): array
{
    return array_replace_recursive([
        'endpoint' => 'https://push.example/abc',
        'keys' => ['p256dh' => 'pub-key', 'auth' => 'auth-token'],
        'contentEncoding' => 'aes128gcm',
    ], $override);
}

it('redirects guests', function () {
    $this->post('/push-subscriptions', subPayload())->assertRedirect('/login');
    $this->delete('/push-subscriptions', ['endpoint' => 'https://push.example/abc'])->assertRedirect('/login');
});

it('stores a subscription for the current user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/push-subscriptions', subPayload())->assertRedirect();

    expect($user->pushSubscriptions()->count())->toBe(1);
    $sub = $user->pushSubscriptions()->first();
    expect($sub->endpoint)->toBe('https://push.example/abc')
        ->and($sub->public_key)->toBe('pub-key')
        ->and($sub->auth_token)->toBe('auth-token');
});

it('updates an existing subscription instead of duplicating', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/push-subscriptions', subPayload());
    $this->actingAs($user)->post('/push-subscriptions', subPayload(['keys' => ['p256dh' => 'new-key']]));

    expect($user->pushSubscriptions()->count())->toBe(1)
        ->and($user->pushSubscriptions()->first()->public_key)->toBe('new-key');
});

it('defaults the content encoding to aes128gcm', function () {
    $user = User::factory()->create();
    $payload = subPayload();
    unset($payload['contentEncoding']);

    $this->actingAs($user)->post('/push-subscriptions', $payload)->assertRedirect();

    expect($user->pushSubscriptions()->first()->content_encoding->value)->toBe('aes128gcm');
});

it('moves a subscription to whichever user registers the endpoint last', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $this->actingAs($a)->post('/push-subscriptions', subPayload());
    $this->actingAs($b)->post('/push-subscriptions', subPayload());

    expect($a->pushSubscriptions()->count())->toBe(0)->and($b->pushSubscriptions()->count())->toBe(1);
});

it('deletes only the current users matching subscription', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $a->updatePushSubscription('https://push.example/a', 'k', 't', 'aes128gcm');
    $a->updatePushSubscription('https://push.example/keep', 'k', 't', 'aes128gcm');
    $b->updatePushSubscription('https://push.example/b', 'k', 't', 'aes128gcm');

    $this->actingAs($a)->delete('/push-subscriptions', ['endpoint' => 'https://push.example/a'])->assertRedirect();
    $this->actingAs($a)->delete('/push-subscriptions', ['endpoint' => 'https://push.example/b'])->assertRedirect();

    expect($a->pushSubscriptions()->pluck('endpoint')->all())->toBe(['https://push.example/keep'])
        ->and($b->pushSubscriptions()->count())->toBe(1);
});

it('validates the subscription payload', function (array $override, string $field) {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/push-subscriptions', subPayload($override))->assertSessionHasErrors($field);
    expect($user->pushSubscriptions()->count())->toBe(0);
})->with([
    'http endpoint' => [['endpoint' => 'http://push.example/abc'], 'endpoint'],
    'not a url' => [['endpoint' => 'nope'], 'endpoint'],
    'too long' => [['endpoint' => 'https://push.example/'.str_repeat('a', 500)], 'endpoint'],
    'missing p256dh' => [['keys' => ['p256dh' => '']], 'keys.p256dh'],
    'missing auth' => [['keys' => ['auth' => '']], 'keys.auth'],
    'bad encoding' => [['contentEncoding' => 'rot13'], 'contentEncoding'],
]);

it('requires an endpoint to delete', function () {
    $this->actingAs(User::factory()->create())->delete('/push-subscriptions', [])->assertSessionHasErrors('endpoint');
});

it('throttles subscription writes at 20 per minute', function () {
    $user = User::factory()->create();
    foreach (range(1, 20) as $i) {
        $this->actingAs($user)->post('/push-subscriptions', subPayload())->assertRedirect();
    }
    $this->actingAs($user)->post('/push-subscriptions', subPayload())->assertStatus(429);
});

it('shares the VAPID public key and success flash', function () {
    config(['webpush.vapid.public_key' => 'BPublicKey']);

    $this->actingAs(User::factory()->create())->withSession(['success' => 'Yay'])->get('/settings')
        ->assertInertia(fn (Assert $page) => $page->where('vapidPublicKey', 'BPublicKey')->where('flash.success', 'Yay'));
});
