import { describe, expect, it } from 'vitest'
import { isEditable } from './editableDays.ts'
import type { EditableDays } from './types.ts'

// Each setting against today, yesterday, 6 and 7 days back, and tomorrow.
const CASES: Record<EditableDays, [boolean, boolean, boolean, boolean, boolean]> = {
	activeDay: [true, false, false, false, false],
	activeAndPrevious: [true, true, false, false, false],
	oneWeek: [true, true, true, false, false],
	allDays: [true, true, true, true, false],
}

function check(today: string, days: [string, string, string, string, string]) {
	for (const [setting, expected] of Object.entries(CASES) as [EditableDays, boolean[]][]) {
		expect(days.map(day => isEditable(day, today, setting)), setting).toEqual(expected)
	}
}

describe('isEditable', () => {
	it('locks the days each setting leaves out', () => {
		check('2026-10-20', ['2026-10-20', '2026-10-19', '2026-10-14', '2026-10-13', '2026-10-21'])
	})

	it('counts whole days across a DST change', () => {
		// Berlin leaves summer time on 25 October 2026
		check('2026-10-28', ['2026-10-28', '2026-10-27', '2026-10-22', '2026-10-21', '2026-10-29'])
	})

	it('reaches back across a month and a year', () => {
		check('2026-01-03', ['2026-01-03', '2026-01-02', '2025-12-28', '2025-12-27', '2026-01-04'])
	})

	it('keeps All open for every past day', () => {
		expect(isEditable('2000-01-01', '2026-10-20', 'allDays')).toBe(true)
	})
})
