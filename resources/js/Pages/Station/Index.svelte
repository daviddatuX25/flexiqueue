<script lang="ts">
	import MobileLayout from '../../Layouts/MobileLayout.svelte';
	import QrScanner from '../../Components/QrScanner.svelte';
	import { get } from 'svelte/store';
	import { usePage } from '@inertiajs/svelte';
	import { router } from '@inertiajs/svelte';

	type AuthType = 'preset_pin' | 'temp_pin' | 'temp_qr' | 'preset_qr';

	interface StationInfo {
		id: number;
		name: string;
	}

	interface ServingSession {
		session_id: number;
		alias: string;
		track: string;
		client_category: string;
		status: 'called' | 'serving';
		current_step_order: number;
		total_steps: number;
		started_at: string;
		no_show_attempts: number;
	}

	interface WaitingSession {
		session_id: number;
		alias: string;
		track: string;
		client_category: string;
		status: string;
		queued_at: string;
		station_queue_position?: number;
	}

	interface QueueData {
		station: { id: number; name: string; client_capacity?: number; serving_count?: number };
		serving: ServingSession[];
		no_show_timer_seconds: number;
		waiting: WaitingSession[];
		priority_first?: boolean;
		balance_mode?: string;
		next_to_call?: { session_id: number; alias: string } | null;
		stats: { total_waiting: number; total_served_today: number; avg_service_time_minutes: number };
	}

	let {
		station = null,
		stations = [],
		canSwitchStation = false,
	}: {
		station: StationInfo | null;
		stations: StationInfo[];
		canSwitchStation: boolean;
	} = $props();

	let queue = $state<QueueData | null>(null);
	let loading = $state(true);
	let error = $state('');
	let actionLoading = $state<string | null>(null);
	let showOverrideModal = $state(false);
	let overrideTargetStationId = $state<number | null>(null);
	let overrideReason = $state('');
	let overridePin = $state('');
	let overrideSupervisorId = $state<number | null>(null);
	let overrideSession = $state<ServingSession | null>(null);
	let authType = $state<AuthType>('preset_pin');
	let tempCodeGenerated = $state<string | null>(null);
	let tempCodeEntered = $state('');
	let tempQrDataUri = $state<string | null>(null);
	let tempQrScanToken = $state('');
	let showQrScanner = $state(false);
	let qrScanHandled = $state(false);
	let showForceCompleteModal = $state(false);
	let forceCompleteSession = $state<ServingSession | null>(null);
	let forceCompleteReason = $state('');
	let noShowModalSession = $state<ServingSession | null>(null);

	/** Countdown per called session_id: when 0, No-show button enabled. Set when session enters 'called'. */
	let noShowCountdown = $state<Record<number, number>>({});

	const page = usePage();
	const authUser = $derived((get(page)?.props as { auth?: { user?: { id: number; role?: string | { value?: string } } } })?.auth?.user ?? null);
	const userRole = $derived(
		typeof authUser?.role === 'string' ? authUser.role : (authUser?.role as { value?: string } | undefined)?.value ?? ''
	);

	const clientCapacity = $derived(queue?.station?.client_capacity ?? 1);
	const servingCount = $derived(queue?.station?.serving_count ?? queue?.serving?.length ?? 0);
	const atCapacity = $derived(servingCount >= clientCapacity);
	const noShowTimerSeconds = $derived(queue?.no_show_timer_seconds ?? 10);

	function getCsrfToken(): string {
		const p = get(page);
		const fromProps = (p?.props as { csrf_token?: string } | undefined)?.csrf_token;
		if (fromProps) return fromProps;
		const meta =
			typeof document !== 'undefined'
				? (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content
				: '';
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
				'X-Requested-With': 'XMLHttpRequest',
			},
			credentials: 'same-origin',
			...(body ? { body: JSON.stringify(body) } : {}),
		});
		const data = await res.json().catch(() => ({}));
		return { ok: res.ok, data, message: (data as { message?: string })?.message };
	}

	/**
	 * Fetch queue data. Use silent=true for refetch after actions (Call Next, Serve, etc.)
	 * so only the queue sections update without showing the full-page spinner.
	 * Optional onSuccess runs in same tick as queue/noShowCountdown to batch renders.
	 */
	async function fetchQueue(silent = false, onSuccess?: () => void) {
		if (!station) return;
		if (!silent) loading = true;
		error = '';
		const { ok, data } = await api('GET', `/api/stations/${station.id}/queue`);
		if (!silent) loading = false;
		if (ok) {
			const q = data as QueueData;
			queue = q;
			// Sync noShowCountdown in same tick as queue to avoid extra render (flicker)
			const serving = q?.serving ?? [];
			const called = serving.filter((s: { status: string }) => s.status === 'called');
			const timerSecs = q?.no_show_timer_seconds ?? 10;
			const next: Record<number, number> = {};
			for (const s of called) {
				next[s.session_id] = noShowCountdown[s.session_id] ?? timerSecs;
			}
			noShowCountdown = next;
			onSuccess?.();
		} else {
			error = (data as { message?: string })?.message ?? 'Failed to load queue';
		}
	}

	$effect(() => {
		if (station?.id) {
			fetchQueue();
		} else {
			queue = null;
			loading = false;
		}
	});

	$effect(() => {
		const hasCountdowns = Object.values(noShowCountdown).some((v) => v > 0);
		if (!hasCountdowns) return;
		const iv = setInterval(() => {
			noShowCountdown = Object.fromEntries(
				Object.entries(noShowCountdown).map(([k, v]) => [k, Math.max(0, v - 1)])
			);
		}, 1000);
		return () => clearInterval(iv);
	});

	function formatDuration(iso: string): string {
		const d = new Date(iso);
		const now = new Date();
		const mins = Math.floor((now.getTime() - d.getTime()) / 60000);
		if (mins < 1) return '< 1 min';
		if (mins < 60) return `${mins} min`;
		return `${Math.floor(mins / 60)}h ${mins % 60}m`;
	}

	function categoryBadgeClass(cat: string): string {
		const c = (cat || 'Regular').toLowerCase();
		if (c === 'pwd' || c === 'senior' || c === 'pregnant') return 'badge-warning';
		return 'badge-ghost';
	}

	function switchStation(s: StationInfo) {
		router.visit(`/station/${s.id}`);
	}

	async function togglePriorityFirst(priorityFirst: boolean) {
		if (!station || actionLoading || !queue) return;
		const prev = queue.priority_first ?? true;
		// Optimistic update: reflect toggle immediately so it feels responsive
		queue = { ...queue, priority_first: priorityFirst };
		actionLoading = 'toggle';
		const { ok } = await api('POST', `/api/stations/${station.id}/priority-first`, {
			priority_first: priorityFirst,
		});
		if (ok) {
			await fetchQueue(true, () => { actionLoading = null; });
		} else {
			actionLoading = null;
			// Revert on failure
			queue = { ...queue, priority_first: prev };
			error = 'Failed to update priority setting';
		}
	}

	async function callNext() {
		const target = queue?.next_to_call ?? queue?.waiting?.[0];
		if (!target || actionLoading || atCapacity) return;
		const sessionId = typeof target === 'object' && 'session_id' in target ? target.session_id : target.session_id;
		actionLoading = 'call';
		const { ok, data } = await api('POST', `/api/sessions/${sessionId}/call`, {});
		if (ok) {
			await fetchQueue(true, () => { actionLoading = null; });
		} else {
			actionLoading = null;
			error = (data as { message?: string })?.message ?? 'Call failed';
		}
	}

	async function serve(s: ServingSession) {
		if (!s || s.status !== 'called' || actionLoading) return;
		actionLoading = `serve-${s.session_id}`;
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/serve`, {});
		if (ok) {
			await fetchQueue(true, () => { actionLoading = null; });
		} else {
			actionLoading = null;
			error = (data as { message?: string })?.message ?? 'Serve failed';
		}
	}

	async function transfer(s: ServingSession) {
		if (!s || s.status !== 'serving' || actionLoading) return;
		actionLoading = `transfer-${s.session_id}`;
		error = '';
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/transfer`, { mode: 'standard' });
		if (ok) {
			const d = data as { action_required?: string };
			if (d?.action_required === 'complete') {
				error = 'No next station in track. Complete the session instead.';
			}
			await fetchQueue(true, () => { actionLoading = null; });
		} else {
			actionLoading = null;
			error = (data as { message?: string })?.message ?? 'Transfer failed';
		}
	}

	async function complete(s: ServingSession) {
		if (!s || s.status !== 'serving' || actionLoading) return;
		actionLoading = `complete-${s.session_id}`;
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/complete`, {});
		if (ok) {
			await fetchQueue(true, () => { actionLoading = null; });
		} else {
			actionLoading = null;
			error = (data as { message?: string })?.message ?? 'Complete failed';
		}
	}

	async function cancel(s: ServingSession) {
		if (!s || actionLoading) return;
		actionLoading = `cancel-${s.session_id}`;
		const { ok } = await api('POST', `/api/sessions/${s.session_id}/cancel`, { remarks: 'Cancelled by staff' });
		if (ok) {
			await fetchQueue(true, () => { actionLoading = null; });
		} else {
			actionLoading = null;
			error = 'Cancel failed';
		}
	}

	function openNoShowModal(s: ServingSession) {
		noShowModalSession = s;
	}

	async function noShow() {
		const s = noShowModalSession;
		if (!s || actionLoading) return;
		actionLoading = 'noShow';
		noShowModalSession = null;
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/no-show`, {});
		if (ok) {
			await fetchQueue(true, () => { actionLoading = null; });
		} else {
			actionLoading = null;
			error = (data as { message?: string })?.message ?? 'No-show failed';
		}
	}

	function resetAuthState() {
		authType = 'preset_pin';
		tempCodeGenerated = null;
		tempCodeEntered = '';
		tempQrDataUri = null;
		tempQrScanToken = '';
		showQrScanner = false;
		qrScanHandled = false;
	}

	function openOverrideModal(s: ServingSession) {
		overrideSession = s;
		showOverrideModal = true;
		resetAuthState();
		if (authUser && authUser.id) overrideSupervisorId = authUser.id;
	}

	function openForceCompleteModal(s: ServingSession) {
		forceCompleteSession = s;
		forceCompleteReason = '';
		showForceCompleteModal = true;
		resetAuthState();
		if (authUser && authUser.id) overrideSupervisorId = authUser.id;
	}

	async function generateTempPin() {
		if (actionLoading) return;
		actionLoading = 'generate-temp-pin';
		error = '';
		const { ok, data } = await api('POST', '/api/auth/temporary-pin', { expires_in_seconds: 300 });
		actionLoading = null;
		if (ok) {
			const d = data as { code?: string };
			tempCodeGenerated = d?.code ?? null;
		} else {
			error = (data as { message?: string })?.message ?? 'Failed to generate code';
		}
	}

	async function generateTempQr() {
		if (actionLoading) return;
		actionLoading = 'generate-temp-qr';
		error = '';
		const { ok, data } = await api('POST', '/api/auth/temporary-qr', { expires_in_seconds: 300 });
		actionLoading = null;
		if (ok) {
			const d = data as { qr_data_uri?: string };
			tempQrDataUri = d?.qr_data_uri ?? null;
		} else {
			error = (data as { message?: string })?.message ?? 'Failed to generate QR';
		}
	}

	function handleQrScan(decodedText: string) {
		if (qrScanHandled) return;
		qrScanHandled = true;
		tempQrScanToken = decodedText.trim();
		showQrScanner = false;
	}

	function buildAuthBody(): Record<string, unknown> | null {
		if (authType === 'preset_pin') {
			if (!overrideSupervisorId || !overridePin || overridePin.length !== 6) return null;
			return { auth_type: 'preset_pin', supervisor_user_id: overrideSupervisorId, supervisor_pin: overridePin };
		}
		if (authType === 'temp_pin') {
			const code = (tempCodeEntered || tempCodeGenerated || '').trim();
			if (code.length !== 6) return null;
			return { auth_type: 'temp_pin', temp_code: code };
		}
		if (authType === 'temp_qr') {
			if (!tempQrScanToken.trim()) return null;
			return { auth_type: 'temp_qr', qr_scan_token: tempQrScanToken.trim() };
		}
		// preset_qr: not implemented yet
		return null;
	}

	function canConfirmAuth(): boolean {
		if (authType === 'preset_pin') return !!(overrideSupervisorId && overridePin && overridePin.length === 6);
		if (authType === 'temp_pin') return (tempCodeEntered || tempCodeGenerated || '').length === 6;
		if (authType === 'temp_qr') return !!tempQrScanToken.trim();
		return false;
	}

	async function override() {
		const s = overrideSession;
		if (!s || !overrideTargetStationId || !overrideReason.trim() || actionLoading) return;
		const authBody = buildAuthBody();
		if (!authBody) return;
		actionLoading = 'override';
		error = '';
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/override`, {
			target_station_id: overrideTargetStationId,
			reason: overrideReason.trim(),
			...authBody,
		});
		actionLoading = null;
		if (ok) {
			showOverrideModal = false;
			overrideSession = null;
			overrideTargetStationId = null;
			overrideReason = '';
			overridePin = '';
			overrideSupervisorId = null;
			resetAuthState();
			await fetchQueue(true, () => { actionLoading = null; });
		} else {
			error = (data as { message?: string })?.message ?? 'Override failed';
		}
	}

	async function forceComplete() {
		const s = forceCompleteSession;
		if (!s || !forceCompleteReason.trim() || actionLoading) return;
		const authBody = buildAuthBody();
		if (!authBody) return;
		actionLoading = 'force-complete';
		error = '';
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/force-complete`, {
			reason: forceCompleteReason.trim(),
			...authBody,
		});
		actionLoading = null;
		if (ok) {
			showForceCompleteModal = false;
			forceCompleteSession = null;
			forceCompleteReason = '';
			overridePin = '';
			overrideSupervisorId = null;
			resetAuthState();
			await fetchQueue(true, () => { actionLoading = null; });
		} else {
			error = (data as { message?: string })?.message ?? 'Force complete failed';
		}
	}

	function isLastStep(s: ServingSession): boolean {
		return (s.current_step_order ?? 0) >= (s.total_steps ?? 1);
	}

	function canNoShow(s: ServingSession): boolean {
		if (s.status !== 'called') return false;
		const remaining = noShowCountdown[s.session_id] ?? 0;
		return remaining <= 0;
	}

	function noShowButtonLabel(s: ServingSession): string {
		const remaining = noShowCountdown[s.session_id] ?? 0;
		if (remaining > 0) return `No-Show (${remaining}s)`;
		return s.no_show_attempts > 0 ? `No-Show (${s.no_show_attempts}/3)` : 'No-Show';
	}
</script>

<svelte:head>
	<title>Station — FlexiQueue</title>
</svelte:head>

<MobileLayout headerTitle={station?.name ?? 'Station'}>
	<div class="flex flex-col gap-4">
		{#if !station}
			<div class="rounded-box bg-base-100 border border-base-300 p-6 text-center text-base-content/80">
				{#if canSwitchStation && stations.length > 0}
					<p class="font-medium mb-3">Select a station</p>
					<div class="flex flex-col gap-2">
						{#each stations as s (s.id)}
							<button type="button" class="btn btn-outline btn-block" onclick={() => switchStation(s)}>
								{s.name}
							</button>
						{/each}
					</div>
				{:else}
					<p class="font-medium">No station assigned</p>
					<p class="mt-1 text-sm">Contact admin to assign you to a station.</p>
				{/if}
			</div>
		{:else if loading}
			<div class="flex justify-center py-12">
				<span class="loading loading-spinner loading-lg text-primary"></span>
			</div>
		{:else if error}
			<div class="alert alert-error">{error}</div>
		{:else if queue}
			{#if canSwitchStation && stations.length > 1}
				<div class="flex gap-2 overflow-x-auto pb-2">
					{#each stations as s (s.id)}
						<button
							type="button"
							class="btn btn-sm shrink-0 {s.id === station.id ? 'btn-primary' : 'btn-ghost'}"
							onclick={() => switchStation(s)}
						>
							{s.name}
						</button>
					{/each}
				</div>
			{/if}

			<!-- Capacity indicator and Priority first toggle -->
			<div class="flex flex-wrap items-center justify-between gap-2">
				<div class="text-sm text-base-content/70">
					Serving {servingCount}/{clientCapacity}
				</div>
				{#if canSwitchStation}
					<label class="label cursor-pointer gap-2">
						<span class="label-text text-sm">Priority first</span>
						<input
							type="checkbox"
							class="toggle toggle-sm toggle-primary"
							checked={queue?.priority_first ?? true}
							disabled={actionLoading === 'toggle'}
							onchange={(e) => togglePriorityFirst((e.target as HTMLInputElement).checked)}
						/>
					</label>
				{/if}
			</div>

			<!-- Serving / Called cards (one per client) -->
			{#each queue.serving as s (s.session_id)}
				<div class="rounded-box bg-base-100 border border-base-300 p-4 space-y-3">
					<p class="text-xs font-medium text-base-content/70 uppercase tracking-wide">
						{s.status === 'called' ? 'Calling' : 'Now Serving'}
					</p>
					<p class="text-4xl font-bold text-primary tabular-nums">{s.alias}</p>
					<!-- Client type prominent -->
					<div class="flex flex-wrap gap-2">
						<span class="badge badge-outline">{s.track}</span>
						<span class="badge {categoryBadgeClass(s.client_category)} text-sm font-semibold">
							{s.client_category ?? 'Regular'}
						</span>
					</div>
					<p class="text-sm text-base-content/70">
						Step {s.current_step_order} of {s.total_steps}
						{#if s.status === 'serving'}
							· Started {formatDuration(s.started_at)} ago
						{/if}
					</p>

					<div class="flex flex-col gap-2 pt-2">
						{#if s.status === 'called'}
							<!-- Called: Serve + No-show (No-show disabled until timer) -->
							<button
								type="button"
								class="btn btn-primary btn-lg"
								disabled={!!actionLoading}
								onclick={() => serve(s)}
							>
								{actionLoading === `serve-${s.session_id}` ? 'Processing…' : 'Serve'}
							</button>
							<button
								type="button"
								class="btn btn-ghost btn-sm {s.no_show_attempts >= 2 ? 'btn-warning' : ''}"
								disabled={!!actionLoading || !canNoShow(s)}
								onclick={() => openNoShowModal(s)}
							>
								{noShowButtonLabel(s)}
							</button>
						{:else}
							<!-- Serving: Transfer or Complete -->
							{#if isLastStep(s)}
								<button
									type="button"
									class="btn btn-primary btn-lg"
									disabled={!!actionLoading}
									onclick={() => complete(s)}
								>
									{actionLoading === `complete-${s.session_id}` ? 'Completing…' : 'Complete Session'}
								</button>
							{:else}
								<button
									type="button"
									class="btn btn-primary btn-lg"
									disabled={!!actionLoading}
									onclick={() => transfer(s)}
								>
									{actionLoading === `transfer-${s.session_id}` ? 'Transferring…' : 'Send to Next Station'}
								</button>
							{/if}
							<div class="flex flex-wrap gap-2">
								{#if canSwitchStation}
									<button
										type="button"
										class="btn btn-outline btn-sm"
										disabled={!!actionLoading}
										onclick={() => openOverrideModal(s)}
									>
										Override
									</button>
									<button
										type="button"
										class="btn btn-ghost btn-sm btn-warning"
										disabled={!!actionLoading}
										onclick={() => openForceCompleteModal(s)}
									>
										Force Complete
									</button>
								{/if}
								<button
									type="button"
									class="btn btn-ghost btn-sm"
									disabled={!!actionLoading}
									onclick={() => cancel(s)}
								>
									Cancel
								</button>
							</div>
						{/if}
					</div>
				</div>
			{/each}

			<!-- Call Next (when capacity allows) -->
			{#if queue.serving.length === 0 || !atCapacity}
				<div class="rounded-box bg-base-100 border border-base-300 p-6 text-center">
					<p class="text-base-content/70 font-medium mb-4">
						{queue.serving.length > 0 ? 'Call another client' : 'No client active'}
					</p>
					{#if queue.waiting.length > 0 && !atCapacity}
						{@const nextSession = queue.next_to_call ? queue.waiting.find((w) => w.session_id === queue.next_to_call!.session_id) ?? queue.waiting[0] : queue.waiting[0]}
						<p class="text-sm text-base-content/60 mb-3">
							Next: <span class="font-mono font-semibold">{nextSession.alias}</span>
							<span class="badge badge-ghost badge-sm ml-1">{nextSession.client_category ?? 'Regular'}</span>
							({nextSession.track})
						</p>
						<button
							type="button"
							class="btn btn-primary btn-lg"
							disabled={!!actionLoading}
							onclick={callNext}
						>
							{actionLoading === 'call' ? 'Calling…' : 'Call Next'}
						</button>
					{:else if atCapacity}
						<p class="text-sm text-base-content/60">At capacity ({servingCount}/{clientCapacity}). Transfer or complete a client first.</p>
					{:else}
						<p class="text-sm text-base-content/60">Queue is empty</p>
						<p class="text-xs text-base-content/50 mt-1">
							Tip: Sessions from triage go to the track's first station. Assign staff to that station in Admin → Users.
						</p>
					{/if}
				</div>
			{/if}

			<!-- Queue preview with client type -->
			{#if queue.waiting.length > 0}
				<div class="rounded-box bg-base-100 border border-base-300 p-4">
					<p class="text-xs font-medium text-base-content/70 uppercase tracking-wide mb-2">
						Queue — Next {queue.waiting.length}
					</p>
					<ul class="space-y-2">
						{#each queue.waiting as w (w.session_id)}
							<li class="flex justify-between items-center text-sm gap-2">
								<div class="flex items-center gap-2 min-w-0">
									<span class="font-mono font-medium">{w.alias}</span>
									<span class="badge {categoryBadgeClass(w.client_category)} badge-sm">{w.client_category ?? 'Regular'}</span>
								</div>
								<span class="text-base-content/60 shrink-0">{w.track} · {formatDuration(w.queued_at)}</span>
							</li>
						{/each}
					</ul>
				</div>
			{/if}

			<div class="rounded-box bg-base-100 border border-base-300 p-3 text-center text-sm text-base-content/70">
				Today: {queue.stats.total_served_today} served · Avg {queue.stats.avg_service_time_minutes} min
			</div>
		{/if}
	</div>

	{#if noShowModalSession}
		<dialog open class="modal modal-open">
			<div class="modal-box">
				<h3 class="font-bold text-lg">Mark No-Show</h3>
				<p class="py-2">
					Mark <span class="font-mono font-semibold">{noShowModalSession.alias}</span> as no-show?
					{noShowModalSession.no_show_attempts >= 2
						? 'This will end the session and free the token (3/3).'
						: 'Client can be called again.'}
				</p>
				<div class="modal-action">
					<button type="button" class="btn btn-ghost" onclick={() => (noShowModalSession = null)}>Cancel</button>
					<button type="button" class="btn btn-warning" onclick={noShow} disabled={!!actionLoading}>
						{actionLoading === 'noShow' ? 'Processing…' : 'Mark No-Show'}
					</button>
				</div>
			</div>
			<form method="dialog" class="modal-backdrop">
				<button type="button" onclick={() => (noShowModalSession = null)}>close</button>
			</form>
		</dialog>
	{/if}

	{#if showOverrideModal}
		<dialog open class="modal modal-open">
			<div class="modal-box max-w-md">
				<h3 class="font-bold text-lg">Override standard flow</h3>
				<p class="text-sm text-base-content/70 py-2">
					Send client {overrideSession?.alias ?? ''} to a different station. Authorize with PIN or QR.
				</p>
				<div class="form-control w-full mt-2">
					<label for="override-target" class="label"><span class="label-text">Target station</span></label>
					<select
						id="override-target"
						class="select select-bordered w-full"
						bind:value={overrideTargetStationId}
						onchange={(e) => (overrideTargetStationId = Number((e.target as HTMLSelectElement).value))}
					>
						<option value={null}>Select…</option>
						{#each stations.filter((s) => s.id !== station?.id) as s (s.id)}
							<option value={s.id}>{s.name}</option>
						{/each}
					</select>
				</div>
				<div class="form-control w-full mt-2">
					<label for="override-reason" class="label"><span class="label-text">Reason (required)</span></label>
					<textarea
						id="override-reason"
						class="textarea textarea-bordered w-full"
						placeholder="e.g. Needs legal assistance first"
						bind:value={overrideReason}
						rows="2"
					></textarea>
				</div>
				<!-- Auth type picker -->
				<div class="form-control w-full mt-2">
					<label class="label"><span class="label-text">Authorize with</span></label>
					<div class="join join-vertical w-full">
						<button
							type="button"
							class="join-item btn btn-sm {authType === 'temp_pin' ? 'btn-primary' : 'btn-ghost'}"
							onclick={() => { authType = 'temp_pin'; tempQrDataUri = null; tempQrScanToken = ''; showQrScanner = false; }}
						>
							Temporary PIN
						</button>
						<button
							type="button"
							class="join-item btn btn-sm {authType === 'temp_qr' ? 'btn-primary' : 'btn-ghost'}"
							onclick={() => { authType = 'temp_qr'; tempCodeGenerated = null; tempCodeEntered = ''; qrScanHandled = false; }}
						>
							Temporary QR
						</button>
						<button
							type="button"
							class="join-item btn btn-sm {authType === 'preset_pin' ? 'btn-primary' : 'btn-ghost'}"
							onclick={() => { authType = 'preset_pin'; tempCodeGenerated = null; tempCodeEntered = ''; tempQrDataUri = null; tempQrScanToken = ''; showQrScanner = false; }}
						>
							Preset PIN
						</button>
						<button type="button" class="join-item btn btn-sm btn-ghost btn-disabled" title="Set up in Profile (coming soon)">
							Preset QR (coming soon)
						</button>
					</div>
				</div>
				{#if authType === 'temp_pin'}
					<div class="form-control w-full mt-2">
						{#if !tempCodeGenerated}
							<button type="button" class="btn btn-outline btn-sm" disabled={!!actionLoading} onclick={generateTempPin}>
								{actionLoading === 'generate-temp-pin' ? 'Generating…' : 'Generate 6-digit code'}
							</button>
						{:else}
							<p class="text-lg font-mono font-bold tracking-widest text-primary">{tempCodeGenerated}</p>
							<p class="text-xs text-base-content/60">Enter code below (or use this if you generated it)</p>
						{/if}
						<input
							type="text"
							inputmode="numeric"
							pattern="[0-9]*"
							class="input input-bordered w-full mt-2 font-mono"
							placeholder="Enter 6-digit code"
							maxlength="6"
							bind:value={tempCodeEntered}
						/>
					</div>
				{:else if authType === 'temp_qr'}
					<div class="form-control w-full mt-2">
						{#if !tempQrDataUri}
							<button type="button" class="btn btn-outline btn-sm" disabled={!!actionLoading} onclick={generateTempQr}>
								{actionLoading === 'generate-temp-qr' ? 'Generating…' : 'Generate QR code'}
							</button>
						{:else}
							<img src={tempQrDataUri} alt="Temporary QR" class="w-40 h-40 mx-auto my-2" />
							<p class="text-xs text-base-content/60 text-center">Scan with staff device, or</p>
						{/if}
						{#if !showQrScanner}
							<button
								type="button"
								class="btn btn-outline btn-sm mt-2"
								onclick={() => { showQrScanner = true; qrScanHandled = false; }}
							>
								Scan QR to authorize
							</button>
						{:else}
							<QrScanner active={showQrScanner} onScan={handleQrScan} />
							<button type="button" class="btn btn-ghost btn-xs mt-1" onclick={() => { showQrScanner = false; qrScanHandled = true; }}>Cancel scan</button>
						{/if}
						{#if tempQrScanToken}
							<p class="text-xs text-success mt-1">✓ QR scanned</p>
						{/if}
					</div>
				{:else if authType === 'preset_pin'}
					<div class="form-control w-full mt-2">
						<label for="override-supervisor-id" class="label"><span class="label-text">Supervisor user ID</span></label>
						<input
							id="override-supervisor-id"
							type="number"
							class="input input-bordered w-full"
							placeholder="e.g. 2"
							bind:value={overrideSupervisorId}
						/>
					</div>
					<div class="form-control w-full mt-2">
						<label for="override-pin" class="label"><span class="label-text">6-digit PIN</span></label>
						<input
							id="override-pin"
							type="password"
							class="input input-bordered w-full"
							placeholder="••••••"
							maxlength="6"
							bind:value={overridePin}
						/>
					</div>
				{/if}
				{#if error}
					<div class="alert alert-error mt-2 text-sm">{error}</div>
				{/if}
				<div class="modal-action">
					<button
						type="button"
						class="btn btn-ghost"
						onclick={() => {
							showOverrideModal = false;
							overrideSession = null;
							overrideTargetStationId = null;
							overrideReason = '';
							overridePin = '';
							overrideSupervisorId = null;
							resetAuthState();
							error = '';
						}}
					>
						Cancel
					</button>
					<button
						type="button"
						class="btn btn-primary"
						disabled={!overrideTargetStationId || !overrideReason.trim() || !canConfirmAuth() || !!actionLoading}
						onclick={override}
					>
						{actionLoading === 'override' ? 'Processing…' : 'Confirm Override'}
					</button>
				</div>
			</div>
			<form method="dialog" class="modal-backdrop">
				<button type="button" onclick={() => { showOverrideModal = false; overrideSession = null; overrideTargetStationId = null; overrideReason = ''; overridePin = ''; overrideSupervisorId = null; resetAuthState(); error = ''; }}>
					close
				</button>
			</form>
		</dialog>
	{/if}

	{#if showForceCompleteModal}
		<dialog open class="modal modal-open">
			<div class="modal-box max-w-md">
				<h3 class="font-bold text-lg">Force complete session</h3>
				<p class="text-sm text-base-content/70 py-2">
					End session for <span class="font-mono font-semibold">{forceCompleteSession?.alias ?? ''}</span> without normal flow (e.g. client left). Requires authorization.
				</p>
				<div class="form-control w-full mt-2">
					<label for="fc-reason" class="label"><span class="label-text">Reason (required)</span></label>
					<textarea id="fc-reason" class="textarea textarea-bordered w-full" placeholder="e.g. Client left without completing" bind:value={forceCompleteReason} rows="2"></textarea>
				</div>
				<!-- Auth type picker (same as override) -->
				<div class="form-control w-full mt-2">
					<label class="label"><span class="label-text">Authorize with</span></label>
					<div class="join join-vertical w-full">
						<button type="button" class="join-item btn btn-sm {authType === 'temp_pin' ? 'btn-primary' : 'btn-ghost'}" onclick={() => { authType = 'temp_pin'; tempQrDataUri = null; tempQrScanToken = ''; showQrScanner = false; }}>Temporary PIN</button>
						<button type="button" class="join-item btn btn-sm {authType === 'temp_qr' ? 'btn-primary' : 'btn-ghost'}" onclick={() => { authType = 'temp_qr'; tempCodeGenerated = null; tempCodeEntered = ''; qrScanHandled = false; }}>Temporary QR</button>
						<button type="button" class="join-item btn btn-sm {authType === 'preset_pin' ? 'btn-primary' : 'btn-ghost'}" onclick={() => { authType = 'preset_pin'; tempCodeGenerated = null; tempCodeEntered = ''; tempQrDataUri = null; tempQrScanToken = ''; showQrScanner = false; }}>Preset PIN</button>
						<button type="button" class="join-item btn btn-sm btn-ghost btn-disabled" title="Set up in Profile (coming soon)">Preset QR (coming soon)</button>
					</div>
				</div>
				{#if authType === 'temp_pin'}
					<div class="form-control w-full mt-2">
						{#if !tempCodeGenerated}
							<button type="button" class="btn btn-outline btn-sm" disabled={!!actionLoading} onclick={generateTempPin}>{actionLoading === 'generate-temp-pin' ? 'Generating…' : 'Generate 6-digit code'}</button>
						{:else}
							<p class="text-lg font-mono font-bold tracking-widest text-primary">{tempCodeGenerated}</p>
						{/if}
						<input type="text" inputmode="numeric" class="input input-bordered w-full mt-2 font-mono" placeholder="Enter 6-digit code" maxlength="6" bind:value={tempCodeEntered} />
					</div>
				{:else if authType === 'temp_qr'}
					<div class="form-control w-full mt-2">
						{#if !tempQrDataUri}
							<button type="button" class="btn btn-outline btn-sm" disabled={!!actionLoading} onclick={generateTempQr}>{actionLoading === 'generate-temp-qr' ? 'Generating…' : 'Generate QR code'}</button>
						{:else}
							<img src={tempQrDataUri} alt="Temporary QR" class="w-40 h-40 mx-auto my-2" />
						{/if}
						{#if !showQrScanner}
							<button type="button" class="btn btn-outline btn-sm mt-2" onclick={() => { showQrScanner = true; qrScanHandled = false; }}>Scan QR to authorize</button>
						{:else}
							<QrScanner active={showQrScanner} onScan={handleQrScan} />
							<button type="button" class="btn btn-ghost btn-xs mt-1" onclick={() => { showQrScanner = false; qrScanHandled = true; }}>Cancel scan</button>
						{/if}
						{#if tempQrScanToken}<p class="text-xs text-success mt-1">✓ QR scanned</p>{/if}
					</div>
				{:else if authType === 'preset_pin'}
					<div class="form-control w-full mt-2">
						<label for="fc-supervisor-id" class="label"><span class="label-text">Supervisor user ID</span></label>
						<input id="fc-supervisor-id" type="number" class="input input-bordered w-full" placeholder="e.g. 2" bind:value={overrideSupervisorId} />
					</div>
					<div class="form-control w-full mt-2">
						<label for="fc-pin" class="label"><span class="label-text">6-digit PIN</span></label>
						<input id="fc-pin" type="password" class="input input-bordered w-full" placeholder="••••••" maxlength="6" bind:value={overridePin} />
					</div>
				{/if}
				{#if error}<div class="alert alert-error mt-2 text-sm">{error}</div>{/if}
				<div class="modal-action">
					<button type="button" class="btn btn-ghost" onclick={() => { showForceCompleteModal = false; forceCompleteSession = null; forceCompleteReason = ''; overridePin = ''; overrideSupervisorId = null; resetAuthState(); error = ''; }}>Cancel</button>
					<button type="button" class="btn btn-primary" disabled={!forceCompleteReason.trim() || !canConfirmAuth() || !!actionLoading} onclick={forceComplete}>
						{actionLoading === 'force-complete' ? 'Processing…' : 'Force Complete'}
					</button>
				</div>
			</div>
			<form method="dialog" class="modal-backdrop">
				<button type="button" onclick={() => { showForceCompleteModal = false; forceCompleteSession = null; forceCompleteReason = ''; overridePin = ''; overrideSupervisorId = null; resetAuthState(); error = ''; }}>close</button>
			</form>
		</dialog>
	{/if}
</MobileLayout>
