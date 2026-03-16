<script lang="ts">
	/** Decoration node: image from data.url; placeholder if missing. Resizable. Per plan 2.5, 2.6. */
	import { NodeResizer } from '@xyflow/svelte';
	import type { NodeProps } from '@xyflow/svelte';

	let { data, id }: NodeProps = $props();
	const url = $derived((data as { url?: string } | undefined)?.url ?? '');
	const hasUrl = $derived(Boolean(url));
</script>

<NodeResizer minWidth={60} minHeight={40} />
<div class="w-full h-full min-w-[80px] min-h-[60px] overflow-hidden rounded border border-surface-300 bg-surface-100" data-id={id}>
	{#if hasUrl}
		<img src={url} alt="" class="w-full h-full object-cover" />
	{:else}
		<div class="w-full h-full min-h-[60px] flex items-center justify-center text-xs text-surface-500">
			Image
		</div>
	{/if}
</div>
