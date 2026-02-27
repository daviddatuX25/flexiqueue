<script lang="ts">
	/** Custom node for staff. Shows name; optional initial. Per plan 2.3. */
	import type { NodeProps } from '@xyflow/svelte';

	let { data, id }: NodeProps = $props();
	const label = $derived((data as { label?: string } | undefined)?.label ?? 'Staff');
	const orphan = $derived((data as { orphan?: boolean } | undefined)?.orphan ?? false);
	const initial = $derived(
		(label as string).trim() ? (label as string).charAt(0).toUpperCase() : '?'
	);
</script>

<div
	class="rounded-full border-2 w-12 h-12 min-w-[48px] min-h-[48px] flex items-center justify-center text-sm font-bold shadow-sm {orphan
		? 'border-error-300 bg-error-50 text-error-700'
		: 'border-primary-300 bg-primary-50 text-primary-700'}"
	data-id={id}
	title={label}
>
	{initial}
</div>
