import type { Track } from './types.ts'

/** What a Journal cell can ask of the Journal, which owns the data. */
export interface JournalActions {
	valueOf(trackId: number, date: string): number
	noteOf(trackId: number, date: string): string
	isLocked(date: string): boolean
	/** The native checkbox flipped itself; it is put back if the write fails. */
	setYesNo(track: Track, date: string, value: boolean, input: HTMLInputElement | null): void
	increment(track: Track, date: string, delta: number): void
	/** Stores a note ("" deletes it); resolves false when it wasn't saved */
	saveNote(track: Track, date: string, text: string): Promise<boolean>
	lockedClick(): void
	/** Opens Today on that day */
	openDay(date: string): void
	/** Opens the track's Analytics */
	openTrack(trackId: number): void
	/** The label for a day in a cell's name, e.g. "Mon 19 Oct" */
	dayLabel(date: string): string
}
