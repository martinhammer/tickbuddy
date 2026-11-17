// Shading shared by the Analytics heatmap, Today's squares and the dashboard widget.

import { userLocale } from './dates.ts'
import { decimalsOf, formatNumber, toMilli } from './numberValue.ts'
import type { Track, TrackStats } from './types.ts'

/** Alpha per level: index 0 is unused (empty days get EMPTY_FILL). */
export const LEVEL_ALPHA = [0, 0.25, 0.45, 0.68, 0.9]
export const EMPTY_FILL = 'var(--color-background-dark)'

/**
 * Booleans get a single shade at level 2 — full strength reads as too heavy
 * when every ticked day is identical. Counters are quantised into four levels
 * against that track's own maximum; small ranges map value→level directly so a
 * track that never exceeds 3 still uses distinct shades.
 *
 * @param value the day's tick value (0 when there is no tick)
 * @param isCounter whether the track is a counter
 * @param maxValue the track's highest tick value ever
 */
export function levelFor(value: number, isCounter: boolean, maxValue: number): number {
	if (value <= 0) return 0
	if (!isCounter) return 2
	if (maxValue <= 4) return Math.min(4, value)
	return Math.max(1, Math.min(4, Math.ceil((value / maxValue) * 4)))
}

export function valueText(value: number, isCounter: boolean): string {
	return value > 0 ? (isCounter ? String(value) : 'Ticked') : (isCounter ? '0' : 'Not ticked')
}

/**
 * A level from 1 to 4 for a value between the lowest and the highest, so the
 * lowest is the lightest filled shade and the highest the darkest; level 2
 * (Yes/No's one shade) when they are equal or unknown.
 *
 * @param value the day's value
 * @param lowest the lowest value
 * @param highest the highest value
 */
export function rangeLevel(value: number, lowest: number | null | undefined, highest: number | null | undefined): number {
	if (lowest === null || lowest === undefined || highest === null || highest === undefined || highest <= lowest) return 2
	return Math.max(1, Math.min(4, Math.ceil(4 * (value - lowest) / (highest - lowest))))
}

/**
 * A day's level for any track: Yes/No and Counter as levelFor (null is 0);
 * a Number between its lowest and highest ever (the stats' minValue and
 * maxValue), 0 being a reading and so filled; an ordered Choice by place, from
 * 1 to the number of options; a plain Choice list in one shade. Empty is 0.
 *
 * @param value the day's stored value, null when it has none
 * @param track the track
 * @param stats the track's stats, if loaded
 */
export function trackLevel(value: number | null, track: Track, stats: TrackStats | undefined): number {
	if (track.type === 'number') {
		if (value === null) return 0
		const lowest = stats?.minValue
		const highest = stats?.maxValue
		return rangeLevel(toMilli(value), lowest == null ? null : toMilli(lowest), highest == null ? null : toMilli(highest))
	}
	if (track.type === 'choice') {
		if (value === null) return 0
		return track.orderedScale ? rangeLevel(value, 1, track.options?.length ?? 0) : 2
	}
	return levelFor(value ?? 0, track.type === 'counter', stats?.maxValue ?? 0)
}

/**
 * A Number's value with its unit ("10.4 km"), in the user's language.
 *
 * @param value the stored value
 * @param track a Number track
 * @param language a BCP 47 tag
 */
export function numberText(value: number, track: Track, language = userLocale): string {
	const shown = formatNumber(value, decimalsOf(track), language)
	return track.unit ? `${shown} ${track.unit}` : shown
}

/**
 * A Choice's label for a place; the place itself when the track has no such
 * option (only a bug can make one).
 *
 * @param value the place
 * @param track a Choice track
 */
export function choiceLabel(value: number, track: Track): string {
	return track.options?.find(o => o.value === value)?.label ?? String(value)
}

/**
 * A day's value in words, for tooltips and labels: as valueText for Yes/No
 * and Counter, a Number's value with its unit, a Choice's label, and "No
 * value" for a Number or Choice day without one.
 *
 * @param value the day's stored value, null when it has none
 * @param track the track
 * @param language a BCP 47 tag
 */
export function trackValueText(value: number | null, track: Track, language = userLocale): string {
	if (track.type === 'number' || track.type === 'choice') {
		if (value === null) return 'No value'
		return track.type === 'number' ? numberText(value, track, language) : choiceLabel(value, track)
	}
	return valueText(value ?? 0, track.type === 'counter')
}

export function getPrimaryColor(): string {
	if (typeof document === 'undefined') return '#0082c9'
	return getComputedStyle(document.documentElement).getPropertyValue('--color-primary-element').trim() || '#0082c9'
}

export function hexToRgba(hex: string, alpha: number): string {
	const r = parseInt(hex.slice(1, 3), 16)
	const g = parseInt(hex.slice(3, 5), 16)
	const b = parseInt(hex.slice(5, 7), 16)
	return `rgba(${r}, ${g}, ${b}, ${alpha})`
}

export function fillFor(level: number, primaryHex: string): string {
	return level === 0 ? EMPTY_FILL : hexToRgba(primaryHex, LEVEL_ALPHA[level])
}
