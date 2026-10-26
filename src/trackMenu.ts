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
 * "Yes/No" or "Counter"; a type this web doesn't know by the server's name.
 *
 * @param type the track's type
 */
export function typeName(type: string): string {
	if (type === 'boolean') return 'Yes/No'
	if (type === 'counter') return 'Counter'
	return type
}

/**
 * Where a dragged track goes when dropped on a row. The line drawn on that row
 * means "insert above it", and once the track is taken out, every row below it
 * moves up one, so a downward drag lands one index higher up than the row's.
 *
 * @param fromIndex the dragged track's index
 * @param toIndex the index of the row it was dropped on
 */
export function dropIndex(fromIndex: number, toIndex: number): number {
	return fromIndex < toIndex ? toIndex - 1 : toIndex
}
