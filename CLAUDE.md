# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Tickbuddy is a Nextcloud app for daily habit/occurrence tracking (a "one-bit journal"), inspired by the Android app [Tickmate](https://github.com/lordi/tickmate). It targets Nextcloud 32–34.

## Architecture

Standard Nextcloud app with a PHP backend and Vue 3 frontend. Two separate screens:

1. **Main app** (`src/main.ts` → `App.vue` → `TickGrid.vue`): grid of days × tracks where users tick/untick events. Three views accessible via sidebar navigation: **Edit journal** (default, interactive checkboxes/counters), **View journal** (read-only with date range picker and sort toggle), and **Analytics** (`AnalyticsView.vue` — per-track charts, see below). Mounts into `<div id="tickbuddy">` via `templates/index.php`.
2. **Personal settings** (`src/settings.ts` → `TrackSettings.vue`): track management (add/edit/delete/reorder tracks, private flag), user preferences (default view), and import/export (Tickmate `.db` and Tickbuddy `.json`). Mounts into `<div id="tickbuddy-settings">` via `templates/settings/personal.php`. Registered as a Nextcloud personal settings section in `Application::register()`.

Each screen has its own Vite entry point (configured in `vite.config.ts`).

### Analytics view

`src/components/AnalyticsView.vue` is the **Analytics** sidebar view. It analyses **one track at a time** (picked via `NcSelect`) and honours the sidebar's "Show private tracks" toggle. There is **no analytics API**: it fetches the selected track's full tick history over a wide date range and does **all aggregation client-side**. Charts use Chart.js via `vue-chartjs`, plus `chartjs-plugin-zoom`.

Contents, top to bottom:
- **Stat cards**: Total, Weekly mean, 2-week trend (a rotated arrow); Current streak/break, Longest streak, Longest break.
- **Calendar heatmap**: a GitHub-contributions-style grid, hand-rolled as SVG (no charting library). Shows a trailing **365 days**, extended further back when the track has older history, in a horizontally scrolling container pinned to today. Weekday labels sit in a **fixed column outside the scroller** so they stay visible; month labels are slanted and scroll with the grid. **Boolean** tracks render one shade; **counter** tracks quantise into four levels against that track's own max (small ranges map value→level directly). Empty days use `--color-background-dark`. Hover shows a **custom tooltip styled to match the Chart.js default** (the swatch flattens the cell's translucent fill over `--color-main-background` so it matches the cell in either theme).
- **Streaks/Breaks**: a zoom/pan line chart of alternating streak (up) and break (down) run lengths, with a tertiary "Reset zoom" `NcButton`.
- **Days of week** and **Months** polar-area charts, aggregated across all years. Slice shading is **darker = higher value**, reinforcing the slice radius and matching the heatmap's Less→More direction (`polarShades`).
- **Weeks / Months / Quarters / Years** time-series line charts (`buildTimeSeries`; weeks use ISO 8601).

Key conventions:
- **First day of week** comes from `getFirstDay()` (`@nextcloud/l10n`), stored as `localeFirstDay` (0=Sun..6=Sat). This reads Nextcloud's server-injected `window.firstDay`, so it is **consistent across browsers** — do **not** revert to calling `Intl.Locale` week-info directly (Chromium exposes the `weekInfo` property, Firefox only the `getWeekInfo()` method, and they disagree on region-less locales).
- Chart accent colour is read from the `--color-primary-element` CSS variable (`getPrimaryColor`) and tinted via `hexToRgba`, so charts follow the Nextcloud theme.
- Remember sparse storage: a missing day means zero/not-ticked, which the client-side date walks must fill in (they do not assume a row per day).
- **Chart.js option objects need an explicit `ChartOptions<'line'>` (or `<'polarArea'>`) annotation**, as `streaksBreaksData` does. Without one, TS infers `mode: 'x'` as `string` and `min: 'original'` as `string`, which are not assignable to Chart.js's narrow unions, so the `:options` binding fails `npm run typecheck`. The same annotation forces axis `ticks.callback` to accept `number | string` (Chart.js's declared signature) even where a linear scale only ever passes numbers.

### Backend layers

Follows the Nextcloud AppFramework pattern: **Entity → Mapper → Service → Controller**.

- `lib/Db/` — Entities (`Track`, `Tick`) and QBMappers. All DB queries live here.
- `lib/Service/` — Business logic. `TrackService` enforces the 99-track limit, type validation, and name trimming. `TickService` handles toggle (boolean) and set (counter) operations. `ImportService` handles Tickmate and JSON imports. `ExportService` handles JSON export.
- `lib/Controller/` — OCS API controllers. Routes are defined via PHP attributes (`#[ApiRoute]`), not in a routes file.
- `lib/Settings/` — `PersonalSection` (sidebar entry with icon) and `PersonalSettings` (renders the settings template).
- `lib/Capabilities.php` — implements `OCP\Capabilities\ICapability`, registered in `Application::register()`. Advertises the app version and a feature-flag map to clients via the core capabilities endpoint (see below). Keep `getCapabilities()` cheap (no DB) — it runs on every capabilities request.
- `lib/Migration/` — Database schema migrations.
- **App metadata**: `appinfo/info.xml` (Nextcloud app manifest — version, dependencies, navigation entry).
- **OpenAPI spec**: `openapi.json` is auto-generated from PHP docblocks on API controllers.

### Data model

Two tables, both scoped per-user:

- **`tickbuddy_tracks`**: id, user_id, name, type (`'boolean'` | `'counter'`), sort_order, private (int 0/1, exposed as bool at the API). Max 99 tracks per user.
- **`tickbuddy_ticks`**: id, user_id, track_id, date, value (int, default 1). Unique on (user_id, track_id, date).

Key design decisions:
- **Sparse storage**: a tick row existing means "yes" / non-zero count. No row means "no" / zero. Toggling a boolean track inserts or deletes the row. Setting a counter to 0 deletes the row.
- **Track type is immutable**: set at creation, cannot be changed afterward. The API rejects updates to the `type` field.
- **Two tick mutation endpoints**: `POST /api/ticks/toggle` (boolean tracks only) and `POST /api/ticks/set` (counter tracks only). The service layer validates the track type matches the endpoint.
- **`private` is stored as an integer, not a boolean.** The physical column is `INTEGER` on every database (`Types::BOOLEAN` failed to migrate on MySQL), so the `Track` entity must map it with `addType('private', 'integer')`. Binding it as a boolean makes QBMapper emit `'t'`/`'f'`, which Postgres rejects against the integer column. `bool` exists only at the API/JSON boundary — the controller serializes `getPrivate() === 1` and services convert incoming bools with `? 1 : 0`. Golden rule: an entity's `addType()` must always match the physical column type.

### API endpoints

**Tracks** (`TrackController`):
- `GET /api/tracks` — list all for current user
- `POST /api/tracks` — create `{name, type}`
- `PUT /api/tracks/{id}` — update `{name?, sortOrder?, private?}` (type is rejected)
- `PUT /api/tracks/reorder` — reorder tracks `{trackIds[]}`
- `DELETE /api/tracks/{id}` — delete track and its ticks

Both `{id}` routes declare `requirements: ['id' => '\d+']`. Without it, `{id}` compiles to Symfony's default `[^/]++` and `PUT /api/tracks/{id}` also matches `/api/tracks/reorder` — which route wins then depends purely on method declaration order within the controller (`Router::getAttributeRoutes()` reflects methods in source order, first match wins). The failure is silent: `settype()` casts `"reorder"` to `0`, so a reorder request would 404 as "Track not found". **Any new literal path segment under a route that also has a `{placeholder}` sibling needs the same treatment.**

**Ticks** (`TickController`):
- `GET /api/ticks?from=YYYY-MM-DD&to=YYYY-MM-DD` — fetch ticks in date range
- `GET /api/ticks/bounds` — first/last tick date per track, `[{trackId, oldest, newest}]`. Aggregated in SQL (`MIN`/`MAX` with `GROUP BY track_id`, served by the `(user_id, track_id, date)` unique index) so clients never download whole tick histories just to find where they start. Tracks with no ticks are **omitted** rather than returned with nulls, per the sparse storage convention. Covers all tracks in one response rather than taking a `trackId`: the response is bounded by the 99-track limit, whereas a per-track variant would mean N requests to answer "oldest across all tracks". Private tracks are included — `private` hides tracks in the UI, it is not an authorization boundary, so clients filter it exactly as they do for the tick list.
- `POST /api/ticks/toggle` — toggle boolean tick `{trackId, date}`
- `POST /api/ticks/set` — set counter value `{trackId, date, value}`

**Preferences** (`PreferencesController`):
- `GET /api/preferences` — get user preferences (defaultView)
- `PUT /api/preferences` — update `{defaultView}`

**Import/Export** (`ImportController`, `ExportController`):
- `POST /api/import` — import Tickmate `.db` file `{file, mode}`
- `POST /api/import/json` — import Tickbuddy `.json` file `{file, mode}`
- `GET /api/export?includePrivate=bool` — export all data as JSON

**Capabilities** (`Capabilities`, not an OCS controller):
- `GET /ocs/v2.php/cloud/capabilities` — core Nextcloud endpoint, not under `/apps/tickbuddy`. Returns `data.capabilities.tickbuddy = {version, apiVersion, features{...}}`. Readable by any authenticated user (app password works). Clients (the mobile app) read this on connect to discover the installed version and which optional API features exist, instead of version-sniffing. `apiVersion` (`Capabilities::API_VERSION`) tracks the client-facing API contract; bump it on a breaking change. Feature flags default the two "known gaps" below to `false` — flip them to `true` here when the endpoints land. **Any optional endpoint clients may call needs a flag**: additive routes don't bump `apiVersion`, so a flag is the only way a client can tell an endpoint exists rather than discovering it via a 404 (`tickBounds` is the worked example — see `mobile_instructions.md` §1/§3 for the flag + fallback pattern).

## Build & Dev Commands

### Frontend (npm)
- `npm run build` — production build
- `npm run dev` — development build
- `npm run watch` — development build with file watching
- `npm run lint` — ESLint
- `npm run stylelint` — Stylelint for Vue/SCSS/CSS
- `npm run typecheck` — `vue-tsc --noEmit`, type-checks `.ts` **and `.vue` templates**

### Backend (composer)
- `composer lint` — PHP syntax check
- `composer cs:check` — PHP CS Fixer dry run
- `composer cs:fix` — PHP CS Fixer auto-fix
- `composer psalm` — static analysis (error level 1, strictest)
- `composer test:unit` — PHPUnit tests (`tests/` directory)
- `composer rector` — Rector refactoring + auto CS fix
- `composer openapi` — regenerate OpenAPI spec from docblocks

### Running a single PHP test
```
vendor-bin/phpunit/vendor/bin/phpunit tests/unit/Controller/ApiTest.php -c tests/phpunit.xml
```

## Demo data

`demo/` holds a ready-made dataset (`tickbuddy-demo-data.json`, JSON import format) for screenshots and manual testing, plus `generate-demo-data.py` — a stdlib-only Python generator that produced it (`python3 demo/generate-demo-data.py`). Regenerate the JSON from the script if you change either, so they stay in sync. See `demo/README.md`.

## Key Conventions

- PHP namespace: `OCA\Tickbuddy\`
- App ID constant: `Application::APP_ID` (`'tickbuddy'`)
- All PHP files use `declare(strict_types=1)`
- Psalm runs at error level 1 with `findUnusedCode` enabled; suppress unused classes injected by Nextcloud with `@psalm-suppress UnusedClass`
- Node version: 24 — `.nvmrc` and `package.json` `engines` must stay in step; CI reads the version from `engines` (the `fallbackNode: '^20'` in the workflows only applies if `engines` is absent)

### Frontend checks and `@nextcloud/vue` gotchas

`npm run typecheck` is the only check that sees third-party component prop types — ESLint cannot, because no rule reads `@nextcloud/vue`'s type definitions. CI runs it via `.github/workflows/lint-typecheck.yml` (mirrors `lint-eslint.yml`: same pinned action SHAs, same `paths-filter` gate, same summary job for branch protection). **Run it after every `@nextcloud/vue` bump** — that is the class of breakage it exists to catch. It does **not** catch CSS or layout regressions; those need a look at the running app.

Breakages already hit, kept here so they are not reintroduced:

- **`NcButton` colour is `variant`, not `type`.** v9 removed the fallback: `type` is now the native HTML button type (`button`/`submit`/`reset`) and `variant` takes `primary` / `secondary` / `tertiary` / `tertiary-no-background` / …. The same applies to `NcActions` and `NcDialogButton`. `type="primary"` fails silently — the button renders in the default `secondary` variant and emits an invalid HTML `type` attribute.
- **`NcDateTimePickerNative` renders an `NcInputField` since 9.10**, whose root `.input-field` is `width: 100%`. As a flex child it claims the whole row, so the View journal toolbar wraps each picker in a fixed-width `.dateControl` div (`TickGrid.vue`). Putting a width class on the component itself does not work: `.input-field` is scoped with an attribute selector and outranks a plain class. The same rewrite moved the field label inside the border, which is why the toolbar's buttons carry no `<label>` — the jump button's date hint is a native `title` tooltip instead (`@nextcloud/vue` v9 ships no Tooltip directive).

## Known gaps / future work

Surfaced while writing the mobile companion app integration guide (`mobile_instructions.md`):

These two gaps sound similar (both bite multi-device offline sync) but have **different root causes and independent fixes** — one is about change *metadata*, the other about the *shape of the write operation*. A timestamp fixes the first and does nothing for the second.

- **No sync delta endpoint.** The API has no `modifiedAt`, ETag, or `since=` parameter. Clients (especially the Android app) must poll full ranges and reconcile locally. If mobile sync UX proves clunky, consider adding a lightweight "changed since X" endpoint. Note this needs **more than a `modified_at` column**: because storage is sparse (untoggling/zeroing deletes the row), a `since=` query over live rows would never surface deletions. A real fix needs tombstones (soft-delete + `deleted_at`) or a separate change log, plus the timestamp/version column. Exposed via the `syncDelta` capability flag when it lands.
- **Counter increments can't merge across devices.** `POST /api/ticks/set` sets an absolute value, not a delta. If two devices each increment the same counter tick offline, both push the same absolute value and one increment is silently lost — and **timestamps don't help**, since last-write-wins just picks between two identical wrong values. The fix is orthogonal: a commutative `POST /api/ticks/increment` (signed delta) that the server applies additively, so concurrent increments compose regardless of order. Exposed via the `counterIncrement` capability flag when it lands.
