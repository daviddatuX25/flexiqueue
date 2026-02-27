<script lang="ts">
	/**
	 * Inner diagram content: uses Svelte Flow hooks for load/save and drop. Per plan 1.5, 1.6.
	 */
import { setContext } from 'svelte';
	import { tick } from 'svelte';
	import { writable } from 'svelte/store';
	import { SvelteFlow, Background, Controls, Panel } from '@xyflow/svelte';
	import type { Node, Edge } from '@xyflow/svelte';
	import { useViewport, useSvelteFlow } from '@xyflow/svelte';
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

	// Pattern A: parent owns nodes/edges as $state.raw + bind: — avoids both structuredClone
	// errors (from toObject()) and derived_references_self circular deps from hook getters.
	// $state.raw skips deep proxying so no cloning issues; bind: keeps SvelteFlow in sync.
	let ownNodes = $state.raw<Node[]>([]);
	let ownEdges = $state.raw<Edge[]>([]);
	const { set: setViewport } = useViewport();
	const svelteFlow = useSvelteFlow();

	let saving = $state(false);
	let saveStatus = $state<'idle' | 'saving' | 'saved' | 'error'>('idle');
	let saveStatusTimeout: ReturnType<typeof setTimeout> | null = null;
	let publishing = $state(false);
	let autoSaveTimeout: ReturnType<typeof setTimeout> | null = null;

	/** Edge bending state: which edge is currently in bend mode, and per-edge layout metadata. */
	const bendingEdgeIdStore = writable<string | null>(null);
	let edgeWaypoints = new Map<string, { x: number; y: number }[]>();
	let edgeEndpointMeta = new Map<
		string,
		{
			sourceSide?: 'top' | 'right' | 'bottom' | 'left';
			sourceOffset?: number;
			targetSide?: 'top' | 'right' | 'bottom' | 'left';
			targetOffset?: number;
		}
	>();
	let edgeLayoutVersion = $state(0);

	function setSaveStatus(status: 'idle' | 'saving' | 'saved' | 'error') {
		if (saveStatusTimeout) clearTimeout(saveStatusTimeout);
		saveStatus = status;
		if (status === 'saved' || status === 'error') {
			saveStatusTimeout = setTimeout(() => {
				saveStatus = 'idle';
			}, 3000);
		}
	}

	setContext('diagramFlow', {
		bendingEdgeIdStore,
		onWaypointUpdate(id: string, waypoints: { x: number; y: number }[]) {
			edgeWaypoints = new Map(edgeWaypoints).set(id, waypoints);
			edgeLayoutVersion++;
			scheduleAutoSave('bend-edge');
		},
		onEndpointUpdate(
			id: string,
			payload: {
				sourceSide?: 'top' | 'right' | 'bottom' | 'left';
				sourceOffset?: number;
				targetSide?: 'top' | 'right' | 'bottom' | 'left';
				targetOffset?: number;
			}
		) {
			// Merge with existing so moving one endpoint doesn't wipe the other (and so partial updates persist).
			const existing = edgeEndpointMeta.get(id) ?? {};
			const merged = { ...existing };
			if (payload.sourceSide !== undefined) merged.sourceSide = payload.sourceSide;
			if (payload.sourceOffset !== undefined) merged.sourceOffset = payload.sourceOffset;
			if (payload.targetSide !== undefined) merged.targetSide = payload.targetSide;
			if (payload.targetOffset !== undefined) merged.targetOffset = payload.targetOffset;
			edgeEndpointMeta = new Map(edgeEndpointMeta).set(id, merged);
			edgeLayoutVersion++;
			scheduleAutoSave('endpoint-drag');
		},
		get readOnly() {
			return readOnly;
		},
	});
	let imageUploading = $state(false);
	let imageInputEl = $state<HTMLInputElement | undefined>(undefined);

	function scheduleAutoSave(reason?: string) {
		if (readOnly || !programId) return;
		if (autoSaveTimeout) {
			clearTimeout(autoSaveTimeout);
		}
		autoSaveTimeout = setTimeout(() => {
			// If a save is already in progress, try again shortly after it finishes.
			if (saving) {
				scheduleAutoSave(reason);
				return;
			}
			handleSave();
		}, 800);
		if (reason) {
			console.debug?.('Scheduling diagram auto-save:', reason);
		}
	}

	/** Tracks last loaded layout so we only reset when layoutFromApi actually changes (not when entityLookups/staffList loads). */
	let lastLoadedLayoutRef = $state<LayoutShape | null | undefined>(undefined);
	$effect(() => {
		const layout = layoutFromApi;
		if (!layout || layout === lastLoadedLayoutRef) return;
		lastLoadedLayoutRef = layout;
		const sanitized = initialLayout ?? layout;
		const loadedNodes = Array.isArray(sanitized.nodes) ? sanitized.nodes : [];
		const loadedEdges = Array.isArray(sanitized.edges) ? sanitized.edges : [];
		const vp = sanitized.viewport ?? layout.viewport;
		if (loadedEdges.length > 0) {
			const waypointMap = new Map<string, { x: number; y: number }[]>();
			const endpointMap = new Map<
				string,
				{
					sourceSide?: 'top' | 'right' | 'bottom' | 'left';
					sourceOffset?: number;
					targetSide?: 'top' | 'right' | 'bottom' | 'left';
					targetOffset?: number;
				}
			>();
			for (const e of loadedEdges) {
				const edge = e as {
					id?: string;
					data?: {
						waypoint?: { x: number; y: number };
						waypoints?: { x: number; y: number }[];
						sourceSide?: 'top' | 'right' | 'bottom' | 'left';
						sourceOffset?: number;
						targetSide?: 'top' | 'right' | 'bottom' | 'left';
						targetOffset?: number;
					};
				};
				if (!edge.id || !edge.data) continue;
				if (Array.isArray(edge.data.waypoints) && edge.data.waypoints.length) {
					waypointMap.set(
						edge.id,
						edge.data.waypoints.map((wp) => ({ x: wp.x, y: wp.y }))
					);
				} else if (edge.data.waypoint) {
					waypointMap.set(edge.id, [{ x: edge.data.waypoint.x, y: edge.data.waypoint.y }]);
				}
				const { sourceSide, sourceOffset, targetSide, targetOffset } = edge.data;
				if (
					sourceSide ||
					targetSide ||
					typeof sourceOffset === 'number' ||
					typeof targetOffset === 'number'
				) {
					endpointMap.set(edge.id, { sourceSide, sourceOffset, targetSide, targetOffset });
				}
			}
			edgeWaypoints = waypointMap;
			edgeEndpointMeta = endpointMap;
			edgeLayoutVersion++;
		} else {
			edgeWaypoints = new Map();
			edgeEndpointMeta = new Map();
			edgeLayoutVersion++;
		}
		ownNodes = loadedNodes as unknown as Node[];
		ownEdges = [];
		if (vp && typeof vp.x === 'number' && typeof vp.y === 'number' && typeof vp.zoom === 'number') {
			setViewport({ x: vp.x, y: vp.y, zoom: vp.zoom });
		}
	});

	/** Diagram v2: derive flow edges from track steps and process handle nodes.
	 * If a track is selected, show only that track; otherwise show all tracks.
	 * Waypoints (edge bends) come from edgeWaypoints, keyed by edge id. */
	$effect(() => {
		const _version = edgeLayoutVersion;
		const tracksToShow = selectedTrackId == null ? tracks : tracks.filter((t) => t.id === selectedTrackId);
		if (!tracksToShow.length) {
			ownEdges = [];
			return;
		}
		const currentNodes = ownNodes as Array<{ id: string; type?: string; data?: Record<string, unknown> }>;
		// Map process_id -> first process_handle node id. Track steps only carry process_id,
		// so we connect based on the process sequence, not specific stations.
		const handleMap = new Map<number, string>();
		for (const n of currentNodes) {
			if (n.type !== 'process_handle' || !n.data) continue;
			const pid = n.data.processId as number | undefined;
			if (pid == null || Number.isNaN(pid)) continue;
			if (!handleMap.has(pid)) handleMap.set(pid, n.id);
		}
		const palette = ['var(--color-primary-500)', 'var(--color-secondary-500)', 'var(--color-tertiary-500)'];
		const baseEdges: Array<{
			id: string;
			source: string;
			target: string;
			type: string;
			data?: {
				trackColor?: string;
				waypoints?: { x: number; y: number }[];
				sourceSide?: 'top' | 'right' | 'bottom' | 'left';
				sourceOffset?: number;
				targetSide?: 'top' | 'right' | 'bottom' | 'left';
				targetOffset?: number;
			};
		}> = [];
		tracksToShow.forEach((track, trackIndex) => {
			const steps = track.steps;
			if (!steps || steps.length < 2) return;
			const sortedSteps = [...steps].sort(
				(a, b) => ((a as { step_order?: number }).step_order ?? 0) - ((b as { step_order?: number }).step_order ?? 0)
			);
			const color = palette[trackIndex % palette.length];
			for (let i = 0; i < sortedSteps.length - 1; i++) {
				const a = sortedSteps[i] as { process_id: number };
				const b = sortedSteps[i + 1] as { process_id: number };
				const sourceId = handleMap.get(a.process_id);
				const targetId = handleMap.get(b.process_id);
				if (sourceId && targetId) {
					const edgeId = `flow-${track.id}-${a.process_id}-${b.process_id}-${i}`;
					const waypoints = edgeWaypoints.get(edgeId);
					const endpoints = edgeEndpointMeta.get(edgeId);
					baseEdges.push({
						id: edgeId,
						source: sourceId,
						target: targetId,
						type: 'dottedFlow',
						data: {
							trackColor: color,
							...(waypoints && waypoints.length ? { waypoints } : {}),
							...(endpoints ?? {}),
						},
					});
				}
			}
		});
		ownEdges = baseEdges as unknown as Edge[];
	});

	function handleDrop(e: DragEvent) {
		e.preventDefault();
		e.stopPropagation();
		if (readOnly) return;
		const raw = e.dataTransfer?.getData('application/json');
		if (!raw) return;
		try {
			const payload = JSON.parse(raw) as { type?: string; entityId?: number; label?: string; text?: string; url?: string };
			const type = payload.type ?? 'station';
			const allowed = ['station', 'track', 'process', 'staff', 'client_seat', 'shape', 'text', 'image'];
			if (!allowed.includes(type)) return;
			let position = svelteFlow.screenToFlowPosition({ x: e.clientX, y: e.clientY });
			if (!position || typeof position.x !== 'number' || typeof position.y !== 'number' || Number.isNaN(position.x) || Number.isNaN(position.y)) {
				position = { x: 0, y: 0 };
			}

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
			ownNodes = [...ownNodes, parentNode as unknown as Node, ...(processHandleNodes as unknown as Node[])];
			scheduleAutoSave('drop-station-group');
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
			ownNodes = [...ownNodes, newNode as unknown as Node];
			scheduleAutoSave('drop-node');
		} catch {
			// ignore invalid JSON
		}
	}

	function handleDragOver(e: DragEvent) {
		e.preventDefault();
		e.stopPropagation();
		if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
	}

	async function handleSave() {
		if (!programId) return;
		saving = true;
		setSaveStatus('saving');
		try {
			await tick();
			// Read directly from $state.raw owned arrays — no structuredClone issues,
			// no circular deps. JSON round-trip strips any remaining reactive proxy wrappers.
			const nodes = JSON.parse(JSON.stringify(ownNodes)) as Array<Record<string, unknown>>;
			const edges = JSON.parse(JSON.stringify(ownEdges)) as Array<Record<string, unknown>>;
			const viewport = svelteFlow.getViewport() as { x?: number; y?: number; zoom?: number } | undefined;
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
			/** Sanitize edges: include only id, source, target, type, data (for trackColor/waypoint). */
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
				setSaveStatus('error');
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
				setSaveStatus('saved');
			} else {
				const errMsg = data?.message as string | undefined;
				const errs = data?.errors as Record<string, string[]> | undefined;
				const firstErr =
					errs && typeof errs === 'object'
						? (Object.values(errs).flat()[0] as string | undefined)
						: undefined;
				let message: string;
				if (firstErr) {
					message = firstErr;
				} else if (errMsg) {
					message = errMsg;
				} else if (res.status === 419) {
					message = 'Session expired. Refresh the page and try again.';
				} else if (res.status >= 500) {
					message = 'Failed to save diagram (server error). Please try again or contact support.';
				} else {
					message = `Failed to save diagram (${res.status}).`;
				}
			console.error?.('Failed to save diagram response', res.status, data);
			setSaveStatus('error');
			toast(message, 'error');
			}
	} catch (err) {
		setSaveStatus('error');
		toast('Failed to save diagram. Check your connection and try again.', 'error');
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
				ownNodes = [
					...ownNodes,
					{
						id: crypto.randomUUID(),
						type: 'image',
						position: { x: 100, y: 100 },
						data: { url: data.url },
					} as unknown as Node,
				];
				toast('Image added.', 'success');
				scheduleAutoSave('image-upload');
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
		if (readOnly || !targetNode) return;
		if (targetNode.type !== 'process_handle' || !targetNode.parentId) {
			// Other node types (stations, shapes, etc.) still need auto-save.
			scheduleAutoSave('node-drag');
			return;
		}
		const parent = nodes.find((n) => n.id === targetNode.parentId);
		if (!parent || typeof parent.width !== 'number' || typeof parent.height !== 'number') {
			scheduleAutoSave('process-handle-drag-no-parent');
			return;
		}
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
		ownNodes = ownNodes.map((n) => {
			if (n.id !== targetNode.id) return n;
			return { ...n, position: { x, y }, data: { ...(n.data as Record<string, unknown> ?? {}), side } } as unknown as Node;
		});
		scheduleAutoSave('process-handle-drag');
	}

	function buildHandleKeySet(nodes: Array<{ type?: string; data?: Record<string, unknown> }>): Set<string> {
		const keys = new Set<string>();
		for (const node of nodes) {
			if (node.type !== 'process_handle' || !node.data) continue;
			const sidRaw = node.data.stationId as unknown;
			const pidRaw = node.data.processId as unknown;
			const sid = typeof sidRaw === 'number' ? sidRaw : parseInt(String(sidRaw ?? ''), 10);
			const pid = typeof pidRaw === 'number' ? pidRaw : parseInt(String(pidRaw ?? ''), 10);
			if (Number.isNaN(sid) || Number.isNaN(pid)) continue;
			keys.add(`${sid},${pid}`);
		}
		return keys;
	}

	function validateDiagramForPublish(): { ok: boolean; errors: string[] } {
		const errors: string[] = [];
		const nodes = ownNodes as Array<{ type?: string; data?: Record<string, unknown> }>;
		const handleKeys = buildHandleKeySet(nodes);

		const checkedTracks =
			selectedTrackId != null ? tracks.filter((t) => t.id === selectedTrackId) : tracks;

		for (const track of checkedTracks) {
			const steps = track.steps ?? [];
			if (!Array.isArray(steps) || steps.length === 0) {
				errors.push(`Track "${track.name}" has no steps configured.`);
				continue;
			}
			for (const step of steps) {
				const sid = (step as { station_id?: number }).station_id;
				const pid = (step as { process_id?: number }).process_id;
				if (sid == null || pid == null) {
					errors.push(
						`Track "${track.name}" has a step with missing station or process. Check track configuration.`
					);
					continue;
				}
				const key = `${sid},${pid}`;
				if (!handleKeys.has(key)) {
					errors.push(
						`Track "${track.name}": step for station ${sid} / process ${pid} is missing from the diagram.`
					);
				}
			}
		}

		return { ok: errors.length === 0, errors };
	}

	async function handlePublish() {
		if (!programId) return;
		publishing = true;
		try {
			const result = validateDiagramForPublish();
			if (!result.ok) {
				const first = result.errors[0] ?? 'Diagram is not ready to publish.';
				console.warn?.('Diagram publish validation errors', result.errors);
				toast(first, 'error');
				return;
			}
			toast('Diagram passed publish checks.', 'success');
		} finally {
			publishing = false;
		}
	}

	function handleClearDiagram() {
		if (readOnly || !programId) return;
		if (typeof window !== 'undefined') {
			const ok = window.confirm('Clear this diagram and start over?');
			if (!ok) return;
		}
		ownNodes = [] as unknown as Node[];
		ownEdges = [] as unknown as Edge[];
		edgeWaypoints = new Map();
		edgeEndpointMeta = new Map();
		edgeLayoutVersion++;
		bendingEdgeIdStore.set(null);
		scheduleAutoSave('clear-diagram');
	}
</script>

<style>
	:global(.svelte-flow__edges) {
		z-index: 10 !important;
	}
</style>

<svelte:window />
<div
	class="min-h-[360px] w-full"
	ondrop={handleDrop}
	ondragover={handleDragOver}
	role="presentation"
>
<SvelteFlow
	bind:nodes={ownNodes}
	bind:edges={ownEdges}
	ondrop={handleDrop}
	ondragover={handleDragOver}
	deleteKey={['Backspace', 'Delete']}
	nodeTypes={nodeTypes}
	edgeTypes={edgeTypes}
	zIndexMode="manual"
	fitView={!initialLayout?.viewport}
	initialViewport={initialLayout?.viewport ? { x: initialLayout.viewport.x ?? 0, y: initialLayout.viewport.y ?? 0, zoom: initialLayout.viewport.zoom ?? 1 } : undefined}
	nodesDraggable={!readOnly}
	elementsSelectable={!readOnly}
	onnodedragstop={handleNodeDragStop}
	onedgeclick={(p) => {
		if (!readOnly) bendingEdgeIdStore.set(p.edge.id);
	}}
	onpaneclick={() => bendingEdgeIdStore.set(null)}
	onbeforedelete={() => {
		scheduleAutoSave('delete');
		return Promise.resolve(true);
	}}
	zoomOnDoubleClick={false}
	class="min-h-[360px] w-full"
>
	<Background />
	<Controls />
	{#if !readOnly}
		<Panel position="bottom-center" class="pointer-events-none">
			{#if saveStatus === 'saving'}
				<div class="pointer-events-auto rounded bg-surface-100/80 px-3 py-1 text-xs shadow border border-surface-200">
					Saving…
				</div>
			{:else if saveStatus === 'saved'}
				<div class="pointer-events-auto rounded bg-surface-100/80 px-3 py-1 text-xs shadow border border-surface-200">
					Saved
				</div>
			{:else if saveStatus === 'error'}
				<div class="pointer-events-auto rounded bg-error-100/90 px-3 py-1 text-xs shadow border border-error-200 text-error-700">
					Save failed
				</div>
			{/if}
		</Panel>
	{/if}
	{#if !readOnly}
	<Panel position="top-left" class="flex flex-col gap-2 max-w-[200px]">
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
			disabled={saving || publishing}
			onclick={handlePublish}
		>
			{#if publishing}
				Checking…
			{:else}
				Publish diagram
			{/if}
		</button>
		<button
			type="button"
			class="btn preset-tonal min-h-[36px]"
			disabled={saving}
			onclick={handleClearDiagram}
		>
			Clear diagram
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
