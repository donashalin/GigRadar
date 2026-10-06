<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Models\Concert;
use App\Models\User;
use App\Notifications\NewTourDates;
use App\Services\ArtistSync;
use App\Support\RecipientSelector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckDates extends Command
{
    protected $signature = 'gigradar:check-dates';

    protected $description = 'Sync followed artists with Ticketmaster and alert followers about new tour dates';

    public function handle(ArtistSync $sync): int
    {
        $checked = $alertsSent = $failed = 0;

        foreach (Artist::has('followers')->cursor() as $artist) {
            $checked++;

            try {
                $sync->syncEvents($artist);
            } catch (Throwable $e) {
                report($e);
                $failed++;
                // still alert on concerts already pending from earlier page-view syncs
            }

            try {
                $alertsSent += $this->alertFollowers($artist->fresh());
            } catch (Throwable $e) {
                report($e);
                $failed++;
            }
        }

        $this->prunePastConcerts();
        $this->info("Checked {$checked} artists, sent {$alertsSent} alerts, {$failed} failures.");

        return self::SUCCESS;
    }

    /** Alerts followers about the artist's pending concerts and stamps them. Returns notifications sent. */
    private function alertFollowers(Artist $artist): int
    {
        if (! $artist->seeded) {
            return 0;
        }

        return DB::transaction(function () use ($artist) {
            $pending = $artist->concerts()->whereNull('alerted_at')->lockForUpdate()->get();

            $alertable = $pending
                ->where('status', '!=', 'cancelled')
                ->filter(fn (Concert $c) => $this->isUpcoming($c))
                ->sortBy(fn (Concert $c) => ($c->local_date ?? $c->starts_at)->format('Y-m-d H:i'))
                ->values();

            $sent = 0;
            foreach (RecipientSelector::select($alertable, $artist->followers()->get()) as $userId => $concerts) {
                User::find($userId)?->notify(new NewTourDates($artist, $concerts));
                $sent++;
            }

            if ($pending->isNotEmpty()) {
                Concert::whereKey($pending->modelKeys())->update(['alerted_at' => now()]);
            }

            return $sent;
        });
    }

    /** Same rule as Concert::upcoming(). */
    private function isUpcoming(Concert $concert): bool
    {
        return $concert->local_date !== null
            ? $concert->local_date->toDateString() >= today()->toDateString()
            : $concert->starts_at->gte(now()->startOfDay());
    }

    private function prunePastConcerts(): void
    {
        Concert::where(fn ($q) => $q->where('local_date', '<', today()->toDateString())
            ->orWhere(fn ($q) => $q->whereNull('local_date')->where('starts_at', '<', now()->startOfDay())))
            ->delete();
    }
}
