<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Models\Concert;
use App\Notifications\NewTourDates;
use App\Services\ArtistSync;
use App\Support\RecipientSelector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            $artistFailed = false;

            try {
                $sync->syncEvents($artist);
            } catch (Throwable $e) {
                report($e);
                $artistFailed = true;
                // still alert on concerts already pending from earlier page-view syncs
            }

            try {
                $alertsSent += $this->alertFollowers($artist->fresh());
            } catch (Throwable $e) {
                report($e);
                $artistFailed = true;
            }

            $failed += $artistFailed ? 1 : 0;
        }

        try {
            $this->prunePastConcerts();
        } catch (Throwable $e) {
            report($e);
            $failed++;
        }

        $this->info("Checked {$checked} ".Str::plural('artist', $checked).", sent {$alertsSent} ".Str::plural('alert', $alertsSent).", {$failed} ".Str::plural('failure', $failed).'.');

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

            $alertable = $artist->concerts()
                ->whereKey($pending->modelKeys())
                ->where('status', '!=', 'cancelled')
                ->upcoming()
                ->get();

            $followers = $artist->followers()->get()->keyBy('id');

            $sent = 0;
            foreach (RecipientSelector::select($alertable, $followers) as $userId => $concerts) {
                $user = $followers[$userId];
                $notification = new NewTourDates($artist, $concerts);
                $user->notify($notification);

                if ($notification->via($user) !== []) {
                    $sent++;
                }
            }

            if ($pending->isNotEmpty()) {
                Concert::whereKey($pending->modelKeys())->update(['alerted_at' => now()]);
            }

            return $sent;
        });
    }

    private function prunePastConcerts(): void
    {
        Concert::where(fn ($q) => $q->where('local_date', '<', today()->toDateString())
            ->orWhere(fn ($q) => $q->whereNull('local_date')->where('starts_at', '<', now()->startOfDay())))
            ->delete();
    }
}
