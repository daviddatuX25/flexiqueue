<script lang="ts">
	import MobileLayout from '../../Layouts/MobileLayout.svelte';
	import QrScanner from '../../Components/QrScanner.svelte';
	import { get } from 'svelte/store';
	import { usePage } from '@inertiajs/svelte';

	interface Track {
		id: number;
		name: string;
		color_code: string | null;
		is_default: boolean;
	}

	interface ActiveProgram {
		id: number;
		name: string;
		tracks: Track[];
	}

	let {
		activeProgram = null,
		queueCount = 0,
		processedToday = 0,
	}: {
		activeProgram: ActiveProgram | null;
		queueCount?: number;
		processedToday?: number;
	} = $props();

	const CATEGORIES = [
		{ label: 'Regular', value: 'Regular' },
		{ label: 'PWD / Senior / Pregnant', value: 'PWD / Senior / Pregnant' },
		{ label: 'Incomplete Documents', value: 'Incomplete Documents' },
	] as const;

	let showCamera = $state(false);
	let scannedToken = $state<{ physical_id: string; qr_hash: string; status: string } | null>(null);
	/** Latch: ignore repeated onScan callbacks after first successful scan (stops flicker). */
	let scanHandled = $state(false);
	let manualPhysicalId = $state('');
	let selectedCategory = $state<string | null>(null);
	let selectedTrackId = $state<number | null>(null);
	let error = $state('');
	let isSubmitting = $state(false);

	const page = usePage();
	function getCsrfToken(): string {
		const p = get(page);
		const fromProps = (p?.props as { csrf_token?: string } | undefined)?.csrf_token;
		if (fromProps) return fromProps;
		const meta = typeof document !== 'undefined' ? (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content : '';
		return meta ?? '';
	}

	async function api(method: string, url: string, body?: object): Promise<{ ok: boolean; data?: object; message?: string; errors?: Record<string, string[]> }> {
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
		return { ok: res.ok, data, message: data?.message, errors: data?.errors };
	}

	function setDefaultTrack() {
		if (activeProgram?.tracks?.length) {
			const def = activeProgram.tracks.find((t) => t.is_default);
			selectedTrackId = def?.id ?? activeProgram.tracks[0]?.id ?? null;
		}
	}

	$effect(() => {
		if (activeProgram?.tracks?.length && selectedTrackId === null) {
			setDefaultTrack();
		}
	});

	async function handleLookup() {
		const id = manualPhysicalId.trim();
		if (!id) return;
		error = '';
		scannedToken = null;
		const { ok, data, message } = await api('GET', `/api/sessions/token-lookup?physical_id=${encodeURIComponent(id)}`);
		if (!ok) {
			error = message ?? 'Token not found.';
			scanHandled = false;
			return;
		}
		const t = data as { physical_id: string; qr_hash: string; status: string };
		if (t.status !== 'available') {
			error = t.status === 'in_use' ? 'Token is already in use.' : `Token is marked as ${t.status}.`;
			// Consume the scan so QR scanner doesn't keep firing and re-clearing/setting error (flashing)
			scanHandled = true;
			return;
		}
		scannedToken = t;
	}

	function handleQrScan(decodedText: string) {
		if (scanHandled) return;
		scanHandled = true;
		error = '';
		// If it looks like a short physical_id (e.g. A1, B12), look up; else treat as qr_hash
		const trimmed = decodedText.trim();
		if (trimmed.length <= 10 && /^[A-Za-z0-9]+$/.test(trimmed)) {
			manualPhysicalId = trimmed;
			handleLookup();
			return;
		}
		if (trimmed.length === 64 && /^[a-f0-9]+$/.test(trimmed)) {
			scannedToken = { physical_id: 'Scanned', qr_hash: trimmed, status: 'available' };
			return;
		}
		// Assume it could be physical_id anyway
		manualPhysicalId = trimmed.slice(0, 10);
		handleLookup();
	}

	function resetScan() {
		scannedToken = null;
		scanHandled = true;
		manualPhysicalId = '';
		selectedCategory = null;
		setDefaultTrack();
		error = '';
		showCamera = false;
	}

	async function handleConfirm() {
		if (!scannedToken || selectedCategory === null || selectedTrackId === null) return;
		error = '';
		isSubmitting = true;
		const { ok, data, message } = await api('POST', '/api/sessions/bind', {
			qr_hash: scannedToken.qr_hash,
			track_id: Number(selectedTrackId),
			client_category: selectedCategory,
		});
		isSubmitting = false;
		if (ok) {
			resetScan();
			// Could show toast here
			return;
		}
		const d = data as { active_session?: { alias: string }; token_status?: string } | undefined;
		if (d?.active_session) {
			error = `Token already in use (${d.active_session.alias}).`;
		} else if (d?.token_status) {
			error = `Token is marked as ${d.token_status}.`;
		} else {
			error = message ?? 'Bind failed.';
		}
	}
</script>

<svelte:head>
	<title>Triage — FlexiQueue</title>
</svelte:head>

<MobileLayout headerTitle="Triage" {queueCount} {processedToday}>
	<div class="flex flex-col gap-4">
		{#if !activeProgram}
			<div class="rounded-box bg-base-100 border border-base-300 p-6 text-center text-base-content/80">
				<p class="font-medium">No active program</p>
				<p class="mt-1 text-sm">Activate a program from Admin → Programs.</p>
			</div>
		{:else}
			<h1 class="text-xl font-semibold text-base-content">Triage</h1>

			{#if !scannedToken}
				<!-- Get token: scan or enter ID (unified) -->
				<div class="rounded-box border border-base-300 bg-base-100 p-4 flex flex-col gap-4">
					<p class="text-sm font-medium text-base-content">Scan or enter token ID</p>
					<button
						type="button"
						class="btn {showCamera ? 'btn-ghost' : 'btn-primary'}"
						onclick={() => {
							showCamera = !showCamera;
							error = '';
							if (showCamera) scanHandled = false;
							else scanHandled = true;
						}}
					>
						{showCamera ? 'Stop camera' : 'Start camera'}
					</button>
					{#if showCamera}
						<QrScanner active={true} onScan={handleQrScan} />
					{/if}
					<div class="flex items-center gap-2">
						<span class="text-xs text-base-content/60 shrink-0">or enter token ID</span>
						<div class="flex-1 border-t border-base-300"></div>
					</div>
					<div class="flex gap-2">
						<input
							type="text"
							class="input input-bordered flex-1"
							placeholder="e.g. A1"
							bind:value={manualPhysicalId}
							onkeydown={(e) => e.key === 'Enter' && handleLookup()}
						/>
						<button type="button" class="btn btn-primary" onclick={handleLookup}>Look up</button>
					</div>
					{#if error}
						<div class="alert alert-error text-sm">{error}</div>
					{/if}
				</div>
			{:else}
				<!-- Category + track + confirm -->
				<div class="rounded-box border border-base-300 bg-base-100 p-4 space-y-4">
					<p class="font-medium text-base-content">Token: <span class="font-mono text-primary">{scannedToken.physical_id}</span></p>

					<div>
						<p class="label-text mb-1">Client category</p>
						<div class="flex flex-wrap gap-2">
							{#each CATEGORIES as cat}
								<button
									type="button"
									class="btn btn-sm {selectedCategory === cat.value ? 'btn-primary' : 'btn-ghost'}"
									onclick={() => (selectedCategory = cat.value)}
								>
									{cat.label}
								</button>
							{/each}
						</div>
					</div>

					<div>
						<label for="triage-track" class="label"><span class="label-text">Track</span></label>
						<select id="triage-track" class="select select-bordered w-full" bind:value={selectedTrackId}>
							{#each activeProgram?.tracks ?? [] as track (track.id)}
								<option value={track.id}>{track.name}</option>
							{/each}
						</select>
					</div>

					{#if error}
						<div class="alert alert-error text-sm">{error}</div>
					{/if}

					<div class="flex gap-2 pt-2">
						<button type="button" class="btn btn-ghost flex-1" onclick={resetScan} disabled={isSubmitting}>
							Cancel
						</button>
						<button
							type="button"
							class="btn btn-primary flex-1"
							onclick={handleConfirm}
							disabled={isSubmitting || selectedCategory === null || selectedTrackId === null}
						>
							{isSubmitting ? 'Binding…' : 'Confirm'}
						</button>
					</div>
				</div>
			{/if}
		{/if}
	</div>
</MobileLayout>
