<?php

use App\Models\User;

it('deletes a user\'s push subscriptions with the user, and only theirs', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $user->updatePushSubscription('https://push.example/a', 'key', 'token', 'aes128gcm');
    $other->updatePushSubscription('https://push.example/b', 'key', 'token', 'aes128gcm');

    $user->delete();

    expect($user->pushSubscriptions()->count())->toBe(0)
        ->and($other->pushSubscriptions()->count())->toBe(1);
});
