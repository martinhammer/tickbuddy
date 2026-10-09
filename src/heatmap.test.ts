import { describe, expect, it } from 'vitest'
import { levelFor, valueText } from './heatmap.ts'

describe('levelFor', () => {
	it('uses level 2 for any ticked boolean day', () => {
		expect(levelFor(1, false, 1)).toBe(2)
		expect(levelFor(1, false, 0)).toBe(2)
	})

	it('maps small counter ranges directly', () => {
		expect(levelFor(1, true, 3)).toBe(1)
		expect(levelFor(2, true, 3)).toBe(2)
		expect(levelFor(3, true, 3)).toBe(3)
	})

	it('quantises larger counter ranges into four levels', () => {
		expect(levelFor(1, true, 10)).toBe(1)
		expect(levelFor(3, true, 10)).toBe(2)
		expect(levelFor(5, true, 10)).toBe(2)
		expect(levelFor(6, true, 10)).toBe(3)
		expect(levelFor(10, true, 10)).toBe(4)
	})

	it('is 0 for an empty day', () => {
		expect(levelFor(0, false, 1)).toBe(0)
		expect(levelFor(0, true, 10)).toBe(0)
	})
})

describe('valueText', () => {
	it('describes all four combinations', () => {
		expect(valueText(1, false)).toBe('Ticked')
		expect(valueText(0, false)).toBe('Not ticked')
		expect(valueText(3, true)).toBe('3')
		expect(valueText(0, true)).toBe('0')
	})
})
