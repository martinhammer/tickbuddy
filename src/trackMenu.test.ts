import { describe, expect, it } from 'vitest'
import { dropIndex, gapAt } from './trackMenu.ts'

// The list after dropping the track at fromIndex at the gap (above row gap)
function drop(list: string[], fromIndex: number, gap: number): string[] {
	const result = [...list]
	const [moved] = result.splice(fromIndex, 1)
	result.splice(dropIndex(fromIndex, gap), 0, moved)
	return result
}

describe('dropIndex', () => {
	it('puts a track dragged down above the row it was dropped on', () => {
		expect(drop(['0', '1', '2'], 0, 2)).toEqual(['1', '0', '2'])
	})

	it('puts a track dragged up above the row it was dropped on', () => {
		expect(drop(['0', '1', '2'], 2, 0)).toEqual(['2', '0', '1'])
	})

	it('puts a track dragged to the gap after the last row at the end', () => {
		expect(drop(['0', '1', '2'], 0, 3)).toEqual(['1', '2', '0'])
	})

	it('leaves the order alone when dropped just above or just below itself', () => {
		expect(dropIndex(1, 1)).toBe(1)
		expect(dropIndex(1, 2)).toBe(1)
	})
})

describe('gapAt', () => {
	const rect = { top: 100, height: 40 }

	it('is above the row over its upper half', () => {
		expect(gapAt(2, 110, rect)).toBe(2)
	})

	it('is below the row over its lower half', () => {
		expect(gapAt(2, 120, rect)).toBe(3)
		expect(gapAt(2, 139, rect)).toBe(3)
	})
})
