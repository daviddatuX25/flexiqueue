<script lang="ts">
	/**
	 * Diagram v2: Dotted, bendable flow edge. Uses smooth step path; stroke is dashed.
	 * When data.waypoint exists, path goes source -> waypoint -> target.
	 * Double-click to enter bend mode; drag midpoint to bend.
	 */
	import { getContext } from 'svelte';
	import { Position, getSmoothStepPath, getStraightPath } from '@xyflow/system';
	import { BaseEdge, useSvelteFlow } from '@xyflow/svelte';
	import type { SmoothStepEdgeProps } from '@xyflow/svelte';

	let {
		id,
		data,
		sourceX,
		sourceY,
		targetX,
		targetY,
		sourcePosition,
		targetPosition,
		pathOptions,
		style = '',
		label,
		labelStyle,
		interactionWidth = 20,
	}: SmoothStepEdgeProps & { data?: { waypoint?: { x: number; y: number } } } = $props();

	const ctx = getContext<{
		bendingEdgeIdStore: { subscribe: (fn: (v: string | null) => void) => () => void };
		onWaypointUpdate: (id: string, wp: { x: number; y: number }) => void;
		readOnly?: boolean;
	}>('diagramFlow');
	const svelteFlow = useSvelteFlow();
	let bendingEdgeId = $state<string | null>(null);
	let dragging = $state(false);

	$effect(() => {
		const store = ctx?.bendingEdgeIdStore;
		if (!store) return;
		return store.subscribe((v) => {
			bendingEdgeId = v;
		});
	});

	const waypoint = $derived((data as { waypoint?: { x: number; y: number } } | undefined)?.waypoint);
	const wx = $derived(waypoint?.x ?? (sourceX + targetX) / 2);
	const wy = $derived(waypoint?.y ?? (sourceY + targetY) / 2);

	const path = $derived.by(() => {
		if (waypoint) {
			const [p1] = getStraightPath({ sourceX, sourceY, targetX: wx, targetY: wy });
			const [p2] = getStraightPath({ sourceX: wx, sourceY: wy, targetX, targetY });
			return p1 + ' ' + p2;
		}
		const [p] = getSmoothStepPath({
			sourceX,
			sourceY,
			targetX,
			targetY,
			sourcePosition: sourcePosition ?? Position.Bottom,
			targetPosition: targetPosition ?? Position.Top,
			borderRadius: pathOptions?.borderRadius,
			offset: pathOptions?.offset,
			stepPosition: pathOptions?.stepPosition,
		});
		return p;
	});
	const labelX = $derived((sourceX + targetX) / 2);
	const labelY = $derived((sourceY + targetY) / 2);
	const dottedStyle = $derived(`${style}; stroke-dasharray: 8 4;`.trim());
	const isBending = $derived(bendingEdgeId === id);
	const showHandle = $derived(isBending && !ctx?.readOnly);

	function handlePointerDown(e: PointerEvent) {
		if (!showHandle || !ctx) return;
		e.preventDefault();
		e.stopPropagation();
		dragging = true;
		(e.target as HTMLElement).setPointerCapture?.(e.pointerId);
	}

	function handlePointerMove(e: PointerEvent) {
		if (!dragging || !ctx) return;
		const pos = svelteFlow.screenToFlowPosition({ x: e.clientX, y: e.clientY });
		ctx.onWaypointUpdate(id, { x: pos.x, y: pos.y });
	}

	function handlePointerUp(e: PointerEvent) {
		if (!dragging) return;
		dragging = false;
		(e.target as HTMLElement).releasePointerCapture?.(e.pointerId);
	}
</script>

<g data-id={id}>
	<BaseEdge
		{id}
		path={path}
		{labelX}
		{labelY}
		{label}
		{labelStyle}
		style={dottedStyle}
		{interactionWidth}
	/>
	{#if showHandle}
		<circle
			cx={wx}
			cy={wy}
			r={8}
			fill="var(--color-primary-500)"
			stroke="white"
			stroke-width={2}
			class="nodrag nopan"
			role="button"
			tabindex="0"
			aria-label="Drag to bend edge"
			style="cursor: grab;"
			onpointerdown={handlePointerDown}
			onpointermove={handlePointerMove}
			onpointerup={handlePointerUp}
			onpointercancel={handlePointerUp}
			onpointerleave={handlePointerUp}
		/>
	{/if}
</g>
