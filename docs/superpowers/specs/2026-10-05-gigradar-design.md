# GigRadar — Design Spec

**Date:** 2026-10-05
**Status:** Approved design, pending implementation plan
**Type:** Personal side project — mobile-first Progressive Web App (PWA)
**Supersedes:** the earlier native iOS / Firebase design (see git history). Dropped because the development Mac (2017 Intel MacBook Pro, macOS 13, max Xcode 15.4) cannot build for the App Store or install on current iOS versions.

## 1. Purpose

GigRadar alerts fans when their favourite artists announce new concert dates or locations. Users register, search for artists, follow them, browse upcoming concerts, and receive email and/or web push alerts when new tour dates are released.

## 2. Decisions

| Area | Decision |
|---|---|
| App type | Mobile-first PWA, installable to the iPhone/Android Home Screen |
| Backend | Latest Laravel, MySQL, queues (database driver), scheduler |
| Frontend | Official Laravel Vue starter kit (Inertia + Vue 3 + TypeScript + Tailwind) |
| PWA | `vite-plugin-pwa` (manifest, icons, service worker) |
| Concert data | Ticketmaster Discovery API (free key, 5,000 calls/day, 5 req/sec) |
| Auth | Email + password (starter kit auth): registration, email verification, password reset |
| Alert channels | Email (Resend) and Web Push (`laravel-notification-channels/webpush`, VAPID). No SMS in v1. |
| Alert rules | Per-artist choice: `everywhere` or `nearby` (within user's radius of home location) |
| Geocoding | OpenStreetMap Nominatim for typed cities (cached); browser Geolocation API for "use my location" |
| Testing | Pest |
| Hosting | Decided at deploy time (Forge-managed VPS or Laravel Cloud); code is host-agnostic |

## 3. Architecture

```
Phone browser / Home-Screen PWA (Vue 3 + Inertia)
        │  HTTPS
        ▼
Laravel app ───────────────────────────────► Ticketmaster Discovery API
  ├─ Auth (starter kit)
  ├─ Controllers: Search, Artist, Follow, Dashboard, Settings, PushSubscription
  ├─ Scheduler: gigradar:check-dates (every 6 hours)
  ├─ Queue worker: delivers notifications
  └─ Notifications: NewTourDates → mail, webpush
        │                       │
        ▼                       ▼
   Resend (email)      Browser push services (Apple / Google / Mozilla)

MySQL: users, artists, follows, concerts, push_subscriptions, jobs, cache
```

- The browser never calls Ticketmaster; the API key lives in `.env` (`TICKETMASTER_API_KEY`) and is used only server-side.
- Ticketmaster responses are stored in MySQL / cache so pages render from the database and API usage stays low.

## 4. Screens

Mobile-first layout with a fixed bottom tab bar: **My Artists**, **Search**, **Settings**. Artist Detail is a full-screen page reached from Search or My Artists. Auth pages come from the starter kit, restyled mobile-first.

1. **Welcome / Login / Register / Forgot password** — starter kit pages. Unverified users are redirected to the verify-email notice.
2. **Search** — text input, 300 ms debounce, minimum 2 characters; results show image, name, and Follow button.
3. **Artist Detail** — upcoming events (date, venue, city, country, status badge if cancelled/postponed; "Get tickets" opens `ticket_url` in a new tab). Follow/Unfollow button. Alert scope toggle (`Everywhere` / `Near me`), shown only when following. Viewing updates the follow's `last_seen_at`.
4. **My Artists** (home, `/dashboard`) — followed artists sorted by name, with a "New" badge where any event's `first_seen_at > follows.last_seen_at`. Section "Upcoming near you": next 10 events across followed artists within the user's radius (hidden if no home location).
5. **Settings**
   - Home location: type a city (Nominatim lookup) or "Use my current location" (Geolocation API).
   - Radius: 25 / 50 / 100 / 250 miles (default 50).
   - Alert channels: Email on/off; "Push on this device" on/off (requests browser permission, stores/removes this device's subscription).
   - Sign out; Delete account (confirmation step; removes user, follows, push subscriptions).

**Install banner:** on iOS Safari when not running standalone (`navigator.standalone !== true`), show a dismissible banner: "Add GigRadar to your Home Screen for instant alerts" with Share → Add to Home Screen instructions. Dismissal remembered in `localStorage`. On iOS, the push toggle is shown only when running standalone (iOS only supports web push for Home-Screen apps).

**Empty states:** My Artists with no follows → prompt linking to Search. Artist with no events → "No upcoming dates — we'll alert you when they're announced."

### Out of scope for v1
SMS, Google/Apple sign-in, Spotify/Apple Music import, native app store builds, on-sale/presale reminders, sharing, calendar view, multiple data sources, alerts on cancellations/postponements.

## 5. Data model (MySQL)

```
users
  id, name, email (unique), password, email_verified_at, remember_token, timestamps
  home_location_name  varchar null
  home_lat            decimal(10,7) null
  home_lng            decimal(10,7) null
  radius_miles        smallint unsigned default 50   -- 25 | 50 | 100 | 250
  notify_email        boolean default true
  notify_push         boolean default true

artists
  id, ticketmaster_id varchar unique, name, image_url null,
  seeded boolean default false, last_checked_at timestamp null, timestamps

follows
  id, user_id FK cascade, artist_id FK cascade,
  alert_scope enum('everywhere','nearby') default 'everywhere',
  last_seen_at timestamp, timestamps
  unique(user_id, artist_id)

concerts                -- model Concert (avoids clashing with Laravel's Event facade)
  id, artist_id FK cascade, ticketmaster_id varchar, name,
  starts_at datetime (UTC), venue_name, city, country,
  lat decimal(10,7) null, lng decimal(10,7) null, ticket_url,
  status enum('onsale','offsale','cancelled','postponed','rescheduled'),
  first_seen_at timestamp
  alerted_at timestamp null       -- null = followers not yet alerted; set on seeding and after alerting
  timestamps
  unique(artist_id, ticketmaster_id)   -- one Ticketmaster event can list several artists
  index(artist_id, starts_at)

push_subscriptions   -- migration published by laravel-notification-channels/webpush
```

**Relationships:** `User belongsToMany Artist` via `follows` (pivot model `Follow` with `alert_scope`, `last_seen_at`); `Artist belongsToMany User` as `followers`; `Artist hasMany Concert`; `User` uses `HasPushSubscriptions`.

**Authorisation:** all app routes require `auth` + `verified`. Follows and settings are always scoped to `auth()->user()`; there are no routes taking another user's ID. Artists and events are readable by any verified user.

## 6. Backend components

| Class | Responsibility | Depends on |
|---|---|---|
| `App\Services\Ticketmaster\TicketmasterClient` | Only class aware of Ticketmaster. `searchAttractions(string)`, `upcomingEvents(string $attractionId)`. Maps JSON to DTOs (`ArtistData`, `ConcertData`); also `attraction(string $id)` for single-artist lookup. Throttles to ≤ 4 req/sec. | `Http` facade, config |
| `App\Services\ArtistSync` | `syncEvents(Artist): Collection<Concert> $new` — fetches events, upserts concerts, sets `first_seen_at` on unseen IDs, updates changed fields, sets `seeded = true` and `last_checked_at`. | `TicketmasterClient` |
| `App\Services\ArtistResolver` | `resolve(string $ticketmasterId): Artist` — finds locally or creates via `attraction()` lookup. | `TicketmasterClient` |
| `App\Support\ConcertDiffer` | Pure: given stored IDs and fetched DTOs, returns new / updated sets. | — |
| `App\Support\Geo` | Pure: `distanceMiles(lat1, lng1, lat2, lng2)` (haversine). | — |
| `App\Support\RecipientSelector` | Pure: given new events and followers (with pivot + location), returns `user → events` to alert. | `Geo` |
| `App\Notifications\NewTourDates` | Queued. `via()` returns enabled channels (`mail` if `notify_email`; `WebPushChannel` if `notify_push` and user has subscriptions). Builds message. | — |
| `App\Console\Commands\CheckDates` | `gigradar:check-dates` orchestration (§7). | `ArtistSync`, `RecipientSelector` |
| `App\Services\Geocoder` | Nominatim lookup for typed city, cached 30 days, with app User-Agent. | `Http`, cache |

### Controllers / routes
- `GET /search?q=` — Inertia page; results via `TicketmasterClient::searchAttractions`, cached 10 min per normalised query.
- `GET /artists/{ticketmasterId}` — upserts the artist; if `last_checked_at` older than 6 hours or null, calls `ArtistSync::syncEvents` and marks `seeded = true` **without alerting**; renders events; updates `last_seen_at` if followed.
- `POST /artists/{ticketmasterId}/follow` (resolves the artist; seeds concerts without alerting if not yet seeded), `DELETE …/follow`, `PATCH …/follow` (alert_scope).
- `GET /dashboard` — My Artists.
- `GET/PATCH /settings`, `DELETE /settings/account`.
- `POST /push-subscriptions`, `DELETE /push-subscriptions` — store/remove the current device's subscription.

## 7. Alert flow — `gigradar:check-dates`

Scheduled `everySixHours()`, `withoutOverlapping()`, timezone Europe/London.

Alerts are driven by `concerts.alerted_at`, not by which sync first saw a concert. This means a page view or follow that syncs an artist can never "use up" an alert.

1. `Artist::has('followers')->cursor()`.
2. For each artist: `ArtistSync::syncEvents($artist)`. Any sync (scheduler, page view, follow) stores unseen concerts; if the artist was **not yet seeded**, those concerts are stamped `alerted_at = now` (existing dates never alert); otherwise they are stored with `alerted_at = null` (pending alert). On a Ticketmaster error, log and skip the artist (§8); catch any `Throwable` per artist so one failure never aborts the run.
3. Pending concerts for the artist = `seeded` artist's concerts with `alerted_at IS NULL`. Stamp pending concerts whose status is `cancelled` as alerted without notifying. If none remain, continue.
4. Load followers with pivot and location. `RecipientSelector`:
   - `everywhere` → alert.
   - `nearby` → alert if `Geo::distanceMiles(venue, home) ≤ radius_miles`; if user has no home location or event has no coordinates, alert.
5. For each selected user: `$user->notify(new NewTourDates($artist, $concertsForUser))` — **one notification per artist per user per run**.
   - Subject/title: "{Artist} announced new dates"
   - Body: "{N} new date(s), including {city} – {d M}" (earliest qualifying concert)
   - Link: `/artists/{ticketmasterId}`
6. Stamp all pending concerts for the artist `alerted_at = now` (whether or not anyone was in range).
   Stamp and queue notifications in the same DB transaction (queued notifications use `afterCommit`), so a crash between the two can't cause a repeat alert.
7. Delete concerts with `starts_at` before today.

## 8. Error handling

- **Ticketmaster error / 429 in the command:** log, skip the artist, leave `last_checked_at` unchanged (retried next run). Never abort the run.
- **Ticketmaster error on a page:** render stored data if any, plus a "Couldn't refresh — showing saved dates" notice; if none, an inline error with Retry.
- **Expired push subscription** (404/410 from push service): delete it (package `expired` handling).
- **Email failure:** queued job retries (3 tries, backoff).
- **Push permission denied:** Settings explains how to re-enable in browser/iOS settings.
- **Nominatim failure / no match:** inline "Couldn't find that place" message.
- **Quota:** at 4 runs/day, ~1,000 followed artists ≈ 4,000 calls/day. If exceeded later, check less-followed artists less often (not built in v1).

## 9. Testing (Pest)

- **Unit:** `ConcertDiffer`, `Geo::distanceMiles`, `RecipientSelector` (scope rules, fallbacks, cancelled excluded), `NewTourDates` message text and channel selection.
- **Feature:**
  - `TicketmasterClient` mapping against recorded JSON fixtures via `Http::fake()`.
  - `gigradar:check-dates` with `Http::fake()` + `Notification::fake()`: unseeded artist sends nothing; new event notifies the right users exactly once per artist; nearby users outside radius not notified.
  - Viewing an artist seeds events without notifying.
  - Follow/unfollow/scope endpoints; settings update; account deletion cascades.
  - Authorisation: guests redirected; unverified users blocked.
- **Manual:** web push on a physical iPhone as a Home-Screen PWA over HTTPS (local via an `ngrok`/`expose` tunnel, or the deployed site).

## 10. Build order

1. Laravel Vue starter kit scaffold (`laravel new gigradar --vue`), Pest, migrations, models, factories.
2. `TicketmasterClient` + Search page.
3. Artist Detail, `ArtistSync`, follow/unfollow, alert scope.
4. My Artists (new badges, upcoming near you).
5. Settings: location (Geocoder + Geolocation), radius, channels, account deletion.
6. `gigradar:check-dates`, `RecipientSelector`, `NewTourDates` via email.
7. PWA (manifest, icons, service worker, install banner) + web push.
8. Deploy: server, domain, HTTPS, queue worker, scheduler cron, Resend domain verification.

## 11. Prerequisites

- `composer self-update` (local Composer is 2.2.6); PHP 8.3, Node 20.19, MySQL 8.4 already installed.
- Ticketmaster developer account → Consumer Key in `.env`.
- Resend account + a domain you own (for sending address and the app's HTTPS URL).
- VAPID keys: `php artisan webpush:vapid`.
- For on-phone push testing before deploy: `ngrok` or `expose`.
