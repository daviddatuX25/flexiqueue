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
	import { onMount } from 'svelte';
	import { Camera, Settings } from 'lucide-svelte';
	import {
		shouldFocusHidInput,
		shouldUseInputModeNone,
		getLocalAllowHidOnThisDevice,
		setLocalAllowHidOnThisDevice,
	} from '../../lib/displayHid.js';

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
		/** Per barcode-hid plan: when false, hidden HID input is disabled (no auto-focus) so mobile keyboard doesn't open. */
		enable_public_triage_hid_barcode = true,
	}: {
		allowed: boolean;
		program_name: string | null;
		tracks: Track[];
		date: string;
		display_scan_timeout_seconds?: number;
		enable_public_triage_hid_barcode?: boolean;
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

	/** When track count is at or below this, show buttons instead of dropdown. */
	const MAX_TRACKS_FOR_BUTTONS = 4;
	const showTrackButtons = $derived(tracks.length <= MAX_TRACKS_FOR_BUTTONS);
	let isSubmitting = $state(false);
	let bindSuccess = $state(false);
	let scanCountdown = $state(0);
	let scanCountdownIntervalId = $state<ReturnType<typeof setInterval> | null>(null);
	/** Program HID setting — from props and .display_settings broadcast. */
	let enablePublicTriageHidBarcode = $state(true);
	/** Settings modal. */
	let showTriageSettingsModal = $state(false);
	let triageSettingsPin = $state('');
	let triageSettingsError = $state('');
	let triageSettingsSaving = $state(false);
	let triageSettingsProgramHid = $state(true);
	let triageSettingsLocalAllowHid = $state(false);

	$effect(() => {
		enablePublicTriageHidBarcode = enable_public_triage_hid_barcode !== false;
	});

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

	/** Refocus hidden barcode input every 10s when camera modal is closed. Both program and device-local must allow (per plan). */
	$effect(() => {
		if (showScanner || !shouldFocusHidInput(enablePublicTriageHidBarcode, 'triage')) return;
		const id = setInterval(() => {
			if (shouldFocusHidInput(enablePublicTriageHidBarcode, 'triage')) barcodeInputEl?.focus();
		}, 10000);
		return () => clearInterval(id);
	});

	function closeScanner() {
		showScanner = false;
	}

	function openTriageSettingsModal() {
		triageSettingsProgramHid = enablePublicTriageHidBarcode;
		triageSettingsLocalAllowHid = getLocalAllowHidOnThisDevice('triage') === true;
		triageSettingsPin = '';
		triageSettingsError = '';
		showTriageSettingsModal = true;
	}

	async function saveTriageSettings() {
		triageSettingsError = '';
		if (!/^\d{6}$/.test(triageSettingsPin.trim())) {
			triageSettingsError = 'Enter a 6-digit PIN.';
			return;
		}
		triageSettingsSaving = true;
		try {
			const body = {
				pin: triageSettingsPin.trim(),
				enable_public_triage_hid_barcode: triageSettingsProgramHid,
			};
			const res = await fetch('/api/public/display-settings', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-CSRF-TOKEN': getCsrfToken(),
					'X-Requested-With': 'XMLHttpRequest',
				},
				credentials: 'same-origin',
				body: JSON.stringify(body),
			});
			const data = await res.json().catch(() => ({}));
			if (res.status === 401) {
				triageSettingsError = (data as { message?: string }).message || 'Invalid PIN.';
				return;
			}
			if (!res.ok) {
				triageSettingsError = (data as { message?: string }).message || 'Failed to save.';
				return;
			}
			const d = data as { enable_public_triage_hid_barcode?: boolean };
			enablePublicTriageHidBarcode = !!d.enable_public_triage_hid_barcode;
			triageSettingsPin = '';
			showTriageSettingsModal = false;
		} finally {
			triageSettingsSaving = false;
		}
	}

	onMount(() => {
		const win = window as Window & { Echo?: { channel: (n: string) => { listen: (e: string, c: (ev: unknown) => void) => void }; leave: (n: string) => void } };
		if (!allowed || typeof window === 'undefined' || !win.Echo) return;
		const echo = win.Echo;
		const ch = echo.channel('display.activity');
		ch.listen('.display_settings', (e: { enable_public_triage_hid_barcode?: boolean }) => {
			if (typeof e.enable_public_triage_hid_barcode === 'boolean') {
				enablePublicTriageHidBarcode = e.enable_public_triage_hid_barcode;
			}
		});
		return () => echo.leave('display.activity');
	});

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
				if (shouldFocusHidInput(enablePublicTriageHidBarcode, 'triage')) barcodeInputEl?.focus();
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
			<div class="flex items-center justify-between gap-2">
				<h1 class="text-xl font-bold text-surface-950">Start your visit</h1>
				<button
					type="button"
					class="btn btn-icon preset-tonal shrink-0 min-h-[48px] min-w-[48px]"
					aria-label="Triage settings"
					title="Settings"
					onclick={openTriageSettingsModal}
				>
					<Settings class="w-5 h-5" />
				</button>
			</div>

			{#if !scannedToken}
				<section>
					<input
						type="text"
						autocomplete="off"
						inputmode={shouldUseInputModeNone(enablePublicTriageHidBarcode, 'triage') ? 'none' : 'text'}
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
							<QrScanner active={showScanner} cameraOnly={true} onScan={handleQrScan} />
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

				<Modal open={showTriageSettingsModal} title="Triage settings" onClose={() => (showTriageSettingsModal = false)}>
					{#snippet children()}
						<div class="flex flex-col gap-6">
							<p class="text-sm text-surface-950/70">Changes to program settings require supervisor or admin PIN.</p>
							<label class="flex flex-col gap-1">
								<span class="text-sm font-medium text-surface-950">PIN (6 digits)</span>
								<input
									type="text"
									inputmode="numeric"
									autocomplete="off"
									maxlength="6"
									class="input input-sm bg-surface-50 border border-surface-300 rounded-container font-mono w-full max-w-[8rem]"
									placeholder="000000"
									bind:value={triageSettingsPin}
									oninput={(e) => { triageSettingsPin = (e.currentTarget.value || '').replace(/\D/g, '').slice(0, 6); }}
									disabled={triageSettingsSaving}
									aria-describedby="triage-settings-pin-error"
								/>
							</label>
							{#if triageSettingsError}
								<p id="triage-settings-pin-error" class="text-sm text-error-600">{triageSettingsError}</p>
							{/if}
							<div class="flex flex-col gap-4">
								<h3 class="text-sm font-semibold text-surface-950">Program settings</h3>
								<p class="text-xs text-surface-950/60">Apply to all triage pages.</p>
								<label class="flex items-center gap-2 cursor-pointer">
									<input
										type="checkbox"
										class="checkbox"
										bind:checked={triageSettingsProgramHid}
										disabled={triageSettingsSaving}
									/>
									<span class="text-sm text-surface-950">Allow HID barcode scanner</span>
								</label>
							</div>
							<div class="border-t border-surface-200 pt-4 flex flex-col gap-2">
								<h3 class="text-sm font-semibold text-surface-950">This device</h3>
								<p class="text-xs text-surface-950/60">On this device only — not saved to server.</p>
								<label class="flex items-center gap-2 cursor-pointer">
									<input
										type="checkbox"
										class="checkbox"
										bind:checked={triageSettingsLocalAllowHid}
										onchange={() => setLocalAllowHidOnThisDevice('triage', triageSettingsLocalAllowHid)}
									/>
									<span class="text-sm text-surface-950">Allow HID scanner on this device</span>
								</label>
							</div>
							<div class="flex flex-wrap gap-2 justify-end pt-2">
								<button
									type="button"
									class="btn preset-tonal"
									onclick={() => (showTriageSettingsModal = false)}
									disabled={triageSettingsSaving}
								>
									Cancel
								</button>
								<button
									type="button"
									class="btn preset-filled-primary-500"
									onclick={saveTriageSettings}
									disabled={triageSettingsSaving}
								>
									{triageSettingsSaving ? 'Saving…' : 'Save'}
								</button>
							</div>
						</div>
					{/snippet}
				</Modal>
			{:else}
				<div class="rounded-container border border-surface-200 bg-surface-50 p-4 md:p-6 space-y-4">
					<p class="font-medium text-surface-950">Token: <span class="font-mono text-primary-500">{scannedToken.physical_id}</span></p>
					<div>
						<p class="text-sm font-medium text-surface-950 mb-2">Choose track</p>
						{#if showTrackButtons}
							<div class="flex flex-wrap gap-2">
								{#each tracks as track (track.id)}
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
							<select
								id="public-track"
								class="w-full rounded-container border border-surface-200 px-3 py-2 min-h-[48px]"
								bind:value={selectedTrackId}
							>
								{#each tracks as track (track.id)}
									<option value={track.id}>{track.name}</option>
								{/each}
							</select>
						{/if}
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
