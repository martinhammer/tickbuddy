/** RFC 7240: ask Tickbuddy to refuse (423) rather than quietly ignore a write to a read-only track. */
export const PREFER_STRICT = 'handling=strict'

/**
 * Whether a request goes to Tickbuddy's own OCS API, the only place a 423 means "read-only".
 *
 * @param url the request's URL; `generateOcsUrl` gives an absolute one
 */
export function isTickbuddyApiUrl(url: string | undefined): boolean {
	return url?.includes('/ocs/v2.php/apps/tickbuddy/api/') ?? false
}

type Rejection = { response?: { status?: unknown, data?: { ocs?: { data?: { reason?: unknown } } } } }

/**
 * The HTTP status of a failed request, or null when there was no response (network down).
 *
 * @param e The rejection value from a failed request — never assumed to be an axios error
 */
export function httpStatus(e: unknown): number | null {
	const status = (e as Rejection | null | undefined)?.response?.status
	return typeof status === 'number' ? status : null
}

/**
 * Tickbuddy's machine-readable `reason` for a failed request (`trackNotFound`, `archived`, …),
 * or null when the answer has none: Nextcloud's own errors (a disabled app's 404 among them),
 * a proxy's, or no answer at all.
 *
 * @param e The rejection value from a failed request — never assumed to be an axios error
 */
export function errorReason(e: unknown): string | null {
	const reason = (e as Rejection | null | undefined)?.response?.data?.ocs?.data?.reason
	return typeof reason === 'string' && reason !== '' ? reason : null
}
