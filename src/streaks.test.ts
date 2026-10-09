import { describe, expect, it } from 'vitest'
import { addDays } from './dates.ts'
import { summariseStreaks, type Run } from './streaks.ts'

// The reference cases from the dashboard widget spec (§4.4), also the
// reference for Tickdroid. Today is Thursday 2026-10-08 throughout.
const TODAY = '2026-10-08'

function range(from: string, to: string): string[] {
	const out: string[] = []
	for (let ds = from; ds <= to; ds = addDays(ds, 1)) out.push(ds)
	return out
}

function run(isStreak: boolean, from: string, to: string, length: number): Run {
	return { isStreak, length, from, to }
}

describe('summariseStreaks', () => {
	it('1: today ticked extends the streak', () => {
		const s = summariseStreaks(range('2026-10-05', '2026-10-08'), TODAY)
		expect(s.current).toEqual(run(true, '2026-10-05', '2026-10-08', 4))
		expect(s.upToYesterday).toBe(false)
		expect(s.runs).toHaveLength(1)
	})

	it('2: open today after a ticked yesterday counts up to yesterday', () => {
		const s = summariseStreaks(range('2026-10-04', '2026-10-07'), TODAY)
		expect(s.current).toEqual(run(true, '2026-10-04', '2026-10-07', 4))
		expect(s.upToYesterday).toBe(true)
		expect(s.runs).toEqual([s.current]) // no break run for today
		expect(s.longestBreak).toBeNull()
	})

	it('3: open today after an unticked yesterday joins the break', () => {
		const s = summariseStreaks(['2026-10-01', '2026-10-04'], TODAY)
		expect(s.current).toEqual(run(false, '2026-10-05', '2026-10-08', 4))
		expect(s.upToYesterday).toBe(false)
		expect(s.runs).toEqual([
			run(true, '2026-10-01', '2026-10-01', 1),
			run(false, '2026-10-02', '2026-10-03', 2),
			run(true, '2026-10-04', '2026-10-04', 1),
			run(false, '2026-10-05', '2026-10-08', 4),
		])
		expect(s.longestBreak).toBe(s.current)
		expect(s.longestStreak).toBe(s.runs[0]) // first of equal runs wins
	})

	it('4: only yesterday ticked is a 1-day streak up to yesterday', () => {
		const s = summariseStreaks(['2026-10-07'], TODAY)
		expect(s.current).toEqual(run(true, '2026-10-07', '2026-10-07', 1))
		expect(s.upToYesterday).toBe(true)
	})

	it('5: only today ticked is a 1-day streak', () => {
		const s = summariseStreaks(['2026-10-08'], TODAY)
		expect(s.current).toEqual(run(true, '2026-10-08', '2026-10-08', 1))
		expect(s.upToYesterday).toBe(false)
	})

	it('6: no ticks has no runs', () => {
		expect(summariseStreaks([], TODAY)).toEqual({
			runs: [], current: null, upToYesterday: false, longestStreak: null, longestBreak: null,
		})
	})

	it('7: future ticks are ignored', () => {
		expect(summariseStreaks(['2026-10-10'], TODAY)).toEqual({
			runs: [], current: null, upToYesterday: false, longestStreak: null, longestBreak: null,
		})
		// ...also next to past ones.
		const s = summariseStreaks(['2026-10-08', '2026-10-09', '2026-10-10'], TODAY)
		expect(s.current).toEqual(run(true, '2026-10-08', '2026-10-08', 1))
	})

	it('8: ticked every day up to yesterday has no break at all', () => {
		const s = summariseStreaks(range('2026-10-01', '2026-10-07'), TODAY)
		expect(s.current).toEqual(run(true, '2026-10-01', '2026-10-07', 7))
		expect(s.upToYesterday).toBe(true)
		expect(s.longestStreak).toBe(s.current)
		expect(s.longestBreak).toBeNull()
	})

	it('9: streak, break, streak up to yesterday', () => {
		const s = summariseStreaks(['2026-10-01', '2026-10-02', '2026-10-06', '2026-10-07'], TODAY)
		expect(s.runs).toEqual([
			run(true, '2026-10-01', '2026-10-02', 2),
			run(false, '2026-10-03', '2026-10-05', 3),
			run(true, '2026-10-06', '2026-10-07', 2),
		])
		expect(s.current).toBe(s.runs[2])
		expect(s.upToYesterday).toBe(true)
		expect(s.longestStreak).toBe(s.runs[0])
		expect(s.longestBreak?.length).toBe(3)
	})

	it('10: runs across the EU DST change stay whole days', () => {
		const s = summariseStreaks(range('2026-03-27', '2026-03-31'), TODAY)
		expect(s.runs[0]).toEqual(run(true, '2026-03-27', '2026-03-31', 5))
		expect(s.current).toEqual(run(false, '2026-04-01', '2026-10-08', 191))
		expect(s.upToYesterday).toBe(false)
	})

	it('gives the same result for unsorted and duplicated input', () => {
		const dates = ['2026-10-01', '2026-10-02', '2026-10-06', '2026-10-07']
		const shuffled = ['2026-10-07', '2026-10-01', '2026-10-06', '2026-10-07', '2026-10-02', '2026-10-01']
		expect(summariseStreaks(shuffled, TODAY)).toEqual(summariseStreaks(dates, TODAY))
	})

	it('handles ten years of daily ticks', () => {
		const dates = range('2016-10-09', TODAY)
		const s = summariseStreaks(dates, TODAY)
		expect(s.runs).toHaveLength(1)
		expect(s.current?.length).toBe(dates.length)
		expect(s.current?.length).toBe(3652) // 3650 days plus 29 Feb 2020 and 2024
	})
})
