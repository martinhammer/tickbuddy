import { createApp } from 'vue'
import DashboardWidget from './components/DashboardWidget.vue'

/**
 * Mount the widget into the element the dashboard hands us.
 *
 * @param el the empty element inside the panel's content area
 * @return a function that unmounts it again
 */
export function mountWidget(el: HTMLElement): () => void {
	// Fill the panel's fixed-height content area, so the rows scroll inside it
	// and the footer sits at the bottom. Where the dashboard makes that area
	// auto-height (one-column layout), the widget's own max-height caps it.
	el.style.height = '100%'
	const app = createApp(DashboardWidget)
	app.mount(el)
	return () => app.unmount()
}
