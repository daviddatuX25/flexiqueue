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
import IdNumberInput from '../../Components/IdNumberInput.svelte';
import ThemeToggle from '../../Components/ThemeToggle.svelte';
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
		program_id = null as number | null,
		program_name = null,
		tracks = [],
		date = '',
		display_scan_timeout_seconds = 20,
		enable_public_triage_hid_barcode = true,
		enable_public_triage_camera_scanner = true,
		identity_binding_mode = 'disabled',
		not_found_reset_seconds = 45,
		/** ID type options for lookup dropdown; first is default. */
		id_types = ['PhilHealth'] as string[],
		/** When true, request identification registration can create a session (unverified). */
		allow_unverified_entry = false,
	}: {
		allowed: boolean;
		program_id: number | null;
		program_name: string | null;
		tracks: Track[];
		date: string;
		display_scan_timeout_seconds?: number;
		enable_public_triage_hid_barcode?: boolean;
		enable_public_triage_camera_scanner?: boolean;
		identity_binding_mode?: IdentityBindingMode;
		not_found_reset_seconds?: number;
		id_types?: string[];
		allow_unverified_entry?: boolean;
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
let publicIdValue = $state('');
	/** ID type for lookup (binding). Auto = try all types; then confirmatory selection if ambiguous. */
	const publicIdTypeOptions = $derived(
		id_types?.length ? ['Auto', ...id_types.filter((t: string) => t !== 'Auto')] : ['Auto']
	);
	/** ID type options for registration only (no Auto — registration requires actual ID type). */
	const publicIdTypeOptionsForRegistration = $derived(id_types ?? []);
	let publicIdType = $state('Auto');
	/** Guest registration form (no token yet). */
	let showGuestRegForm = $state(false);
	/** When true, show manual ID entry in guest reg optional ID block (same format as binding). */
	let showGuestRegManualId = $state(false);
	/** ID type selected in guest reg form (registration options only). */
	let guestRegIdType = $state('');
	/** ID type selected in request identification reg form (registration options only). */
	let requestRegIdType = $state('');
	/** Request identification registration form (when ID not found). */
	let showRequestRegForm = $state(false);
	/** Warning modal before submitting an unverified registration request. */
	let showUnverifiedRequestWarning = $state(false);
	/** When true, show manual ID entry in request reg form (same format as binding). */
	let showRequestRegManualId = $state(false);
	let regName = $state('');
	let regBirthYear = $state('');
	let regCategory = $state('Regular');
	const REGISTRATION_CATEGORIES = [
		{ label: 'Regular', value: 'Regular' },
		{ label: 'PWD / Senior / Pregnant', value: 'PWD / Senior / Pregnant' },
	] as const;
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
	/** Program camera/QR scanner setting — from props and .display_settings broadcast. */
	let enablePublicTriageCameraScanner = $state(true);
	/** Effective: both program and device must allow (per display board parity). */
	const effectiveAllowCameraScanner = $derived(enablePublicTriageCameraScanner && localAllowCameraScanner);
	/** Settings modal (shown after successful PIN/QR auth). */
	let showTriageSettingsModal = $state(false);
	/** Auth-first step for settings: gate modal behind PIN/QR and store payload for save. */
	let triageSettingsStep = $state<'auth' | 'settings'>('auth');
	let triageSettingsAuthPayload = $state<{ pin?: string; qr_scan_token?: string } | null>(null);
	let triageSettingsAuthMode = $state<'pin' | 'qr'>('pin');
	let triageSettingsPin = $state('');
	let triageSettingsQrScanToken = $state('');
	let triagePinOrQrRef = $state(null);
	let triageSettingsError = $state('');
	let triageSettingsSaving = $state(false);
	let triageSettingsProgramHid = $state(true);
	let triageSettingsProgramCamera = $state(true);
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
	| 'ambiguous'
	| 'not_found'
	| 'skipped'
	| 'completed';

let binderMode = $state<PublicBinderMode>('idle');
let binderStatus = $state<'idle' | 'bound' | 'skipped'>('idle');
/** When true, show manual ID number + type + Lookup group (otherwise only "Scan ID" + "Enter ID manually" button). */
let showManualIdEntry = $state(false);

type PublicClientSummary = {
	id: number;
	name: string;
	birth_year: number | null;
	id_document?: { id: number; id_type: string; id_last4: string };
};

let boundClient = $state<PublicClientSummary | null>(null);
/** ID number for the post-token identity-binding step (Link your ID card). */
let binderIdNumber = $state('');
/** When lookup returns ambiguous, candidate ID types for confirmatory selection. */
let publicAmbiguousIdTypes = $state<string[]>([]);
/** ID number held when showing ambiguous confirmatory selection (retry with chosen type). */
let publicIdNumberPending = $state('');
/** When opening "Scan ID to capture number", which field to fill: guest reg form or binder step. */
type CaptureIdTarget = 'guest' | 'binder';
let captureIdTarget = $state<CaptureIdTarget>('guest');

// Scanner + HID mode switching between token, identity lookup, and capture-id (fill field)
type ScannerContext = 'token' | 'identity' | 'capture_id';
let scannerContext = $state<ScannerContext>('token');
type HidMode = 'token' | 'identity' | 'off';
let hidMode = $state<HidMode>('token');

	$effect(() => {
		enablePublicTriageHidBarcode = enable_public_triage_hid_barcode !== false;
		enablePublicTriageCameraScanner = enable_public_triage_camera_scanner !== false;
	});
	// Sync publicIdType when options change: if current value not in list, reset to Auto
	$effect(() => {
		if (!publicIdTypeOptions.length) return;
		if (!publicIdType || !publicIdTypeOptions.includes(publicIdType)) {
			publicIdType = 'Auto';
		}
	});

	onMount(() => {
		localAllowCameraScanner = shouldAllowCameraScanner('triage');
	});

	$effect(() => {
		if (!effectiveAllowCameraScanner) showScanner = false;
	});
	// Default registration ID type when opening registration forms (no Auto).
	$effect(() => {
		if (showGuestRegForm && publicIdTypeOptionsForRegistration.length && !publicIdTypeOptionsForRegistration.includes(guestRegIdType)) {
			guestRegIdType = publicIdTypeOptionsForRegistration[0];
		}
	});
	$effect(() => {
		if (showRequestRegForm && publicIdTypeOptionsForRegistration.length && !publicIdTypeOptionsForRegistration.includes(requestRegIdType)) {
			requestRegIdType = publicIdTypeOptionsForRegistration[0];
		}
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

	/** Per plan: HID only in modal. When modal opens, an HID input inside the modal (first focusable) is focused by Modal's trap; fallback focus global input. */
	$effect(() => {
		if (!showScanner || !shouldFocusHidInput(enablePublicTriageHidBarcode, 'triage')) return;
		queueMicrotask(() => {
			requestAnimationFrame(() => {
				barcodeInputEl?.focus();
			});
		});
	});

	function closeScanner() {
		showScanner = false;
	}

	function openTriageSettingsModal() {
		triageSettingsProgramHid = enablePublicTriageHidBarcode;
		triageSettingsProgramCamera = enablePublicTriageCameraScanner;
		triageSettingsLocalAllowHid = getLocalAllowHidOnThisDevice('triage') === true;
		triageSettingsLocalAllowCamera = shouldAllowCameraScanner('triage');
		triageSettingsAuthMode = 'pin';
		triageSettingsPin = '';
		triageSettingsQrScanToken = '';
		triageSettingsError = '';
		triageSettingsStep = 'auth';
		triageSettingsAuthPayload = null;
		showTriageSettingsModal = true;
	}

	function proceedTriageSettingsAuth() {
		triageSettingsError = '';
		const authBody = triagePinOrQrRef?.buildPinOrQrPayload?.() ?? null;
		if (!authBody) {
			triageSettingsError =
				triageSettingsAuthMode === 'pin'
					? 'Enter a 6-digit PIN.'
					: 'Scan QR first.';
			return;
		}
		triageSettingsAuthPayload = authBody;
		triageSettingsStep = 'settings';
	}

	async function saveTriageSettings() {
		triageSettingsError = '';
		const authBody = triageSettingsAuthPayload;
		if (!authBody) {
			triageSettingsError = 'Authorize with PIN or QR first.';
			triageSettingsStep = 'auth';
			return;
		}
		triageSettingsSaving = true;
		try {
			const body = {
				...authBody, // { pin } or { qr_scan_token }
				enable_public_triage_hid_barcode: triageSettingsProgramHid,
				enable_public_triage_camera_scanner: triageSettingsProgramCamera,
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
				triageSettingsStep = 'auth';
				triageSettingsAuthPayload = null;
				return;
			}
			if (res.status === 403) {
				triageSettingsError = (data as { message?: string }).message || 'Not authorized for this program.';
				triageSettingsStep = 'auth';
				triageSettingsAuthPayload = null;
				return;
			}
			if (res.status === 429) {
				triageSettingsError = (data as { message?: string }).message || 'Too many attempts. Try again later.';
				triageSettingsStep = 'auth';
				triageSettingsAuthPayload = null;
				return;
			}
			if (!res.ok) {
				triageSettingsError = (data as { message?: string }).message || 'Failed to save.';
				triageSettingsStep = 'auth';
				triageSettingsAuthPayload = null;
				return;
			}
			const d = data as { enable_public_triage_hid_barcode?: boolean; enable_public_triage_camera_scanner?: boolean };
			enablePublicTriageHidBarcode = !!d.enable_public_triage_hid_barcode;
			enablePublicTriageCameraScanner = d.enable_public_triage_camera_scanner !== false;
			// Apply device-local settings only after successful, authenticated save.
			setLocalAllowHidOnThisDevice('triage', triageSettingsLocalAllowHid);
			setLocalAllowCameraOnThisDevice('triage', triageSettingsLocalAllowCamera);
			localAllowCameraScanner = triageSettingsLocalAllowCamera;
			triageSettingsPin = '';
			triageSettingsQrScanToken = '';
			triageSettingsAuthPayload = null;
			triageSettingsStep = 'auth';
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
		if (!allowed || typeof window === 'undefined' || !win.Echo || program_id == null) return;
		const echo = win.Echo;
		const channelName = `display.activity.${program_id}`;
		const ch = echo.channel(channelName);
		ch.listen('.display_settings', (e: { enable_public_triage_hid_barcode?: boolean; enable_public_triage_camera_scanner?: boolean }) => {
			if (typeof e.enable_public_triage_hid_barcode === 'boolean') {
				enablePublicTriageHidBarcode = e.enable_public_triage_hid_barcode;
			}
			if (typeof e.enable_public_triage_camera_scanner === 'boolean') {
				enablePublicTriageCameraScanner = e.enable_public_triage_camera_scanner;
			}
		});
		return () => echo.leave(channelName);
	});

	function extendCountdown() {
		const extra = Math.max(0, Number(display_scan_timeout_seconds) || 20);
		scannerCountdownRef?.extend(extra);
	}

	async function doTokenLookup(qrHash: string, physicalId: string): Promise<boolean> {
		if (program_id == null) {
			toaster.error({ title: 'Program not set. Please use the triage link for your program.' });
			return false;
		}
		const programParam = `program_id=${encodeURIComponent(program_id)}`;
		if (qrHash && qrHash.length >= 32) {
			const { ok, data } = await api('GET', `/api/public/token-lookup?qr_hash=${encodeURIComponent(qrHash)}&${programParam}`);
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
			const { ok, data } = await api('GET', `/api/public/token-lookup?physical_id=${encodeURIComponent(physicalId.trim())}&${programParam}`);
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

		const body: { program_id?: number; id_number: string; id_type?: string } = { id_number: trimmed };
		if (program_id != null) body.program_id = program_id;
		if (publicIdType !== 'Auto') {
			body.id_type = publicIdType;
		}
		const { ok, data, message } = await api('POST', '/api/public/clients/lookup-by-id', body);

		if (!ok) {
			console.error('ID lookup failed', message);
			binderMode = 'not_found';
			return;
		}

		const d = data as {
			match_status?: string;
			message?: string;
			id_types?: string[];
			client?: { id: number; name: string; birth_year: number | null };
			id_document?: { id: number; id_type: string; id_last4: string };
		};
		if (d.match_status === 'ambiguous') {
			publicAmbiguousIdTypes = d.id_types ?? [];
			publicIdNumberPending = trimmed;
			if (publicAmbiguousIdTypes.length) publicIdType = publicAmbiguousIdTypes[0];
			binderMode = 'ambiguous';
			return;
		}
		if (d.match_status === 'existing' && d.client) {
			boundClient = {
				id: d.client.id,
				name: d.client.name,
				birth_year: d.client.birth_year,
				id_document: d.id_document,
			};
			binderStatus = 'bound';
			binderMode = 'match_found';
		} else {
			binderMode = 'not_found';
		}
	}

	/** After "Found match", user confirms it's them. */
	function confirmMatchProceed() {
		binderMode = 'completed';
	}

	/** After "Found match", user says not me — reset to ID entry. */
	function reportNotMe() {
		boundClient = null;
		binderStatus = 'idle';
		binderMode = 'idle';
		binderIdNumber = '';
		publicAmbiguousIdTypes = [];
		publicIdNumberPending = '';
	}

	function handleIdQrScan(decodedText: string) {
		if (scannerContext !== 'identity') return;
		if (scanHandled) return;
		scanHandled = true;
		const raw = decodedText.trim();
		publicIdValue = raw;
		showScanner = false;
		scannerContext = 'token';
		scanHandled = false;
		toaster.success({ title: 'ID number captured. You can edit and look up.' });
	}

	/** When scanner is in capture_id context, fill the ID number field and close. Scan → auto-trigger lookup for binder. */
	function handleCaptureIdScan(decodedText: string) {
		if (scannerContext !== 'capture_id') return;
		if (scanHandled) return;
		scanHandled = true;
		const trimmed = decodedText.trim();
		if (captureIdTarget === 'binder') {
			binderIdNumber = trimmed;
			toaster.success({ title: 'ID number captured. Looking up…' });
			showScanner = false;
			scannerContext = 'token';
			scanHandled = false;
			submitIdLookup(trimmed);
		} else {
			publicIdValue = trimmed;
			toaster.success({ title: 'ID number captured. You can edit and submit.' });
			showScanner = false;
			scannerContext = 'token';
			scanHandled = false;
		}
	}

	function handleScan(decodedText: string) {
		if (scannerContext === 'capture_id') {
			handleCaptureIdScan(decodedText);
			return;
		}
		if (scannerContext === 'identity') {
			handleIdQrScan(decodedText);
			return;
		}
		handleQrScan(decodedText);
	}

	function onBarcodeKeydown(e: KeyboardEvent) {
		if (e.key !== 'Enter') return;
		const raw = barcodeValue.trim();
		if (!raw) return;
		e.preventDefault();
		scanHandled = true;

		if (scannerContext === 'capture_id') {
			const trimmed = raw.trim();
			if (captureIdTarget === 'binder') {
				binderIdNumber = trimmed;
				toaster.success({ title: 'ID number captured. Looking up…' });
				showScanner = false;
				scannerContext = 'token';
				scanHandled = false;
				barcodeValue = '';
				submitIdLookup(trimmed);
			} else {
				publicIdValue = trimmed;
				toaster.success({ title: 'ID number captured. You can edit and submit.' });
				showScanner = false;
				scannerContext = 'token';
				scanHandled = false;
				barcodeValue = '';
			}
			return;
		}

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
			});
		} else if (hidMode === 'identity') {
			submitIdLookup(raw).finally(() => {
				scanHandled = false;
				barcodeValue = '';
			});
		} else {
			scanHandled = false;
			barcodeValue = '';
		}
	}

	async function handleBind() {
		if (!scannedToken || selectedTrackId == null) return;
		if (program_id == null) {
			toaster.error({ title: 'Program not set. Please use the triage link for your program.' });
			return;
		}
		isSubmitting = true;
		const body: Record<string, unknown> = {
			program_id,
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
			const d = data as { request_submitted?: boolean; session?: unknown; message?: string; unverified?: boolean };
			if (d?.request_submitted) {
				toaster.success({ title: d?.message ?? 'Your request has been submitted. Staff will verify your identity.' });
				return;
			}
			bindSuccess = true;
			toaster.success({
				title: d?.unverified === true
					? "You're in the queue (unverified). Staff may verify your ID later."
					: "You're in the queue.",
			});
			return;
		}
		const d = data as {
			error_code?: string;
			active_session?: { alias: string };
			message?: string;
		} | undefined;
		if (d?.error_code === 'client_already_queued' && d.active_session) {
			toaster.error({ title: `You're already in the queue (Token ${d.active_session.alias}).` });
			reset();
		} else if (d?.active_session) {
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
		binderIdNumber = '';
		publicAmbiguousIdTypes = [];
		publicIdNumberPending = '';
		showRequestRegForm = false;
		regName = '';
		regBirthYear = '';
		regCategory = 'Regular';
	}

	async function submitRequestIdentificationRegistration() {
		if (!scannedToken || selectedTrackId == null) return;
		if (program_id == null) {
			toaster.error({ title: 'Program not set. Please use the triage link for your program.' });
			return;
		}
		isSubmitting = true;
		const nameStr = String(regName ?? '').trim();
		const birthYearStr = String(regBirthYear ?? '').trim();
		const idNumberStr = String(publicIdValue ?? '').trim();
		const idTypeForReg = requestRegIdType || (id_types?.[0] ?? '');
		const body: Record<string, unknown> = {
			program_id,
			qr_hash: scannedToken.qr_hash,
			track_id: Number(selectedTrackId),
			identity_registration_request: {
				...(nameStr ? { name: nameStr } : {}),
				...(birthYearStr ? { birth_year: Number(birthYearStr) || undefined } : {}),
				...(regCategory ? { client_category: regCategory } : {}),
				...(idTypeForReg ? { id_type: idTypeForReg } : {}),
				...(idNumberStr ? { id_number: idNumberStr } : {}),
			},
		};
		const { ok, data } = await api('POST', '/api/public/sessions/bind', body);
		isSubmitting = false;
		if (ok) {
			const d = data as { request_submitted?: boolean; session?: unknown; message?: string; unverified?: boolean };
			if (d?.request_submitted) {
				toaster.success({ title: d?.message ?? 'Your request has been submitted. Staff will verify your identity.' });
				showUnverifiedRequestWarning = false;
				showRequestRegForm = false;
				regName = '';
				regBirthYear = '';
				regCategory = 'Regular';
				return;
			}
			bindSuccess = true;
			toaster.success({
				title: d?.unverified === true
					? "You're in the queue (unverified). Staff may verify your ID later."
					: "You're in the queue.",
			});
		} else {
			toaster.error({ title: (data as { message?: string })?.message ?? 'Could not submit request.' });
		}
	}

	async function confirmUnverifiedRequestAndSubmit() {
		if (isSubmitting) return;
		showUnverifiedRequestWarning = false;
		await submitRequestIdentificationRegistration();
	}

	async function submitGuestIdentificationRegistration() {
		if (program_id == null) {
			toaster.error({ title: 'Program not set. Please use the triage link for your program.' });
			return;
		}
		isSubmitting = true;
		const nameStr = String(regName ?? '').trim();
		const birthYearStr = String(regBirthYear ?? '').trim();
		const idNumberStr = String(publicIdValue ?? '').trim();
		const idTypeForReg = guestRegIdType || (id_types?.[0] ?? '');
		const body: Record<string, unknown> = {
			program_id,
			identity_registration_request: {
				...(nameStr ? { name: nameStr } : {}),
				...(birthYearStr ? { birth_year: Number(birthYearStr) || undefined } : {}),
				...(regCategory ? { client_category: regCategory } : {}),
				...(idTypeForReg ? { id_type: idTypeForReg } : {}),
				...(idNumberStr ? { id_number: idNumberStr } : {}),
			},
		};
		const { ok, data } = await api('POST', '/api/public/sessions/bind', body);
		isSubmitting = false;
		if (ok) {
			const d = data as { request_submitted?: boolean; session?: unknown; message?: string; unverified?: boolean };
			if (d?.request_submitted) {
				toaster.success({ title: d?.message ?? 'Your request has been submitted. Staff will verify your identity.' });
				showGuestRegForm = false;
				regName = '';
				regBirthYear = '';
				regCategory = 'Regular';
				publicIdValue = '';
				return;
			}
			bindSuccess = true;
			toaster.success({
				title: d?.unverified === true
					? "You're in the queue (unverified). Staff may verify your ID later."
					: "You're in the queue.",
			});
			return;
		}
		toaster.error({ title: (data as { message?: string })?.message ?? 'Could not submit request.' });
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

			<Modal
				open={showScanner}
				title={scannerContext === 'capture_id' ? 'Scan ID to capture number' : scannerContext === 'identity' ? 'Scan ID card' : 'Scan QR'}
				onClose={closeScanner}
				wide={true}
			>
				{#snippet children()}
					<div class="flex flex-col gap-3">
						{#if shouldFocusHidInput(enablePublicTriageHidBarcode, 'triage')}
							<!-- First focusable so Modal focus trap focuses it; same value/handler as global input. -->
							<input
								type="text"
								autocomplete="off"
								inputmode={shouldUseInputModeNone(enablePublicTriageHidBarcode, 'triage') ? 'none' : 'text'}
								aria-label="Barcode scanner input"
								class="sr-only"
								bind:value={barcodeValue}
								onkeydown={onBarcodeKeydown}
							/>
						{/if}
						{#if enablePublicTriageCameraScanner}
							<QrScanner
								active={showScanner}
								cameraOnly={true}
								onScan={handleScan}
							/>
						{/if}
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
						{#if shouldFocusHidInput(enablePublicTriageHidBarcode, 'triage')}
							<p class="text-sm text-surface-600 rounded-container border border-surface-200 bg-surface-50 px-3 py-2" aria-live="polite">HID scanner turned on, waiting for scan.</p>
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
						{#if triageSettingsStep === 'auth'}
							<div class="flex flex-wrap gap-2 justify-end pt-1">
								<button
									type="button"
									class="btn preset-filled-primary-500"
									onclick={proceedTriageSettingsAuth}
									disabled={triageSettingsSaving}
								>
									Continue
								</button>
							</div>
						{:else}
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
							<label class="flex items-center gap-2 cursor-pointer">
								<input
									type="checkbox"
									class="checkbox"
									bind:checked={triageSettingsProgramCamera}
									disabled={triageSettingsSaving}
								/>
								<span class="text-sm text-surface-950">Allow camera/QR scanner</span>
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
								/>
								<span class="text-sm text-surface-950">Allow HID scanner on this device</span>
							</label>
							<label class="flex items-center gap-2 cursor-pointer">
								<input
									type="checkbox"
									class="checkbox"
									bind:checked={triageSettingsLocalAllowCamera}
								/>
								<span class="text-sm text-surface-950">Allow camera/QR scanner on this device</span>
							</label>
							<div class="flex items-center justify-between gap-3 pt-2">
								<div>
									<h4 class="text-sm font-semibold text-surface-950">Theme</h4>
									<p class="text-xs text-surface-950/60">Light or dark mode on this device.</p>
								</div>
								<ThemeToggle />
							</div>
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
						{/if}
					</div>
				{/snippet}
			</Modal>

			<Modal
				open={showUnverifiedRequestWarning}
				title="Before you submit"
				onClose={() => (showUnverifiedRequestWarning = false)}
			>
				{#snippet children()}
					<div class="space-y-4" data-testid="public-request-registration-warning-modal">
						<div class="space-y-2 text-sm text-surface-700">
							<p class="font-medium text-surface-950">Please confirm your details are accurate.</p>
							<ul class="list-disc pl-5 space-y-1">
								<li>This request will be recorded under the details you enter.</li>
								<li>Incorrect name, birth year, or ID may delay help or be linked to the wrong person.</li>
								<li>Submit only if the details are accurate.</li>
							</ul>
						</div>
						<div class="flex flex-wrap gap-2 justify-end pt-2">
							<button
								type="button"
								class="btn preset-tonal"
								data-testid="public-request-registration-warning-cancel"
								onclick={() => (showUnverifiedRequestWarning = false)}
								disabled={isSubmitting}
							>
								Review details
							</button>
							<button
								type="button"
								class="btn preset-filled-primary-500"
								data-testid="public-request-registration-warning-confirm"
								onclick={confirmUnverifiedRequestAndSubmit}
								disabled={isSubmitting}
							>
								{isSubmitting ? 'Submitting…' : 'Submit request'}
							</button>
						</div>
					</div>
				{/snippet}
			</Modal>

			{#if !scannedToken}
				<section>
					<div
						class="flex items-center gap-3 rounded-container border-2 border-primary-500/30 bg-primary-500/5 p-4 animate-pulse"
						role="region"
					>
						<p class="flex-1 text-base font-medium text-surface-950">Scan your token</p>
						{#if effectiveAllowCameraScanner}
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
				</section>
				{#if identityBindingMode !== 'disabled'}
					<section class="rounded-container border border-surface-200 bg-surface-50 p-4 md:p-6 space-y-3">
						<div class="space-y-1">
							<p class="text-sm font-semibold text-surface-950">Need help linking your ID?</p>
							<p class="text-xs text-surface-700">
								Request registration so staff can verify your identity and link your ID to your record (new client or existing).
							</p>
						</div>
						<button
							type="button"
							class="btn preset-tonal w-full touch-target-h"
							onclick={() => (showGuestRegForm = !showGuestRegForm)}
							disabled={isSubmitting}
							data-testid="public-guest-request-registration-toggle"
						>
							{showGuestRegForm ? 'Hide registration form' : 'Request registration / Link ID'}
						</button>
						{#if showGuestRegForm}
							<div class="rounded-container border border-surface-200 bg-surface-100 p-3 space-y-2" data-testid="public-guest-request-registration-form">
								<p class="text-xs font-medium text-surface-800">Enter your details (all optional). Staff will verify.</p>
								<label class="flex flex-col gap-1 text-xs">
									<span class="text-surface-600">Name</span>
									<input
										type="text"
										class="input rounded-container border border-surface-200 px-3 py-2"
										placeholder="Full name"
										bind:value={regName}
									/>
								</label>
								<label class="flex flex-col gap-1 text-xs">
									<span class="text-surface-600">Birth year</span>
									<input
										type="number"
										class="input rounded-container border border-surface-200 px-3 py-2"
										placeholder="e.g. 1985"
										bind:value={regBirthYear}
									/>
								</label>
								<label class="flex flex-col gap-1 text-xs">
									<span class="text-surface-600">Classification</span>
									<select
										class="select select-theme input rounded-container border border-surface-200 px-3 py-2"
										bind:value={regCategory}
									>
										{#each REGISTRATION_CATEGORIES as cat (cat.value)}
											<option value={cat.value}>{cat.label}</option>
										{/each}
									</select>
								</label>
								<div class="border-t border-surface-200 pt-3 space-y-3">
									<div>
										<p class="text-xs font-medium text-surface-800">Optional ID details</p>
										<p class="text-[11px] text-surface-600 mt-0.5">
											Used to help staff verify your identity and re-use this ID next time.
										</p>
									</div>
									<div class="space-y-2">
										{#if effectiveAllowCameraScanner}
											<button
												type="button"
												class="btn preset-filled-primary-500 w-full text-sm touch-target-h flex items-center justify-center gap-2"
												data-testid="public-guest-scan-id-button"
												onclick={() => {
													captureIdTarget = 'guest';
													scannerContext = 'capture_id';
													showScanner = true;
													scanHandled = false;
												}}
												disabled={isSubmitting}
											>
												<Camera class="w-4 h-4 shrink-0" />
												Scan ID to capture number
											</button>
										{/if}
										{#if showGuestRegManualId}
											<div
												class="rounded-container border border-surface-200 bg-surface-50 p-3 space-y-3"
												data-testid="public-guest-manual-id-entry-group"
											>
												<div class="flex items-center justify-between gap-2">
													<span class="text-sm font-medium text-surface-700">Enter ID manually</span>
													{#if effectiveAllowCameraScanner}
														<button
															type="button"
															class="btn btn-sm preset-tonal"
															onclick={() => (showGuestRegManualId = false)}
														>
															Use scanner
														</button>
													{/if}
												</div>
												<div class="space-y-1">
													<span class="text-sm text-surface-700">ID number (optional)</span>
													<IdNumberInput
														bind:value={publicIdValue}
														placeholder="Enter or scan"
														disabled={isSubmitting}
														showMaskToggle={true}
														showScanButton={effectiveAllowCameraScanner}
														onScanClick={() => {
															captureIdTarget = 'guest';
															scannerContext = 'capture_id';
															showScanner = true;
															scanHandled = false;
														}}
														scanButtonFullWidth={true}
														testId="public-guest-id-number"
													/>
												</div>
												{#if publicIdTypeOptionsForRegistration.length}
													<label class="flex flex-col gap-1 text-xs">
														<span class="text-surface-700">ID type (optional)</span>
														<select
															class="select select-theme input rounded-container border border-surface-200 px-3 py-2 text-xs"
															bind:value={guestRegIdType}
															aria-label="ID type"
														>
															{#each publicIdTypeOptionsForRegistration as type (type)}
																<option value={type}>{type}</option>
															{/each}
														</select>
													</label>
												{/if}
											</div>
										{:else}
											<button
												type="button"
												class="btn preset-tonal w-full touch-target-h"
												data-testid="public-guest-enter-manually-button"
												onclick={() => (showGuestRegManualId = true)}
												disabled={isSubmitting}
											>
												Enter ID manually
											</button>
										{/if}
									</div>
								</div>
								<div class="border-t border-surface-200 mt-4 pt-4">
									<button
										type="button"
										class="btn preset-filled-primary-500 w-full touch-target-h"
										disabled={isSubmitting}
										onclick={submitGuestIdentificationRegistration}
										data-testid="public-guest-request-registration-submit"
									>
										{isSubmitting ? 'Submitting…' : 'Request registration'}
									</button>
								</div>
							</div>
						{/if}
					</section>
				{/if}
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
								class="select select-theme w-full rounded-container border border-surface-200 px-3 py-2 touch-target-h"
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
									{identityBindingMode === 'required'
										? 'Link your ID (required before starting)'
										: 'Link your ID (recommended)'}
								</p>
								<p class="text-xs text-surface-700">
									{identityBindingMode === 'required'
										? 'Before you can start your visit, scan your ID card or submit a registration so we can link this visit to your record.'
										: 'Scan your ID card or submit a registration to link this visit to your record — or continue without ID.'}
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
										staff member for help. To use this ID here, ask a staff member to register it in the system first.
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
										<button
											type="button"
											class="btn preset-tonal flex-1 touch-target-h"
											data-testid="public-id-request-registration-button"
											onclick={() => {
													showRequestRegForm = !showRequestRegForm;
													if (!showRequestRegForm) return;
													publicIdValue = binderIdNumber;
												}}
										>
											Request verification / Link ID
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
									{#if showRequestRegForm}
										<div class="rounded-container border border-surface-200 bg-surface-100 p-3 space-y-3 mt-3" data-testid="public-request-registration-form">
											<p class="text-xs font-medium text-surface-800">Enter your details (all optional). Staff will verify.</p>
											<label class="flex flex-col gap-1 text-xs">
												<span class="text-surface-600">Name</span>
												<input
													type="text"
													class="input rounded-container border border-surface-200 px-3 py-2"
													placeholder="Full name"
													bind:value={regName}
												/>
											</label>
											<label class="flex flex-col gap-1 text-xs">
												<span class="text-surface-600">Birth year</span>
												<input
													type="number"
													class="input rounded-container border border-surface-200 px-3 py-2"
													placeholder="e.g. 1985"
													bind:value={regBirthYear}
												/>
											</label>
											<label class="flex flex-col gap-1 text-xs">
												<span class="text-surface-600">Classification</span>
												<select class="select select-theme input rounded-container border border-surface-200 px-3 py-2" bind:value={regCategory}>
													{#each REGISTRATION_CATEGORIES as cat (cat.value)}
														<option value={cat.value}>{cat.label}</option>
													{/each}
												</select>
											</label>
											<div class="border-t border-surface-200 pt-3 space-y-2">
												<p class="text-xs font-medium text-surface-800">Optional ID details</p>
												{#if effectiveAllowCameraScanner}
													<button
														type="button"
														class="btn preset-filled-primary-500 w-full text-sm touch-target-h flex items-center justify-center gap-2"
														data-testid="public-request-reg-scan-id-button"
														onclick={() => {
															captureIdTarget = 'binder';
															scannerContext = 'capture_id';
															showScanner = true;
															scanHandled = false;
														}}
														disabled={isSubmitting}
													>
														<Camera class="w-4 h-4 shrink-0" />
														Scan ID to capture number
													</button>
												{/if}
												{#if showRequestRegManualId}
													<div
														class="rounded-container border border-surface-200 bg-surface-50 p-3 space-y-3"
														data-testid="public-request-reg-manual-id-entry-group"
													>
														<div class="flex items-center justify-between gap-2">
															<span class="text-sm font-medium text-surface-700">Enter ID manually</span>
															{#if effectiveAllowCameraScanner}
																<button
																	type="button"
																	class="btn btn-sm preset-tonal"
																	onclick={() => (showRequestRegManualId = false)}
																>
																	Use scanner
																</button>
															{/if}
														</div>
														<div class="space-y-1">
															<span class="text-sm text-surface-700">ID number (optional)</span>
															<IdNumberInput
																bind:value={publicIdValue}
																placeholder="Enter or scan"
																disabled={isSubmitting}
																showMaskToggle={true}
																showScanButton={effectiveAllowCameraScanner}
																onScanClick={() => {
																	captureIdTarget = 'binder';
																	scannerContext = 'capture_id';
																	showScanner = true;
																	scanHandled = false;
																}}
																scanButtonFullWidth={true}
																testId="public-request-reg-id-number"
															/>
														</div>
														{#if publicIdTypeOptionsForRegistration.length}
															<label class="flex flex-col gap-1 text-xs">
																<span class="text-surface-700">ID type (optional)</span>
																<select
																	class="select select-theme input rounded-container border border-surface-200 px-3 py-2 text-xs"
																	bind:value={requestRegIdType}
																	aria-label="ID type"
																>
																	{#each publicIdTypeOptionsForRegistration as type (type)}
																		<option value={type}>{type}</option>
																	{/each}
																</select>
															</label>
														{/if}
													</div>
												{:else}
													<button
														type="button"
														class="btn preset-tonal w-full touch-target-h"
														data-testid="public-request-reg-enter-manually-button"
														onclick={() => (showRequestRegManualId = true)}
														disabled={isSubmitting}
													>
														Enter ID manually
													</button>
												{/if}
											</div>
											<div class="border-t border-surface-200 mt-4 pt-4">
												<button
													type="button"
													class="btn preset-filled-primary-500 w-full touch-target-h"
													data-testid="public-request-registration-submit"
													disabled={isSubmitting}
													onclick={() => (showUnverifiedRequestWarning = true)}
												>
													{isSubmitting ? 'Submitting…' : 'Request identification registration'}
												</button>
											</div>
										</div>
									{/if}
								</div>
							{:else if binderMode === 'ambiguous'}
								<div class="space-y-3" data-testid="public-id-ambiguous">
									<p class="text-sm font-medium text-surface-950">Multiple ID types match this number</p>
									<p class="text-xs text-surface-700">
										This ID could be one of the following. Please select the correct ID type and search again.
									</p>
									{#if publicAmbiguousIdTypes.length}
										<label class="flex flex-col gap-1 text-xs">
											<span class="text-surface-700">ID type</span>
											<select
												class="select select-theme input rounded-container border border-surface-200 px-3 py-2 text-sm"
												data-testid="public-ambiguous-id-type-select"
												bind:value={publicIdType}
												aria-label="ID type"
											>
												{#each publicAmbiguousIdTypes as type (type)}
													<option value={type}>{type}</option>
												{/each}
											</select>
										</label>
										<div class="flex flex-wrap gap-2">
											<button
												type="button"
												class="btn preset-filled-primary-500 flex-1 touch-target-h"
												data-testid="public-ambiguous-search-again"
												onclick={() => submitIdLookup(publicIdNumberPending)}
											>
												Search again with selected type
											</button>
											<button
												type="button"
												class="btn preset-tonal flex-1 touch-target-h"
												onclick={() => {
													binderMode = 'idle';
													publicAmbiguousIdTypes = [];
													publicIdNumberPending = '';
												}}
											>
												Try different ID
											</button>
										</div>
									{/if}
								</div>
							{:else if binderMode === 'match_found' && boundClient}
								<div class="space-y-3" data-testid="public-found-match-confirmation">
									<p class="text-sm font-semibold text-surface-950">Found match</p>
									<p class="text-xs text-surface-700">
										{boundClient.name}
										{#if boundClient.birth_year}
											({boundClient.birth_year})
										{/if}
										{#if boundClient.id_document}
											· {boundClient.id_document.id_type} ending in {boundClient.id_document.id_last4}
										{/if}
									</p>
									<p class="text-xs text-surface-600">Is this you?</p>
									<div class="flex flex-wrap gap-2">
										<button
											type="button"
											class="btn preset-filled-primary-500 flex-1 touch-target-h"
											data-testid="public-found-match-proceed"
											onclick={confirmMatchProceed}
										>
											Proceed
										</button>
										<button
											type="button"
											class="btn preset-tonal flex-1 touch-target-h"
											data-testid="public-found-match-report-not-me"
											onclick={reportNotMe}
										>
											Report as not me
										</button>
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
								<div class="space-y-3">
									<div class="flex flex-wrap gap-2">
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
									<div class="space-y-2 pt-1">
										{#if effectiveAllowCameraScanner}
											<button
												type="button"
												class="btn preset-filled-primary-500 w-full text-sm touch-target-h"
												data-testid="public-id-scan-button"
												onclick={() => {
													captureIdTarget = 'binder';
													scannerContext = 'capture_id';
													showScanner = true;
													scanHandled = false;
												}}
											>
												Scan ID to capture number
											</button>
										{/if}
										{#if showManualIdEntry}
											<div
												class="rounded-container border border-surface-200 bg-surface-50 p-3 space-y-3"
												data-testid="public-id-manual-entry-group"
											>
												<div class="flex items-center justify-between gap-2">
													<span class="text-sm font-medium text-surface-700">Enter ID manually</span>
													<button
														type="button"
														class="btn btn-sm preset-tonal"
														onclick={() => (showManualIdEntry = false)}
													>
														Use scanner
													</button>
												</div>
												<div class="space-y-1">
													<span class="text-sm text-surface-700">ID number (optional)</span>
													<IdNumberInput
														bind:value={binderIdNumber}
														placeholder="Enter or scan"
														disabled={isSubmitting}
														showMaskToggle={true}
														showScanButton={false}
														onKeydown={(e) => {
															if (e.key === 'Enter') {
																e.preventDefault();
																submitIdLookup(binderIdNumber);
															}
														}}
														scanButtonFullWidth={true}
														testId="public-id-number-input"
													/>
												</div>
												<div class="flex flex-wrap gap-2 items-end">
													<label class="flex flex-col gap-1 min-w-0 flex-1">
														<span class="text-sm text-surface-700">ID type (optional)</span>
														<select
															class="select select-theme input rounded-container border border-surface-200 px-3 py-2 text-sm"
															bind:value={publicIdType}
															aria-label="ID type"
														>
															{#each publicIdTypeOptions as type (type)}
																<option value={type}>{type}</option>
															{/each}
														</select>
													</label>
													<button
														type="button"
														class="btn preset-tonal touch-target-h"
														data-testid="public-id-submit-button"
														onclick={() => submitIdLookup(binderIdNumber)}
													>
														Lookup
													</button>
												</div>
											</div>
										{:else}
											<button
												type="button"
												class="btn preset-tonal w-full touch-target-h"
												data-testid="public-id-enter-manually-button"
												onclick={() => (showManualIdEntry = true)}
											>
												Enter ID manually
											</button>
										{/if}
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
								(identityBindingMode === 'required' && (binderStatus !== 'bound' || binderMode !== 'completed'))
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
