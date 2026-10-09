// Loaded on every dashboard visit, whether or not the user has added the
// widget (WeekWidget::load() runs for all registered widgets), so keep this
// tiny: register the callback and only pull in the widget when it is called.
import './dashboard.css'

const WIDGET_ID = 'tickbuddy-week' // = WeekWidget::ID

declare global {
	interface Window {
		OCA?: {
			Dashboard?: {
				register(id: string, callback: (el: HTMLElement, context: { widget: unknown }) => void): void
			}
		}
	}
}

// Removing and re-adding the widget calls back again with a new element, so
// unmount the old instance to stop its timer and listeners.
let unmountPrevious: (() => void) | null = null

document.addEventListener('DOMContentLoaded', () => {
	window.OCA?.Dashboard?.register(WIDGET_ID, async (el: HTMLElement) => {
		const { mountWidget } = await import('./dashboardApp.ts')
		unmountPrevious?.()
		unmountPrevious = mountWidget(el)
	})
})
