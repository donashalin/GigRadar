# GigRadar Plan 2c — iPhone-style Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the settings sub-nav layout with an iPhone Settings-style grouped list at `/settings`, where each row opens a focused full-screen sub-page with a "‹ Settings" back link, and every alert choice saves immediately.

**Architecture:** One `SettingsController@index` renders the list with summaries. Alert settings are split into two pages (Home location, Near me) plus an inline Email switch, all hitting the existing `PATCH /settings/alerts`, which becomes a **partial** update (`sometimes` rules). The tabs layout header gains an optional back link. The old `layouts/settings/Layout.vue` sub-nav is deleted.

**Tech Stack:** Laravel 12, Pest, Inertia v2 + Vue 3 + TypeScript, Tailwind, lucide-vue-next.

**Spec:** `docs/superpowers/specs/2026-10-05-gigradar-design.md` §4 item 5 (Settings).

Work directly on `main`. Never commit `.env`.

## Routes after this plan

| Route | Page |
|---|---|
| `GET /settings` (name `settings`) | `settings/Index` — the grouped list |
| `GET /settings/location` (`settings.location`) | `settings/Location` |
| `GET /settings/near-me` (`settings.near-me`) | `settings/NearMe` |
| `PATCH /settings/alerts` (`alerts.update`) | partial update → redirects **back** (or to `settings` when `redirect_to=settings` is sent) |
| `GET /settings/alerts/places`, `/reverse` | unchanged JSON endpoints |
| `GET /settings/profile`, `/password`, `/appearance` | existing pages, restyled |
| `GET /settings/alerts` | **301 → `/settings`** (keeps old links working) |

All new routes: `auth` + `verified`.

---

### Task 1: Backend — index, sub-pages, partial update

**Files:** create `app/Http/Controllers/Settings/SettingsController.php`; modify `AlertSettingsController.php`, `routes/settings.php`; tests `tests/Feature/Settings/SettingsIndexTest.php`, update `tests/Feature/Settings/AlertSettingsTest.php`.

- [ ] Tests first:
  - `GET /settings` renders `settings/Index` with props `alerts.homeLocationName` (null or name), `alerts.nearbySummary` ("Anywhere in United Kingdom" for country mode with GB; "Anywhere in my country" when country mode without a code; "Within 50 miles" for radius mode 50), `alerts.notifyEmail`; requires auth+verified.
  - `GET /settings/location` renders `settings/Location` with `homeLocationName`, `homeCountryCode`.
  - `GET /settings/near-me` renders `settings/NearMe` with `nearbyMode`, `radiusMiles`, `homeCountryCode`, `radiusOptions`.
  - `GET /settings/alerts` redirects (301) to `/settings`.
  - `PATCH /settings/alerts` with only `{notify_email: false}` saves it and leaves location/mode/radius untouched; with only `{nearby_mode:'radius', radius_miles:100}` saves those; with only the location fields saves them (country-code rules from Plan 2b still hold); `radius_miles` without `nearby_mode` is allowed; invalid values still 422/errors; `redirect_to=settings` redirects to `/settings`, otherwise back.
  - Existing AlertSettingsTest expectations that relied on `GET /settings/alerts` rendering a page or on `/settings` redirecting to `/settings/alerts` are updated to the new routes.
- [ ] `SettingsController@index`: build the summary using the same country-name logic as `MyArtistsController` (extract a small shared helper `App\Support\CountryName::for(?string $code): ?string` that returns the display name or the code, and use it in both controllers).
- [ ] `AlertSettingsController`: `update()` rules become `sometimes`-based: `home_location_name`/`home_lat`/`home_lng` keep `required_with` pairing; `radius_miles` → `['sometimes', 'integer', Rule::in(...)]`; `nearby_mode` → `['sometimes', Rule::in(['country','radius'])]`; `notify_email` → `['sometimes', 'boolean']`. Keep the country-code logic. Redirect: `$request->input('redirect_to') === 'settings' ? to_route('settings') : back()`. Remove `edit()`; add `location()` and `nearMe()` render actions.
- [ ] `routes/settings.php`: remove `Route::redirect('settings', 'settings/alerts')`; add the routes in the table above (put `/settings` in the `auth`+`verified` group).
- [ ] Commit `feat: settings index and split alert settings routes`.

### Task 2: Layout back link + settings list UI

**Files:** modify `resources/js/layouts/app/AppTabsLayout.vue`, `resources/js/layouts/AppLayout.vue`; create `resources/js/pages/settings/Index.vue`, `resources/js/components/settings/SettingsGroup.vue`, `resources/js/components/settings/SettingsRow.vue`.

- [ ] `AppLayout` and `AppTabsLayout` accept an optional `back?: { href: string; label: string }` prop. When set, the header shows a left-aligned `Link` "‹ {label}" (lucide `ChevronLeft` + label, `min-h-11`, violet text with dark variant, focus-visible outline) and the title centred; otherwise the current left-aligned title.
- [ ] `SettingsGroup.vue`: optional uppercase `title` (small, neutral-500/400, px-4) above a `rounded-xl` card with `divide-y` rows (light: white on neutral-100 page background; dark: neutral-900 card on neutral-950).
- [ ] `SettingsRow.vue`: props `label`, optional `value` (secondary text, truncates), optional `href` (renders an Inertia `Link` with a trailing `ChevronRight`), `destructive?: boolean` (red text, centred), and a default slot for trailing controls (e.g. a switch). Row: `flex min-h-12 items-center gap-3 px-4`, label `min-w-0 flex-1 truncate`.
- [ ] `settings/Index.vue` (title "Settings"):
  - Group **Alerts**: Home location › (`value` = name or "Not set", href `/settings/location`); Near me › (`value` = `nearbySummary`, href `/settings/near-me`); Email alerts row with an accessible switch (`role="switch"`, `aria-checked`, ≥44px tap area) that `router.patch('/settings/alerts', { notify_email: next }, { preserveScroll: true, preserveState: true })` with a pending guard and reverts on error.
  - Group **Account**: Profile ›, Password ›, Appearance › (`value` = current appearance from `useAppearance()`: "Light"/"Dark"/"System").
  - Group (no title): Log out row — `Link href="/logout" method="post" as="button"`, destructive.
  - Page background `bg-neutral-100 dark:bg-neutral-950` behind the groups; spacing `space-y-6 p-4`.
- [ ] Commit `feat: iPhone-style settings list`.

### Task 3: Home location and Near me sub-pages

**Files:** create `resources/js/pages/settings/Location.vue`, `resources/js/pages/settings/NearMe.vue`; delete `resources/js/pages/settings/Alerts.vue` (after moving its logic).

- [ ] `Location.vue` (back to Settings, title "Home location"): move the place search + "Use my current location" logic from `Alerts.vue` **with all its hardening** (separate AbortControllers, `lookupId` counter incl. unmount, Enter picks first result, 429 message, geolocation error codes, `maxlength=100`, role=alert errors, focus-visible outlines, dark variants). Show the current location at the top if set ("Current: Leicester, Leicestershire, United Kingdom"). Choosing a place (search result or current location) immediately `router.patch('/settings/alerts', { home_location_name, home_lat, home_lng, home_country_code, redirect_to: 'settings' })`. Below, when a location is set: a destructive "Remove home location" button that patches the three location fields as `null` with `redirect_to: 'settings'`. No Change/Cancel state any more (this page *is* the change flow; back = cancel).
- [ ] `NearMe.vue` (back to Settings, title "Near me"): a `SettingsGroup` of rows — "Anywhere in {country}" (or "Anywhere in my country"), then "Within 25/50/100/250 miles"; the selected row shows a violet `Check` icon and has `aria-checked="true"` inside a `role="radiogroup"` (each row `role="radio"`). Tapping a row patches `{ nearby_mode, radius_miles? }` with `preserveScroll`, pending guard. Below the group, the hint from Plan 2b when country mode has no country code ("Set your home location so we know which country." with a link to `/settings/location`, or "Re-pick your home location to use this." when a location exists without a code). A short footer note: "Used for “Near me” alerts and gigs near you."
- [ ] Delete `Alerts.vue`. Commit `feat: home location and near me pages`.

### Task 4: Restyle Profile, Password, Appearance; remove the old settings layout

**Files:** modify `resources/js/pages/settings/Profile.vue`, `Password.vue`, `Appearance.vue`; delete `resources/js/layouts/settings/Layout.vue`.

- [ ] Each page: drop `SettingsLayout`; pass `back: { href: '/settings', label: 'Settings' }` and a breadcrumb title ("Profile", "Password", "Appearance") to `AppLayout`; wrap content in `mx-auto w-full max-w-xl space-y-6 p-4`. Keep forms and behaviour unchanged (Profile keeps `DeleteUser` at the bottom). Remove the now-redundant in-page `HeadingSmall` titles only where they duplicate the header title; keep descriptive ones.
- [ ] Delete `layouts/settings/Layout.vue` after confirming with grep that nothing imports it.
- [ ] `npm run build`, `npx vue-tsc --noEmit 2>&1 | grep -v TS2688` clean; `php artisan test` green (starter-kit settings tests must still pass).
- [ ] Commit `feat: restyle account settings pages; remove settings sub-nav`.

### Task 5: Check on iPhone
- [ ] Settings tab → grouped list; rows open sub-pages with "‹ Settings"; picking a location returns to the list showing it; Near me ticks save; Email switch toggles and survives refresh; Log out works. Push to `main`.
