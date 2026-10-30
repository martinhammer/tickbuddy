import { describe, expect, it } from 'vitest'
import { runText } from './runText.ts'
import type { Run, TrackStats } from './types.ts'

function stats(current: Run | null, upToYesterday = false): TrackStats {
	return {
		trackId: 1,
		total: 0,
		weeklyMean: 0,
		last7: 0,
		previous7: 0,
		peak7: 0,
		current,
		upToYesterday,
		longestStreak: null,
		longestBreak: null,
		firstTick: null,
		lastTick: null,
		maxValue: 0,
		minValue: 0,
	}
}

function run(isStreak: boolean, length: number): Run {
	return { isStreak, length, from: '2026-10-01', to: '2026-10-03' }
}

describe('runText', () => {
	it('words a streak', () => {
		expect(runText(stats(run(true, 3)))).toBe('3-day streak')
		expect(runText(stats(run(true, 1)))).toBe('1-day streak')
	})

	it('words a break', () => {
		expect(runText(stats(run(false, 21)))).toBe('21-day break')
	})

	it('says when a streak runs up to yesterday', () => {
		expect(runText(stats(run(true, 1), true))).toBe('1-day streak, up to yesterday')
	})

	it('says when there are no ticks', () => {
		expect(runText(stats(null))).toBe('No ticks yet')
		expect(runText(undefined)).toBe('No ticks yet')
	})
})
