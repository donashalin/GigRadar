<?php

namespace App\Notifications;

use App\Models\Artist;
use App\Models\Concert;
use App\Models\User;
use App\Notifications\Concerns\RoutesToAlertChannels;
use App\Support\AlertSummary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use NotificationChannels\WebPush\WebPushMessage;

class NewTourDates extends Notification implements ShouldQueue
{
    use Queueable, RoutesToAlertChannels;

    public $tries = 3;

    public $backoff = [60, 300];

    /** @param Collection<int, Concert> $concerts */
    public function __construct(public Artist $artist, public Collection $concerts)
    {
        $this->afterCommit();
    }

    public function shouldSend(User $user, string $channel): bool
    {
        return $this->concerts->isNotEmpty();
    }

    public function toMail(User $user): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("{$this->artist->name} announced new dates")
            ->greeting("{$this->artist->name} announced new dates")
            ->line(AlertSummary::for($this->concerts));

        foreach ($this->concerts->take(5) as $concert) {
            $date = ($concert->local_date ?? $concert->starts_at)->format('j M Y');
            $place = collect([$concert->venue_name, $concert->city])->filter()->implode(', ');
            $mail->line("{$date} — {$place}");
        }

        return $mail
            ->action('View dates', route('artists.show', $this->artist->ticketmaster_id))
            ->line("You're getting this because you follow {$this->artist->name} on GigRadar. Change alerts in Settings.");
    }

    public function toWebPush(User $user, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->artist->name)
            ->body(AlertSummary::for($this->concerts))
            ->icon('/icons/icon-192.png')
            ->badge('/icons/badge-72.png')
            ->tag('artist-'.$this->artist->id)
            ->data(['url' => route('artists.show', $this->artist->ticketmaster_id, false)]);
    }
}
