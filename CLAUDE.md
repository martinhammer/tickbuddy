# CLAUDE.md — Tickbuddy

Tickbuddy is a Nextcloud app for daily habit, event and occurrence tracking.
It currently targets Nextcloud 33–36 (PHP 8.2+).

**It is published on the app store, has active users, and is the server for
Tickdroid (Android), Tickcli and TickLLMe.** Those clients are released on their
own schedules, so old versions of them talk to new versions of this server, and
the other way round. `mobile_instructions.md` is the clients' integration guide:
read it before changing anything a client can see.

Detail loads only when needed:

| Where | What | Loads |
|---|---|---|
| `.claude/rules/api.md` | Every endpoint, capabilities and their flags, error reasons, the public link call, flag parsing | controllers, `Capabilities.php`, `ResponseDefinitions.php`, middleware, `openapi.json` |
| `.claude/rules/backend.md` | Backend layers, data model, key design decisions (sparse storage, Number/Choice, archive, notes, idempotency, reorder, increment, insert races, stats, migrations) | `lib/**`, `tests/unit/**`, `appinfo/**` |
| `.claude/rules/frontend.md` | Routing, preferences, editable days, notes, writes, track types, shared modules, `@nextcloud/vue` workarounds | `src/**` |
| `.claude/rules/{today,journal,tracks,analytics}.md` | One screen each | that screen's components |
| `.claude/rules/dashboard.md` | The dashboard widget | `lib/Dashboard/**`, `src/dashboard*` |
| `docs/backlog.md` | Open work, known gaps, deviations from the shared skills | read when planning |
| nextcloud plugin skills | Platform knowledge shared by all apps (php-backend, vue-frontend, responsive-layout, dashboard-widget, app-project-files, github-ci, …) | by topic |

## Client compatibility: never break a client

Each of these is explained in `api.md` or `backend.md`; read the entry before
changing what it covers.
- **Additive changes only.** Every optional endpoint or parameter a client may
  use gets a capability flag (`Capabilities.php`); `apiVersion` changes only on a
  breaking change. An older server ignores unknown parameters or 404s new
  routes, and the flag is how a client tells.
- **Every error answer carries a `reason`** (`ErrorReason`), and its values are
  API: never renamed. Clients drop a queued write only on Tickbuddy's own 404 or 400.
- **Archived tracks are hidden unless `includeArchived=true`, and writes on them
  answer a quiet 200 (204) without `Prefer: handling=strict`.** Both look like
  bugs and are not: hiding keeps Tickdroid ≤ 1.2.0 working, and the quiet 200
  keeps Tickdroid ≤ 1.3.0's upload queue from stalling. Don't "fix" either.
- **New track types join `VALID_TYPES`, never `BASE_TYPES`**, and get a flag;
  reads list them only with `includeAllTypes=true`.
- **`Idempotency-Key`:** never change the fingerprint recipe; adding a parameter
  to one of the five unsafe writes changes its fingerprint (harmless only between
  releases); a new unsafe write needs its own flag before it honours the key.
- **Streak and stats maths lives on the server** (`StatsService`), the one
  implementation every client reads.
- **Sparse storage:** no tick row means no / zero for Yes/No and Counter, no
  value (`null`, never 0) for Number and Choice.
- **Track type is immutable.**
- **The web writes with `ticks/set` and `ticks/increment`, never `toggle` or an
  absolute value worked out from the page**, and shows the server's answer (no
  optimistic updates).
- **Note text is always rendered escaped**, never `v-html`.
- **The dashboard widget id `tickbuddy-week` is permanent.**
- **A migration that has shipped in a tag is immutable**: correct it with a new one.

## Architecture

PHP backend (AppFramework: Entity → Mapper → Service → Controller; OCS routes as
`#[ApiRoute]` attributes) and a Vue 3 frontend with two Vite entry points:
1. **Main app** (`src/main.ts` → `App.vue`), mounted into `<div id="tickbuddy">`
   via `templates/index.php`. Four screens in the navigation, one job each:
   **Today** (tick), **Journal** (review), **Analytics** (understand), **Tracks**
   (set up).
2. **Dashboard widget** (`src/dashboard.ts` → `dashboardApp.ts` →
   `DashboardWidget.vue`), read-only, registered by `lib/Dashboard/WeekWidget.php`.

**Personal settings** → Tickbuddy is a plain PHP template
(`templates/settings/personal.php`, rendered by `PersonalSettings`, no script): one
line and an "Open Tracks" button to `/apps/tickbuddy/#tracks`. Still registered in
`appinfo/info.xml` (`<settings>`) so old bookmarks find the link; removing it is a
later release.

## Commands

The Makefile is the shared one (nextcloud `app-project-files` skill): `make dev`,
`make lint`, `make test`, `make psalm`, `make openapi`, `make check`. `make stage`,
`make package` and `make appstore` are release targets: Martin runs them.

```sh
npm run build        # production build (dev, watch for development)
npm run lint         # ESLint
npm run stylelint
npm run typecheck    # vue-tsc --noEmit, .ts and .vue templates (alias ts:check, used by CI)
npm test             # Vitest (src/*.test.ts), TZ=Europe/Berlin via vitest.config.ts so date walks cross DST

composer lint        # PHP syntax check
composer cs:check    # cs:fix to apply
composer psalm       # level 1, findUnusedCode
composer test:unit   # PHPUnit; standalone on the ocp stubs, in CI inside a server checkout
composer rector
composer openapi     # regenerate openapi.json from the docblocks
```

One PHP test:
`vendor-bin/phpunit/vendor/bin/phpunit tests/unit/Controller/ApiTest.php -c tests/phpunit.xml`

**CI** follows the `nextcloud/.github` org templates (nextcloud `github-ci` skill).
`assign.yml` is the only workflow of our own. Deliberate changes, re-applied by
every sync as `.yml.patch` files:
- `update-nextcloud-ocp-matrix.yml.patch` removes the `repository_owner ==
  'nextcloud'` guard and, with `npm-audit-fix.yml.patch`, the approve and
  auto-merge steps: **bot PRs are merged by hand**.
- `node-test.yml.patch` removes the coverage steps (no `test:coverage` script, no Codecov).
- `appstore-build-publish.yml` is deliberately absent: releases are packaged by
  hand and uploaded to the app store by hand.

## Demo data

`demo/` holds a ready-made dataset (`tickbuddy-demo-data.json`, JSON import
format) for screenshots and manual testing, plus `generate-demo-data.py`, a
stdlib-only Python generator that produced it (`python3 demo/generate-demo-data.py`).
Regenerate the JSON from the script if you change either, so they stay in sync.
`demo/` is kept out of the release (`.nextcloudignore`).

## Conventions

- PHP namespace `OCA\Tickbuddy\`; app id `Application::APP_ID` (`'tickbuddy'`);
  every PHP file `declare(strict_types=1)`.
- Psalm level 1 with `findUnusedCode`: suppress classes Nextcloud injects with
  `@psalm-suppress UnusedClass`.
- Node 24: `.nvmrc` and `package.json` `engines` stay in step; CI reads `engines`
  (node and npm) with no fallback.
- A new settled design decision goes into the rule for its area; a platform
  lesson goes into the nextcloud skills; open work into `docs/backlog.md`.
