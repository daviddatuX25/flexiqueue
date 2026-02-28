<script lang="ts">
	/**
	 * Public self-serve triage: scan token, choose track, bind. No auth.
	 * Per plan: GET /triage/start; when program allow_public_triage is true, clients can start a visit.
	 */
	import { get } from 'svelte/store';
	import { usePage } from '@inertiajs/svelte';
	import { router } from '@inertiajs/svelte';
	import DisplayLayout from '../../Layouts/DisplayLayout.svelte';
	import Modal from '../../Components/Modal.svelte';
	import QrScanner from '../../Components/QrScanner.svelte';
	import { Camera } from 'lucide-svelte';

	interface Track {
		id: number;
		name: string;
		is_default?: boolean;
	}

	let {
		allowed = false,
		program_name = null,
		tracks = [],
		date = '',
		display_scan_timeout_seconds = 20,
	}: {
		allowed: boolean;
		program_name: string | null;
		tracks: Track[];
		date: string;
		display_scan_timeout_seconds?: number;
	} = $props();

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
		return { ok: res.ok, data, message: (data as { message?: string }).message, errors: (data as { errors?: Record<string, string[]> }).errors };
	}

	let showScanner = $state(false);
	let scanHandled = $state(false);
	let barcodeValue = $state('');
	let barcodeInputEl = $state<HTMLInputElement | null>(null);
	let scannedToken = $state<{ physical_id: string; qr_hash: string; status: string } | null>(null);
	let selectedTrackId = $state<number | null>(null);
	let error = $state('');
	let isSubmitting = $state(false);
	let bindSuccess = $state(false);
	let scanCountdown = $state(0);
	let scanCountdownIntervalId = $state<ReturnType<typeof setInterval> | null>(null);

	function setDefaultTrack() {
		if (tracks?.length) {
			const def = tracks.find((t) => t.is_default);
			selectedTrackId = def?.id ?? tracks[0]?.id ?? null;
		}
	}
	$effect(() => {
		if (tracks?.length && selectedTrackId === null) setDefaultTrack();
	});

	$effect(() => {
		if (!showScanner) {
			if (scanCountdownIntervalId != null) {
				clearInterval(scanCountdownIntervalId);
				scanCountdownIntervalId = null;
			}
			scanCountdown = 0;
			return;
		}
		const timeout = Math.max(0, Number(display_scan_timeout_seconds) || 20);
		if (timeout === 0) return;
		let remaining = timeout;
		scanCountdown = remaining;
		const id = setInterval(() => {
			remaining -= 1;
			scanCountdown = remaining;
			if (remaining <= 0) showScanner = false;
		}, 1000);
		scanCountdownIntervalId = id;
		return () => {
			if (scanCountdownIntervalId != null) clearInterval(scanCountdownIntervalId);
			scanCountdownIntervalId = null;
		};
	});

	$effect(() => {
		if (showScanner) return;
		const id = setInterval(() => barcodeInputEl?.focus(), 2000);
		return () => clearInterval(id);
	});

	function closeScanner() {
		showScanner = false;
	}

	function extendCountdown() {
		const extra = Math.max(0, Number(display_scan_timeout_seconds) || 20);
		scanCountdown += extra;
	}

	async function doTokenLookup(qrHash: string, physicalId: string): Promise<boolean> {
		error = '';
		if (qrHash && qrHash.length >= 32) {
			const { ok, data } = await api('GET', `/api/public/token-lookup?qr_hash=${encodeURIComponent(qrHash)}`);
			if (!ok) {
				error = (data as { message?: string })?.message ?? 'Token not found.';
				return false;
			}
			const t = data as { physical_id: string; qr_hash: string; status: string };
			if (t.status !== 'available') {
				error = t.status === 'in_use' ? 'Token is already in use.' : t.status === 'deactivated' ? 'Token deactivated.' : `Token is ${t.status}.`;
				return false;
			}
			scannedToken = { physical_id: t.physical_id, qr_hash: t.qr_hash, status: t.status };
			return true;
		}
		if (physicalId.trim()) {
			const { ok, data } = await api('GET', `/api/public/token-lookup?physical_id=${encodeURIComponent(physicalId.trim())}`);
			if (!ok) {
				error = (data as { message?: string })?.message ?? 'Token not found.';
				return false;
			}
			const t = data as { physical_id: string; qr_hash: string; status: string };
			if (t.status !== 'available') {
				error = t.status === 'in_use' ? 'Token is already in use.' : t.status === 'deactivated' ? 'Token deactivated.' : `Token is ${t.status}.`;
				return false;
			}
			scannedToken = { physical_id: t.physical_id, qr_hash: t.qr_hash, status: t.status };
			return true;
		}
		error = 'Enter or scan a token.';
		return false;
	}

	function handleQrScan(decodedText: string) {
		if (scanHandled) return;
		scanHandled = true;
		const raw = decodedText.trim();
		const lastSegment = raw.includes('/') ? (raw.split('/').pop() ?? '').split('?')[0].trim() : '';
		const isHash = (s: string) => s.length === 64 && /^[a-f0-9]+$/.test(s);
		const qrHash = isHash(lastSegment) ? lastSegment : isHash(raw) ? raw : '';
		const physicalId = raw.length <= 10 && /^[A-Za-z0-9]+$/.test(raw) ? raw : '';
		doTokenLookup(qrHash, physicalId).then((ok) => {
			if (ok) {
				showScanner = false;
				setDefaultTrack();
			}
			scanHandled = false;
		});
	}

	function onBarcodeKeydown(e: KeyboardEvent) {
		if (e.key !== 'Enter') return;
		const raw = barcodeValue.trim();
		if (!raw) return;
		e.preventDefault();
		scanHandled = true;
		const isHash = (s: string) => s.length === 64 && /^[a-f0-9]+$/.test(s);
		const physicalId = raw.length <= 10 && /^[A-Za-z0-9]+$/.test(raw) ? raw : '';
		const qrHash = raw.includes('/') ? (raw.split('/').pop() ?? '').split('?')[0].trim() : (isHash(raw) ? raw : '');
		doTokenLookup(qrHash, physicalId || raw).then((ok) => {
			if (ok) showScanner = false;
			scanHandled = false;
			barcodeValue = '';
			barcodeInputEl?.focus();
		});
	}

	async function handleBind() {
		if (!scannedToken || selectedTrackId == null) return;
		error = '';
		isSubmitting = true;
		const { ok, data } = await api('POST', '/api/public/sessions/bind', {
			qr_hash: scannedToken.qr_hash,
			track_id: Number(selectedTrackId),
			client_category: 'Regular',
		});
		isSubmitting = false;
		if (ok) {
			bindSuccess = true;
			return;
		}
		const d = data as { active_session?: { alias: string }; message?: string } | undefined;
		if (d?.active_session) {
			error = `Token already in use (${d.active_session.alias}).`;
		} else {
			error = d?.message ?? 'Could not start visit.';
		}
	}

	function reset() {
		scannedToken = null;
		selectedTrackId = null;
		setDefaultTrack();
		error = '';
		bindSuccess = false;
	}
</script>

<svelte:head>
	<title>Start your visit — FlexiQueue</title>
</svelte:head>

<DisplayLayout programName={program_name} {date}>
	<div class="flex flex-col gap-6 max-w-2xl mx-auto text-surface-950">
		{#if !allowed}
			<div class="rounded-container bg-surface-50 border border-surface-200 p-6 md:p-8 text-center">
				<h2 class="text-xl font-bold text-surface-950 mb-2">Self-service is not available</h2>
				<p class="text-surface-600 mb-4">Public triage is not enabled for the current program.</p>
				<a href="/display" class="btn preset-filled-primary-500">View display board</a>
			</div>
		{:else if bindSuccess && scannedToken}
			<div class="rounded-container bg-success-100 border border-success-300 p-6 text-center">
				<p class="text-lg font-semibold text-success-900 mb-2">You're in the queue</p>
				<p class="text-surface-700 mb-4">Token {scannedToken.physical_id}. Check your status below.</p>
				<a href="/display/status/{encodeURIComponent(scannedToken.qr_hash)}" class="btn preset-filled-primary-500">Check my status</a>
			</div>
		{:else}
			<h1 class="text-xl font-bold text-surface-950">Start your visit</h1>

			{#if !scannedToken}
				<section>
					<input
						type="text"
						autocomplete="off"
						aria-label="Barcode scanner input"
						class="sr-only"
						bind:value={barcodeValue}
						bind:this={barcodeInputEl}
						onkeydown={onBarcodeKeydown}
					/>
					<div
						class="flex items-center gap-3 rounded-container border-2 border-primary-500/30 bg-primary-500/5 p-4 animate-pulse"
						role="region"
					>
						<p class="flex-1 text-base font-medium text-surface-950">Scan your token or enter ID</p>
						<button
							type="button"
							class="btn btn-icon preset-filled-primary-500 shrink-0 min-h-[48px] min-w-[48px]"
							aria-label="Open camera to scan QR"
							onclick={() => { showScanner = true; scanHandled = false; }}
						>
							<Camera class="w-6 h-6" />
						</button>
					</div>
					{#if error}
						<p class="mt-2 text-sm text-error-600">{error}</p>
					{/if}
				</section>

				<Modal open={showScanner} title="Scan QR" onClose={closeScanner} wide={true}>
					{#snippet children()}
						<div class="flex flex-col gap-3">
							<QrScanner active={true} cameraOnly={true} onScan={handleQrScan} />
							{#if scanCountdown > 0}
								<p class="text-sm text-surface-600">Closing in {scanCountdown}s</p>
								<button type="button" class="btn preset-tonal text-sm" onclick={extendCountdown}>
									Extend (+{Math.max(0, Number(display_scan_timeout_seconds) || 20)}s)
								</button>
							{/if}
							<button type="button" class="btn preset-tonal" onclick={closeScanner}>Cancel</button>
						</div>
					{/snippet}
				</Modal>
			{:else}
				<div class="rounded-container border border-surface-200 bg-surface-50 p-4 md:p-6 space-y-4">
					<p class="font-medium text-surface-950">Token: <span class="font-mono text-primary-500">{scannedToken.physical_id}</span></p>
					<div>
						<label for="public-track" class="block text-sm font-medium text-surface-950 mb-2">Choose track</label>
						<select
							id="public-track"
							class="w-full rounded-container border border-surface-200 px-3 py-2 min-h-[48px]"
							bind:value={selectedTrackId}
						>
							{#each tracks as track (track.id)}
								<option value={track.id}>{track.name}</option>
							{/each}
						</select>
					</div>
					{#if error}
						<p class="text-sm text-error-600">{error}</p>
					{/if}
					<div class="flex gap-2 pt-2">
						<button type="button" class="btn preset-tonal flex-1 min-h-[48px]" onclick={reset} disabled={isSubmitting}>
							Cancel
						</button>
						<button
							type="button"
							class="btn preset-filled-primary-500 flex-1 min-h-[48px]"
							onclick={handleBind}
							disabled={isSubmitting || selectedTrackId == null}
						>
							{isSubmitting ? 'Starting…' : 'Start my visit'}
						</button>
					</div>
				</div>
			{/if}
		{/if}
	</div>
</DisplayLayout>
