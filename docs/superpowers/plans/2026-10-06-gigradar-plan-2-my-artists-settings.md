# GigRadar Plan 2 — My Artists, Alert Settings, Mobile Tab Bar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the placeholder Dashboard with **My Artists** (followed artists, "New" badges, upcoming gigs near you), add an **Alert settings** page (home location, radius, email alerts), and replace the desktop sidebar with a **mobile bottom tab bar**.

**Architecture:** Builds on Plan 1 (on `main`). Distance maths is a pure `Geo` class. Place lookup goes through one `Geocoder` service (OpenStreetMap Nominatim) behind JSON endpoints. "Upcoming" filtering moves into a `Concert::upcoming()` scope shared by the artist page and My Artists. A new `from_seed` column keeps dates stored while seeding from ever showing as "New".

**Tech Stack:** Laravel 12, Pest, Inertia v2 + Vue 3 + TypeScript, Tailwind, lucide-vue-next.

**Spec:** `docs/superpowers/specs/2026-10-05-gigradar-design.md` (build-order steps 4–5; §4 screens, §5 data model).

**Work directly on `main`** (the user's choice). Never commit `.env`.

---

## File map

| File | Responsibility |
|---|---|
| `app/Support/Geo.php` | Pure haversine distance in miles |
| `database/migrations/2026_10_06_000001_add_from_seed_to_concerts_table.php` | `concerts.from_seed` |
| `app/Models/Concert.php` (modify) | `from_seed` fillable/cast; `scopeUpcoming()` |
| `app/Services/ArtistSync.php` (modify) | Sets `from_seed` |
| `app/Http/Controllers/ArtistController.php` (modify) | Uses `Concert::upcoming()` |
| `app/Http/Controllers/MyArtistsController.php` | My Artists page data |
| `resources/js/lib/dates.ts` | Shared concert date formatting |
| `resources/js/pages/MyArtists.vue` | My Artists page |
| `app/Services/Geocoding/Place.php`, `GeocoderException.php`, `Geocoder.php` | Nominatim search + reverse lookup |
| `app/Http/Controllers/Settings/AlertSettingsController.php` | Alert settings page + place lookup endpoints |
| `resources/js/pages/settings/Alerts.vue` | Alert settings page |
| `resources/js/layouts/settings/Layout.vue` (modify) | Adds Alerts + Log out |
| `resources/js/layouts/app/AppTabsLayout.vue`, `resources/js/components/BottomTabBar.vue` | Mobile app shell |
| `resources/js/layouts/AppLayout.vue` (modify) | Uses the tabs layout |

---

### Task 1: Geo distance helper

**Files:**
- Create: `app/Support/Geo.php`
- Test: `tests/Unit/GeoTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Support\Geo;

it('is zero for the same point', function () {
    expect(Geo::distanceMiles(52.6369, -1.1398, 52.6369, -1.1398))->toBe(0.0);
});

it('measures Leicester to Manchester as about 74 miles', function () {
    expect(Geo::distanceMiles(52.6369, -1.1398, 53.4808, -2.2426))
        ->toBeGreaterThan(70.0)->toBeLessThan(80.0);
});

it('measures London to Paris as about 213 miles', function () {
    expect(Geo::distanceMiles(51.5074, -0.1278, 48.8566, 2.3522))
        ->toBeGreaterThan(205.0)->toBeLessThan(220.0);
});

it('is symmetric', function () {
    expect(Geo::distanceMiles(51.5074, -0.1278, 48.8566, 2.3522))
        ->toBe(Geo::distanceMiles(48.8566, 2.3522, 51.5074, -0.1278));
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=GeoTest` — Expected: FAIL, `Class "App\Support\Geo" not found`.

- [ ] **Step 3: Implement**

`app/Support/Geo.php`:
```php
<?php

namespace App\Support;

final class Geo
{
    private const EARTH_RADIUS_MILES = 3958.8;

    /** Great-circle (haversine) distance between two points, in miles. */
    public static function distanceMiles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_MILES * 2 * asin(min(1.0, sqrt($a)));
    }
}
```

- [ ] **Step 4: Run to verify it passes** — `php artisan test --filter=GeoTest`, Expected: 4 PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Support/Geo.php tests/Unit/GeoTest.php
git commit -m "feat: add Geo distance helper"
```

---

### Task 2: `from_seed` column and `Concert::upcoming()` scope

**Files:**
- Create: `database/migrations/2026_10_06_000001_add_from_seed_to_concerts_table.php`
- Modify: `app/Models/Concert.php`, `app/Services/ArtistSync.php`, `database/factories/ConcertFactory.php`, `app/Http/Controllers/ArtistController.php`
- Test: `tests/Feature/Services/ArtistSyncTest.php`, `tests/Feature/Models/ConcertUpcomingTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Services/ArtistSyncTest.php`:
```php
it('marks concerts stored while seeding as from_seed', function () {
    Http::fake(['app.ticketmaster.com/discovery/v2/events.json*' => Http::response(tmFixture('events'))]);
    $artist = Artist::factory()->unseeded()->create(['ticketmaster_id' => 'K8vZ917G1V0']);

    app(ArtistSync::class)->syncEvents($artist);

    expect($artist->concerts()->where('from_seed', false)->count())->toBe(0);
});

it('does not mark concerts of an already-seeded artist as from_seed', function () {
    Http::fake(['app.ticketmaster.com/discovery/v2/events.json*' => Http::response(tmFixture('events'))]);
    $artist = Artist::factory()->create(['ticketmaster_id' => 'K8vZ917G1V0']);

    app(ArtistSync::class)->syncEvents($artist);

    expect($artist->concerts()->where('from_seed', true)->count())->toBe(0);
});
```

Create `tests/Feature/Models/ConcertUpcomingTest.php`:
```php
<?php

use App\Models\Concert;

it('returns concerts from the venue-local today onwards, in date order', function () {
    $later = Concert::factory()->create(['local_date' => today()->addDays(10)->toDateString(), 'starts_at' => now()->addDays(10)]);
    $today = Concert::factory()->create(['local_date' => today()->toDateString(), 'starts_at' => now()]);
    Concert::factory()->create(['local_date' => today()->subDay()->toDateString(), 'starts_at' => now()->subDay()]);

    expect(Concert::query()->upcoming()->pluck('id')->all())->toBe([$today->id, $later->id]);
});

it('falls back to starts_at when local_date is missing', function () {
    $future = Concert::factory()->create(['local_date' => null, 'starts_at' => now()->addDay()]);
    Concert::factory()->create(['local_date' => null, 'starts_at' => now()->subDays(2)]);

    expect(Concert::query()->upcoming()->pluck('id')->all())->toBe([$future->id]);
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --filter='ArtistSyncTest|ConcertUpcomingTest'` — Expected: FAIL (no `from_seed` column; `upcoming` scope undefined).

- [ ] **Step 3: Migration**

`database/migrations/2026_10_06_000001_add_from_seed_to_concerts_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('concerts', function (Blueprint $table) {
            $table->boolean('from_seed')->default(false)->after('alerted_at');
        });
    }

    public function down(): void
    {
        Schema::table('concerts', function (Blueprint $table) {
            $table->dropColumn('from_seed');
        });
    }
};
```

- [ ] **Step 4: Model, factory, sync**

In `app/Models/Concert.php`: add `'from_seed'` to `$fillable`, add `'from_seed' => 'boolean'` to `casts()`, and add the scope (with `use Illuminate\Database\Eloquent\Builder;`):
```php
    /** Concerts on or after today at the venue (falls back to the UTC start when local_date is missing), soonest first. */
    public function scopeUpcoming(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->where('local_date', '>=', today()->toDateString())
            ->orWhere(fn (Builder $q) => $q->whereNull('local_date')->where('starts_at', '>=', now()->startOfDay())))
            ->orderByRaw('COALESCE(local_date, DATE(starts_at))')
            ->orderBy('starts_at');
    }
```

In `database/factories/ConcertFactory.php` `definition()`, add `'from_seed' => false,`.

In `app/Services/ArtistSync.php`, in the `createOrFirst` values array where `'alerted_at' => $wasSeeded ? null : $now` is set, add `'from_seed' => ! $wasSeeded,` beside it.

- [ ] **Step 5: Use the scope in ArtistController**

In `app/Http/Controllers/ArtistController.php`, replace the `$concerts = $artist->concerts()->where(...)...->get();` block with:
```php
        $concerts = $artist->concerts()->upcoming()->get();
```

- [ ] **Step 6: Run tests and migrate**

Run: `php artisan test` — Expected: all PASS (ArtistPageTest still green proves the refactor). Then `php artisan migrate`.

- [ ] **Step 7: Commit**
```bash
git add database/migrations/2026_10_06_000001_add_from_seed_to_concerts_table.php app/Models/Concert.php app/Services/ArtistSync.php database/factories/ConcertFactory.php app/Http/Controllers/ArtistController.php tests/Feature/Services/ArtistSyncTest.php tests/Feature/Models/ConcertUpcomingTest.php
git commit -m "feat: from_seed flag and shared upcoming-concerts scope"
```

---

### Task 3: My Artists backend

**Files:**
- Create: `app/Http/Controllers/MyArtistsController.php`
- Modify: `routes/web.php`
- Delete: `tests/Feature/DashboardTest.php`
- Create: `resources/js/pages/MyArtists.vue` (minimal stub in this task; real UI in Task 4)
- Test: `tests/Feature/MyArtistsTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/MyArtistsTest.php`:
```php
<?php

use App\Models\Artist;
use App\Models\Concert;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function leicesterHome(): array
{
    return ['home_location_name' => 'Leicester, Leicestershire, United Kingdom', 'home_lat' => 52.6369, 'home_lng' => -1.1398, 'radius_miles' => 50];
}

function followArtist(User $user, Artist $artist, array $pivot = []): void
{
    $user->artists()->attach($artist, ['last_seen_at' => now()->subDay(), ...$pivot]);
}

it('redirects guests to login', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('lists followed artists by name with their next concert and scope', function () {
    $user = User::factory()->create();
    $b = Artist::factory()->create(['name' => 'Bicep']);
    $a = Artist::factory()->create(['name' => 'Arctic Monkeys']);
    followArtist($user, $b, ['alert_scope' => 'nearby']);
    followArtist($user, $a);
    Concert::factory()->for($a)->create(['local_date' => today()->addDays(5)->toDateString(), 'city' => 'Sheffield', 'first_seen_at' => now()->subWeek()]);
    Artist::factory()->create(['name' => 'Not Followed']);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->component('MyArtists')
            ->has('artists', 2)
            ->where('artists.0.name', 'Arctic Monkeys')
            ->where('artists.0.nextConcert.city', 'Sheffield')
            ->where('artists.0.nextConcert.localDate', today()->addDays(5)->toDateString())
            ->where('artists.0.alertScope', 'everywhere')
            ->where('artists.1.name', 'Bicep')
            ->where('artists.1.nextConcert', null)
            ->where('artists.1.alertScope', 'nearby'));
});

it('marks an artist as new only for non-seed concerts first seen after the last visit', function () {
    $user = User::factory()->create();
    [$fresh, $seedOnly, $seenBefore] = Artist::factory()->count(3)->sequence(['name' => 'A'], ['name' => 'B'], ['name' => 'C'])->create();
    foreach ([$fresh, $seedOnly, $seenBefore] as $artist) {
        followArtist($user, $artist); // last_seen_at = yesterday
    }
    Concert::factory()->for($fresh)->create(['first_seen_at' => now()]);
    Concert::factory()->for($seedOnly)->create(['first_seen_at' => now(), 'from_seed' => true]);
    Concert::factory()->for($seenBefore)->create(['first_seen_at' => now()->subWeek()]);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->where('artists.0.hasNew', true)
            ->where('artists.1.hasNew', false)
            ->where('artists.2.hasNew', false));
});

it('ignores past concerts for new badges and next concert', function () {
    $user = User::factory()->create();
    $artist = Artist::factory()->create();
    followArtist($user, $artist);
    Concert::factory()->for($artist)->create(['local_date' => today()->subDay()->toDateString(), 'starts_at' => now()->subDay(), 'first_seen_at' => now()]);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('artists.0.hasNew', false)->where('artists.0.nextConcert', null));
});

it('shows no nearby section without a home location', function () {
    $user = User::factory()->create();
    followArtist($user, Concert::factory()->create()->artist);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('hasHomeLocation', false)->has('nearby', 0));
});

it('lists upcoming concerts within the radius, excluding cancelled', function () {
    $user = User::factory()->create(leicesterHome());
    $artist = Artist::factory()->create(['name' => 'Local Band']);
    followArtist($user, $artist);
    $leicester = Concert::factory()->for($artist)->create(['city' => 'Leicester', 'lat' => 52.6369, 'lng' => -1.1398, 'local_date' => today()->addDays(3)->toDateString()]);
    Concert::factory()->for($artist)->create(['city' => 'Manchester', 'lat' => 53.4808, 'lng' => -2.2426, 'local_date' => today()->addDays(4)->toDateString()]);
    Concert::factory()->for($artist)->create(['city' => 'Leicester', 'lat' => 52.6369, 'lng' => -1.1398, 'status' => 'cancelled']);
    Concert::factory()->for($artist)->create(['city' => 'Nowhere', 'lat' => null, 'lng' => null]);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('hasHomeLocation', true)
            ->where('radiusMiles', 50)
            ->has('nearby', 1)
            ->where('nearby.0.id', $leicester->id)
            ->where('nearby.0.artistName', 'Local Band')
            ->where('nearby.0.distanceMiles', 0));
});

it('limits nearby to the next 10 concerts', function () {
    $user = User::factory()->create(leicesterHome());
    $artist = Artist::factory()->create();
    followArtist($user, $artist);
    foreach (range(1, 12) as $day) {
        Concert::factory()->for($artist)->create(['local_date' => today()->addDays($day)->toDateString(), 'starts_at' => now()->addDays($day)]);
    }

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->has('nearby', 10)
            ->where('nearby.0.localDate', today()->addDay()->toDateString()));
});
```
(ConcertFactory's default location is Leicester, so default concerts count as "nearby".)

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=MyArtistsTest` — Expected: FAIL (component is `Dashboard`, not `MyArtists`).

- [ ] **Step 3: Implement the controller**

`app/Http/Controllers/MyArtistsController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Models\Concert;
use App\Support\Geo;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class MyArtistsController extends Controller
{
    private const NEARBY_LIMIT = 10;

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $artists = $user->artists()->orderBy('name')->get();
        $upcoming = Concert::query()->whereIn('artist_id', $artists->modelKeys())->upcoming()->get();
        $byArtist = $upcoming->groupBy('artist_id');
        $hasHomeLocation = $user->home_lat !== null && $user->home_lng !== null;

        return Inertia::render('MyArtists', [
            'artists' => $artists->map(fn (Artist $artist) => $this->artistRow($artist, $byArtist->get($artist->id, collect())))->values(),
            'hasHomeLocation' => $hasHomeLocation,
            'radiusMiles' => $user->radius_miles,
            'nearby' => $hasHomeLocation ? $this->nearby($upcoming, $artists, $user->home_lat, $user->home_lng, $user->radius_miles) : [],
        ]);
    }

    /** @param Collection<int, Concert> $concerts this artist's upcoming concerts, soonest first */
    private function artistRow(Artist $artist, Collection $concerts): array
    {
        $lastSeen = $artist->pivot->last_seen_at;
        $next = $concerts->first();

        return [
            'ticketmasterId' => $artist->ticketmaster_id,
            'name' => $artist->name,
            'imageUrl' => $artist->image_url,
            'alertScope' => $artist->pivot->alert_scope,
            'hasNew' => $concerts->contains(fn (Concert $c) => ! $c->from_seed && ($lastSeen === null || $c->first_seen_at->gt($lastSeen))),
            'nextConcert' => $next ? [
                'localDate' => $next->local_date?->toDateString(),
                'startsAt' => $next->starts_at->toIso8601String(),
                'city' => $next->city,
            ] : null,
        ];
    }

    /**
     * @param  Collection<int, Concert>  $upcoming  soonest first
     * @param  Collection<int, Artist>  $artists
     */
    private function nearby(Collection $upcoming, Collection $artists, float $lat, float $lng, int $radiusMiles): array
    {
        $names = $artists->pluck('name', 'id');
        $ticketmasterIds = $artists->pluck('ticketmaster_id', 'id');

        return $upcoming
            ->filter(fn (Concert $c) => $c->status !== 'cancelled' && $c->lat !== null && $c->lng !== null)
            ->map(fn (Concert $c) => [$c, Geo::distanceMiles($lat, $lng, $c->lat, $c->lng)])
            ->filter(fn (array $pair) => $pair[1] <= $radiusMiles)
            ->take(self::NEARBY_LIMIT)
            ->map(fn (array $pair) => [
                'id' => $pair[0]->id,
                'artistName' => $names[$pair[0]->artist_id],
                'artistTicketmasterId' => $ticketmasterIds[$pair[0]->artist_id],
                'localDate' => $pair[0]->local_date?->toDateString(),
                'startsAt' => $pair[0]->starts_at->toIso8601String(),
                'venueName' => $pair[0]->venue_name,
                'city' => $pair[0]->city,
                'distanceMiles' => (int) round($pair[1]),
            ])
            ->values()
            ->all();
    }
}
```

- [ ] **Step 4: Route and stub page**

In `routes/web.php`, replace the `Route::get('dashboard', function () {...})...` block with (import `App\Http\Controllers\MyArtistsController`):
```php
// The route stays named "dashboard" because the auth controllers redirect there after login.
Route::get('dashboard', MyArtistsController::class)->middleware(['auth', 'verified'])->name('dashboard');
```

Create `resources/js/pages/MyArtists.vue` (stub — Task 4 replaces it):
```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="My Artists" />
    <AppLayout :breadcrumbs="[{ title: 'My Artists', href: '/dashboard' }]" />
</template>
```

Delete `tests/Feature/DashboardTest.php` (its guest and verified cases are covered by `MyArtistsTest` and `EmailVerificationRequiredTest`).

- [ ] **Step 5: Run tests** — `php artisan test`, Expected: all PASS. `npm run build` passes.

- [ ] **Step 6: Commit**
```bash
git add app/Http/Controllers/MyArtistsController.php routes/web.php resources/js/pages/MyArtists.vue tests/Feature/MyArtistsTest.php
git rm tests/Feature/DashboardTest.php
git commit -m "feat: My Artists data with new badges and nearby concerts"
```

---

### Task 4: My Artists page UI (and shared date formatting)

**Files:**
- Create: `resources/js/lib/dates.ts`
- Replace: `resources/js/pages/MyArtists.vue`
- Modify: `resources/js/pages/artists/Show.vue`
- Delete: `resources/js/pages/Dashboard.vue`, `resources/js/components/PlaceholderPattern.vue` (only if nothing else imports it — check with `grep -r PlaceholderPattern resources/js`)

- [ ] **Step 1: Shared date helper**

`resources/js/lib/dates.ts`:
```ts
const DEFAULT_FORMAT: Intl.DateTimeFormatOptions = { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' };

/**
 * Format a concert date. Prefers the venue-local date (YYYY-MM-DD) so a show never
 * appears on a different day because of the viewer's timezone.
 */
export function formatConcertDate(localDate: string | null, startsAt: string, options: Intl.DateTimeFormatOptions = DEFAULT_FORMAT): string {
    if (localDate) {
        return new Date(`${localDate}T00:00:00Z`).toLocaleDateString('en-GB', { ...options, timeZone: 'UTC' });
    }

    return new Date(startsAt).toLocaleDateString('en-GB', options);
}
```

In `resources/js/pages/artists/Show.vue`, delete the local `formatDate` function (and its `dateFormat` constant if only it uses it), import `formatConcertDate` from `@/lib/dates`, and change the template call to `formatConcertDate(concert.localDate, concert.startsAt)`. Output must be unchanged.

- [ ] **Step 2: My Artists page**

Replace `resources/js/pages/MyArtists.vue`:
```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { formatConcertDate } from '@/lib/dates';
import { Head, Link } from '@inertiajs/vue3';

interface ArtistRow {
    ticketmasterId: string;
    name: string;
    imageUrl: string | null;
    alertScope: 'everywhere' | 'nearby';
    hasNew: boolean;
    nextConcert: { localDate: string | null; startsAt: string; city: string } | null;
}

interface NearbyConcert {
    id: number;
    artistName: string;
    artistTicketmasterId: string;
    localDate: string | null;
    startsAt: string;
    venueName: string;
    city: string;
    distanceMiles: number;
}

defineProps<{
    artists: ArtistRow[];
    hasHomeLocation: boolean;
    radiusMiles: number;
    nearby: NearbyConcert[];
}>();

const shortDate: Intl.DateTimeFormatOptions = { weekday: 'short', day: 'numeric', month: 'short' };
</script>

<template>
    <Head title="My Artists" />
    <AppLayout :breadcrumbs="[{ title: 'My Artists', href: '/dashboard' }]">
        <div class="mx-auto w-full max-w-xl space-y-8 p-4">
            <section v-if="artists.length > 0" aria-labelledby="upcoming-near-you">
                <h2 id="upcoming-near-you" class="text-lg font-semibold">Upcoming near you</h2>

                <p v-if="!hasHomeLocation" class="mt-2 text-sm text-neutral-500">
                    <Link href="/settings/alerts" class="font-medium text-violet-600">Set your home location</Link>
                    to see gigs near you.
                </p>
                <p v-else-if="nearby.length === 0" class="mt-2 text-sm text-neutral-500">
                    No upcoming gigs within {{ radiusMiles }} miles yet.
                </p>
                <ul v-else class="mt-2 divide-y divide-neutral-200 dark:divide-neutral-800">
                    <li v-for="concert in nearby" :key="concert.id">
                        <Link :href="`/artists/${concert.artistTicketmasterId}`" class="flex min-h-11 items-center gap-3 py-3">
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium">{{ concert.artistName }}</p>
                                <p class="truncate text-sm text-neutral-500">
                                    {{ formatConcertDate(concert.localDate, concert.startsAt, shortDate) }} · {{ concert.venueName }}, {{ concert.city }}
                                </p>
                            </div>
                            <span class="shrink-0 text-xs text-neutral-500">{{ concert.distanceMiles }} mi</span>
                        </Link>
                    </li>
                </ul>
            </section>

            <section aria-labelledby="following">
                <h2 id="following" class="text-lg font-semibold">Following</h2>

                <div v-if="artists.length === 0" class="mt-4 rounded-xl border border-dashed border-neutral-300 p-6 text-center dark:border-neutral-700">
                    <p class="font-medium">You're not following anyone yet.</p>
                    <p class="mt-1 text-sm text-neutral-500">Follow artists to get alerts when they announce new dates.</p>
                    <Link href="/search" class="mt-4 inline-flex min-h-11 items-center rounded-full bg-violet-600 px-5 text-sm font-medium text-white">
                        Find artists
                    </Link>
                </div>

                <ul v-else class="mt-2 divide-y divide-neutral-200 dark:divide-neutral-800">
                    <li v-for="artist in artists" :key="artist.ticketmasterId">
                        <Link :href="`/artists/${artist.ticketmasterId}`" class="flex min-h-11 items-center gap-3 py-3">
                            <img v-if="artist.imageUrl" :src="artist.imageUrl" alt="" class="size-12 shrink-0 rounded-lg object-cover" />
                            <div v-else class="size-12 shrink-0 rounded-lg bg-neutral-200 dark:bg-neutral-800" />
                            <div class="min-w-0 flex-1">
                                <p class="flex items-center gap-2">
                                    <span class="truncate font-medium">{{ artist.name }}</span>
                                    <span
                                        v-if="artist.hasNew"
                                        class="shrink-0 rounded-full bg-violet-600 px-2 py-0.5 text-xs font-semibold text-white"
                                    >
                                        New
                                    </span>
                                </p>
                                <p class="truncate text-sm text-neutral-500">
                                    <template v-if="artist.nextConcert">
                                        Next: {{ formatConcertDate(artist.nextConcert.localDate, artist.nextConcert.startsAt, shortDate) }} · {{ artist.nextConcert.city }}
                                    </template>
                                    <template v-else>No upcoming dates</template>
                                </p>
                            </div>
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 3: Remove the placeholder Dashboard**

Delete `resources/js/pages/Dashboard.vue`. Delete `resources/js/components/PlaceholderPattern.vue` if `grep -r PlaceholderPattern resources/js` finds no other users.

- [ ] **Step 4: Verify** — `npm run build` passes; `php artisan test` all PASS.

- [ ] **Step 5: Commit**
```bash
git add resources/js/lib/dates.ts resources/js/pages/MyArtists.vue resources/js/pages/artists/Show.vue
git rm resources/js/pages/Dashboard.vue resources/js/components/PlaceholderPattern.vue
git commit -m "feat: My Artists page"
```

---

### Task 5: Geocoder (OpenStreetMap Nominatim)

**Files:**
- Create: `app/Services/Geocoding/Place.php`, `GeocoderException.php`, `Geocoder.php`
- Modify: `config/services.php`, `app/Providers/AppServiceProvider.php`, `tests/Pest.php`
- Create: `tests/Fixtures/nominatim/search.json`, `tests/Fixtures/nominatim/reverse.json`
- Test: `tests/Feature/Geocoding/GeocoderTest.php`

Nominatim's usage policy: identify the app with a User-Agent, at most 1 request/second, cache results. Our traffic is tiny (only when a user edits their location) and results are cached 30 days.

- [ ] **Step 1: Fixtures and helper**

`tests/Fixtures/nominatim/search.json`:
```json
[
  { "place_id": 1, "lat": "52.6362", "lon": "-1.1331", "name": "Leicester", "display_name": "Leicester, Leicestershire, East Midlands, England, United Kingdom" },
  { "place_id": 2, "lat": "42.2459", "lon": "-71.9087", "name": "Leicester", "display_name": "Leicester, Worcester County, Massachusetts, United States" }
]
```

`tests/Fixtures/nominatim/reverse.json`:
```json
{ "place_id": 1, "lat": "52.63621234", "lon": "-1.13314567", "name": "Leicester", "display_name": "Leicester, Leicestershire, East Midlands, England, United Kingdom" }
```

At the end of `tests/Pest.php`:
```php
function nominatimFixture(string $name): array
{
    return json_decode(file_get_contents(__DIR__."/Fixtures/nominatim/{$name}.json"), true);
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Geocoding/GeocoderTest.php`:
```php
<?php

use App\Services\Geocoding\Geocoder;
use App\Services\Geocoding\GeocoderException;
use App\Services\Geocoding\Place;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('searches places with short labels and identifies itself', function () {
    Http::fake(['nominatim.openstreetmap.org/search*' => Http::response(nominatimFixture('search'))]);

    $places = app(Geocoder::class)->search('Leicester');

    expect($places)->toHaveCount(2)
        ->and($places[0])->toBeInstanceOf(Place::class)
        ->and($places[0]->name)->toBe('Leicester, Leicestershire, United Kingdom')
        ->and($places[0]->lat)->toBe(52.6362)
        ->and($places[0]->lng)->toBe(-1.1331)
        ->and($places[1]->name)->toBe('Leicester, Worcester County, United States');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'q=Leicester')
        && str_contains($r->url(), 'format=jsonv2')
        && $r->hasHeader('User-Agent', 'GigRadar/1.0 (+https://github.com/donashalin/GigRadar)'));
});

it('caches searches', function () {
    Http::fake(['nominatim.openstreetmap.org/search*' => Http::response(nominatimFixture('search'))]);

    app(Geocoder::class)->search('Leicester');
    app(Geocoder::class)->search(' leicester ');

    Http::assertSentCount(1);
});

it('reverse-geocodes coordinates rounded to 4 decimals', function () {
    Http::fake(['nominatim.openstreetmap.org/reverse*' => Http::response(nominatimFixture('reverse'))]);

    $place = app(Geocoder::class)->reverse(52.63621234, -1.13314567);

    expect($place->name)->toBe('Leicester, Leicestershire, United Kingdom')
        ->and($place->lat)->toBe(52.6362)
        ->and($place->lng)->toBe(-1.1331);
});

it('returns null when a point cannot be reverse-geocoded', function () {
    Http::fake(['nominatim.openstreetmap.org/reverse*' => Http::response(['error' => 'Unable to geocode'])]);

    expect(app(Geocoder::class)->reverse(0.0, 0.0))->toBeNull();
});

it('throws when Nominatim fails', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 503)]);

    app(Geocoder::class)->search('Leicester');
})->throws(GeocoderException::class);

it('throws when Nominatim is unreachable', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::failedConnection()]);

    app(Geocoder::class)->search('Leicester');
})->throws(GeocoderException::class);
```

- [ ] **Step 3: Run to verify it fails** — `php artisan test --filter=GeocoderTest`, Expected: FAIL, class not found.

- [ ] **Step 4: Implement**

`app/Services/Geocoding/Place.php`:
```php
<?php

namespace App\Services\Geocoding;

final readonly class Place
{
    public function __construct(
        public string $name,
        public float $lat,
        public float $lng,
    ) {}

    /** @return array{name: string, lat: float, lng: float} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'lat' => $this->lat, 'lng' => $this->lng];
    }
}
```

`app/Services/Geocoding/GeocoderException.php`:
```php
<?php

namespace App\Services\Geocoding;

use RuntimeException;

class GeocoderException extends RuntimeException {}
```

`app/Services/Geocoding/Geocoder.php`:
```php
<?php

namespace App\Services\Geocoding;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Place lookup via OpenStreetMap Nominatim. Results are cached; coordinates are rounded to ~10 m. */
class Geocoder
{
    private const BASE_URL = 'https://nominatim.openstreetmap.org';

    public function __construct(private readonly string $userAgent) {}

    /** @return list<Place> */
    public function search(string $query): array
    {
        $query = trim($query);

        return Cache::remember('geo:search:'.md5(mb_strtolower($query)), now()->addDays(30), function () use ($query) {
            $results = $this->get('/search', ['q' => $query, 'format' => 'jsonv2', 'limit' => 5]);

            return array_values(array_filter(array_map($this->toPlace(...), array_filter($results, 'is_array'))));
        });
    }

    public function reverse(float $lat, float $lng): ?Place
    {
        $key = sprintf('geo:reverse:%.3f,%.3f', $lat, $lng);

        return Cache::remember($key, now()->addDays(30), fn () => $this->toPlace(
            $this->get('/reverse', ['lat' => $lat, 'lon' => $lng, 'format' => 'jsonv2', 'zoom' => 10]),
        ));
    }

    private function get(string $path, array $query): array
    {
        try {
            $response = Http::baseUrl(self::BASE_URL)
                ->withUserAgent($this->userAgent)
                ->acceptJson()
                ->timeout(8)
                ->get($path, $query);
        } catch (ConnectionException) {
            throw new GeocoderException('Nominatim unreachable');
        }

        if ($response->failed()) {
            throw new GeocoderException("Nominatim {$path} failed with HTTP {$response->status()}");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function toPlace(array $result): ?Place
    {
        if (! isset($result['lat'], $result['lon'], $result['display_name'])) {
            return null;
        }

        return new Place(
            name: $this->label($result['display_name']),
            lat: round((float) $result['lat'], 4),
            lng: round((float) $result['lon'], 4),
        );
    }

    /** "Leicester, Leicestershire, East Midlands, England, United Kingdom" → "Leicester, Leicestershire, United Kingdom" */
    private function label(string $displayName): string
    {
        $parts = array_map('trim', explode(',', $displayName));

        return count($parts) > 3
            ? implode(', ', [$parts[0], $parts[1], end($parts)])
            : implode(', ', $parts);
    }
}
```

In `config/services.php` add:
```php
    'nominatim' => [
        'user_agent' => env('NOMINATIM_USER_AGENT', 'GigRadar/1.0 (+https://github.com/donashalin/GigRadar)'),
    ],
```

In `app/Providers/AppServiceProvider.php` `register()` (import `App\Services\Geocoding\Geocoder`):
```php
        $this->app->singleton(Geocoder::class, fn () => new Geocoder((string) config('services.nominatim.user_agent')));
```

- [ ] **Step 5: Run tests** — `php artisan test --filter=GeocoderTest`, Expected: 6 PASS; full suite PASS.

- [ ] **Step 6: Commit**
```bash
git add app/Services/Geocoding config/services.php app/Providers/AppServiceProvider.php tests/Pest.php tests/Fixtures/nominatim tests/Feature/Geocoding
git commit -m "feat: add Nominatim geocoder"
```

---

### Task 6: Alert settings backend

**Files:**
- Create: `app/Http/Controllers/Settings/AlertSettingsController.php`
- Modify: `routes/settings.php`
- Create: `resources/js/pages/settings/Alerts.vue` (stub; Task 7 builds the UI)
- Test: `tests/Feature/Settings/AlertSettingsTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Settings/AlertSettingsTest.php`:
```php
<?php

use App\Models\Artist;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

it('sends /settings to the alert settings page', function () {
    $this->actingAs(User::factory()->create())->get('/settings')->assertRedirect('/settings/alerts');
});

it('requires a verified user', function () {
    $this->get('/settings/alerts')->assertRedirect('/login');
    $this->actingAs(User::factory()->unverified()->create())
        ->get('/settings/alerts')->assertRedirect(route('verification.notice'));
});

it('shows the current alert settings', function () {
    $user = User::factory()->create(['home_location_name' => 'Leicester, Leicestershire, United Kingdom', 'home_lat' => 52.6362, 'home_lng' => -1.1331, 'radius_miles' => 100, 'notify_email' => false]);

    $this->actingAs($user)->get('/settings/alerts')
        ->assertInertia(fn (Assert $page) => $page->component('settings/Alerts')
            ->where('settings.homeLocationName', 'Leicester, Leicestershire, United Kingdom')
            ->where('settings.homeLat', 52.6362)
            ->where('settings.homeLng', -1.1331)
            ->where('settings.radiusMiles', 100)
            ->where('settings.notifyEmail', false)
            ->where('radiusOptions', [25, 50, 100, 250]));
});

it('saves alert settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/settings/alerts', [
        'home_location_name' => 'Leicester, Leicestershire, United Kingdom',
        'home_lat' => 52.6362,
        'home_lng' => -1.1331,
        'radius_miles' => 25,
        'notify_email' => false,
    ])->assertRedirect('/settings/alerts')->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->home_location_name)->toBe('Leicester, Leicestershire, United Kingdom')
        ->and($user->home_lat)->toBe(52.6362)
        ->and($user->radius_miles)->toBe(25)
        ->and($user->notify_email)->toBeFalse();
});

it('clears the home location', function () {
    $user = User::factory()->create(['home_location_name' => 'Leicester', 'home_lat' => 52.6, 'home_lng' => -1.1]);

    $this->actingAs($user)->patch('/settings/alerts', [
        'home_location_name' => null, 'home_lat' => null, 'home_lng' => null, 'radius_miles' => 50, 'notify_email' => true,
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->home_lat)->toBeNull();
});

it('validates alert settings', function (array $input, string $errorField) {
    $valid = ['home_location_name' => 'Leicester', 'home_lat' => 52.6, 'home_lng' => -1.1, 'radius_miles' => 50, 'notify_email' => true];

    $this->actingAs(User::factory()->create())
        ->patch('/settings/alerts', [...$valid, ...$input])
        ->assertSessionHasErrors($errorField);
})->with([
    'radius not offered' => [['radius_miles' => 30], 'radius_miles'],
    'latitude out of range' => [['home_lat' => 91], 'home_lat'],
    'longitude out of range' => [['home_lng' => -181], 'home_lng'],
    'name without coordinates' => [['home_lat' => null, 'home_lng' => null], 'home_lat'],
    'coordinates without name' => [['home_location_name' => null], 'home_location_name'],
    'email flag missing' => [['notify_email' => null], 'notify_email'],
]);

it('searches places as JSON', function () {
    Http::fake(['nominatim.openstreetmap.org/search*' => Http::response(nominatimFixture('search'))]);

    $this->actingAs(User::factory()->create())->getJson('/settings/alerts/places?q=Leicester')
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.name', 'Leicester, Leicestershire, United Kingdom');
});

it('does not search places for fewer than 3 characters', function () {
    Http::fake();

    $this->actingAs(User::factory()->create())->getJson('/settings/alerts/places?q=Le')->assertOk()->assertExactJson([]);
    Http::assertNothingSent();
});

it('reports place search outages', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 503)]);

    $this->actingAs(User::factory()->create())->getJson('/settings/alerts/places?q=Leicester')
        ->assertStatus(503)->assertJsonPath('message', 'Location search is unavailable right now.');
});

it('reverse-geocodes the current location', function () {
    Http::fake(['nominatim.openstreetmap.org/reverse*' => Http::response(nominatimFixture('reverse'))]);

    $this->actingAs(User::factory()->create())->getJson('/settings/alerts/reverse?lat=52.6362&lng=-1.1331')
        ->assertOk()->assertJsonPath('name', 'Leicester, Leicestershire, United Kingdom');
});

it('404s when the current location cannot be named', function () {
    Http::fake(['nominatim.openstreetmap.org/reverse*' => Http::response(['error' => 'Unable to geocode'])]);

    $this->actingAs(User::factory()->create())->getJson('/settings/alerts/reverse?lat=0&lng=0')
        ->assertNotFound()->assertJsonPath('message', "Couldn't work out where that is.");
});

it('validates reverse-geocode coordinates', function () {
    $this->actingAs(User::factory()->create())->getJson('/settings/alerts/reverse?lat=200&lng=0')->assertUnprocessable();
});

it('deletes follows when the account is deleted', function () {
    $user = User::factory()->create();
    $user->artists()->attach(Artist::factory()->create(), ['last_seen_at' => now()]);

    $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])->assertRedirect('/');

    expect(Illuminate\Support\Facades\DB::table('follows')->count())->toBe(0);
});
```

- [ ] **Step 2: Run to verify it fails** — `php artisan test --filter=AlertSettingsTest`, Expected: FAIL (`/settings` redirects to `/settings/profile`; `/settings/alerts` 404).

- [ ] **Step 3: Implement the controller**

`app/Http/Controllers/Settings/AlertSettingsController.php`:
```php
<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Geocoding\Geocoder;
use App\Services\Geocoding\GeocoderException;
use App\Services\Geocoding\Place;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AlertSettingsController extends Controller
{
    public const RADIUS_OPTIONS = [25, 50, 100, 250];

    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Alerts', [
            'settings' => [
                'homeLocationName' => $user->home_location_name,
                'homeLat' => $user->home_lat,
                'homeLng' => $user->home_lng,
                'radiusMiles' => $user->radius_miles,
                'notifyEmail' => $user->notify_email,
            ],
            'radiusOptions' => self::RADIUS_OPTIONS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'home_location_name' => ['nullable', 'string', 'max:255', 'required_with:home_lat,home_lng'],
            'home_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:home_location_name'],
            'home_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:home_location_name'],
            'radius_miles' => ['required', 'integer', Rule::in(self::RADIUS_OPTIONS)],
            'notify_email' => ['required', 'boolean'],
        ]);

        $request->user()->forceFill($validated)->save();

        return to_route('alerts.edit');
    }

    public function places(Request $request, Geocoder $geocoder): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 3) {
            return response()->json([]);
        }

        try {
            return response()->json(array_map(fn (Place $p) => $p->toArray(), $geocoder->search($q)));
        } catch (GeocoderException $e) {
            report($e);

            return response()->json(['message' => 'Location search is unavailable right now.'], 503);
        }
    }

    public function reverse(Request $request, Geocoder $geocoder): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        try {
            $place = $geocoder->reverse((float) $validated['lat'], (float) $validated['lng']);
        } catch (GeocoderException $e) {
            report($e);

            return response()->json(['message' => 'Location lookup is unavailable right now.'], 503);
        }

        return $place
            ? response()->json($place->toArray())
            : response()->json(['message' => "Couldn't work out where that is."], 404);
    }
}
```

- [ ] **Step 4: Routes and stub page**

In `routes/settings.php`: change `Route::redirect('settings', 'settings/profile');` to `Route::redirect('settings', 'settings/alerts');`, import `App\Http\Controllers\Settings\AlertSettingsController`, and add a new group below the existing one:
```php
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/alerts', [AlertSettingsController::class, 'edit'])->name('alerts.edit');
    Route::patch('settings/alerts', [AlertSettingsController::class, 'update'])->name('alerts.update');
    Route::get('settings/alerts/places', [AlertSettingsController::class, 'places'])
        ->middleware('throttle:30,1')->name('alerts.places');
    Route::get('settings/alerts/reverse', [AlertSettingsController::class, 'reverse'])
        ->middleware('throttle:10,1')->name('alerts.reverse');
});
```

Create stub `resources/js/pages/settings/Alerts.vue`:
```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Alert settings" />
    <AppLayout :breadcrumbs="[{ title: 'Alert settings', href: '/settings/alerts' }]" />
</template>
```

- [ ] **Step 5: Run tests** — `php artisan test`, Expected: all PASS (the starter kit's profile/settings tests must still pass; if one asserted the old `/settings` redirect target, update it to `/settings/alerts`).

- [ ] **Step 6: Commit**
```bash
git add app/Http/Controllers/Settings/AlertSettingsController.php routes/settings.php resources/js/pages/settings/Alerts.vue tests/Feature/Settings/AlertSettingsTest.php
git commit -m "feat: alert settings endpoints"
```

---

### Task 7: Alert settings page UI

**Files:**
- Replace: `resources/js/pages/settings/Alerts.vue`
- Modify: `resources/js/layouts/settings/Layout.vue`, `resources/js/types/index.ts`

- [ ] **Step 1: Types**

In `resources/js/types/index.ts`, extend `User`:
```ts
export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    home_location_name: string | null;
    home_lat: number | null;
    home_lng: number | null;
    radius_miles: number;
    notify_email: boolean;
    notify_push: boolean;
    created_at: string;
    updated_at: string;
}
```

- [ ] **Step 2: Settings navigation**

In `resources/js/layouts/settings/Layout.vue`: make `{ title: 'Alerts', href: '/settings/alerts' }` the **first** entry of `sidebarNavItems`, update the `Heading` description to `"Manage alerts, your profile and account"`, and add a log-out button under the nav list (inside `<aside>`, after `</nav>`):
```vue
                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    class="mt-4 flex min-h-11 w-full items-center rounded-md px-4 text-sm font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-950"
                >
                    Log out
                </Link>
```
Also give the nav `Button`s `class` an extra `min-h-11` so they're comfortable tap targets.

- [ ] **Step 3: The page**

Replace `resources/js/pages/settings/Alerts.vue`:
```vue
<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';

interface Place {
    name: string;
    lat: number;
    lng: number;
}

const props = defineProps<{
    settings: {
        homeLocationName: string | null;
        homeLat: number | null;
        homeLng: number | null;
        radiusMiles: number;
        notifyEmail: boolean;
    };
    radiusOptions: number[];
}>();

const form = useForm({
    home_location_name: props.settings.homeLocationName,
    home_lat: props.settings.homeLat,
    home_lng: props.settings.homeLng,
    radius_miles: props.settings.radiusMiles,
    notify_email: props.settings.notifyEmail,
});

const placeQuery = ref('');
const places = ref<Place[]>([]);
const lookupError = ref<string | null>(null);
const locating = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;

async function getJson<T>(url: string): Promise<T> {
    controller?.abort();
    controller = new AbortController();
    const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(body.message ?? 'Something went wrong. Please try again.');
    }
    return body as T;
}

watch(placeQuery, (value) => {
    clearTimeout(timer);
    lookupError.value = null;
    const q = value.trim();
    if (q.length < 3) {
        places.value = [];
        return;
    }
    timer = setTimeout(async () => {
        try {
            places.value = await getJson<Place[]>(`/settings/alerts/places?q=${encodeURIComponent(q)}`);
            if (places.value.length === 0) {
                lookupError.value = "Couldn't find that place.";
            }
        } catch (e) {
            if ((e as Error).name !== 'AbortError') {
                lookupError.value = (e as Error).message;
            }
        }
    }, 400);
});

onBeforeUnmount(() => {
    clearTimeout(timer);
    controller?.abort();
});

function choose(place: Place) {
    form.home_location_name = place.name;
    form.home_lat = place.lat;
    form.home_lng = place.lng;
    placeQuery.value = '';
    places.value = [];
}

function clearLocation() {
    form.home_location_name = null;
    form.home_lat = null;
    form.home_lng = null;
}

function useCurrentLocation() {
    if (!('geolocation' in navigator)) {
        lookupError.value = "Your browser can't share your location.";
        return;
    }
    locating.value = true;
    lookupError.value = null;
    navigator.geolocation.getCurrentPosition(
        async ({ coords }) => {
            try {
                choose(await getJson<Place>(`/settings/alerts/reverse?lat=${coords.latitude}&lng=${coords.longitude}`));
            } catch (e) {
                lookupError.value = (e as Error).message;
            } finally {
                locating.value = false;
            }
        },
        () => {
            locating.value = false;
            lookupError.value = 'Location permission was denied. You can type a city instead.';
        },
        { timeout: 10000, maximumAge: 600000 },
    );
}

function save() {
    form.patch('/settings/alerts', { preserveScroll: true });
}
</script>

<template>
    <AppLayout :breadcrumbs="[{ title: 'Alert settings', href: '/settings/alerts' }]">
        <Head title="Alert settings" />

        <SettingsLayout>
            <form class="space-y-8" @submit.prevent="save">
                <section class="space-y-3">
                    <HeadingSmall title="Home location" description="Used for “Near me” alerts and gigs near you." />

                    <div
                        v-if="form.home_location_name"
                        class="flex items-center justify-between gap-3 rounded-xl border border-neutral-200 p-3 dark:border-neutral-800"
                    >
                        <span class="min-w-0 truncate font-medium">{{ form.home_location_name }}</span>
                        <button type="button" class="min-h-11 shrink-0 px-2 text-sm font-medium text-violet-600" @click="clearLocation">
                            Change
                        </button>
                    </div>

                    <template v-else>
                        <input
                            v-model="placeQuery"
                            type="search"
                            aria-label="Search for a town or city"
                            placeholder="Type a town or city…"
                            class="w-full rounded-xl border border-neutral-300 bg-white px-4 py-3 text-base dark:border-neutral-700 dark:bg-neutral-900"
                        />
                        <ul v-if="places.length" class="divide-y divide-neutral-200 rounded-xl border border-neutral-200 dark:divide-neutral-800 dark:border-neutral-800">
                            <li v-for="place in places" :key="`${place.lat},${place.lng}`">
                                <button type="button" class="flex min-h-11 w-full items-center px-4 text-left" @click="choose(place)">
                                    {{ place.name }}
                                </button>
                            </li>
                        </ul>
                        <button
                            type="button"
                            class="inline-flex min-h-11 items-center rounded-full border border-violet-600 px-4 text-sm font-medium text-violet-600 disabled:opacity-60"
                            :disabled="locating"
                            @click="useCurrentLocation"
                        >
                            {{ locating ? 'Finding you…' : 'Use my current location' }}
                        </button>
                    </template>

                    <p v-if="lookupError" role="alert" class="text-sm text-red-600">{{ lookupError }}</p>
                    <InputError :message="form.errors.home_location_name || form.errors.home_lat || form.errors.home_lng" />
                </section>

                <section class="space-y-3">
                    <HeadingSmall title="Distance" description="How far you'd travel for a gig." />
                    <div class="grid grid-cols-4 gap-1 rounded-xl bg-neutral-100 p-1 dark:bg-neutral-900" role="group" aria-label="Distance in miles">
                        <button
                            v-for="miles in radiusOptions"
                            :key="miles"
                            type="button"
                            class="min-h-11 rounded-lg text-sm font-medium"
                            :class="form.radius_miles === miles ? 'bg-white shadow dark:bg-neutral-700' : 'text-neutral-500'"
                            :aria-pressed="form.radius_miles === miles"
                            @click="form.radius_miles = miles"
                        >
                            {{ miles }} mi
                        </button>
                    </div>
                    <InputError :message="form.errors.radius_miles" />
                </section>

                <section class="space-y-3">
                    <HeadingSmall title="Alerts" description="How we tell you about new dates." />
                    <label class="flex min-h-11 items-center justify-between gap-3">
                        <span>Email me when artists I follow announce new dates</span>
                        <input v-model="form.notify_email" type="checkbox" class="size-5 shrink-0 accent-violet-600" />
                    </label>
                    <p class="text-sm text-neutral-500">Push notifications are coming soon.</p>
                </section>

                <div class="flex items-center gap-4">
                    <button
                        type="submit"
                        class="min-h-11 rounded-full bg-violet-600 px-6 text-sm font-medium text-white disabled:opacity-60"
                        :disabled="form.processing"
                    >
                        Save
                    </button>
                    <p v-if="form.recentlySuccessful" class="text-sm text-neutral-500">Saved.</p>
                </div>
            </form>
        </SettingsLayout>
    </AppLayout>
</template>
```

- [ ] **Step 4: Verify** — `npm run build` passes; `php artisan test` all PASS.

- [ ] **Step 5: Commit**
```bash
git add resources/js/pages/settings/Alerts.vue resources/js/layouts/settings/Layout.vue resources/js/types/index.ts
git commit -m "feat: alert settings page"
```

---

### Task 8: Mobile app shell with bottom tab bar

**Files:**
- Create: `resources/js/layouts/app/AppTabsLayout.vue`, `resources/js/components/BottomTabBar.vue`
- Modify: `resources/js/layouts/AppLayout.vue`, `resources/views/app.blade.php`
- Delete: the sidebar/header shell components (see Step 4)

- [ ] **Step 1: Safe-area viewport**

In `resources/views/app.blade.php`, change the viewport meta to:
```html
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
```

- [ ] **Step 2: Bottom tab bar**

`resources/js/components/BottomTabBar.vue`:
```vue
<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Music, Search, Settings } from 'lucide-vue-next';

const page = usePage();

const tabs = [
    { title: 'My Artists', href: '/dashboard', icon: Music, match: ['/dashboard', '/artists'] },
    { title: 'Search', href: '/search', icon: Search, match: ['/search'] },
    { title: 'Settings', href: '/settings', icon: Settings, match: ['/settings'] },
];

const isActive = (match: string[]) =>
    match.some((prefix) => page.url === prefix || page.url.startsWith(`${prefix}/`) || page.url.startsWith(`${prefix}?`));
</script>

<template>
    <nav
        aria-label="Main"
        class="fixed inset-x-0 bottom-0 z-20 border-t border-neutral-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur dark:border-neutral-800 dark:bg-neutral-950/95"
    >
        <ul class="mx-auto grid max-w-xl grid-cols-3">
            <li v-for="tab in tabs" :key="tab.href">
                <Link
                    :href="tab.href"
                    class="flex min-h-14 flex-col items-center justify-center gap-0.5 text-xs font-medium"
                    :class="isActive(tab.match) ? 'text-violet-600' : 'text-neutral-500'"
                    :aria-current="isActive(tab.match) ? 'page' : undefined"
                >
                    <component :is="tab.icon" class="size-6" aria-hidden="true" />
                    {{ tab.title }}
                </Link>
            </li>
        </ul>
    </nav>
</template>
```

- [ ] **Step 3: Tabs layout**

`resources/js/layouts/app/AppTabsLayout.vue`:
```vue
<script setup lang="ts">
import BottomTabBar from '@/components/BottomTabBar.vue';
import type { BreadcrumbItemType } from '@/types';
import { computed } from 'vue';

const props = withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItemType[] }>(), {
    breadcrumbs: () => [],
});

const title = computed(() => props.breadcrumbs.at(-1)?.title ?? 'GigRadar');
</script>

<template>
    <div class="flex min-h-svh flex-col bg-white dark:bg-neutral-950">
        <header
            class="sticky top-0 z-20 border-b border-neutral-200 bg-white/95 pt-[env(safe-area-inset-top)] backdrop-blur dark:border-neutral-800 dark:bg-neutral-950/95"
        >
            <div class="mx-auto flex h-14 w-full max-w-xl items-center px-4">
                <p class="truncate text-lg font-semibold">{{ title }}</p>
            </div>
        </header>

        <main class="mx-auto w-full min-w-0 max-w-xl flex-1 pb-[calc(3.5rem+env(safe-area-inset-bottom))]">
            <slot />
        </main>

        <BottomTabBar />
    </div>
</template>
```

Replace `resources/js/layouts/AppLayout.vue`:
```vue
<script setup lang="ts">
import AppTabsLayout from '@/layouts/app/AppTabsLayout.vue';
import type { BreadcrumbItemType } from '@/types';

withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItemType[] }>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppTabsLayout :breadcrumbs="breadcrumbs">
        <slot />
    </AppTabsLayout>
</template>
```

- [ ] **Step 4: Remove the old shell**

Delete these now-unused files:
- `resources/js/layouts/app/AppSidebarLayout.vue`, `resources/js/layouts/app/AppHeaderLayout.vue`
- `resources/js/components/AppSidebar.vue`, `AppSidebarHeader.vue`, `AppHeader.vue`, `AppShell.vue`, `AppContent.vue`, `NavMain.vue`, `NavUser.vue`, `NavFooter.vue`, `UserMenuContent.vue`, `UserInfo.vue`

Before deleting each, run `grep -rn "<FileNameWithoutExt>" resources/js --include=*.vue --include=*.ts` and only delete it if nothing outside the files being deleted imports it. Report anything you kept and why. Do **not** delete anything under `resources/js/components/ui/`.

- [ ] **Step 5: Verify**

Run: `npm run build` (must pass) and `npx vue-tsc --noEmit 2>&1 | grep -v TS2688` (expected: no errors; TS2688 is a known starter-kit tsconfig warning). Then `php artisan test` — all PASS.

- [ ] **Step 6: Commit**
```bash
git add -A resources/js resources/views/app.blade.php
git commit -m "feat: mobile app shell with bottom tab bar"
```

---

### Task 9: Manual check on iPhone (over Tailscale)

The app is served at `https://shalins-macbook-pro.tail1fa0db.ts.net` (Tailscale Serve → `php artisan serve` on 127.0.0.1:8000). After `npm run build`, the running server serves the new assets.

- [ ] Log in → lands on **My Artists** with the bottom tab bar; followed artists listed; "Upcoming near you" prompts to set a location.
- [ ] **Settings** tab → Alert settings: type "Leicester", pick it; or "Use my current location" (Safari asks permission). Change distance; untick email; **Save** → "Saved."
- [ ] Back on **My Artists**: "Upcoming near you" lists concerts within the radius with distances.
- [ ] Open an artist from My Artists; go back; tabs highlight correctly; nothing is hidden behind the tab bar or the iPhone notch/home indicator.
- [ ] Settings → **Log out** works.
- [ ] `git push origin main`.
