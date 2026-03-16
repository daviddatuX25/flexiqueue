<script lang="ts">
	import Marquee from './Marquee.svelte';

	let {
		category = 'Regular',
		badgeClass = 'preset-tonal text-surface-950',
		size = 'default',
	}: {
		category: string;
		badgeClass?: string;
		size?: 'default' | 'sm';
	} = $props();
</script>

<span
	class="badge category-badge {size === 'sm' ? 'badge-sm' : ''} {badgeClass}"
	class:text-sm={size === 'default'}
	class:font-semibold={size === 'default'}
>
	<Marquee overflowOnly={true} duration={10} gapEm={2.5} class="category-badge-marquee">
		{#snippet children()}{category}{/snippet}
	</Marquee>
</span>

<style>
	.category-badge {
		max-width: 7rem;
		overflow: hidden;
		display: inline-block;
		vertical-align: middle;
		flex-shrink: 0;
	}

	.category-badge-marquee {
		width: 100%;
	}
</style>
