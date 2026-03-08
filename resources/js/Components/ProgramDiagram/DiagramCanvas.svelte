<script lang="ts">
	/**
	 * Program diagram canvas: loads layout from API, wraps Svelte Flow (plan 1.4, 1.5).
	 */
	import { SvelteFlowProvider } from '@xyflow/svelte';
	import '@xyflow/svelte/dist/style.css';
	import DiagramFlowContent from './DiagramFlowContent.svelte';
	import { toaster } from '../../lib/toaster.js';

	const MSG_SESSION_EXPIRED = 'Session expired. Please refresh and try again.';
	const MSG_NETWORK_ERROR = 'Network error. Please try again.';

	interface LayoutShape {
		viewport?: { x?: number; y?: number; zoom?: number };
		nodes: Array<Record<string, unknown>>;
		edges?: Array<Record<string, unknown>>;
	}

	interface ProgramDiagramProps {
		program: { id: number; name: string } | null;
		tracks: Array<{ id: number; name: string; steps?: Array<{ station_id: number; process_id: number; step_order: number }> }>;
		stations: Array<{ id: number; name: string; process_ids?: number[] }>;
		processes: Array<{ id: number; name: string }>;
		readOnly?: boolean;
		/** When provided (e.g. Display/Status client view), use instead of fetching from API. Skips diagram and staff fetches. */
		initialLayout?: LayoutShape | null;
		/** Lock selected track for client view. */
		initialSelectedTrackId?: number | null;
		/** Staff list when using initialLayout (avoids fetch). */
		initialStaffList?: Array<{ id: number; name: string }>;
	}

	let {
		program = null,
		tracks = [],
		stations = [],
		processes = [],
		readOnly = false,
		initialLayout = null,
		initialSelectedTrackId = null,
		initialStaffList = [],
	}: ProgramDiagramProps = $props();

	let layoutFromApi = $state<LayoutShape | null>(null);
	let loadError = $state<string | null>(null);
	let staffList = $state<Array<{ id: number; name: string }>>([]);
	/** Diagram v2: which track's flow lines are shown. Default to first track, not None. */
	let selectedTrackId = $state<number | null>(null);

	/** When initialLayout provided, use it; otherwise default to first track or initialSelectedTrackId. */
	$effect(() => {
		const t = tracks;
		const initTrack = initialSelectedTrackId;
		if (initTrack != null) {
			selectedTrackId = initTrack;
		} else if (Array.isArray(t) && t.length > 0 && selectedTrackId == null) {
			selectedTrackId = t[0].id;
		}
	});

	/** When initialLayout provided, use it and initialStaffList; otherwise fetch from API. */
	$effect(() => {
		const initLayout = initialLayout;
		if (initLayout && initLayout.nodes && Array.isArray(initLayout.nodes) && initLayout.nodes.length > 0) {
			layoutFromApi = {
				viewport: initLayout.viewport,
				nodes: initLayout.nodes,
				edges: Array.isArray(initLayout.edges) ? initLayout.edges : [],
			};
			staffList = Array.isArray(initialStaffList) ? [...initialStaffList] : [];
			loadError = null;
			return;
		}
		const id = program?.id;
		if (!id) {
			layoutFromApi = null;
			staffList = [];
			return;
		}
		let cancelled = false;
		loadError = null;
		const check419 = (r: Response) => {
			if (r.status === 419) {
				toaster.error({ title: MSG_SESSION_EXPIRED });
				return Promise.resolve({});
			}
			return r.json().catch(() => ({}));
		};
		fetch(`/api/admin/programs/${id}/diagram`, {
			headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
			credentials: 'same-origin',
		})
			.then(check419)
			.then((data) => {
				if (cancelled) return;
				const layout = data?.layout;
				if (layout && typeof layout === 'object' && Array.isArray(layout.nodes)) {
					layoutFromApi = {
						viewport: layout.viewport,
						nodes: layout.nodes as Array<Record<string, unknown>>,
						edges: Array.isArray(layout.edges) ? (layout.edges as Array<Record<string, unknown>>) : [],
					};
				} else {
					layoutFromApi = null;
				}
			})
			.catch(() => {
				if (!cancelled) {
					loadError = 'Failed to load diagram';
					toaster.error({ title: MSG_NETWORK_ERROR });
				}
			});
		Promise.all([
				fetch(`/api/admin/programs/${id}/staff-assignments`, {
					headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
					credentials: 'same-origin',
				}).then(check419),
				fetch(`/api/admin/programs/${id}/supervisors`, {
					headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
					credentials: 'same-origin',
				}).then(check419),
			])
				.then(([assignmentsData, supervisorsData]) => {
					if (cancelled) return;
					const seen = new Set<number>();
					const list: Array<{ id: number; name: string }> = [];
					const assignments = assignmentsData?.assignments ?? [];
					for (const a of assignments) {
						const uid = a?.user_id ?? a?.user?.id;
						if (uid != null && !seen.has(uid)) {
							seen.add(uid);
							list.push({ id: uid, name: a?.user?.name ?? `User ${uid}` });
						}
					}
					const supervisors = supervisorsData?.supervisors ?? [];
					for (const s of supervisors) {
						const uid = s?.id;
						if (uid != null && !seen.has(uid)) {
							seen.add(uid);
							list.push({ id: uid, name: s?.name ?? `User ${uid}` });
						}
					}
					staffList = list.sort((a, b) => a.name.localeCompare(b.name));
				})
				.catch(() => {
					if (!cancelled) {
						staffList = [];
						toaster.error({ title: MSG_NETWORK_ERROR });
					}
				});
		return () => {
			cancelled = true;
		};
	});

	function dragPayload(type: string, entityId: number | undefined, label: string) {
		return JSON.stringify({ type, entityId, label });
	}
</script>

<div class="rounded-container border border-surface-200 bg-surface-50 min-h-[400px] w-full flex flex-col sm:flex-row">
	{#if program}
		{#if loadError}
			<p class="p-4 text-error-600">{loadError}</p>
		{:else}
			{#if !readOnly}
			<aside class="w-full sm:w-52 border-surface-200 border-b sm:border-b-0 sm:border-r p-3 bg-surface-100 shrink-0 overflow-y-auto max-h-[70vh] sm:max-h-none" aria-label="Diagram entities">
				<p class="text-xs font-semibold text-surface-700 mb-1.5">Stations</p>
				<ul class="space-y-1 mb-4">
					{#each stations as station (station.id)}
						<li>
							<div
								class="cursor-grab active:cursor-grabbing rounded-lg border border-surface-300 bg-surface-50 px-2 py-1.5 text-sm text-surface-950 hover:bg-surface-200/80"
								role="button"
								tabindex="0"
								draggable="true"
								ondragstart={(e) => {
									const dt = e.dataTransfer;
									if (dt) {
										dt.setData('application/json', dragPayload('station', station.id, station.name));
										dt.effectAllowed = 'copy';
									}
								}}
							>
								{station.name}
							</div>
						</li>
					{/each}
				</ul>
				{#if stations.length === 0}
					<p class="text-xs text-surface-500 mb-4">Add stations in the Stations tab first.</p>
				{/if}
				<p class="text-xs font-semibold text-surface-700 mb-1.5">Decorations</p>
				<div class="space-y-1">
					<div
						class="cursor-grab active:cursor-grabbing rounded-lg border border-dashed border-surface-400 bg-surface-50 px-2 py-1.5 text-xs text-surface-700 hover:bg-surface-200/80"
						role="button"
						tabindex="0"
						draggable="true"
						ondragstart={(e) => {
							const dt = e.dataTransfer;
							if (dt) {
								dt.setData('application/json', dragPayload('shape', undefined, 'Room'));
								dt.effectAllowed = 'copy';
							}
						}}
					>
						Add room
					</div>
					<div
						class="cursor-grab active:cursor-grabbing rounded-lg border border-surface-400 bg-surface-100 px-2 py-1.5 text-xs text-surface-700 hover:bg-surface-200/80"
						role="button"
						tabindex="0"
						draggable="true"
						ondragstart={(e) => {
							const dt = e.dataTransfer;
							if (dt) {
								dt.setData('application/json', JSON.stringify({ type: 'text', text: 'Text' }));
								dt.effectAllowed = 'copy';
							}
						}}
					>
						Add text
					</div>
				</div>
			</aside>
			{/if}
			<div class="flex-1 min-h-[360px] relative">
				<SvelteFlowProvider>
					<DiagramFlowContent
						layoutFromApi={layoutFromApi}
						entityLookups={{ stations, tracks, processes, staffList }}
						programId={program.id}
						{readOnly}
						{tracks}
						selectedTrackId={selectedTrackId}
						onTrackSelect={(id) => (selectedTrackId = id)}
						{stations}
						{processes}
					/>
				</SvelteFlowProvider>
			</div>
		{/if}
	{:else}
		<p class="p-4 text-surface-600">No program selected.</p>
	{/if}
</div>
