# GigRadar Plan 3 — Alerts, PWA and Push Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every 6 hours, find new tour dates for followed artists and alert the right people once per artist, by email (log mailer until Plan 4) and web push; make GigRadar an installable PWA with push notifications, a Home-Screen install banner and a "Send a test alert" action.

**Architecture:** `gigradar:check-dates` (scheduled) syncs each followed artist with `ArtistSync`, then alerts on that artist's concerts with `alerted_at IS NULL` inside one DB transaction: a pure `RecipientSelector` (using `NearbyArea`) picks users → concerts, a queued `NewTourDates` notification (`afterCommit`) goes out per user per artist, and the pending concerts are stamped. Web push uses `laravel-notification-channels/webpush` (VAPID). The PWA is a hand-written manifest + `public/sw.js`.

**Tech Stack:** Laravel 12 (scheduler, database queue, notifications), `laravel-notification-channels/webpush`, Pest, Inertia v2 + Vue 3 + TypeScript.

**Spec:** `docs/superpowers/specs/2026-10-05-gigradar-design.md` §4 (Settings rows, install banner), §6–§8 (alert flow, error handling), §9 (testing).

Work directly on `main`. `.env` is never opened, printed or committed; the only permitted `.env` changes are those made by `php artisan webpush:vapid` and the single guarded `VAPID_SUBJECT` append in Task 2.

---

## File map

| File | Responsibility |
|---|---|
| `app/Support/RecipientSelector.php` | Pure: followers + pending concerts → user id ⇒ concerts to alert |
| `app/Support/AlertSummary.php` | Pure: "3 new dates, including Manchester – 14 Mar" |
| `app/Notifications/NewTourDates.php` | Queued mail + web-push notification |
| `app/Notifications/TestAlert.php` | Sent-now sample alert for the current user |
| `app/Console/Commands/CheckDates.php` | `gigradar:check-dates` orchestration |
| `routes/console.php` | Schedule |
| `app/Http/Controllers/PushSubscriptionController.php` | Store/delete this device's subscription |
| `app/Http/Controllers/Settings/TestAlertController.php` | POST send test alert |
| `public/manifest.webmanifest`, `public/sw.js`, `public/offline.html`, `public/icons/*` | PWA |
| `resources/js/composables/usePush.ts` | Browser push subscribe/unsubscribe + support detection |
| `resources/js/components/InstallBanner.vue` | iOS "Add to Home Screen" banner |

---

### Task 1: RecipientSelector and AlertSummary (pure)

**Files:** create `app/Support/RecipientSelector.php`, `app/Support/AlertSummary.php`; tests `tests/Feature/Support/RecipientSelectorTest.php` (needs models/factories, so Feature), `tests/Unit/AlertSummaryTest.php`.

- [ ] `RecipientSelector::select(Collection $concerts, Collection $followers): array` — `$followers` are `User` models loaded via `$artist->followers()` (pivot `alert_scope`). Returns `array<int userId, Collection<Concert>>` (keep the concerts' order), omitting users with nothing to receive. Rules:
  - skip cancelled concerts (defensive; caller also filters);
  - `everywhere` → all concerts;
  - `nearby` → concerts where `NearbyArea::forUser($user)->contains($c->country ?: null, $c->lat, $c->lng) !== false` (null = can't judge → alert, per spec §7).
- [ ] Tests (factories): everywhere follower gets all; nearby radius-mode Leicester user gets Leicester but not Manchester at 50 mi; nearby country-mode GB user gets Glasgow (GB) but not Dublin (IE); nearby user with no home location gets everything; nearby concert with `country = ''` in country mode is included; cancelled never included; user with nothing matching is absent from the result.
- [ ] `AlertSummary::for(Collection $concerts): string` — earliest concert by `local_date ?? starts_at`:
  - 1 concert → `"New date: Manchester – 14 Mar"`
  - N>1 → `"{N} new dates, including Manchester – 14 Mar"`
  - date format `j M` from `local_date` (fallback `starts_at`); if city is `''`, use the venue name.
  - Unit test with plain `Concert` instances (`new Concert([...])`, no DB) — or move to Feature if casts need the app; keep it fast.
- [ ] Commit `feat: recipient selector and alert summary`.

### Task 2: Web push package and NewTourDates notification

**Files:** `composer.json`/lock; published webpush migration + `config/webpush.php`; `app/Models/User.php`; `.env.example`; create `app/Notifications/NewTourDates.php`; tests `tests/Feature/Notifications/NewTourDatesTest.php`, `tests/Feature/Models/UserPushSubscriptionsTest.php`.

- [ ] `composer require laravel-notification-channels/webpush` (if it doesn't resolve for Laravel 12, STOP and report).
- [ ] `php artisan vendor:publish --provider="NotificationChannels\WebPush\WebPushServiceProvider" --tag="migrations"` and `--tag="config"`; `php artisan migrate`.
- [ ] `php artisan webpush:vapid` (writes `VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY` to `.env`). Then exactly: `grep -q '^VAPID_SUBJECT=' .env || echo 'VAPID_SUBJECT=https://github.com/donashalin/GigRadar' >> .env`. Add `VAPID_SUBJECT=https://github.com/donashalin/GigRadar`, `VAPID_PUBLIC_KEY=`, `VAPID_PRIVATE_KEY=` to `.env.example`. Ensure `config/webpush.php` reads `VAPID_SUBJECT` for `vapid.subject`.
- [ ] `User` uses `NotificationChannels\WebPush\HasPushSubscriptions`. Push subscriptions are a morph relation without a FK, so add in `User::booted()`: `static::deleting(fn (User $u) => $u->pushSubscriptions()->delete());`. Test: deleting a user deletes their subscriptions but not another user's.
- [ ] `NewTourDates implements ShouldQueue` (use `Queueable`), constructor `(public Artist $artist, public Collection $concerts)`, `$this->afterCommit()` in the constructor.
  - `via(User $u)`: `mail` if `$u->notify_email`; `WebPushChannel::class` if `$u->pushSubscriptions()->exists()`; may return `[]`.
  - `toMail`: subject `"{Artist} announced new dates"`, greeting none, line `AlertSummary::for(...)`, then up to 5 lines `"{d M Y} — {venue}, {city}"`, action `View dates` → `route('artists.show', $artist->ticketmaster_id)`, footer line "You're getting this because you follow {Artist} on GigRadar. Change alerts in Settings."
  - `toWebPush`: `(new WebPushMessage)->title($artist->name)->body(AlertSummary::for(...))->icon('/icons/icon-192.png')->badge('/icons/badge-72.png')->tag('artist-'.$artist->id)->data(['url' => route('artists.show', $artist->ticketmaster_id, false)])`.
- [ ] Tests: `via` combinations (email on/off × subscriptions yes/no); mail subject, summary line, action URL; web-push payload title/body/data url/tag; notification is `ShouldQueue` and `afterCommit` is true.
- [ ] Commit `feat: web push setup and NewTourDates notification`.

### Task 3: `gigradar:check-dates` command and schedule

**Files:** create `app/Console/Commands/CheckDates.php`; modify `routes/console.php`; test `tests/Feature/Console/CheckDatesTest.php`.

- [ ] Command flow (spec §7):
```php
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
            $alertsSent += $this->alert($artist->fresh());
        } catch (Throwable $e) {
            report($e);
            $failed++;
        }
    }

    $this->prunePastConcerts();
    $this->info("Checked {$checked} artists, sent {$alertsSent} alerts, {$failed} failures.");

    return self::SUCCESS;
}
```
  - `alert(Artist $artist): int` — return 0 if `! $artist->seeded`. In `DB::transaction`: `$pending = $artist->concerts()->whereNull('alerted_at')->lockForUpdate()->get()`; `$alertable = $pending->where('status', '!=', 'cancelled')` filtered to upcoming (same rule as `Concert::upcoming()`: `local_date >= today` or null local_date and `starts_at >= today start`), sorted by `local_date ?? starts_at`; `$recipients = RecipientSelector::select($alertable, $artist->followers()->get())`; for each: `User::find($id)->notify(new NewTourDates($artist, $concerts))` (queued after commit); then `Concert::whereKey($pending->modelKeys())->update(['alerted_at' => now()])`. Return the number of notifications.
  - `prunePastConcerts()`: delete concerts where `local_date < today` OR (`local_date` null AND `starts_at < today start`).
- [ ] Schedule in `routes/console.php`: `Schedule::command('gigradar:check-dates')->everySixHours()->withoutOverlapping()->onOneServer();` (import `Illuminate\Support\Facades\Schedule`).
- [ ] Tests (`Notification::fake()`, `Http::fake()` with the `events` fixture; artists `seeded`):
  - new concert for a seeded artist notifies an `everywhere` follower exactly once, with that concert; `alerted_at` stamped;
  - running the command twice sends nothing the second time;
  - unseeded artist: first run seeds and sends nothing;
  - two new concerts → one notification containing both;
  - nearby follower outside radius gets nothing; concerts still stamped;
  - cancelled pending concert: stamped, nobody notified;
  - Ticketmaster 500 for artist A doesn't stop artist B's alerts; a concert that was already pending for A (alerted_at null, created via factory with `from_seed=false`, `alerted_at=null`) is still alerted despite A's sync failing;
  - artists with no followers aren't fetched (`Http::assertNotSent` for their attractionId);
  - past concerts are pruned; output line contains "Checked 2 artists";
  - the schedule contains the command every six hours (`$this->artisan('schedule:list')` output contains `gigradar:check-dates` and `0 */6 * * *`).
- [ ] Commit `feat: gigradar:check-dates command and schedule`.

### Task 4: Push subscription endpoints, shared VAPID key, test alert

**Files:** create `app/Http/Controllers/PushSubscriptionController.php`, `app/Http/Controllers/Settings/TestAlertController.php`, `app/Notifications/TestAlert.php`; modify `routes/web.php`, `routes/settings.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/types/index.ts`; tests `tests/Feature/PushSubscriptionTest.php`, `tests/Feature/Settings/TestAlertTest.php`.

- [ ] `POST /push-subscriptions` (auth+verified, `throttle:20,1` via a named limiter `push-subscriptions`): validate `endpoint` (required, url, max:500, must start with `https://`), `keys.p256dh` and `keys.auth` (required strings, max:255), `contentEncoding` (nullable, in `aesgcm,aes128gcm`); `$user->updatePushSubscription($endpoint, $p256dh, $auth, $encoding ?? 'aes128gcm')`; return `back()`. `DELETE /push-subscriptions` with `endpoint` → `$user->deletePushSubscription($endpoint)`; `back()`. Tests: stores/updates for the current user only; delete removes only the current user's matching subscription; validation errors; guests redirected.
- [ ] Share `vapidPublicKey` => `config('webpush.vapid.public_key')` and `flash.success` => session `success` in `HandleInertiaRequests` (keep existing shared props); add both to `SharedData` TS type.
- [ ] `TestAlert` notification (not queued; send with `notifyNow`): same channel rules as `NewTourDates`; mail subject "GigRadar test alert", line "This is a test — real alerts look like this when an artist you follow announces dates."; push title "GigRadar", body "Test alert — notifications are working.", data url `/settings`.
- [ ] `POST /settings/test-alert` (auth+verified, named limiter `test-alert` 3/min per user) → if `via()` would be empty, `back()->with('error', 'Turn on email alerts or push on this device first.')`; else `notifyNow(new TestAlert)` and `back()->with('success', 'Test alert sent.')`. Tests: success flash + `Notification::assertSentTo`; no channels → error flash and nothing sent; throttled after 3.
- [ ] Commit `feat: push subscription endpoints and test alert`.

### Task 5: PWA — manifest, icons, service worker, offline page

**Files:** create `public/manifest.webmanifest`, `public/sw.js`, `public/offline.html`, `public/icons/icon-192.png`, `icon-512.png`, `icon-maskable-512.png`, `apple-touch-icon.png` (180×180), `badge-72.png`; modify `resources/views/app.blade.php`, `resources/js/app.ts`.

- [ ] Icons: a simple GigRadar mark — white radar/sound-wave glyph on violet `#7c3aed` (maskable version with ~20% safe padding; badge is a monochrome white glyph on transparent). Generate from an SVG you write to `resources/icons/gigradar.svg` (commit it too) using PHP GD or `qlmanage -t -s <size> -o <dir> file.svg` (macOS) — whatever works; verify each PNG's dimensions with `sips -g pixelWidth -g pixelHeight`.
- [ ] `manifest.webmanifest`: `name` "GigRadar", `short_name` "GigRadar", `start_url` "/dashboard", `scope` "/", `display` "standalone", `background_color` "#ffffff", `theme_color` "#7c3aed", icons (192, 512, maskable 512 with `"purpose": "maskable"`).
- [ ] `app.blade.php` `<head>`: `<link rel="manifest" href="/manifest.webmanifest">`, `<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">`, `<meta name="theme-color" content="#7c3aed">`, `<meta name="apple-mobile-web-app-capable" content="yes">`, `<meta name="mobile-web-app-capable" content="yes">`, `<meta name="apple-mobile-web-app-title" content="GigRadar">`, `<meta name="apple-mobile-web-app-status-bar-style" content="default">`.
- [ ] `public/sw.js` (plain JS, no build step):
  - `install`: cache `['/offline.html', '/icons/icon-192.png']` in cache `gigradar-v1`; `skipWaiting()`.
  - `activate`: delete other caches; `clients.claim()`.
  - `fetch`: only for `request.mode === 'navigate'`: network, falling back to `caches.match('/offline.html')`. Everything else: don't intercept (no data or page caching).
  - `push`: parse `event.data.json()` (laravel webpush payload: `{ title, body, icon, badge, tag, data }`); `self.registration.showNotification(title, { body, icon, badge, tag, data, renotify: true })`.
  - `notificationclick`: close; `url = new URL(event.notification.data?.url ?? '/dashboard', self.location.origin)`; focus an existing client on the same origin and `navigate(url)`, else `clients.openWindow(url)`.
- [ ] `offline.html`: self-contained (inline CSS, no external assets except the icon), "You're offline — GigRadar needs a connection to check tour dates." + Retry button (`location.reload()`), dark-mode aware via `prefers-color-scheme`.
- [ ] `app.ts`: after `initializeTheme()`, register the SW: `if ('serviceWorker' in navigator) { window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js')); }`.
- [ ] Feature test: GET `/manifest.webmanifest` is 200 JSON with `name` GigRadar; `/sw.js` is 200; app layout HTML contains the manifest link and apple-touch-icon.
- [ ] Commit `feat: installable PWA with service worker`.

### Task 6: Push switch, test alert row, install banner (UI)

**Files:** create `resources/js/composables/usePush.ts`, `resources/js/components/InstallBanner.vue`; modify `resources/js/pages/settings/Index.vue`, `resources/js/layouts/app/AppTabsLayout.vue`.

- [ ] `usePush()` returns `{ supported, needsInstall, permission, subscribed, busy, error, enable, disable, refresh }`:
  - `supported` = `'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window`.
  - `isIOS` = `/iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)`; `standalone` = `matchMedia('(display-mode: standalone)').matches || (navigator as any).standalone === true`; `needsInstall` = `isIOS && !standalone`.
  - `refresh()`: `subscribed` from `(await navigator.serviceWorker.ready).pushManager.getSubscription() !== null`.
  - `enable()`: `Notification.requestPermission()`; if not `granted`, set `error` "Notifications are blocked. Allow them in your device settings."; else `reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(vapidPublicKey) })`, then `router.post('/push-subscriptions', { endpoint, keys: { p256dh, auth }, contentEncoding: (PushManager.supportedContentEncodings?.[0] ?? 'aes128gcm') }, { preserveScroll: true, preserveState: true })`. Use `subscription.toJSON()` for keys.
  - `disable()`: get subscription; `router.delete('/push-subscriptions', { data: { endpoint }, preserveScroll: true, preserveState: true })` then `subscription.unsubscribe()`.
  - If `vapidPublicKey` is missing, `supported` is false.
- [ ] Settings `Index.vue` Alerts group (after Email alerts):
  - **Push on this device** row — if `needsInstall`: value "Add to Home Screen first" and tapping shows a short inline explanation (Share → Add to Home Screen → open GigRadar from the icon); else if `!supported`: value "Not supported on this browser"; else an accessible switch (same component/pattern as Email) bound to `subscribed`, calling `enable()`/`disable()`, with `error` shown in the existing `role="alert"` slot.
  - **Send a test alert** row (`action`) → `router.post('/settings/test-alert', {}, { preserveScroll: true })`; show `flash.success` (role=status) / `flash.error` (role=alert) under the group.
- [ ] `InstallBanner.vue`: shown when `needsInstall` and not dismissed (`localStorage['gigradar:install-dismissed']`); fixed above the tab bar (`bottom-[calc(3.5rem+env(safe-area-inset-bottom))]`), violet, text "Add GigRadar to your Home Screen for alerts", sub-text "Tap Share, then Add to Home Screen.", a close button (aria-label "Dismiss"). Mount it in `AppTabsLayout`. Never shown in standalone mode or on non-iOS.
- [ ] `npm run build`, `npx vue-tsc --noEmit 2>&1 | grep -v TS2688` clean; `php artisan test` green.
- [ ] Commit `feat: push switch, test alert and install banner`.

### Task 7: Run the worker and scheduler locally; manual check

- [ ] Start detached alongside the existing server: `nohup php artisan queue:work --sleep=3 --tries=3 >> storage/logs/queue.log 2>&1 &` and `nohup php artisan schedule:work >> storage/logs/schedule.log 2>&1 &` (from the repo root; `disown`).
- [ ] iPhone: Share → Add to Home Screen → open from the icon → Settings → Push on this device → Allow → Send a test alert → notification arrives → tap opens Settings.
- [ ] `php artisan gigradar:check-dates` → output summary; any email alerts appear in `storage/logs/laravel.log`.
- [ ] `git push origin main`.
