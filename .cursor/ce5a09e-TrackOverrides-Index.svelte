<script lang="ts">
	import MobileLayout from '../../Layouts/MobileLayout.svelte';
	import { get } from 'svelte/store';
	import { usePage } from '@inertiajs/svelte';
	import { router } from '@inertiajs/svelte';
	import { onMount } from 'svelte';

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
		created_at: string | null;
		expires_at: string | null;
		used_at: string | null;
	}

	const TTL_OPTIONS = [
		{ label: '5 min', value: 300 },
		{ label: '15 min', value: 900 },
		{ label: '1 hr', value: 3600 },
		{ label: 'No expiry', value: 0 },
	];

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
	let actionLoading = $state<string | null>(null);
	let nowMs = $state(Date.now());
	let error = $state('');
	let rejectModalRequestId = $state<number | null>(null);
	let rejectReassignTrackId = $state<number | null>(null);
	let approveModalRequestId = $state<number | null>(null);
	let approveCustomPath = $state<number[]>([]);
	let approveCustomAddStationId = $state<number | ''>('');

	const page = usePage();

	$effect(() => {
		const id = setInterval(() => {
			nowMs = Date.now();
		}, 1000);
		return () => clearInterval(id);
	});

	// Real-time: staff sees when their request is approved/rejected without reload
	onMount(() => {
		const user = (get(page)?.props as { auth?: { user?: { id: number } } })?.auth?.user;
		const userId = user?.id;
		if (!userId || typeof window === 'undefined' || !(window as { Echo?: { private: (ch: string) => { listen: (ev: string, cb: () => void) => void }; leave: (ch: string) => void } }).Echo) return;

		const Echo = (window as { Echo: { private: (ch: string) => { listen: (ev: string, cb: () => void) => void }; leave: (ch: string) => void } }).Echo;
		const ch = `App.Models.User.${userId}`;
		Echo.private(ch).listen('.permission_request_responded', () => {
			router.reload();
		});

		return () => {
			Echo.leave(`private-${ch}`);
		};
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

	async function api(method: string, url: string, body?: object) {
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

	async function generateTempPin() {
		if (actionLoading) return;
		actionLoading = 'generate-temp-pin';
		error = '';
		const { ok, data } = await api('POST', '/api/auth/temporary-pin', { expires_in_seconds: selectedTtlSeconds });
		actionLoading = null;
		if (ok) {
			const d = data as { code?: string; expires_at?: string };
			tempCodeGenerated = d?.code ?? null;
			tempQrDataUri = null;
			tempQrExpiresAt = null;
			tempCodeExpiresAt = d?.expires_at ?? null;
		} else {
			error = (data as { message?: string })?.message ?? 'Failed to generate';
		}
	}

	async function generateTempQr() {
		if (actionLoading) return;
		actionLoading = 'generate-temp-qr';
		error = '';
		const { ok, data } = await api('POST', '/api/auth/temporary-qr', { expires_in_seconds: selectedTtlSeconds });
		actionLoading = null;
		if (ok) {
			const d = data as { qr_data_uri?: string; expires_at?: string };
			tempQrDataUri = d?.qr_data_uri ?? null;
			tempCodeGenerated = null;
			tempCodeExpiresAt = null;
			tempQrExpiresAt = d?.expires_at ?? null;
		} else {
			error = (data as { message?: string })?.message ?? 'Failed to generate';
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

	async function approveRequest(id: number, body?: { target_track_id?: number; custom_steps?: number[] }) {
		if (actionLoading) return;
		actionLoading = `approve-${id}`;
		error = '';
		const { ok, data } = await api('POST', `/api/permission-requests/${id}/approve`, body ?? {});
		actionLoading = null;
		if (ok) {
			closeApproveModal();
			router.reload();
		} else {
			error = (data as { message?: string })?.message ?? 'Failed to approve';
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
		error = '';
		const { ok } = await api('POST', `/api/permission-requests/${id}/reject`, body ?? {});
		actionLoading = null;
		if (ok) {
			closeRejectModal();
			router.reload();
		} else {
			error = 'Failed to reject';
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
	<title>Track Overrides — FlexiQueue</title>
</svelte:head>

<MobileLayout headerTitle="Track Overrides" {queueCount} {processedToday}>
	<div class="flex flex-col gap-4 md:gap-6 text-surface-950 w-full max-w-2xl mx-auto px-4 md:px-6 py-4 md:py-6">
		{#if canApprove}
			<!-- Generate PIN/QR for staff -->
			<div class="rounded-container bg-surface-50 border border-surface-200 elevation-card p-4 md:p-6">
				<p class="text-sm font-medium text-surface-950/80 mb-3">Generate for staff</p>
				<p class="text-xs text-surface-950/60 mb-4">Create temporary PIN or QR for staff to authorize override or force-complete.</p>
				<div class="form-control w-full max-w-xs mb-4">
					<label class="label"><span class="label-text">Expiry</span></label>
					<select
						class="select rounded-container border border-surface-200 px-3 min-h-[48px]"
						bind:value={selectedTtlSeconds}
						onchange={(e) => (selectedTtlSeconds = Number((e.target as HTMLSelectElement).value))}
					>
						{#each TTL_OPTIONS as opt}
							<option value={opt.value}>{opt.label}</option>
						{/each}
					</select>
				</div>
				<div class="flex flex-wrap gap-3">
					<div class="flex flex-col gap-2">
						<button
							type="button"
							class="btn preset-outlined min-h-[48px] px-4"
							disabled={!!actionLoading}
							onclick={generateTempPin}
						>
							{actionLoading === 'generate-temp-pin' ? 'Generating…' : 'Generate 6-digit PIN'}
						</button>
						{#if tempCodeGenerated}
							<p class="text-xl font-mono font-bold tracking-widest text-primary-500">{tempCodeGenerated}</p>
							<p class="text-xs text-surface-950/60">{formatExpiry(tempCodeExpiresAt, $nowMs)}</p>
						{/if}
					</div>
					<div class="flex flex-col gap-2">
						<button
							type="button"
							class="btn preset-outlined min-h-[48px] px-4"
							disabled={!!actionLoading}
							onclick={generateTempQr}
						>
							{actionLoading === 'generate-temp-qr' ? 'Generating…' : 'Generate QR code'}
						</button>
						{#if tempQrDataUri}
							<img src={tempQrDataUri} alt="Temporary QR" class="w-28 h-28" />
							<p class="text-xs text-surface-950/60">{formatExpiry(tempQrExpiresAt, $nowMs)}</p>
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
							<li class="flex justify-between items-center py-1">
								<span class="font-mono text-surface-950">{a.type === 'pin' ? 'PIN' : 'QR'}</span>
								<span class="text-surface-950/60">{new Date(a.created_at ?? 0).toLocaleString()}</span>
								{#if a.used_at}
									<span class="text-xs px-2 py-0.5 rounded preset-tonal badge-sm text-surface-950">Used</span>
								{:else if a.expires_at}
									<span class="text-surface-950/50">{formatExpiry(a.expires_at, $nowMs)}</span>
								{:else}
									<span class="text-xs px-2 py-0.5 rounded preset-tonal badge-sm text-surface-950">No expiry</span>
								{/if}
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
			{#if error}
				<div class="rounded-container bg-error-100 text-error-900 border border-error-300 p-4 text-sm mb-4">{error}</div>
			{/if}
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
										class="btn preset-filled-primary-500 min-h-[48px] min-w-[48px] px-4"
										disabled={!!actionLoading}
										onclick={() => openApproveModal(pr)}
									>
										{actionLoading === `approve-${pr.id}` ? '…' : 'Approve'}
									</button>
									{#if pr.action_type === 'override'}
										<button
											type="button"
											class="btn preset-filled-error-500 min-h-[48px] min-w-[48px] px-4"
											disabled={!!actionLoading}
											onclick={() => openRejectModal(pr.id)}
										>
											Reject
										</button>
									{:else}
										<button
											type="button"
											class="btn preset-filled-error-500 min-h-[48px] min-w-[48px] px-4"
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
					<label class="label"><span class="label-text">Add station</span></label>
					<div class="flex gap-2">
						<select
							class="select rounded-container border border-surface-200 px-3 min-h-[48px] flex-1"
							bind:value={approveCustomAddStationId}
							onchange={(e) => { approveCustomAddStationId = (e.target as HTMLSelectElement).value === '' ? '' : Number((e.target as HTMLSelectElement).value); }}
						>
							<option value="">Select station…</option>
							{#each stations as st (st.id)}
								<option value={st.id}>{st.name}</option>
							{/each}
						</select>
						<button type="button" class="btn preset-outlined min-h-[48px] min-w-[48px] px-4" onclick={addStationToCustomPath} disabled={approveCustomAddStationId === ''}>Add</button>
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
									<button type="button" class="btn preset-tonal min-h-[48px] min-w-[48px] shrink-0" onclick={() => removeStationFromCustomPath(i)} aria-label="Remove station">×</button>
								</li>
							{/each}
						</ul>
					</div>
				{/if}
				<div class="flex flex-wrap justify-end gap-2 mt-4">
					<button type="button" class="btn preset-tonal min-h-[48px] px-4" onclick={closeApproveModal}>Cancel</button>
					<button
						type="button"
						class="btn preset-filled-primary-500 min-h-[48px] px-4"
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
					<label class="label"><span class="label-text">Reassign to track (optional)</span></label>
					<select
						class="select rounded-container border border-surface-200 px-3 min-h-[48px] w-full"
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
					<button type="button" class="btn preset-tonal min-h-[48px] px-4" onclick={closeRejectModal}>Cancel</button>
					<button
						type="button"
						class="btn preset-filled-error-500 min-h-[48px] px-4"
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
