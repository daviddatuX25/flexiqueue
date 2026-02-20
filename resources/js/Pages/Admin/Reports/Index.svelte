<script lang="ts">
	import AdminLayout from '../../../Layouts/AdminLayout.svelte';
	import { get } from 'svelte/store';
	import { onMount } from 'svelte';
	import { usePage } from '@inertiajs/svelte';

	interface ProgramItem {
		id: number;
		name: string;
	}

	interface StationItem {
		id: number;
		name: string;
		program_id: number;
	}

	interface StaffUserItem {
		id: number;
		name: string;
	}

	interface ProgramSessionItem {
		id: number;
		program_id: number;
		program_name: string;
		started_at: string;
		ended_at: string | null;
		started_by: string;
	}

	interface AuditLogEntry {
		id: number | string;
		source?: string;
		session_alias: string;
		action_type: string;
		station: string;
		staff: string;
		remarks: string | null;
		created_at: string;
	}

	let {
		programs = [],
		stations = [],
		staffUsers = []
	}: {
		programs: ProgramItem[];
		stations: StationItem[];
		staffUsers: StaffUserItem[];
	} = $props();

	let data = $state<AuditLogEntry[]>([]);
	let meta = $state<{ total: number; per_page: number; current_page: number } | null>(null);
	let loading = $state(true);
	let error = $state('');

	// Filters
	let filterProgramId = $state<number | ''>('');
	let filterFrom = $state('');
	let filterTo = $state('');
	let filterActionType = $state('');
	let filterStationId = $state<number | ''>('');
	let filterStaffUserId = $state<number | ''>('');
	let filterProgramSessionId = $state<number | ''>('');

	let programSessions = $state<ProgramSessionItem[]>([]);
	let programSessionsLoading = $state(false);

	const page = usePage();
	function getCsrfToken(): string {
		const p = get(page);
		const fromProps = (p?.props as { csrf_token?: string } | undefined)?.csrf_token;
		if (fromProps) return fromProps;
		const metaEl =
			typeof document !== 'undefined'
				? (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content
				: '';
		return metaEl ?? '';
	}

	async function api(method: string, url: string): Promise<{ ok: boolean; data?: { data: AuditLogEntry[]; meta: typeof meta } }> {
		const res = await fetch(url, {
			method,
			headers: {
				Accept: 'application/json',
				'X-CSRF-TOKEN': getCsrfToken(),
				'X-Requested-With': 'XMLHttpRequest'
			},
			credentials: 'same-origin'
		});
		const json = await res.json().catch(() => ({}));
		return { ok: res.ok, data: json };
	}

	function buildAuditUrl(pageNum = 1): string {
		const params = new URLSearchParams();
		if (filterProgramId) params.set('program_id', String(filterProgramId));
		if (filterFrom) params.set('from', filterFrom);
		if (filterTo) params.set('to', filterTo);
		if (filterActionType) params.set('action_type', filterActionType);
		if (filterStationId) params.set('station_id', String(filterStationId));
		if (filterStaffUserId) params.set('staff_user_id', String(filterStaffUserId));
		if (filterProgramSessionId) params.set('program_session_id', String(filterProgramSessionId));
		params.set('page', String(pageNum));
		return `/api/admin/reports/audit?${params.toString()}`;
	}

	function buildExportUrl(): string {
		const params = new URLSearchParams();
		if (filterProgramId) params.set('program_id', String(filterProgramId));
		if (filterFrom) params.set('from', filterFrom);
		if (filterTo) params.set('to', filterTo);
		if (filterActionType) params.set('action_type', filterActionType);
		if (filterStationId) params.set('station_id', String(filterStationId));
		if (filterStaffUserId) params.set('staff_user_id', String(filterStaffUserId));
		if (filterProgramSessionId) params.set('program_session_id', String(filterProgramSessionId));
		const q = params.toString();
		return q ? `/api/admin/reports/audit/export?${q}` : '/api/admin/reports/audit/export';
	}

	async function fetchProgramSessions() {
		programSessionsLoading = true;
		const params = new URLSearchParams();
		if (filterProgramId) params.set('program_id', String(filterProgramId));
		if (filterFrom) params.set('from', filterFrom);
		if (filterTo) params.set('to', filterTo);
		const url = `/api/admin/reports/program-sessions?${params.toString()}`;
		const res = await api('GET', url);
		programSessionsLoading = false;
		if (res.ok && (res.data as { program_sessions?: ProgramSessionItem[] })?.program_sessions !== undefined) {
			programSessions = (res.data as { program_sessions: ProgramSessionItem[] }).program_sessions;
		} else {
			programSessions = [];
		}
	}

	async function fetchAudit(pageNum = 1) {
		loading = true;
		error = '';
		const res = await api('GET', buildAuditUrl(pageNum));
		loading = false;
		if (res.ok && res.data?.data !== undefined) {
			data = res.data.data;
			meta = res.data.meta ?? null;
		} else {
			data = [];
			meta = null;
			error = 'Failed to load audit log.';
		}
	}

	function applyFilters() {
		fetchProgramSessions();
		fetchAudit(1);
	}

	function goToPage(pageNum: number) {
		if (meta && pageNum >= 1 && pageNum <= Math.ceil(meta.total / meta.per_page)) {
			fetchAudit(pageNum);
		}
	}

	// Per 09-UI-ROUTES: LogRow color-coded by action_type; program session start/stop
	function actionBadgeClass(actionType: string): string {
		const map: Record<string, string> = {
			bind: 'preset-filled-primary-500',
			call: 'preset-filled-primary-500',
			check_in: 'preset-filled-success-500',
			transfer: 'preset-filled-warning-500',
			override: 'preset-filled-error-500',
			complete: 'preset-filled-success-500',
			cancel: 'preset-tonal',
			no_show: 'preset-filled-warning-500',
			reorder: 'preset-tonal',
			force_complete: 'preset-filled-error-500',
			identity_mismatch: 'preset-filled-error-500',
			session_start: 'preset-filled-success-500',
			session_stop: 'preset-tonal'
		};
		return `badge ${map[actionType] ?? 'preset-tonal'}`;
	}

	function formatProgramSessionLabel(ps: ProgramSessionItem): string {
		const start = ps.started_at ? formatDate(ps.started_at) : '—';
		const end = ps.ended_at ? formatDate(ps.ended_at) : 'ongoing';
		return `${ps.program_name} — ${start} to ${end}`;
	}

	function formatDate(iso: string): string {
		if (!iso) return '—';
		try {
			const d = new Date(iso);
			return d.toLocaleString(undefined, {
				dateStyle: 'short',
				timeStyle: 'medium'
			});
		} catch {
			return iso;
		}
	}

	const ACTION_TYPES = [
		'bind',
		'call',
		'check_in',
		'transfer',
		'override',
		'complete',
		'cancel',
		'no_show',
		'reorder',
		'force_complete',
		'identity_mismatch',
		'session_start',
		'session_stop'
	];

	const stationsForProgram = $derived(
		filterProgramId ? stations.filter((s) => s.program_id === filterProgramId) : stations
	);

	const totalPages = $derived(meta ? Math.ceil(meta.total / meta.per_page) : 0);

	onMount(() => {
		fetchProgramSessions();
		fetchAudit(1);
	});
</script>

<svelte:head>
	<title>Reports — FlexiQueue</title>
</svelte:head>

<AdminLayout>
	<h1 class="text-2xl font-semibold text-surface-950">Reports & Audit</h1>
	<p class="mt-2 text-surface-950/80">View transaction logs and export to CSV for COA compliance.</p>

	{#if error}
		<div class="bg-error-100 text-error-900 border border-error-300 rounded-container p-4 mt-4">{error}</div>
	{/if}

	<!-- Filter panel -->
	<div class="card bg-surface-50 rounded-container elevation-card mt-6">
		<div class="card-body">
			<h2 class="card-title text-lg">Filters</h2>
			<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6 gap-4">
				<div class="form-control">
					<label for="filter-program" class="label">
						<span class="label-text">Program</span>
					</label>
					<select
						id="filter-program"
						class="select rounded-container border border-surface-200 px-3 py-2 select-sm"
						bind:value={filterProgramId}
						onchange={() => { filterStationId = ''; filterProgramSessionId = ''; }}
					>
						<option value="">All programs</option>
						{#each programs as p (p.id)}
							<option value={p.id}>{p.name}</option>
						{/each}
					</select>
				</div>
				<div class="form-control">
					<label for="filter-from" class="label">
						<span class="label-text">From date</span>
					</label>
					<input id="filter-from" type="date" class="input rounded-container border border-surface-200 px-3 py-2 input-sm" bind:value={filterFrom} />
				</div>
				<div class="form-control">
					<label for="filter-to" class="label">
						<span class="label-text">To date</span>
					</label>
					<input id="filter-to" type="date" class="input rounded-container border border-surface-200 px-3 py-2 input-sm" bind:value={filterTo} />
				</div>
				<div class="form-control">
					<label for="filter-program-session" class="label">
						<span class="label-text">Program session</span>
					</label>
					<select
						id="filter-program-session"
						class="select rounded-container border border-surface-200 px-3 py-2 select-sm"
						bind:value={filterProgramSessionId}
						disabled={programSessionsLoading}
					>
						<option value="">All sessions</option>
						{#each programSessions as ps (ps.id)}
							<option value={ps.id}>{formatProgramSessionLabel(ps)}</option>
						{/each}
					</select>
				</div>
				<div class="form-control">
					<label for="filter-action" class="label">
						<span class="label-text">Action type</span>
					</label>
					<select id="filter-action" class="select rounded-container border border-surface-200 px-3 py-2 select-sm" bind:value={filterActionType}>
						<option value="">All actions</option>
						{#each ACTION_TYPES as at}
							<option value={at}>{at.replace(/_/g, ' ')}</option>
						{/each}
					</select>
				</div>
				<div class="form-control">
					<label for="filter-station" class="label">
						<span class="label-text">Station</span>
					</label>
					<select id="filter-station" class="select rounded-container border border-surface-200 px-3 py-2 select-sm" bind:value={filterStationId}>
						<option value="">All stations</option>
						{#each stationsForProgram as s (s.id)}
							<option value={s.id}>{s.name}</option>
						{/each}
					</select>
				</div>
				<div class="form-control">
					<label for="filter-staff" class="label">
						<span class="label-text">Staff</span>
					</label>
					<select id="filter-staff" class="select rounded-container border border-surface-200 px-3 py-2 select-sm" bind:value={filterStaffUserId}>
						<option value="">All staff</option>
						{#each staffUsers as u (u.id)}
							<option value={u.id}>{u.name}</option>
						{/each}
					</select>
				</div>
			</div>
			<div class="card-actions justify-end mt-4">
				<button type="button" class="btn preset-filled-primary-500 btn-sm" onclick={applyFilters} disabled={loading}>
					Apply filters
				</button>
			</div>
		</div>
	</div>

	<!-- Export bar -->
	<div class="flex flex-wrap items-center justify-between gap-2 mt-6">
		<span class="text-sm text-surface-950/70">
			{#if meta}
				{meta.total} record{meta.total === 1 ? '' : 's'}
			{/if}
		</span>
		<a
			href={buildExportUrl()}
			class="btn preset-outlined btn-sm"
			download
			aria-label="Download CSV export"
		>
			<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
			</svg>
			Download CSV
		</a>
	</div>

	<!-- Audit log table -->
	{#if loading}
		<div class="flex justify-center py-12 mt-4">
			<span class="loading-spinner loading-lg text-primary-500"></span>
		</div>
	{:else if data.length === 0}
		<div class="text-center py-12 text-surface-950/70 mt-4">No audit log entries match the current filters.</div>
	{:else}
		<div class="table-container mt-4">
			<table class="table table-zebra">
				<thead>
					<tr>
						<th>Time</th>
						<th>Session</th>
						<th>Action</th>
						<th>Station</th>
						<th>Staff</th>
						<th>Remarks</th>
					</tr>
				</thead>
				<tbody>
					{#each data as entry (String(entry.id))}
						<tr>
							<td class="whitespace-nowrap">{formatDate(entry.created_at)}</td>
							<td>{entry.session_alias}</td>
							<td>
								<span class={actionBadgeClass(entry.action_type)}>{entry.action_type.replace(/_/g, ' ')}</span>
								{#if entry.source === 'program_session'}
									<span class="text-xs px-2 py-0.5 rounded preset-tonal badge-xs ml-1">program</span>
								{/if}
							</td>
							<td>{entry.station}</td>
							<td>{entry.staff}</td>
							<td class="max-w-xs truncate" title={entry.remarks ?? ''}>{entry.remarks ?? '—'}</td>
						</tr>
					{/each}
				</tbody>
			</table>
		</div>
	{/if}

	<!-- Pagination -->
	{#if meta && totalPages > 1}
		<div class="flex justify-center gap-2 mt-6">
			<button
				type="button"
				class="btn preset-tonal btn-sm"
				disabled={meta.current_page <= 1}
				onclick={() => goToPage(meta!.current_page - 1)}
			>
				Previous
			</button>
			<span class="flex items-center px-4 text-sm text-surface-950/80">
				Page {meta.current_page} of {totalPages}
			</span>
			<button
				type="button"
				class="btn preset-tonal btn-sm"
				disabled={meta.current_page >= totalPages}
				onclick={() => goToPage(meta!.current_page + 1)}
			>
				Next
			</button>
		</div>
	{/if}
</AdminLayout>
