// Shading shared by the Analytics heatmap and the dashboard widget.

/** Alpha per level: index 0 is unused (empty days get EMPTY_FILL). */
export const LEVEL_ALPHA = [0, 0.25, 0.45, 0.68, 0.9]
export const EMPTY_FILL = 'var(--color-background-dark)'

/**
 * Booleans get a single shade at level 2 — full strength reads as too heavy
 * when every ticked day is identical. Counters are quantised into four levels
 * against that track's own maximum; small ranges map value→level directly so a
 * track that never exceeds 3 still uses distinct shades.
 *
 * @param value the day's tick value (0 when there is no tick)
 * @param isCounter whether the track is a counter
 * @param maxValue the track's highest tick value ever
 */
export function levelFor(value: number, isCounter: boolean, maxValue: number): number {
	if (value <= 0) return 0
	if (!isCounter) return 2
	if (maxValue <= 4) return Math.min(4, value)
	return Math.max(1, Math.min(4, Math.ceil((value / maxValue) * 4)))
}

export function valueText(value: number, isCounter: boolean): string {
	return value > 0 ? (isCounter ? String(value) : 'Ticked') : (isCounter ? '0' : 'Not ticked')
}

export function getPrimaryColor(): string {
	if (typeof document === 'undefined') return '#0082c9'
	return getComputedStyle(document.documentElement).getPropertyValue('--color-primary-element').trim() || '#0082c9'
}

export function hexToRgba(hex: string, alpha: number): string {
	const r = parseInt(hex.slice(1, 3), 16)
	const g = parseInt(hex.slice(3, 5), 16)
	const b = parseInt(hex.slice(5, 7), 16)
	return `rgba(${r}, ${g}, ${b}, ${alpha})`
}

export function fillFor(level: number, primaryHex: string): string {
	return level === 0 ? EMPTY_FILL : hexToRgba(primaryHex, LEVEL_ALPHA[level])
}
