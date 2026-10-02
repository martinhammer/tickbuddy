/**
 * The message to show for a failed OCS request.
 *
 * This app's controllers all *return* `DataResponse(['message' => …], 4xx)`, which
 * lands the reason in `ocs.data.message` and leaves `ocs.meta.message` as the empty
 * string — not absent. A thrown `OCSException` (core's convention, and what a future
 * controller here might use) is the other way round: the reason sits in
 * `ocs.meta.message` and `ocs.data` is `[]`. Read both, and join with `||` so the
 * empty `meta.message` falls through to the fallback instead of producing a blank
 * toast the way `meta.message ?? fallback` would.
 *
 * @param e The rejection value from a failed request — never assumed to be an axios error
 * @param fallback Text to show when the response carries no usable message
 */
export function ocsErrorMessage(e: unknown, fallback: string): string {
	const ocs = (e as { response?: { data?: { ocs?: { meta?: { message?: unknown }; data?: { message?: unknown } } } } })
		?.response?.data?.ocs
	const text = (value: unknown) => (typeof value === 'string' ? value.trim() : '')
	return text(ocs?.data?.message) || text(ocs?.meta?.message) || fallback
}
