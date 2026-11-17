// A Number track's values: the web's copy of the server's NumberValue, plus
// showing and reading them in the user's language. The API sends a stored
// value as a JSON number (10.45, -1.5, 72) and takes one as a string with a
// point and no grouping. Rounding works on whole thousandths, as the server
// stores them, never on the double itself.

import type { Track } from './types.ts'

/** The highest number of thousandths the server stores (its INTEGER column's range) */
export const MAX_MILLI = 2147483647

/**
 * A value as whole thousandths. Exact within the limit: a double holds every
 * thousandth there to well under half a thousandth.
 *
 * @param value the value
 */
export function toMilli(value: number): number {
	return Math.round(value * 1000)
}

/**
 * @param milli whole thousandths
 */
export function fromMilli(milli: number): number {
	return milli / 1000
}

/**
 * Thousandths rounded to `decimalPlaces` half away from zero, as the server
 * rounds. Math.round rounds -2.5 to -2, so it works on the magnitude.
 *
 * @param milli whole thousandths
 * @param decimalPlaces 0 to 3
 */
export function roundMilli(milli: number, decimalPlaces: number): number {
	const scale = 10 ** (3 - decimalPlaces)
	const magnitude = Math.abs(milli)
	const rounded = Math.floor(magnitude / scale) + (magnitude % scale * 2 >= scale ? 1 : 0)
	return Math.sign(milli) * rounded * scale || 0
}

/**
 * A track's decimal places, 1 when the server sent none.
 *
 * @param track a Number track
 */
export function decimalsOf(track: Track): number {
	return track.decimalPlaces ?? 1
}

const formatters = new Map<string, Intl.NumberFormat>()

function formatter(language: string, decimalPlaces: number): Intl.NumberFormat {
	const key = `${language}|${decimalPlaces}`
	let f = formatters.get(key)
	if (!f) {
		f = new Intl.NumberFormat(language, { minimumFractionDigits: decimalPlaces, maximumFractionDigits: decimalPlaces })
		formatters.set(key, f)
	}
	return f
}

/**
 * A value with the track's decimals and the language's separators: "10.4",
 * "10,4", "1 204,50". Rounded on thousandths first: Intl rounds the double, so
 * 10.45 (stored as 10450) would otherwise show as 10.4.
 *
 * @param value the stored value
 * @param decimalPlaces 0 to 3
 * @param language a BCP 47 tag
 */
export function formatNumber(value: number, decimalPlaces: number, language: string): string {
	return formatter(language, decimalPlaces).format(fromMilli(roundMilli(toMilli(value), decimalPlaces)))
}

interface Separators {
	decimal: string
	group: string
	minus: string
}

const separatorCache = new Map<string, Separators>()

/**
 * The language's decimal and group separators and minus sign.
 *
 * @param language a BCP 47 tag
 */
export function separators(language: string): Separators {
	let s = separatorCache.get(language)
	if (!s) {
		const parts = new Intl.NumberFormat(language, { useGrouping: true }).formatToParts(-1234567.5)
		s = {
			decimal: parts.find(p => p.type === 'decimal')?.value ?? '.',
			group: parts.find(p => p.type === 'group')?.value ?? ',',
			minus: parts.find(p => p.type === 'minusSign')?.value ?? '-',
		}
		separatorCache.set(language, s)
	}
	return s
}

const SPACES = [' ', '\u00a0', '\u202f']
const APOSTROPHES = ['\'', '\u2019']

function escape(text: string): string {
	return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * Typed text as a number, in the language's way of writing it: its decimal
 * separator, its group separator only between groups of three digits ("1.204,50"
 * in Dutch, "1,204.50" in English), and a point as the decimal separator too
 * where the group separator isn't one ("10.4" in French). A leading "-" or "−".
 * Any space stands for a group separator that is a space, and either apostrophe
 * for one that is an apostrophe (Swiss German's, whose sign varies by ICU version).
 *
 * @param text what was typed
 * @param language a BCP 47 tag
 * @return the number; null for empty text (clear the day); 'invalid' for anything else
 */
export function parseNumber(text: string, language: string): number | null | 'invalid' {
	let s = text.trim()
	if (s === '') return null
	const { decimal, group, minus } = separators(language)
	const sign = ['-', '\u2212', minus].find(m => s.startsWith(m))
	const negative = sign !== undefined
	if (sign !== undefined) s = s.slice(sign.length).trimStart()
	const groups = SPACES.includes(group) ? SPACES : APOSTROPHES.includes(group) ? APOSTROPHES : [group]
	const decimals = group !== '.' && decimal !== '.' ? [decimal, '.'] : [decimal]
	const groupClass = groups.map(escape).join('|')
	const decimalClass = decimals.map(escape).join('|')
	const pattern = new RegExp(`^(\\d{1,3}(?:(?:${groupClass})\\d{3})+|\\d+)(?:(?:${decimalClass})(\\d+))?$`)
	const m = pattern.exec(s)
	if (!m) return 'invalid'
	const whole = m[1].replace(new RegExp(groupClass, 'g'), '')
	const value = Number(`${whole}.${m[2] ?? '0'}`)
	if (!Number.isFinite(value)) return 'invalid'
	return negative && value !== 0 ? -value : value
}

/**
 * A value as the API takes it: a point, no grouping, rounded to the track's
 * decimals ("10.4", "-1.5", "72").
 *
 * @param value the value
 * @param decimalPlaces 0 to 3
 */
export function toWire(value: number, decimalPlaces: number): string {
	const milli = roundMilli(toMilli(value), decimalPlaces)
	const magnitude = Math.abs(milli)
	const whole = Math.floor(magnitude / 1000)
	const fraction = String(magnitude % 1000).padStart(3, '0').replace(/0+$/, '')
	return `${milli < 0 ? '-' : ''}${whole}${fraction ? `.${fraction}` : ''}`
}

/**
 * Whether a typed value would be stored as the day's value already is.
 *
 * @param value the value typed
 * @param stored the day's stored value, null when it has none
 * @param decimalPlaces 0 to 3
 */
export function sameAsStored(value: number | null, stored: number | null, decimalPlaces: number): boolean {
	if (value === null || stored === null) return value === stored
	return roundMilli(toMilli(value), decimalPlaces) === toMilli(stored)
}
