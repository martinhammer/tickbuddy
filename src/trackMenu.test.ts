import { describe, expect, it } from 'vitest'
import { typeName } from './trackMenu.ts'

describe('typeName', () => {
	it('names every type', () => {
		expect(['boolean', 'counter', 'number', 'choice', 'duration'].map(typeName)).toEqual(['Yes/No', 'Counter', 'Number', 'Choice', 'duration'])
	})
})
