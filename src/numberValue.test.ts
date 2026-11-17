import { describe, expect, it } from 'vitest'
import { formatNumber, parseNumber, roundMilli, sameAsStored, separators, toMilli, toWire } from './numberValue.ts'

// Spaces in formatted numbers vary by language and ICU version (U+00A0, U+202F)
function plain(text: string): string {
	return text.replace(/[\u00a0\u202f]/g, ' ')
}

describe('roundMilli', () => {
	it('rounds half away from zero', () => {
		expect(roundMilli(10450, 1)).toBe(10500)
		expect(roundMilli(-10450, 1)).toBe(-10500)
		expect(roundMilli(2500, 0)).toBe(3000)
		expect(roundMilli(-2500, 0)).toBe(-3000)
		expect(roundMilli(10449, 1)).toBe(10400)
		expect(roundMilli(1235, 3)).toBe(1235)
	})

	it('never gives a negative zero', () => {
		expect(Object.is(roundMilli(-40, 1), 0)).toBe(true)
	})
})

describe('formatNumber', () => {
	it('uses the language\'s separators and the track\'s decimals', () => {
		expect(formatNumber(10.4, 1, 'en-GB')).toBe('10.4')
		expect(formatNumber(10.4, 1, 'nl-BE')).toBe('10,4')
		expect(formatNumber(10.4, 1, 'fr-BE')).toBe('10,4')
		expect(formatNumber(1204.5, 2, 'en-GB')).toBe('1,204.50')
		expect(formatNumber(1204.5, 2, 'nl-BE')).toBe('1.204,50')
		expect(plain(formatNumber(1204.5, 2, 'fr-BE'))).toBe('1 204,50')
		expect(formatNumber(1204.5, 2, 'de-CH')).toMatch(/^1['’]204\.50$/)
	})

	it('shows every day with the track\'s decimals', () => {
		expect(formatNumber(72, 1, 'en-GB')).toBe('72.0')
		expect(formatNumber(0, 2, 'en-GB')).toBe('0.00')
	})

	it('rounds stored values half away from zero, not the double', () => {
		expect(formatNumber(10.45, 1, 'en-GB')).toBe('10.5')
		expect(formatNumber(-10.45, 1, 'en-GB')).toBe('-10.5')
		expect(formatNumber(1.005, 2, 'en-GB')).toBe('1.01')
	})

	it('writes negative values', () => {
		expect(formatNumber(-1.5, 1, 'en-GB')).toBe('-1.5')
		expect(formatNumber(-1.5, 1, 'nl-BE')).toBe('-1,5')
	})
})

describe('parseNumber', () => {
	it('reads the language\'s own way of writing numbers', () => {
		expect(parseNumber('10.4', 'en-GB')).toBe(10.4)
		expect(parseNumber('10,4', 'nl-BE')).toBe(10.4)
		expect(parseNumber('1.204,50', 'nl-BE')).toBe(1204.5)
		expect(parseNumber('1,204.50', 'en-GB')).toBe(1204.5)
		expect(parseNumber('1 204,50', 'fr-BE')).toBe(1204.5)
		expect(parseNumber('1 204,50', 'fr-BE')).toBe(1204.5)
		expect(parseNumber('1’204.50', 'de-CH')).toBe(1204.5)
		expect(parseNumber('1\'204.50', 'de-CH')).toBe(1204.5)
		expect(parseNumber('72', 'en-GB')).toBe(72)
	})

	it('takes a point as the decimal separator where grouping isn\'t a point', () => {
		expect(parseNumber('10.4', 'fr-BE')).toBe(10.4)
		expect(parseNumber('10.4', 'de-CH')).toBe(10.4)
	})

	it('reads a point as grouping where grouping is one', () => {
		expect(parseNumber('10.4', 'nl-BE')).toBe('invalid')
		expect(parseNumber('10.400', 'nl-BE')).toBe(10400)
	})

	it('takes a minus or a minus sign', () => {
		expect(parseNumber('-3', 'en-GB')).toBe(-3)
		expect(parseNumber('−3', 'en-GB')).toBe(-3)
		expect(parseNumber('-1,5', 'nl-BE')).toBe(-1.5)
		expect(parseNumber('-0', 'en-GB')).toBe(0)
	})

	it('is null for empty text, which clears the day', () => {
		expect(parseNumber('', 'en-GB')).toBeNull()
		expect(parseNumber('   ', 'en-GB')).toBeNull()
	})

	it('refuses anything else', () => {
		for (const text of ['1,2,3', '10,4', '1,20', 'abc', '1e3', '+5', '.5', '5.', '1.2.3', '--1', '12,34.5']) {
			expect(parseNumber(text, 'en-GB'), text).toBe('invalid')
		}
		expect(parseNumber('1,2,3', 'nl-BE')).toBe('invalid')
		expect(parseNumber('10,4,5', 'fr-BE')).toBe('invalid')
	})
})

describe('separators', () => {
	it('reads them from Intl', () => {
		expect(separators('en-GB')).toEqual({ decimal: '.', group: ',', minus: '-' })
		expect(separators('nl-BE').decimal).toBe(',')
		expect(separators('nl-BE').group).toBe('.')
	})
})

describe('toWire', () => {
	it('writes a point, no grouping, rounded to the track\'s decimals', () => {
		expect(toWire(10.4, 1)).toBe('10.4')
		expect(toWire(10.45, 1)).toBe('10.5')
		expect(toWire(-1.5, 1)).toBe('-1.5')
		expect(toWire(72, 1)).toBe('72')
		expect(toWire(1204.5, 2)).toBe('1204.5')
		expect(toWire(0.001, 3)).toBe('0.001')
		expect(toWire(-0.04, 1)).toBe('0')
	})
})

describe('sameAsStored', () => {
	it('compares at the track\'s decimals', () => {
		expect(sameAsStored(10.4, 10.4, 1)).toBe(true)
		expect(sameAsStored(10.41, 10.4, 1)).toBe(true)
		// A day stored with more decimals than the track has now: retyping it changes it
		expect(sameAsStored(10.5, 10.45, 1)).toBe(false)
		expect(sameAsStored(null, null, 1)).toBe(true)
		expect(sameAsStored(0, null, 1)).toBe(false)
	})

	it('is exact within the limit', () => {
		expect(toMilli(2147483.647)).toBe(2147483647)
		expect(toMilli(-10.45)).toBe(-10450)
	})
})
