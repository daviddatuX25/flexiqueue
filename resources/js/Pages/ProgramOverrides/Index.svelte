<script lang="ts">
	import MobileLayout from '../../Layouts/MobileLayout.svelte';
	import Modal from '../../Components/Modal.svelte';
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

	let selectedTtlSeconds = $state(300);
	let selectedExpiryMode = $state<'time_only' | 'usage_only' | 'time_or_usage'>('time_only');
	let selectedMaxUses = $state(1);
	let actionLoading = $state<string | null>(null);
	let rejectModalRequestId = $state<number | null>(null);
	let rejectReassignTrackId = $state<number | null>(null);
	let approveModalRequestId = $state<number | null>(null);
	let approveCustomPath = $state<number[]>([]);
	let approveCustomAddStationId = $state<number | ''>('');

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

	function openApproveModal(pr: PendingRequest) {
		if (pr.action_type === 'override' && !pr.target_track && !pr.target_station) {
			approveModalRequestId = pr.id;
			approveCustomPath = [];
			approveCustomAddStationId = '';
		} else {
			approveRequest(pr.id);
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

	async function approveRequest(id: number, body?: { target_track_id?: number; custom_steps?: number[]; request_token?: string }) {
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
	<title>Track overrides — FlexiQueue</title>
</svelte:head>

<MobileLayout headerTitle="Track overrides" {queueCount} {processedToday}>
	<div class="flex flex-col gap-4 md:gap-6 text-surface-950 w-full max-w-2xl mx-auto px-4 md:px-6 py-4 md:py-6">
		<!-- Pending requests -->
		<div class="rounded-container bg-surface-50 border border-surface-200 elevation-card p-4 md:p-6">
			<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
				<p class="text-sm md:text-base font-medium text-surface-950/80 m-0">
					{canApprove ? 'Pending requests' : 'Your pending requests'}
				</p>
			</div>
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
		<Modal open={!!approveModalRequestId} onClose={closeApproveModal} title="Define custom path">
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
		</Modal>
	{/if}

	{#if rejectModalRequestId}
		<Modal open={!!rejectModalRequestId} onClose={closeRejectModal} title="Reject and reassign?">
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
		</Modal>
	{/if}

</MobileLayout>

