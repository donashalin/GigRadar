<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\RoutesToAlertChannels;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class SimilarGigsRoundup extends Notification implements ShouldQueue
{
    use Queueable, RoutesToAlertChannels;

    public $tries = 3;

    public $backoff = [60, 300];

    private const MAIL_LIMIT = 10;

    /** @param list<array<string, mixed>> $items Flattened DiscoverFeed items. */
    public function __construct(public array $items)
    {
        $this->afterCommit();
    }

    public function shouldSend(User $user, string $channel): bool
    {
        return $this->items !== [];
    }

    /** @return list<array<string, mixed>> Soonest first. */
    private function sorted(): array
    {
        $items = $this->items;
        usort($items, fn (array $a, array $b) => [$this->dateKey($a), $a['startsAt'] ?? ''] <=> [$this->dateKey($b), $b['startsAt'] ?? '']);

        return $items;
    }

    private function dateKey(array $item): string
    {
        return $item['localDate'] ?? substr((string) ($item['startsAt'] ?? ''), 0, 10);
    }

    private function date(array $item, string $format): string
    {
        return Carbon::parse($this->dateKey($item))->format($format);
    }

    public function summary(): string
    {
        $items = $this->sorted();
        $first = $items[0];
        $count = count($items);

        return $count === 1
            ? "New gig that matches your taste: {$first['attractionName']} in {$first['city']} – {$this->date($first, 'j M')}"
            : "{$count} new gigs that match your taste — {$first['attractionName']} in {$first['city']} and ".($count - 1).' more';
    }

    public function toMail(User $user): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('New gigs that match your taste')
            ->greeting('New gigs that match your taste')
            ->line($this->summary());

        foreach (array_slice($this->sorted(), 0, self::MAIL_LIMIT) as $item) {
            $mail->line("{$this->date($item, 'j M Y')} — {$item['attractionName']}, {$item['city']}");
        }

        return $mail
            ->action('Open Discover', url('/discover'))
            ->line("You're getting this weekly roundup because Similar artists is on in GigRadar Settings.");
    }

    public function toWebPush(User $user, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('GigRadar')
            ->body($this->summary())
            ->icon('/icons/icon-192.png')
            ->badge('/icons/badge-72.png')
            ->tag('similar-roundup')
            ->data(['url' => '/discover']);
    }
}
