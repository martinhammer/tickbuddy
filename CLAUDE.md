# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Tickbuddy is a Nextcloud app for daily habit/occurrence tracking (a "one-bit journal"), inspired by the Android app [Tickmate](https://github.com/lordi/tickmate). It targets Nextcloud 31–32.

## Architecture

Standard Nextcloud app with a PHP backend and Vue 3 frontend. Two separate screens:

1. **Main app** (`src/main.ts` → `App.vue` → `TickGrid.vue`): grid of days × tracks where users tick/untick events. Three views accessible via sidebar navigation: **Edit journal** (default, interactive checkboxes/counters), **View journal** (read-only with date range picker and sort toggle), and **Analytics** (placeholder). Mounts into `<div id="tickbuddy">` via `templates/index.php`.
2. **Personal settings** (`src/settings.ts` → `TrackSettings.vue`): track management (add/edit/delete/reorder tracks, private flag), user preferences (default view), and import/export (Tickmate `.db` and Tickbuddy `.json`). Mounts into `<div id="tickbuddy-settings">` via `templates/settings/personal.php`. Registered as a Nextcloud personal settings section in `Application::register()`.

Each screen has its own Vite entry point (configured in `vite.config.ts`).

### Backend layers

Follows the Nextcloud AppFramework pattern: **Entity → Mapper → Service → Controller**.

- `lib/Db/` — Entities (`Track`, `Tick`) and QBMappers. All DB queries live here.
- `lib/Service/` — Business logic. `TrackService` enforces the 99-track limit, type validation, and name trimming. `TickService` handles toggle (boolean) and set (counter) operations. `ImportService` handles Tickmate and JSON imports. `ExportService` handles JSON export.
- `lib/Controller/` — OCS API controllers. Routes are defined via PHP attributes (`#[ApiRoute]`), not in a routes file.
- `lib/Settings/` — `PersonalSection` (sidebar entry with icon) and `PersonalSettings` (renders the settings template).
- `lib/Migration/` — Database schema migrations.
- **App metadata**: `appinfo/info.xml` (Nextcloud app manifest — version, dependencies, navigation entry).
- **OpenAPI spec**: `openapi.json` is auto-generated from PHP docblocks on API controllers.

### Data model

Two tables, both scoped per-user:

- **`tickbuddy_tracks`**: id, user_id, name, type (`'boolean'` | `'counter'`), sort_order, private (bool). Max 99 tracks per user.
- **`tickbuddy_ticks`**: id, user_id, track_id, date, value (int, default 1). Unique on (user_id, track_id, date).

Key design decisions:
- **Sparse storage**: a tick row existing means "yes" / non-zero count. No row means "no" / zero. Toggling a boolean track inserts or deletes the row. Setting a counter to 0 deletes the row.
- **Track type is immutable**: set at creation, cannot be changed afterward. The API rejects updates to the `type` field.
- **Two tick mutation endpoints**: `POST /api/ticks/toggle` (boolean tracks only) and `POST /api/ticks/set` (counter tracks only). The service layer validates the track type matches the endpoint.

### API endpoints

**Tracks** (`TrackController`):
- `GET /api/tracks` — list all for current user
- `POST /api/tracks` — create `{name, type}`
- `PUT /api/tracks/{id}` — update `{name?, sortOrder?, private?}` (type is rejected)
- `PUT /api/tracks/reorder` — reorder tracks `{trackIds[]}`
- `DELETE /api/tracks/{id}` — delete track and its ticks

**Ticks** (`TickController`):
- `GET /api/ticks?from=YYYY-MM-DD&to=YYYY-MM-DD` — fetch ticks in date range
- `POST /api/ticks/toggle` — toggle boolean tick `{trackId, date}`
- `POST /api/ticks/set` — set counter value `{trackId, date, value}`

**Preferences** (`PreferencesController`):
- `GET /api/preferences` — get user preferences (defaultView)
- `PUT /api/preferences` — update `{defaultView}`

**Import/Export** (`ImportController`, `ExportController`):
- `POST /api/import` — import Tickmate `.db` file `{file, mode}`
- `POST /api/import/json` — import Tickbuddy `.json` file `{file, mode}`
- `GET /api/export?includePrivate=bool` — export all data as JSON

## Build & Dev Commands

### Frontend (npm)
- `npm run build` — production build
- `npm run dev` — development build
- `npm run watch` — development build with file watching
- `npm run lint` — ESLint
- `npm run stylelint` — Stylelint for Vue/SCSS/CSS

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

## Key Conventions

- PHP namespace: `OCA\Tickbuddy\`
- App ID constant: `Application::APP_ID` (`'tickbuddy'`)
- All PHP files use `declare(strict_types=1)`
- Psalm runs at error level 1 with `findUnusedCode` enabled; suppress unused classes injected by Nextcloud with `@psalm-suppress UnusedClass`
- Node version: 20 (`.nvmrc`)

## Known gaps / future work

Surfaced while writing the mobile companion app integration guide (`mobile_instructions.md`):

- **No sync delta endpoint.** The API has no `modifiedAt`, ETag, or `since=` parameter. Clients (especially the forthcoming Android app) must poll full ranges and reconcile locally. If mobile sync UX proves clunky, consider adding a lightweight "changed since X" endpoint for tracks and ticks to avoid re-downloading full windows on every pull.
- **Counter increments can't merge across devices.** `POST /api/ticks/set` sets an absolute value, not a delta. If two devices each increment the same counter tick offline, one update will overwrite the other on push — a lost increment. Adding a server-side `POST /api/ticks/increment` (with a signed delta) would let mobile clients queue increments commutatively and resolve concurrent edits cleanly.
