# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.0] - 2026-10-30

### Added
- Today screen, where Tickbuddy now opens: one row per track for a single day, with the last seven days, the current streak or break, and the day's note; arrows go back to earlier days, and a Today button comes back
- Journal layouts setting: Days down (as before) and Tracks down (days across, today first)
- Editable days setting: choose which days a click can change (Today, +1 day, 1 week, All); other days are locked
- Notes on the web: add, edit and delete a day's note on Today and in the Journal
- Journal: "Go to date" starts the journal on any earlier day
- Tracks: new screen to manage tracks and import/export

### Changed
- Edit journal and View journal merged into one Journal screen
- Track management moved from Personal settings into the app (Tracks screen)
- "Show private tracks" moved from the navigation's settings to a button on Today, the Journal and Analytics; the setting is now stored across sessions
- Counters add and take one instead of setting a value, and yes/no days are set to what the checkbox shows instead of toggled, so changes made meanwhile on another device are kept

### Removed
- "Default screen" setting
- API: `defaultView` from `GET`/`PUT /api/preferences`, which now hold the web's toolbar choices (an unknown value is http 400)


## [2.3.0] - 2026-10-24

### Added
- Backend: table `tickbuddy_notes` added to hold notes
- API: set a yes/no tick to a state with `POST /api/ticks/set`
- API: `POST /api/ticks/increment` adds to a counter without losing concurrent changes
- API: `GET /api/stats` with totals, weekly mean, trend inputs, streaks and highest/lowest value per track, with an optional period (`from`, `to`)
- API: notes on a track's day (`/api/notes`, up to 500 characters), also settable with toggle, set and increment; appending a line (`POST /api/notes/append`, or `appendNote` on toggle, set and increment) skips a line that's already there and never overwrites another device's change; exported and imported with backups
- API: clients that send `Prefer: handling=strict` get http 423 Locked instead of a silent 200 when trying to change an archived track
- API: every error answer carries a machine-readable `reason` next to its message
- API: creating a track, toggle, set, increment and appending to a note can be safely retried by sending an `Idempotency-Key` header (flag `idempotencyKey`)
- Import: Tickmate backup import now creates disabled tracks as archived (previously those tracks were not imported)

### Changed
- API: toggle and set reject a date that isn't YYYY-MM-DD
- API: rename, toggle private, re-archive, or change order with an archived track no longer changes it
- API: toggle also answers `value` (1 or 0), as the tick list does; `ticked` stays for older clients
- Dashboard widget no longer loads the whole tick history
- Analytics ignores future-dated ticks in the total and weekly mean, and the weekly mean runs to today instead of the last tick
- Journal: a tick on a track archived or deleted elsewhere shows a message and refreshes the journal; other failed ticks show an error instead of nothing

### Fixed
- Analytics weekly mean was incorrect in some scenarios
- Track names over 255 characters gives a clear error instead of a server error
- Renaming a track to a blank name gives a clear error instead of a server error
- Import: a backup with an invalid date, an unknown track, an invalid value, a repeated day or a malformed entry now fails with a message instead of being partly imported
- Import: merging a track with a very long name no longer fails with a database error
- Import: Tickmate backup import incorrectly created counter tracks as yes/no if they only had values of 0 and 1 in all days 


## [2.2.0] - 2026-10-17

### Added
- Archive functionality for tracks which the user no longer actively tracks but wants to keep the history
- Restore functionality to reactivate archived tracks
- Note for Tickdroid users: archived tracks are not visible in Tickdroid versions 1.2.x and lower

### Changed
- Delete confirmation dialogue offers to archive the track instead
- Updated Settings screen for consistency and polish
- Export to JSON file includes new field `trackIndex` to distinguish tracks with duplicate names

### Fixed
- Importing a Tickbuddy backup with tracks of the same name now puts each tick on the right track (depends on `trackIndex` field in the backup file)
- Reordering tracks no longer moves tracks that weren't in the list to the top


## [2.1.0] - 2026-10-10

### Added
- Nextcloud dashboard widget showing the last 7 days and the current streak or break of each track
- Toast messages for success and error on set default view, import, export, delete track actions
- Toast messages for errors on add track, rename track, toggle track privacy, reorder tracks actions

### Changed
- Changed streak calculation logic in Analytics: a streak is no longer broken while today is still open; it counts up to yesterday
- Bumped dependencies


## [2.0.0] - 2026-09-29

### Changed
- Removed support for Nextcloud version 32
- Added support for Nextcloud version 36
- Bumped front-end and PHP dependencies

### Fixed
- Icon files follow the correct standards
- Broken screenshots on Nextcloud App store


## [1.1.1] - 2026-09-05

### Added
- Track headings on Edit journal and View journal are clickable and navigate to the track in Analytics view

### Changed
- Removed labels on View journal
- Bumped dependencies
- Improved test scripts

### Fixed
- Button variant applied correctly
- Chart options types


## [1.1.0] - 2026-07-29

### Added
- New API endpoint `GET /api/ticks/bounds` returning the first and last tick date per track
- "Jump to oldest" button on the View journal screen

### Changed
- Improvements on Analytics screen

### Fixed
- `PUT`/`DELETE /api/tracks/{id}` now constrain `{id}` to digits


## [1.0.7] - 2026-07-25

### Added
- Calendar heatmap on the Analytics screen ("GitHub contributions" style visualisation) 
- Dummy data for demo purposes 

### Changed
- Support for Nextcloud version 35
- Improvements on Analytics screen
- Bumped dependencies

### Fixed
- First day of week on charts is now correctly reflecting the Nextcloud user setting 


## [1.0.6] - 2026-07-05

### Added
- Implemented OCP\Capabilities\ICapability to advertise the current Tickbuddy version and feature map


## [1.0.5] - 2026-07-04

### Fixed
- Error on Postgres database when toggling the private track checkbox

### Changed
- Bumped dependencies


## [1.0.4] - 2026-06-13

### Changed
- Removed support for Nextcloud 31


## [1.0.3] - 2026-06-08

### Changed
- Bumped front-end and PHP dependencies
- Bumped Nextcloud max version


## [1.0.2] - 2026-05-15

## Added
- Analytics: Added zoomable streaks/breaks chart

## Changed
- Analytics: Radar/area charts for days-of-week and months instead of bar charts
- Analytics: Summary statistics now show current streak/break instead of always the all-time streak.
- Frontend and PHP dependencies bumped

## Fixed
- API: Fixed API issues
- Fixed GitHiub CI


## [1.0.1] - 2026-04-26

### Fixed
- Migration script error with MySQL database
- Locale handling bug an app views


## [1.0.0] - 2026-04-25

### Added
- First release
- All basic functionality for daily tracking
- Ensuring look and feel fits well within Nextcloud

- **Settings screen**
  - Add tracks with Yes/No or Counter types
  - Delete tracks
  - Rename tracks
  - Reorder tracks
  - Mark tracks as Private
  - Set a preference for default app view
  - Import/export using standard JSON format
  - Import from Tickmate backup

- **Main app screen - Common**
  - In-app setting to show/hide private tracks

- **Main app screen - Edit tracks**
  - Visually edit track data by day
  - Infinite scroll to move back in history

- **Main app screen - View tracks**
  - Nicely formatted read-only view of track data
  - Date selectors to explicitly select a date range
  - Sort latest/oldest first
  - Infinite scroll to move forward/back in history 

- **Main app screen - Analytics**
  - Track selector to select one track at a time
  - Simple statistics and charts visualising the track data
