import { describe, expect, it } from 'vitest'
import { dropIndex } from './trackMenu.ts'

// The list after dropping the track at fromIndex above the row at toIndex
function drop(list: string[], fromIndex: number, toIndex: number): string[] {
	const result = [...list]
	const [moved] = result.splice(fromIndex, 1)
	result.splice(dropIndex(fromIndex, toIndex), 0, moved)
	return result
}

describe('dropIndex', () => {
	it('puts a track dragged down above the row it was dropped on', () => {
		expect(drop(['0', '1', '2'], 0, 2)).toEqual(['1', '0', '2'])
	})

	it('puts a track dragged up above the row it was dropped on', () => {
		expect(drop(['0', '1', '2'], 2, 0)).toEqual(['2', '0', '1'])
	})

	it('leaves the order alone when dropped on itself or the row below', () => {
		expect(dropIndex(1, 1)).toBe(1)
		expect(dropIndex(1, 2)).toBe(1)
	})
})
