<script lang="ts">
	/**
	 * Diagram v2: Animated directional flow edge.
	 * - Thick dotted line with arrow pointing toward the target.
	 * - Supports multiple bend midpoints and draggable endpoints on process box sides.
	 */
	import { getContext } from 'svelte';
	import { Position, getSmoothStepPath } from '@xyflow/system';
	import { useSvelteFlow } from '@xyflow/svelte';
	import type { SmoothStepEdgeProps } from '@xyflow/svelte';

	type Side = 'top' | 'right' | 'bottom' | 'left';
	type Waypoint = { x: number; y: number };

	let {
		id,
		sourceX,
		sourceY,
		targetX,
		targetY,
		sourcePosition,
		targetPosition,
		pathOptions,
		style = '',
		data,
		label,
		labelStyle,
		interactionWidth = 20,
		source,
		target,
	}: SmoothStepEdgeProps & {
		source?: string;
		target?: string;
		data?: {
			trackColor?: string;
			waypoint?: Waypoint;
			waypoints?: Waypoint[];
			sourceSide?: Side;
			targetSide?: Side;
			sourceOffset?: number;
			targetOffset?: number;
		};
	} = $props();

	const ctx = getContext<{
		bendingEdgeIdStore?: { subscribe: (fn: (v: string | null) => void) => () => void };
		onWaypointUpdate?: (id: string, waypoints: Waypoint[]) => void;
		onEndpointUpdate?: (
			id: string,
			payload: { sourceSide?: Side; sourceOffset?: number; targetSide?: Side; targetOffset?: number }
		) => void;
		readOnly?: boolean;
	}>('diagramFlow');

	const svelteFlow = useSvelteFlow();

	let bendingEdgeId = $state<string | null>(null);
	let waypoints = $state<Waypoint[]>([]);
	let draggingWaypointIndex = $state<number | null>(null);
	let selectedWaypointIndex = $state<number | null>(null);
	let draggingEndpoint = $state<'source' | 'target' | null>(null);

	const color = $derived((data as { trackColor?: string } | undefined)?.trackColor ?? 'var(--color-primary-500, #6366f1)');
	const isBending = $derived(bendingEdgeId === id);
	const showHandle = $derived(isBending && !ctx?.readOnly);

	$effect(() => {
		const store = ctx?.bendingEdgeIdStore;
		if (!store) return;
		return store.subscribe((value) => {
			bendingEdgeId = value;
		});
	});

	// Initialise waypoints from persisted edge data once; after the user edits they are pure local state.
	$effect(() => {
		if (waypoints.length) return;
		const d = data as { waypoints?: Waypoint[]; waypoint?: Waypoint } | undefined;
		if (d?.waypoints && Array.isArray(d.waypoints) && d.waypoints.length) {
			waypoints = d.waypoints.map((wp) => ({ x: wp.x, y: wp.y }));
		} else if (d?.waypoint) {
			waypoints = [{ x: d.waypoint.x, y: d.waypoint.y }];
		}
	});

	// While dragging a waypoint, listen on window so fast pointer movement never escapes the handle.
	$effect(() => {
		if (draggingWaypointIndex === null) return;

		function onMove(e: PointerEvent) {
			if (!ctx?.onWaypointUpdate) return;
			const pos = svelteFlow.screenToFlowPosition({ x: e.clientX, y: e.clientY });
			const next = [...waypoints];
			next[draggingWaypointIndex] = { x: pos.x, y: pos.y };
			waypoints = next;
			ctx.onWaypointUpdate(id, next);
		}

		function onUp() {
			draggingWaypointIndex = null;
		}

		window.addEventListener('pointermove', onMove);
		window.addEventListener('pointerup', onUp);
		window.addEventListener('pointercancel', onUp);

		return () => {
			window.removeEventListener('pointermove', onMove);
			window.removeEventListener('pointerup', onUp);
			window.removeEventListener('pointercancel', onUp);
		};
	});

	function getNodeBox(nodeId?: string): { x: number; y: number; width: number; height: number } | null {
		if (!nodeId) return null;
		const api: any = svelteFlow;
		const node = api?.getNode?.(nodeId);
		if (!node) return null;
		const abs = (node as any).positionAbsolute ?? (node as any).position;
		const width = (node as any).width ?? 0;
		const height = (node as any).height ?? 0;
		if (!abs || typeof abs.x !== 'number' || typeof abs.y !== 'number') return null;
		return { x: abs.x, y: abs.y, width, height };
	}

	function projectOnBox(
		box: { x: number; y: number; width: number; height: number },
		side: Side,
		offset: number
	): { x: number; y: number } {
		const clamped = Math.max(0, Math.min(1, offset));
		if (side === 'top') {
			return { x: box.x + box.width * clamped, y: box.y };
		}
		if (side === 'bottom') {
			return { x: box.x + box.width * clamped, y: box.y + box.height };
		}
		if (side === 'left') {
			return { x: box.x, y: box.y + box.height * clamped };
		}
		// right
		return { x: box.x + box.width, y: box.y + box.height * clamped };
	}

	function projectToNearestSide(
		box: { x: number; y: number; width: number; height: number },
		px: number,
		py: number
	): { side: Side; offset: number } {
		const leftDist = Math.abs(px - box.x);
		const rightDist = Math.abs(px - (box.x + box.width));
		const topDist = Math.abs(py - box.y);
		const bottomDist = Math.abs(py - (box.y + box.height));
		const min = Math.min(leftDist, rightDist, topDist, bottomDist);
		let side: Side;
		let offset: number;
		if (min === topDist) {
			side = 'top';
			const x = Math.max(box.x, Math.min(box.x + box.width, px));
			offset = (x - box.x) / box.width;
		} else if (min === bottomDist) {
			side = 'bottom';
			const x = Math.max(box.x, Math.min(box.x + box.width, px));
			offset = (x - box.x) / box.width;
		} else if (min === leftDist) {
			side = 'left';
			const y = Math.max(box.y, Math.min(box.y + box.height, py));
			offset = (y - box.y) / box.height;
		} else {
			side = 'right';
			const y = Math.max(box.y, Math.min(box.y + box.height, py));
			offset = (y - box.y) / box.height;
		}
		return { side, offset };
	}

	function getEndpointPoint(
		which: 'source' | 'target',
		fallback: { x: number; y: number }
	): { x: number; y: number } {
		const d = data as
			| {
					sourceSide?: Side;
					sourceOffset?: number;
					targetSide?: Side;
					targetOffset?: number;
			  }
			| undefined;
		const side = which === 'source' ? d?.sourceSide : d?.targetSide;
		const offset = which === 'source' ? d?.sourceOffset ?? 0.5 : d?.targetOffset ?? 0.5;
		const nodeId = which === 'source' ? source : target;
		if (!side) return fallback;
		const box = getNodeBox(nodeId);
		if (!box) return fallback;
		return projectOnBox(box, side, offset);
	}

	const sourcePoint = $derived(getEndpointPoint('source', { x: sourceX, y: sourceY }));
	const targetPoint = $derived(getEndpointPoint('target', { x: targetX, y: targetY }));

	const path = $derived.by(() => {
		// When no waypoints exist, fall back to a smooth step like the base edge.
		if (!waypoints.length) {
			const [p] = getSmoothStepPath({
				sourceX: sourcePoint.x,
				sourceY: sourcePoint.y,
				targetX: targetPoint.x,
				targetY: targetPoint.y,
				sourcePosition: sourcePosition ?? Position.Bottom,
				targetPosition: targetPosition ?? Position.Top,
				borderRadius: pathOptions?.borderRadius ?? 12,
				offset: pathOptions?.offset,
				stepPosition: pathOptions?.stepPosition,
			});
			return p;
		}
		// Polyline: source -> each waypoint -> target.
		const pts: Waypoint[] = [
			{ x: sourcePoint.x, y: sourcePoint.y },
			...waypoints,
			{ x: targetPoint.x, y: targetPoint.y },
		];
		let d = `M ${pts[0].x} ${pts[0].y}`;
		for (let i = 1; i < pts.length; i++) {
			d += ` L ${pts[i].x} ${pts[i].y}`;
		}
		return d;
	});

	const labelX = $derived((sourcePoint.x + targetPoint.x) / 2);
	const labelY = $derived((sourcePoint.y + targetPoint.y) / 2);

	function startWaypointDrag(index: number, e: PointerEvent) {
		e.preventDefault();
		e.stopPropagation();
		(e.currentTarget as HTMLElement)?.setPointerCapture?.(e.pointerId);
		draggingWaypointIndex = index;
		selectedWaypointIndex = index;
	}

	function handleWaypointPointerDown(index: number, e: PointerEvent) {
		if (!showHandle || !ctx?.onWaypointUpdate) return;
		startWaypointDrag(index, e);
	}

	function handleGroupPointerDown(e: PointerEvent) {
		if (!showHandle || !ctx?.onWaypointUpdate) return;
		if (!waypoints.length) return;
		const pos = svelteFlow.screenToFlowPosition({ x: e.clientX, y: e.clientY });
		let bestIndex = -1;
		let bestDistSq = Infinity;
		for (let i = 0; i < waypoints.length; i += 1) {
			const wp = waypoints[i];
			const dx = pos.x - wp.x;
			const dy = pos.y - wp.y;
			const d2 = dx * dx + dy * dy;
			if (d2 < bestDistSq) {
				bestDistSq = d2;
				bestIndex = i;
			}
		}
		const radius = 14;
		if (bestIndex === -1 || bestDistSq > radius * radius) return;
		startWaypointDrag(bestIndex, e);
	}

	function handleDoubleClick(e: MouseEvent) {
		if (!ctx?.onWaypointUpdate || ctx.readOnly) return;
		e.preventDefault();
		e.stopPropagation();
		const pos = svelteFlow.screenToFlowPosition({ x: e.clientX, y: e.clientY });
		if (!Number.isFinite(pos.x) || !Number.isFinite(pos.y)) return;
		const next = [...waypoints, { x: pos.x, y: pos.y }];
		waypoints = next;
		ctx.onWaypointUpdate(id, next);
	}

	function handleKeyDown(e: KeyboardEvent) {
		if (!isBending || selectedWaypointIndex === null) return;
		if (e.key !== 'Delete' && e.key !== 'Backspace') return;
		if (!ctx?.onWaypointUpdate) return;
		e.preventDefault();
		const idx = selectedWaypointIndex;
		const next = waypoints.filter((_, i) => i !== idx);
		waypoints = next;
		selectedWaypointIndex = null;
		ctx.onWaypointUpdate(id, next);
	}

	// Endpoint dragging: allow user to move the root/target along the sides of the associated process box.
	$effect(() => {
		if (!draggingEndpoint || !ctx?.onEndpointUpdate) return;

		function onMove(e: PointerEvent) {
			const pos = svelteFlow.screenToFlowPosition({ x: e.clientX, y: e.clientY });
			const nodeId = draggingEndpoint === 'source' ? source : target;
			const box = getNodeBox(nodeId);
			if (!box) return;
			const { side, offset } = projectToNearestSide(box, pos.x, pos.y);
			const d = (data as
				| {
						sourceSide?: Side;
						sourceOffset?: number;
						targetSide?: Side;
						targetOffset?: number;
				  }
				| undefined) ?? {};
			const payload =
				draggingEndpoint === 'source'
					? {
							sourceSide: side,
							sourceOffset: offset,
							targetSide: d.targetSide,
							targetOffset: d.targetOffset,
					  }
					: {
							sourceSide: d.sourceSide,
							sourceOffset: d.sourceOffset,
							targetSide: side,
							targetOffset: offset,
					  };
			ctx.onEndpointUpdate(id, payload);
		}

		function onUp() {
			draggingEndpoint = null;
		}

		window.addEventListener('pointermove', onMove);
		window.addEventListener('pointerup', onUp);
		window.addEventListener('pointercancel', onUp);

		return () => {
			window.removeEventListener('pointermove', onMove);
			window.removeEventListener('pointerup', onUp);
			window.removeEventListener('pointercancel', onUp);
		};
	});

	function startEndpointDrag(which: 'source' | 'target', e: PointerEvent) {
		if (!ctx?.onEndpointUpdate) return;
		e.preventDefault();
		e.stopPropagation();
		(e.currentTarget as HTMLElement)?.setPointerCapture?.(e.pointerId);
		draggingEndpoint = which;
	}
</script>

<svelte:window on:keydown={handleKeyDown} />

<g data-id={id} onpointerdown={handleGroupPointerDown} ondblclick={handleDoubleClick} role="presentation">
	<defs>
		<style>
			@keyframes flowDash {
				to {
					stroke-dashoffset: -15;
				}
			}
		</style>
		<marker
			id="fa-{id}"
			markerWidth="10"
			markerHeight="7"
			refX="9"
			refY="3.5"
			orient="auto"
			markerUnits="userSpaceOnUse"
		>
			<polygon points="0 0, 10 3.5, 0 7" fill={color} />
		</marker>
	</defs>

	<path
		d={path}
		fill="none"
		stroke={color}
		stroke-width="3"
		stroke-linecap="round"
		stroke-dasharray="10 5"
		style="animation: flowDash 0.7s linear infinite"
		marker-end="url(#fa-{id})"
	/>

	<!-- Wide transparent path for easier hover/selection; mark as nodrag/nopan so it never pans the canvas. -->
	<path
		d={path}
		fill="none"
		stroke="transparent"
		stroke-width={interactionWidth}
		class="nodrag nopan"
	/>

	{#if showHandle && waypoints.length}
		{#each waypoints as wp, index}
			<!-- Soft visual halo (non-interactive) -->
			<circle
				cx={wp.x}
				cy={wp.y}
				r={18}
				fill={color}
				fill-opacity="0.16"
				stroke={color}
				stroke-opacity="0.25"
				class="nodrag nopan"
				pointer-events="none"
			/>
			<!-- Actual interactive handle -->
			<circle
				cx={wp.x}
				cy={wp.y}
				r={10}
				fill={color}
				stroke="white"
				stroke-width={2}
				class="nodrag nopan"
				role="button"
				tabindex="0"
				aria-label="Drag to bend edge"
				style="cursor: {draggingWaypointIndex === index ? 'grabbing' : 'grab'};"
				onpointerdown={(e) => handleWaypointPointerDown(index, e)}
			/>
		{/each}
	{/if}

	{#if showHandle}
		<!-- Endpoint handles at the root/target of the edge, constrained to their process boxes. -->
		<circle
			cx={sourcePoint.x}
			cy={sourcePoint.y}
			r={6}
			fill="white"
			stroke={color}
			stroke-width={2}
			class="nodrag nopan"
			role="button"
			tabindex="0"
			aria-label="Drag source endpoint"
			style="cursor: {draggingEndpoint === 'source' ? 'grabbing' : 'grab'};"
			onpointerdown={(e) => startEndpointDrag('source', e)}
		/>
		<circle
			cx={targetPoint.x}
			cy={targetPoint.y}
			r={6}
			fill="white"
			stroke={color}
			stroke-width={2}
			class="nodrag nopan"
			role="button"
			tabindex="0"
			aria-label="Drag target endpoint"
			style="cursor: {draggingEndpoint === 'target' ? 'grabbing' : 'grab'};"
			onpointerdown={(e) => startEndpointDrag('target', e)}
		/>
	{/if}
</g>
