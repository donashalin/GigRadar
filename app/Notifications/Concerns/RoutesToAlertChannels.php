<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use NotificationChannels\WebPush\WebPushChannel;

trait RoutesToAlertChannels
{
    /** @return list<string> Mail if the user wants email, web push if they have a subscription. */
    public function via(User $user): array
    {
        $channels = [];

        if ($user->notify_email) {
            $channels[] = 'mail';
        }

        if ($user->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }
}
