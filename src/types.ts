export interface Track {
	id: number
	name: string
	type: string
	sortOrder: number
	private: boolean
	/** The archive date as YYYY-MM-DD, or null while active. */
	archivedAt: string | null
}

export interface Tick {
	id: number
	trackId: number
	date: string
	value: number
}
