import { describe, expect, it } from 'vitest'
import { errorReason, httpStatus, isTickbuddyApiUrl } from './strictHandling.ts'

/**
 * A failed OCS request as axios rejects it.
 *
 * @param status the HTTP status
 * @param data the OCS `data`
 */
function failed(status: number, data: unknown) {
	return { response: { status, data: { ocs: { meta: { statuscode: status, message: '' }, data } } } }
}

describe('isTickbuddyApiUrl', () => {
	it('matches Tickbuddy\'s API, with or without a webroot', () => {
		expect(isTickbuddyApiUrl('https://cloud.example/ocs/v2.php/apps/tickbuddy/api/ticks/toggle')).toBe(true)
		expect(isTickbuddyApiUrl('/nextcloud/ocs/v2.php/apps/tickbuddy/api/tracks')).toBe(true)
	})

	it('leaves everything else alone', () => {
		expect(isTickbuddyApiUrl(undefined)).toBe(false)
		expect(isTickbuddyApiUrl('/remote.php/dav/files/u/')).toBe(false)
		expect(isTickbuddyApiUrl('/ocs/v2.php/apps/notes/api/v1/notes')).toBe(false)
		expect(isTickbuddyApiUrl('/ocs/v1.php/apps/tickbuddy/api/tracks')).toBe(false)
		expect(isTickbuddyApiUrl('/ocs/v2.php/apps/tickbuddy/apifoo')).toBe(false)
	})
})

describe('errorReason', () => {
	it('reads Tickbuddy\'s reason', () => {
		expect(errorReason(failed(423, { message: 'Exercise is archived, so nothing was changed', reason: 'archived' }))).toBe('archived')
		expect(errorReason(failed(404, { message: 'Track not found', reason: 'trackNotFound' }))).toBe('trackNotFound')
	})

	it('is null for answers without one', () => {
		// Nextcloud's own 404 while the app is disabled
		expect(errorReason(failed(404, []))).toBeNull()
		expect(errorReason(failed(400, { message: 'x', reason: '' }))).toBeNull()
		expect(errorReason(failed(400, { message: 'x', reason: 7 }))).toBeNull()
		expect(errorReason({ request: {} })).toBeNull()
		expect(errorReason(undefined)).toBeNull()
		expect(errorReason(new Error('Network Error'))).toBeNull()
	})
})

describe('httpStatus', () => {
	it('is the status of an answer', () => {
		expect(httpStatus(failed(423, {}))).toBe(423)
	})

	it('is null without an answer', () => {
		expect(httpStatus({ request: {} })).toBeNull()
		expect(httpStatus(undefined)).toBeNull()
		expect(httpStatus(new Error('Network Error'))).toBeNull()
	})
})
