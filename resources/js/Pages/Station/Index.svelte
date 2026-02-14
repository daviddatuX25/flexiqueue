<script lang="ts">
	import MobileLayout from '../../Layouts/MobileLayout.svelte';
	import { get } from 'svelte/store';
	import { usePage } from '@inertiajs/svelte';
	import { router } from '@inertiajs/svelte';

	interface StationInfo {
		id: number;
		name: string;
	}

	interface ServingSession {
		session_id: number;
		alias: string;
		track: string;
		client_category: string;
		status: string;
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
	}

	interface QueueData {
		station: { id: number; name: string };
		now_serving: ServingSession | null;
		waiting: WaitingSession[];
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
	let noShowModalSession = $state<ServingSession | null>(null);

	const page = usePage();
	const authUser = $derived((get(page)?.props as { auth?: { user?: { id: number; role?: string | { value?: string } } } })?.auth?.user ?? null);
	const userRole = $derived(
		typeof authUser?.role === 'string' ? authUser.role : (authUser?.role as { value?: string } | undefined)?.value ?? ''
	);

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

	async function fetchQueue() {
		if (!station) return;
		loading = true;
		error = '';
		const { ok, data } = await api('GET', `/api/stations/${station.id}/queue`);
		loading = false;
		if (ok) {
			queue = data as QueueData;
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

	function formatDuration(iso: string): string {
		const d = new Date(iso);
		const now = new Date();
		const mins = Math.floor((now.getTime() - d.getTime()) / 60000);
		if (mins < 1) return '< 1 min';
		if (mins < 60) return `${mins} min`;
		return `${Math.floor(mins / 60)}h ${mins % 60}m`;
	}

	function switchStation(s: StationInfo) {
		router.visit(`/station/${s.id}`);
	}

	async function callNext() {
		const first = queue?.waiting?.[0];
		if (!first || actionLoading) return;
		actionLoading = 'call';
		const { ok, data } = await api('POST', `/api/sessions/${first.session_id}/call`, {});
		actionLoading = null;
		if (ok) {
			await fetchQueue();
		} else {
			error = (data as { message?: string })?.message ?? 'Call failed';
		}
	}

	async function transfer() {
		const s = queue?.now_serving;
		if (!s || actionLoading) return;
		actionLoading = 'transfer';
		error = '';
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/transfer`, { mode: 'standard' });
		actionLoading = null;
		if (ok) {
			const d = data as { action_required?: string };
			if (d?.action_required === 'complete') {
				error = 'No next station in track. Complete the session instead.';
			}
			await fetchQueue();
		} else {
			error = (data as { message?: string })?.message ?? 'Transfer failed';
		}
	}

	async function complete() {
		const s = queue?.now_serving;
		if (!s || actionLoading) return;
		actionLoading = 'complete';
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/complete`, {});
		actionLoading = null;
		if (ok) {
			await fetchQueue();
		} else {
			error = (data as { message?: string })?.message ?? 'Complete failed';
		}
	}

	async function cancel() {
		const s = queue?.now_serving;
		if (!s || actionLoading) return;
		actionLoading = 'cancel';
		const { ok } = await api('POST', `/api/sessions/${s.session_id}/cancel`, { remarks: 'Cancelled by staff' });
		actionLoading = null;
		if (ok) await fetchQueue();
		else error = 'Cancel failed';
	}

	async function noShow() {
		const s = noShowModalSession ?? queue?.now_serving;
		if (!s || actionLoading) return;
		actionLoading = 'noShow';
		const { ok } = await api('POST', `/api/sessions/${s.session_id}/no-show`, {});
		actionLoading = null;
		noShowModalSession = null;
		if (ok) await fetchQueue();
		else error = 'No-show failed';
	}

	async function override() {
		const s = queue?.now_serving;
		if (!s || !overrideTargetStationId || !overrideReason.trim() || !overridePin || !overrideSupervisorId || actionLoading)
			return;
		actionLoading = 'override';
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/override`, {
			target_station_id: overrideTargetStationId,
			reason: overrideReason.trim(),
			supervisor_user_id: overrideSupervisorId,
			supervisor_pin: overridePin,
		});
		actionLoading = null;
		if (ok) {
			showOverrideModal = false;
			overrideTargetStationId = null;
			overrideReason = '';
			overridePin = '';
			overrideSupervisorId = null;
			await fetchQueue();
		} else {
			error = (data as { message?: string })?.message ?? 'Override failed';
		}
	}

	const isLastStep = $derived(
		queue?.now_serving
			? (queue.now_serving.current_step_order ?? 0) >= (queue.now_serving.total_steps ?? 1)
			: false
	);
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
							<button
								type="button"
								class="btn btn-outline btn-block"
								onclick={() => switchStation(s)}
							>
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
			<!-- Station header -->
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

			{#if queue.now_serving}
				<!-- Current client card -->
				<div class="rounded-box bg-base-100 border border-base-300 p-4 space-y-3">
					<p class="text-xs font-medium text-base-content/70 uppercase tracking-wide">Now Serving</p>
					<p class="text-5xl font-bold text-primary tabular-nums">{queue.now_serving.alias}</p>
					<div class="flex flex-wrap gap-2">
						<span class="badge badge-outline">{queue.now_serving.track}</span>
						<span class="badge badge-ghost">{queue.now_serving.client_category}</span>
					</div>
					<p class="text-sm text-base-content/70">
						Step {queue.now_serving.current_step_order} of {queue.now_serving.total_steps} · Started {formatDuration(queue.now_serving.started_at)} ago
					</p>

					<!-- Primary action -->
					<div class="flex flex-col gap-2 pt-2">
						{#if isLastStep}
							<button
								type="button"
								class="btn btn-primary btn-lg"
								disabled={!!actionLoading}
								onclick={complete}
							>
								{actionLoading === 'complete' ? 'Completing…' : 'Complete Session'}
							</button>
						{:else}
							<button
								type="button"
								class="btn btn-primary btn-lg"
								disabled={!!actionLoading}
								onclick={transfer}
							>
								{actionLoading === 'transfer' ? 'Transferring…' : 'Send to Next Station'}
							</button>
						{/if}

						<!-- Secondary actions -->
						<div class="flex flex-wrap gap-2">
							{#if canSwitchStation}
								<button
									type="button"
									class="btn btn-outline btn-sm"
									disabled={!!actionLoading}
									onclick={() => {
										showOverrideModal = true;
										if (authUser && ['admin', 'supervisor'].includes(userRole) && authUser.id)
											overrideSupervisorId = authUser.id;
									}}
								>
									Override
								</button>
							{/if}
							<button
								type="button"
								class="btn btn-ghost btn-sm"
								disabled={!!actionLoading}
								onclick={cancel}
							>
								Cancel
							</button>
							<button
								type="button"
								class="btn btn-ghost btn-sm {queue.now_serving.no_show_attempts >= 2 ? 'btn-warning' : ''}"
								disabled={!!actionLoading}
								onclick={() => (noShowModalSession = queue!.now_serving!)}
							>
								No-Show {queue.now_serving.no_show_attempts > 0 ? `(${queue.now_serving.no_show_attempts}/3)` : ''}
							</button>
						</div>
					</div>
				</div>
			{:else}
				<!-- No client - call next -->
				<div class="rounded-box bg-base-100 border border-base-300 p-6 text-center">
					<p class="text-base-content/70 font-medium mb-4">No client active</p>
					{#if queue.waiting.length > 0}
						<p class="text-sm text-base-content/60 mb-3">
							Next: <span class="font-mono font-semibold">{queue.waiting[0].alias}</span> ({queue.waiting[0].track})
						</p>
						<button
							type="button"
							class="btn btn-primary btn-lg"
							disabled={!!actionLoading}
							onclick={callNext}
						>
							{actionLoading === 'call' ? 'Calling…' : 'Call Next Client'}
						</button>
					{:else}
						<p class="text-sm text-base-content/60">Queue is empty</p>
					{/if}
				</div>
			{/if}

			<!-- Queue preview -->
			{#if queue.waiting.length > 0}
				<div class="rounded-box bg-base-100 border border-base-300 p-4">
					<p class="text-xs font-medium text-base-content/70 uppercase tracking-wide mb-2">Queue — Next {Math.min(5, queue.waiting.length)}</p>
					<ul class="space-y-2">
						{#each queue.waiting.slice(0, 5) as w (w.session_id)}
							<li class="flex justify-between items-center text-sm">
								<span class="font-mono font-medium">{w.alias}</span>
								<span class="text-base-content/60">{w.track} · {formatDuration(w.queued_at)}</span>
							</li>
						{/each}
					</ul>
				</div>
			{/if}

			<!-- Stats -->
			<div class="rounded-box bg-base-100 border border-base-300 p-3 text-center text-sm text-base-content/70">
				Today: {queue.stats.total_served_today} served · Avg {queue.stats.avg_service_time_minutes} min
			</div>
		{/if}
	</div>

	<!-- No-show confirm modal -->
	{#if noShowModalSession}
		<dialog open class="modal modal-open">
			<div class="modal-box">
				<h3 class="font-bold text-lg">Mark No-Show</h3>
				<p class="py-2">
					Mark <span class="font-mono font-semibold">{noShowModalSession.alias}</span> as no-show? This will end the session and free the token.
				</p>
				<div class="modal-action">
					<button type="button" class="btn btn-ghost" onclick={() => (noShowModalSession = null)}>
						Cancel
					</button>
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

	<!-- Override modal -->
	{#if showOverrideModal}
		<dialog open class="modal modal-open">
			<div class="modal-box">
				<h3 class="font-bold text-lg">Override standard flow</h3>
				<p class="text-sm text-base-content/70 py-2">Send client to a different station. Requires supervisor PIN.</p>
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
				{#if error}
					<div class="alert alert-error mt-2 text-sm">{error}</div>
				{/if}
				<div class="modal-action">
					<button
						type="button"
						class="btn btn-ghost"
						onclick={() => {
							showOverrideModal = false;
							overrideTargetStationId = null;
							overrideReason = '';
							overridePin = '';
							overrideSupervisorId = null;
							error = '';
						}}
					>
						Cancel
					</button>
					<button
						type="button"
						class="btn btn-primary"
						disabled={
							!overrideTargetStationId || !overrideReason.trim() || !overridePin || overridePin.length !== 6 || !overrideSupervisorId || !!actionLoading
						}
						onclick={override}
					>
						{actionLoading === 'override' ? 'Processing…' : 'Confirm Override'}
					</button>
				</div>
			</div>
			<form method="dialog" class="modal-backdrop">
				<button
					type="button"
					onclick={() => {
						showOverrideModal = false;
						overrideTargetStationId = null;
						overrideReason = '';
						overridePin = '';
						overrideSupervisorId = null;
						error = '';
					}}
				>
					close
				</button>
			</form>
		</dialog>
	{/if}
</MobileLayout>
