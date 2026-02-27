<script lang="ts">
	/**
	 * Diagram v2: Station as a bound group — table + staff capacity + client seaters below.
	 * Bigger chair icon for client capacity, bigger user avatar for staff (nameable on chest).
	 */
	import { Armchair, User } from 'lucide-svelte';
	import type { NodeProps } from '@xyflow/svelte';

	let { data, id }: NodeProps = $props();
	const label = $derived((data as { label?: string } | undefined)?.label ?? 'Station');
	const capacity = $derived((data as { capacity?: number } | undefined)?.capacity ?? 1);
	const clientCapacity = $derived((data as { clientCapacity?: number } | undefined)?.clientCapacity ?? 1);
	/** Optional staff names for nameable avatars; when absent use "Staff 1", "Staff 2", etc. */
	const staffNames = $derived((data as { staffNames?: Array<{ id: number; name: string }> } | undefined)?.staffNames ?? []);
</script>

<div
	class="rounded-lg border-2 border-primary-300 bg-primary-50/90 w-full h-full min-w-[220px] min-h-[220px] flex flex-col shadow-md overflow-hidden"
	data-id={id}
>
	<!-- Top zone: process boxes are pre-placed and dragged only within this dotted area -->
	<div class="flex-shrink-0 w-full h-12 border-b-2 border-dashed border-surface-400 bg-surface-100/50 rounded-t-lg" aria-label="Process boxes (top)"></div>
	<!-- Staff capacity row: bigger icons, nameable on chest -->
	<div class="flex items-center justify-center gap-2 py-2 px-2 bg-primary-100/80 border-b border-primary-200 text-surface-700 flex-shrink-0">
		{#each Array(Math.min(capacity, 6)) as _, i}
			<div class="flex flex-col items-center gap-0.5">
				<User size={28} class="shrink-0 text-surface-600" aria-hidden="true" />
				<span class="text-[10px] font-medium text-surface-700 truncate max-w-[48px] text-center" title={staffNames[i]?.name ?? `Staff ${i + 1}`}>
					{staffNames[i]?.name ?? `Staff ${i + 1}`}
				</span>
			</div>
		{/each}
		{#if capacity > 6}
			<span class="text-xs text-surface-600">+{capacity - 6}</span>
		{/if}
	</div>
	<!-- Table (main area) -->
	<div class="flex-1 flex items-center justify-center p-2">
		<div class="rounded border-2 border-surface-400 bg-surface-100 w-full max-w-[160px] h-12 flex items-center justify-center text-sm font-semibold text-surface-950">
			{label}
		</div>
	</div>
	<!-- Client seaters in line below: bigger chair icons -->
	<div class="flex items-center justify-center gap-1.5 py-2 px-2 border-t border-primary-200 bg-primary-100/60 flex-shrink-0">
		{#each Array(Math.min(clientCapacity, 8)) as _}
			<span title="Seat" class="inline-flex"><Armchair size={24} class="shrink-0 text-surface-600" aria-hidden="true" /></span>
		{/each}
		{#if clientCapacity > 8}
			<span class="text-xs text-surface-600">+{clientCapacity - 8}</span>
		{/if}
	</div>
	<!-- Bottom zone: process boxes are pre-placed and dragged only within this dotted area -->
	<div class="flex-shrink-0 w-full h-12 border-t-2 border-dashed border-surface-400 bg-surface-100/50 rounded-b-lg" aria-label="Process boxes (bottom)"></div>
</div>
