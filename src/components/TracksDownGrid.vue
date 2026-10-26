<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { isWeekend, parseDateStr, userLocale } from '../dates.ts'
import type { JournalActions } from '../journal.ts'
import type { Track } from '../types.ts'
import JournalCell from './JournalCell.vue'

// One row per track; days across, newest first on the left, as the dashboard
// widget and Tickdroid's Tracks down.

const props = defineProps<{
	tracks: Track[]
	dates: string[]
	today: string
	actions: JournalActions
}>()

const emit = defineEmits<{
	more: []
}>()

const weekdayFormatter = new Intl.DateTimeFormat(userLocale, { weekday: 'narrow' })

function weekday(date: string): string {
	return date === props.today ? 'Today' : weekdayFormatter.format(parseDateStr(date))
}

function dayClasses(date: string, style: Record<string, string>): (string | false)[] {
	return [
		date === props.today && style.today,
		isWeekend(date) && style.weekend,
	]
}

// Loads more as the right end comes into view
const sentinel = ref<HTMLElement | null>(null)
let observer: IntersectionObserver | null = null

watch(sentinel, (el) => {
	observer?.disconnect()
	observer = null
	if (!el) return
	observer = new IntersectionObserver((entries) => {
		if (entries[0]?.isIntersecting) emit('more')
	}, { rootMargin: '0px 200px' })
	observer.observe(el)
})

onBeforeUnmount(() => {
	observer?.disconnect()
})
</script>

<template>
	<div :class="$style.wrap">
		<table :class="$style.grid">
			<thead>
				<tr>
					<!-- data-sticky and data-today tell JournalView whether today is in view -->
					<th :class="$style.corner" data-sticky />
					<th v-for="date in dates"
						:key="date"
						:class="[$style.dayHeader, ...dayClasses(date, $style)]"
						:data-today="date === today || undefined">
						<button type="button"
							:class="$style.dayButton"
							:aria-label="`Open ${actions.dayLabel(date)} in Today`"
							@click="actions.openDay(date)">
							<span :class="$style.weekday">{{ weekday(date) }}</span>
							<span>{{ parseDateStr(date).getDate() }}</span>
						</button>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="track in tracks" :key="track.id">
					<th scope="row" :class="$style.nameCell">
						<button type="button"
							:class="$style.nameButton"
							:title="`Show analytics for ${track.name}`"
							@click="actions.openTrack(track.id)">
							<span :class="$style.name">{{ track.name }}</span>
						</button>
					</th>
					<td v-for="date in dates"
						:key="date"
						:class="[$style.tickCell, ...dayClasses(date, $style)]">
						<JournalCell :track="track" :date="date" :actions="actions" />
					</td>
				</tr>
			</tbody>
		</table>
		<div ref="sentinel" :class="$style.sentinel" />
	</div>
</template>

<style module>
.wrap {
	display: flex;
	width: max-content;
	min-width: 100%;
}

.grid {
	border-collapse: separate;
	border-spacing: 0;
}

.corner,
.dayHeader {
	position: sticky;
	inset-block-start: 0;
	z-index: 5;
	background: var(--color-main-background);
}

.corner {
	inset-inline-start: 0;
	z-index: 6;
}

/* The grid's start margin, solid so that days scrolling under the sticky
   column don't show through it */
.corner,
.nameCell {
	border-inline-start: 8px solid var(--color-main-background);
}

.dayHeader {
	width: 38px;
	min-width: 38px;
	padding: 0;
	font-weight: normal;
}

/* Nextcloud's core CSS draws every button that isn't an NcButton as a light
   primary button, with selectors a single class can't beat (button:not(.button-vue,
   …) is 0,1,1, and 0,2,1 with :hover or :focus). So the grid's buttons are
   styled under .grid, as a scoped style would be. */
.grid .dayButton,
.grid .nameButton {
	min-height: 0;
	/* Its margin rule adds a :not() (0,2,1), which only !important beats */
	margin: 0 !important;
	border: none;
	background: transparent;
	font-weight: normal;
	cursor: pointer;
}

.grid .dayButton:hover,
.grid .dayButton:focus,
.grid .nameButton:hover,
.grid .nameButton:focus {
	background: transparent;
}

.grid .dayButton {
	display: flex;
	flex-direction: column;
	align-items: center;
	width: 100%;
	padding: 4px 0;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	line-height: 1.2;
}

.grid .dayButton:hover,
.grid .dayButton:focus-visible {
	text-decoration: underline;
}

.grid .today .dayButton {
	color: var(--color-primary-element);
	font-weight: bold;
}

/* "Today" is wider than the column: let it overflow both ways, centred */
.weekday {
	white-space: nowrap;
}

.nameCell {
	position: sticky;
	inset-inline-start: 0;
	z-index: 4;
	max-width: 180px;
	padding: 0;
	border-block-end: 1px solid var(--color-border);
	background: var(--color-main-background);
	text-align: start;
	font-weight: normal;
}

.grid .nameButton {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	width: 100%;
	padding: 6px 12px 6px 4px;
	color: var(--color-main-text);
	font-size: inherit;
	text-align: start;
}

.nameButton:hover .name,
.nameButton:focus-visible .name {
	text-decoration: underline;
}

.name {
	max-width: 100%;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-weight: 600;
}

.tickCell {
	width: 38px;
	min-width: 38px;
	padding: 0;
	text-align: center;
	border-block-end: 1px solid var(--color-border);
}

/* Shading as a layer over the background, so sticky headers stay solid */
.weekend {
	background-image: linear-gradient(color-mix(in srgb, var(--color-primary-element) 8%, transparent), color-mix(in srgb, var(--color-primary-element) 8%, transparent));
}

.today {
	background-image: linear-gradient(color-mix(in srgb, var(--color-primary-element) 14%, transparent), color-mix(in srgb, var(--color-primary-element) 14%, transparent));
}

.sentinel {
	flex: none;
	width: 1px;
}

@media (max-width: 1023px) {
	.dayHeader,
	.tickCell {
		width: 34px;
		min-width: 34px;
	}

	.nameCell {
		max-width: 120px;
	}
}
</style>
