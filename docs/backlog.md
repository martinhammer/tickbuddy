# Backlog

Open work for this project, including where it deviates from the shared skills
(the `nextcloud` plugin and `~/Code/claude-config/skills`). Not loaded at session
start. One line per item: date, the skill it concerns, what to do.

## Deviations from shared skills
- 2026-11-24 · eslint-v9-migration · Still on `@nextcloud/eslint-config` 8 with `.eslintrc.cjs`, base `@nextcloud` config with a custom parser. Migrate to v9 (flat config).

## Known gaps / future work

Surfaced while writing the mobile companion app integration guide (`mobile_instructions.md`):

Counter increments merging across devices, once a gap here, are done: `POST /api/ticks/increment` (signed delta, flag `counterIncrement`). Note that a timestamp alone would never have fixed it: two devices pushing the same absolute value are both wrong, so last-write-wins just picks one.

- **No sync delta endpoint.** The API has no `modifiedAt`, ETag, or `since=` parameter. Clients (especially the Android app) must poll full ranges and reconcile locally. If mobile sync UX proves clunky, consider adding a lightweight "changed since X" endpoint. Note this needs **more than a `modified_at` column**: because storage is sparse (untoggling/zeroing deletes the row), a `since=` query over live rows would never surface deletions. A real fix needs tombstones (soft-delete + `deleted_at`) or a separate change log, plus the timestamp/version column. Notes have the same gap (no `modified_at`, deletions are deleted rows), so a delta endpoint must cover them too. Exposed via the `syncDelta` capability flag when it lands.
