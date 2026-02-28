<script lang="ts">
	/**
	 * Diagram v2: Process handle on the edge of a station group. Dashed box with read-only label; connection point for flow edges.
	 * Label is fully visible via horizontal scroll (marquee-style) when long.
	 */
	import { getContext } from 'svelte';
	import { Handle, Position } from '@xyflow/svelte';
	import type { NodeProps } from '@xyflow/svelte';

	let { data, id }: NodeProps = $props();
	const label = $derived((data as { label?: string } | undefined)?.label ?? 'Process');
	const ctx = getContext<{
		onProcessHandleClick?: (nodeId: string) => void;
		readOnly?: boolean;
	}>('diagramFlow');
</script>

<button
	type="button"
	class="rounded border border-dashed border-surface-500 bg-surface-50 px-2 py-1 min-w-[80px] min-h-[28px] max-w-[200px] flex items-center justify-center text-xs font-medium text-surface-800 shadow-sm overflow-hidden"
	data-id={id}
	onclick={() => {
		if (!ctx?.readOnly) {
			ctx?.onProcessHandleClick?.(id);
		}
	}}
>
	<Handle type="target" position={Position.Top} isConnectable={false} />
	<Handle type="source" position={Position.Bottom} isConnectable={false} />
	<Handle type="target" position={Position.Left} isConnectable={false} />
	<Handle type="source" position={Position.Right} isConnectable={false} />
	<!-- Horizontally scrollable so entire label is visible (marquee-style) -->
	<div class="overflow-x-auto overflow-y-hidden w-full min-w-0" style="scrollbar-width: thin;">
		<span class="whitespace-nowrap inline-block pr-1" title={label}>&rarr; {label}</span>
	</div>
</button>
