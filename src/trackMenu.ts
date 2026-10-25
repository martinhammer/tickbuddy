/** One item of a track's ⋯ menu, the same on its row and in its sidebar. */
export interface TrackMenuItem {
	key: string
	label: string
	icon: string
	/** Shown in the error colour (Delete) */
	danger?: boolean
	run: () => void
}

/**
 * "Yes/No" or "Counter"; a type this web doesn't know by the server's name.
 *
 * @param type the track's type
 */
export function typeName(type: string): string {
	if (type === 'boolean') return 'Yes/No'
	if (type === 'counter') return 'Counter'
	return type
}
