import { describe, expect, it } from 'vitest'
import { addDays, dayHeading, parseDateStr, toDateStr } from './dates.ts'

describe('addDays', () => {
	it('crosses month and year ends', () => {
		expect(addDays('2026-01-31', 1)).toBe('2026-02-01')
		expect(addDays('2026-03-01', -1)).toBe('2026-02-28')
		expect(addDays('2024-02-28', 1)).toBe('2024-02-29')
		expect(addDays('2026-12-31', 1)).toBe('2027-01-01')
		expect(addDays('2027-01-01', -1)).toBe('2026-12-31')
	})

	it('steps whole days across both 2026 EU DST changes', () => {
		// The test run sets TZ=Europe/Berlin; make sure that took effect, or
		// these cases would pass without exercising a 23- or 25-hour day.
		expect(parseDateStr('2026-03-29').getTimezoneOffset()).not.toBe(parseDateStr('2026-03-30').getTimezoneOffset())

		expect(addDays('2026-03-28', 1)).toBe('2026-03-29')
		expect(addDays('2026-03-29', 1)).toBe('2026-03-30')
		expect(addDays('2026-03-30', -1)).toBe('2026-03-29')
		expect(addDays('2026-10-24', 1)).toBe('2026-10-25')
		expect(addDays('2026-10-25', 1)).toBe('2026-10-26')
		expect(addDays('2026-10-26', -1)).toBe('2026-10-25')
		expect(addDays('2026-03-01', 61)).toBe('2026-05-01')
	})
})

describe('parseDateStr and toDateStr', () => {
	it('round-trip', () => {
		for (const ds of ['2026-01-01', '2026-03-29', '2026-10-25', '2024-02-29', '2026-12-31']) {
			expect(toDateStr(parseDateStr(ds))).toBe(ds)
		}
	})
})

describe('dayHeading', () => {
	const today = '2026-10-25'

	it('says today and yesterday over the full date', () => {
		expect(dayHeading(today, today, 'en-GB')).toEqual({ title: 'Today', line: 'Sunday 25 October' })
		expect(dayHeading('2026-10-24', today, 'en-GB')).toEqual({ title: 'Yesterday', line: 'Saturday 24 October' })
	})

	it('counts 2 to 6 days back in digits', () => {
		expect(dayHeading('2026-10-23', today, 'en-GB')).toEqual({ title: '2 days ago', line: 'Friday 23 October' })
		expect(dayHeading('2026-10-19', today, 'en-GB')).toEqual({ title: '6 days ago', line: 'Monday 19 October' })
	})

	it('shows the date from 7 days back, with the year under it', () => {
		expect(dayHeading('2026-10-18', today, 'en-GB')).toEqual({ title: '18 October', line: 'Sunday, 18 October 2026' })
	})

	it('adds the year to the title in an earlier year', () => {
		expect(dayHeading('2025-12-31', '2026-01-10', 'en-GB')).toEqual({ title: '31 December 2025', line: 'Wednesday, 31 December 2025' })
	})

	it('counts across a DST change as whole days', () => {
		// 29 March 2026 is 23 hours long in Europe/Berlin
		expect(dayHeading('2026-03-28', '2026-03-30', 'en-GB').title).toBe('2 days ago')
		expect(dayHeading('2026-03-23', '2026-03-30', 'en-GB').title).toBe('23 March')
	})

	it('capitalises the relative title in other languages', () => {
		expect(dayHeading('2026-10-24', today, 'de-DE').title).toBe('Gestern')
	})
})
