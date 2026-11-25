---
paths:
  - "src/components/AnalyticsView.vue"
  - "src/heatmap.ts"
---

# Analytics screen

First day of week: `getFirstDay()` (nextcloud `vue-frontend` skill).

`src/components/AnalyticsView.vue` is the **Analytics** screen. It analyses **one track at a time** (picked via `NcSelect`, nothing beside it; the picker lists active tracks only) and honours the saved "Show private tracks" value (`preferences.ts`), which has no button here: it is set with the eye on Today or in the Journal. The stat cards, streaks and the Streaks/Breaks chart come from `GET /api/stats` (the selected track, `includeRuns=true`); the heatmap, polar and time-series charts read the track's own ticks (`GET /api/ticks` with `trackId`, from the stats' `firstTick` to today, so the stats come first and a track without ticks sends no ticks request) and aggregate client-side, all of them up to the same end day as the stats (`chartsEnd`: today, or an archived track's archive date), so a future-dated tick counts nowhere. The stats are asked for as of `useToday`'s day, so the screen moves on at midnight; that reload and the one on focus after 5 minutes are quiet (the charts stay up, a failure keeps them). A failed load shows an error view with Try again, never the empty-account copy: "Couldn't load your tracks" in place of the screen, or "Couldn't load this track" under the picker; a new track's load clears the previous track's stats first, so its totals can never sit beside another track's charts. Charts use Chart.js via `vue-chartjs`, plus `chartjs-plugin-zoom`.

**A track the picker doesn't list** (archived, or private while private tracks are hidden) is shown when the route names it (`#analytics/<id>`, from a row's ⋯ menu on Tracks): it is the picker's value but not an option. The navigation's link never names one (see `update:trackId` below); a deleted id falls back to the first visible track. An archived one also gets a grey "Archived on 2 March 2026" above the charts, and the heatmap and charts end on the archive date, as its stats do on the server. The view emits `update:trackId` with whether the picker lists the track, so `App.vue`'s `analyticsTrackId` (the navigation's link, "last track viewed") only ever holds a listed one.

Contents, top to bottom:
- **Stat cards**: Total, Weekly mean, 2-week trend (a rotated arrow); Current streak/break, Longest streak, Longest break.
- **Calendar heatmap**: a GitHub-contributions-style grid, hand-rolled as SVG (no charting library). Shows a trailing **365 days**, extended further back when the track has older history, in a horizontally scrolling container pinned to today. Weekday labels sit in a **fixed column outside the scroller** so they stay visible; month labels are slanted and scroll with the grid. **Boolean** tracks render one shade; **counter** tracks quantise into four levels against that track's own max, the stats' `maxValue`, so future-dated ticks don't set the scale and the widget shades a day the same way (small ranges map value→level directly). Empty days use `--color-background-dark`. Hover shows a **custom tooltip styled to match the Chart.js default** (the swatch flattens the cell's translucent fill over `--color-main-background` so it matches the cell in either theme).
- **Streaks/Breaks**: a zoom/pan line chart of alternating streak (up) and break (down) run lengths, with a tertiary "Reset zoom" `NcButton`.
- **Days of week** and **Months** polar-area charts, aggregated across all years. Slice shading is **darker = higher value**, reinforcing the slice radius and matching the heatmap's Less→More direction (`polarShades`).
- **Weeks / Months / Quarters / Years** time-series line charts (`buildTimeSeries`; weeks use ISO 8601).

Key conventions:
- Chart accent colour is read from the `--color-primary-element` CSS variable (`getPrimaryColor`) and tinted via `hexToRgba`, so charts follow the Nextcloud theme.
- **Streak maths lives on the server**, in `StatsService::summariseStreaks` behind `GET /api/stats`, the one implementation every client (web, Tickdroid, Tickcli, TickLLMe) reads, so Analytics and the dashboard widget always agree. The rule: days count from the first tick on or before today, future ticks are ignored, and **an open today never ends a streak** — if today is unticked but yesterday is ticked, the current streak counts up to yesterday (`upToYesterday`, shown as a second tooltip line on "Current streak"); otherwise an open today joins the break.
- Remember sparse storage: a missing day means zero/not-ticked, which the client-side date walks must fill in (they do not assume a row per day).
- **Chart.js option objects need an explicit `ChartOptions<'line'>` (or `<'polarArea'>`) annotation**, as `streaksBreaksData` does. Without one, TS infers `mode: 'x'` as `string` and `min: 'original'` as `string`, which are not assignable to Chart.js's narrow unions, so the `:options` binding fails `npm run typecheck`. The same annotation forces axis `ticks.callback` to accept `number | string` (Chart.js's declared signature) even where a linear scale only ever passes numbers.
