export interface Track {
	id: number
	name: string
	type: string
	sortOrder: number
	private: boolean
}

export interface Tick {
	id: number
	trackId: number
	date: string
	value: number
}
