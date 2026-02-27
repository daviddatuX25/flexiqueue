<script lang="ts">
	/**
	 * Diagram v2: Process handle on the edge of a station group. Dashed box with editable label; connection point for flow edges.
	 */
	import { Handle, Position, useSvelteFlow } from '@xyflow/svelte';
	import type { NodeProps } from '@xyflow/svelte';

	let { data, id }: NodeProps = $props();
	const label = $derived((data as { label?: string } | undefined)?.label ?? 'Process');
	const svelteFlow = useSvelteFlow();
	let editing = $state(false);
	let inputValue = $state('');

	function startEdit() {
		editing = true;
		inputValue = label;
	}
	function commitEdit() {
		editing = false;
		const v = String(inputValue).trim() || 'Process';
		svelteFlow.updateNodeData(id, { label: v });
	}
</script>

<div
	class="rounded border border-dashed border-surface-500 bg-surface-50 px-2 py-1 min-w-[80px] min-h-[28px] flex items-center justify-center text-xs font-medium text-surface-800 shadow-sm"
	data-id={id}
>
	<Handle type="target" position={Position.Top} />
	<Handle type="source" position={Position.Bottom} />
	<Handle type="target" position={Position.Left} />
	<Handle type="source" position={Position.Right} />
	{#if editing}
		<input
			type="text"
			class="min-w-0 w-20 bg-transparent border border-surface-400 rounded px-1 text-inherit text-xs"
			bind:value={inputValue}
			onblur={commitEdit}
			onkeydown={(e) => e.key === 'Enter' && (e.currentTarget?.blur(), commitEdit())}
		/>
	{:else}
		<button type="button" class="truncate max-w-[100px]" onclick={startEdit} title="Click to edit">
			&rarr; {label}
		</button>
	{/if}
</div>
