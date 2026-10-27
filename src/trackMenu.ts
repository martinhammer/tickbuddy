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
 * Where a dragged track goes when dropped at a gap between rows: gap `i` is
 * above row `i`, and the number of tracks is the gap after the last one. Once
 * the track is taken out, every row below it moves up one, so a downward drag
 * lands one index higher up than the gap's. Dropping just above or just below
 * the dragged row gives its own index.
 *
 * @param fromIndex the dragged track's index
 * @param gap the gap it was dropped at
 */
export function dropIndex(fromIndex: number, gap: number): number {
	return fromIndex < gap ? gap - 1 : gap
}

/**
 * The gap a drag over a row points at: above the row over its upper half,
 * below it over its lower half.
 *
 * @param index the row's index
 * @param clientY the pointer's position
 * @param rect the row's bounding box
 * @param rect.top the row's top edge
 * @param rect.height the row's height
 */
export function gapAt(index: number, clientY: number, rect: { top: number, height: number }): number {
	return clientY < rect.top + rect.height / 2 ? index : index + 1
}
