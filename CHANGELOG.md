# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), 
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).


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
