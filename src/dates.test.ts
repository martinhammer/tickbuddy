import { afterEach, describe, expect, it, vi } from 'vitest'
import { addDays, dayHeading, isoWeek, parseDateStr, squaresEnd, toDateStr } from './dates.ts'

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

describe('squaresEnd', () => {
	const today = '2026-10-26'

	it('ends on today while the day shown is one of the last seven', () => {
		expect(squaresEnd(today, today)).toBe(today)
		expect(squaresEnd('2026-10-25', today)).toBe(today)
		expect(squaresEnd('2026-10-20', today)).toBe(today)
	})

	it('keeps an older day shown as the oldest square', () => {
		expect(squaresEnd('2026-10-19', today)).toBe('2026-10-25')
		expect(squaresEnd('2026-10-01', today)).toBe('2026-10-07')
	})

	it('moves at most one day per step back', () => {
		let end = squaresEnd(today, today)
		for (let back = 1; back <= 20; back++) {
			const next = squaresEnd(addDays(today, -back), today)
			expect([end, addDays(end, -1)]).toContain(next)
			end = next
		}
	})
})

describe('isoWeek', () => {
	afterEach(() => {
		vi.unstubAllEnvs()
	})

	it('puts each day in its ISO week, across year ends', () => {
		expect(isoWeek('2021-01-03')).toBe('2020-W53')
		expect(isoWeek('2021-01-04')).toBe('2021-W01')
		expect(isoWeek('2021-04-05')).toBe('2021-W14')
		expect(isoWeek('2024-12-30')).toBe('2025-W01')
		expect(isoWeek('2026-12-31')).toBe('2026-W53')
		expect(isoWeek('2027-01-01')).toBe('2026-W53')
		expect(isoWeek('2027-01-04')).toBe('2027-W01')
	})

	it('runs in the zone it is given', () => {
		// Otherwise the walks below would all run in Berlin and prove nothing
		vi.stubEnv('TZ', 'Australia/Sydney')
		expect(new Date(2021, 0, 1).getTimezoneOffset()).toBe(-660)
	})

	// Sydney, Auckland and Santiago are on summer time in January: a local
	// count of days moved every later week one bucket up in 2016, 2021 and 2027
	for (const zone of ['Australia/Sydney', 'Pacific/Auckland', 'America/Santiago', 'Europe/Berlin', 'America/New_York']) {
		it(`starts every week on a Monday and skips none, in ${zone}`, () => {
			vi.stubEnv('TZ', zone)
			expect(isoWeek('2021-04-05')).toBe('2021-W14')
			let previous = isoWeek('2014-12-31')
			for (let day = '2015-01-01'; day <= '2032-12-31'; day = addDays(day, 1)) {
				const week = isoWeek(day)
				const monday = parseDateStr(day).getDay() === 1
				expect(week !== previous, day).toBe(monday)
				if (monday) {
					const [py, pw] = previous.split('-W').map(Number)
					const [y, w] = week.split('-W').map(Number)
					expect(y === py ? w === pw + 1 : y === py + 1 && w === 1, day).toBe(true)
				}
				previous = week
			}
		})
	}
})
