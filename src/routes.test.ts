import { describe, expect, it } from 'vitest'
import { hashFor, parseHash } from './routes.ts'

const TODAY = '2026-10-25'

describe('parseHash', () => {
	it('opens Today on today for no hash, a bare # and anything unknown', () => {
		for (const hash of ['', '#', '#nothing', '#journal2', '#Today']) {
			expect(parseHash(hash, TODAY), hash).toEqual({ route: { screen: 'today', day: null }, replaceWith: '#today' })
		}
	})

	it('opens Today on today', () => {
		expect(parseHash('#today', TODAY)).toEqual({ route: { screen: 'today', day: null }, replaceWith: null })
	})

	it('opens Today on an earlier day', () => {
		expect(parseHash('#today/2026-10-20', TODAY)).toEqual({ route: { screen: 'today', day: '2026-10-20' }, replaceWith: null })
		expect(parseHash('#today/2019-02-28', TODAY).route).toEqual({ screen: 'today', day: '2019-02-28' })
	})

	it('opens today for today, a future day, or a day that isn\'t real', () => {
		for (const hash of ['#today/2026-10-25', '#today/2026-10-26', '#today/2026-02-30', '#today/x', '#today/2026-1-2', '#today/']) {
			expect(parseHash(hash, TODAY), hash).toEqual({ route: { screen: 'today', day: null }, replaceWith: '#today' })
		}
	})

	it('opens the Journal, also from View journal\'s old link', () => {
		expect(parseHash('#journal', TODAY)).toEqual({ route: { screen: 'journal' }, replaceWith: null })
		expect(parseHash('#readonly', TODAY)).toEqual({ route: { screen: 'journal' }, replaceWith: '#journal' })
	})

	it('opens Analytics, with or without a track', () => {
		expect(parseHash('#analytics', TODAY)).toEqual({ route: { screen: 'analytics', trackId: null }, replaceWith: null })
		expect(parseHash('#analytics/3', TODAY)).toEqual({ route: { screen: 'analytics', trackId: 3 }, replaceWith: null })
		expect(parseHash('#analytics/x', TODAY)).toEqual({ route: { screen: 'analytics', trackId: null }, replaceWith: '#analytics' })
	})

	it('opens Tracks, with or without a track\'s sidebar', () => {
		expect(parseHash('#tracks', TODAY)).toEqual({ route: { screen: 'tracks', trackId: null }, replaceWith: null })
		expect(parseHash('#tracks/3', TODAY)).toEqual({ route: { screen: 'tracks', trackId: 3 }, replaceWith: null })
		expect(parseHash('#tracks/3/x', TODAY)).toEqual({ route: { screen: 'tracks', trackId: null }, replaceWith: '#tracks' })
	})

	it('takes a hash without its #', () => {
		expect(parseHash('journal', TODAY)).toEqual({ route: { screen: 'journal' }, replaceWith: null })
	})
})

describe('hashFor', () => {
	it('round-trips through parseHash', () => {
		for (const hash of ['#today', '#today/2026-10-20', '#journal', '#analytics', '#analytics/3', '#tracks', '#tracks/12']) {
			expect(hashFor(parseHash(hash, TODAY).route)).toBe(hash)
		}
	})
})
