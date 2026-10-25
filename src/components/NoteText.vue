<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

// A note as read: line breaks kept, clamped to three lines with "Show more"
// when it is longer (measured, as the dashboard widget measures cut-off names).

const props = defineProps<{
	text: string
}>()

const textEl = ref<HTMLElement | null>(null)
const expanded = ref(false)
const clamped = ref(false)
let resizeObserver: ResizeObserver | null = null

function measure(): void {
	const el = textEl.value
	if (!el || expanded.value) return
	clamped.value = el.scrollHeight > el.clientHeight + 1
}

watch(() => props.text, async () => {
	expanded.value = false
	await nextTick()
	measure()
})

onMounted(() => {
	measure()
	resizeObserver = new ResizeObserver(measure)
	if (textEl.value) resizeObserver.observe(textEl.value)
})

onBeforeUnmount(() => {
	resizeObserver?.disconnect()
})
</script>

<template>
	<div :class="$style.note">
		<!-- v-text: escaped like {{ }}, with no template whitespace for pre-line to show -->
		<div ref="textEl" :class="[$style.text, !expanded && $style.clamp]" v-text="text" />
		<button v-if="clamped || expanded"
			type="button"
			:class="$style.more"
			:aria-expanded="expanded"
			@click.stop="expanded = !expanded">
			{{ expanded ? 'Show less' : 'Show more' }}
		</button>
	</div>
</template>

<style module>
.note {
	min-width: 0;
}

.text {
	white-space: pre-line;
	overflow-wrap: anywhere;
}

.clamp {
	display: -webkit-box;
	-webkit-line-clamp: 3;
	line-clamp: 3;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

/* A link-style button: Nextcloud's global button styles would draw a box. */
.more {
	min-height: 0;
	margin: 0;
	padding: 0;
	border: none;
	background: none;
	color: var(--color-primary-element);
	font-size: inherit;
	font-weight: normal;
	cursor: pointer;
}

.more:hover,
.more:focus-visible {
	text-decoration: underline;
}
</style>
