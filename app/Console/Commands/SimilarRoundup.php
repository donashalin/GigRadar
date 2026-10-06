<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\SimilarGigsRoundup;
use App\Services\DiscoverFeed;
use Illuminate\Console\Command;
use Throwable;

class SimilarRoundup extends Command
{
    protected $signature = 'gigradar:similar-roundup';

    protected $description = 'Send opted-in users a roundup of new gigs that match their taste';

    public function handle(DiscoverFeed $feed): int
    {
        $sent = 0;
        $since = now()->subDays(7);

        User::where('notify_similar', true)->chunkById(100, function ($users) use ($feed, $since, &$sent) {
            foreach ($users as $user) {
                try {
                    $items = collect($feed->for($user, $since))->flatMap(fn (array $group) => $group['items'])->values()->all();

                    if ($items === []) {
                        continue;
                    }

                    $notification = new SimilarGigsRoundup($items);
                    $user->notify($notification);
                    $sent++;
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });

        $this->info("Sent {$sent} roundups.");

        return self::SUCCESS;
    }
}
