<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\RoutesToAlertChannels;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/** Sent immediately (notifyNow) so a user can check their alert channels work. */
class TestAlert extends Notification
{
    use RoutesToAlertChannels;

    public function toMail(User $user): MailMessage
    {
        return (new MailMessage)
            ->subject('GigRadar test alert')
            ->line('This is a test — real alerts look like this when an artist you follow announces dates.');
    }

    public function toWebPush(User $user, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('GigRadar')
            ->body('Test alert — notifications are working.')
            ->icon('/icons/icon-192.png')
            ->badge('/icons/badge-72.png')
            ->tag('test-alert')
            ->data(['url' => '/settings']);
    }
}
