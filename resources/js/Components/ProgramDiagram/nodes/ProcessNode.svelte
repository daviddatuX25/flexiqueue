<script lang="ts">
	/** Custom node for a process. Per plan 2.3. */
	import type { NodeProps } from '@xyflow/svelte';

	let { data, id }: NodeProps = $props();
	const label = $derived((data as { label?: string } | undefined)?.label ?? 'Process');
	const orphan = $derived((data as { orphan?: boolean } | undefined)?.orphan ?? false);
</script>

<div
	class="rounded-lg border-2 px-3 py-2 min-w-[100px] min-h-[48px] flex items-center justify-center text-sm font-medium shadow-sm {orphan
		? 'border-error-300 bg-error-50 text-error-700'
		: 'border-tertiary-300 bg-tertiary-50 text-surface-950'}"
	data-id={id}
>
	{label}
</div>
