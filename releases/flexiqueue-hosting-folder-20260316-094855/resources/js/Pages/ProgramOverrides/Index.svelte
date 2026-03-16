<script lang="ts">
	import MobileLayout from '../../Layouts/MobileLayout.svelte';
	import { get } from 'svelte/store';
	import { usePage } from '@inertiajs/svelte';
	import { router } from '@inertiajs/svelte';
	import { onMount } from 'svelte';
	import { toaster } from '../../lib/toaster.js';

	interface PendingRequest {
		id: number;
		session_id: number;
		action_type: string;
		reason: string;
		created_at: string;
		session: { id: number; alias: string; status: string; track: string; current_station: { id: number; name: string } | null };
		requester: { id: number; name: string };
		target_station: { id: number; name: string } | null;
		target_track?: { id: number; name: string } | null;
	}

	interface AuthItem {
		id: number;
		type: string;
		expiry_mode?: 'time_only' | 'usage_only' | 'time_or_usage' | string;
		max_uses?: number | null;
		used_count?: number | null;
		created_at: string | null;
		expires_at: string | null;
		used_at: string | null;
		last_used_at?: string | null;
	}

	const TTL_OPTIONS = [
		{ label: '5 min', value: 300 },
		{ label: '15 min', value: 900 },
		{ label: '1 hr', value: 3600 },
		{ label: 'No expiry', value: 0 },
	];

	const MAX_USES_OPTIONS = [
		{ label: '1 use', value: 1 },
		{ label: '2 uses', value: 2 },
		{ label: '3 uses', value: 3 },
		{ label: '5 uses', value: 5 },
		{ label: '10 uses', value: 10 },
	];

	const STAFF_OVERRIDE_PREFS_KEY = 'flexiqueue-staff-override-prefs';

	let {
		canApprove = false,
		stations = [],
		tracks = [],
		authorizations = [],
		pendingRequests = [],
		queueCount = 0,
		processedToday = 0,
	}: {
		canApprove: boolean;
		stations: { id: number; name: string }[];
		tracks: { id: number; name: string }[];
		authorizations: AuthItem[];
		pendingRequests: PendingRequest[];
		queueCount?: number;
		processedToday?: number;
	} = $props();

	let tempCodeGenerated = $state<string | null>(null);
	let tempCodeExpiresAt = $state<string | null>(null);
	let tempQrDataUri = $state<string | null>(null);
	let tempQrExpiresAt = $state<string | null>(null);
	let selectedTtlSeconds = $state(300);
	let selectedExpiryMode = $state<'time_only' | 'usage_only' | 'time_or_usage'>('time_only');
	let selectedMaxUses = $state(1);
	let actionLoading = $state<string | null>(null);
	let rejectModalRequestId = $state<number | null>(null);
	let rejectReassignTrackId = $state<number | null>(null);
	let approveModalRequestId = $state<number | null>(null);
	let approveCustomPath = $state<number[]>([]);
	let approveCustomAddStationId = $state<number | ''>('');
	let deletingAuthorizationId = $state<number | null>(null);

	type StaffOverridePrefs = {
		mode: 'time_only' | 'usage_only' | 'time_or_usage';
		ttlSeconds: number;
		maxUses: number;
	};

	/** Real-time tick for live expiry countdowns (client-side, no polling). */
	let nowMs = $state(Date.now());
	$effect(() => {
		const id = setInterval(() => {
			nowMs = Date.now();
		}, 1000);
		return () => clearInterval(id);
	});

	const page = usePage();

	// Real-time: staff sees when their request is approved/rejected without reload
	onMount(() => {
		const user = (get(page)?.props as { auth?: { user?: { id: number } } })?.auth?.user;
		const userId = user?.id;
		const w = window as unknown as {
			Echo?: { private: (ch: string) => { listen: (ev: string, cb: () => void) => void }; leave: (ch: string) => void };
		};
		if (!userId || typeof window === 'undefined' || !w.Echo) return;

		// Load locally persisted Staff override defaults (per device).
		try {
			const raw = window.localStorage.getItem(STAFF_OVERRIDE_PREFS_KEY);
			if (raw) {
				const parsed = JSON.parse(raw) as Partial<StaffOverridePrefs>;
				const ttlValues = new Set(TTL_OPTIONS.map((t) => t.value));
				const maxUseValues = new Set(MAX_USES_OPTIONS.map((m) => m.value));

				if (parsed.mode === 'time_only' || parsed.mode === 'usage_only' || parsed.mode === 'time_or_usage') {
					selectedExpiryMode = parsed.mode;
				}
				if (typeof parsed.ttlSeconds === 'number' && ttlValues.has(parsed.ttlSeconds)) {
					selectedTtlSeconds = parsed.ttlSeconds;
				}
				if (typeof parsed.maxUses === 'number' && maxUseValues.has(parsed.maxUses)) {
					selectedMaxUses = parsed.maxUses;
				}
			}
		} catch {
			// Ignore parse/storage errors; fall back to defaults.
		}

		const Echo = w.Echo;
		const ch = `App.Models.User.${userId}`;
		Echo.private(ch).listen('.permission_request_responded', () => {
			router.reload();
		});

		return () => {
			Echo.leave(`private-${ch}`);
		};
	});

	// Persist Staff override preferences per device whenever the controls change.
	$effect(() => {
		if (typeof window === 'undefined') return;
		const prefs: StaffOverridePrefs = {
			mode: selectedExpiryMode,
			ttlSeconds: selectedTtlSeconds,
			maxUses: selectedMaxUses,
		};
		try {
			window.localStorage.setItem(STAFF_OVERRIDE_PREFS_KEY, JSON.stringify(prefs));
		} catch {
			// Ignore storage failures (e.g. private mode).
		}
	});

	function getCsrfToken(): string {
		const p = get(page);
		const fromProps = (p?.props as { csrf_token?: string } | undefined)?.csrf_token;
		if (fromProps) return fromProps;
		const meta = (typeof document !== 'undefined'
			? (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content
			: '') ?? '';
		return meta;
	}

	const MSG_SESSION_EXPIRED = 'Session expired. Please refresh and try again.';
	const MSG_NETWORK_ERROR = 'Network error. Please try again.';

	async function api(method: string, url: string, body?: object) {
		try {
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
			if (res.status === 419) {
				toaster.error({ title: MSG_SESSION_EXPIRED });
				return { ok: false, data: undefined, message: MSG_SESSION_EXPIRED };
			}
			const data = await res.json().catch(() => ({}));
			return { ok: res.ok, data, message: (data as { message?: string })?.message };
		} catch (e) {
			toaster.error({ title: MSG_NETWORK_ERROR });
			return { ok: false, data: undefined, message: MSG_NETWORK_ERROR };
		}
	}

	async function generateTempPin() {
		if (actionLoading) return;
		actionLoading = 'generate-temp-pin';
		const body: Record<string, unknown> = { expiry_mode: selectedExpiryMode };
		if (selectedExpiryMode === 'time_only' || selectedExpiryMode === 'time_or_usage') {
			body.expires_in_seconds = selectedTtlSeconds;
		}
		if (selectedExpiryMode === 'usage_only' || selectedExpiryMode === 'time_or_usage') {
			body.max_uses = selectedMaxUses;
		}
		const { ok, data } = await api('POST', '/api/auth/temporary-pin', body);
		actionLoading = null;
		if (ok) {
			toaster.success({ title: 'Temporary code generated.' });
			const d = data as { code?: string; expires_at?: string | null };
			tempCodeGenerated = d?.code ?? null;
			tempQrDataUri = null;
			tempQrExpiresAt = null;
			tempCodeExpiresAt = d?.expires_at ?? null;
			setTimeout(() => router.reload(), 1500);
		} else {
			toaster.error({ title: (data as { message?: string })?.message ?? 'Failed to generate' });
		}
	}

	async function generateTempQr() {
		if (actionLoading) return;
		actionLoading = 'generate-temp-qr';
		const body: Record<string, unknown> = { expiry_mode: selectedExpiryMode };
		if (selectedExpiryMode === 'time_only' || selectedExpiryMode === 'time_or_usage') {
			body.expires_in_seconds = selectedTtlSeconds;
		}
		if (selectedExpiryMode === 'usage_only' || selectedExpiryMode === 'time_or_usage') {
			body.max_uses = selectedMaxUses;
		}
		const { ok, data } = await api('POST', '/api/auth/temporary-qr', body);
		actionLoading = null;
		if (ok) {
			toaster.success({ title: 'Temporary QR generated.' });
			const d = data as { qr_data_uri?: string; expires_at?: string | null };
			tempQrDataUri = d?.qr_data_uri ?? null;
			tempCodeGenerated = null;
			tempCodeExpiresAt = null;
			tempQrExpiresAt = d?.expires_at ?? null;
			setTimeout(() => router.reload(), 1500);
		} else {
			toaster.error({ title: (data as { message?: string })?.message ?? 'Failed to generate' });
		}
	}

	function openApproveModal(pr: PendingRequest) {
		if (pr.action_type === 'override' && !pr.target_track && !pr.target_station) {
			approveModalRequestId = pr.id;
			approveCustomPath = [];
			approveCustomAddStationId = '';
		} else {
			approveRequest(pr.id);
		}
	}

	async function deleteAuthorization(id: number) {
		if (actionLoading) return;
		const confirmDelete = window.confirm('Revoke this authorization? It cannot be used after this.');
		if (!confirmDelete) return;
		actionLoading = `delete-auth-${id}`;
		const { ok } = await api('DELETE', `/api/auth/authorizations/${id}`);
		actionLoading = null;
		if (ok) {
			toaster.success({ title: 'Authorization revoked.' });
			router.reload();
		} else {
			toaster.error({ title: 'Failed to delete authorization' });
		}
	}

	function closeApproveModal() {
		approveModalRequestId = null;
		approveCustomPath = [];
	}

	function addStationToCustomPath() {
		const raw = approveCustomAddStationId;
		if (raw === '') return;
		const id = typeof raw === 'number' ? raw : parseInt(String(raw), 10);
		if (isNaN(id)) return;
		approveCustomPath = [...approveCustomPath, id];
		approveCustomAddStationId = '';
	}

	function removeStationFromCustomPath(index: number) {
		approveCustomPath = approveCustomPath.filter((_, i) => i !== index);
	}

	async function approveRequest(id: number, body?: { target_track_id?: number; custom_steps?: number[] }) {
		if (actionLoading) return;
		actionLoading = `approve-${id}`;
		const { ok, data } = await api('POST', `/api/permission-requests/${id}/approve`, body ?? {});
		actionLoading = null;
		if (ok) {
			toaster.success({ title: 'Request approved.' });
			closeApproveModal();
			router.reload();
		} else {
			toaster.error({ title: (data as { message?: string })?.message ?? 'Failed to approve' });
		}
	}

	function openRejectModal(prId: number) {
		rejectModalRequestId = prId;
		rejectReassignTrackId = null;
	}

	function closeRejectModal() {
		rejectModalRequestId = null;
		rejectReassignTrackId = null;
	}

	async function rejectRequest(id: number, body?: { reassign_track_id?: number }) {
		if (actionLoading) return;
		actionLoading = `reject-${id}`;
		const { ok } = await api('POST', `/api/permission-requests/${id}/reject`, body ?? {});
		actionLoading = null;
		if (ok) {
			toaster.success({ title: 'Request rejected.' });
			closeRejectModal();
			router.reload();
		} else {
			toaster.error({ title: 'Failed to reject' });
		}
	}

	function formatExpiry(iso: string | null, now: number): string {
		if (!iso) return '';
		try {
			const d = new Date(iso);
			const diff = Math.max(0, Math.round((d.getTime() - now) / 1000));
			if (diff <= 0) return 'Expired';
			const m = Math.floor(diff / 60);
			const s = diff % 60;
			return `${m}:${s.toString().padStart(2, '0')} left`;
		} catch {
			return '';
		}
	}
</script>

<svelte:head>
	<title>Program Overrides — FlexiQueue</title>
</svelte:head>

<MobileLayout headerTitle="Program Overrides" {queueCount} {processedToday}>
	<div class="flex flex-col gap-4 md:gap-6 text-surface-950 w-full max-w-2xl mx-auto px-4 md:px-6 py-4 md:py-6">
		{#if canApprove}
			<!-- Generate PIN/QR for staff actions -->
			<div class="rounded-container bg-surface-50 border border-surface-200 elevation-card p-4 md:p-6">
				<p class="text-sm font-medium text-surface-950/80 mb-3">Staff override tools</p>
				<p class="text-xs text-surface-950/60 mb-4">
					Generate a temporary PIN or QR for staff to authorize overrides, force-complete sessions, or quickly tweak display and public triage settings.
				</p>
				<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
					<div class="form-control w-full">
						<label class="label" for="expiryMode"><span class="label-text">Mode</span></label>
						<select
							id="expiryMode"
							class="select rounded-container border border-surface-200 px-3 touch-target-h"
							bind:value={selectedExpiryMode}
							onchange={(e) => (selectedExpiryMode = (e.target as HTMLSelectElement).value as typeof selectedExpiryMode)}
						>
							<option value="time_only">Time only</option>
							<option value="usage_only">Usage only</option>
							<option value="time_or_usage">Time or usage (whichever first)</option>
						</select>
					</div>

					{#if selectedExpiryMode === 'usage_only' || selectedExpiryMode === 'time_or_usage'}
						<div class="form-control w-full">
							<label class="label" for="maxUses"><span class="label-text">Max uses</span></label>
							<select
								id="maxUses"
								class="select rounded-container border border-surface-200 px-3 touch-target-h"
								bind:value={selectedMaxUses}
								onchange={(e) => (selectedMaxUses = Number((e.target as HTMLSelectElement).value))}
							>
								{#each MAX_USES_OPTIONS as opt}
									<option value={opt.value}>{opt.label}</option>
								{/each}
							</select>
						</div>
					{/if}

					{#if selectedExpiryMode === 'time_only' || selectedExpiryMode === 'time_or_usage'}
						<div class="form-control w-full">
							<label class="label" for="expiresIn"><span class="label-text">Expires in</span></label>
							<select
								id="expiresIn"
								class="select rounded-container border border-surface-200 px-3 touch-target-h"
								bind:value={selectedTtlSeconds}
								onchange={(e) => (selectedTtlSeconds = Number((e.target as HTMLSelectElement).value))}
							>
								{#each TTL_OPTIONS as opt}
									<option value={opt.value}>{opt.label}</option>
								{/each}
							</select>
						</div>
					{/if}
				</div>
				<div class="flex flex-wrap gap-3">
					<div class="flex flex-col gap-2">
						<button
							type="button"
							class="btn preset-outlined touch-target-h px-4"
							disabled={!!actionLoading}
							onclick={generateTempPin}
						>
							{actionLoading === 'generate-temp-pin' ? 'Generating…' : 'Generate 6-digit PIN'}
						</button>
						{#if tempCodeGenerated}
							<p class="text-warning-600 text-xs font-medium mb-1">Save this elsewhere — it is shown only once.</p>
							<p class="text-xl font-mono font-bold tracking-widest text-primary-500">{tempCodeGenerated}</p>
							<p class="text-xs text-surface-950/60">{formatExpiry(tempCodeExpiresAt, nowMs)}</p>
						{/if}
					</div>
					<div class="flex flex-col gap-2">
						<button
							type="button"
							class="btn preset-outlined touch-target-h px-4"
							disabled={!!actionLoading}
							onclick={generateTempQr}
						>
							{actionLoading === 'generate-temp-qr' ? 'Generating…' : 'Generate QR code'}
						</button>
						{#if tempQrDataUri}
							<p class="text-warning-600 text-xs font-medium mb-1">Save or share this elsewhere — it is shown only once.</p>
							<img src={tempQrDataUri} alt="Temporary QR" class="w-28 h-28" />
							<p class="text-xs text-surface-950/60">{formatExpiry(tempQrExpiresAt, nowMs)}</p>
						{/if}
					</div>
				</div>
			</div>

			<!-- Recent generated auths -->
			{#if authorizations.length > 0}
				<div class="rounded-container bg-surface-50 border border-surface-200 elevation-card p-4 md:p-5">
					<p class="text-sm font-medium text-surface-950/80 mb-3">Recent authorizations</p>
					<ul class="space-y-1 text-xs">
						{#each authorizations as a (a.id)}
							{@const maxUses = a.max_uses ?? null}
							{@const usedCount = a.used_count ?? 0}
							{@const usesLeft = maxUses !== null ? Math.max(0, maxUses - usedCount) : null}
							{@const timeLabel = a.expires_at ? formatExpiry(a.expires_at, nowMs) : ''}
							<li class="flex justify-between items-center py-1 gap-2">
								<div class="flex flex-col">
									<span class="font-mono text-surface-950">{a.type === 'pin' ? 'PIN' : 'QR'}</span>
									<span class="text-surface-950/60">{new Date(a.created_at ?? 0).toLocaleString()}</span>
								</div>
								<div class="flex items-center gap-2">
									{#if maxUses !== null && usesLeft <= 0}
										<span class="text-xs px-2 py-0.5 rounded preset-tonal badge-sm text-surface-950">Consumed</span>
									{:else if (a.expiry_mode === 'usage_only' || a.expiry_mode === 'time_or_usage') && usesLeft !== null}
										<span class="text-surface-950/50">{usesLeft} use{usesLeft === 1 ? '' : 's'} left</span>
									{:else if a.expires_at}
										<span class="text-surface-950/50">{timeLabel}</span>
									{:else}
										<span class="text-xs px-2 py-0.5 rounded preset-tonal badge-sm text-surface-950">No expiry</span>
									{/if}
									<button
										type="button"
										class="btn btn-icon btn-icon-sm preset-tonal"
										aria-label="Delete authorization"
										disabled={!!actionLoading}
										onclick={() => deleteAuthorization(a.id)}
									>
										✕
									</button>
								</div>
							</li>
						{/each}
					</ul>
				</div>
			{/if}
		{/if}

		<!-- Pending requests -->
		<div class="rounded-container bg-surface-50 border border-surface-200 elevation-card p-4 md:p-6">
			<p class="text-sm md:text-base font-medium text-surface-950/80 mb-4">
				{canApprove ? 'Pending requests' : 'Your pending requests'}
			</p>
			{#if pendingRequests.length === 0}
				<p class="text-sm text-surface-950/60 py-4">
					{canApprove ? 'No pending requests.' : 'No pending requests. Request approval from the Override or Force Complete modal on the Station page.'}
				</p>
			{:else}
				<ul class="space-y-3 md:space-y-4">
					{#each pendingRequests as pr (pr.id)}
						<li class="rounded-container bg-surface-100 border border-surface-200 p-3 md:p-4 space-y-2">
							<div class="flex justify-between items-start gap-2">
								<div>
									<span class="badge text-xs {pr.action_type === 'override' ? 'preset-filled-primary-500' : 'preset-filled-warning-500'}">{pr.action_type.replace('_', ' ')}</span>
									<span class="font-mono font-semibold ml-1 text-surface-950">{pr.session.alias}</span>
								</div>
								{#if canApprove}
									<span class="text-xs text-surface-950/60">{pr.requester.name}</span>
								{/if}
							</div>
							<p class="text-xs text-surface-950/70">{pr.reason}</p>
							{#if pr.action_type === 'override'}
								{#if pr.target_track}
									<p class="text-xs text-surface-950/60">→ {pr.target_track.name}</p>
								{:else if pr.target_station}
									<p class="text-xs text-surface-950/60">→ {pr.target_station.name}</p>
								{:else}
									<p class="text-xs text-surface-950/60">→ Custom (define path below)</p>
								{/if}
							{/if}
							<p class="text-xs text-surface-950/50">{new Date(pr.created_at).toLocaleString()}</p>
							{#if canApprove}
								<div class="flex flex-wrap gap-2 pt-2">
									<button
										type="button"
										class="btn preset-filled-primary-500 touch-target px-4"
										disabled={!!actionLoading}
										onclick={() => openApproveModal(pr)}
									>
										{actionLoading === `approve-${pr.id}` ? '…' : 'Approve'}
									</button>
									{#if pr.action_type === 'override'}
										<button
											type="button"
											class="btn preset-filled-error-500 touch-target px-4"
											disabled={!!actionLoading}
											onclick={() => openRejectModal(pr.id)}
										>
											Reject
										</button>
									{:else}
										<button
											type="button"
											class="btn preset-filled-error-500 touch-target px-4"
											disabled={!!actionLoading}
											onclick={() => rejectRequest(pr.id)}
										>
											{actionLoading === `reject-${pr.id}` ? '…' : 'Reject'}
										</button>
									{/if}
								</div>
							{:else}
								<p class="text-xs text-warning-500">Waiting for supervisor approval…</p>
							{/if}
						</li>
					{/each}
				</ul>
			{/if}
		</div>
	</div>

	{#if approveModalRequestId}
		<dialog open class="modal-dialog-center rounded-container" oncancel={(e) => e.preventDefault()}>
			<div class="card bg-surface-50 rounded-container shadow-xl p-6 max-w-sm text-surface-950 relative">
				<button
					type="button"
					class="btn btn-icon btn-icon-sm preset-tonal absolute right-2 top-2"
					aria-label="Close"
					onclick={closeApproveModal}
				>✕</button>
				<h3 class="font-bold text-lg">Define custom path</h3>
				<p class="text-sm text-surface-950/70 py-2">Add stations in the order the client should visit. Admin defines the one-off path.</p>
				<div class="form-control w-full mt-2">
					<label class="label" for="customPathAddStation"><span class="label-text">Add station</span></label>
					<div class="flex gap-2">
						<select
							id="customPathAddStation"
							class="select rounded-container border border-surface-200 px-3 touch-target-h flex-1"
							bind:value={approveCustomAddStationId}
							onchange={(e) => { approveCustomAddStationId = (e.target as HTMLSelectElement).value === '' ? '' : Number((e.target as HTMLSelectElement).value); }}
						>
							<option value="">Select station…</option>
							{#each stations as st (st.id)}
								<option value={st.id}>{st.name}</option>
							{/each}
						</select>
						<button type="button" class="btn preset-outlined touch-target px-4" onclick={addStationToCustomPath} disabled={approveCustomAddStationId === ''}>Add</button>
					</div>
				</div>
				{#if approveCustomPath.length > 0}
					<div class="mt-3">
						<p class="text-xs font-medium text-surface-950/70 mb-2">Path (order):</p>
						<ul class="space-y-1">
							{#each approveCustomPath as stationId, i}
								{@const st = stations.find(s => s.id === stationId)}
								<li class="flex justify-between items-center text-sm text-surface-950 gap-2">
									<span>{i + 1}. {st?.name ?? stationId}</span>
									<button type="button" class="btn preset-tonal touch-target shrink-0" onclick={() => removeStationFromCustomPath(i)} aria-label="Remove station">×</button>
								</li>
							{/each}
						</ul>
					</div>
				{/if}
				<div class="flex flex-wrap justify-end gap-2 mt-4">
					<button type="button" class="btn preset-tonal touch-target-h px-4" onclick={closeApproveModal}>Cancel</button>
					<button
						type="button"
						class="btn preset-filled-primary-500 touch-target-h px-4"
						disabled={approveCustomPath.length === 0 || !!actionLoading}
						onclick={() => approveRequest(approveModalRequestId!, { custom_steps: approveCustomPath })}
					>
						{actionLoading === `approve-${approveModalRequestId}` ? '…' : 'Approve with path'}
					</button>
				</div>
			</div>
			<!-- Per flexiqueue-ldd: backdrop does not close modal; only Cancel/Close buttons do -->
			<div class="modal-backdrop" aria-hidden="true"></div>
		</dialog>
	{/if}

	{#if rejectModalRequestId}
		<dialog open class="modal-dialog-center rounded-container" oncancel={(e) => e.preventDefault()}>
			<div class="card bg-surface-50 rounded-container shadow-xl p-6 max-w-sm text-surface-950 relative">
				<button
					type="button"
					class="btn btn-icon btn-icon-sm preset-tonal absolute right-2 top-2"
					aria-label="Close"
					onclick={closeRejectModal}
				>✕</button>
				<h3 class="font-bold text-lg">Reject and reassign?</h3>
				<p class="text-sm text-surface-950/70 py-2">Optionally reassign the session to a track instead of leaving it awaiting approval.</p>
				<div class="form-control w-full mt-2">
					<label class="label" for="rejectReassignTrack"><span class="label-text">Reassign to track (optional)</span></label>
					<select
						id="rejectReassignTrack"
						class="select rounded-container border border-surface-200 px-3 touch-target-h w-full"
						value={rejectReassignTrackId ?? ''}
						onchange={(e) => {
							const v = (e.target as HTMLSelectElement).value;
							rejectReassignTrackId = v === '' ? null : Number(v);
						}}
					>
						<option value="">Don't reassign</option>
						{#each tracks as t (t.id)}
							<option value={t.id}>{t.name}</option>
						{/each}
					</select>
				</div>
				<div class="flex flex-wrap justify-end gap-2 mt-4">
					<button type="button" class="btn preset-tonal touch-target-h px-4" onclick={closeRejectModal}>Cancel</button>
					<button
						type="button"
						class="btn preset-filled-error-500 touch-target-h px-4"
						disabled={!!actionLoading}
						onclick={() => rejectRequest(rejectModalRequestId!, rejectReassignTrackId ? { reassign_track_id: rejectReassignTrackId } : undefined)}
					>
						{actionLoading === `reject-${rejectModalRequestId}` ? '…' : 'Reject'}
					</button>
				</div>
			</div>
			<div class="modal-backdrop" aria-hidden="true"></div>
		</dialog>
	{/if}
</MobileLayout>

