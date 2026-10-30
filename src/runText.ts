import type { TrackStats } from './types.ts'

/**
 * A track's current run in Tickcli's words (`runText`, output.go): "3-day streak",
 * "21-day break", "1-day streak, up to yesterday", or "No ticks yet".
 *
 * @param stats the track's entry in `GET /api/stats`, if there is one
 */
export function runText(stats: TrackStats | null | undefined): string {
	const current = stats?.current
	if (!current) return 'No ticks yet'
	if (!current.isStreak) return `${current.length}-day break`
	return stats.upToYesterday
		? `${current.length}-day streak, up to yesterday`
		: `${current.length}-day streak`
}
