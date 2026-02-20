<script lang="ts">
	import AdminLayout from '../../../Layouts/AdminLayout.svelte';
	import Modal from '../../../Components/Modal.svelte';
	import ConfirmModal from '../../../Components/ConfirmModal.svelte';
	import FlowDiagram from '../../../Components/FlowDiagram.svelte';
	import { get } from 'svelte/store';
	import { Link, router, usePage } from '@inertiajs/svelte';

	interface ProgramItem {
		id: number;
		name: string;
		description: string | null;
		is_active: boolean;
		is_paused?: boolean;
		created_at: string | null;
		settings?: {
			no_show_timer_seconds: number;
			require_permission_before_override: boolean;
			priority_first: boolean;
			balance_mode: string;
			alternate_ratio: [number, number];
		};
	}

	interface StepItem {
		id: number;
		track_id: number;
		station_id: number;
		station_name: string;
		step_order: number;
		is_required: boolean;
		estimated_minutes: number | null;
	}

	interface TrackItem {
		id: number;
		program_id: number;
		name: string;
		description: string | null;
		is_default: boolean;
		color_code: string | null;
		created_at: string | null;
		active_sessions_count?: number;
		steps?: StepItem[];
	}

	interface StationItem {
		id: number;
		program_id: number;
		name: string;
		capacity: number;
		client_capacity?: number;
		priority_first_override?: boolean | null;
		is_active: boolean;
		created_at: string | null;
	}

	interface ProgramStats {
		total_sessions: number;
		active_sessions: number;
		completed_sessions: number;
	}

	let { program, tracks = [], stations = [], stats = { total_sessions: 0, active_sessions: 0, completed_sessions: 0 } }: {
		program: ProgramItem;
		tracks: TrackItem[];
		stations: StationItem[];
		stats?: ProgramStats;
	} = $props();

	let activeTab = $state<'tracks' | 'stations' | 'overview' | 'settings' | 'staff'>('overview');
	let showCreateModal = $state(false);
	let editTrack = $state<TrackItem | null>(null);
	let createName = $state('');
	let createDescription = $state('');
	let createIsDefault = $state(false);
	let createColorCode = $state('');
	let editName = $state('');
	let editDescription = $state('');
	let editIsDefault = $state(false);
	let editColorCode = $state('');
	let showCreateStationModal = $state(false);
	let editStation = $state<StationItem | null>(null);
	let createStationName = $state('');
	let createStationCapacity = $state(1);
	let createStationClientCapacity = $state(1);
	let editStationName = $state('');
	let editStationCapacity = $state(1);
	let editStationClientCapacity = $state(1);
	let editStationPriorityFirstOverride = $state<boolean | null>(null);
	let editStationIsActive = $state(true);
	let showStepModal = $state(false);
	let stepModalTrack = $state<TrackItem | null>(null);
	/** Steps shown in the modal; updated in place on add/delete/reorder so modal stays realtime */
	let modalSteps = $state<StepItem[]>([]);
	let addStepStationId = $state<number | ''>('');
	let addStepEstimatedMinutes = $state<number | ''>('');
	let editingStepId = $state<number | null>(null);
	let editStepStationId = $state<number | ''>('');
	let editStepEstimatedMinutes = $state<number | ''>('');
	let editStepIsRequired = $state(true);
	let submitting = $state(false);
	let error = $state('');
	let settingsNoShowTimer = $state(10);
	let settingsRequireOverride = $state(true);
	let settingsPriorityFirst = $state(true);
	let settingsBalanceMode = $state<'fifo' | 'alternate'>('fifo');
	let settingsAlternateRatioP = $state(2);
	let settingsAlternateRatioR = $state(1);

	$effect(() => {
		const s = program?.settings;
		if (s) {
			settingsNoShowTimer = s.no_show_timer_seconds ?? 10;
			settingsRequireOverride = s.require_permission_before_override ?? true;
			settingsPriorityFirst = s.priority_first ?? true;
			settingsBalanceMode = (s.balance_mode === 'alternate' ? 'alternate' : 'fifo') as 'fifo' | 'alternate';
			const ar = s.alternate_ratio ?? [2, 1];
			settingsAlternateRatioP = ar[0] ?? 2;
			settingsAlternateRatioR = ar[1] ?? 1;
		}
	});

	$effect(() => {
		if (activeTab !== 'staff' || !program?.id) return;
		let cancelled = false;
		staffLoading = true;
		Promise.all([
			fetch(`/api/admin/programs/${program.id}/staff-assignments`, {
				headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
				credentials: 'same-origin'
			}).then((r) => r.json()),
			fetch(`/api/admin/programs/${program.id}/supervisors`, {
				headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
				credentials: 'same-origin'
			}).then((r) => r.json())
		]).then(([assignRes, superRes]) => {
			if (cancelled) return;
			staffAssignments = assignRes.assignments ?? [];
			staffStations = assignRes.stations ?? [];
			staffSupervisors = superRes.supervisors ?? [];
			staffWithPin = superRes.staff_with_pin ?? [];
		}).catch(() => {
			if (!cancelled) staffAssignments = [];
		}).finally(() => {
			if (!cancelled) staffLoading = false;
		});
		return () => { cancelled = true; };
	});
	let showReorderConfirm = $state(false);
	let pendingReorderStepIds = $state<number[]>([]);
	let reorderScope = $state<'new_only' | 'migrate'>('new_only');
	let confirmStopProgram = $state(false);
	let confirmDeleteTrack = $state<TrackItem | null>(null);
	let confirmDeleteStation = $state<StationItem | null>(null);
	let confirmRemoveStep = $state<{ step: StepItem; track: TrackItem } | null>(null);
	// Staff tab state
	let staffAssignments = $state<Array<{ user_id: number; user: { id: number; name: string; email: string }; station_id: number | null; station: { id: number; name: string } | null }>>([]);
	let staffStations = $state<Array<{ id: number; name: string }>>([]);
	let staffSupervisors = $state<Array<{ id: number; name: string; email: string }>>([]);
	let staffWithPin = $state<Array<{ id: number; name: string; email: string; is_supervisor: boolean }>>([]);
	let staffLoading = $state(false);
	let staffAssigningUserId = $state<number | null>(null);
	const page = usePage();
	function getCsrfToken(): string {
		const p = get(page);
		const fromProps = (p?.props as { csrf_token?: string } | undefined)?.csrf_token;
		if (fromProps) return fromProps;
		const meta = typeof document !== 'undefined' ? (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content : '';
		return meta ?? '';
	}

	async function api(
		method: string,
		url: string,
		body?: object
	): Promise<{ ok: boolean; data?: object; message?: string }> {
		const res = await fetch(url, {
			method,
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
				'X-CSRF-TOKEN': getCsrfToken(),
				'X-Requested-With': 'XMLHttpRequest'
			},
			credentials: 'same-origin',
			...(body ? { body: JSON.stringify(body) } : {})
		});
		const data = await res.json().catch(() => ({}));
		return { ok: res.ok, data, message: data?.message };
	}

	async function handlePause() {
		submitting = true;
		error = '';
		const { ok, message } = await api('POST', `/api/admin/programs/${program.id}/pause`);
		submitting = false;
		if (ok) router.reload();
		else error = message ?? 'Failed to pause.';
	}

	async function handleResume() {
		submitting = true;
		error = '';
		const { ok, message } = await api('POST', `/api/admin/programs/${program.id}/resume`);
		submitting = false;
		if (ok) router.reload();
		else error = message ?? 'Failed to resume.';
	}

	async function handleActivate() {
		submitting = true;
		error = '';
		const { ok, message } = await api('POST', `/api/admin/programs/${program.id}/activate`);
		submitting = false;
		if (ok) router.reload();
		else error = message ?? 'Failed to start session.';
	}

	function openStopConfirm() {
		confirmStopProgram = true;
		error = '';
	}

	async function handleStopConfirm() {
		submitting = true;
		error = '';
		const { ok, message } = await api('POST', `/api/admin/programs/${program.id}/deactivate`);
		submitting = false;
		if (ok) {
			confirmStopProgram = false;
			router.reload();
		} else {
			error = message ?? 'You can only stop the session when no clients are in the queue.';
		}
	}

	function closeStopConfirm() {
		confirmStopProgram = false;
	}

	function openCreate() {
		createName = '';
		createDescription = '';
		createIsDefault = false;
		createColorCode = '';
		error = '';
		showCreateModal = true;
	}

	function openEdit(t: TrackItem) {
		editTrack = t;
		editName = t.name;
		editDescription = t.description ?? '';
		editIsDefault = t.is_default;
		editColorCode = t.color_code ?? '';
		error = '';
	}

	function openStepModal(t: TrackItem) {
		stepModalTrack = t;
		modalSteps = stepList(t);
		addStepStationId = '';
		addStepEstimatedMinutes = '';
		editingStepId = null;
		error = '';
		showStepModal = true;
	}

	function openEditStep(step: StepItem) {
		editingStepId = step.id;
		editStepStationId = step.station_id;
		editStepEstimatedMinutes = step.estimated_minutes ?? '';
		editStepIsRequired = step.is_required;
		error = '';
	}

	function cancelEditStep() {
		editingStepId = null;
	}

	async function handleUpdateStep() {
		if (editingStepId == null) return;
		submitting = true;
		error = '';
		const body: { station_id?: number; estimated_minutes?: number | null; is_required?: boolean } = {};
		if (editStepStationId !== '') body.station_id = editStepStationId as number;
		body.estimated_minutes = editStepEstimatedMinutes === '' ? null : Number(editStepEstimatedMinutes);
		body.is_required = editStepIsRequired;
		const { ok, data, message } = await api('PUT', `/api/admin/steps/${editingStepId}`, body);
		submitting = false;
		if (ok && data?.step) {
			const updated = data.step as StepItem;
			modalSteps = modalSteps.map((s) => (s.id === updated.id ? updated : s));
			editingStepId = null;
		} else {
			error = message ?? 'Failed to update step.';
		}
	}

	function closeStepModal() {
		showStepModal = false;
		stepModalTrack = null;
		modalSteps = [];
		error = '';
		router.reload();
	}

	function closeModals() {
		showCreateModal = false;
		editTrack = null;
		showCreateStationModal = false;
		editStation = null;
		error = '';
	}

	async function handleCreate() {
		if (!createName.trim()) return;
		submitting = true;
		error = '';
		const { ok, data, message } = await api('POST', `/api/admin/programs/${program.id}/tracks`, {
			name: createName.trim(),
			description: createDescription.trim() || null,
			is_default: createIsDefault,
			color_code: createColorCode.trim() || null
		});
		submitting = false;
		if (ok) {
			closeModals();
			router.reload();
		} else {
			error = message ?? (data && 'errors' in data ? JSON.stringify((data as { errors?: Record<string, string[]> }).errors) : 'Failed to create track.');
		}
	}

	async function handleUpdate() {
		if (!editTrack || !editName.trim()) return;
		submitting = true;
		error = '';
		const { ok, message } = await api('PUT', `/api/admin/tracks/${editTrack.id}`, {
			name: editName.trim(),
			description: editDescription.trim() || null,
			is_default: editIsDefault,
			color_code: editColorCode.trim() || null
		});
		submitting = false;
		if (ok) {
			closeModals();
			router.reload();
		} else {
			error = message ?? 'Failed to update track.';
		}
	}

	function openDeleteTrackConfirm(t: TrackItem) {
		confirmDeleteTrack = t;
		error = '';
	}

	async function handleDeleteTrackConfirm() {
		if (!confirmDeleteTrack) return;
		const t = confirmDeleteTrack;
		submitting = true;
		error = '';
		const { ok, message } = await api('DELETE', `/api/admin/tracks/${t.id}`);
		submitting = false;
		if (ok) {
			confirmDeleteTrack = null;
			router.reload();
		} else {
			error = message ?? 'Cannot delete: active sessions use this track.';
		}
	}

	function closeDeleteTrackConfirm() {
		confirmDeleteTrack = null;
	}

	function openCreateStation() {
		createStationName = '';
		createStationCapacity = 1;
		createStationClientCapacity = 1;
		error = '';
		showCreateStationModal = true;
	}

	function openEditStation(s: StationItem) {
		editStation = s;
		editStationName = s.name;
		editStationCapacity = s.capacity;
		editStationClientCapacity = s.client_capacity ?? 1;
		editStationPriorityFirstOverride = s.priority_first_override ?? null;
		editStationIsActive = s.is_active;
		error = '';
	}

	async function handleCreateStation() {
		if (!createStationName.trim()) return;
		submitting = true;
		error = '';
		const { ok, message } = await api('POST', `/api/admin/programs/${program.id}/stations`, {
			name: createStationName.trim(),
			capacity: createStationCapacity,
			client_capacity: createStationClientCapacity
		});
		submitting = false;
		if (ok) {
			closeModals();
			router.reload();
		} else {
			error = message ?? 'Failed to create station.';
		}
	}

	async function handleUpdateStation() {
		if (!editStation || !editStationName.trim()) return;
		submitting = true;
		error = '';
		const { ok, message } = await api('PUT', `/api/admin/stations/${editStation.id}`, {
			name: editStationName.trim(),
			capacity: editStationCapacity,
			client_capacity: editStationClientCapacity,
			priority_first_override: editStationPriorityFirstOverride,
			is_active: editStationIsActive
		});
		submitting = false;
		if (ok) {
			closeModals();
			router.reload();
		} else {
			error = message ?? 'Failed to update station.';
		}
	}

	function openDeleteStationConfirm(s: StationItem) {
		confirmDeleteStation = s;
		error = '';
	}

	async function handleDeleteStationConfirm() {
		if (!confirmDeleteStation) return;
		const s = confirmDeleteStation;
		submitting = true;
		error = '';
		const { ok, message } = await api('DELETE', `/api/admin/stations/${s.id}`);
		submitting = false;
		if (ok) {
			confirmDeleteStation = null;
			router.reload();
		} else {
			error = message ?? 'Cannot delete: station is used in track steps.';
		}
	}

	function closeDeleteStationConfirm() {
		confirmDeleteStation = null;
	}

	async function handleToggleStationActive(s: StationItem) {
		submitting = true;
		error = '';
		const { ok, message } = await api('PUT', `/api/admin/stations/${s.id}`, {
			name: s.name,
			capacity: s.capacity,
			client_capacity: s.client_capacity ?? 1,
			is_active: !s.is_active
		});
		submitting = false;
		if (ok) {
			router.reload();
		} else {
			error = message ?? 'Failed to update station.';
		}
	}

	async function handleAddStep() {
		if (!stepModalTrack || addStepStationId === '') return;
		submitting = true;
		error = '';
		const body: { station_id: number; estimated_minutes?: number } = { station_id: addStepStationId as number };
		if (addStepEstimatedMinutes !== '') body.estimated_minutes = addStepEstimatedMinutes as number;
		const { ok, data, message } = await api('POST', `/api/admin/tracks/${stepModalTrack.id}/steps`, body);
		submitting = false;
		if (ok && data?.step) {
			const s = data.step as StepItem;
			modalSteps = [...modalSteps, s].sort((a, b) => a.step_order - b.step_order);
			addStepStationId = '';
			addStepEstimatedMinutes = '';
		} else {
			error = message ?? 'Failed to add step.';
		}
	}

	function openRemoveStepConfirm(step: StepItem) {
		if (!stepModalTrack) return;
		confirmRemoveStep = { step, track: stepModalTrack };
		error = '';
	}

	async function handleRemoveStepConfirm() {
		if (!confirmRemoveStep) return;
		const { step } = confirmRemoveStep;
		submitting = true;
		error = '';
		const { ok, message } = await api('DELETE', `/api/admin/steps/${step.id}`);
		submitting = false;
		if (ok) {
			modalSteps = modalSteps.filter((s) => s.id !== step.id);
			confirmRemoveStep = null;
		} else {
			error = message ?? 'Failed to delete step.';
		}
	}

	function closeRemoveStepConfirm() {
		confirmRemoveStep = null;
	}

	function stepList(t: TrackItem): StepItem[] {
		const s = (t.steps ?? []).slice().sort((a, b) => a.step_order - b.step_order);
		return s;
	}

	async function handleMoveStepUp(step: StepItem) {
		if (!stepModalTrack) return;
		const list = [...modalSteps];
		const i = list.findIndex((s) => s.id === step.id);
		if (i <= 0) return;
		[list[i - 1], list[i]] = [list[i], list[i - 1]];
		const stepIds = list.map((s) => s.id);
		const activeCount = stepModalTrack.active_sessions_count ?? 0;
		if (activeCount > 0) {
			pendingReorderStepIds = stepIds;
			reorderScope = 'new_only';
			showReorderConfirm = true;
		} else {
			await submitReorder(stepModalTrack, stepIds, false);
		}
	}

	async function handleMoveStepDown(step: StepItem) {
		if (!stepModalTrack) return;
		const list = [...modalSteps];
		const i = list.findIndex((s) => s.id === step.id);
		if (i < 0 || i >= list.length - 1) return;
		[list[i], list[i + 1]] = [list[i + 1], list[i]];
		const stepIds = list.map((s) => s.id);
		const activeCount = stepModalTrack.active_sessions_count ?? 0;
		if (activeCount > 0) {
			pendingReorderStepIds = stepIds;
			reorderScope = 'new_only';
			showReorderConfirm = true;
		} else {
			await submitReorder(stepModalTrack, stepIds, false);
		}
	}

	function closeReorderConfirm() {
		showReorderConfirm = false;
		pendingReorderStepIds = [];
		reorderScope = 'new_only';
	}

	async function confirmReorderApply() {
		if (!stepModalTrack) return;
		await submitReorder(stepModalTrack, pendingReorderStepIds, reorderScope === 'migrate');
		closeReorderConfirm();
	}

	async function submitReorder(t: TrackItem, stepIds: number[], migrateSessions: boolean = false) {
		submitting = true;
		error = '';
		const { ok, data, message } = await api('POST', `/api/admin/tracks/${t.id}/steps/reorder`, {
			step_ids: stepIds,
			migrate_sessions: migrateSessions
		});
		submitting = false;
		if (ok && data?.steps) {
			modalSteps = data.steps as StepItem[];
		} else {
			error = message ?? 'Failed to reorder steps.';
		}
	}

	async function handleSaveSettings() {
		submitting = true;
		error = '';
		const { ok, message } = await api('PUT', `/api/admin/programs/${program.id}`, {
			name: program.name,
			description: program.description,
			settings: {
				no_show_timer_seconds: settingsNoShowTimer,
				require_permission_before_override: settingsRequireOverride,
				priority_first: settingsPriorityFirst,
				balance_mode: settingsBalanceMode,
				alternate_ratio: [settingsAlternateRatioP, settingsAlternateRatioR]
			}
		});
		submitting = false;
		if (ok) router.reload();
		else error = message ?? 'Failed to save settings.';
	}

	async function handleAssignStaff(userId: number, stationId: number | null) {
		staffAssigningUserId = userId;
		error = '';
		if (stationId === null) {
			const { ok, message: msg } = await api('DELETE', `/api/admin/programs/${program.id}/staff-assignments/${userId}`);
			if (ok) {
				staffAssignments = staffAssignments.map((a) =>
					a.user_id === userId ? { ...a, station_id: null, station: null } : a
				);
			} else error = msg ?? 'Failed to unassign.';
		} else {
			const { ok, message: msg } = await api('POST', `/api/admin/programs/${program.id}/staff-assignments`, {
				user_id: userId,
				station_id: stationId
			});
			if (ok) {
				const station = staffStations.find((s) => s.id === stationId);
				staffAssignments = staffAssignments.map((a) =>
					a.user_id === userId
						? { ...a, station_id: stationId, station: station ? { id: station.id, name: station.name } : null }
						: a
				);
			} else error = msg ?? 'Failed to assign.';
		}
		staffAssigningUserId = null;
	}

	async function handleAddSupervisor(userId: number) {
		error = '';
		const { ok, message: msg } = await api('POST', `/api/admin/programs/${program.id}/supervisors`, { user_id: userId });
		if (ok) {
			const u = staffWithPin.find((u) => u.id === userId);
			if (u) {
				staffSupervisors = [...staffSupervisors, { id: u.id, name: u.name, email: u.email }];
				staffWithPin = staffWithPin.map((x) => (x.id === userId ? { ...x, is_supervisor: true } : x));
			}
		} else error = msg ?? 'Failed to add supervisor.';
	}

	async function handleRemoveSupervisor(userId: number) {
		error = '';
		const removed = staffSupervisors.find((s) => s.id === userId);
		const { ok, message: msg } = await api('DELETE', `/api/admin/programs/${program.id}/supervisors/${userId}`);
		if (ok) {
			staffSupervisors = staffSupervisors.filter((s) => s.id !== userId);
			const inList = staffWithPin.some((u) => u.id === userId);
			if (inList) {
				staffWithPin = staffWithPin.map((x) => (x.id === userId ? { ...x, is_supervisor: false } : x));
			} else if (removed) {
				// Ensure removed supervisor can be re-added from the "Add supervisor" list
				staffWithPin = [...staffWithPin, { id: removed.id, name: removed.name, email: removed.email, is_supervisor: false }];
			}
		} else error = msg ?? 'Failed to remove supervisor.';
	}

	function formatDate(iso: string | null): string {
		if (!iso) return '';
		try {
			return new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' });
		} catch {
			return iso;
		}
	}
</script>

<svelte:head>
	<title>{program.name} — Programs — FlexiQueue</title>
</svelte:head>

<AdminLayout>
	<div class="flex flex-col gap-6">
		<!-- Back link -->
		<div>
			<Link href="/admin/programs" class="btn preset-tonal btn-sm gap-1.5 text-surface-950">
				<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
				</svg>
				Programs
			</Link>
		</div>

		<!-- Program header -->
		<div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
			<div>
				<h1 class="text-2xl font-semibold text-surface-950">{program.name}</h1>
				<p class="text-sm text-surface-600 mt-1 flex items-center gap-2 flex-wrap">
					{#if program.is_active}
						<span class="text-xs px-2 py-0.5 rounded preset-filled-success-500">Active</span>
					{:else}
						<span class="text-xs px-2 py-0.5 rounded preset-tonal text-surface-950">Inactive</span>
					{/if}
					<span>Created {formatDate(program.created_at)}</span>
				</p>
				{#if program.description}
					<p class="mt-2 text-surface-600">{program.description}</p>
				{/if}
			</div>
		</div>

		<!-- Status banner + primary actions -->
		{#if program.is_active && !program.is_paused}
			<div class="rounded-container border border-success-300 bg-success-50 p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
				<p class="font-medium text-success-900">
					Program is LIVE — Queue times are being recorded.
				</p>
				<div class="flex flex-wrap gap-3">
					<Link
						href="/triage"
						class="btn preset-filled-primary-500 gap-2"
						title="Open triage to scan tokens and assign tracks"
					>
						<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
						</svg>
						Open Triage
					</Link>
					<button type="button" class="btn preset-tonal gap-2" disabled={submitting} onclick={handlePause}>
						Pause
					</button>
					<button type="button" class="btn preset-tonal gap-2" disabled={submitting} onclick={openStopConfirm} aria-label="Stop session">
						Stop session
					</button>
				</div>
			</div>
		{:else if program.is_active && program.is_paused}
			<div class="rounded-container border border-warning-300 bg-warning-50 p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
				<p class="font-medium text-warning-900">
					Program is paused. Queue times are not being recorded.
				</p>
				<button type="button" class="btn preset-filled-primary-500 gap-2 shrink-0" disabled={submitting} onclick={handleResume}>
					Resume
				</button>
			</div>
		{:else}
			<div class="rounded-container border border-surface-200 bg-surface-50 p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
				<p class="font-medium text-surface-950">
					Program is inactive. Start a session to begin routing clients.
				</p>
				<button type="button" class="btn preset-filled-primary-500 gap-2 shrink-0" disabled={submitting} onclick={handleActivate}>
					<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
					</svg>
					Start session
				</button>
			</div>
		{/if}

		<!-- Tab navigation (BD-009, BD-010) — Skeleton-compatible tabs, Overview first -->
		<div role="tablist" class="tabs">
			<button
				type="button"
				role="tab"
				class="tab"
				class:tab-active={activeTab === 'overview'}
				onclick={() => (activeTab = 'overview')}
			>
				Overview
			</button>
			<button
				type="button"
				role="tab"
				class="tab"
				class:tab-active={activeTab === 'tracks'}
				onclick={() => (activeTab = 'tracks')}
			>
				Tracks
			</button>
			<button
				type="button"
				role="tab"
				class="tab"
				class:tab-active={activeTab === 'stations'}
				onclick={() => (activeTab = 'stations')}
			>
				Stations
			</button>
			<button
				type="button"
				role="tab"
				class="tab"
				class:tab-active={activeTab === 'staff'}
				onclick={() => (activeTab = 'staff')}
			>
				Staff
			</button>
			<button
				type="button"
				role="tab"
				class="tab"
				class:tab-active={activeTab === 'settings'}
				onclick={() => (activeTab = 'settings')}
			>
				Settings
			</button>
		</div>

		{#if error}
			<div class="bg-error-100 text-error-900 border border-error-300 rounded-container p-4" role="alert">
				<span>{error}</span>
				<button type="button" class="btn preset-tonal btn-sm" onclick={() => (error = '')}>Dismiss</button>
			</div>
		{/if}

		{#if activeTab === 'overview'}
			<div class="space-y-8">
				<section>
					<h2 class="text-lg font-semibold text-surface-950 mb-4">Program stats</h2>
					<div class="grid gap-4 sm:grid-cols-3">
						<div class="rounded-container bg-surface-50 border border-surface-200 p-4">
							<p class="text-2xl font-bold text-surface-950">{stats.total_sessions}</p>
							<p class="text-sm text-surface-600">Total sessions</p>
						</div>
						<div class="rounded-container bg-surface-50 border border-surface-200 p-4">
							<p class="text-2xl font-bold text-primary-500">{stats.active_sessions}</p>
							<p class="text-sm text-surface-600">Active in queue</p>
						</div>
						<div class="rounded-container bg-surface-50 border border-surface-200 p-4">
							<p class="text-2xl font-bold text-surface-950">{stats.completed_sessions}</p>
							<p class="text-sm text-surface-600">Completed / Cancelled / No-show</p>
						</div>
					</div>
				</section>
				<section>
					<h2 class="text-lg font-semibold text-surface-950 mb-4">Track flow</h2>
					<FlowDiagram {tracks} />
				</section>
			</div>
		{:else if activeTab === 'tracks'}
			<div class="flex flex-wrap items-center justify-between gap-2">
				<h2 class="text-lg font-semibold text-surface-950">Tracks</h2>
				<button type="button" class="btn preset-filled-primary-500 btn-sm" onclick={openCreate}>+ Add Track</button>
			</div>
			{#if tracks.length === 0}
				<div class="rounded-box bg-surface-50 border border-surface-200 p-8 text-center text-surface-950/70">
					<p>No tracks yet. Add a track to define a service lane (e.g. Regular, Priority).</p>
				</div>
			{:else}
				<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
					{#each tracks as track (track.id)}
						<div class="card bg-surface-50 rounded-container elevation-card">
							<div class="card-body">
								<div class="flex items-start justify-between gap-2">
									<div class="flex items-center gap-2">
										{#if track.color_code}
											<span
												class="inline-block h-4 w-4 rounded-full border border-surface-200 shrink-0"
												style="background-color: {track.color_code}"
												title="{track.color_code}"
											></span>
										{/if}
										<h3 class="card-title text-base">{track.name}</h3>
									</div>
									{#if track.is_default}
										<span class="text-xs px-2 py-0.5 rounded preset-filled-primary-500 badge-sm">Default</span>
									{/if}
								</div>
								{#if track.description}
									<p class="text-sm text-surface-950/70 line-clamp-2">{track.description}</p>
								{/if}
								{#if (track.steps ?? []).length > 0}
									<p class="text-sm text-surface-950/70">
										Steps: {(track.steps ?? []).map((s) => s.station_name).join(' → ')}
									</p>
								{/if}
								<div class="card-actions mt-2 justify-end flex-wrap gap-1">
									<button
										type="button"
										class="btn preset-tonal btn-sm"
										onclick={() => openStepModal(track)}
										disabled={submitting}
									>
										Manage steps
									</button>
									<button
										type="button"
										class="btn preset-tonal btn-sm"
										onclick={() => openEdit(track)}
										disabled={submitting}
									>
										Edit
									</button>
									<button
										type="button"
										class="btn preset-tonal btn-sm text-error"
										onclick={() => openDeleteTrackConfirm(track)}
										disabled={submitting}
									>
										Delete
									</button>
								</div>
							</div>
						</div>
					{/each}
				</div>
			{/if}
		{:else if activeTab === 'settings'}
			<!-- Settings tab — Skeleton theme colors, UI-friendly help text -->
			<div class="rounded-container bg-surface-50 border border-surface-200 p-6 max-w-xl">
				<h2 class="text-lg font-semibold text-surface-950 mb-4">Program settings</h2>
				<div class="space-y-6">
					<div class="form-control w-full">
						<label for="no-show-timer" class="label"><span class="label-text text-surface-950">No-show timer (seconds)</span></label>
						<input
							id="no-show-timer"
							type="number"
							class="input rounded-container border border-surface-200 px-3 py-2 w-full text-surface-950 bg-surface-50"
							min="5"
							max="120"
							bind:value={settingsNoShowTimer}
						/>
						<p class="mt-1.5 text-sm text-surface-600 pl-1 border-l-2 border-surface-300">
							How long to wait before staff can mark no-show. Default: 10 seconds.
						</p>
					</div>
					<div class="form-control">
						<label class="label cursor-pointer justify-start gap-2">
							<input type="checkbox" class="checkbox" bind:checked={settingsPriorityFirst} />
							<span class="label-text text-surface-950">Priority first (default)</span>
						</label>
						<p class="mt-1.5 text-sm text-surface-600 pl-1 border-l-2 border-surface-300">
							PWD / Senior / Pregnant clients are called before Regular. Individual stations can override.
						</p>
					</div>
					<div class="form-control w-full">
						<label for="balance-mode" class="label"><span class="label-text text-surface-950">When priority first is off: balance mode</span></label>
						<select id="balance-mode" class="select rounded-container border border-surface-200 px-3 py-2 w-full text-surface-950 bg-surface-50" bind:value={settingsBalanceMode}>
							<option value="fifo">FIFO — strict arrival order</option>
							<option value="alternate">Alternate — ratio of priority to regular</option>
						</select>
					</div>
					{#if settingsBalanceMode === 'alternate'}
						<div class="form-control w-full flex flex-row flex-wrap gap-2 items-center">
							<label class="label w-full"><span class="label-text text-surface-950">Alternate ratio (priority : regular)</span></label>
							<input type="number" class="input rounded-container border border-surface-200 px-3 py-2 w-16 text-surface-950 bg-surface-50" min="1" max="10" bind:value={settingsAlternateRatioP} />
							<span class="text-surface-950 font-medium">:</span>
							<input type="number" class="input rounded-container border border-surface-200 px-3 py-2 w-16 text-surface-950 bg-surface-50" min="1" max="10" bind:value={settingsAlternateRatioR} />
						</div>
					{/if}
					<div class="form-control">
						<label class="label cursor-pointer justify-start gap-2">
							<input type="checkbox" class="checkbox" bind:checked={settingsRequireOverride} />
							<span class="label-text text-surface-950">Require supervisor PIN before override (flow redirection)</span>
						</label>
						<p class="mt-1.5 text-sm text-surface-600 pl-1 border-l-2 border-surface-300">
							When enabled, staff must enter a supervisor PIN to redirect a client to a different station. Default: on.
						</p>
					</div>
					<div class="pt-2">
						<button type="button" class="btn preset-filled-primary-500" disabled={submitting} onclick={handleSaveSettings}>
							{submitting ? 'Saving…' : 'Save settings'}
						</button>
					</div>
				</div>
			</div>
		{:else if activeTab === 'staff'}
			<!-- Staff tab: station assignments + supervisors -->
			<div class="space-y-8">
				<section>
					<h2 class="text-lg font-semibold text-surface-950 mb-4">Station assignments</h2>
					<p class="text-sm text-surface-950/70 mb-4">
						Assign staff to stations for this program. Only staff role can be assigned.
					</p>
					{#if staffLoading}
						<div class="rounded-box bg-surface-50 border border-surface-200 p-8 text-center">Loading…</div>
					{:else if staffAssignments.length === 0}
						<div class="rounded-box bg-surface-50 border border-surface-200 p-8 text-center text-surface-950/70">
							No staff yet. Add staff accounts from the Staff page.
						</div>
					{:else if staffStations.length === 0}
						<div class="bg-warning-100 text-warning-900 border border-warning-300 rounded-container p-4">
							No stations in this program. Add stations first, then assign staff.
						</div>
					{:else}
						<div class="table-container">
							<table class="table table-zebra">
								<thead>
									<tr>
										<th>Staff</th>
										<th>Assigned station</th>
									</tr>
								</thead>
								<tbody>
									{#each staffAssignments as a (a.user_id)}
										<tr>
											<td>
												<div class="flex items-center gap-2 flex-wrap">
													<span class="font-medium">{a.user.name}</span>
													{#if staffSupervisors.some((s) => s.id === a.user_id)}
														<span class="text-xs px-2 py-0.5 rounded preset-filled-primary-500 badge-sm">Supervisor</span>
													{/if}
												</div>
												<span class="text-sm text-surface-950/60 block">{a.user.email}</span>
											</td>
											<td>
												<select
													class="select rounded-container border border-surface-200 px-3 py-2 select-sm max-w-xs"
													value={a.station_id ?? ''}
													disabled={staffAssigningUserId === a.user_id}
													onchange={(e) => {
														const val = (e.target as HTMLSelectElement).value;
														const sid = val === '' ? null : Number(val);
														handleAssignStaff(a.user_id, sid);
													}}
												>
													<option value="">— Unassigned —</option>
													{#each staffStations as s (s.id)}
														<option value={s.id}>{s.name}</option>
													{/each}
												</select>
												{#if (a.station_id == null) && staffSupervisors.some((s) => s.id === a.user_id)}
													<p class="text-xs text-surface-950/60 mt-1">Supervisor — can take charge without a station</p>
												{/if}
											</td>
										</tr>
									{/each}
								</tbody>
							</table>
						</div>
					{/if}
				</section>

				<section>
					<h2 class="text-lg font-semibold text-surface-950 mb-4">Supervisors</h2>
					<p class="text-sm text-surface-950/70 mb-4">
						Supervisors can approve flow overrides.
					</p>
					{#if staffLoading}
						<div class="rounded-box bg-surface-50 border border-surface-200 p-4 text-center">Loading…</div>
					{:else}
						<div class="space-y-4">
							<div class="rounded-box bg-surface-50 border border-surface-200 p-4">
								<h3 class="font-medium text-surface-950 mb-2">Current supervisors</h3>
								{#if staffSupervisors.length === 0}
									<p class="text-sm text-surface-950/70">None. Add staff with override PINs below.</p>
								{:else}
									<ul class="flex flex-wrap gap-2">
										{#each staffSupervisors as s (s.id)}
											<li class="text-xs px-2 py-0.5 rounded preset-filled-primary-500 badge-lg gap-1">
												{s.name}
												<button
													type="button"
													class="btn preset-tonal btn-xs"
													aria-label="Remove {s.name}"
													onclick={() => handleRemoveSupervisor(s.id)}
													disabled={submitting}
												>
													×
												</button>
											</li>
										{/each}
									</ul>
								{/if}
							</div>
							<div class="rounded-box bg-surface-50 border border-surface-200 p-4">
								<h3 class="font-medium text-surface-950 mb-2">Add supervisor (staff with PIN)</h3>
								{#if staffWithPin.filter((u) => !u.is_supervisor).length === 0}
									<p class="text-sm text-surface-950/70">No staff with override PIN left to add.</p>
								{:else}
									<ul class="flex flex-wrap gap-2">
										{#each staffWithPin.filter((u) => !u.is_supervisor) as u (u.id)}
											<li>
												<button
													type="button"
													class="btn preset-outlined btn-sm"
													onclick={() => handleAddSupervisor(u.id)}
													disabled={submitting}
												>
													+ {u.name}
												</button>
											</li>
										{/each}
									</ul>
								{/if}
							</div>
						</div>
					{/if}
				</section>
			</div>
		{:else}
			<!-- Stations tab (BD-010) -->
			<div class="flex flex-wrap items-center justify-between gap-2">
				<h2 class="text-lg font-semibold text-surface-950">Stations</h2>
				<button type="button" class="btn preset-filled-primary-500 btn-sm" onclick={openCreateStation}>+ Add Station</button>
			</div>
			{#if stations.length === 0}
				<div class="rounded-box bg-surface-50 border border-surface-200 p-8 text-center text-surface-950/70">
					<p>No stations yet. Add a station (e.g. Verification Desk, Cashier) for staff to serve clients.</p>
				</div>
			{:else}
				<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
					{#each stations as station (station.id)}
						<div class="card bg-surface-50 rounded-container elevation-card">
							<div class="card-body">
								<div class="flex items-start justify-between gap-2">
									<h3 class="card-title text-base">{station.name}</h3>
								</div>
								<p class="text-sm text-surface-950/70">
									Staff: {station.capacity} · Clients: {station.client_capacity ?? 1}
								</p>
								<div class="flex items-center gap-2">
									{#if station.is_active}
										<span class="text-xs px-2 py-0.5 rounded preset-filled-success-500 badge-sm">Active</span>
									{:else}
										<span class="text-xs px-2 py-0.5 rounded preset-tonal badge-sm">Inactive</span>
									{/if}
								</div>
								<div class="card-actions mt-2 justify-end flex-wrap gap-1">
									<button
										type="button"
										class="btn preset-tonal btn-sm"
										onclick={() => handleToggleStationActive(station)}
										disabled={submitting}
										title={station.is_active ? 'Deactivate' : 'Activate'}
									>
										{station.is_active ? 'Deactivate' : 'Activate'}
									</button>
									<button
										type="button"
										class="btn preset-tonal btn-sm"
										onclick={() => openEditStation(station)}
										disabled={submitting}
									>
										Edit
									</button>
									<button
										type="button"
										class="btn preset-tonal btn-sm text-error"
										onclick={() => openDeleteStationConfirm(station)}
										disabled={submitting}
									>
										Delete
									</button>
								</div>
							</div>
						</div>
					{/each}
				</div>
			{/if}
		{/if}
	</div>
</AdminLayout>

<Modal open={showCreateModal} title="Add Track" onClose={closeModals}>
	{#snippet children()}
		<form
			onsubmit={(e) => {
				e.preventDefault();
				handleCreate();
			}}
			class="flex flex-col gap-4"
		>
			<div class="form-control w-full">
				<label for="create-track-name" class="label"><span class="label-text">Name</span></label>
				<input
					id="create-track-name"
					type="text"
					class="input rounded-container border border-surface-200 px-3 py-2 w-full"
					placeholder="e.g. Priority Lane"
					maxlength="50"
					bind:value={createName}
					required
				/>
			</div>
			<div class="form-control w-full">
				<label for="create-track-desc" class="label"><span class="label-text">Description (optional)</span></label>
				<textarea
					id="create-track-desc"
					class="textarea rounded-container border border-surface-200 w-full"
					rows="2"
					placeholder="Brief description"
					bind:value={createDescription}
				></textarea>
			</div>
			<div class="form-control w-full">
				<label for="create-track-color" class="label"><span class="label-text">Color (optional, hex)</span></label>
				<input
					id="create-track-color"
					type="text"
					class="input rounded-container border border-surface-200 px-3 py-2 w-full"
					placeholder="#F59E0B"
					maxlength="7"
					bind:value={createColorCode}
				/>
			</div>
			<div class="form-control">
				<label class="label cursor-pointer justify-start gap-2">
					<input type="checkbox" class="checkbox checkbox-sm" bind:checked={createIsDefault} />
					<span class="label-text">Default track (exactly one per program)</span>
				</label>
			</div>
			<div class="flex justify-end gap-2">
				<button type="button" class="btn preset-tonal" onclick={closeModals}>Cancel</button>
				<button type="submit" class="btn preset-filled-primary-500" disabled={submitting || !createName.trim()}>
					{submitting ? 'Creating…' : 'Create'}
				</button>
			</div>
		</form>
	{/snippet}
</Modal>

{#if editTrack}
	<Modal
		open={!!editTrack}
		title="Edit Track"
		onClose={closeModals}
	>
		{#snippet children()}
			<form
				onsubmit={(e) => {
					e.preventDefault();
					handleUpdate();
				}}
				class="flex flex-col gap-4"
			>
				<div class="form-control w-full">
					<label for="edit-track-name" class="label"><span class="label-text">Name</span></label>
					<input
						id="edit-track-name"
						type="text"
						class="input rounded-container border border-surface-200 px-3 py-2 w-full"
						maxlength="50"
						bind:value={editName}
						required
					/>
				</div>
				<div class="form-control w-full">
					<label for="edit-track-desc" class="label"><span class="label-text">Description (optional)</span></label>
					<textarea
						id="edit-track-desc"
						class="textarea rounded-container border border-surface-200 w-full"
						rows="2"
						bind:value={editDescription}
					></textarea>
				</div>
				<div class="form-control w-full">
					<label for="edit-track-color" class="label"><span class="label-text">Color (optional, hex)</span></label>
					<input
						id="edit-track-color"
						type="text"
						class="input rounded-container border border-surface-200 px-3 py-2 w-full"
						placeholder="#F59E0B"
						maxlength="7"
						bind:value={editColorCode}
					/>
				</div>
				<div class="form-control">
					<label class="label cursor-pointer justify-start gap-2">
						<input type="checkbox" class="checkbox checkbox-sm" bind:checked={editIsDefault} />
						<span class="label-text">Default track</span>
					</label>
				</div>
				<div class="flex justify-end gap-2">
					<button type="button" class="btn preset-tonal" onclick={closeModals}>Cancel</button>
					<button type="submit" class="btn preset-filled-primary-500" disabled={submitting || !editName.trim()}>
						{submitting ? 'Saving…' : 'Save'}
					</button>
				</div>
			</form>
		{/snippet}
	</Modal>
{/if}

<Modal open={showCreateStationModal} title="Add Station" onClose={closeModals}>
	{#snippet children()}
		<form
			onsubmit={(e) => {
				e.preventDefault();
				handleCreateStation();
			}}
			class="flex flex-col gap-4"
		>
			<div class="form-control w-full">
				<label for="create-station-name" class="label"><span class="label-text">Name</span></label>
				<input
					id="create-station-name"
					type="text"
					class="input rounded-container border border-surface-200 px-3 py-2 w-full"
					placeholder="e.g. Verification Desk"
					maxlength="50"
					bind:value={createStationName}
					required
				/>
			</div>
			<div class="form-control w-full">
				<label for="create-station-capacity" class="label"><span class="label-text">Staff capacity</span></label>
				<input
					id="create-station-capacity"
					type="number"
					min="1"
					class="input rounded-container border border-surface-200 px-3 py-2 w-full"
					bind:value={createStationCapacity}
					required
				/>
			</div>
			<div class="form-control w-full">
				<label for="create-station-client-capacity" class="label"><span class="label-text">Client capacity</span></label>
				<input
					id="create-station-client-capacity"
					type="number"
					min="1"
					class="input rounded-container border border-surface-200 px-3 py-2 w-full"
					placeholder="Chairs/seats at station"
					bind:value={createStationClientCapacity}
					required
				/>
			</div>
			<div class="flex justify-end gap-2">
				<button type="button" class="btn preset-tonal" onclick={closeModals}>Cancel</button>
				<button type="submit" class="btn preset-filled-primary-500" disabled={submitting || !createStationName.trim()}>
					{submitting ? 'Creating…' : 'Create'}
				</button>
			</div>
		</form>
	{/snippet}
</Modal>

{#if editStation}
	<Modal open={!!editStation} title="Edit Station" onClose={closeModals}>
		{#snippet children()}
			<form
				onsubmit={(e) => {
					e.preventDefault();
					handleUpdateStation();
				}}
				class="flex flex-col gap-4"
			>
				<div class="form-control w-full">
					<label for="edit-station-name" class="label"><span class="label-text">Name</span></label>
					<input
						id="edit-station-name"
						type="text"
						class="input rounded-container border border-surface-200 px-3 py-2 w-full"
						maxlength="50"
						bind:value={editStationName}
						required
					/>
				</div>
				<div class="form-control w-full">
					<label for="edit-station-capacity" class="label"><span class="label-text">Staff capacity</span></label>
					<input
						id="edit-station-capacity"
						type="number"
						min="1"
						class="input rounded-container border border-surface-200 px-3 py-2 w-full"
						bind:value={editStationCapacity}
						required
					/>
				</div>
				<div class="form-control w-full">
					<label for="edit-station-client-capacity" class="label"><span class="label-text">Client capacity</span></label>
					<input
						id="edit-station-client-capacity"
						type="number"
						min="1"
						class="input rounded-container border border-surface-200 px-3 py-2 w-full"
						bind:value={editStationClientCapacity}
						required
					/>
				</div>
				<div class="form-control">
					<label for="edit-priority-override" class="label"><span class="label-text">Priority first override</span></label>
					<select
						id="edit-priority-override"
						class="select rounded-container border border-surface-200 px-3 py-2 w-full"
						value={editStationPriorityFirstOverride === null ? 'default' : editStationPriorityFirstOverride ? 'true' : 'false'}
						onchange={(e) => {
							const v = (e.target as HTMLSelectElement).value;
							editStationPriorityFirstOverride = v === 'default' ? null : v === 'true';
						}}
					>
						<option value="default">Use program default</option>
						<option value="true">Yes (priority lane first)</option>
						<option value="false">No (FIFO/alternate)</option>
					</select>
					<span class="label-text-alt">Override program's priority-first setting for this station</span>
				</div>
				<div class="form-control">
					<label class="label cursor-pointer justify-start gap-2">
						<input type="checkbox" class="checkbox checkbox-sm" bind:checked={editStationIsActive} />
						<span class="label-text">Active</span>
					</label>
				</div>
				<div class="flex justify-end gap-2">
					<button type="button" class="btn preset-tonal" onclick={closeModals}>Cancel</button>
					<button type="submit" class="btn preset-filled-primary-500" disabled={submitting || !editStationName.trim()}>
						{submitting ? 'Saving…' : 'Save'}
					</button>
				</div>
			</form>
		{/snippet}
	</Modal>
{/if}

{#if stepModalTrack}
	<Modal open={!!stepModalTrack} title="Steps: {stepModalTrack.name}" onClose={closeStepModal}>
		{#snippet children()}
			<div class="flex flex-col gap-4">
				{#if error}
					<div class="bg-error-100 text-error-900 border border-error-300 rounded-container p-4 text-sm" role="alert">
						<span>{error}</span>
						<button type="button" class="btn preset-tonal btn-xs" onclick={() => (error = '')}>Dismiss</button>
					</div>
				{/if}
				<div class="text-sm text-surface-950/70">Define the station sequence for this track. Clients will follow this order.</div>
				<ul class="flex flex-col gap-2">
					{#each modalSteps as step, i (step.id)}
						<li class="rounded-lg border border-surface-200 bg-surface-100 p-2">
							{#if editingStepId === step.id}
								<div class="flex flex-col gap-2">
									<div class="flex items-center gap-2">
										<span class="font-medium text-surface-950/80">{step.step_order}.</span>
										<span class="text-sm text-surface-950/70">Edit step</span>
									</div>
									<div class="grid gap-2 sm:grid-cols-[1fr_auto_auto_auto]">
										<div class="form-control min-w-0">
											<label for="edit-step-station-{step.id}" class="label py-0"><span class="label-text text-xs">Station</span></label>
											<select
												id="edit-step-station-{step.id}"
												class="select rounded-container border border-surface-200 px-3 py-2 select-sm w-full"
												bind:value={editStepStationId}
												aria-label="Station"
											>
												{#each stations as st}
													<option value={st.id}>{st.name}</option>
												{/each}
											</select>
										</div>
										<div class="form-control w-28">
											<label for="edit-step-min-{step.id}" class="label py-0"><span class="label-text text-xs">Est. time (min)</span></label>
											<input
												id="edit-step-min-{step.id}"
												type="number"
												min="0"
												max="120"
												class="input rounded-container border border-surface-200 px-3 py-2 input-sm w-full"
												placeholder="Optional"
												bind:value={editStepEstimatedMinutes}
												aria-label="Estimated time in minutes"
											/>
										</div>
										<div class="form-control justify-end">
											<label class="label cursor-pointer justify-start gap-2 py-0" for="edit-step-required-{step.id}">
												<input
													id="edit-step-required-{step.id}"
													type="checkbox"
													class="checkbox checkbox-sm"
													bind:checked={editStepIsRequired}
													aria-label="Required step"
												/>
												<span class="label-text text-xs">Required</span>
											</label>
										</div>
										<div class="flex items-end gap-1">
											<button type="button" class="btn preset-filled-primary-500 btn-sm" onclick={handleUpdateStep} disabled={submitting || editStepStationId === ''}>Save</button>
											<button type="button" class="btn preset-tonal btn-sm" onclick={cancelEditStep} disabled={submitting}>Cancel</button>
										</div>
									</div>
								</div>
							{:else}
								<div class="flex items-center gap-2">
									<span class="font-medium text-surface-950/80">{step.step_order}.</span>
									<span class="flex-1">{step.station_name}</span>
									{#if step.estimated_minutes != null}
										<span class="text-xs text-surface-950/60" title="Estimated time">~{step.estimated_minutes} min</span>
									{:else}
										<span class="text-xs text-surface-950/50">—</span>
									{/if}
									{#if step.is_required}
										<span class="text-xs px-2 py-0.5 rounded preset-tonal badge-xs">Required</span>
									{/if}
									<div class="flex gap-1">
										<button type="button" class="btn preset-tonal btn-xs" onclick={() => openEditStep(step)} disabled={submitting || editingStepId != null} title="Edit step">Edit</button>
										<button type="button" class="btn preset-tonal btn-xs" onclick={() => handleMoveStepUp(step)} disabled={submitting || i === 0} title="Move up">↑</button>
										<button type="button" class="btn preset-tonal btn-xs" onclick={() => handleMoveStepDown(step)} disabled={submitting || i === modalSteps.length - 1} title="Move down">↓</button>
										<button type="button" class="btn preset-tonal btn-xs text-error" onclick={() => openRemoveStepConfirm(step)} disabled={submitting} title="Remove step">Delete</button>
									</div>
								</div>
							{/if}
						</li>
					{/each}
				</ul>
				<div class="border-t border-surface-200 pt-3">
					<p class="label-text mb-2">Add step</p>
					<div class="flex flex-wrap items-end gap-3">
						<div class="form-control min-w-[180px]">
							<label for="add-step-station" class="label py-0"><span class="label-text text-xs">Station</span></label>
							<select id="add-step-station" class="select rounded-container border border-surface-200 px-3 py-2 select-sm w-full" bind:value={addStepStationId} aria-label="Station">
								<option value="">Select station</option>
								{#each stations as st}
									<option value={st.id}>{st.name}</option>
								{/each}
							</select>
						</div>
						<div class="form-control w-28">
							<label for="add-step-min" class="label py-0"><span class="label-text text-xs">Est. time (min)</span></label>
							<input
								id="add-step-min"
								type="number"
								min="0"
								max="120"
								class="input rounded-container border border-surface-200 px-3 py-2 input-sm w-full"
								placeholder="Optional"
								bind:value={addStepEstimatedMinutes}
								aria-label="Estimated time in minutes for queue estimates"
							/>
						</div>
						<button type="button" class="btn preset-filled-primary-500 btn-sm" onclick={handleAddStep} disabled={submitting || addStepStationId === ''}>Add</button>
					</div>
					<p class="text-xs text-surface-950/50 mt-1">Estimated time is optional and used for queue time estimates.</p>
				</div>
				<div class="flex justify-end">
					<button type="button" class="btn preset-tonal" onclick={closeStepModal}>Close</button>
				</div>
			</div>
		{/snippet}
	</Modal>
{/if}

{#if showReorderConfirm && stepModalTrack}
	<Modal open={showReorderConfirm} title="Reorder steps" onClose={closeReorderConfirm}>
		{#snippet children()}
			<div class="flex flex-col gap-4">
				<p class="text-sm text-surface-950/80">
					This track has <strong>{stepModalTrack.active_sessions_count ?? 0} active session(s)</strong>.
					How should the new order apply?
				</p>
				<div class="form-control gap-2">
					<label class="label cursor-pointer justify-start gap-3 rounded-lg border border-surface-200 bg-surface-100 p-3">
						<input type="radio" name="reorder_scope" class="radio radio-primary radio-sm" bind:group={reorderScope} value="new_only" />
						<div>
							<span class="font-medium">New sessions only</span>
							<p class="text-xs text-surface-950/60">Future check-ins follow the new order. Existing sessions keep their current position.</p>
						</div>
					</label>
					<label class="label cursor-pointer justify-start gap-3 rounded-lg border border-surface-200 bg-surface-100 p-3">
						<input type="radio" name="reorder_scope" class="radio radio-primary radio-sm" bind:group={reorderScope} value="migrate" />
						<div>
							<span class="font-medium">Migrate existing sessions</span>
							<p class="text-xs text-surface-950/60">Update active sessions to match the new step order (remap their position).</p>
						</div>
					</label>
				</div>
				<div class="flex justify-end gap-2">
					<button type="button" class="btn preset-tonal" onclick={closeReorderConfirm}>Cancel</button>
					<button type="button" class="btn preset-filled-primary-500" onclick={confirmReorderApply} disabled={submitting}>Apply</button>
				</div>
			</div>
		{/snippet}
	</Modal>
{/if}

<ConfirmModal
	open={confirmStopProgram}
	title="Stop session?"
	message={`Stop session for "${program.name}"? You can only stop when no clients are in the queue.`}
	confirmLabel="Stop session"
	cancelLabel="Cancel"
	variant="danger"
	loading={submitting}
	onConfirm={handleStopConfirm}
	onCancel={closeStopConfirm}
/>

<ConfirmModal
	open={!!confirmDeleteTrack}
	title="Delete track?"
	message={confirmDeleteTrack ? `Delete track "${confirmDeleteTrack.name}"? This is only allowed if no active sessions use it.` : ''}
	confirmLabel="Delete"
	cancelLabel="Cancel"
	variant="danger"
	loading={submitting}
	onConfirm={handleDeleteTrackConfirm}
	onCancel={closeDeleteTrackConfirm}
/>

<ConfirmModal
	open={!!confirmDeleteStation}
	title="Delete station?"
	message={confirmDeleteStation ? `Delete station "${confirmDeleteStation.name}"? This is only allowed if it is not used in any track steps.` : ''}
	confirmLabel="Delete"
	cancelLabel="Cancel"
	variant="danger"
	loading={submitting}
	onConfirm={handleDeleteStationConfirm}
	onCancel={closeDeleteStationConfirm}
/>

<ConfirmModal
	open={!!confirmRemoveStep}
	title="Remove step?"
	message={confirmRemoveStep ? `Remove step "${confirmRemoveStep.step.station_name}" from this track?` : ''}
	confirmLabel="Remove"
	cancelLabel="Cancel"
	variant="warning"
	loading={submitting}
	onConfirm={handleRemoveStepConfirm}
	onCancel={closeRemoveStepConfirm}
/>
