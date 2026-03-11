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
import CountdownTimer from '../../Components/CountdownTimer.svelte';
import AuthChoiceButtons from '../../Components/AuthChoiceButtons.svelte';
import PinOrQrInput from '../../Components/PinOrQrInput.svelte';
import { onMount } from 'svelte';
	import { Camera, Settings } from 'lucide-svelte';
	import { toaster } from '../../lib/toaster.js';
	import {
		shouldFocusHidInput,
		shouldUseInputModeNone,
		getLocalAllowHidOnThisDevice,
		setLocalAllowHidOnThisDevice,
	} from '../../lib/displayHid.js';
	import {
		shouldAllowCameraScanner,
		setLocalAllowCameraOnThisDevice,
	} from '../../lib/displayCamera.js';

type IdentityBindingMode = 'disabled' | 'optional' | 'required';

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
		identity_binding_mode = 'disabled',
		/** Auto-reset duration for NOT_FOUND state; overridable for tests. */
		not_found_reset_seconds = 45,
	}: {
		allowed: boolean;
		program_name: string | null;
		tracks: Track[];
		date: string;
		display_scan_timeout_seconds?: number;
		enable_public_triage_hid_barcode?: boolean;
		identity_binding_mode?: IdentityBindingMode;
		not_found_reset_seconds?: number;
	} = $props();

	const page = usePage();
	function getCsrfToken(): string {
		const p = get(page);
		const fromProps = (p?.props as { csrf_token?: string } | undefined)?.csrf_token;
		if (fromProps) return fromProps;
		const meta = typeof document !== 'undefined' ? (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content : '';
		return meta ?? '';
	}

	const MSG_SESSION_EXPIRED = 'Session expired. Please refresh and try again.';
	const MSG_NETWORK_ERROR = 'Network error. Please try again.';

	async function api(method: string, url: string, body?: object): Promise<{ ok: boolean; data?: object; message?: string; errors?: Record<string, string[]> }> {
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
				return { ok: false, message: MSG_SESSION_EXPIRED };
			}
			const data = await res.json().catch(() => ({}));
			return { ok: res.ok, data, message: (data as { message?: string }).message, errors: (data as { errors?: Record<string, string[]> }).errors };
		} catch (e) {
			toaster.error({ title: MSG_NETWORK_ERROR });
			return { ok: false, message: MSG_NETWORK_ERROR };
		}
	}

let showScanner = $state(false);
let scanHandled = $state(false);
let localAllowCameraScanner = $state(true);
let barcodeValue = $state('');
let barcodeInputEl = $state<HTMLInputElement | null>(null);
let publicTokenValue = $state('');
let publicIdValue = $state('');
let scannedToken = $state<{ physical_id: string; qr_hash: string; status: string } | null>(null);
let selectedTrackId = $state<number | null>(null);

	/** When track count is at or below this, show buttons instead of dropdown. */
	const MAX_TRACKS_FOR_BUTTONS = 4;
	const showTrackButtons = $derived(tracks.length <= MAX_TRACKS_FOR_BUTTONS);
	let isSubmitting = $state(false);
	let bindSuccess = $state(false);
	let scannerCountdownRef = $state<{ extend: (seconds: number) => void } | null>(null);
	/** Program HID setting — from props and .display_settings broadcast. */
	let enablePublicTriageHidBarcode = $state(true);
	/** Settings modal. */
	let showTriageSettingsModal = $state(false);
	let triageSettingsAuthMode = $state<'pin' | 'qr'>('pin');
	let triageSettingsPin = $state('');
	let triageSettingsQrScanToken = $state('');
	let triagePinOrQrRef = $state(null);
	let triageSettingsError = $state('');
	let triageSettingsSaving = $state(false);
	let triageSettingsProgramHid = $state(true);
	let triageSettingsLocalAllowHid = $state(false);
	let triageSettingsLocalAllowCamera = $state(true);

// Identity binding mode for public triage (per ProgramSettings)
const identityBindingMode = $derived(
	(identity_binding_mode ?? 'disabled') as IdentityBindingMode
);

const notFoundResetSecondsDefault = $derived(
	(() => {
		if (typeof window === 'undefined') {
			return not_found_reset_seconds;
		}
		try {
			const url = new URL(window.location.href);
			const param = url.searchParams.get('_reset_seconds');
			const parsed = param ? parseInt(param, 10) : NaN;
			if (Number.isFinite(parsed) && parsed > 0) {
				return parsed;
			}
		} catch {
			// Ignore URL parse errors and fall back to prop.
		}
		return not_found_reset_seconds;
	})()
);

// Public identity binding state (per Bead 4 plan)
type PublicBinderMode =
	| 'idle'
	| 'scanning'
	| 'lookup_in_progress'
	| 'match_found'
	| 'not_found'
	| 'skipped'
	| 'completed';

let binderMode = $state<PublicBinderMode>('idle');
let binderStatus = $state<'idle' | 'bound' | 'skipped'>('idle');

type PublicClientSummary = {
	id: number;
	name: string;
	birth_year: number | null;
	id_document?: { id: number; id_type: string; id_last4: string };
};

let boundClient = $state<PublicClientSummary | null>(null);

// Scanner + HID mode switching between token and identity binding
type ScannerContext = 'token' | 'identity';
let scannerContext = $state<ScannerContext>('token');
type HidMode = 'token' | 'identity' | 'off';
let hidMode = $state<HidMode>('token');

	$effect(() => {
		enablePublicTriageHidBarcode = enable_public_triage_hid_barcode !== false;
	});

	onMount(() => {
		localAllowCameraScanner = shouldAllowCameraScanner('triage');
	});

	$effect(() => {
		if (!localAllowCameraScanner) showScanner = false;
	});

// Keep HID mode in sync with token vs identity flows
$effect(() => {
	if (!scannedToken) {
		hidMode = 'token';
	} else if (identityBindingMode !== 'disabled' && binderMode !== 'completed') {
		hidMode = 'identity';
	} else {
		hidMode = 'off';
	}
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
		triageSettingsLocalAllowCamera = shouldAllowCameraScanner('triage');
		triageSettingsAuthMode = 'pin';
		triageSettingsPin = '';
		triageSettingsQrScanToken = '';
		triageSettingsError = '';
		showTriageSettingsModal = true;
	}

	async function saveTriageSettings() {
		triageSettingsError = '';
		const authBody = triagePinOrQrRef?.buildPinOrQrPayload?.() ?? null;
		if (!authBody) return (triageSettingsError = triageSettingsAuthMode === 'pin'
			? 'Enter a 6-digit PIN.'
			: 'Scan QR first.');
		triageSettingsSaving = true;
		try {
			const body = {
				...authBody, // { pin } or { qr_scan_token }
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
			if (res.status === 419) {
				triageSettingsError = MSG_SESSION_EXPIRED;
				toaster.error({ title: MSG_SESSION_EXPIRED });
				return;
			}
			const data = await res.json().catch(() => ({}));
			if (res.status === 401) {
				triageSettingsError = (data as { message?: string }).message || 'Authorization failed.';
				return;
			}
			if (res.status === 403) {
				triageSettingsError = (data as { message?: string }).message || 'Not authorized for this program.';
				return;
			}
			if (res.status === 429) {
				triageSettingsError = (data as { message?: string }).message || 'Too many attempts. Try again later.';
				return;
			}
			if (!res.ok) {
				triageSettingsError = (data as { message?: string }).message || 'Failed to save.';
				return;
			}
			const d = data as { enable_public_triage_hid_barcode?: boolean };
			enablePublicTriageHidBarcode = !!d.enable_public_triage_hid_barcode;
			triageSettingsPin = '';
			triageSettingsQrScanToken = '';
			showTriageSettingsModal = false;
		} catch (e) {
			const isNetwork = e instanceof TypeError && (e as Error).message === 'Failed to fetch';
			triageSettingsError = isNetwork ? MSG_NETWORK_ERROR : 'Failed to save.';
			if (isNetwork) toaster.error({ title: MSG_NETWORK_ERROR });
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
		scannerCountdownRef?.extend(extra);
	}

	async function doTokenLookup(qrHash: string, physicalId: string): Promise<boolean> {
		if (qrHash && qrHash.length >= 32) {
			const { ok, data } = await api('GET', `/api/public/token-lookup?qr_hash=${encodeURIComponent(qrHash)}`);
			if (!ok) {
				toaster.error({ title: (data as { message?: string })?.message ?? 'Token not found.' });
				return false;
			}
			const t = data as { physical_id: string; qr_hash: string; status: string };
			if (t.status !== 'available') {
				toaster.error({ title: t.status === 'in_use' ? 'Token is already in use.' : t.status === 'deactivated' ? 'Token deactivated.' : `Token is ${t.status}.` });
				return false;
			}
			scannedToken = { physical_id: t.physical_id, qr_hash: t.qr_hash, status: t.status };
			return true;
		}
		if (physicalId.trim()) {
			const { ok, data } = await api('GET', `/api/public/token-lookup?physical_id=${encodeURIComponent(physicalId.trim())}`);
			if (!ok) {
				toaster.error({ title: (data as { message?: string })?.message ?? 'Token not found.' });
				return false;
			}
			const t = data as { physical_id: string; qr_hash: string; status: string };
			if (t.status !== 'available') {
				toaster.error({ title: t.status === 'in_use' ? 'Token is already in use.' : t.status === 'deactivated' ? 'Token deactivated.' : `Token is ${t.status}.` });
				return false;
			}
			scannedToken = { physical_id: t.physical_id, qr_hash: t.qr_hash, status: t.status };
			return true;
		}
		toaster.error({ title: 'Enter or scan a token.' });
		return false;
	}

	function handleQrScan(decodedText: string) {
		if (scannerContext !== 'token') return;
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

	async function submitIdLookup(idValue: string) {
		const trimmed = idValue.trim();
		if (!trimmed) return;

		binderMode = 'lookup_in_progress';
		boundClient = null;

		const { ok, data, message } = await api('POST', '/api/public/clients/lookup-by-id', {
			id_type: 'PhilHealth',
			id_number: trimmed,
		});

		if (!ok) {
			console.error('ID lookup failed', message);
			binderMode = 'not_found';
			return;
		}

		const d = data as {
			match_status?: string;
			client?: { id: number; name: string; birth_year: number | null };
			id_document?: { id: number; id_type: string; id_last4: string };
		};
		if (d.match_status === 'existing' && d.client) {
			boundClient = {
				id: d.client.id,
				name: d.client.name,
				birth_year: d.client.birth_year,
				id_document: d.id_document,
			};
			binderStatus = 'bound';
			binderMode = 'match_found';
			queueMicrotask(() => {
				binderMode = 'completed';
			});
		} else {
			binderMode = 'not_found';
		}
	}

	function handleIdQrScan(decodedText: string) {
		if (scannerContext !== 'identity') return;
		if (scanHandled) return;
		scanHandled = true;
		const raw = decodedText.trim();
		submitIdLookup(raw).finally(() => {
			scanHandled = false;
		});
	}

	function onBarcodeKeydown(e: KeyboardEvent) {
		if (e.key !== 'Enter') return;
		const raw = barcodeValue.trim();
		if (!raw) return;
		e.preventDefault();
		scanHandled = true;

		if (hidMode === 'token') {
			const isHash = (s: string) => s.length === 64 && /^[a-f0-9]+$/.test(s);
			const physicalId = raw.length <= 10 && /^[A-Za-z0-9]+$/.test(raw) ? raw : '';
			const qrHash = raw.includes('/')
				? (raw.split('/').pop() ?? '').split('?')[0].trim()
				: isHash(raw)
					? raw
					: '';
			doTokenLookup(qrHash, physicalId || raw).then((ok) => {
				if (ok) showScanner = false;
				scanHandled = false;
				barcodeValue = '';
				if (shouldFocusHidInput(enablePublicTriageHidBarcode, 'triage')) barcodeInputEl?.focus();
			});
		} else if (hidMode === 'identity') {
			submitIdLookup(raw).finally(() => {
				scanHandled = false;
				barcodeValue = '';
				if (shouldFocusHidInput(enablePublicTriageHidBarcode, 'triage')) barcodeInputEl?.focus();
			});
		} else {
			scanHandled = false;
			barcodeValue = '';
		}
	}

	async function handleBind() {
		if (!scannedToken || selectedTrackId == null) return;
		isSubmitting = true;
		const body: any = {
			qr_hash: scannedToken.qr_hash,
			track_id: Number(selectedTrackId),
			client_category: 'Regular',
		};

		if (binderStatus === 'bound' && boundClient) {
			body.client_binding = {
				client_id: boundClient.id,
				source: 'existing_id_document',
				id_document_id: boundClient.id_document?.id,
			};
		}

		const { ok, data } = await api('POST', '/api/public/sessions/bind', body);
		isSubmitting = false;
		if (ok) {
			bindSuccess = true;
			return;
		}
		const d = data as { active_session?: { alias: string }; message?: string } | undefined;
		if (d?.active_session) {
			toaster.error({ title: `Token already in use (${d.active_session.alias}).` });
		} else {
			toaster.error({ title: d?.message ?? 'Could not start visit.' });
		}
	}

	function reset() {
		scannedToken = null;
		selectedTrackId = null;
		setDefaultTrack();
		bindSuccess = false;
		binderMode = 'idle';
		binderStatus = 'idle';
		boundClient = null;
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
					class="btn btn-icon preset-tonal shrink-0 touch-target"
					aria-label="Triage settings"
					title="Settings"
					onclick={openTriageSettingsModal}
				>
					<Settings class="w-5 h-5" />
				</button>
			</div>

			<!-- Global HID barcode input (sr-only) to support both token and ID scans -->
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

			{#if !scannedToken}
				<section>
					<div
						class="flex items-center gap-3 rounded-container border-2 border-primary-500/30 bg-primary-500/5 p-4 animate-pulse"
						role="region"
					>
						<p class="flex-1 text-base font-medium text-surface-950">Scan your token or enter ID</p>
						{#if localAllowCameraScanner}
							<button
								type="button"
								class="btn btn-icon preset-filled-primary-500 shrink-0 touch-target"
								aria-label="Open camera to scan QR"
								onclick={() => {
									scannerContext = 'token';
									showScanner = true;
									scanHandled = false;
								}}
							>
								<Camera class="w-6 h-6" />
							</button>
						{/if}
					</div>

					<div class="mt-3 flex flex-col gap-2">
						<div class="flex gap-2">
							<input
								type="text"
								class="input rounded-container border border-surface-200 px-3 py-2 text-sm flex-1"
								placeholder="Enter token ID"
								data-testid="public-token-input"
								bind:value={publicTokenValue}
							/>
							<button
								type="button"
								class="btn preset-filled-primary-500 touch-target-h"
								data-testid="public-token-lookup-button"
								onclick={() => {
									const raw = publicTokenValue.trim();
									if (!raw) return;
									const isHash = (s: string) => s.length === 64 && /^[a-f0-9]+$/.test(s);
									const physicalId = raw.length <= 10 && /^[A-Za-z0-9]+$/.test(raw) ? raw : '';
									const qrHash = raw.includes('/')
										? (raw.split('/').pop() ?? '').split('?')[0].trim()
										: isHash(raw)
											? raw
											: '';
									doTokenLookup(qrHash, physicalId || raw).then((ok) => {
										if (ok) {
											publicTokenValue = '';
											setDefaultTrack();
										}
									});
								}}
							>
								Start
							</button>
						</div>
					</div>
				</section>

				<Modal
					open={showScanner}
					title={scannerContext === 'identity' ? 'Scan ID card' : 'Scan QR'}
					onClose={closeScanner}
					wide={true}
				>
					{#snippet children()}
						<div class="flex flex-col gap-3">
							<QrScanner
								active={showScanner}
								cameraOnly={true}
								onScan={scannerContext === 'identity' ? handleIdQrScan : handleQrScan}
							/>
							<CountdownTimer
								bind:this={scannerCountdownRef}
								active={showScanner}
								initialSeconds={display_scan_timeout_seconds}
								prefix="Closing in "
								suffix="s"
								onExpire={() => { showScanner = false; }}
							/>
							{#if showScanner}
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
							<p class="text-sm text-surface-950/70">Changes to program settings require supervisor or admin authorization.</p>
							<div class="flex flex-col gap-2">
								<div class="label"><span class="label-text">Authorize with</span></div>
								<AuthChoiceButtons includeRequest={false} disabled={triageSettingsSaving} bind:mode={triageSettingsAuthMode} />
							</div>
							<PinOrQrInput
								bind:this={triagePinOrQrRef}
								disabled={triageSettingsSaving}
								mode={triageSettingsAuthMode}
								bind:pin={triageSettingsPin}
								bind:qrScanToken={triageSettingsQrScanToken}
							/>
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
								<label class="flex items-center gap-2 cursor-pointer">
									<input
										type="checkbox"
										class="checkbox"
										bind:checked={triageSettingsLocalAllowCamera}
										onchange={() => {
											setLocalAllowCameraOnThisDevice('triage', triageSettingsLocalAllowCamera);
											localAllowCameraScanner = triageSettingsLocalAllowCamera;
										}}
									/>
									<span class="text-sm text-surface-950">Allow camera/QR scanner on this device</span>
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
				<div
					class="rounded-container border border-surface-200 bg-surface-50 p-4 md:p-6 space-y-4"
					data-testid="public-scanned-token-card"
				>
					<p class="font-medium text-surface-950">Token: <span class="font-mono text-primary-500">{scannedToken.physical_id}</span></p>
					<div>
						<p class="text-sm font-medium text-surface-950 mb-2">Choose track</p>
						{#if showTrackButtons}
							<div class="flex flex-wrap gap-2">
								{#each tracks as track (track.id)}
									<button
										type="button"
										class="btn touch-target-h px-4 py-2 {selectedTrackId === track.id ? 'preset-filled-primary-500' : 'preset-tonal'}"
										onclick={() => (selectedTrackId = track.id)}
									>
										{track.name}
									</button>
								{/each}
							</div>
						{:else}
							<select
								id="public-track"
								class="w-full rounded-container border border-surface-200 px-3 py-2 touch-target-h"
								bind:value={selectedTrackId}
							>
								{#each tracks as track (track.id)}
									<option value={track.id}>{track.name}</option>
								{/each}
							</select>
						{/if}
					</div>

					{#if identityBindingMode !== 'disabled'}
						<div
							class="rounded-container border border-surface-200 bg-surface-100 p-3 space-y-3"
							data-testid="public-id-binding-step"
						>
							<div class="space-y-1">
								<p class="text-sm font-semibold text-surface-950">
									{identityBindingMode === 'required' ? 'Link your ID (required)' : 'Link your ID (optional)'}
								</p>
								<p class="text-xs text-surface-700">
									{identityBindingMode === 'required'
										? 'Scan your ID card to link your visit to your record.'
										: 'Scan your ID card to link your visit, or continue without ID.'}
								</p>
							</div>

							{#if binderMode === 'not_found'}
								<div class="space-y-3">
									<p
										class="text-sm font-medium text-error-900"
										data-testid="public-id-rejection-title"
									>
										We couldn’t find your details
									</p>
									<p
										class="text-xs text-surface-700"
										data-testid="public-id-rejection-body"
									>
										This ID card isn’t linked to a record in our system. Please check you’re using the right card, or ask a
										staff member for help.
									</p>
									<p class="text-xs text-surface-600">
										If you don’t choose an option, this screen will reset automatically.
									</p>
									<div class="text-xs text-surface-600" data-testid="public-id-rejection-countdown">
										<CountdownTimer
											active={binderMode === 'not_found'}
											initialSeconds={notFoundResetSecondsDefault}
											prefix="Resetting to the start in "
											suffix=" seconds…"
											onExpire={() => {
												binderMode = 'idle';
												binderStatus = 'idle';
												boundClient = null;
											}}
										/>
									</div>
									<div class="flex flex-wrap gap-2">
										<button
											type="button"
											class="btn preset-filled-primary-500 flex-1 touch-target-h"
											data-testid="public-id-rejection-try-again"
											onclick={() => {
												binderMode = 'scanning';
											}}
										>
											Try again
										</button>
										{#if identityBindingMode === 'optional'}
											<button
												type="button"
												class="btn preset-tonal flex-1 touch-target-h"
												data-testid="public-id-rejection-continue-without-id"
												onclick={() => {
													binderMode = 'skipped';
													binderStatus = 'skipped';
													boundClient = null;
													binderMode = 'completed';
												}}
											>
												Continue without ID
											</button>
										{/if}
									</div>
								</div>
							{:else if binderMode === 'completed' && binderStatus === 'bound' && boundClient}
								<div
									class="space-y-1"
									data-testid="public-id-binding-completed"
								>
									<p class="text-xs font-medium text-success-900">ID linked</p>
									<p class="text-xs text-surface-700">
										{boundClient.name}
										{#if boundClient.birth_year}
											({boundClient.birth_year})
										{/if}
										{#if boundClient.id_document}
											· {boundClient.id_document.id_type} ending in {boundClient.id_document.id_last4}
										{/if}
									</p>
								</div>
							{:else}
								<div class="space-y-2">
									<div class="flex flex-wrap gap-2">
										<button
											type="button"
											class="btn preset-filled-primary-500 flex-1 touch-target-h"
											data-testid="public-id-scan-button"
											onclick={() => {
												binderMode = 'scanning';
												scannerContext = 'identity';
												showScanner = true;
												scanHandled = false;
											}}
										>
											Scan ID card
										</button>
										{#if identityBindingMode === 'optional'}
											<button
												type="button"
												class="btn preset-tonal flex-1 touch-target-h"
												data-testid="public-id-skip-button"
												onclick={() => {
													binderMode = 'skipped';
													binderStatus = 'skipped';
													boundClient = null;
													binderMode = 'completed';
												}}
											>
												Continue without ID
											</button>
										{/if}
									</div>
									<div class="flex flex-col gap-2 pt-2">
										<div class="flex gap-2">
											<input
												type="text"
												class="input rounded-container border border-surface-200 px-3 py-2 text-xs flex-1"
												placeholder="Enter ID number"
												data-testid="public-id-number-input"
												bind:value={publicIdValue}
												onkeydown={(e) => {
													if (e.key === 'Enter') {
														e.preventDefault();
														submitIdLookup(publicIdValue);
													}
												}}
											/>
											<button
												type="button"
												class="btn preset-tonal touch-target-h"
												data-testid="public-id-submit-button"
												onclick={() => submitIdLookup(publicIdValue)}
											>
												Lookup
											</button>
										</div>
									</div>
									{#if identityBindingMode === 'optional'}
										<p
											class="text-xs text-surface-600"
											data-testid="public-id-skip-helper"
										>
											You can still join the queue without linking your ID card.
										</p>
									{/if}
								</div>
							{/if}
						</div>
					{/if}

					<div class="flex gap-2 pt-2">
						<button type="button" class="btn preset-tonal flex-1 touch-target-h" onclick={reset} disabled={isSubmitting}>
							Cancel
						</button>
						<button
							type="button"
							class="btn preset-filled-primary-500 flex-1 touch-target-h"
							onclick={handleBind}
							disabled={
								isSubmitting ||
								selectedTrackId == null ||
								(identityBindingMode === 'required' && binderStatus !== 'bound')
							}
						>
							{isSubmitting ? 'Starting…' : 'Start my visit'}
						</button>
					</div>
				</div>
			{/if}
		{/if}
	</div>
</DisplayLayout>
