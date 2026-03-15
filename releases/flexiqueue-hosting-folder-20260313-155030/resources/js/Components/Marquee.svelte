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
	let needsMarquee = $state(false);

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
	class="fq-marquee-wrapper {className}"
	bind:this={containerEl}
>
	{#if overflowOnly}
		<div class="fq-marquee-measure" aria-hidden="true" bind:this={measureEl}>{@render children()}</div>
	{/if}
	{#if overflowOnly && !needsMarquee}
		<span class="fq-marquee-static">{@render children()}</span>
	{:else}
		<span class="fq-marquee-track" style="animation-duration: {duration}s">
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
