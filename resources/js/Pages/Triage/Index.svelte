<script lang="ts">
	import MobileLayout from '../../Layouts/MobileLayout.svelte';
	import Modal from '../../Components/Modal.svelte';
	import QrScanner from '../../Components/QrScanner.svelte';
	import { Camera } from 'lucide-svelte';
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
		display_scan_timeout_seconds = 20,
	}: {
		activeProgram: ActiveProgram | null;
		queueCount?: number;
		processedToday?: number;
		display_scan_timeout_seconds?: number;
	} = $props();

	const CATEGORIES = [
		{ label: 'Regular', value: 'Regular' },
		{ label: 'PWD / Senior / Pregnant', value: 'PWD / Senior / Pregnant' },
		{ label: 'Incomplete Documents', value: 'Incomplete Documents' },
	] as const;

	/** When track count is at or below this, show buttons instead of dropdown. */
	const MAX_TRACKS_FOR_BUTTONS = 4;
	const showTrackButtons = $derived((activeProgram?.tracks?.length ?? 0) <= MAX_TRACKS_FOR_BUTTONS);

	let showScanner = $state(false);
	let scannedToken = $state<{ physical_id: string; qr_hash: string; status: string } | null>(null);
	/** Latch: ignore repeated onScan callbacks after first successful scan (stops flicker). */
	let scanHandled = $state(false);
	let manualPhysicalId = $state('');
	let barcodeValue = $state('');
	let barcodeInputEl = $state<HTMLInputElement | null>(null);
	let selectedCategory = $state<string | null>(null);
	let selectedTrackId = $state<number | null>(null);
	let error = $state('');
	let isSubmitting = $state(false);
	let scanCountdown = $state(0);
	let scanCountdownIntervalId = $state<ReturnType<typeof setInterval> | null>(null);

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

	/** Refocus hidden barcode input every 10s when camera modal is closed (HID scanner). Disabled while modal is open. */
	$effect(() => {
		if (showScanner) return;
		const id = setInterval(() => barcodeInputEl?.focus(), 10000);
		return () => clearInterval(id);
	});

	/** Optional countdown when scanner modal is open. */
	$effect(() => {
		if (!showScanner) {
			if (scanCountdownIntervalId != null) {
				clearInterval(scanCountdownIntervalId);
				scanCountdownIntervalId = null;
			}
			scanCountdown = 0;
			return;
		}
		const timeout = Math.max(0, Number(display_scan_timeout_seconds) ?? 20);
		if (timeout === 0) return;
		scanCountdown = timeout;
		const id = setInterval(() => {
			scanCountdown = scanCountdown - 1;
			if (scanCountdown <= 0) {
				clearInterval(id);
				scanCountdownIntervalId = null;
				queueMicrotask(() => { showScanner = false; });
			}
		}, 1000);
		scanCountdownIntervalId = id;
		return () => {
			if (scanCountdownIntervalId != null) clearInterval(scanCountdownIntervalId);
			scanCountdownIntervalId = null;
		};
	});

	function closeScanner() {
		showScanner = false;
	}

	function extendScannerCountdown() {
		scanCountdown += Math.max(0, Number(display_scan_timeout_seconds) || 20);
	}

	function onBarcodeKeydown(e: KeyboardEvent) {
		if (e.key !== 'Enter') return;
		const raw = barcodeValue.trim();
		if (!raw) return;
		e.preventDefault();
		scanHandled = true;
		const branchA = raw.length <= 10 && /^[A-Za-z0-9]+$/.test(raw);
		const branchB = raw.length === 64 && /^[a-f0-9]+$/.test(raw);
		const lastSegment = raw.includes('/') ? (raw.split('/').pop() ?? '').split('?')[0].trim() : '';
		const branchUrl = lastSegment.length === 64 && /^[a-f0-9]+$/.test(lastSegment);
		if (branchA) {
			manualPhysicalId = raw;
			handleLookup();
		} else if (branchB || branchUrl) {
			const hashToUse = branchUrl ? lastSegment : raw;
			api('GET', `/api/sessions/token-lookup?qr_hash=${encodeURIComponent(hashToUse)}`).then(({ ok, data }) => {
				const t = data as { physical_id?: string; qr_hash?: string; status?: string } | undefined;
				if (ok && t?.physical_id && t?.qr_hash && t?.status === 'available') {
					scannedToken = { physical_id: t.physical_id, qr_hash: t.qr_hash, status: 'available' };
					showScanner = false;
				} else if (t?.status === 'in_use') {
					error = 'Token is already in use.';
				} else if (t?.status === 'deactivated') {
					error = 'Token deactivated.';
				} else {
					error = 'Token not found.';
				}
				scanHandled = false;
			});
		} else {
			manualPhysicalId = raw.slice(0, 10);
			handleLookup();
		}
		barcodeValue = '';
		barcodeInputEl?.focus();
	}

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

	async function handleQrScan(decodedText: string) {
		if (scanHandled) return;
		scanHandled = true;
		error = '';
		const trimmed = decodedText.trim();
		const branchA = trimmed.length <= 10 && /^[A-Za-z0-9]+$/.test(trimmed);
		const branchB = trimmed.length === 64 && /^[a-f0-9]+$/.test(trimmed);
		// If URL (e.g. .../display/status/HASH), extract last path segment as qr_hash (strip query string)
		const lastSegment = trimmed.includes('/') ? (trimmed.split('/').pop() ?? '').split('?')[0].trim() : '';
		const branchUrl = lastSegment.length === 64 && /^[a-f0-9]+$/.test(lastSegment);

		if (branchA) {
			manualPhysicalId = trimmed;
			handleLookup();
			return;
		}
		if (branchB || branchUrl) {
			const hashToUse = branchUrl ? lastSegment : trimmed;
			const { ok, data } = await api('GET', `/api/sessions/token-lookup?qr_hash=${encodeURIComponent(hashToUse)}`);
			const t = data as { physical_id?: string; qr_hash?: string; status?: string } | undefined;
			if (ok && t?.physical_id && t?.qr_hash && t?.status === 'available') {
				scannedToken = { physical_id: t.physical_id, qr_hash: t.qr_hash, status: 'available' };
				showScanner = false;
			} else if (t?.status === 'in_use') {
				error = 'Token is already in use.';
			} else if (t?.status === 'deactivated') {
				error = 'Token deactivated.';
			} else {
				error = 'Token not found.';
			}
			return;
		}
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
		showScanner = false;
	}

	/** Per ISSUES-ELABORATION §12: clear error and allow scan/lookup again without refresh (e.g. after token freed elsewhere). */
	function tryAgain() {
		error = '';
		scanHandled = false;
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
	<div class="flex flex-col gap-4 md:gap-6 text-surface-950 w-full max-w-2xl mx-auto px-4 md:px-6 py-4 md:py-6">
		{#if !activeProgram}
			<div class="rounded-container bg-surface-50 border border-surface-200 elevation-card p-6 md:p-8 text-center text-surface-950/80">
				<p class="font-medium">No active program</p>
				<p class="mt-2 text-sm">Activate a program from Admin → Programs.</p>
			</div>
		{:else}
			<h1 class="text-xl md:text-2xl font-semibold text-surface-950">Triage</h1>

			{#if !scannedToken}
				<!-- Get token: hidden HID input + pulsing CTA with camera icon opens modal (same pattern as display). -->
				<div class="rounded-container border border-surface-200 bg-surface-50 elevation-card p-4 md:p-6 flex flex-col gap-4">
					<input
						type="text"
						autocomplete="off"
						aria-label="Barcode scanner input; scan with hardware scanner or type and press Enter"
						class="sr-only"
						bind:value={barcodeValue}
						bind:this={barcodeInputEl}
						onkeydown={onBarcodeKeydown}
					/>
					<div
						class="flex items-center gap-3 rounded-container border-2 border-primary-500/30 bg-primary-500/5 p-4 animate-pulse"
						role="region"
						aria-label="Scan or enter token ID"
					>
						<p class="flex-1 text-base font-medium text-surface-950">Scan or enter token ID</p>
						<button
							type="button"
							class="btn btn-icon preset-filled-primary-500 shrink-0 min-h-[48px] min-w-[48px]"
							aria-label="Open camera to scan QR"
							title="Tap to scan with device camera"
							onclick={() => {
								showScanner = true;
								scanHandled = false;
								error = '';
							}}
						>
							<Camera class="w-6 h-6" />
						</button>
					</div>
					<div class="flex items-center gap-2">
						<span class="text-xs text-surface-950/60 shrink-0">or enter token ID</span>
						<div class="flex-1 border-t border-surface-200"></div>
					</div>
					<div class="flex gap-2">
						<input
							type="text"
							class="input flex-1 rounded-container border border-surface-200 px-3 min-h-[48px]"
							placeholder="e.g. A1"
							bind:value={manualPhysicalId}
							onkeydown={(e) => e.key === 'Enter' && handleLookup()}
						/>
						<button type="button" class="btn preset-filled-primary-500 min-h-[48px] min-w-[48px] px-4" onclick={handleLookup}>Look up</button>
					</div>
					{#if error}
						<div class="rounded-container bg-error-100 text-error-900 border border-error-300 p-3 md:p-4 text-sm flex flex-col gap-3">
							<span>{error}</span>
							<button type="button" class="btn preset-tonal min-h-[48px] min-w-[48px] px-4 w-fit" onclick={tryAgain}>
								Try again
							</button>
						</div>
					{/if}
				</div>

				<Modal open={showScanner} title="Scan QR via device" onClose={closeScanner} wide={true}>
					{#snippet children()}
						<div class="flex flex-col gap-3 w-full min-w-[20rem] mx-auto">
							<QrScanner active={showScanner} cameraOnly={true} onScan={handleQrScan} />
							{#if scanCountdown > 0}
								<div class="flex flex-wrap items-center justify-center gap-2">
									<p class="text-sm text-surface-600" aria-live="polite">Closing in {scanCountdown}s</p>
									<button type="button" class="btn preset-tonal text-sm py-1.5 px-3" onclick={extendScannerCountdown}>
										Extend (+{Math.max(0, Number(display_scan_timeout_seconds) || 20)}s)
									</button>
								</div>
							{/if}
							<button type="button" class="btn preset-tonal w-full py-3" onclick={closeScanner}>Cancel</button>
						</div>
					{/snippet}
				</Modal>
			{:else}
				<!-- Category + track + confirm -->
				<div class="rounded-container border border-surface-200 bg-surface-50 elevation-card p-4 md:p-6 space-y-4">
					<p class="font-medium text-surface-950">Token: <span class="font-mono text-primary-500">{scannedToken.physical_id}</span></p>

					<div>
						<p class="text-sm font-medium text-surface-950 mb-1">Client category</p>
						<div class="flex flex-wrap gap-2">
							{#each CATEGORIES as cat}
								<button
									type="button"
									class="btn min-h-[48px] px-4 py-2 {selectedCategory === cat.value ? 'preset-filled-primary-500' : 'preset-tonal'}"
									onclick={() => (selectedCategory = cat.value)}
								>
									{cat.label}
								</button>
							{/each}
						</div>
					</div>

					<div>
						<p class="text-sm font-medium text-surface-950 mb-2">Track</p>
						{#if showTrackButtons}
							<div class="flex flex-wrap gap-2">
								{#each activeProgram?.tracks ?? [] as track (track.id)}
									<button
										type="button"
										class="btn min-h-[48px] px-4 py-2 {selectedTrackId === track.id ? 'preset-filled-primary-500' : 'preset-tonal'}"
										onclick={() => (selectedTrackId = track.id)}
									>
										{track.name}
									</button>
								{/each}
							</div>
						{:else}
							<select id="triage-track" class="w-full rounded-container border border-surface-200 px-3 py-2 min-h-[48px]" bind:value={selectedTrackId}>
								{#each activeProgram?.tracks ?? [] as track (track.id)}
									<option value={track.id}>{track.name}</option>
								{/each}
							</select>
						{/if}
					</div>

					{#if error}
						<div class="rounded-container bg-error-100 text-error-900 border border-error-300 p-3 text-sm">{error}</div>
					{/if}

					<div class="flex gap-2 pt-2">
						<button type="button" class="btn preset-tonal flex-1 min-h-[48px]" onclick={resetScan} disabled={isSubmitting}>
							Cancel
						</button>
						<button
							type="button"
							class="btn preset-filled-primary-500 flex-1 min-h-[48px]"
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
