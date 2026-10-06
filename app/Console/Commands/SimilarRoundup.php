<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\SimilarGigsRoundup;
use App\Services\DiscoverFeed;
use Illuminate\Console\Command;
use Throwable;

class SimilarRoundup extends Command
{
    /** The roundup is not capped by the Discover tab's per-group display limit. */
    private const GROUP_LIMIT = 200;

    protected $signature = 'gigradar:similar-roundup';

    protected $description = 'Send opted-in users a roundup of new gigs that match their taste';

    public function handle(DiscoverFeed $feed): int
    {
        $sent = 0;

        User::where('notify_similar', true)->chunkById(100, function ($users) use ($feed, &$sent) {
            foreach ($users as $user) {
                try {
                    $since = max($user->similar_roundup_at ?? now()->subDays(7), now()->subDays(14));
                    $groups = $feed->for($user, $since, self::GROUP_LIMIT, excludeSeed: true);
                    $items = collect($groups)->flatMap(fn (array $group) => $group['items'])->values()->all();

                    if ($items !== []) {
                        $user->notify(new SimilarGigsRoundup($items));
                        $sent++;
                    }

                    $user->forceFill(['similar_roundup_at' => now()])->save();
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });

        $this->info("Sent {$sent} roundups.");

        return self::SUCCESS;
    }
}
