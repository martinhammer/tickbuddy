import { describe, expect, it } from 'vitest'
import { archiveLinksText, exampleBase, exampleDates, lastUsedText, linkCountText, linkDetails, revokeText, trackSubname } from './publicLinks.ts'
import type { PublicLink, Track } from './types.ts'

const track: Track = { id: 3, name: 'Backup ran', type: 'boolean', sortOrder: 1, private: false, archivedAt: null, publicLinkCount: 0 }

const link: PublicLink = {
	id: 7,
	trackId: 3,
	name: 'Backup server',
	permission: 'tick',
	allowedDays: 'activeAndPrevious',
	url: 'https://cloud.example.com/ocs/v2.php/apps/tickbuddy/ping/Hx4abcdefgijkmnopqrstwxyzABCDEFG',
	lastUsedAt: null,
}

/**
 * Unix seconds of a local time (the suite runs in Europe/Berlin)
 *
 * @param local a local date and time, YYYY-MM-DDTHH:MM:SS
 */
function seconds(local: string): number {
	return new Date(local).getTime() / 1000
}

describe('linkCountText', () => {
	it('says nothing for none, and counts', () => {
		expect(linkCountText(0)).toBe('')
		expect(linkCountText(1)).toBe('1 public link')
		expect(linkCountText(2)).toBe('2 public links')
	})
})

describe('trackSubname', () => {
	it('adds private and the links after the type', () => {
		expect(trackSubname(track)).toBe('Yes/No')
		expect(trackSubname({ ...track, private: true }, 2)).toBe('Yes/No · Private · 2 public links')
		expect(trackSubname({ ...track, type: 'counter' }, 1)).toBe('Counter · 1 public link')
	})
})

describe('linkDetails', () => {
	it('names the permission and the allowed days', () => {
		expect(linkDetails(link)).toBe('Tick only · +1 day')
		expect(linkDetails({ ...link, permission: 'view', allowedDays: 'oneWeek' })).toBe('View only · 1 week')
		expect(linkDetails({ ...link, allowedDays: 'allDays' })).toBe('Tick only · All')
	})
})

describe('lastUsedText', () => {
	const now = new Date('2026-11-16T09:00:00')

	it('says when a link was never called', () => {
		expect(lastUsedText(null, now, 'en-GB')).toBe('Not used yet')
	})

	it('names today and yesterday', () => {
		expect(lastUsedText(seconds('2026-11-16T03:12:00'), now, 'en-GB')).toBe('Last used today, 03:12')
		expect(lastUsedText(seconds('2026-11-15T22:10:00'), now, 'en-GB')).toBe('Last used yesterday, 22:10')
	})

	it('counts days by local midnight', () => {
		const justAfter = new Date('2026-11-16T00:00:30')
		expect(lastUsedText(seconds('2026-11-15T23:59:00'), justAfter, 'en-GB')).toBe('Last used yesterday, 23:59')
		expect(lastUsedText(seconds('2026-11-16T00:00:10'), justAfter, 'en-GB')).toBe('Last used today, 00:00')
	})

	it('gives the date before yesterday, with the year outside this one', () => {
		expect(lastUsedText(seconds('2026-11-14T03:12:00'), now, 'en-GB')).toBe('Last used Sat 14 Nov, 03:12')
		expect(lastUsedText(seconds('2025-12-31T18:00:00'), new Date('2026-01-02T08:00:00'), 'en-GB')).toBe('Last used Wed 31 Dec 2025, 18:00')
	})

	it('steps across a DST change', () => {
		// 25 October 2026 is 25 hours long in Berlin
		const morning = new Date('2026-10-26T08:00:00')
		expect(lastUsedText(seconds('2026-10-25T00:30:00'), morning, 'en-GB')).toBe('Last used yesterday, 00:30')
		expect(lastUsedText(seconds('2026-10-24T23:30:00'), morning, 'en-GB')).toBe('Last used Sat 24 Oct, 23:30')
	})
})

describe('revokeText', () => {
	it('says what each kind stops doing', () => {
		expect(revokeText(link, 'Backup ran')).toBe('Scripts using this link will no longer be able to tick the track "Backup ran". Ticks already made are kept.')
		expect(revokeText({ ...link, permission: 'view' }, 'Backup ran')).toBe('Scripts using this link will no longer be able to read the track "Backup ran".')
	})
})

describe('archiveLinksText', () => {
	it('says nothing for a track without links', () => {
		expect(archiveLinksText(0)).toBe('')
		expect(archiveLinksText(1)).toBe('This track\'s public link will be revoked.')
		expect(archiveLinksText(2)).toBe('This track\'s 2 public links will be revoked.')
	})
})

describe('exampleBase', () => {
	it('keeps the address up to the token', () => {
		expect(exampleBase(link.url)).toBe('https://cloud.example.com/ocs/v2.php/apps/tickbuddy/ping/')
	})

	it('works under a subfolder and with index.php', () => {
		expect(exampleBase('https://example.com/nextcloud/index.php/ocs/v2.php/apps/tickbuddy/ping/abc'))
			.toBe('https://example.com/nextcloud/index.php/ocs/v2.php/apps/tickbuddy/ping/')
		expect(exampleBase('https://example.com/nextcloud/ocs/v2.php/apps/tickbuddy/ping/abc'))
			.toBe('https://example.com/nextcloud/ocs/v2.php/apps/tickbuddy/ping/')
	})

	it('is empty for an address without a token', () => {
		expect(exampleBase('https://example.com/')).toBe('')
	})
})

describe('exampleDates', () => {
	it('is yesterday, and the seven days ending today', () => {
		expect(exampleDates('2026-11-16')).toEqual({ yesterday: '2026-11-15', from: '2026-11-10', to: '2026-11-16' })
	})
})
