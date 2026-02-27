<script lang="ts">
	/** Decoration node: plain text. Resizable. Per plan 2.4, 2.6. */
	import { NodeResizer, useSvelteFlow } from '@xyflow/svelte';
	import type { NodeProps } from '@xyflow/svelte';

	let { data, id }: NodeProps = $props();
	const text = $derived((data as { text?: string } | undefined)?.text ?? 'Text');
	const svelteFlow = useSvelteFlow();
	let editing = $state(false);
	let inputValue = $state('');

	function startEdit() {
		editing = true;
		inputValue = text;
	}
	function commitEdit() {
		editing = false;
		const v = String(inputValue).trim() || 'Text';
		svelteFlow.updateNodeData(id, { text: v });
	}
</script>

<NodeResizer minWidth={40} minHeight={24} />
<div
	class="rounded px-2 py-1 w-full h-full min-w-[60px] min-h-[28px] text-sm text-surface-800 bg-transparent border border-surface-300 flex items-center"
	data-id={id}
>
	{#if editing}
		<input
			type="text"
			class="min-w-0 w-full bg-transparent border-none outline-none ring-0 p-0 text-inherit"
			bind:value={inputValue}
			onblur={commitEdit}
			onkeydown={(e) => e.key === 'Enter' && (e.currentTarget?.blur(), commitEdit())}
		/>
	{:else}
		<button type="button" class="text-left w-full min-w-0 truncate" onclick={startEdit} title="Click to edit">
			{text}
		</button>
	{/if}
</div>
