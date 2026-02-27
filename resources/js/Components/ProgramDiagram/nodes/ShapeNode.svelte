<script lang="ts">
	/** Decoration node: rectangle with optional label. Resizable. Room name editable (Diagram v2). */
	import { NodeResizer, useSvelteFlow } from '@xyflow/svelte';
	import type { NodeProps } from '@xyflow/svelte';

	let { data, id }: NodeProps = $props();
	const label = $derived((data as { label?: string } | undefined)?.label ?? 'Room');
	const svelteFlow = useSvelteFlow();
	let editing = $state(false);
	let inputValue = $state('');

	function startEdit() {
		editing = true;
		inputValue = label;
	}
	function commitEdit() {
		editing = false;
		const v = String(inputValue).trim() || 'Room';
		svelteFlow.updateNodeData(id, { label: v });
	}
</script>

<NodeResizer minWidth={80} minHeight={40} />
<div
	class="rounded-lg border-2 border-dashed border-surface-400 bg-surface-50/80 px-3 py-2 w-full h-full min-w-[120px] min-h-[60px] flex items-center justify-center text-sm text-surface-700"
	data-id={id}
>
	{#if editing}
		<input
			type="text"
			class="min-w-0 w-full max-w-full bg-transparent border-none outline-none ring-0 p-0 text-center text-inherit"
			bind:value={inputValue}
			onblur={commitEdit}
			onkeydown={(e) => e.key === 'Enter' && (e.currentTarget?.blur(), commitEdit())}
		/>
	{:else}
		<button type="button" class="w-full truncate" onclick={startEdit} title="Click to edit room name">
			{label}
		</button>
	{/if}
</div>
