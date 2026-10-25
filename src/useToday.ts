import { onBeforeUnmount, onMounted, ref } from 'vue'
import { todayStr } from './dates.ts'

// Quiet reload on focus once the data is older than this.
const STALE_AFTER_MS = 5 * 60 * 1000

/**
 * The local date, kept current, for a screen that shows "today". Moves on one
 * second after local midnight, and is checked again when the tab gets focus or
 * becomes visible (timers are throttled in background tabs and paused during
 * sleep). `reload` is called quietly when the day has changed, or on focus when
 * the data is more than 5 minutes old; call `loaded()` after each load.
 *
 * @param reload loads the screen's data again, quietly
 * @param onDayChange called with the new day before the reload
 */
export function useToday(reload: () => void, onDayChange?: (today: string) => void) {
	const today = ref(todayStr())
	let lastLoadedAt = Date.now()
	let midnightTimer: ReturnType<typeof setTimeout> | undefined
	let mounted = false

	function armMidnightTimer(): void {
		clearTimeout(midnightTimer)
		const next = new Date()
		next.setHours(24, 0, 1, 0) // one second after the next local midnight
		midnightTimer = setTimeout(() => {
			if (!mounted) return
			checkDay()
			armMidnightTimer()
		}, next.getTime() - Date.now())
	}

	/** Move to a new day if it has changed; true when it did. */
	function checkDay(): boolean {
		const now = todayStr()
		if (now === today.value) return false
		today.value = now
		onDayChange?.(now)
		reload()
		return true
	}

	function onWake(): void {
		if (!mounted) return
		armMidnightTimer()
		if (!checkDay() && Date.now() - lastLoadedAt > STALE_AFTER_MS) {
			reload()
		}
	}

	function onVisibilityChange(): void {
		if (document.visibilityState === 'visible') onWake()
	}

	onMounted(() => {
		mounted = true
		armMidnightTimer()
		document.addEventListener('visibilitychange', onVisibilityChange)
		window.addEventListener('focus', onWake)
	})

	onBeforeUnmount(() => {
		mounted = false
		clearTimeout(midnightTimer)
		document.removeEventListener('visibilitychange', onVisibilityChange)
		window.removeEventListener('focus', onWake)
	})

	return {
		today,
		/** Mark the data as just loaded. */
		loaded: () => { lastLoadedAt = Date.now() },
	}
}
