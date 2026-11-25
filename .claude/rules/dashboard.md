---
paths:
  - "lib/Dashboard/**"
  - "src/dashboard.ts"
  - "src/dashboard.css"
  - "src/dashboardApp.ts"
  - "src/components/DashboardWidget.vue"
---

# Dashboard widget

How Nextcloud's dashboard behaves (`load()` on every visit, lazy import, 288 × 424px,
no buttons for Vue widgets): the nextcloud `dashboard-widget` skill. Tickbuddy's
widget follows it.

`lib/Dashboard/WeekWidget.php` (`IIconWidget`) + `src/components/DashboardWidget.vue`: one row per **non-private** track (always hidden here, whatever the app's eye says) in journal order, the last seven days as heatmap squares (**today first**, on the left), and the current streak or break at the end. Read-only. Loads `GET /api/tracks`, `GET /api/stats?today=…` and the last seven days of `GET /api/ticks` (never the whole history); streaks and counter shading (`maxValue`) come from the stats, so they match Analytics. `today` is reactive state that follows midnight (timer) and tab focus/visibility, with a quiet reload on day change or when data is over 5 minutes old.

- **The widget id `tickbuddy-week` is permanent**: it is stored in every user's dashboard layout. `WeekWidget::ID` and `WIDGET_ID` in `src/dashboard.ts` must match. A future editable widget gets its own id.
