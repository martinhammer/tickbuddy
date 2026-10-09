// Separate from vite.config.ts, which builds the app bundles: unit tests need
// none of the Nextcloud build plugins.
import { defineConfig } from 'vitest/config'

export default defineConfig({
	test: {
		include: ['src/**/*.test.ts'],
		// A timezone with DST, so the date walks are tested across a 23-hour day.
		env: { TZ: 'Europe/Berlin' },
	},
})
