<script lang="ts">
	/** Decoration node: plain text. Resizable; font size scales when dragging resize handle. */
	import { NodeResizer, useSvelteFlow } from '@xyflow/svelte';
	import type { NodeProps } from '@xyflow/svelte';

	let { data, id, selected = false }: NodeProps = $props();
	const d = $derived((data as { text?: string; fontSize?: number } | undefined) ?? {});
	const text = $derived(d.text ?? 'Text');
	const fontSize = $derived(typeof d.fontSize === 'number' && d.fontSize > 0 ? d.fontSize : 14);
	const svelteFlow = useSvelteFlow();
	let editing = $state(false);
	let inputValue = $state('');
	let containerEl = $state<HTMLDivElement | null>(null);

	function startEdit() {
		editing = true;
		inputValue = text;
	}
	function commitEdit() {
		editing = false;
		const v = String(inputValue).trim() || 'Text';
		svelteFlow.updateNodeData(id, { text: v });
	}

	// When user clicks canvas or elsewhere, flow deselects node (selected = false); close editing so box hides
	$effect(() => {
		if (selected) return;
		if (!editing) return;
		const v = String(inputValue).trim() || 'Text';
		svelteFlow.updateNodeData(id, { text: v });
		editing = false;
	});

	// Sync wrapper class so selection box is only shown when editing (styled in DiagramFlowContent)
	$effect(() => {
		const el = containerEl?.parentElement;
		if (!el?.classList?.contains('svelte-flow__node')) return;
		if (editing) el.classList.add('is-editing');
		else el.classList.remove('is-editing');
		return () => el.classList.remove('is-editing');
	});

	// When resized (drag handle), update font size from height so text scales with the box
	$effect(() => {
		const el = containerEl;
		const currentFontSize = fontSize;
		if (!el) return;
		const ro = new ResizeObserver(() => {
			const h = el.getBoundingClientRect().height;
			const size = Math.max(12, Math.min(72, Math.round(h * 0.55)));
			if (Math.abs(size - currentFontSize) <= 2) return;
			svelteFlow.updateNodeData(id, { fontSize: size });
		});
		ro.observe(el);
		return () => ro.disconnect();
	});
</script>

{#if editing}
	<NodeResizer minWidth={40} minHeight={24} />
{/if}
<div
	bind:this={containerEl}
	class="rounded px-2 py-1 w-full h-full min-w-[60px] min-h-[28px] text-surface-800 bg-transparent flex items-center {editing ? 'border border-surface-300' : 'border border-transparent'}"
	data-id={id}
	style="font-size: {fontSize}px;"
>
	{#if editing}
		<input
			type="text"
			class="min-w-0 w-full bg-transparent border-none outline-none ring-0 p-0 text-inherit"
			style="font-size: {fontSize}px;"
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
