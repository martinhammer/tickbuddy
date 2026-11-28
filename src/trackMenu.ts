/** One item of a track's ⋯ menu on its row. */
export interface TrackMenuItem {
	key: string
	label: string
	icon: string
	/** Shown in the error colour (Delete) */
	danger?: boolean
	run: () => void
}

/**
 * "Yes/No", "Counter", "Number" or "Choice"; a type this web doesn't know by
 * the server's name.
 *
 * @param type the track's type
 */
export function typeName(type: string): string {
	if (type === 'boolean') return 'Yes/No'
	if (type === 'counter') return 'Counter'
	if (type === 'number') return 'Number'
	if (type === 'choice') return 'Choice'
	return type
}
