<script lang="ts">
	/** Custom node for a track. Per plan 2.3. */
	import type { NodeProps } from '@xyflow/svelte';

	let { data, id }: NodeProps = $props();
	const label = $derived((data as { label?: string } | undefined)?.label ?? 'Track');
	const orphan = $derived((data as { orphan?: boolean } | undefined)?.orphan ?? false);
</script>

<div
	class="rounded-lg border-2 px-3 py-2 min-w-[100px] touch-target-h flex items-center justify-center text-sm font-medium shadow-sm {orphan
		? 'border-error-300 bg-error-50 text-error-700'
		: 'border-secondary-300 bg-secondary-50 text-surface-950'}"
	data-id={id}
>
	{label}
</div>
