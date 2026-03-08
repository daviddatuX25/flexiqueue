<script lang="ts">
	/** Custom node for client seat / waiting area. Per plan 2.3. */
	import type { NodeProps } from '@xyflow/svelte';

	let { data, id }: NodeProps = $props();
	const label = $derived((data as { label?: string } | undefined)?.label ?? 'Waiting');
</script>

<div
	class="rounded-lg border-2 border-surface-400 bg-surface-100 px-3 py-2 min-w-[80px] touch-target-h flex items-center justify-center text-xs font-medium text-surface-700 shadow-sm"
	data-id={id}
>
	{label}
</div>
