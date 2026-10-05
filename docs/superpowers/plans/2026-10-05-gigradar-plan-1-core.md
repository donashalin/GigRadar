# GigRadar Plan 1 — Core App (Search, Artist, Follow) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A logged-in, email-verified user can search Ticketmaster for artists, open an artist page showing upcoming concerts, and follow/unfollow artists with a per-artist alert scope.

**Architecture:** Laravel app scaffolded from the official Vue starter kit (Inertia + Vue 3 + TypeScript + Tailwind). All Ticketmaster access goes through one server-side client class that returns DTOs; a sync service stores concerts in MySQL and records when each was first seen. Controllers render Inertia pages from the database.

**Tech Stack:** Laravel (latest), PHP 8.3, MySQL 8.4 (local), SQLite in-memory (tests), Pest, Inertia + Vue 3 + TypeScript, Tailwind.

**Spec:** `docs/superpowers/specs/2026-10-05-gigradar-design.md` (this plan covers build-order steps 1–3).

**Later plans:** Plan 2 — My Artists, Settings, mobile tab bar. Plan 3 — check-dates command, notifications, PWA + web push, deploy.

---

## File map

| File | Responsibility |
|---|---|
| `database/migrations/2026_10_05_000001_add_gigradar_fields_to_users_table.php` | Location, radius, channel prefs on users |
| `database/migrations/2026_10_05_000002_create_artists_table.php` | Artists (keyed by Ticketmaster attraction ID) |
| `database/migrations/2026_10_05_000003_create_follows_table.php` | User↔artist pivot with alert scope |
| `database/migrations/2026_10_05_000004_create_concerts_table.php` | Concerts per artist |
| `app/Models/Artist.php`, `Concert.php`, `Follow.php`, `User.php` (modify) | Eloquent models + relationships |
| `database/factories/ArtistFactory.php`, `ConcertFactory.php` | Test data |
| `app/Services/Ticketmaster/TicketmasterClient.php` | Only code that talks to Ticketmaster |
| `app/Services/Ticketmaster/ArtistData.php`, `ConcertData.php` | DTOs returned by the client |
| `app/Services/Ticketmaster/TicketmasterException.php` | Client failure (code = HTTP status, 0 = network) |
| `app/Support/ConcertDiffer.php` | Pure: split fetched concerts into new / existing |
| `app/Services/ArtistSync.php` | Fetch + store concerts for one artist |
| `app/Services/ArtistResolver.php` | Find artist locally or create it from Ticketmaster |
| `app/Http/Controllers/SearchController.php` | Search page |
| `app/Http/Controllers/ArtistController.php` | Artist page |
| `app/Http/Controllers/FollowController.php` | Follow / unfollow / alert scope |
| `resources/js/pages/Search.vue`, `resources/js/pages/artists/Show.vue` | Inertia pages |
| `tests/Fixtures/ticketmaster/*.json` | Recorded API responses |

> **Vue/Laravel mapping note:** Inertia page names map to files in `resources/js/pages/` — `Inertia::render('artists/Show')` renders `resources/js/pages/artists/Show.vue`. Inertia's test helper fails if the `.vue` file does not exist, so each task creates the page before running its feature tests.

---

### Task 1: Scaffold the Laravel app into the repo

**Files:**
- Create: whole Laravel app at repo root (`/Users/shalin/Sites/GigRadar`), keeping existing `docs/` and `.git`

- [ ] **Step 1: Update Composer and start MySQL**

```bash
composer self-update
brew services start mysql
mysql -u root -e 'CREATE DATABASE IF NOT EXISTS gigradar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
```
Expected: Composer reports version 2.8 or later; the `mysql` command exits silently. If root has a password, add `-p` and use that password in Step 4.

- [ ] **Step 2: Create the starter kit in a sibling folder and move it in**

```bash
cd /Users/shalin/Sites
composer create-project laravel/vue-starter-kit gigradar-scaffold
rsync -a --exclude .git gigradar-scaffold/ GigRadar/
rm -rf gigradar-scaffold
cd GigRadar
```
Expected: `ls` shows `app/ artisan composer.json docs/ resources/ routes/ tests/ ...` and `git log` still shows the spec commits.

- [ ] **Step 3: Confirm Pest is present**

Run: `ls tests/Pest.php && grep -n "RefreshDatabase" tests/Pest.php`
Expected: the file exists and contains `->use(Illuminate\Foundation\Testing\RefreshDatabase::class)` (applied to `Feature`).
If `tests/Pest.php` is missing, run `composer require pestphp/pest pestphp/pest-plugin-laravel --dev --with-all-dependencies && ./vendor/bin/pest --init`, then make `tests/Pest.php` contain:
```php
<?php

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');
```

- [ ] **Step 4: Point `.env` at MySQL and name the app**

In `.env`, set these lines (replace the `DB_CONNECTION=sqlite` line and uncomment the `DB_*` lines):
```dotenv
APP_NAME=GigRadar
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gigradar
DB_USERNAME=root
DB_PASSWORD=
TICKETMASTER_API_KEY=
TICKETMASTER_THROTTLE_MS=250
```
Add the same two `TICKETMASTER_*` lines (empty key) to `.env.example`. Then:
```bash
rm -f database/database.sqlite
php artisan migrate
```
Expected: migrations run against MySQL with no errors.

- [ ] **Step 5: Install frontend deps and run the test suite**

```bash
npm install
npm run build
php artisan test
```
Expected: build succeeds; all starter-kit tests PASS. (Tests use SQLite in-memory as configured in `phpunit.xml`.)

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "chore: scaffold Laravel Vue starter kit"
```

---

### Task 2: Require email verification

**Files:**
- Modify: `app/Models/User.php`
- Test: `tests/Feature/EmailVerificationRequiredTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\User;

it('redirects unverified users away from the dashboard', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));
});

it('lets verified users see the dashboard', function () {
    $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=EmailVerificationRequiredTest`
Expected: first test FAILS (gets 200 instead of a redirect), because `User` does not implement `MustVerifyEmail`.

- [ ] **Step 3: Implement**

In `app/Models/User.php`, uncomment/add the import and implement the interface:
```php
use Illuminate\Contracts\Auth\MustVerifyEmail;
// ...
class User extends Authenticatable implements MustVerifyEmail
```

- [ ] **Step 4: Run the full suite**

Run: `php artisan test`
Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: require email verification"
```

---

### Task 3: Database schema, models and factories

**Files:**
- Create: the four migrations in the file map
- Create: `app/Models/Artist.php`, `app/Models/Concert.php`, `app/Models/Follow.php`
- Modify: `app/Models/User.php`
- Create: `database/factories/ArtistFactory.php`, `database/factories/ConcertFactory.php`
- Test: `tests/Feature/Models/RelationshipsTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Models/RelationshipsTest.php`:
```php
<?php

use App\Models\Artist;
use App\Models\Concert;
use App\Models\User;

it('lets a user follow an artist with default alert scope', function () {
    $user = User::factory()->create();
    $artist = Artist::factory()->create();

    $user->artists()->attach($artist, ['last_seen_at' => now()]);

    $followed = $user->artists()->first();
    expect($followed->is($artist))->toBeTrue()
        ->and($followed->pivot->alert_scope)->toBe('everywhere')
        ->and($artist->followers()->first()->is($user))->toBeTrue();
});

it('gives users sensible alert defaults', function () {
    $user = User::factory()->create()->fresh();

    expect($user->radius_miles)->toBe(50)
        ->and($user->notify_email)->toBeTrue()
        ->and($user->notify_push)->toBeTrue()
        ->and($user->home_lat)->toBeNull();
});

it('lets the same Ticketmaster event belong to two artists', function () {
    $a = Concert::factory()->create(['ticketmaster_id' => 'SHARED1']);
    $b = Concert::factory()->create(['ticketmaster_id' => 'SHARED1']);

    expect($a->artist->is($b->artist))->toBeFalse()
        ->and($a->artist->concerts)->toHaveCount(1);
});

it('deletes follows and concerts when an artist is deleted', function () {
    $user = User::factory()->create();
    $concert = Concert::factory()->create();
    $user->artists()->attach($concert->artist, ['last_seen_at' => now()]);

    $concert->artist->delete();

    expect(Concert::count())->toBe(0)->and($user->artists()->count())->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RelationshipsTest`
Expected: FAIL with `Class "App\Models\Artist" not found`.

- [ ] **Step 3: Create the migrations**

`database/migrations/2026_10_05_000001_add_gigradar_fields_to_users_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('home_location_name')->nullable();
            $table->decimal('home_lat', 10, 7)->nullable();
            $table->decimal('home_lng', 10, 7)->nullable();
            $table->unsignedSmallInteger('radius_miles')->default(50);
            $table->boolean('notify_email')->default(true);
            $table->boolean('notify_push')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['home_location_name', 'home_lat', 'home_lng', 'radius_miles', 'notify_email', 'notify_push']);
        });
    }
};
```

`database/migrations/2026_10_05_000002_create_artists_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artists', function (Blueprint $table) {
            $table->id();
            $table->string('ticketmaster_id')->unique();
            $table->string('name');
            $table->string('image_url', 1024)->nullable();
            $table->boolean('seeded')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artists');
    }
};
```

`database/migrations/2026_10_05_000003_create_follows_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->enum('alert_scope', ['everywhere', 'nearby'])->default('everywhere');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'artist_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follows');
    }
};
```

`database/migrations/2026_10_05_000004_create_concerts_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->string('ticketmaster_id');
            $table->string('name');
            $table->dateTime('starts_at');
            $table->string('venue_name');
            $table->string('city');
            $table->string('country');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('ticket_url', 1024);
            $table->enum('status', ['onsale', 'offsale', 'cancelled', 'postponed', 'rescheduled'])->default('onsale');
            $table->timestamp('first_seen_at');
            $table->timestamps();
            $table->unique(['artist_id', 'ticketmaster_id']);
            $table->index(['artist_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concerts');
    }
};
```

- [ ] **Step 4: Create the models**

`app/Models/Follow.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class Follow extends Pivot
{
    protected $table = 'follows';

    public $incrementing = true;

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }
}
```

`app/Models/Artist.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Artist extends Model
{
    use HasFactory;

    protected $fillable = ['ticketmaster_id', 'name', 'image_url', 'seeded', 'last_checked_at'];

    protected function casts(): array
    {
        return [
            'seeded' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    public function concerts(): HasMany
    {
        return $this->hasMany(Concert::class);
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows')
            ->using(Follow::class)
            ->withPivot(['alert_scope', 'last_seen_at'])
            ->withTimestamps();
    }

    public function isStale(): bool
    {
        return $this->last_checked_at === null || $this->last_checked_at->lt(now()->subHours(6));
    }
}
```

`app/Models/Concert.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Concert extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticketmaster_id', 'name', 'starts_at', 'venue_name', 'city', 'country',
        'lat', 'lng', 'ticket_url', 'status', 'first_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }
}
```

In `app/Models/User.php`, add the import and relationship, and extend `casts()`:
```php
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
// ...
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'home_lat' => 'float',
            'home_lng' => 'float',
            'radius_miles' => 'integer',
            'notify_email' => 'boolean',
            'notify_push' => 'boolean',
        ];
    }

    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class, 'follows')
            ->using(Follow::class)
            ->withPivot(['alert_scope', 'last_seen_at'])
            ->withTimestamps();
    }
```
(Keep any other entries already in the starter kit's `casts()`.)

- [ ] **Step 5: Create the factories**

`database/factories/ArtistFactory.php`:
```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Artist> */
class ArtistFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticketmaster_id' => 'K8'.fake()->unique()->bothify('??########'),
            'name' => fake()->unique()->name(),
            'image_url' => null,
            'seeded' => true,
            'last_checked_at' => now(),
        ];
    }

    public function unseeded(): static
    {
        return $this->state(['seeded' => false, 'last_checked_at' => null]);
    }
}
```

`database/factories/ConcertFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Artist;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Concert> */
class ConcertFactory extends Factory
{
    public function definition(): array
    {
        return [
            'artist_id' => Artist::factory(),
            'ticketmaster_id' => 'vv'.fake()->unique()->bothify('??##########'),
            'name' => fake()->words(3, true),
            'starts_at' => now()->addMonths(2),
            'venue_name' => 'O2 Academy',
            'city' => 'Leicester',
            'country' => 'GB',
            'lat' => 52.6369,
            'lng' => -1.1398,
            'ticket_url' => 'https://www.ticketmaster.co.uk/event/example',
            'status' => 'onsale',
            'first_seen_at' => now(),
        ];
    }
}
```

- [ ] **Step 6: Run tests**

Run: `php artisan test --filter=RelationshipsTest`
Expected: 4 PASS. Then `php artisan migrate` (local MySQL) — expected: 4 migrations run.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: add artists, follows and concerts schema"
```

---

### Task 4: Ticketmaster client and DTOs

**Files:**
- Create: `app/Services/Ticketmaster/ArtistData.php`, `ConcertData.php`, `TicketmasterException.php`, `TicketmasterClient.php`
- Modify: `config/services.php`, `app/Providers/AppServiceProvider.php`, `phpunit.xml`, `tests/TestCase.php`, `tests/Pest.php`
- Create: `tests/Fixtures/ticketmaster/attractions-search.json`, `attraction.json`, `events.json`, `empty.json`
- Test: `tests/Feature/Ticketmaster/TicketmasterClientTest.php`

- [ ] **Step 1: Add fixtures**

`tests/Fixtures/ticketmaster/attractions-search.json`:
```json
{
  "_embedded": {
    "attractions": [
      {
        "id": "K8vZ917G1V0",
        "name": "Fontaines D.C.",
        "images": [
          { "ratio": "3_2", "url": "https://s1.ticketm.net/dam/a/small.jpg", "width": 305 },
          { "ratio": "16_9", "url": "https://s1.ticketm.net/dam/a/large.jpg", "width": 1024 },
          { "ratio": "16_9", "url": "https://s1.ticketm.net/dam/a/medium.jpg", "width": 640 }
        ]
      },
      { "id": "K8vZ9171ob7", "name": "Fontaines Tribute Band" }
    ]
  },
  "page": { "size": 20, "totalElements": 2, "totalPages": 1, "number": 0 }
}
```

`tests/Fixtures/ticketmaster/attraction.json`:
```json
{
  "id": "K8vZ917G1V0",
  "name": "Fontaines D.C.",
  "images": [{ "ratio": "16_9", "url": "https://s1.ticketm.net/dam/a/large.jpg", "width": 1024 }]
}
```

`tests/Fixtures/ticketmaster/events.json`:
```json
{
  "_embedded": {
    "events": [
      {
        "id": "G5vYZ9abc001",
        "name": "Fontaines D.C. - Romance Tour",
        "url": "https://www.ticketmaster.co.uk/event/001",
        "dates": {
          "start": { "localDate": "2027-03-14", "dateTime": "2027-03-14T19:30:00Z" },
          "status": { "code": "onsale" }
        },
        "_embedded": {
          "venues": [{
            "name": "O2 Victoria Warehouse",
            "city": { "name": "Manchester" },
            "country": { "countryCode": "GB" },
            "location": { "latitude": "53.4668", "longitude": "-2.2850" }
          }]
        }
      },
      {
        "id": "G5vYZ9abc002",
        "name": "Fontaines D.C.",
        "url": "https://www.ticketmaster.co.uk/event/002",
        "dates": {
          "start": { "localDate": "2027-04-02" },
          "status": { "code": "cancelled" }
        },
        "_embedded": {
          "venues": [{ "name": "Olympia", "city": { "name": "Dublin" }, "country": { "countryCode": "IE" } }]
        }
      },
      {
        "id": "G5vYZ9abc003",
        "name": "Fontaines D.C. - TBA",
        "url": "https://www.ticketmaster.co.uk/event/003",
        "dates": { "start": { "dateTBA": true }, "status": { "code": "offsale" } },
        "_embedded": { "venues": [{ "name": "TBA", "city": { "name": "London" }, "country": { "countryCode": "GB" } }] }
      }
    ]
  },
  "page": { "size": 200, "totalElements": 3, "totalPages": 1, "number": 0 }
}
```

`tests/Fixtures/ticketmaster/empty.json`:
```json
{ "page": { "size": 20, "totalElements": 0, "totalPages": 0, "number": 0 } }
```

- [ ] **Step 2: Test configuration**

In `phpunit.xml`, inside `<php>`, add:
```xml
<env name="TICKETMASTER_API_KEY" value="test-key"/>
<env name="TICKETMASTER_THROTTLE_MS" value="0"/>
```

In `tests/TestCase.php`, block real HTTP calls in every test:
```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }
}
```
(If the starter kit's `TestCase` has other contents, keep them and add only the `setUp` method.)

At the end of `tests/Pest.php`, add a fixture helper:
```php
function tmFixture(string $name): array
{
    return json_decode(file_get_contents(__DIR__."/Fixtures/ticketmaster/{$name}.json"), true);
}

/** Query-string parameters of a faked HTTP request, as strings. */
function tmQuery(Illuminate\Http\Client\Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}
```

- [ ] **Step 3: Write the failing tests**

`tests/Feature/Ticketmaster/TicketmasterClientTest.php`:
```php
<?php

use App\Services\Ticketmaster\ArtistData;
use App\Services\Ticketmaster\ConcertData;
use App\Services\Ticketmaster\TicketmasterClient;
use App\Services\Ticketmaster\TicketmasterException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('searches music attractions and picks the widest 16:9 image', function () {
    Http::fake(['app.ticketmaster.com/discovery/v2/attractions.json*' => Http::response(tmFixture('attractions-search'))]);

    $results = app(TicketmasterClient::class)->searchAttractions('fontaines');

    expect($results)->toHaveCount(2)
        ->and($results[0])->toBeInstanceOf(ArtistData::class)
        ->and($results[0]->id)->toBe('K8vZ917G1V0')
        ->and($results[0]->name)->toBe('Fontaines D.C.')
        ->and($results[0]->imageUrl)->toBe('https://s1.ticketm.net/dam/a/large.jpg')
        ->and($results[1]->imageUrl)->toBeNull();

    Http::assertSent(fn (Request $r) => tmQuery($r)['keyword'] === 'fontaines'
        && tmQuery($r)['classificationName'] === 'music'
        && tmQuery($r)['apikey'] === 'test-key');
});

it('returns no attractions when Ticketmaster has none', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response(tmFixture('empty'))]);

    expect(app(TicketmasterClient::class)->searchAttractions('zzzz'))->toBe([]);
});

it('looks up a single attraction', function () {
    Http::fake(['app.ticketmaster.com/discovery/v2/attractions/K8vZ917G1V0.json*' => Http::response(tmFixture('attraction'))]);

    $artist = app(TicketmasterClient::class)->attraction('K8vZ917G1V0');

    expect($artist->name)->toBe('Fontaines D.C.')
        ->and($artist->imageUrl)->toBe('https://s1.ticketm.net/dam/a/large.jpg');
});

it('maps upcoming events to concerts and skips dateless ones', function () {
    Http::fake(['app.ticketmaster.com/discovery/v2/events.json*' => Http::response(tmFixture('events'))]);

    $concerts = app(TicketmasterClient::class)->upcomingEvents('K8vZ917G1V0');

    expect($concerts)->toHaveCount(2);

    [$manchester, $dublin] = $concerts;
    expect($manchester)->toBeInstanceOf(ConcertData::class)
        ->and($manchester->id)->toBe('G5vYZ9abc001')
        ->and($manchester->startsAt->toIso8601String())->toBe('2027-03-14T19:30:00+00:00')
        ->and($manchester->venueName)->toBe('O2 Victoria Warehouse')
        ->and($manchester->city)->toBe('Manchester')
        ->and($manchester->country)->toBe('GB')
        ->and($manchester->lat)->toBe(53.4668)
        ->and($manchester->lng)->toBe(-2.285)
        ->and($manchester->status)->toBe('onsale')
        ->and($dublin->startsAt->toDateString())->toBe('2027-04-02')
        ->and($dublin->lat)->toBeNull()
        ->and($dublin->status)->toBe('cancelled');

    Http::assertSent(fn (Request $r) => tmQuery($r)['attractionId'] === 'K8vZ917G1V0'
        && tmQuery($r)['sort'] === 'date,asc'
        && tmQuery($r)['size'] === '200');
});

it('throws with the HTTP status when Ticketmaster fails', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response([], 404)]);

    try {
        app(TicketmasterClient::class)->attraction('nope');
        $this->fail('Expected TicketmasterException');
    } catch (TicketmasterException $e) {
        expect($e->getCode())->toBe(404)->and($e->isNotFound())->toBeTrue();
    }
});

it('throws when Ticketmaster is unreachable', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::failedConnection()]);

    app(TicketmasterClient::class)->searchAttractions('x');
})->throws(TicketmasterException::class);
```

- [ ] **Step 4: Run tests to verify they fail**

Run: `php artisan test --filter=TicketmasterClientTest`
Expected: FAIL with `Class "App\Services\Ticketmaster\TicketmasterClient" not found`.

- [ ] **Step 5: Implement DTOs and exception**

`app/Services/Ticketmaster/ArtistData.php`:
```php
<?php

namespace App\Services\Ticketmaster;

final readonly class ArtistData
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $imageUrl,
    ) {}
}
```

`app/Services/Ticketmaster/ConcertData.php`:
```php
<?php

namespace App\Services\Ticketmaster;

use Carbon\CarbonImmutable;

final readonly class ConcertData
{
    public function __construct(
        public string $id,
        public string $name,
        public CarbonImmutable $startsAt,
        public string $venueName,
        public string $city,
        public string $country,
        public ?float $lat,
        public ?float $lng,
        public string $ticketUrl,
        public string $status,
    ) {}
}
```

`app/Services/Ticketmaster/TicketmasterException.php`:
```php
<?php

namespace App\Services\Ticketmaster;

use RuntimeException;

/** Code is the HTTP status, or 0 when Ticketmaster could not be reached. */
class TicketmasterException extends RuntimeException
{
    public function isNotFound(): bool
    {
        return $this->getCode() === 404;
    }
}
```

- [ ] **Step 6: Implement the client**

`app/Services/Ticketmaster/TicketmasterClient.php`:
```php
<?php

namespace App\Services\Ticketmaster;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TicketmasterClient
{
    private const BASE_URL = 'https://app.ticketmaster.com/discovery/v2';

    private const STATUSES = ['onsale', 'offsale', 'cancelled', 'postponed', 'rescheduled'];

    private int $lastRequestNs = 0;

    public function __construct(
        private readonly string $apiKey,
        private readonly int $throttleMs,
    ) {}

    /** @return list<ArtistData> */
    public function searchAttractions(string $keyword): array
    {
        $json = $this->get('/attractions.json', [
            'keyword' => $keyword,
            'classificationName' => 'music',
            'size' => 20,
        ]);

        return array_map($this->toArtist(...), $json['_embedded']['attractions'] ?? []);
    }

    public function attraction(string $id): ArtistData
    {
        return $this->toArtist($this->get('/attractions/'.rawurlencode($id).'.json'));
    }

    /** @return list<ConcertData> */
    public function upcomingEvents(string $attractionId): array
    {
        $json = $this->get('/events.json', [
            'attractionId' => $attractionId,
            'classificationName' => 'music',
            'sort' => 'date,asc',
            'size' => 200,
        ]);

        $concerts = array_map($this->toConcert(...), $json['_embedded']['events'] ?? []);

        return array_values(array_filter($concerts));
    }

    private function get(string $path, array $query = []): array
    {
        $this->throttle();

        try {
            $response = Http::baseUrl(self::BASE_URL)
                ->acceptJson()
                ->timeout(10)
                ->get($path, [...$query, 'apikey' => $this->apiKey]);
        } catch (ConnectionException $e) {
            throw new TicketmasterException('Ticketmaster unreachable: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new TicketmasterException("Ticketmaster {$path} failed with HTTP {$response->status()}", $response->status());
        }

        return $response->json() ?? [];
    }

    private function throttle(): void
    {
        if ($this->throttleMs <= 0) {
            return;
        }

        if ($this->lastRequestNs > 0) {
            $elapsedMs = (hrtime(true) - $this->lastRequestNs) / 1_000_000;
            if ($elapsedMs < $this->throttleMs) {
                usleep((int) (($this->throttleMs - $elapsedMs) * 1000));
            }
        }

        $this->lastRequestNs = hrtime(true);
    }

    private function toArtist(array $attraction): ArtistData
    {
        $images = collect($attraction['images'] ?? []);
        $image = $images->where('ratio', '16_9')->sortByDesc('width')->first() ?? $images->first();

        return new ArtistData($attraction['id'], $attraction['name'], $image['url'] ?? null);
    }

    private function toConcert(array $event): ?ConcertData
    {
        $start = $event['dates']['start'] ?? [];
        $startsAt = match (true) {
            isset($start['dateTime']) => CarbonImmutable::parse($start['dateTime'])->utc(),
            isset($start['localDate']) => CarbonImmutable::parse($start['localDate'], 'UTC'),
            default => null,
        };

        if ($startsAt === null) {
            return null;
        }

        $venue = $event['_embedded']['venues'][0] ?? [];
        $status = $event['dates']['status']['code'] ?? 'onsale';

        return new ConcertData(
            id: $event['id'],
            name: $event['name'],
            startsAt: $startsAt,
            venueName: $venue['name'] ?? 'Venue TBA',
            city: $venue['city']['name'] ?? '',
            country: $venue['country']['countryCode'] ?? '',
            lat: isset($venue['location']['latitude']) ? (float) $venue['location']['latitude'] : null,
            lng: isset($venue['location']['longitude']) ? (float) $venue['location']['longitude'] : null,
            ticketUrl: $event['url'] ?? '',
            status: in_array($status, self::STATUSES, true) ? $status : 'onsale',
        );
    }
}
```

- [ ] **Step 7: Configure and bind the client**

In `config/services.php`, add to the returned array:
```php
    'ticketmaster' => [
        'key' => env('TICKETMASTER_API_KEY'),
        'throttle_ms' => (int) env('TICKETMASTER_THROTTLE_MS', 250),
    ],
```

In `app/Providers/AppServiceProvider.php`, in `register()`:
```php
        $this->app->singleton(\App\Services\Ticketmaster\TicketmasterClient::class, fn () => new \App\Services\Ticketmaster\TicketmasterClient(
            (string) config('services.ticketmaster.key'),
            (int) config('services.ticketmaster.throttle_ms'),
        ));
```

- [ ] **Step 8: Run tests**

Run: `php artisan test --filter=TicketmasterClientTest`
Expected: 6 PASS. Then `php artisan test` — all PASS.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: add Ticketmaster client"
```

---

### Task 5: ConcertDiffer (pure)

**Files:**
- Create: `app/Support/ConcertDiffer.php`
- Test: `tests/Unit/ConcertDifferTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Ticketmaster\ConcertData;
use App\Support\ConcertDiffer;
use Carbon\CarbonImmutable;

function concertData(string $id): ConcertData
{
    return new ConcertData($id, 'Gig', CarbonImmutable::parse('2027-01-01'), 'Venue', 'City', 'GB', null, null, 'https://x', 'onsale');
}

it('splits fetched concerts into new and existing by Ticketmaster id', function () {
    $result = ConcertDiffer::diff(['a', 'b'], [concertData('b'), concertData('c'), concertData('d')]);

    expect(array_map(fn ($c) => $c->id, $result['new']))->toBe(['c', 'd'])
        ->and(array_map(fn ($c) => $c->id, $result['existing']))->toBe(['b']);
});

it('treats everything as new when nothing is stored', function () {
    $result = ConcertDiffer::diff([], [concertData('a')]);

    expect($result['new'])->toHaveCount(1)->and($result['existing'])->toBe([]);
});

it('returns nothing when nothing is fetched', function () {
    expect(ConcertDiffer::diff(['a'], []))->toBe(['new' => [], 'existing' => []]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ConcertDifferTest`
Expected: FAIL with `Class "App\Support\ConcertDiffer" not found`.

- [ ] **Step 3: Implement**

`app/Support/ConcertDiffer.php`:
```php
<?php

namespace App\Support;

use App\Services\Ticketmaster\ConcertData;

final class ConcertDiffer
{
    /**
     * @param  list<string>  $storedIds
     * @param  list<ConcertData>  $fetched
     * @return array{new: list<ConcertData>, existing: list<ConcertData>}
     */
    public static function diff(array $storedIds, array $fetched): array
    {
        $stored = array_flip($storedIds);
        $result = ['new' => [], 'existing' => []];

        foreach ($fetched as $concert) {
            $result[isset($stored[$concert->id]) ? 'existing' : 'new'][] = $concert;
        }

        return $result;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ConcertDifferTest`
Expected: 3 PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: add ConcertDiffer"
```

---

### Task 6: ArtistSync and ArtistResolver services

**Files:**
- Create: `app/Services/ArtistSync.php`, `app/Services/ArtistResolver.php`
- Test: `tests/Feature/Services/ArtistSyncTest.php`, `tests/Feature/Services/ArtistResolverTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Services/ArtistSyncTest.php`:
```php
<?php

use App\Models\Artist;
use App\Models\Concert;
use App\Services\ArtistSync;
use App\Services\Ticketmaster\TicketmasterException;
use Illuminate\Support\Facades\Http;

// Fakes are set per test: when several Http::fake() patterns match, the first registered wins,
// so a shared beforeEach fake could not be overridden by the failure test.

it('stores fetched concerts as new and marks the artist seeded', function () {
    Http::fake(['app.ticketmaster.com/discovery/v2/events.json*' => Http::response(tmFixture('events'))]);
    $artist = Artist::factory()->unseeded()->create(['ticketmaster_id' => 'K8vZ917G1V0']);

    $new = app(ArtistSync::class)->syncEvents($artist);

    expect($new)->toHaveCount(2)
        ->and($artist->concerts()->count())->toBe(2)
        ->and($artist->fresh()->seeded)->toBeTrue()
        ->and($artist->fresh()->last_checked_at)->not->toBeNull();

    $manchester = $artist->concerts()->where('ticketmaster_id', 'G5vYZ9abc001')->first();
    expect($manchester->city)->toBe('Manchester')
        ->and($manchester->lat)->toBe(53.4668)
        ->and($manchester->first_seen_at)->not->toBeNull();
});

it('returns only unseen concerts and updates existing ones', function () {
    Http::fake(['app.ticketmaster.com/discovery/v2/events.json*' => Http::response(tmFixture('events'))]);
    $artist = Artist::factory()->create(['ticketmaster_id' => 'K8vZ917G1V0']);
    $existing = Concert::factory()->for($artist)->create([
        'ticketmaster_id' => 'G5vYZ9abc002',
        'status' => 'onsale',
        'first_seen_at' => now()->subWeek(),
    ]);

    $new = app(ArtistSync::class)->syncEvents($artist);

    expect($new->pluck('ticketmaster_id')->all())->toBe(['G5vYZ9abc001'])
        ->and($existing->fresh()->status)->toBe('cancelled')
        ->and($existing->fresh()->first_seen_at->lt(now()->subDays(6)))->toBeTrue();
});

it('leaves last_checked_at untouched when Ticketmaster fails', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response([], 500)]);
    $artist = Artist::factory()->unseeded()->create();

    expect(fn () => app(ArtistSync::class)->syncEvents($artist))->toThrow(TicketmasterException::class);
    expect($artist->fresh()->last_checked_at)->toBeNull()
        ->and($artist->fresh()->seeded)->toBeFalse();
});
```

`tests/Feature/Services/ArtistResolverTest.php`:
```php
<?php

use App\Models\Artist;
use App\Services\ArtistResolver;
use Illuminate\Support\Facades\Http;

it('returns an existing artist without calling Ticketmaster', function () {
    $artist = Artist::factory()->create();
    Http::fake();

    expect(app(ArtistResolver::class)->resolve($artist->ticketmaster_id)->is($artist))->toBeTrue();
    Http::assertNothingSent();
});

it('creates an unseeded artist from Ticketmaster when unknown', function () {
    Http::fake(['app.ticketmaster.com/discovery/v2/attractions/K8vZ917G1V0.json*' => Http::response(tmFixture('attraction'))]);

    $artist = app(ArtistResolver::class)->resolve('K8vZ917G1V0');

    expect($artist->exists)->toBeTrue()
        ->and($artist->name)->toBe('Fontaines D.C.')
        ->and($artist->image_url)->toBe('https://s1.ticketm.net/dam/a/large.jpg')
        ->and($artist->seeded)->toBeFalse()
        ->and($artist->last_checked_at)->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter='ArtistSyncTest|ArtistResolverTest'`
Expected: FAIL with `Class "App\Services\ArtistSync" not found`.

- [ ] **Step 3: Implement ArtistSync**

`app/Services/ArtistSync.php`:
```php
<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\Concert;
use App\Services\Ticketmaster\ConcertData;
use App\Services\Ticketmaster\TicketmasterClient;
use App\Support\ConcertDiffer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ArtistSync
{
    public function __construct(private readonly TicketmasterClient $ticketmaster) {}

    /**
     * Fetch the artist's upcoming concerts and store them.
     * Sets seeded = true and last_checked_at = now on success.
     *
     * @return Collection<int, Concert> concerts not seen before this call
     *
     * @throws \App\Services\Ticketmaster\TicketmasterException
     */
    public function syncEvents(Artist $artist): Collection
    {
        $fetched = $this->ticketmaster->upcomingEvents($artist->ticketmaster_id);
        $storedIds = $artist->concerts()->pluck('ticketmaster_id')->all();
        ['new' => $new, 'existing' => $existing] = ConcertDiffer::diff($storedIds, $fetched);

        return DB::transaction(function () use ($artist, $new, $existing) {
            $now = now();

            $created = collect($new)->map(fn (ConcertData $c) => $artist->concerts()->create([
                ...$this->attributes($c),
                'ticketmaster_id' => $c->id,
                'first_seen_at' => $now,
            ]));

            foreach ($existing as $c) {
                $artist->concerts()->where('ticketmaster_id', $c->id)->update($this->attributes($c));
            }

            $artist->forceFill(['seeded' => true, 'last_checked_at' => $now])->save();

            return $created;
        });
    }

    private function attributes(ConcertData $c): array
    {
        return [
            'name' => $c->name,
            'starts_at' => $c->startsAt,
            'venue_name' => $c->venueName,
            'city' => $c->city,
            'country' => $c->country,
            'lat' => $c->lat,
            'lng' => $c->lng,
            'ticket_url' => $c->ticketUrl,
            'status' => $c->status,
        ];
    }
}
```

- [ ] **Step 4: Implement ArtistResolver**

`app/Services/ArtistResolver.php`:
```php
<?php

namespace App\Services;

use App\Models\Artist;
use App\Services\Ticketmaster\TicketmasterClient;

class ArtistResolver
{
    public function __construct(private readonly TicketmasterClient $ticketmaster) {}

    /** @throws \App\Services\Ticketmaster\TicketmasterException */
    public function resolve(string $ticketmasterId): Artist
    {
        $artist = Artist::firstWhere('ticketmaster_id', $ticketmasterId);
        if ($artist) {
            return $artist;
        }

        $data = $this->ticketmaster->attraction($ticketmasterId);

        return Artist::firstOrCreate(
            ['ticketmaster_id' => $data->id],
            ['name' => $data->name, 'image_url' => $data->imageUrl],
        );
    }
}
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter='ArtistSyncTest|ArtistResolverTest'`
Expected: 5 PASS.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add ArtistSync and ArtistResolver"
```

---

### Task 7: Search page

**Files:**
- Create: `app/Http/Controllers/SearchController.php`, `resources/js/pages/Search.vue`
- Modify: `routes/web.php`, `resources/js/components/AppSidebar.vue`
- Test: `tests/Feature/SearchTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/SearchTest.php`:
```php
<?php

use App\Models\Artist;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

it('requires login', function () {
    $this->get('/search')->assertRedirect('/login');
});

it('requires a verified email', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get('/search')->assertRedirect(route('verification.notice'));
});

it('does not search for fewer than 2 characters', function () {
    Http::fake();

    $this->actingAs(User::factory()->create())->get('/search?q=a')
        ->assertInertia(fn (Assert $page) => $page->component('Search')->where('q', 'a')->has('results', 0));

    Http::assertNothingSent();
});

it('shows results with following flags', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response(tmFixture('attractions-search'))]);
    $user = User::factory()->create();
    $user->artists()->attach(Artist::factory()->create(['ticketmaster_id' => 'K8vZ917G1V0']), ['last_seen_at' => now()]);

    $this->actingAs($user)->get('/search?q=fontaines')
        ->assertInertia(fn (Assert $page) => $page->component('Search')
            ->has('results', 2)
            ->where('results.0.id', 'K8vZ917G1V0')
            ->where('results.0.name', 'Fontaines D.C.')
            ->where('results.0.following', true)
            ->where('results.1.following', false)
            ->where('error', null));
});

it('caches repeated searches', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response(tmFixture('attractions-search'))]);
    $user = User::factory()->create();

    $this->actingAs($user)->get('/search?q=Fontaines');
    $this->actingAs($user)->get('/search?q=fontaines%20');

    Http::assertSentCount(1);
});

it('shows an error when Ticketmaster is down', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response([], 503)]);

    $this->actingAs(User::factory()->create())->get('/search?q=fontaines')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('results', 0)
            ->where('error', 'Search is unavailable right now. Please try again.'));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SearchTest`
Expected: FAIL (404 for `/search`).

- [ ] **Step 3: Implement the controller and route**

`app/Http/Controllers/SearchController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Services\Ticketmaster\ArtistData;
use App\Services\Ticketmaster\TicketmasterClient;
use App\Services\Ticketmaster\TicketmasterException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    public function __invoke(Request $request, TicketmasterClient $ticketmaster): Response
    {
        $q = trim((string) $request->query('q', ''));
        $results = [];
        $error = null;

        if (mb_strlen($q) >= 2) {
            try {
                $results = Cache::remember(
                    'tm:search:'.md5(mb_strtolower($q)),
                    now()->addMinutes(10),
                    fn () => $ticketmaster->searchAttractions($q),
                );
            } catch (TicketmasterException $e) {
                report($e);
                $error = 'Search is unavailable right now. Please try again.';
            }
        }

        $followed = $request->user()->artists()->pluck('ticketmaster_id')->flip();

        return Inertia::render('Search', [
            'q' => $q,
            'results' => array_map(fn (ArtistData $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'imageUrl' => $a->imageUrl,
                'following' => $followed->has($a->id),
            ], $results),
            'error' => $error,
        ]);
    }
}
```

In `routes/web.php`, add (below the existing dashboard route):
```php
use App\Http\Controllers\SearchController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('search', SearchController::class)->name('search');
});
```
(Put the `use` statement with the other imports at the top of the file.)

- [ ] **Step 4: Create the Vue page**

`resources/js/pages/Search.vue`:
```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface Result {
    id: string;
    name: string;
    imageUrl: string | null;
    following: boolean;
}

const props = defineProps<{ q: string; results: Result[]; error: string | null }>();

const query = ref(props.q);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(query, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get('/search', value.trim().length >= 2 ? { q: value.trim() } : {}, {
            preserveState: true,
            replace: true,
            only: ['q', 'results', 'error'],
        });
    }, 300);
});

function toggleFollow(result: Result) {
    const url = `/artists/${result.id}/follow`;
    const options = { preserveScroll: true, preserveState: true, only: ['results'] };
    if (result.following) {
        router.delete(url, options);
    } else {
        router.post(url, {}, options);
    }
}
</script>

<template>
    <Head title="Search" />
    <AppLayout :breadcrumbs="[{ title: 'Search', href: '/search' }]">
        <div class="mx-auto w-full max-w-xl p-4">
            <input
                v-model="query"
                type="search"
                autofocus
                placeholder="Search for an artist…"
                class="w-full rounded-xl border border-neutral-300 bg-white px-4 py-3 text-base dark:border-neutral-700 dark:bg-neutral-900"
            />

            <p v-if="error" class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ error }}</p>

            <p v-else-if="q.length >= 2 && results.length === 0" class="mt-6 text-center text-sm text-neutral-500">
                No artists found for “{{ q }}”.
            </p>

            <ul class="mt-4 divide-y divide-neutral-200 dark:divide-neutral-800">
                <li v-for="result in results" :key="result.id" class="flex items-center gap-3 py-3">
                    <Link :href="`/artists/${result.id}`" class="flex min-w-0 flex-1 items-center gap-3">
                        <img v-if="result.imageUrl" :src="result.imageUrl" alt="" class="size-12 shrink-0 rounded-lg object-cover" />
                        <div v-else class="size-12 shrink-0 rounded-lg bg-neutral-200 dark:bg-neutral-800" />
                        <span class="truncate font-medium">{{ result.name }}</span>
                    </Link>
                    <button
                        type="button"
                        class="shrink-0 rounded-full px-4 py-1.5 text-sm font-medium"
                        :class="result.following ? 'bg-neutral-200 dark:bg-neutral-800' : 'bg-violet-600 text-white'"
                        @click="toggleFollow(result)"
                    >
                        {{ result.following ? 'Following' : 'Follow' }}
                    </button>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 5: Add Search to the sidebar nav**

In `resources/js/components/AppSidebar.vue`, add `Search` to the `lucide-vue-next` import and add this entry to the `mainNavItems` array (after Dashboard):
```ts
    { title: 'Search', href: '/search', icon: Search },
```
(Plan 2 replaces the sidebar with a mobile bottom tab bar.)

- [ ] **Step 6: Run tests**

Run: `php artisan test --filter=SearchTest`
Expected: 6 PASS. Then `npm run build` — expected: no TypeScript/Vite errors.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: add artist search page"
```

---

### Task 8: Artist page

**Files:**
- Create: `app/Http/Controllers/ArtistController.php`, `resources/js/pages/artists/Show.vue`
- Modify: `routes/web.php`
- Test: `tests/Feature/ArtistPageTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/ArtistPageTest.php`:
```php
<?php

use App\Models\Artist;
use App\Models\Concert;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

it('resolves and seeds an unknown artist on first view', function () {
    Http::fake([
        'app.ticketmaster.com/discovery/v2/attractions/K8vZ917G1V0.json*' => Http::response(tmFixture('attraction')),
        'app.ticketmaster.com/discovery/v2/events.json*' => Http::response(tmFixture('events')),
    ]);

    $this->actingAs(User::factory()->create())->get('/artists/K8vZ917G1V0')
        ->assertInertia(fn (Assert $page) => $page->component('artists/Show')
            ->where('artist.name', 'Fontaines D.C.')
            ->has('concerts', 2)
            ->where('concerts.0.city', 'Manchester')
            ->where('following', false)
            ->where('alertScope', null)
            ->where('refreshFailed', false));

    expect(Artist::firstWhere('ticketmaster_id', 'K8vZ917G1V0')->seeded)->toBeTrue();
});

it('does not call Ticketmaster when the artist was checked recently', function () {
    Http::fake();
    $concert = Concert::factory()->create();

    $this->actingAs(User::factory()->create())->get("/artists/{$concert->artist->ticketmaster_id}")
        ->assertInertia(fn (Assert $page) => $page->has('concerts', 1));

    Http::assertNothingSent();
});

it('hides past concerts', function () {
    Http::fake();
    $artist = Artist::factory()->create();
    Concert::factory()->for($artist)->create(['starts_at' => now()->subDay()]);
    Concert::factory()->for($artist)->create(['starts_at' => now()->addDay()]);

    $this->actingAs(User::factory()->create())->get("/artists/{$artist->ticketmaster_id}")
        ->assertInertia(fn (Assert $page) => $page->has('concerts', 1));
});

it('shows saved concerts with a notice when a refresh fails', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response([], 500)]);
    $artist = Artist::factory()->create(['last_checked_at' => now()->subDay()]);
    Concert::factory()->for($artist)->create();

    $this->actingAs(User::factory()->create())->get("/artists/{$artist->ticketmaster_id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('concerts', 1)->where('refreshFailed', true));
});

it('returns 404 for an artist Ticketmaster does not know', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response([], 404)]);

    $this->actingAs(User::factory()->create())->get('/artists/UNKNOWN1')->assertNotFound();
});

it('returns 503 when an unknown artist cannot be looked up', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response([], 500)]);

    $this->actingAs(User::factory()->create())->get('/artists/UNKNOWN1')->assertStatus(503);
});

it('marks the artist as seen for a follower', function () {
    Http::fake();
    $user = User::factory()->create();
    $artist = Artist::factory()->create();
    $user->artists()->attach($artist, ['alert_scope' => 'nearby', 'last_seen_at' => now()->subWeek()]);

    $this->actingAs($user)->get("/artists/{$artist->ticketmaster_id}")
        ->assertInertia(fn (Assert $page) => $page->where('following', true)->where('alertScope', 'nearby'));

    expect($user->artists()->first()->pivot->last_seen_at->gt(now()->subMinute()))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ArtistPageTest`
Expected: FAIL (404 for `/artists/...`).

- [ ] **Step 3: Implement the controller and route**

`app/Http/Controllers/ArtistController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Concert;
use App\Services\ArtistResolver;
use App\Services\ArtistSync;
use App\Services\Ticketmaster\TicketmasterException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ArtistController extends Controller
{
    public function show(Request $request, string $ticketmasterId, ArtistResolver $resolver, ArtistSync $sync): Response
    {
        try {
            $artist = $resolver->resolve($ticketmasterId);
        } catch (TicketmasterException $e) {
            abort($e->isNotFound() ? 404 : 503);
        }

        $refreshFailed = false;
        if ($artist->isStale()) {
            try {
                $sync->syncEvents($artist);
            } catch (TicketmasterException $e) {
                report($e);
                $refreshFailed = true;
            }
        }

        $user = $request->user();
        $follow = $artist->followers()->where('users.id', $user->id)->first()?->pivot;
        if ($follow) {
            $user->artists()->updateExistingPivot($artist->id, ['last_seen_at' => now()]);
        }

        $concerts = $artist->concerts()
            ->where('starts_at', '>=', now()->startOfDay())
            ->orderBy('starts_at')
            ->get();

        return Inertia::render('artists/Show', [
            'artist' => [
                'ticketmasterId' => $artist->ticketmaster_id,
                'name' => $artist->name,
                'imageUrl' => $artist->image_url,
            ],
            'concerts' => $concerts->map(fn (Concert $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'startsAt' => $c->starts_at->toIso8601String(),
                'venueName' => $c->venue_name,
                'city' => $c->city,
                'country' => $c->country,
                'ticketUrl' => $c->ticket_url,
                'status' => $c->status,
            ])->values(),
            'following' => $follow !== null,
            'alertScope' => $follow?->alert_scope,
            'refreshFailed' => $refreshFailed,
        ]);
    }
}
```

In `routes/web.php`, add inside the `auth`/`verified` group from Task 7 (and import `App\Http\Controllers\ArtistController` at the top):
```php
    Route::get('artists/{ticketmasterId}', [ArtistController::class, 'show'])
        ->whereAlphaNumeric('ticketmasterId')
        ->name('artists.show');
```

- [ ] **Step 4: Create the Vue page**

`resources/js/pages/artists/Show.vue`:
```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';

type AlertScope = 'everywhere' | 'nearby';

interface Concert {
    id: number;
    name: string;
    startsAt: string;
    venueName: string;
    city: string;
    country: string;
    ticketUrl: string;
    status: 'onsale' | 'offsale' | 'cancelled' | 'postponed' | 'rescheduled';
}

const props = defineProps<{
    artist: { ticketmasterId: string; name: string; imageUrl: string | null };
    concerts: Concert[];
    following: boolean;
    alertScope: AlertScope | null;
    refreshFailed: boolean;
}>();

const followUrl = `/artists/${props.artist.ticketmasterId}/follow`;
const options = { preserveScroll: true };

function toggleFollow() {
    if (props.following) {
        router.delete(followUrl, options);
    } else {
        router.post(followUrl, {}, options);
    }
}

function setScope(alert_scope: AlertScope) {
    router.patch(followUrl, { alert_scope }, options);
}

const statusLabels: Partial<Record<Concert['status'], string>> = {
    cancelled: 'Cancelled',
    postponed: 'Postponed',
    rescheduled: 'Rescheduled',
};

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
}
</script>

<template>
    <Head :title="artist.name" />
    <AppLayout :breadcrumbs="[{ title: artist.name, href: `/artists/${artist.ticketmasterId}` }]">
        <div class="mx-auto w-full max-w-xl p-4">
            <img v-if="artist.imageUrl" :src="artist.imageUrl" alt="" class="aspect-video w-full rounded-2xl object-cover" />

            <div class="mt-4 flex items-center justify-between gap-3">
                <h1 class="text-2xl font-bold">{{ artist.name }}</h1>
                <button
                    type="button"
                    class="shrink-0 rounded-full px-5 py-2 text-sm font-medium"
                    :class="following ? 'bg-neutral-200 dark:bg-neutral-800' : 'bg-violet-600 text-white'"
                    @click="toggleFollow"
                >
                    {{ following ? 'Following' : 'Follow' }}
                </button>
            </div>

            <div v-if="following" class="mt-4">
                <p class="mb-2 text-sm text-neutral-500">Alert me about new dates</p>
                <div class="grid grid-cols-2 gap-1 rounded-xl bg-neutral-100 p-1 dark:bg-neutral-900">
                    <button
                        v-for="scope in (['everywhere', 'nearby'] as AlertScope[])"
                        :key="scope"
                        type="button"
                        class="rounded-lg py-2 text-sm font-medium"
                        :class="alertScope === scope ? 'bg-white shadow dark:bg-neutral-700' : 'text-neutral-500'"
                        @click="setScope(scope)"
                    >
                        {{ scope === 'everywhere' ? 'Everywhere' : 'Near me' }}
                    </button>
                </div>
            </div>

            <p v-if="refreshFailed" class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200">
                Couldn't refresh — showing saved dates.
            </p>

            <h2 class="mt-6 text-lg font-semibold">Upcoming concerts</h2>

            <p v-if="concerts.length === 0" class="mt-3 text-sm text-neutral-500">
                No upcoming dates — we'll alert you when they're announced.
            </p>

            <ul class="mt-2 divide-y divide-neutral-200 dark:divide-neutral-800">
                <li v-for="concert in concerts" :key="concert.id" class="flex items-center gap-3 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ formatDate(concert.startsAt) }}</p>
                        <p class="truncate text-sm text-neutral-500">{{ concert.venueName }} · {{ concert.city }}, {{ concert.country }}</p>
                        <span
                            v-if="statusLabels[concert.status]"
                            class="mt-1 inline-block rounded bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950 dark:text-red-300"
                        >
                            {{ statusLabels[concert.status] }}
                        </span>
                    </div>
                    <a
                        v-if="concert.ticketUrl && concert.status !== 'cancelled'"
                        :href="concert.ticketUrl"
                        target="_blank"
                        rel="noopener"
                        class="shrink-0 rounded-full border border-violet-600 px-4 py-1.5 text-sm font-medium text-violet-600"
                    >
                        Tickets
                    </a>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter=ArtistPageTest`
Expected: 7 PASS. Then `npm run build` — expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add artist page with upcoming concerts"
```

---

### Task 9: Follow, unfollow and alert scope

**Files:**
- Create: `app/Http/Controllers/FollowController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/FollowTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/FollowTest.php`:
```php
<?php

use App\Models\Artist;
use App\Models\User;
use Illuminate\Support\Facades\Http;

it('follows an artist and seeds its concerts without alerting', function () {
    Http::fake([
        'app.ticketmaster.com/discovery/v2/attractions/K8vZ917G1V0.json*' => Http::response(tmFixture('attraction')),
        'app.ticketmaster.com/discovery/v2/events.json*' => Http::response(tmFixture('events')),
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)->from('/search?q=fontaines')
        ->post('/artists/K8vZ917G1V0/follow')
        ->assertRedirect('/search?q=fontaines');

    $artist = $user->artists()->first();
    expect($artist->ticketmaster_id)->toBe('K8vZ917G1V0')
        ->and($artist->pivot->alert_scope)->toBe('everywhere')
        ->and($artist->seeded)->toBeTrue()
        ->and($artist->concerts()->count())->toBe(2);
});

it('does not duplicate a follow or reset its scope', function () {
    Http::fake();
    $user = User::factory()->create();
    $artist = Artist::factory()->create();
    $user->artists()->attach($artist, ['alert_scope' => 'nearby', 'last_seen_at' => now()]);

    $this->actingAs($user)->post("/artists/{$artist->ticketmaster_id}/follow")->assertRedirect();

    expect($user->artists()->count())->toBe(1)
        ->and($user->artists()->first()->pivot->alert_scope)->toBe('nearby');
});

it('still follows when seeding fails', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response([], 500)]);
    $user = User::factory()->create();
    $artist = Artist::factory()->unseeded()->create();

    $this->actingAs($user)->post("/artists/{$artist->ticketmaster_id}/follow")->assertRedirect();

    expect($user->artists()->count())->toBe(1)->and($artist->fresh()->seeded)->toBeFalse();
});

it('changes the alert scope', function () {
    $user = User::factory()->create();
    $artist = Artist::factory()->create();
    $user->artists()->attach($artist, ['last_seen_at' => now()]);

    $this->actingAs($user)->patch("/artists/{$artist->ticketmaster_id}/follow", ['alert_scope' => 'nearby'])->assertRedirect();

    expect($user->artists()->first()->pivot->alert_scope)->toBe('nearby');
});

it('rejects an invalid alert scope', function () {
    $user = User::factory()->create();
    $artist = Artist::factory()->create();
    $user->artists()->attach($artist, ['last_seen_at' => now()]);

    $this->actingAs($user)->patch("/artists/{$artist->ticketmaster_id}/follow", ['alert_scope' => 'mars'])
        ->assertSessionHasErrors('alert_scope');
});

it('404s when changing scope for an artist not followed', function () {
    $artist = Artist::factory()->create();

    $this->actingAs(User::factory()->create())
        ->patch("/artists/{$artist->ticketmaster_id}/follow", ['alert_scope' => 'nearby'])
        ->assertNotFound();
});

it('unfollows an artist', function () {
    $user = User::factory()->create();
    $artist = Artist::factory()->create();
    $user->artists()->attach($artist, ['last_seen_at' => now()]);

    $this->actingAs($user)->delete("/artists/{$artist->ticketmaster_id}/follow")->assertRedirect();

    expect($user->artists()->count())->toBe(0);
});

it('requires a verified user to follow', function () {
    $this->post('/artists/K8vZ917G1V0/follow')->assertRedirect('/login');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=FollowTest`
Expected: FAIL (405/404 on `/artists/.../follow`).

- [ ] **Step 3: Implement the controller and routes**

`app/Http/Controllers/FollowController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Services\ArtistResolver;
use App\Services\ArtistSync;
use App\Services\Ticketmaster\TicketmasterException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FollowController extends Controller
{
    public function store(Request $request, string $ticketmasterId, ArtistResolver $resolver, ArtistSync $sync): RedirectResponse
    {
        try {
            $artist = $resolver->resolve($ticketmasterId);
        } catch (TicketmasterException $e) {
            abort($e->isNotFound() ? 404 : 503);
        }

        $user = $request->user();
        if (! $user->artists()->where('artists.id', $artist->id)->exists()) {
            $user->artists()->attach($artist, ['last_seen_at' => now()]);
        }

        // Store current dates now so they never trigger "new date" alerts later.
        if (! $artist->seeded) {
            try {
                $sync->syncEvents($artist);
            } catch (TicketmasterException $e) {
                report($e); // the scheduler seeds it on its next run instead
            }
        }

        return back();
    }

    public function update(Request $request, string $ticketmasterId): RedirectResponse
    {
        $validated = $request->validate([
            'alert_scope' => ['required', Rule::in(['everywhere', 'nearby'])],
        ]);

        $artist = Artist::where('ticketmaster_id', $ticketmasterId)->firstOrFail();
        $user = $request->user();
        abort_unless($user->artists()->where('artists.id', $artist->id)->exists(), 404);
        $user->artists()->updateExistingPivot($artist->id, $validated);

        return back();
    }

    public function destroy(Request $request, string $ticketmasterId): RedirectResponse
    {
        $artist = Artist::where('ticketmaster_id', $ticketmasterId)->firstOrFail();
        $request->user()->artists()->detach($artist->id);

        return back();
    }
}
```

In `routes/web.php`, add inside the `auth`/`verified` group (and import `App\Http\Controllers\FollowController`):
```php
    Route::post('artists/{ticketmasterId}/follow', [FollowController::class, 'store'])
        ->whereAlphaNumeric('ticketmasterId')->name('follows.store');
    Route::patch('artists/{ticketmasterId}/follow', [FollowController::class, 'update'])
        ->whereAlphaNumeric('ticketmasterId')->name('follows.update');
    Route::delete('artists/{ticketmasterId}/follow', [FollowController::class, 'destroy'])
        ->whereAlphaNumeric('ticketmasterId')->name('follows.destroy');
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter=FollowTest`
Expected: 8 PASS. Then the full suite: `php artisan test` — all PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: follow, unfollow and per-artist alert scope"
```

---

### Task 10: Manual end-to-end check

- [ ] **Step 1: Add a real Ticketmaster key**

Register at developer.ticketmaster.com → My Apps → copy the **Consumer Key** into `.env` as `TICKETMASTER_API_KEY=...`. Then `php artisan config:clear`.

- [ ] **Step 2: Run the app**

Run: `composer run dev` and open http://localhost:8000.
Expected: Vite and the PHP server both start.

- [ ] **Step 3: Walk through the flow**

1. Register a new account. Mail is written to `storage/logs/laravel.log` (`MAIL_MAILER=log`) — open the verification link from the log.
2. Go to Search, type an artist you like (e.g. "Fontaines"). Results appear after you stop typing.
3. Tap Follow on a result → button changes to Following.
4. Open the artist → upcoming concerts are listed with Tickets links; the Everywhere / Near me toggle appears and switches.
5. Unfollow → toggle disappears.
6. Use browser dev tools' mobile view (iPhone size) to check nothing overflows horizontally.

- [ ] **Step 4: Push**

```bash
git push
```
