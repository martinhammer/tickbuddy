<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { addDays, isWeekend, parseDateStr, userLocale } from '../dates.ts'
import { MDI_LOCK_OUTLINE } from '../icons.ts'
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
							:title="`Show analytics for ${track.name}`"
							@click="actions.openTrack(track.id)">
							{{ track.name }}
						</button>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="date in dates"
					:key="date"
					:class="{ [$style.weekend]: isWeekend(date), [$style.lockedRow]: actions.isLocked(date) }"
					:data-today="date === today || undefined">
					<th scope="row" :class="$style.dateCell">
						<button type="button"
							:class="$style.dateButton"
							:aria-label="`Open ${actions.dayLabel(date)} in Today`"
							@click="actions.openDay(date)">
							<NcIconSvgWrapper v-if="actions.isLocked(date)"
								:path="MDI_LOCK_OUTLINE"
								:size="14"
								:class="$style.lock" />
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
	width: 100%;
	border-collapse: separate;
	border-spacing: 0;
}

.corner,
.trackHeader {
	position: sticky;
	inset-block-start: 0;
	z-index: 5;
	background: var(--color-main-background);
}

.corner {
	inset-inline-start: 0;
	z-index: 6;
}

.trackHeader {
	padding: 2px;
	text-align: center;
	white-space: nowrap;
}

.trackButton,
.dateButton {
	min-height: 0;
	margin: 0;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	font-size: inherit;
	cursor: pointer;
}

.trackButton {
	padding: 6px 10px;
	border-radius: var(--border-radius-element, 8px);
	font-weight: bold;
}

.trackButton:hover,
.trackButton:focus-visible {
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

.dateButton {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 6px 12px;
}

.dateButton:hover,
.dateButton:focus-visible {
	text-decoration: underline;
}

.lock {
	color: var(--color-text-maxcontrast);
}

.lockedRow .dateButton {
	opacity: 0.55;
}

.tickCell {
	padding: 0 4px;
	text-align: center;
	border-block-end: 1px solid var(--color-border);
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
</style>
