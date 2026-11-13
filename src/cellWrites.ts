// Today and the Journal keep tick values and notes in maps by tickKey(), and
// both load and write them. Two rules keep what is on screen right:
// - one cell's writes go to the server one at a time, in the order they were
//   made, so the last answer is the cell's value (two quick taps on + would
//   otherwise answer in either order);
// - a load that started before a write leaves that cell alone when it
//   arrives, since what it read may be older than the write.

/**
 * The key of a track's day in the tick and note maps.
 *
 * @param trackId the track
 * @param date the day as YYYY-MM-DD
 */
export function tickKey(trackId: number, date: string): string {
	return `${trackId}|${date}`
}

export interface CellWrites {
	/** The point a load starts at, for changedSince() when it arrives */
	mark(): number
	/** Run a write to a cell once the cell's earlier writes have settled. Rejects as the write does. */
	run<T>(key: string, write: () => Promise<T>): Promise<T>
	/** The cells written since `mark`, including writes still waiting or under way */
	changedSince(mark: number): string[]
}

export function cellWrites(): CellWrites {
	let made = 0
	// When each cell was last written
	const madeAt = new Map<string, number>()
	// Each cell's last write, settled either way, while there is one
	const queues = new Map<string, Promise<void>>()

	return {
		mark: () => made,
		run<T>(key: string, write: () => Promise<T>): Promise<T> {
			madeAt.set(key, ++made)
			const result = (queues.get(key) ?? Promise.resolve()).then(write)
			const settled = result.then(() => undefined, () => undefined)
			queues.set(key, settled)
			settled.then(() => {
				if (queues.get(key) === settled) queues.delete(key)
			})
			return result
		},
		changedSince: mark => [...madeAt].filter(([, at]) => at > mark).map(([key]) => key),
	}
}

/**
 * `fresh`, from a load, with the given cells as they are in `current`
 * instead (there or not), because they were written after the load started.
 * Changes and returns `fresh`.
 *
 * @param fresh the load's cells
 * @param current the cells on screen
 * @param keys the cells written since the load started
 */
export function keepCells<T>(fresh: Record<string, T>, current: Record<string, T>, keys: string[]): Record<string, T> {
	for (const key of keys) {
		if (key in current) {
			fresh[key] = current[key]
		} else {
			delete fresh[key]
		}
	}
	return fresh
}

/**
 * A copy of `current` with every cell from `from` to `to` taken from `fresh`,
 * which holds that range: a cell missing there is removed, as storage is
 * sparse. Cells on other days are kept.
 *
 * @param current the cells on screen
 * @param fresh a load's cells for the range
 * @param from the range's first day as YYYY-MM-DD
 * @param to the range's last day as YYYY-MM-DD
 */
export function replaceDays<T>(current: Record<string, T>, fresh: Record<string, T>, from: string, to: string): Record<string, T> {
	const result: Record<string, T> = {}
	for (const [key, value] of Object.entries(current)) {
		const date = key.slice(key.indexOf('|') + 1)
		if (date < from || date > to) result[key] = value
	}
	return Object.assign(result, fresh)
}
