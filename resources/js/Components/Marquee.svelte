<script lang="ts">
	import { tick } from 'svelte';
	import type { Snippet } from 'svelte';

	let {
		overflowOnly = false,
		duration = 10,
		gapEm = 2.5,
		class: className = '',
		children,
	}: {
		overflowOnly?: boolean;
		duration?: number;
		gapEm?: number;
		class?: string;
		children: Snippet;
	} = $props();

	let containerEl: HTMLDivElement | undefined = $state();
	let measureEl: HTMLDivElement | undefined = $state();
	let trackEl: HTMLSpanElement | undefined = $state();
	let needsMarquee = $state(false);

	/** Per ui-ux-tasks-checklist: interruptible/scrollable by touch. Pause animation and allow horizontal drag. */
	let paused = $state(false);
	let manualTranslateX = $state<number | null>(null);
	let startClientX = $state(0);
	let startTranslateX = $state(0);

	function getTranslateX(el: HTMLElement): number {
		const style = getComputedStyle(el);
		const transform = style.transform;
		if (!transform || transform === 'none') return 0;
		const m = transform.match(/matrix\(([^)]+)\)/);
		if (!m) return 0;
		const parts = m[1].split(',');
		return parts.length >= 6 ? parseFloat(parts[4]) : 0;
	}

	function onPointerDown(e: PointerEvent) {
		if (!trackEl) return;
		if (overflowOnly && !needsMarquee) return;
		e.preventDefault();
		trackEl.setPointerCapture(e.pointerId);
		startClientX = e.clientX;
		startTranslateX = getTranslateX(trackEl);
		paused = true;
		manualTranslateX = startTranslateX;
	}

	function onPointerMove(e: PointerEvent) {
		if (!paused || !trackEl || manualTranslateX === null) return;
		const dx = e.clientX - startClientX;
		const trackWidth = trackEl.offsetWidth;
		const maxNegative = trackWidth <= 0 ? 0 : -trackWidth / 2;
		manualTranslateX = Math.max(maxNegative, Math.min(0, startTranslateX - dx));
	}

	function onPointerUp(e: PointerEvent) {
		if (!trackEl) return;
		try {
			trackEl.releasePointerCapture(e.pointerId);
		} catch (_) {}
		paused = false;
		manualTranslateX = null;
	}

	function getTrackStyle(): string {
		if (!paused || manualTranslateX === null) return '';
		return `transform: translate3d(${manualTranslateX}px, 0, 0); animation-play-state: paused;`;
	}

	$effect(() => {
		if (!overflowOnly) return;
		const container = containerEl;
		const measure = measureEl;
		if (!container || !measure) return;
		const check = () => {
			needsMarquee = measure.scrollWidth > container.clientWidth;
		};
		tick().then(check);
		const ro = new ResizeObserver(() => check());
		ro.observe(container);
		ro.observe(measure);
		return () => ro.disconnect();
	});
</script>

<div
	class="fq-marquee-wrapper {overflowOnly ? 'fq-marquee-fill' : ''} {className}"
	bind:this={containerEl}
	role="region"
	aria-label="Scrollable text"
	onpointerdown={onPointerDown}
	onpointermove={onPointerMove}
	onpointerup={onPointerUp}
	onpointercancel={onPointerUp}
	onpointerleave={onPointerUp}
>
	{#if overflowOnly}
		<div class="fq-marquee-measure" aria-hidden="true" bind:this={measureEl}>{@render children()}</div>
	{/if}
	{#if overflowOnly && !needsMarquee}
		<span class="fq-marquee-static">{@render children()}</span>
	{:else}
		<span
			class="fq-marquee-track"
			bind:this={trackEl}
			style="animation-duration: {duration}s; {getTrackStyle()}"
		>
			<span class="fq-marquee-content">{@render children()}</span>
			<span class="fq-marquee-gap" style="width: {gapEm}em; min-width: {gapEm}em}" aria-hidden="true"></span>
			<span class="fq-marquee-content">{@render children()}</span>
			<span class="fq-marquee-gap" style="width: {gapEm}em; min-width: {gapEm}em}" aria-hidden="true"></span>
		</span>
	{/if}
</div>

<style>
	.fq-marquee-wrapper {
		overflow: hidden;
		min-width: 0;
		display: inline-block;
		vertical-align: middle;
		position: relative;
		touch-action: pan-y;
	}
	/* When overflowOnly: fill parent so container has a width and overflow detection works */
	.fq-marquee-wrapper.fq-marquee-fill {
		display: block;
		width: 100%;
	}

	.fq-marquee-measure {
		position: absolute;
		left: -9999px;
		top: 0;
		white-space: nowrap;
		pointer-events: none;
		visibility: hidden;
		font: inherit;
	}

	.fq-marquee-static {
		display: inline-block;
		white-space: nowrap;
	}

	.fq-marquee-content {
		display: inline-block;
		white-space: nowrap;
	}

	.fq-marquee-gap {
		display: inline-block;
		flex-shrink: 0;
	}
</style>
