# GigRadar Plan 5 — Similar Vibe (Discover + weekly roundup) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Discover tab showing upcoming gigs, in the user's area, by artists in the same Ticketmaster sub-genres as the artists they follow; "Not interested" to hide artists permanently; an opt-in Friday roundup notification of new matching gigs.

**Architecture:** Artists gain Ticketmaster classifications. A pure `Vibe` class turns a user's followed artists into weighted classification ids. `gigradar:discover` (daily) fetches events per (classification, country) into a separate `discovery_events` table — never mixed with `concerts`, so it can't trigger followed-artist alerts. A `DiscoverFeed` service builds the per-user feed (area via `NearbyArea`, excluding followed/dismissed/cancelled) and is reused by the Discover page and by `gigradar:similar-roundup`.

**Spec:** `docs/superpowers/specs/2026-10-05-gigradar-design.md` §13 (and §4–§7 for existing patterns).

**Tech Stack:** Laravel 12, Pest, Inertia v2 + Vue 3 + TS, Tailwind, lucide-vue-next.

Work directly on `main`. Never open, print, edit or commit `.env`.

---

### Task 1: Artist classifications

**Files:** migration `database/migrations/2026_10_07_000001_add_classifications_to_artists_table.php`; `app/Services/Ticketmaster/ArtistData.php`, `TicketmasterClient.php`; `app/Services/ArtistResolver.php`; `app/Models/Artist.php`; new `app/Console/Commands/BackfillArtistClassifications.php`; fixtures + tests.

- [ ] Migration: nullable strings `genre_id`, `genre_name`, `sub_genre_id`, `sub_genre_name` on `artists`.
- [ ] `ArtistData` gains `?string $genreId = null, ?string $genreName = null, ?string $subGenreId = null, ?string $subGenreName = null` (appended, defaulted, so existing constructions still work). `toArtist()` reads `classifications[0].genre.{id,name}` and `.subGenre.{id,name}` defensively (strings only).
- [ ] Fixtures: add `classifications` to the Fontaines entry in `attractions-search.json` and `attraction.json`: genre `{ "id": "KnvZfZ7vAvv", "name": "Alternative" }`, subGenre `{ "id": "KZazBEonSMnZfZ7vAde", "name": "Alternative Rock" }`.
- [ ] `ArtistResolver`: when creating, store the four fields; when an existing artist has all four null and we fetch it anyway, don't add extra calls — the backfill command covers existing rows.
- [ ] Also fill classifications when Search results are followed: `FollowController::store` resolves via `ArtistResolver`, which now stores them on create — no extra change. Add `Artist` fillable entries.
- [ ] `gigradar:backfill-artist-classifications`: for artists with `genre_id` null, call `attraction()` and save the four fields; per-artist try/catch; prints `Updated N of M artists.`
- [ ] Tests: client mapping (present / missing / malformed classifications); resolver stores them; backfill command (success, outage continues).
- [ ] Commit `feat: store Ticketmaster classifications on artists`.

### Task 2: Vibe (pure)

**Files:** `app/Support/Vibe.php`; `tests/Feature/Support/VibeTest.php`.

- [ ] `Vibe::for(Collection $artists): array` → list of `['id' => string, 'name' => string, 'weight' => int, 'artists' => list<string> names]`, sorted by weight desc then name. Per artist use the sub-genre unless its name is null/'' or one of `Undefined`, `Other` (case-insensitive), in which case use the genre under the same rule; skip the artist if neither is usable.
- [ ] Tests: weighting and ordering; Undefined/Other fallback to genre; both unusable → skipped; artist names listed per bucket (sorted by name).
- [ ] Commit `feat: vibe from followed artists`.

### Task 3: Discovery events and `gigradar:discover`

**Files:** migration `2026_10_07_000002_create_discovery_events_table.php`; `app/Models/DiscoveryEvent.php` + factory; `TicketmasterClient::eventsByClassification(string $classificationId, string $countryCode): list<DiscoveryEventData>` (new DTO `app/Services/Ticketmaster/DiscoveryEventData.php` = `ConcertData` fields + `attractionId`, `attractionName`, `attractionImageUrl`); `app/Console/Commands/Discover.php`; `routes/console.php`; tests.

- [ ] Table per spec §13 (`discovery_events`). Model casts match `Concert` (`starts_at` datetime, `local_date` date:Y-m-d, `first_seen_at` datetime, lat/lng float) and reuse an `upcoming()` scope with the same rule as `Concert::upcoming()` (extract a shared trait `App\Models\Concerns\HasUpcomingScope` used by both).
- [ ] Client: `GET /events.json?classificationId=…&countryCode=…&classificationName=music&sort=date,asc&size=200`; map each event like `toConcert()` plus the first attraction in `_embedded.attractions` (skip events with no attraction id/name); `safeUrl` on image and ticket URLs; reuse the existing private helpers.
- [ ] `gigradar:discover`: collect distinct pairs (classification id from `Vibe::for($user->artists)` for every user, × `home_country_code`) for users with a non-null `home_country_code`; for each pair fetch and upsert by (`classification_id`, `ticketmaster_event_id`) — insert sets `first_seen_at = now()`, update refreshes other fields only; per-pair try/catch (report, continue); then delete rows that are no longer upcoming. Output `Fetched N classifications, stored X new gigs, F failures.`
- [ ] Schedule: `Schedule::command('gigradar:discover')->dailyAt('04:00')->timezone('Europe/London')->withoutOverlapping()->onOneServer();`
- [ ] Tests (`Http::fake` with a new `tests/Fixtures/ticketmaster/discovery-events.json` fixture containing 3 events across 2 attractions, one cancelled, one with no attraction): pairs deduplicated across users; users without country skipped; first run inserts with `first_seen_at`; second run doesn't change `first_seen_at`; past rows pruned; one pair failing doesn't stop others; schedule registered at 04:00 Europe/London.
- [ ] Commit `feat: discovery events and gigradar:discover`.

### Task 4: Dismissed artists and the feed service

**Files:** migration `2026_10_07_000003_create_dismissed_artists_table.php`; `app/Models/DismissedArtist.php`; `User::dismissedArtists()` hasMany; `app/Services/DiscoverFeed.php`; tests.

- [ ] Table per spec §13. Following an artist deletes the user's dismissal for that `ticketmaster_id` (in `FollowController::store`, after attach) — test it.
- [ ] `DiscoverFeed::for(User $user, ?CarbonInterface $firstSeenSince = null): array` → list of groups `['id','name','artists' => [followed names], 'items' => list<item>]` in vibe order, where items are the soonest upcoming, non-cancelled discovery event per attraction in that classification, inside `NearbyArea::forUser($user)` (`contains(...) !== false`; requires `isConfigured()` — else return `[]`), excluding attractions the user follows (by `ticketmaster_id`) or dismissed; at most 10 items per group sorted by date; an attraction appears only in its highest-weight group; empty groups omitted. Item: `eventId` (discovery_events.id), `attractionId`, `attractionName`, `imageUrl`, `localDate`, `startsAt`, `venueName`, `city`, `distanceMiles` (int|null), `ticketUrl`. With `$firstSeenSince`, only events with `first_seen_at >= $firstSeenSince` are considered (used by the roundup).
- [ ] Tests: area filtering (country and radius modes); followed + dismissed excluded; cancelled excluded; per-attraction de-dup across groups; limit 10; order; unconfigured area → `[]`; `firstSeenSince` filter.
- [ ] Commit `feat: dismissed artists and discover feed`.

### Task 5: Discover page, Not interested, Hidden artists

**Files:** `app/Http/Controllers/DiscoverController.php`, `app/Http/Controllers/DismissedArtistController.php`; routes; `resources/js/pages/Discover.vue`, `resources/js/pages/settings/HiddenArtists.vue`; `BottomTabBar.vue`; `settings/Index.vue`; `SettingsController` (hidden count); tests.

- [ ] `GET /discover` (auth+verified) → `Discover` with `groups`, `hasFollows`, `hasArea` (= `NearbyArea::isConfigured()`).
- [ ] `POST /dismissed-artists` `{attraction_ticketmaster_id (alphanumeric, max 64), attraction_name (max 255)}` → firstOrCreate for the user; `back()`. `DELETE /dismissed-artists/{attractionId}` → delete the user's row; `back()`. Throttle with a named limiter `dismissals` (60/min per user). Tests incl. scoping to the current user.
- [ ] `GET /settings/hidden-artists` → `settings/HiddenArtists` with `artists` [{attractionId, name}] sorted by name. Settings Index Alerts group gets **Hidden artists ›** (value = count) and the **Similar artists** switch (Task 6 wires the column; render it there).
- [ ] `Discover.vue` (title "Discover", grouped background): empty states — no follows: "Follow a few artists to get recommendations." + link to Search; no area: "Set your home location to see gigs near you." + link to `/settings/location`; nothing found: "No matching gigs near you yet — check back soon." Groups: header = classification name, sub-line "Because you follow A, B" (max 3 names + "and N more"); rows: image (or placeholder), artist name (link to `/artists/{attractionId}`), "Sat 14 Mar · Leeds" (+ "· 12 mi" when known) using `formatConcertDate`, a **Follow** button (POST `/artists/{id}/follow`, `preserveScroll`, `only` the page props; on success the row disappears because the feed excludes followed artists), and a **Not interested** button (lucide `EyeOff`, `aria-label="Not interested in {name}"`) that optimistically hides the row and shows an inline **Undo** snackbar for 5s (Undo → `DELETE`). Pending guards, `min-h-11`, focus-visible outlines, dark variants.
- [ ] `HiddenArtists.vue` (back to Settings): grouped list with a **Show again** action per row (DELETE), empty state "You haven't hidden any artists."
- [ ] Bottom tab bar: 4 tabs — My Artists, Discover (lucide `Sparkles`, matches `/discover`), Search, Settings (`grid-cols-4`).
- [ ] Commit `feat: discover tab, not interested and hidden artists`.

### Task 6: Weekly roundup

**Files:** migration `2026_10_07_000004_add_notify_similar_to_users_table.php`; `User` ($attributes, casts, TS type); `AlertSettingsController::update` (`notify_similar` sometimes|boolean); `SettingsController` props; `settings/Index.vue` switch; `app/Notifications/SimilarGigsRoundup.php`; `app/Console/Commands/SimilarRoundup.php`; schedule; tests.

- [ ] `notify_similar` boolean default false; Settings → Alerts **Similar artists** switch (row sub-text "Weekly roundup of gigs that match your taste") patches `{notify_similar}` like the Email switch.
- [ ] `SimilarGigsRoundup` (queued, `afterCommit`, `RoutesToAlertChannels`, `tries`/`backoff` like `NewTourDates`): constructor `(array $items)` (flattened feed items); summary: 1 → "New gig that matches your taste: Shame in Leeds – 14 Mar"; N → "{N} new gigs that match your taste — Shame in Leeds and {N-1} more" (first = soonest). Mail: subject "New gigs that match your taste", up to 10 lines "{date} — {artist}, {city}", action "Open Discover" → absolute `/discover`. Push: title "GigRadar", body = summary, `tag` "similar-roundup", data url `/discover`. `shouldSend` false when items empty.
- [ ] `gigradar:similar-roundup`: for users with `notify_similar` (chunked), `DiscoverFeed::for($user, now()->subDays(7))`, flatten; if non-empty notify. Output `Sent N roundups.` Per-user try/catch.
- [ ] Schedule: `Schedule::command('gigradar:similar-roundup')->weeklyOn(5, '18:00')->timezone('Europe/London')->withoutOverlapping()->onOneServer();`
- [ ] Tests: only opted-in users; only new-this-week items; nothing new → nothing sent; summary text; mail/push payloads; settings switch saves; schedule registered.
- [ ] Commit `feat: weekly similar-artists roundup`.

### Task 7: Run and check
- [ ] `php artisan migrate`; `php artisan gigradar:backfill-artist-classifications`; `php artisan gigradar:discover` (real Ticketmaster calls — a handful); `npm run build`; `php artisan queue:restart`.
- [ ] iPhone: Discover tab shows grouped gigs; Follow removes a row; Not interested + Undo; Settings → Hidden artists → Show again; Similar artists switch; optionally `php artisan gigradar:similar-roundup` to see a roundup push.
- [ ] `git push origin main`.
