<script lang="ts">
	/**
	 * Inner diagram content: uses Svelte Flow hooks for load/save and drop. Per plan 1.5, 1.6.
	 */
	import { setContext } from 'svelte';
	import { tick } from 'svelte';
	import { get, writable } from 'svelte/store';
	import { SvelteFlow, Background, Controls, Panel } from '@xyflow/svelte';
	import type { Node } from '@xyflow/svelte';
	import { useNodes, useEdges, useViewport, useSvelteFlow } from '@xyflow/svelte';
	import { toast } from '../../stores/toastStore.js';
	import StationNode from './nodes/StationNode.svelte';
	import TrackNode from './nodes/TrackNode.svelte';
	import ProcessNode from './nodes/ProcessNode.svelte';
	import StaffNode from './nodes/StaffNode.svelte';
	import ClientSeatNode from './nodes/ClientSeatNode.svelte';
	import ShapeNode from './nodes/ShapeNode.svelte';
	import TextNode from './nodes/TextNode.svelte';
	import ImageNode from './nodes/ImageNode.svelte';
	import StationGroupNode from './nodes/StationGroupNode.svelte';
	import ProcessHandleNode from './nodes/ProcessHandleNode.svelte';
	import DottedFlowEdge from './edges/DottedFlowEdge.svelte';

	interface LayoutShape {
		viewport?: { x?: number; y?: number; zoom?: number };
		nodes: Array<Record<string, unknown>>;
		edges?: Array<Record<string, unknown>>;
	}

	interface TrackWithSteps {
		id: number;
		name: string;
		steps?: Array<{ station_id: number; process_id: number; step_order: number }>;
	}
	interface StationWithProcesses {
		id: number;
		name: string;
		process_ids?: number[];
		capacity?: number;
		client_capacity?: number;
	}

	interface EntityLookups {
		stations: StationWithProcesses[];
		tracks: TrackWithSteps[];
		processes: Array<{ id: number; name: string }>;
		staffList: Array<{ id: number; name: string }>;
	}
	let {
		layoutFromApi = null as LayoutShape | null,
		entityLookups = { stations: [], tracks: [], processes: [], staffList: [] } as EntityLookups,
		programId = 0,
		readOnly = false,
		tracks = [] as TrackWithSteps[],
		selectedTrackId = null as number | null,
		onTrackSelect = (_id: number | null) => {},
		stations = [] as StationWithProcesses[],
		processes = [] as Array<{ id: number; name: string }>,
	}: {
		layoutFromApi?: LayoutShape | null;
		entityLookups?: EntityLookups;
		programId?: number;
		readOnly?: boolean;
		tracks?: TrackWithSteps[];
		selectedTrackId?: number | null;
		onTrackSelect?: (id: number | null) => void;
		stations?: StationWithProcesses[];
		processes?: Array<{ id: number; name: string }>;
	} = $props();

	/** Sanitize layout for orphan entities (per plan 3.3). Only used when loading layout. */
	const initialLayout = $derived.by(() => {
		const layout = layoutFromApi;
		if (!layout || !Array.isArray(layout.nodes)) return layout;
		const { stations: s, tracks: t, processes: p, staffList: staff } = entityLookups;
		const stationIds = new Set(s.map((x) => x.id));
		const trackIds = new Set(t.map((x) => x.id));
		const processIds = new Set(p.map((x) => x.id));
		const staffIds = new Set(staff.map((x) => x.id));
		const entityTypes = ['station', 'track', 'process', 'staff', 'station_group'];
		const nodes = layout.nodes.map((node) => {
			if (typeof node !== 'object' || node === null) return node;
			const rec = node as Record<string, unknown>;
			const type = rec.type as string | undefined;
			const data = (rec.data as Record<string, unknown>) ?? {};
			const entityId = (rec.entityId as number | undefined) ?? (data.entityId as number | undefined) ?? (data.stationId as number | undefined);
			if (!entityTypes.includes(type ?? '') || entityId == null) return node;
			const valid =
				(type === 'station' && stationIds.has(entityId)) ||
				(type === 'station_group' && stationIds.has(entityId)) ||
				(type === 'track' && trackIds.has(entityId)) ||
				(type === 'process' && processIds.has(entityId)) ||
				(type === 'staff' && staffIds.has(entityId));
			if (valid) return node;
			return { ...node, data: { ...data, label: 'Unknown (removed)', orphan: true } };
		});
		return { ...layout, nodes };
	});

	const nodeTypes = {
		station: StationNode,
		track: TrackNode,
		process: ProcessNode,
		staff: StaffNode,
		client_seat: ClientSeatNode,
		shape: ShapeNode,
		text: TextNode,
		image: ImageNode,
		station_group: StationGroupNode,
		process_handle: ProcessHandleNode,
	};
	const edgeTypes = {
		dottedFlow: DottedFlowEdge,
	};

	const { current: nodesCurrent, set: setNodes, update: updateNodes } = useNodes();
	const { current: edgesCurrent, set: setEdges } = useEdges();
	const { current: viewportCurrent, set: setViewport } = useViewport();
	const svelteFlow = useSvelteFlow();

	let saving = $state(false);
	/** Edge id that is in bend mode (double-clicked, shows draggable midpoint). */
	const bendingEdgeIdStore = writable<string | null>(null);
	/** Persisted waypoints for edges (survives derivation). */
	let edgeWaypoints = $state<Map<string, { x: number; y: number }>>(new Map());

	/** Undo/redo history: use stores so Svelte does not clone (avoids DataCloneError on assign). */
	type HistoryEntryPlain = { nodes: unknown[]; viewport: { x: number; y: number; zoom: number }; edgeWaypoints: Array<[string, { x: number; y: number }]> };
	const historyStore = writable<HistoryEntryPlain[]>([]);
	const historyIndexStore = writable(-1);
	const MAX_HISTORY = 50;
	let isRestoring = $state(false);

	function serializeState(): HistoryEntryPlain {
		const { nodes, viewport } = svelteFlow.toObject();
		return {
			nodes: JSON.parse(JSON.stringify(nodes ?? [])),
			viewport: viewport && typeof viewport.x === 'number' ? { x: viewport.x, y: viewport.y ?? 0, zoom: viewport.zoom ?? 1 } : { x: 0, y: 0, zoom: 1 },
			edgeWaypoints: [...edgeWaypoints.entries()],
		};
	}
	function entryToWaypointsMap(entry: HistoryEntryPlain): Map<string, { x: number; y: number }> {
		return new Map(entry.edgeWaypoints);
	}
	function pushHistory() {
		if (readOnly || isRestoring) return;
		queueMicrotask(() => {
			let snap: HistoryEntryPlain;
			try {
				snap = serializeState();
			} catch {
				return;
			}
			const h = get(historyStore);
			const idx = get(historyIndexStore);
			const next = idx + 1;
			let newHistory = next < h.length ? h.slice(0, next) : [...h];
			newHistory = [...newHistory, snap];
			if (newHistory.length > MAX_HISTORY) newHistory = newHistory.slice(-MAX_HISTORY);
			historyStore.set(newHistory);
			historyIndexStore.set(newHistory.length - 1);
		});
	}
	function undo() {
		if ($historyIndexStore <= 0) return;
		isRestoring = true;
		historyIndexStore.update((i) => i - 1);
		const e = get(historyStore)[get(historyIndexStore)];
		if (e) {
			setNodes(e.nodes as Parameters<typeof setNodes>[0]);
			setViewport(e.viewport);
			edgeWaypoints = entryToWaypointsMap(e);
		}
		isRestoring = false;
	}
	function redo() {
		if ($historyIndexStore >= $historyStore.length - 1) return;
		isRestoring = true;
		historyIndexStore.update((i) => i + 1);
		const e = get(historyStore)[get(historyIndexStore)];
		if (e) {
			setNodes(e.nodes as Parameters<typeof setNodes>[0]);
			setViewport(e.viewport);
			edgeWaypoints = entryToWaypointsMap(e);
		}
		isRestoring = false;
	}
	const canUndo = $derived($historyIndexStore > 0);
	const canRedo = $derived($historyIndexStore < $historyStore.length - 1 && $historyStore.length > 0);

	setContext('diagramFlow', {
		bendingEdgeIdStore,
		onWaypointUpdate(id: string, waypoint: { x: number; y: number }) {
			edgeWaypoints = new Map(edgeWaypoints).set(id, waypoint);
			svelteFlow.updateEdge(id, (e) => ({ ...e, data: { ...(e.data ?? {}), waypoint } }));
			pushHistory();
		},
		get readOnly() {
			return readOnly;
		},
		pushHistory,
	});
	let imageUploading = $state(false);
	let imageInputEl: HTMLInputElement | undefined = $state();

	/** Tracks last loaded layout so we only reset when layoutFromApi actually changes (not when entityLookups/staffList loads). */
	let lastLoadedLayoutRef = $state<LayoutShape | null | undefined>(undefined);
	$effect(() => {
		const layout = layoutFromApi;
		if (!layout || layout === lastLoadedLayoutRef) return;
		lastLoadedLayoutRef = layout;
		const sanitized = initialLayout ?? layout;
		const nodes = Array.isArray(sanitized.nodes) ? sanitized.nodes : [];
		const edges = Array.isArray(sanitized.edges) ? sanitized.edges : [];
		const vp = sanitized.viewport ?? layout.viewport;
		if (Array.isArray(edges) && edges.length > 0) {
			const map = new Map<string, { x: number; y: number }>();
			for (const e of edges) {
				const ed = e as { id?: string; data?: { waypoint?: { x: number; y: number } } };
				if (ed.id && ed.data?.waypoint) map.set(ed.id, ed.data.waypoint);
			}
			edgeWaypoints = map;
		} else {
			edgeWaypoints = new Map();
		}
		isRestoring = true;
		setNodes(nodes as Parameters<typeof setNodes>[0]);
		setEdges(edges as Parameters<typeof setEdges>[0]);
		if (vp && typeof vp.x === 'number' && typeof vp.y === 'number' && typeof vp.zoom === 'number') {
			setViewport({ x: vp.x, y: vp.y, zoom: vp.zoom });
		}
		isRestoring = false;
		queueMicrotask(async () => {
			await tick();
			const snap = serializeState();
			historyStore.set([snap]);
			historyIndexStore.set(0);
		});
	});

	/** When no saved layout, initialize history with empty snapshot so undo/redo work after first user action. */
	$effect(() => {
		if (layoutFromApi != null) return;
		if ($historyStore.length > 0) return;
		queueMicrotask(async () => {
			await tick();
			const snap = serializeState();
			historyStore.set([snap]);
			historyIndexStore.set(0);
		});
	});

	/** Diagram v2: when a track is selected, derive flow edges from track steps and process handle nodes. Merge waypoints from edgeWaypoints and current edges. */
	$effect(() => {
		if (selectedTrackId == null) {
			setEdges([]);
			return;
		}
		const track = tracks.find((t) => t.id === selectedTrackId);
		const steps = track?.steps;
		if (!steps || steps.length < 2) {
			setEdges([]);
			return;
		}
		const nodes = nodesCurrent as Array<{ id: string; type?: string; data?: Record<string, unknown> }>;
		const handleMap = new Map<string, string>();
		for (const n of nodes) {
			if (n.type === 'process_handle' && n.data) {
				const sid = n.data.stationId as number | undefined;
				const pid = n.data.processId as number | undefined;
				if (sid != null && pid != null) handleMap.set(`${sid},${pid}`, n.id);
			}
		}
		const sortedSteps = [...steps].sort(
			(a, b) => ((a as { step_order?: number }).step_order ?? 0) - ((b as { step_order?: number }).step_order ?? 0)
		);
		const baseEdges: Array<{ id: string; source: string; target: string; type: string; data?: { waypoint?: { x: number; y: number } } }> = [];
		for (let i = 0; i < sortedSteps.length - 1; i++) {
			const a = sortedSteps[i] as { station_id: number; process_id: number; id?: number };
			const b = sortedSteps[i + 1] as { station_id: number; process_id: number; id?: number };
			const sourceId = handleMap.get(`${a.station_id},${a.process_id}`);
			const targetId = handleMap.get(`${b.station_id},${b.process_id}`);
			if (sourceId && targetId) {
				const edgeId = `dotted-${a.station_id}-${a.process_id}-${b.station_id}-${b.process_id}-${i}`;
				const wp = edgeWaypoints.get(edgeId) ?? (edgesCurrent as Array<{ id: string; data?: { waypoint?: { x: number; y: number } } }>).find((e) => e.id === edgeId)?.data?.waypoint;
				baseEdges.push({
					id: edgeId,
					source: sourceId,
					target: targetId,
					type: 'dottedFlow',
					...(wp ? { data: { waypoint: wp } } : {}),
				});
			}
		}
		setEdges(baseEdges as Parameters<typeof setEdges>[0]);
	});

	function handleDrop(e: DragEvent) {
		e.preventDefault();
		if (readOnly) return;
		const raw = e.dataTransfer?.getData('application/json');
		if (!raw) return;
		try {
			const payload = JSON.parse(raw) as { type?: string; entityId?: number; label?: string; text?: string; url?: string };
			const type = payload.type ?? 'station';
			const allowed = ['station', 'track', 'process', 'staff', 'client_seat', 'shape', 'text', 'image'];
			if (!allowed.includes(type)) return;
			const position = svelteFlow.screenToFlowPosition({ x: e.clientX, y: e.clientY });

			if (type === 'station' && payload.entityId != null && stations.length > 0) {
				const station = stations.find((s) => s.id === payload.entityId);
				if (!station) return;
				const processIds = station.process_ids ?? [];
				const parentId = crypto.randomUUID();
				const parentWidth = 280;
				/** Top and bottom dotted zones for process boxes (match StationGroupNode h-12 = 48px). */
				const TOP_ZONE_HEIGHT = 48;
				const BOTTOM_ZONE_HEIGHT = 48;
				const parentHeight = 280;
				const parentNode = {
					id: parentId,
					type: 'station_group',
					position,
					width: parentWidth,
					height: parentHeight,
					data: {
						label: station.name,
						stationId: station.id,
						capacity: station.capacity ?? 1,
						clientCapacity: station.client_capacity ?? 1,
					},
					entityId: station.id,
				};
				const processHandleNodes: Array<Record<string, unknown>> = [];
				const n = processIds.length;
				const handleWidth = 80;
				const handleHeight = 28;
				/** Process boxes pre-placed only in top zone (above staff) or bottom zone (below clients); draggable within that zone. */
				const sides = ['top', 'bottom'] as const;
				const topZoneYMax = TOP_ZONE_HEIGHT - handleHeight;
				const bottomZoneYMin = parentHeight - BOTTOM_ZONE_HEIGHT - handleHeight;
				const bottomZoneYMax = parentHeight - handleHeight;
				for (let i = 0; i < n; i++) {
					const processId = processIds[i];
					const proc = processes.find((p) => p.id === processId);
					const label = proc?.name ?? `Process ${processId}`;
					const side = sides[i % 2];
					const indexOnSide = Math.floor(i / 2);
					const countOnSide = Math.ceil((n - (i % 2)) / 2);
					let x: number;
					let y: number;
					if (side === 'top') {
						x = countOnSide <= 1 ? parentWidth / 2 - handleWidth / 2 : (parentWidth / (countOnSide + 1)) * (indexOnSide + 1) - handleWidth / 2;
						y = Math.min(4, topZoneYMax);
					} else {
						x = countOnSide <= 1 ? parentWidth / 2 - handleWidth / 2 : (parentWidth / (countOnSide + 1)) * (indexOnSide + 1) - handleWidth / 2;
						y = Math.max(bottomZoneYMin, bottomZoneYMax - 4);
					}
					x = Math.max(0, Math.min(parentWidth - handleWidth, x));
					y = side === 'top' ? Math.max(0, Math.min(topZoneYMax, y)) : Math.max(bottomZoneYMin, Math.min(bottomZoneYMax, y));
					processHandleNodes.push({
						id: crypto.randomUUID(),
						type: 'process_handle',
						position: { x, y },
						parentId,
						extent: 'parent',
						data: { stationId: station.id, processId, label, side, indexOnSide },
					});
				}
				updateNodes((nodes) => [...nodes, parentNode, ...processHandleNodes] as Node[]);
				pushHistory();
				return;
			}

			let data: Record<string, unknown>;
			if (type === 'text') {
				data = { text: payload.text ?? 'Text' };
			} else if (type === 'shape') {
				data = { shape: 'rectangle', label: payload.label ?? 'Room' };
			} else if (type === 'image') {
				data = { url: payload.url ?? '' };
			} else {
				const label = payload.label ?? (type === 'client_seat' ? 'Waiting' : type);
				data = { label };
				if (payload.entityId != null) data.entityId = payload.entityId;
			}
			const newNode = {
				id: crypto.randomUUID(),
				type,
				position,
				data,
			};
			updateNodes((nodes) => [...nodes, newNode]);
			pushHistory();
		} catch {
			// ignore invalid JSON
		}
	}

	function handleDragOver(e: DragEvent) {
		e.preventDefault();
		if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
	}

	async function handleSave() {
		if (!programId) return;
		saving = true;
		try {
			await tick();
			const { nodes, edges, viewport } = svelteFlow.toObject();
			/** Sanitize nodes: backend requires id, type, position{x,y}; station_group needs entityId; process_handle needs data.stationId, data.processId. */
			const sanitizedNodes = ((nodes ?? []) as Array<Record<string, unknown>>).map((n) => {
				const pos = (n.position as { x?: number; y?: number }) ?? {};
				let data = (n.data != null && typeof n.data === 'object' ? n.data : {}) as Record<string, unknown>;
				if (n.type === 'process_handle') {
					const sid = data.stationId;
					const pid = data.processId;
					const numSid = typeof sid === 'number' && !isNaN(sid) ? sid : parseInt(String(sid ?? ''), 10);
					const numPid = typeof pid === 'number' && !isNaN(pid) ? pid : parseInt(String(pid ?? ''), 10);
					if (!isNaN(numSid) && !isNaN(numPid)) data = { ...data, stationId: numSid, processId: numPid };
				}
				const obj: Record<string, unknown> = {
					id: String(n.id ?? ''),
					type: String(n.type ?? ''),
					position: {
						x: typeof pos.x === 'number' ? pos.x : 0,
						y: typeof pos.y === 'number' ? pos.y : 0,
					},
					data,
				};
				if (n.parentId != null) obj.parentId = n.parentId;
				const eid = n.entityId ?? (data?.stationId ?? data?.entityId);
				if (eid != null && eid !== '') {
					const numEid = typeof eid === 'number' && !isNaN(eid) ? eid : parseInt(String(eid), 10);
					if (!isNaN(numEid) && Number.isInteger(numEid)) obj.entityId = numEid;
				}
				if (n.width != null && typeof n.width === 'number') obj.width = n.width;
				if (n.height != null && typeof n.height === 'number') obj.height = n.height;
				return obj;
			});
			/** Sanitize edges: include only id, source, target, type, data (for waypoints). */
			const rawEdges = (edges ?? []) as Array<Record<string, unknown>>;
			const sanitizedEdges = rawEdges.map((e) => ({
				id: String(e.id ?? ''),
				source: String(e.source ?? ''),
				target: String(e.target ?? ''),
				type: e.type ?? 'dottedFlow',
				...(e.data && typeof e.data === 'object' ? { data: e.data } : {}),
			}));
			const layout: Record<string, unknown> = {
				nodes: sanitizedNodes,
				edges: sanitizedEdges,
			};
			if (viewport && typeof viewport.x === 'number' && typeof viewport.y === 'number' && typeof viewport.zoom === 'number') {
				layout.viewport = { x: viewport.x, y: viewport.y, zoom: viewport.zoom };
			}
			const csrf =
				(typeof document !== 'undefined' &&
					(document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content) ||
				'';
			if (!csrf) {
				toast('Missing CSRF token. Refresh the page and try again.', 'error');
				return;
			}
			const res = await fetch(`/api/admin/programs/${programId}/diagram`, {
				method: 'PUT',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-CSRF-TOKEN': csrf,
					'X-Requested-With': 'XMLHttpRequest',
				},
				credentials: 'same-origin',
				body: JSON.stringify({ layout }),
			});
			const data = await res.json().catch(() => ({}));
			if (res.ok) {
				toast('Diagram saved.', 'success');
			} else {
				const errMsg = data?.message as string | undefined;
				const errs = data?.errors as Record<string, string[]> | undefined;
				const firstErr = errs && typeof errs === 'object'
					? (Object.values(errs).flat()[0] as string | undefined)
					: undefined;
				toast(firstErr || errMsg || `Failed to save diagram (${res.status}).`, 'error');
			}
		} catch {
			toast('Failed to save diagram.', 'error');
		} finally {
			saving = false;
		}
	}

	async function handleImageUpload(e: Event) {
		const input = e.target as HTMLInputElement;
		const file = input?.files?.[0];
		if (!file || !programId) return;
		imageUploading = true;
		try {
			const csrf =
				(typeof document !== 'undefined' &&
					(document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content) ||
				'';
			const form = new FormData();
			form.append('image', file);
			const res = await fetch(`/api/admin/programs/${programId}/diagram/image`, {
				method: 'POST',
				headers: {
					Accept: 'application/json',
					'X-CSRF-TOKEN': csrf,
					'X-Requested-With': 'XMLHttpRequest',
				},
				credentials: 'same-origin',
				body: form,
			});
			const data = await res.json().catch(() => ({}));
			if (res.ok && data?.url) {
				updateNodes((nodes) => [
					...nodes,
					{
						id: crypto.randomUUID(),
						type: 'image',
						position: { x: 100, y: 100 },
						data: { url: data.url },
					},
				]);
				pushHistory();
				toast('Image added.', 'success');
			} else {
				toast((data?.message as string) || 'Failed to upload image.', 'error');
			}
		} catch {
			toast('Failed to upload image.', 'error');
		} finally {
			imageUploading = false;
			input.value = '';
		}
	}

	const selectedNodeIds = $derived(
		(nodesCurrent as Array<{ id: string; selected?: boolean }>).filter((n) => n.selected).map((n) => n.id)
	);

	function bringSelectedToFront() {
		const nodes = nodesCurrent as Array<{ id: string; zIndex?: number }>;
		const maxZ = nodes.reduce((m, n) => Math.max(m, n.zIndex ?? 0), 0);
		for (const id of selectedNodeIds) {
			svelteFlow.updateNode(id, { zIndex: maxZ + 1 });
		}
		pushHistory();
	}

	function sendSelectedToBack() {
		for (const id of selectedNodeIds) {
			svelteFlow.updateNode(id, { zIndex: 0 });
		}
		pushHistory();
	}

	/** Keep process handles inside their zone (top or bottom dotted area) when drag stops. */
	const TOP_ZONE_HEIGHT = 48;
	const BOTTOM_ZONE_HEIGHT = 48;
	function handleNodeDragStop({
		targetNode,
		nodes,
	}: {
		targetNode: { id: string; type?: string; parentId?: string; position?: { x: number; y: number }; data?: Record<string, unknown> };
		nodes: Array<{ id: string; type?: string; parentId?: string; position?: { x: number; y: number }; width?: number; height?: number }>;
	}) {
		if (readOnly || !targetNode || targetNode.type !== 'process_handle' || !targetNode.parentId) return;
		const parent = nodes.find((n) => n.id === targetNode.parentId);
		if (!parent || typeof parent.width !== 'number' || typeof parent.height !== 'number') return;
		const parentWidth = parent.width;
		const parentHeight = parent.height;
		const handleWidth = 80;
		const handleHeight = 28;
		const pos = targetNode.position ?? { x: 0, y: 0 };
		const centerX = pos.x + handleWidth / 2;
		const centerY = pos.y + handleHeight / 2;
		const topZoneYMax = TOP_ZONE_HEIGHT - handleHeight;
		const bottomZoneYMin = parentHeight - BOTTOM_ZONE_HEIGHT - handleHeight;
		const bottomZoneYMax = parentHeight - handleHeight;
		/** Process boxes live only in top zone or bottom zone; clamp to that area. */
		const side: 'top' | 'bottom' = centerY < parentHeight / 2 ? 'top' : 'bottom';
		const x = Math.max(0, Math.min(parentWidth - handleWidth, centerX - handleWidth / 2));
		const y = side === 'top'
			? Math.max(0, Math.min(topZoneYMax, pos.y))
			: Math.max(bottomZoneYMin, Math.min(bottomZoneYMax, pos.y));
		svelteFlow.updateNode(targetNode.id, { position: { x, y } });
		svelteFlow.updateNodeData(targetNode.id, { ...(targetNode.data ?? {}), side });
		pushHistory();
	}
</script>

<svelte:window
	onkeydown={(e) => {
		if (readOnly) return;
		const mod = e.ctrlKey || e.metaKey;
		if (mod && e.key === 'z') { e.preventDefault(); e.shiftKey ? redo() : undo(); }
		if (mod && e.key === 'y') { e.preventDefault(); redo(); }
	}}
/>
<div
	class="min-h-[360px] w-full"
	ondrop={handleDrop}
	ondragover={handleDragOver}
	role="presentation"
>
<SvelteFlow
	nodes={nodesCurrent}
	edges={edgesCurrent}
	deleteKey={['Backspace', 'Delete']}
	nodeTypes={nodeTypes}
	edgeTypes={edgeTypes}
	fitView={!initialLayout?.viewport}
	initialViewport={initialLayout?.viewport ? { x: initialLayout.viewport.x ?? 0, y: initialLayout.viewport.y ?? 0, zoom: initialLayout.viewport.zoom ?? 1 } : undefined}
	nodesDraggable={!readOnly}
	elementsSelectable={!readOnly}
	onnodedragstop={handleNodeDragStop}
	onedgeclick={(p) => {
		if (!readOnly && p.event.detail === 2) bendingEdgeIdStore.set(p.edge.id);
	}}
	onpaneclick={() => bendingEdgeIdStore.set(null)}
	onbeforedelete={() => {
		pushHistory();
		return Promise.resolve(true);
	}}
	zoomOnDoubleClick={false}
	class="min-h-[360px] w-full"
>
	<Background />
	<Controls />
	{#if !readOnly}
	<Panel position="top-left" class="flex flex-col gap-2 max-w-[200px]">
		<p class="text-xs font-semibold text-surface-700">Undo / Redo</p>
		<div class="flex flex-wrap gap-1">
			<button
				type="button"
				class="btn preset-tonal text-xs min-h-[32px] px-2"
				disabled={!canUndo}
				onclick={undo}
				title="Undo"
			>
				Undo
			</button>
			<button
				type="button"
				class="btn preset-tonal text-xs min-h-[32px] px-2"
				disabled={!canRedo}
				onclick={redo}
				title="Redo"
			>
				Redo
			</button>
		</div>
		<p class="text-xs font-semibold text-surface-700">Layer</p>
		<div class="flex flex-wrap gap-1">
			<button
				type="button"
				class="btn preset-tonal text-xs min-h-[32px] px-2"
				disabled={selectedNodeIds.length === 0}
				onclick={bringSelectedToFront}
				title="Bring selected to front"
			>
				Front
			</button>
			<button
				type="button"
				class="btn preset-tonal text-xs min-h-[32px] px-2"
				disabled={selectedNodeIds.length === 0}
				onclick={sendSelectedToBack}
				title="Send selected to back"
			>
				Back
			</button>
		</div>
		<p class="text-xs font-semibold text-surface-700">Flow (track)</p>
		<div class="flex flex-wrap gap-1">
			<button
				type="button"
				class="btn preset-tonal text-xs min-h-[32px] px-2 {selectedTrackId === null ? 'ring-2 ring-primary-500' : ''}"
				onclick={() => onTrackSelect(null)}
			>
				None
			</button>
			{#each tracks as track (track.id)}
				<button
					type="button"
					class="btn preset-tonal text-xs min-h-[32px] px-2 {selectedTrackId === track.id ? 'ring-2 ring-primary-500' : ''}"
					onclick={() => onTrackSelect(track.id)}
				>
					{track.name}
				</button>
			{/each}
		</div>
		<button
			type="button"
			class="btn preset-filled-primary-500 min-h-[40px]"
			disabled={saving}
			onclick={handleSave}
		>
			{#if saving}
				Saving...
			{:else}
				Save diagram
			{/if}
		</button>
		<input
			type="file"
			accept="image/jpeg,image/png,image/jpg"
			class="hidden"
			bind:this={imageInputEl}
			onchange={handleImageUpload}
		/>
		<button
			type="button"
			class="btn preset-tonal min-h-[36px]"
			disabled={imageUploading}
			onclick={() => imageInputEl?.click()}
		>
			{#if imageUploading}
				Uploading...
			{:else}
				Add image
			{/if}
		</button>
	</Panel>
	{/if}
</SvelteFlow>
</div>
