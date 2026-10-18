# Tickbuddy Mobile Integration Guide

Handoff document for building an Android companion app that syncs to the Tickbuddy Nextcloud backend. This file is the single source of truth for the HTTP API, data model, auth, and sync semantics. If anything here contradicts the code, the code wins — re-read `lib/Controller/*.php` and `lib/Service/*.php` and update this doc.

Backend repo: https://github.com/martinhammer/Tickbuddy
Nextcloud compatibility: 33–36
Backend version at time of writing: 1.0.6

> Don't hardcode that version — the app now advertises its version and feature set at runtime through the Nextcloud capabilities endpoint. Read it on connect; see §1 "Capability & version discovery".

---

## 1. Transport, auth, and request conventions

Tickbuddy exposes an **OCS (Open Collaboration Services) API** hosted inside a Nextcloud instance. OCS is a thin Nextcloud convention layered on top of plain HTTP + JSON.

### Base URL

```
{nextcloudBaseUrl}/ocs/v2.php/apps/tickbuddy
```

Example: `https://cloud.example.com/ocs/v2.php/apps/tickbuddy/api/tracks`

The user must supply their Nextcloud server URL — it is not discoverable. Accept it as a configuration field, validate scheme is `https://` (allow `http://` only with a toggle for self-hosted dev), strip trailing slashes.

### Authentication

Use **HTTP Basic Auth** with a Nextcloud **app password** (NOT the user's login password).

1. Direct users to `Settings → Security → Devices & sessions → Create new app password` on their Nextcloud instance.
2. They copy the generated token and paste it (or scan a QR) into the app, along with their login name.
3. Store credentials in Android's `EncryptedSharedPreferences` or the Keystore-backed `MasterKey` API. Never log them.
4. Send on every request: `Authorization: Basic base64(login:app-password)`.

App passwords can be revoked per-device without affecting the user's main password. If a request returns `401`, treat credentials as invalid and prompt re-entry.

> There is also a "Login Flow v2" poll-based flow (`/index.php/login/v2`) if you want to avoid having users paste tokens — recommended for production UX. See [Nextcloud login flow docs](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/LoginFlow/index.html). Out of scope for this doc, but worth implementing.

### Required headers

Every request MUST include:

| Header | Value | Purpose |
|---|---|---|
| `OCS-APIRequest` | `true` | Required by Nextcloud for any OCS call. Missing it → 404 or HTML error page. |
| `Accept` | `application/json` | Forces JSON response envelope. |
| `Authorization` | `Basic …` | See above. |

For `POST`/`PUT` requests sending form data, also set `Content-Type: application/x-www-form-urlencoded`. The backend reads params via `$this->request->getParam(...)`, which works with form-encoded bodies AND JSON bodies (Nextcloud merges both into the same param bag). Prefer form-encoded — it's what the existing web frontend uses and is well tested.

### OCS response envelope

Every OCS response is wrapped. A successful `GET /api/tracks` returns:

```json
{
  "ocs": {
    "meta": {
      "status": "ok",
      "statuscode": 200,
      "message": "OK"
    },
    "data": [ /* actual payload */ ]
  }
}
```

Your HTTP client should unwrap `response.ocs.data` before handing it to the rest of the app. Error responses have the same envelope with `status: "failure"` and `statuscode` matching the HTTP status.

HTTP status codes used by the backend:

| Code | Meaning |
|---|---|
| 200 | OK (reads, most writes) |
| 201 | Created (new track) |
| 204 | No Content (delete) |
| 400 | Bad input (invalid type/name, missing required param, wrong mode, file too large) |
| 401 | Unauthenticated |
| 403 | Track limit reached |
| 404 | Track not found |
| 409 | DB constraint conflict (e.g. duplicate on reorder) |

### CSRF

Not applicable for API clients authenticating with Basic auth — Nextcloud exempts Basic-authenticated OCS requests from CSRF. Don't send any CSRF token.

### Capability & version discovery

Tickbuddy registers a Nextcloud **capability**, so the client can discover the installed app version and which optional API features the server supports — without version-sniffing or probing endpoints.

**Important: this endpoint is NOT under the Tickbuddy base URL.** It's a core Nextcloud endpoint:

```
GET {nextcloudBaseUrl}/ocs/v2.php/cloud/capabilities
```

Same headers as every other call (`OCS-APIRequest: true`, `Accept: application/json`, Basic auth). Unwrap the usual OCS envelope, then read `data.capabilities.tickbuddy`:

```json
{
  "version": "1.0.6",
  "apiVersion": 1,
  "features": {
    "import": true,
    "export": true,
    "counterTracks": true,
    "privateTracks": true,
    "tickBounds": true,
    "archivedTracks": true,
    "syncDelta": false,
    "counterIncrement": true,
    "booleanSet": true,
    "stats": true,
    "notes": true
  }
}
```

- **`version`** — the installed app version string (from `info.xml`). Show it in an "About" / server-info screen.
- **`apiVersion`** — an integer contract version for the client-facing API. Bumped on a breaking change. Branch on this, not on `version`, when you need to gate behavior.
- **`features`** — capability flags. Treat every flag as **optional and possibly absent**: if the whole `tickbuddy` key is missing, the app isn't installed on that server (fail the connection with a clear message). If a specific flag is missing, assume `false`. Four flags gate optional behaviour: `tickBounds` (`GET /api/ticks/bounds` exists — see §3; absent on older servers, where that route 404s), `archivedTracks` (tracks can be archived and restored — see §3 `PUT /api/tracks/{id}`; an older server silently ignores the `archived` field and answers 200 without archiving, so **don't offer archive or restore without this flag**), `counterIncrement` (`POST /api/ticks/increment` exists — see §3 and §4), `booleanSet` (`POST /api/ticks/set` also takes yes/no tracks — see §3; an older server answers `400` there), `stats` (`GET /api/stats` exists — see §3; absent on older servers, where that route 404s), `notes` (notes on a track's day — see §3 Notes; an older server 404s the `/api/notes` routes and **silently ignores `note` on tick calls**, so never send one without this flag), plus `syncDelta`, which will flip to `true` in a future backend (see §4): a "changed since X" delta endpoint exists. Gate those code paths on the flags so the app lights them up automatically when the server is upgraded — no client release required.

Recommended usage: fetch capabilities once right after credential validation and cache it (refresh on app update or daily). A present `data.capabilities.tickbuddy` also doubles as a clean "is Tickbuddy installed here?" check during onboarding.

---

## 2. Data model

Two per-user tables, both scoped by `user_id` on the server side (you never send `user_id` — it's inferred from auth).

### Track

```
id          int        server-assigned
name        string     1..? chars, trimmed, non-empty
type        enum       "boolean" | "counter"    (immutable after create)
sortOrder   int        client-influenced display order
private     bool       hides from default views; export must opt-in
archivedAt  string?    "YYYY-MM-DD" the track was archived on; null while active
```

- **Max 99 tracks per user.** Creating the 100th returns `403` with `{message: "Maximum of 99 tracks reached"}`.
- **Track `type` is immutable.** `PUT /api/tracks/{id}` silently ignores any `type` field. If a user wants a different type, they must delete and recreate.
- **Deleting a track cascades**: all its ticks and notes are deleted server-side.
- **Archived tracks** (`archivedAt` set) are hidden from `GET /api/tracks` unless asked for, keep their ticks, and can't be ticked (writes are quietly ignored, see `toggle`/`set`). They still count towards the 99-track limit, so creating a track can fail with fewer than 99 visible.

### Tick

```
id       int       server-assigned (do not persist client-side; see sync section)
trackId  int       foreign key to Track
date     string    ISO date, "YYYY-MM-DD" exactly (no time, no timezone)
value    int       for boolean: always 1 when present; for counter: > 0
```

Two hard rules that your sync layer must respect:

1. **Sparse storage.** A tick row existing means "yes" or "count = value". No row means "no" or "count = 0". A boolean tick is never stored as `value=0`. A counter tick with value 0 is deleted, not kept.
2. **Uniqueness.** `(user_id, track_id, date)` is unique. You can't have two ticks for the same track on the same day.

### Note

> Requires `features.notes` (§1).

```
trackId  int       foreign key to Track
date     string    "YYYY-MM-DD"
text     string    plain text, 1..200 characters
```

- **One note per track per day**, keyed by `(trackId, date)`; there is no note id.
- **Independent of ticks.** A note can sit on a day with no tick, and unticking (which deletes the tick row) never deletes the note. A note-only day is not a tick anywhere: not in `GET /api/ticks`, `bounds` or `stats`.
- **Plain text, stored as sent.** The server turns `\r\n` and `\r` into `\n` and trims spaces, tabs and line breaks at both ends, nothing more. Inner line breaks and tabs are kept. It never strips or escapes markup, so **escape it for your own output** (Compose `Text` does). Length is counted in Unicode code points (`é` is one, a family or flag emoji several).
- **Empty means none.** Setting an empty (or whitespace-only) text deletes the note, everywhere a note is written.

### Dates

- Always `YYYY-MM-DD`, e.g. `"2026-04-24"`.
- Dates are **user-local**. The backend does no timezone math — the day a tick belongs to is whatever day the client says it is. Use the device's local date when the user taps "today".
- Weekend highlighting, first-day-of-week, etc. are **client concerns**. Backend stores and returns dates verbatim.

---

## 3. Endpoint reference

All paths below are relative to `{nextcloudBaseUrl}/ocs/v2.php/apps/tickbuddy`. All requests need the headers from §1. (The one exception is version/feature discovery, which lives on the core `/ocs/v2.php/cloud/capabilities` endpoint — see §1.)

### Tracks

#### `GET /api/tracks`
List the current user's tracks. Returns tracks in `sortOrder` ascending, then `id`.

Query params:
- `includeArchived` (boolean, default `false`). Without it, **archived tracks are left out** — which is what keeps clients that predate archiving working: an archived track simply disappears for them. With `true`, archived tracks are included in the same `sortOrder` order; split and sort them client-side.

Response `data`:
```json
[
  {"id": 1, "name": "Meditate", "type": "boolean", "sortOrder": 1, "private": false, "archivedAt": null},
  {"id": 2, "name": "Cups of coffee", "type": "counter", "sortOrder": 2, "private": false, "archivedAt": null}
]
```

Every endpoint that returns a track (list, create, update, reorder) includes `archivedAt`.

#### `POST /api/tracks`
Create a new track.

Form params:
- `name` (string, required, trimmed, non-empty, at most 255 characters)
- `type` (string, required, `"boolean"` or `"counter"`)

Responses:
- `201` + serialized track on success
- `400` on invalid name or type: `{message: "..."}` (`Track name cannot be empty`, `Track names can be at most 255 characters`)
- `403` if already at 99 tracks

#### `PUT /api/tracks/{id}`
Update an existing track. All fields optional; omitted fields untouched.

Form params (any subset):
- `name` (string, trimmed, non-empty if provided, at most 255 characters)
- `sortOrder` (int) — usually set via `/reorder` instead
- `private` (boolean; accepts `true`/`false`/`1`/`0`/`yes`/`no` via PHP `FILTER_VALIDATE_BOOLEAN`)
- `archived` (boolean) — requires `features.archivedTracks`. `true` archives the track (archiving an archived track again sets the new date); `false` restores it, moving it to the **end of the list** (restoring an active track does nothing).
- `archivedAt` (string, `YYYY-MM-DD`) — only read with `archived=true`. **Send the device's local today**, as for ticks. If omitted the server uses its own date (its default time zone, usually UTC), which can be a day off for the user.

`type` is silently ignored if sent. Name, sortOrder and private are applied before `archived`, so a restore's end-of-list position wins over a `sortOrder` sent with it.

Responses: `200` + updated track, `400` (nothing is saved) with `Track name cannot be empty`, `Track names can be at most 255 characters` or `Archive date must be a date as YYYY-MM-DD`, `404`, or `409`.

#### `PUT /api/tracks/reorder`
Bulk reorder. Send the desired order of track IDs. The listed tracks come first, in the order sent (ids that aren't yours are dropped, a repeated id counts once at its first place); **every other track follows in its previous relative order** — archived tracks, and any track created elsewhere since your last read. `sortOrder` is then renumbered `1..N` over all of the user's tracks.

Form params:
- `trackIds[]` (array of ints, required, non-empty). In form-encoded bodies this is `trackIds[]=1&trackIds[]=5&trackIds[]=3`.

Response: `200` + the user's **active** tracks in the new order (the same list as `GET /api/tracks`).

#### `DELETE /api/tracks/{id}`
Delete a track and all its ticks. `204` on success, `404` if not found.

### Ticks

#### `GET /api/ticks?from=YYYY-MM-DD&to=YYYY-MM-DD`
Fetch all ticks for the current user with `date` in `[from, to]` inclusive. Both params required. No pagination — keep ranges sensible (the web client uses 30-day windows, with infinite scroll extending ~30 days at a time).

Response `data`:
```json
[
  {"id": 42, "trackId": 1, "date": "2026-04-24", "value": 1},
  {"id": 43, "trackId": 2, "date": "2026-04-24", "value": 3}
]
```

Only existing ticks are returned. A missing (track, date) pair means "not ticked".

#### `GET /api/ticks/bounds`
> Requires `features.tickBounds` (§1). On servers without it this route returns `404` — fall back to deriving the same information from a wide `GET /api/ticks` range.

The first and last tick date for each track, aggregated server-side. No params.

Response `data`:
```json
[
  {"trackId": 1, "oldest": "2026-03-07", "newest": "2026-07-28"},
  {"trackId": 2, "oldest": "2026-05-01", "newest": "2026-05-01"}
]
```

Notes:
- **Tracks with no ticks are omitted** — same sparse convention as the tick list. Don't expect one entry per track, and don't treat a missing entry as an error.
- Covers **all** tracks in one response; there is no per-track variant. The payload is bounded by the 99-track limit, so it's safe to fetch whole.
- **Private tracks are included.** As everywhere else in this API, `private` is a display hint, not an access control — filter client-side if the user has private tracks hidden.
- Cheap: a single indexed aggregate query, no row scan of tick history.

Useful for sizing the initial sync window (don't page back past `oldest`), rendering "member since"-style stats, and showing a jump-to-start affordance without downloading the whole history first.

#### `POST /api/ticks/toggle`
Toggle a boolean tick. Only for `type=boolean` tracks — returns `400` for counter tracks.

Form params:
- `trackId` (int, required)
- `date` (string, required, `YYYY-MM-DD`)
- `note` (string, optional, requires `features.notes`): the day's note. Missing leaves it alone; sent, it replaces it and an empty value deletes it (see Notes below). When sent, the response also carries `"note"`, the stored note after the call (`""` if none). The note is written whichever way the tick flips.

Response: `{"ticked": true}` if the tick now exists, `{"ticked": false}` if it was removed. A `date` that isn't a real `YYYY-MM-DD` date answers `400` `Date must be a date as YYYY-MM-DD` and changes nothing.

On an **archived** track nothing changes, and the response says whether a tick exists for that day (still `200`, so an offline queue keeps draining; the next pull drops the track). Unknown track is still `404` and a counter track still `400`.

Prefer `set` (with `features.booleanSet`) over `toggle` for anything that may be retried: a replayed toggle undoes itself, a replayed `set` doesn't.

#### `POST /api/ticks/set`
Set a tick to a state: a counter's absolute value, or (with `features.booleanSet`) whether a yes/no day is ticked.

Form params:
- `trackId` (int, required)
- `date` (string, required, `YYYY-MM-DD`)
- `value`:
  - **Counter**: int. `<= 0` deletes the tick; `> 0` creates or updates it. Missing counts as `0`.
  - **Yes/no** (requires `features.booleanSet`): `true` or `false`; also `1`/`0`, `on`/`off`, `yes`/`no`, in any letter case, and in a JSON body a JSON boolean or the numbers 1 and 0. Anything else, or no value, answers `400` `Yes/no tracks take a value of true or false`.
- `note` (string, optional, requires `features.notes`): the day's note. Missing leaves it alone; sent, it replaces it and an empty value deletes it (see Notes below). When sent, the response also carries `"note"`, the stored note after the call (`""` if none).

Response: counter `{"value": <stored value>}` (`0` means no row exists); yes/no `{"value": true}` or `{"value": false}`. Setting the state a day already has changes nothing and gives the same answer, so a retried request is always safe. You know which shape you get from the track's type.

Errors, in this order: `404` unknown track; `400` bad yes/no value; `400` `Date must be a date as YYYY-MM-DD`. On a server without `booleanSet`, a yes/no track answers `400` `Set is only available for counter tracks`.

On an **archived** track nothing changes and the response carries the state already stored (`0`/`false` if none), with `200`, as for `toggle`. A bad value or date still answers `400`.

#### `POST /api/ticks/increment`
> Requires `features.counterIncrement` (§1). On servers without it this route returns `404`.

Add a signed delta to a counter tick. Atomic: concurrent increments of the same day from any mix of devices all count, including the first write of a day.

Form params:
- `trackId` (int, required)
- `date` (string, required, `YYYY-MM-DD`)
- `delta` (int, required, not 0; negative to take away)
- `note` (string, optional, requires `features.notes`): the day's note. Missing leaves it alone; sent, it replaces it and an empty value deletes it (see Notes below). When sent, the response also carries `"note"`, the stored note after the call (`""` if none).

Response: `{"value": n}`, the value right after this request's write (under concurrency it may already include another device's delta). The value never goes below 0: a result of 0 or less deletes the tick and answers `{"value": 0}`. So a decrement that hits 0 isn't undone by a later increment of the same size (−3 on 2 gives 0, then +3 gives 3).

Errors, in this order: `404` unknown track; `400` `Increment is only available for counter tracks`; `400` `Delta must be a whole number other than 0`; `400` `Date must be a date as YYYY-MM-DD`. On an archived counter nothing changes and the stored value comes back with `200`.

With `note`, every check of the tick call and the note's own checks (§ Notes) run before anything is written, so a `400` writes neither the tick nor the note. Then the tick is written, then the note. On an archived track neither changes and the response carries the stored state and, if `note` was sent, the stored note.

### Notes

> Requires `features.notes` (§1). On servers without it these routes return `404`.

Checks on a note's text, wherever it is written (here, `note` on tick calls, and imports), each a `400` with nothing saved:
- longer than 200 characters after trimming: `Notes can be at most 200 characters`
- not valid UTF-8: `Notes must be UTF-8 text`
- control characters other than `\n` and `\t` (U+0000–U+001F, U+007F, U+0080–U+009F): `Notes can't contain control characters`

A JSON number is stored as its digits; a JSON `true` or `false` answers `400` `Notes must be UTF-8 text` (send `""` to delete).

#### `GET /api/notes?from=YYYY-MM-DD&to=YYYY-MM-DD`
Notes of every track (private and archived included) in the range, inclusive on both ends, newest first:

```json
[{"trackId": 3, "date": "2026-10-18", "text": "backup 03:14"}]
```

Both dates are required; a missing one or one that isn't a real date answers `400` `From and to must be dates as YYYY-MM-DD`.

#### `POST /api/notes/set`
Form params: `trackId` (int), `date` (`YYYY-MM-DD`), `text` (string; empty deletes the note).

Response: `{"text": "…"}`, the stored text after trimming (`""` when deleted). Errors, in this order: `404` unknown track; `400` `Date must be a date as YYYY-MM-DD`; `400` text checks above. On an archived track nothing changes and the stored note comes back (`""` if none), with `200`.

#### `DELETE /api/notes?trackId=n&date=YYYY-MM-DD`
Deletes the day's note. `204`, also when there was none. `404` unknown track, `400` bad date. On an archived track nothing is deleted, still `204`.

### Stats

#### `GET /api/stats?today=YYYY-MM-DD[&trackId=n][&includeRuns=true][&includeArchived=true]`
> Requires `features.stats` (§1). On servers without it this route returns `404`.

Totals, streaks and highest/lowest value per track, worked out on the server with the same rule the web uses, so you don't need a track's whole history to show them.

Params:
- `today` — **the device's local date**. Whether today is still open decides where a streak ends, so always send it. If missing, the server's date is used (its default time zone, usually UTC). Not a real date: `400` `Today must be a date as YYYY-MM-DD`.
- `trackId` — one track only (`404` if not yours). An archived track is returned even without `includeArchived`. The response is still a list, with one entry.
- `includeRuns` — add `runs`: every streak and break, oldest first.
- `includeArchived` — with all tracks, include archived ones (default: left out, as `GET /api/tracks`).

Every track gets an entry, also with no ticks, in track list order. Private tracks are included. Each track is worked out up to its **end day**: `today`, or for an archived track its `archivedAt` if earlier. Ticks after the end day are ignored everywhere.

```json
[{
  "trackId": 3, "total": 412, "weeklyMean": 9.58,
  "last7": 11, "previous7": 14, "peak7": 31,
  "current": {"isStreak": true, "length": 6, "from": "2026-10-11", "to": "2026-10-16"},
  "upToYesterday": true,
  "longestStreak": {"isStreak": true, "length": 41, "from": "2026-03-02", "to": "2026-04-11"},
  "longestBreak": {"isStreak": false, "length": 9, "from": "2026-07-20", "to": "2026-07-28"},
  "firstTick": "2025-12-21", "lastTick": "2026-10-16",
  "maxValue": 7, "minValue": 0
}]
```

- `total` — sum of values (yes/no: ticked days). `weeklyMean` — `total` ÷ (days from `firstTick` to the end day, counting both, ÷ 7), floored at one week; not rounded. Ticking every day gives exactly 7.
- `last7` / `previous7` — sums over the end day and the 6 before it, and the 7 days before those. `peak7` — the highest 7-day sum in the history. The web's trend arrow compares `last7` with `previous7`, scaled by `max(peak7, 7)`.
- `current`, `longestStreak`, `longestBreak` — runs `{isStreak, length, from, to}`, or `null` with no ticks; the first of equally long runs wins.
- `upToYesterday` — `true` when the current run is a streak ending yesterday because today isn't ticked yet. **An open today never ends a streak**: if today is unticked but yesterday is ticked, counting stops at yesterday; otherwise an open today joins the break.
- `maxValue` / `minValue` — over every day from `firstTick` to the end day, an unticked day counting as 0 (so `minValue` is 0 whenever a day was missed).

### Preferences

#### `GET /api/preferences`
Response: `{"defaultView": "journal" | "readonly" | "analytics"}`. Default `"journal"` if never set. The mobile app is free to ignore this if it has its own navigation, but respecting it matches cross-device expectations.

#### `PUT /api/preferences`
Form param: `defaultView` (one of the three enum values). Unknown values fall back to `"journal"`.

### Import / Export

#### `GET /api/export?includePrivate=true|false`
Returns the full user data as a JSON blob:

```json
{
  "version": 1,
  "exportedAt": "2026-04-24T15:30:00+00:00",
  "tracks": [
    {"name": "Meditate", "type": "boolean", "sortOrder": 1, "private": false}
  ],
  "ticks": [
    {"track": "Meditate", "date": "2026-04-24", "value": 1}
  ]
}
```

Note: ticks reference tracks by **name**, not id, to be portable across instances. Track IDs are intentionally omitted.

Archived tracks are always exported (a private one only with `includePrivate=true`) and carry `"archivedAt": "YYYY-MM-DD"`; active tracks have no such key. The JSON import reads it back (missing or `null` means active) and rejects a value that isn't a real `YYYY-MM-DD` date.

Track names aren't unique, so when a name appears more than once in `tracks`, the ticks of those tracks also carry an optional `trackIndex`: the 0-based position of their track in the file's `tracks` list (after the private-track filter). It is only written for repeated names, so a file without duplicates has exactly the shape above:

```json
{"track": "Exercise", "trackIndex": 2, "date": "2026-03-01", "value": 1}
```

Importers should prefer `trackIndex` when present and fall back to the name otherwise. The server rejects a `trackIndex` that isn't a whole number within `tracks`, and a tick without one whose name matches several tracks (older backups), rather than guess.

When the user has notes, the file also has a `notes` list (files without notes have no such key). Notes reference their track like ticks, `trackIndex` included; a private track's notes are left out with its track:

```json
"notes": [{"track": "Backup", "date": "2026-10-18", "text": "done 03:14"}]
```

The JSON import fails the whole file, changing nothing, on a tick or note whose track can't be found, whose date isn't a real date, that repeats a track and day, or whose value or text its track can't take (note text: the checks in §3 Notes; a JSON `true`/`false` is not text). Empty notes are skipped. The import result gains `"notes": n` (always `0` for a Tickmate import).

#### `POST /api/import/json`  (multipart)
Import a previously exported JSON file.

Multipart form:
- `file` — the JSON file
- `mode` — `"replace"` (wipe all existing tracks, ticks and notes first) or `"merge"` (add non-conflicting)
- Max 20 MB

Likely out of scope for a mobile client; mention only if you want to offer "restore from backup".

#### `POST /api/import`  (multipart)
Imports a Tickmate `.db` SQLite file (Android Tickmate app's export format). Also probably out of scope for mobile unless you want to offer Tickmate migration.

---

## 4. Sync strategy (recommended)

As of the version this doc targets, the backend has no sync protocol of its own (no `modifiedAt`, no delta endpoint, no ETags, no push). So the mobile app must build sync client-side. Suggested approach below.

> **Forward-compat:** two of the sharpest edges here (full-range polling and lost counter increments) are fixed or slated to be fixed server-side, discoverable via capability flags (§1). Guard those code paths on `features.syncDelta`, `features.counterIncrement` and `features.booleanSet` so the app upgrades its own behavior when it detects a newer backend. The full-poll + absolute-set approach below remains the correct fallback whenever the flags are `false`.

### Local schema

Mirror the server shape, plus sync metadata:

```
tracks(local_id, server_id NULLABLE, name, type, sort_order, private,
       dirty INT, deleted INT, updated_at_local)

ticks(local_id, server_id NULLABLE, track_local_id, date, value,
      dirty INT, deleted INT, updated_at_local)
```

`dirty=1` means "local change not yet pushed". `deleted=1` is a tombstone until confirmed deleted on server.

### Pull

On app open, on pull-to-refresh, and periodically (e.g. every 15 min while foregrounded):

1. `GET /api/tracks` → reconcile by `server_id` (and fall back to `name` match for rows that have no `server_id` yet because they were created offline). Track rows missing from the response are considered deleted server-side → delete locally unless the local row is `dirty` (then it's a conflict — see below). A track archived elsewhere is missing from the default response too: it isn't deleted on the server (its ticks are kept), so if you need to tell the two apart, pull with `includeArchived=true` (requires `features.archivedTracks`) and treat a track with `archivedAt` set as archived rather than gone — in particular, don't recreate it from a dirty local edit.
2. `GET /api/ticks?from=X&to=Y` for the visible date range, plus any date range that has `dirty` ticks pending push. Reconcile by `(trackId, date)` — that's the natural key.

> On **first** sync you don't know how far back to go. If `features.tickBounds` is set, one `GET /api/ticks/bounds` (§3) tells you the earliest and latest tick date per track, so you can size the backfill exactly and stop paging when you reach `oldest` instead of walking blindly into empty ranges. Without the flag, page backwards until you hit a few consecutive empty windows and accept the guess.

> When `features.syncDelta` is `true` (§1), a future backend will let you replace step 2's full-window re-download with an incremental "changed since X" pull. Until then, full-range polling is required. One caveat to design for now: because storage is sparse (a removed tick is a *deleted row*, not a zero), any future delta mechanism will need to convey deletions too — don't assume a delta pull can be a simple "rows where modifiedAt > X" over live rows.

### Push

Walk dirty rows in order: **track creates → track updates → track deletes → tick changes**. The order matters because ticks reference tracks.

- Track created offline: `POST /api/tracks`, store returned `id` as `server_id`, clear `dirty`.
- Track renamed: `PUT /api/tracks/{server_id}`.
- Track deleted: `DELETE /api/tracks/{server_id}`; on 404 treat as already gone.
- Tick toggled (boolean): queue the **desired end state** (`ticked: true/false`), not "toggle". With `features.booleanSet`, push it with `POST /api/ticks/set` and `value=true|false`: idempotent, always safe to replay. Without the flag, `POST /api/ticks/toggle` is idempotent *per tap*, not per state — replaying a queued toggle that was already applied flips it back — so fetch the current server value before pushing, and only toggle if it differs.
- Tick counter set: `POST /api/ticks/set` with the desired value — this IS idempotent, always safe to replay. With `features.counterIncrement`, prefer queuing taps as deltas (see Conflict resolution).

### Conflict resolution

Two devices edit the same track name, or toggle the same tick. Recommended policy: **last-write-wins, server authoritative after pull**. When reconciling pull:

- If server row differs from local, and local is not `dirty`: take server value (silent overwrite).
- If server row differs and local IS `dirty`: push local on next push cycle; accept whatever server returns. For deletes specifically: if server shows a track the user deleted locally (and the delete is still queued), push the delete. If the user edited a track the server has deleted, create a new track with the local name + type.

Counter ticks are the trickiest case — if both devices incremented the same tick offline, both push the same absolute value and one increment is silently lost. Note this is **not** a last-write-wins timing problem you can fix with a timestamp: both writes carry the same wrong value. The real fix is a commutative operation. When `features.counterIncrement` is `true` (§1), send user taps as signed deltas to `POST /api/ticks/increment` and let the server compose them; concurrent increments then merge correctly. While the flag is `false`, fall back to `POST /api/ticks/set`, live with the lost-increment risk for v1, and consider a subtle UI hint when a counter was edited on multiple devices.

### Offline queue

All writes go to the local DB first (optimistic). A `WorkManager` job with `NetworkType.CONNECTED` constraint drains the dirty queue. On `401` stop the worker and surface a re-auth prompt. On `5xx` exponential-backoff retry.

---

## 5. Gotchas and non-obvious behaviors

- **`OCS-APIRequest: true` is not optional.** Without it, Nextcloud's router may return HTML login pages or 404s instead of JSON, and the error will be cryptic. Hardcode this in your HTTP client.
- **Boolean encoding.** PHP's `FILTER_VALIDATE_BOOLEAN` accepts `true|false|1|0|yes|no|on|off`. Send `"true"`/`"false"` strings to be safe.
- **Reorder puts unlisted tracks last.** `PUT /api/tracks/reorder` numbers the listed tracks `1..n` and the rest (archived ones, or ones you didn't know about) after them, in their previous order. Send the tracks you show; you can't send archived tracks to the front by accident, since you never see them unless you ask.
- **An older server ignores `note` on tick calls without an error.** It drops the unknown parameter and answers `200`, so the note is lost. Check `features.notes` before sending one.
- **Missing `note` and empty `note` differ.** Leave the parameter out to keep the day's note; send it empty to delete it.
- **Ticks on archived tracks are quietly dropped.** `toggle` and `set` answer `200` with the unchanged state rather than an error, so an upload queue never stalls on one. Reconcile from the response or the next pull.
- **`value` on boolean ticks is always 1** in `GET /api/ticks`. Don't write UI logic that assumes it could be 2. Don't send a value to `/toggle`.
- **`set` answers a number for counters and a boolean for yes/no tracks.** `{"value": 3}` vs `{"value": true}`; pick the parser from the track's type.
- **`toggle`, `set` and `increment` reject a date that isn't `YYYY-MM-DD` with `400`.** `2026-2-3` and `2026-02-30` included; nothing is stored. Send `LocalDate.toString()`.
- **Stats needs the client's today.** Send `today` on every `GET /api/stats`: where a streak ends depends on whether today is still open, and the server's date (usually UTC) can be a day off for the user.
- **Date range query is inclusive on both ends.** `from=2026-04-01&to=2026-04-30` includes both the 1st and the 30th.
- **Private tracks are soft-hidden, not encrypted.** Any client that reads `GET /api/tracks` sees them. The `private` flag controls default visibility in the web UI (there's a "Show private tracks" toggle) and whether they appear in exports. The mobile app should offer the same UX — a toggle/PIN gate on showing private tracks — but do not rely on `private` for any security claim.
- **No pagination.** Track and tick endpoints return all matching rows. Ticks are bounded by the `from/to` window. Tracks are capped at 99 per user so unbounded list is fine.
- **No push / websockets.** The mobile app has to poll.
- **Capabilities live on the core endpoint, not the app endpoint.** Version/feature discovery is `GET /ocs/v2.php/cloud/capabilities` (§1) — under `/cloud`, not `/apps/tickbuddy`. It still needs `OCS-APIRequest: true`. If `data.capabilities.tickbuddy` is absent, the app isn't installed on that server; treat it as a setup failure, not an empty result.
- **Don't gate features on the version string.** Parse `apiVersion` (int) or check individual `features` flags. String-comparing `version` ("1.0.5" vs "1.0.10") is a footgun.
- **Nextcloud hosts this app; the domain is NOT under our control.** Don't assume HTTPS, don't pin certificates. Allow any user-supplied hostname that validates via system trust store.
- **Time zones in `exportedAt`.** The export timestamp uses the server's timezone (PHP `date('c')`). Don't rely on it matching the user's timezone.
- **`OCS-APIRequest` header is case-insensitive in HTTP, but Nextcloud reads it as given.** Most HTTP clients normalize — verify yours does.

---

## 6. Minimal curl examples for manual testing

```bash
NC=https://cloud.example.com
USER=alice
APP_PASS=xxxx-xxxx-xxxx

# Discover version + features (core endpoint, note the /cloud path)
curl -u "$USER:$APP_PASS" \
  -H "OCS-APIRequest: true" -H "Accept: application/json" \
  "$NC/ocs/v2.php/cloud/capabilities"
# → look for .ocs.data.capabilities.tickbuddy

# List tracks
curl -u "$USER:$APP_PASS" \
  -H "OCS-APIRequest: true" -H "Accept: application/json" \
  "$NC/ocs/v2.php/apps/tickbuddy/api/tracks"

# Create a boolean track
curl -u "$USER:$APP_PASS" \
  -H "OCS-APIRequest: true" -H "Accept: application/json" \
  -d "name=Meditate&type=boolean" \
  "$NC/ocs/v2.php/apps/tickbuddy/api/tracks"

# Toggle today
curl -u "$USER:$APP_PASS" \
  -H "OCS-APIRequest: true" -H "Accept: application/json" \
  -d "trackId=1&date=2026-04-24" \
  "$NC/ocs/v2.php/apps/tickbuddy/api/ticks/toggle"

# Fetch a range
curl -u "$USER:$APP_PASS" \
  -H "OCS-APIRequest: true" -H "Accept: application/json" \
  "$NC/ocs/v2.php/apps/tickbuddy/api/ticks?from=2026-04-01&to=2026-04-30"

# Where does the history start and end? (404 = server predates features.tickBounds)
curl -u "$USER:$APP_PASS" \
  -H "OCS-APIRequest: true" -H "Accept: application/json" \
  "$NC/ocs/v2.php/apps/tickbuddy/api/ticks/bounds"
```

If any of those return HTML, you forgot `OCS-APIRequest: true`.

---

## 7. Checklist for a minimum-viable mobile client

- [ ] Server URL + login + app password entry with validation round-trip (`GET /api/tracks` with the credentials — a 200 proves all three)
- [ ] Read `GET /ocs/v2.php/cloud/capabilities` on connect: confirm `tickbuddy` is present (app installed), cache `version` + `features`, gate optional code paths on the flags
- [ ] Credentials in Keystore-backed encrypted storage
- [ ] Local SQLite mirror with `dirty` / `deleted` flags
- [ ] Read: pull tracks on open, pull ticks for visible range
- [ ] Write: optimistic local apply, queued push, retry on network
- [ ] Today screen: grid of tracks with checkbox (boolean) / ±buttons (counter)
- [ ] History screen: scrollable back by day, matches web "View journal"
- [ ] Track management: add/edit/delete/reorder, respect 99-track limit
- [ ] Private tracks: local toggle to show/hide, no security claim
- [ ] 401 handler that clears session and prompts re-auth
- [ ] Handle `type` immutability in UI (no "change type" button)
- [ ] Don't send `value=0` to `/toggle`; don't send toggles to counter tracks
- [ ] Show installed backend `version` in an About/server-info screen
- [ ] Archive/restore only when `features.archivedTracks` is set; send the device's today as `archivedAt`
- [ ] Gate `tickBounds` / `counterIncrement` / `booleanSet` / `stats` / `notes` / `syncDelta` code paths on capability flags so the app upgrades behavior automatically against newer backends, with a working fallback when each is `false`

Nice-to-have:
- [ ] Nextcloud Login Flow v2 instead of pasted app password
- [ ] Widget for today's boolean tracks
- [ ] Export/import UI using `GET /api/export` and `POST /api/import/json`
- [ ] Notification/daily reminder (purely client-side)
