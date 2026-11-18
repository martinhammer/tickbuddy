import { describe, expect, it } from 'vitest'
import { levelFor, rangeLevel, trackLevel, trackValueText, valueText } from './heatmap.ts'
import type { Track, TrackStats } from './types.ts'

describe('levelFor', () => {
	it('uses level 2 for any ticked boolean day', () => {
		expect(levelFor(1, false, 1)).toBe(2)
		expect(levelFor(1, false, 0)).toBe(2)
	})

	it('maps small counter ranges directly', () => {
		expect(levelFor(1, true, 3)).toBe(1)
		expect(levelFor(2, true, 3)).toBe(2)
		expect(levelFor(3, true, 3)).toBe(3)
	})

	it('quantises larger counter ranges into four levels', () => {
		expect(levelFor(1, true, 10)).toBe(1)
		expect(levelFor(3, true, 10)).toBe(2)
		expect(levelFor(5, true, 10)).toBe(2)
		expect(levelFor(6, true, 10)).toBe(3)
		expect(levelFor(10, true, 10)).toBe(4)
	})

	it('is 0 for an empty day', () => {
		expect(levelFor(0, false, 1)).toBe(0)
		expect(levelFor(0, true, 10)).toBe(0)
	})
})

describe('valueText', () => {
	it('describes all four combinations', () => {
		expect(valueText(1, false)).toBe('Ticked')
		expect(valueText(0, false)).toBe('Not ticked')
		expect(valueText(3, true)).toBe('3')
		expect(valueText(0, true)).toBe('0')
	})
})

function makeTrack(fields: Partial<Track>): Track {
	const track: Track = { id: 1, name: 'Run', type: 'number', sortOrder: 1, private: false, archivedAt: null, publicLinkCount: 0, unit: null, decimalPlaces: null, allowNegative: null, options: null, orderedScale: null }
	return { ...track, ...fields }
}

function makeStats(minValue: number | null, maxValue: number | null): TrackStats {
	const empty = { trackId: 1, total: 0, weeklyMean: 0, last7: 0, previous7: 0, peak7: 0, current: null, upToYesterday: false, longestStreak: null, longestBreak: null, firstTick: null, lastTick: null }
	return { ...empty, minValue, maxValue }
}

const options = (n: number) => Array.from({ length: n }, (_, i) => ({ value: i + 1, label: `Option ${i + 1}` }))

describe('rangeLevel', () => {
	it('gives the lowest level 1 and the highest level 4', () => {
		expect(rangeLevel(0, 0, 100)).toBe(1)
		expect(rangeLevel(25, 0, 100)).toBe(1)
		expect(rangeLevel(26, 0, 100)).toBe(2)
		expect(rangeLevel(75, 0, 100)).toBe(3)
		expect(rangeLevel(100, 0, 100)).toBe(4)
	})

	it('is level 2 when every value is the same, or the range is unknown', () => {
		expect(rangeLevel(5, 5, 5)).toBe(2)
		expect(rangeLevel(5, null, 5)).toBe(2)
		expect(rangeLevel(5, undefined, undefined)).toBe(2)
	})
})

describe('trackLevel', () => {
	const number = makeTrack({ type: 'number', decimalPlaces: 1, allowNegative: true })

	it('shades a Number between its lowest and highest', () => {
		const stats = makeStats(-5, 3)
		expect(trackLevel(-5, number, stats)).toBe(1)
		expect(trackLevel(3, number, stats)).toBe(4)
		// Negative values sit in the same range, lighter than higher ones
		expect(trackLevel(-1, number, stats)).toBe(2)
		expect(trackLevel(0, number, stats)).toBe(3)
	})

	it('fills a Number\'s 0, which is a reading', () => {
		expect(trackLevel(0, number, makeStats(0, 10))).toBe(1)
	})

	it('is level 2 for a Number whose values are all equal, or without stats', () => {
		expect(trackLevel(7.5, number, makeStats(7.5, 7.5))).toBe(2)
		expect(trackLevel(7.5, number, undefined)).toBe(2)
	})

	it('leaves an empty day empty', () => {
		expect(trackLevel(null, number, makeStats(0, 10))).toBe(0)
		expect(trackLevel(null, makeTrack({ type: 'choice', options: options(4), orderedScale: true }), undefined)).toBe(0)
	})

	it('shades an ordered scale by place, its ends lightest and darkest', () => {
		const two = makeTrack({ type: 'choice', options: options(2), orderedScale: true })
		expect([1, 2].map(v => trackLevel(v, two, undefined))).toEqual([1, 4])
		const four = makeTrack({ type: 'choice', options: options(4), orderedScale: true })
		expect([1, 2, 3, 4].map(v => trackLevel(v, four, undefined))).toEqual([1, 2, 3, 4])
		const eleven = makeTrack({ type: 'choice', options: options(11), orderedScale: true })
		expect([1, 2, 6, 11].map(v => trackLevel(v, eleven, undefined))).toEqual([1, 1, 2, 4])
	})

	it('shades a plain list in one shade', () => {
		const list = makeTrack({ type: 'choice', options: options(3), orderedScale: false })
		expect([1, 2, 3].map(v => trackLevel(v, list, undefined))).toEqual([2, 2, 2])
	})

	it('leaves Yes/No and Counter as they were', () => {
		expect(trackLevel(1, makeTrack({ type: 'boolean' }), undefined)).toBe(2)
		expect(trackLevel(null, makeTrack({ type: 'boolean' }), undefined)).toBe(0)
		expect(trackLevel(6, makeTrack({ type: 'counter' }), makeStats(0, 10))).toBe(3)
	})
})

describe('trackValueText', () => {
	it('gives a Number\'s value with its unit, in the language', () => {
		const run = makeTrack({ type: 'number', decimalPlaces: 1, unit: 'km' })
		expect(trackValueText(10.4, run, 'en-GB')).toBe('10.4 km')
		expect(trackValueText(10.4, run, 'nl-BE')).toBe('10,4 km')
		expect(trackValueText(10.4, makeTrack({ type: 'number', decimalPlaces: 2, unit: '' }), 'en-GB')).toBe('10.40')
	})

	it('gives a Choice\'s label', () => {
		expect(trackValueText(2, makeTrack({ type: 'choice', options: [{ value: 1, label: 'Excited' }, { value: 2, label: 'Happy' }] }))).toBe('Happy')
	})

	it('says No value for an empty day', () => {
		expect(trackValueText(null, makeTrack({ type: 'number' }))).toBe('No value')
		expect(trackValueText(null, makeTrack({ type: 'choice', options: options(2) }))).toBe('No value')
	})

	it('leaves Yes/No and Counter as they were', () => {
		expect(trackValueText(null, makeTrack({ type: 'boolean' }))).toBe('Not ticked')
		expect(trackValueText(3, makeTrack({ type: 'counter' }))).toBe('3')
	})
})
