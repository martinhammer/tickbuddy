import { describe, expect, it } from 'vitest'
import { cellWrites, keepCells, replaceDays, tickKey } from './cellWrites.ts'

/** A promise and the functions that settle it */
function deferred<T>() {
	let resolve!: (value: T) => void
	let reject!: (reason: unknown) => void
	const promise = new Promise<T>((_resolve, _reject) => {
		resolve = _resolve
		reject = _reject
	})
	return { promise, resolve, reject }
}

/** Let every settled promise's handlers run */
const flush = () => new Promise(resolve => setTimeout(resolve, 0))

describe('cellWrites run', () => {
	it('starts a cell\'s write only once its earlier write has settled', async () => {
		const writes = cellWrites()
		const first = deferred<number>()
		const started: string[] = []
		const a = writes.run('1|2026-10-19', () => { started.push('first'); return first.promise })
		const b = writes.run('1|2026-10-19', () => { started.push('second'); return Promise.resolve(7) })
		await flush()
		expect(started).toEqual(['first'])

		first.resolve(6)
		expect(await a).toBe(6)
		expect(await b).toBe(7)
		expect(started).toEqual(['first', 'second'])
	})

	it('starts the next write after one that failed, and rejects only the failed one', async () => {
		const writes = cellWrites()
		const a = writes.run('1|2026-10-19', () => Promise.reject(new Error('offline')))
		const b = writes.run('1|2026-10-19', () => Promise.resolve(1))
		await expect(a).rejects.toThrow('offline')
		expect(await b).toBe(1)
	})

	it('does not hold up another cell', async () => {
		const writes = cellWrites()
		const slow = deferred<number>()
		writes.run('1|2026-10-19', () => slow.promise)
		expect(await writes.run('2|2026-10-19', () => Promise.resolve(3))).toBe(3)
		slow.resolve(0)
	})
})

describe('cellWrites changedSince', () => {
	it('lists the cells written after the mark, finished or not', async () => {
		const writes = cellWrites()
		await writes.run('1|2026-10-18', () => Promise.resolve(1))
		const mark = writes.mark()
		await writes.run('1|2026-10-19', () => Promise.resolve(1))
		writes.run('2|2026-10-19', () => new Promise(() => {}))
		expect(writes.changedSince(mark).sort()).toEqual(['1|2026-10-19', '2|2026-10-19'])
	})

	it('lists a cell written both before and after the mark', async () => {
		const writes = cellWrites()
		await writes.run('1|2026-10-19', () => Promise.resolve(1))
		const mark = writes.mark()
		expect(writes.changedSince(mark)).toEqual([])
		await writes.run('1|2026-10-19', () => Promise.resolve(2))
		expect(writes.changedSince(mark)).toEqual(['1|2026-10-19'])
	})
})

describe('keepCells', () => {
	it('keeps the written cells as they are now, there or not', () => {
		const fresh = { '1|a': 5, '2|a': 1, '3|a': 2 }
		const current = { '1|a': 6, '4|a': 1 }
		expect(keepCells(fresh, current, ['1|a', '2|a', '4|a'])).toEqual({ '1|a': 6, '3|a': 2, '4|a': 1 })
	})
})

describe('replaceDays', () => {
	it('replaces the range, removing cells it no longer has, and keeps other days', () => {
		const current = {
			[tickKey(1, '2026-10-17')]: 1,
			[tickKey(1, '2026-10-18')]: 2,
			[tickKey(2, '2026-10-19')]: 3,
			[tickKey(1, '2026-10-20')]: 4,
		}
		const fresh = { [tickKey(2, '2026-10-18')]: 9 }
		expect(replaceDays(current, fresh, '2026-10-18', '2026-10-19')).toEqual({
			[tickKey(1, '2026-10-17')]: 1,
			[tickKey(2, '2026-10-18')]: 9,
			[tickKey(1, '2026-10-20')]: 4,
		})
	})

	it('leaves current unchanged', () => {
		const current = { [tickKey(1, '2026-10-18')]: 'note' }
		replaceDays(current, {}, '2026-10-18', '2026-10-18')
		expect(current).toEqual({ [tickKey(1, '2026-10-18')]: 'note' })
	})
})
