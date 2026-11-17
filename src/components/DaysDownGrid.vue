<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { addDays, isWeekend, parseDateStr, userLocale } from '../dates.ts'
import type { JournalActions } from '../journal.ts'
import type { Track } from '../types.ts'
import JournalCell from './JournalCell.vue'

// One row per day, newest first; one column per track.

const props = defineProps<{
	tracks: Track[]
	dates: string[]
	today: string
	actions: JournalActions
}>()

const emit = defineEmits<{
	more: []
}>()

const shortFormatter = new Intl.DateTimeFormat(userLocale, { weekday: 'short', month: 'short', day: 'numeric' })
const yearFormatter = new Intl.DateTimeFormat(userLocale, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })

// The unit's line under the names exists only when a track on screen has a unit
const hasUnits = computed(() => props.tracks.some(t => t.type === 'number' && t.unit))

// The full name, and the unit on a second line
function headerTitle(track: Track): string {
	return track.type === 'number' && track.unit ? `${track.name}\n${track.unit}` : track.name
}

// As Edit journal: "Today", "Yesterday", then the date (with its year outside this one)
function formatDate(date: string): string {
	if (date === props.today) return 'Today'
	if (date === addDays(props.today, -1)) return 'Yesterday'
	const sameYear = date.slice(0, 4) === props.today.slice(0, 4)
	return (sameYear ? shortFormatter : yearFormatter).format(parseDateStr(date))
}

// Loads more as the end comes into view
const sentinel = ref<HTMLElement | null>(null)
let observer: IntersectionObserver | null = null

watch(sentinel, (el) => {
	observer?.disconnect()
	observer = null
	if (!el) return
	observer = new IntersectionObserver((entries) => {
		if (entries[0]?.isIntersecting) emit('more')
	}, { rootMargin: '200px' })
	observer.observe(el)
})

onBeforeUnmount(() => {
	observer?.disconnect()
})
</script>

<template>
	<div>
		<table :class="$style.grid">
			<thead>
				<tr>
					<!-- data-sticky and data-today tell JournalView whether today is in view -->
					<th :class="$style.corner" data-sticky />
					<th v-for="track in tracks" :key="track.id" :class="$style.trackHeader">
						<button type="button"
							:class="$style.trackButton"
							:aria-label="`Show analytics for ${track.name}`"
							:title="headerTitle(track)"
							@click="actions.openTrack(track.id)">
							<span :class="$style.trackName">{{ track.name }}</span>
							<span v-if="hasUnits" :class="$style.unit">{{ track.type === 'number' ? track.unit : '' }}</span>
						</button>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="date in dates"
					:key="date"
					:class="{ [$style.weekend]: isWeekend(date) }"
					:data-today="date === today || undefined">
					<th scope="row" :class="$style.dateCell">
						<button type="button"
							:class="$style.dateButton"
							:aria-label="`Open ${actions.dayLabel(date)} in Today`"
							@click="actions.openDay(date)">
							{{ formatDate(date) }}
						</button>
					</th>
					<td v-for="track in tracks" :key="track.id" :class="$style.tickCell">
						<JournalCell :track="track" :date="date" :actions="actions" />
					</td>
				</tr>
			</tbody>
		</table>
		<div ref="sentinel" :class="$style.sentinel" />
	</div>
</template>

<style module>
.grid {
	/* Track headers' maximum width, which also cuts Choice labels in the cells */
	--days-down-header-width: 120px;
	width: 100%;
	border-collapse: separate;
	border-spacing: 0;
}

/* Layers, in both grids and JournalCell.vue: the sticky first column 4, a
   hovered, focused or editing cell 5 (its floating bar and note box reach past
   the cell, over the first column's start), the sticky header row 6, the corner 7 */
.corner,
.trackHeader {
	position: sticky;
	inset-block-start: 0;
	z-index: 6;
	background: var(--color-main-background);
}

.corner {
	inset-inline-start: 0;
	z-index: 7;
}

/* The grid's start margin, solid so that days scrolling under the sticky
   column don't show through it */
.corner,
.dateCell {
	border-inline-start: 8px solid var(--color-main-background);
}

/* A maximum width, so a long name or unit can't widen its column (one value,
   --days-down-header-width, to refine) */
.trackHeader {
	max-width: var(--days-down-header-width);
	padding: 2px;
	text-align: center;
	vertical-align: top;
	white-space: nowrap;
}

/* Nextcloud's core CSS draws every button that isn't an NcButton as a light
   primary button, with selectors a single class can't beat (button:not(.button-vue,
   …) is 0,1,1, and 0,2,1 with :hover or :focus). So the grid's buttons are
   styled under .grid, as a scoped style would be. */
.grid .trackButton,
.grid .dateButton {
	width: auto;
	min-height: 0;
	/* Its margin rule adds a :not() (0,2,1), which only !important beats */
	margin: 0 !important;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	font-size: inherit;
	font-weight: normal;
	cursor: pointer;
}

.grid .trackButton:focus,
.grid .dateButton:hover,
.grid .dateButton:focus {
	background: transparent;
}

.grid .trackButton {
	display: inline-flex;
	flex-direction: column;
	align-items: center;
	max-width: 100%;
	padding: 6px 10px;
	border-radius: var(--border-radius-element, 8px);
	font-weight: bold;
}

/* Name and unit each cut with "…" on its own line */
.trackName,
.unit {
	max-width: 100%;
	overflow: hidden;
	text-overflow: ellipsis;
}

/* Kept when empty (a track without a unit), so the names stay aligned */
.unit {
	min-height: 1.3em;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	font-weight: normal;
}

.grid .trackButton:hover,
.grid .trackButton:focus-visible {
	background: var(--color-background-hover);
}

.dateCell {
	position: sticky;
	inset-inline-start: 0;
	z-index: 4;
	padding: 0;
	border-block-end: 1px solid var(--color-border);
	background: var(--color-main-background);
	text-align: start;
	font-weight: normal;
	white-space: nowrap;
}

.grid .dateButton {
	padding: 6px 12px;
}

.tickCell {
	--cell-label-width: var(--days-down-header-width);
	padding: 0 4px;
	text-align: center;
	border-block-end: 1px solid var(--color-border);
	/* Solid like the date cell, so Nextcloud's own tr:hover colour doesn't show
	   through and the row highlights in one colour */
	background-color: var(--color-main-background);
}

/* Shading on the cells, not the row: the sticky date cell needs a solid background */
.weekend > * {
	background-image: linear-gradient(color-mix(in srgb, var(--color-primary-element) 8%, transparent), color-mix(in srgb, var(--color-primary-element) 8%, transparent));
}

.grid tbody tr:hover > * {
	background-image: linear-gradient(color-mix(in srgb, var(--color-primary-element) 15%, transparent), color-mix(in srgb, var(--color-primary-element) 15%, transparent));
}

.sentinel {
	height: 1px;
}

@media (max-width: 1023px) {
	.grid {
		--days-down-header-width: 96px;
	}
}
</style>
