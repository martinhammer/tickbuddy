import { describe, expect, it } from 'vitest'
import { addDays, parseDateStr, toDateStr } from './dates.ts'

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
