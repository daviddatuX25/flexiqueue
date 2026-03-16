<script lang="ts">
	/** Decoration node: rectangle with optional label. Resizable. Room name editable; background color option; title at middle top. */
	import { NodeResizer, useSvelteFlow } from '@xyflow/svelte';
	import type { NodeProps } from '@xyflow/svelte';

	let { data, id, selected = false }: NodeProps = $props();
	const d = $derived((data as { label?: string; backgroundColor?: string } | undefined) ?? {});
	const label = $derived(d.label ?? 'Room');
	const backgroundColor = $derived(d.backgroundColor ?? 'rgba(248,250,252,0.9)');
	const svelteFlow = useSvelteFlow();
	let editing = $state(false);
	let inputValue = $state('');
	let showColorPicker = $state(false);

	const PRESET_COLORS = [
		'rgba(248,250,252,0.9)',
		'rgba(224,242,254,0.95)',
		'rgba(254,249,195,0.95)',
		'rgba(220,252,231,0.95)',
		'rgba(254,226,226,0.95)',
		'rgba(233,213,255,0.95)',
		'rgba(254,215,170,0.95)',
	];

	function startEdit() {
		editing = true;
		inputValue = label;
	}
	function commitEdit() {
		editing = false;
		const v = String(inputValue).trim() || 'Room';
		svelteFlow.updateNodeData(id, { label: v });
	}
	function setBackgroundColor(color: string) {
		svelteFlow.updateNodeData(id, { backgroundColor: color });
		showColorPicker = false;
	}

	let rootEl = $state<HTMLDivElement | null>(null);

	// When user clicks canvas or elsewhere, flow deselects node (selected = false); close editing so box hides
	$effect(() => {
		if (selected) return;
		if (!editing) return;
		const v = String(inputValue).trim() || 'Room';
		svelteFlow.updateNodeData(id, { label: v });
		editing = false;
		showColorPicker = false;
	});

	// Sync wrapper class so selection box is only shown when editing (styled in DiagramFlowContent)
	$effect(() => {
		const el = rootEl?.parentElement;
		if (!el?.classList?.contains('svelte-flow__node')) return;
		if (editing) el.classList.add('is-editing');
		else el.classList.remove('is-editing');
		return () => el.classList.remove('is-editing');
	});
</script>

{#if editing}
	<NodeResizer minWidth={80} minHeight={40} />
{/if}
<div
	bind:this={rootEl}
	class="rounded-lg border-2 border-dashed px-3 py-2 w-full h-full min-w-[120px] min-h-[60px] flex flex-col items-center text-sm text-surface-700 relative {editing ? 'border-surface-400' : 'border-transparent'}"
	data-id={id}
	style="background-color: {backgroundColor};"
>
	<!-- Title always at middle top of the box -->
	<div class="w-full flex justify-center items-center shrink-0 pt-0.5 min-h-[28px]">
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
	<!-- Spacer so title stays top -->
	<div class="flex-1 min-h-0" aria-hidden="true"></div>
	<!-- Background color picker trigger -->
	<div class="absolute bottom-1 right-1">
		<button
			type="button"
			class="w-5 h-5 rounded border border-surface-400 shadow-sm"
			style="background-color: {backgroundColor};"
			title="Change room background color"
			onclick={(e) => {
				e.stopPropagation();
				showColorPicker = !showColorPicker;
			}}
		></button>
		{#if showColorPicker}
			<div
				class="absolute bottom-full right-0 mb-1 p-2 rounded-lg bg-surface-50 border border-surface-300 shadow-lg flex flex-wrap gap-1 w-[120px]"
				role="listbox"
				aria-label="Room background color"
			>
				{#each PRESET_COLORS as color}
					<button
						type="button"
						class="w-6 h-6 rounded border-2 border-surface-300 hover:border-primary-500"
						style="background-color: {color};"
						title={color}
						onclick={(e) => {
							e.stopPropagation();
							setBackgroundColor(color);
						}}
					></button>
				{/each}
			</div>
		{/if}
	</div>
</div>
