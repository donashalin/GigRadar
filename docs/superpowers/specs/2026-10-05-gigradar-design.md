# GigRadar — Design Spec

**Date:** 2026-10-05
**Status:** Approved design, pending implementation plan
**Type:** Personal side project — iOS App Store app

## 1. Purpose

GigRadar alerts fans when their favourite artists announce new concert dates or locations. Users register, search for artists, follow them, browse upcoming concerts, and receive push notifications when new tour dates are released.

## 2. Decisions

| Area | Decision |
|---|---|
| iOS app | Native SwiftUI (iOS 17+, `@Observable`) |
| Backend | Firebase: Authentication, Firestore, Cloud Functions (TypeScript), Cloud Messaging (FCM → APNs) |
| Concert data | Ticketmaster Discovery API (free key, 5,000 calls/day, 5 req/sec) |
| Sign-in | Sign in with Apple + email/password (with email verification and password reset) |
| Alert rules | Per-artist choice: `everywhere` or `nearby` (within user's radius of home location) |
| Firebase plan | Blaze (pay-as-you-go, required for scheduled functions); budget alert set at £5/month |

## 3. Architecture

```
iPhone (SwiftUI) ──Firebase SDK──► Firebase
                                   ├─ Auth
                                   ├─ Firestore
                                   ├─ Cloud Functions (TS)
                                   │   ├─ searchArtists()      callable  ──► Ticketmaster
                                   │   ├─ getArtistEvents()    callable  ──► Ticketmaster
                                   │   ├─ checkNewDates()      scheduled, every 6 hours
                                   │   ├─ onFollowWrite()      Firestore trigger (followerCount)
                                   │   └─ onUserDelete()       Auth trigger (data cleanup)
                                   └─ FCM ──► APNs ──► iPhone
```

**The app never calls Ticketmaster directly.** All Ticketmaster access goes through Cloud Functions, so that:
- the API key is stored as a Firebase secret (`TICKETMASTER_API_KEY`) and never ships in the app;
- results are cached in Firestore, keeping usage under the daily quota;
- the data source can be changed without an app update.

## 4. Screens

Tab bar with three tabs: **My Artists**, **Search**, **Settings**. Artist Detail is pushed onto the navigation stack from Search or My Artists.

1. **Welcome / Sign in** — shown when signed out. Sign in with Apple button; email sign-up, sign-in, and "forgot password".
2. **Search** — text field with 300 ms debounce, min 2 characters; calls `searchArtists`. Results show image, name, and Follow button.
3. **Artist Detail** — upcoming events (date, venue, city, country, status badge if cancelled/postponed, "Get tickets" opens `ticketUrl` in Safari). Follow/Unfollow toggle. Alert scope picker (`Everywhere` / `Near me`), shown only when following. Opening this screen updates the follow's `lastSeenAt`.
4. **My Artists** (home) — followed artists, sorted by name, with a "New" badge where any event's `firstSeenAt > follow.lastSeenAt`. Section "Upcoming near you": next 10 events across followed artists within the user's radius (hidden if no home location).
5. **Settings** — home location (city search via `MKLocalSearch`, or "Use my current location"), radius (25 / 50 / 100 / 250 miles, default 50), notifications toggle, sign out, delete account (with confirmation).

Empty states: My Artists with no follows shows a prompt linking to Search; Artist Detail with no events shows "No upcoming dates — we'll alert you when they're announced."

### Out of scope for v1
Spotify/Apple Music import, Android, on-sale/presale reminders, sharing, calendar view, multiple data sources, alerts on cancellations/postponements.

## 5. Data model (Firestore)

```
users/{uid}
  email: string
  displayName: string | null
  createdAt: timestamp
  homeLocation: { name: string, lat: number, lng: number } | null
  radiusMiles: number            // 25 | 50 | 100 | 250, default 50
  notificationsEnabled: boolean  // default true
  fcmTokens: string[]            // one per signed-in device

users/{uid}/follows/{artistId}
  artistId: string               // duplicated from doc ID for collection-group queries
  artistName: string             // denormalised
  imageUrl: string | null        // denormalised
  alertScope: "everywhere" | "nearby"   // default "everywhere"
  followedAt: timestamp
  lastSeenAt: timestamp

artists/{artistId}               // artistId = Ticketmaster attractionId
  name: string
  imageUrl: string | null
  followerCount: number
  lastCheckedAt: timestamp | null
  seeded: boolean                // true once initial events stored without alerting

artists/{artistId}/events/{eventId}   // eventId = Ticketmaster event id
  name: string
  date: timestamp                // local start date/time converted to UTC
  venueName: string
  city: string
  country: string
  lat: number | null
  lng: number | null
  ticketUrl: string
  status: "onsale" | "offsale" | "cancelled" | "postponed" | "rescheduled"
  firstSeenAt: timestamp
```

### Security rules
- `users/{uid}` and `users/{uid}/follows/**`: read/write only when `request.auth.uid == uid`. Clients cannot write `fcmTokens` of other users.
- `artists/**`: read for any signed-in user; no client writes (only Cloud Functions, via the Admin SDK).
- Everything else denied.

### Indexes
- Collection-group index on `follows.artistId` supports "all followers of artist X" (document IDs can't be filtered in collection-group queries, hence the duplicated field).

## 6. Cloud Functions

### `searchArtists(query: string)` — callable, auth required
Calls Ticketmaster `attractions.json?keyword=…&classificationName=music&size=20`. Returns `[{ id, name, imageUrl }]`. Results cached in memory for 10 minutes per normalised query to absorb debounce-adjacent duplicates.

### `getArtistEvents(artistId: string)` — callable, auth required
- If `artists/{artistId}` exists and `lastCheckedAt` is within 6 hours, return stored events.
- Otherwise fetch `events.json?attractionId=…&classificationName=music&sort=date,asc&size=200`, upsert the artist doc and events, set `seeded = true`, and return events. **No alerts are sent from this function.**

### `onFollowWrite` — Firestore trigger on `users/{uid}/follows/{artistId}`
Create → increment `artists/{artistId}.followerCount` (creating the artist doc if missing). Delete → decrement.

### `checkNewDates` — scheduled, every 6 hours (Europe/London)
1. Query `artists` where `followerCount > 0`.
2. For each artist (throttled to ≤ 4 req/sec), fetch upcoming events from Ticketmaster.
3. Diff returned event IDs against stored events. Unseen IDs are **new**: store with `firstSeenAt = now`. Update fields (status, date, etc.) on existing events.
4. If the artist was not yet `seeded`, set `seeded = true` and send no alerts.
5. Otherwise, for new events with status not `cancelled`, query followers (`follows` collection group where `artistId == X`) and select recipients per follow:
   - `everywhere` → alert.
   - `nearby` → alert if haversine distance (venue, user `homeLocation`) ≤ `radiusMiles`; if user has no `homeLocation` or event has no coordinates, alert.
   - Skip users with `notificationsEnabled == false` or no `fcmTokens`.
6. Send **one push per artist per user per run**: title = artist name; body = "Announced N new date(s), including {city} – {d MMM}" (earliest qualifying event). Payload includes `artistId` so a tap opens Artist Detail.
7. Delete events with `date` before today.
8. Set `lastCheckedAt = now` on success.

### `onUserDelete` — Auth trigger
Deletes `users/{uid}` and its `follows` (each delete fires `onFollowWrite`, decrementing counts).

### Module boundaries (functions/src)
- `ticketmaster.ts` — the only module aware of Ticketmaster; maps API JSON to internal `Artist`/`Event` types.
- `alerts.ts` — pure functions: `diffEvents`, `distanceMiles`, `selectRecipients`, `buildMessage`. No I/O.
- `scheduler.ts` — `checkNewDates` orchestration.
- `callable.ts` — `searchArtists`, `getArtistEvents`.
- `triggers.ts` — `onFollowWrite`, `onUserDelete`.

## 7. Error handling

- **Ticketmaster failure / 429 during scheduler:** log, skip that artist, leave `lastCheckedAt` unchanged so it's retried next run. Never abort the whole run.
- **Ticketmaster failure in callables:** return stored data if any exists, otherwise a `unavailable` error the app shows as "Couldn't load — Retry".
- **Invalid FCM token** (`messaging/registration-token-not-registered`): remove it from the user's `fcmTokens`.
- **App network errors:** inline error with Retry on Search, Artist Detail, My Artists.
- **Push permission denied:** Settings shows a notice with a button opening iOS Settings.
- **Quota:** at 4 runs/day, ~1,000 followed artists ≈ 4,000 calls/day. If exceeded in future, check artists with fewer followers less frequently (not built in v1).

## 8. iOS project structure

```
ios/GigRadar/
  App/        GigRadarApp.swift, RootView (signed-in vs signed-out), MainTabView
  Features/   Auth/, Search/, Artist/, MyArtists/, Settings/   (View + ViewModel each)
  Services/   AuthService, ArtistService, FollowService, UserService, PushService
              (protocols + Firebase implementations, so ViewModels can be tested with fakes)
  Models/     Artist, Event, Follow, UserProfile (Codable)
```

Dependencies via Swift Package Manager: `firebase-ios-sdk` (FirebaseAuth, FirebaseFirestore, FirebaseFunctions, FirebaseMessaging).

## 9. Testing

- **Functions:** Vitest unit tests for `alerts.ts` (new-event diffing, radius maths, recipient selection, one grouped message per artist, seeding produces no alerts). `ticketmaster.ts` mapping tested against recorded JSON fixtures.
- **Integration:** Firebase Emulator Suite for callables, triggers, and scheduler with Ticketmaster mocked.
- **Security rules:** `@firebase/rules-unit-testing` against the emulator — users cannot read/write others' data; clients cannot write `artists/**`.
- **iOS:** Swift Testing unit tests on ViewModels using fake services. Manual end-to-end on a physical iPhone for push.

## 10. Build order & release

1. Backend: Firebase project, functions, rules, emulator, tests.
2. iOS (free Apple ID is sufficient): email auth → Search → Artist Detail → Follow → My Artists → Settings → delete account.
3. **Join Apple Developer Program** ($99/yr).
4. Sign in with Apple, push notifications (APNs key uploaded to Firebase).
5. TestFlight beta.
6. App Store submission: privacy policy URL, privacy nutrition label (email, coarse location, device token), screenshots, account deletion in-app.

## 11. Prerequisites

- Google account → Firebase project on Blaze plan with budget alert.
- Ticketmaster developer account → Consumer Key stored via `firebase functions:secrets:set TICKETMASTER_API_KEY`.
- Xcode (latest), Node 20+, Firebase CLI.
- Apple Developer Program membership (from step 3 of build order).

## 12. Known constraint: development Mac

Current machine is a 2017 Intel MacBook Pro capped at macOS 13 / Xcode 15.4. Since April 2026 App Store and TestFlight uploads require Xcode 26+ (macOS 15+). Implications:
- Build-order steps 1–2 (backend, iOS screens in the simulator) can proceed on this Mac.
- Firebase iOS SDK must be pinned to the latest version supporting Xcode 15.
- Before step 5 (TestFlight), choose one: newer Apple Silicon Mac (preferred), GitHub Actions macOS runner for builds/uploads, or a rented cloud Mac.
