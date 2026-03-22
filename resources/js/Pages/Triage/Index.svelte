<script lang="ts">
	import MobileLayout from '../../Layouts/MobileLayout.svelte';
	import Modal from '../../Components/Modal.svelte';
	import ScanModal from '../../Components/ScanModal.svelte';
	import CreateRegistrationModal from '../../Components/CreateRegistrationModal.svelte';
	import StaffTriageBindPanel from '../../Components/StaffTriageBindPanel.svelte';
	import IdNumberInput from '../../Components/IdNumberInput.svelte';
	import AuthChoiceButtons from '../../Components/AuthChoiceButtons.svelte';
	import PinOrQrInput from '../../Components/PinOrQrInput.svelte';
	import { Camera, Plus, Search, Settings } from 'lucide-svelte';
	import { get } from 'svelte/store';
	import { onMount } from 'svelte';
	import { usePage, router } from '@inertiajs/svelte';
	import { toaster } from '../../lib/toaster.js';
	import { clientDisplayName } from '../../lib/clientDisplayName.js';
	import { getLocalAllowHidOnThisDevice, setLocalAllowHidOnThisDevice, getLocalPersistentHidOnThisDevice, setLocalPersistentHidOnThisDevice, isMobileTouch } from '../../lib/displayHid.js';
	import { shouldAllowCameraScanner, setLocalAllowCameraOnThisDevice } from '../../lib/displayCamera.js';

	interface Track {
		id: number;
		name: string;
		color_code: string | null;
		is_default: boolean;
	}

	interface ActiveProgram {
		id: number;
		name: string;
		is_active?: boolean;
		is_paused?: boolean;
		tracks: Track[];
		identity_binding_mode?: 'disabled' | 'required';
		allow_unverified_entry?: boolean;
	}

	/** A.4.2: currentProgram from controller; fallback to program then activeProgram for transition. */
	let {
		currentProgram = null,
		program = null,
		activeProgram = null,
		canSwitchProgram = false,
		programs = [],
		queueCount = 0,
		processedToday = 0,
		display_scan_timeout_seconds = 20,
		staff_triage_allow_hid_barcode = true,
		staff_triage_allow_camera_scanner = true,
		pending_identity_registrations = [],
		site_slug = null,
		program_slug = null,
		allow_public_triage = false,
	}: {
		currentProgram?: ActiveProgram | null;
		program?: ActiveProgram | null;
		activeProgram?: ActiveProgram | null;
		canSwitchProgram?: boolean;
		programs?: { id: number; name: string }[];
		queueCount?: number;
		processedToday?: number;
		display_scan_timeout_seconds?: number;
		staff_triage_allow_hid_barcode?: boolean;
		staff_triage_allow_camera_scanner?: boolean;
		pending_identity_registrations?: { id: number; request_type?: string; first_name: string | null; middle_name: string | null; last_name: string | null; birth_date: string | null; client_category: string | null; mobile_masked: string | null; id_verified: boolean; id_verified_at: string | null; id_verified_by_user_id: number | null; id_verified_by: string | null; requested_at: string; session_id: number | null; session_alias: string | null; token_physical_id?: string; track_name?: string; client_name?: string | null }[];
		site_slug?: string | null;
		program_slug?: string | null;
		allow_public_triage?: boolean;
	} = $props();

	const effectiveProgram = $derived(currentProgram ?? program ?? activeProgram);

	const CATEGORIES = [
		{ label: 'Regular', value: 'Regular' },
		{ label: 'PWD / Senior / Pregnant', value: 'PWD / Senior / Pregnant' },
		{ label: 'Incomplete Documents', value: 'Incomplete Documents' },
	] as const;

	let showScanner = $state(false);
	/** When 'verify_id', scan verifies stored ID; when 'capture_id_accept', scan fills optional ID in Accept modal; when 'capture_id_new_reg', scan fills ID in New registration modal; else token lookup. */
	/** Token scan only; no ID/phone scan until printable ID and scan feature exist. */
	let scannerMode = $state<'token'>('token');
	let scannedToken = $state<{ physical_id: string; qr_hash: string; status: string } | null>(null);
	/** Latch: ignore repeated onScan callbacks after first successful scan (stops flicker). */
	let scanHandled = $state(false);
	let manualPhysicalId = $state('');
	let barcodeValue = $state('');
	let barcodeInputEl = $state<HTMLInputElement | null>(null);
	/** Per plan: category hidden in staff triage normal bind; default to Regular. */
	let selectedCategory = $state<string>('Regular');
	let selectedTrackId = $state<number | null>(null);
	let scanCountdown = $state(0);
	let scanCountdownIntervalId = $state<ReturnType<typeof setInterval> | null>(null);
	/** Account-level preferences (from props, updated after PUT). */
	let accountAllowHid = $state(true);
	let accountAllowCamera = $state(true);
	/** Device-level (localStorage staff_binder); in sync with modal device toggles. */
	let localAllowHid = $state(true);
	let localAllowCamera = $state(true);
	/** When true, HID refocused every 2s when scan modal is closed. */
	let localPersistentHid = $state(false);
	const effectiveHid = $derived(accountAllowHid && localAllowHid);
	const effectiveCamera = $derived(accountAllowCamera && localAllowCamera);
	/** When true, user is in the "manual token input" focus window; pause HID refocus for 10s then return focus to hidden input. */
	let manualFocusActive = $state(false);
	let manualFocusTimeoutId = $state<ReturnType<typeof setTimeout> | null>(null);

	/** Staff triage settings modal: view/edit anytime; apply only on Save with PIN/QR. */
	let showTriageSettingsModal = $state(false);
	let triageSettingsAuthMode = $state<'pin' | 'qr' | 'request'>('pin');
	let triageSettingsPin = $state('');
	let triageSettingsQrScanToken = $state('');
	let triagePinOrQrRef = $state<{ buildPinOrQrPayload?: () => { pin: string } | { qr_scan_token: string } | null } | null>(null);
	let triageSettingsError = $state('');
	let triageSettingsSaving = $state(false);
	let triageSettingsAccountHid = $state(true);
	let triageSettingsAccountCamera = $state(true);
	let triageSettingsLocalHid = $state(true);
	let triageSettingsLocalPersistentHid = $state(false);
	let triageSettingsLocalCamera = $state(true);
	/** QR flow: show QR for admin to scan to unlock settings (reuses display-settings-requests). */
	let triageSettingsRequestId = $state<number | null>(null);
	let triageSettingsRequestToken = $state<string | null>(null);
	let triageSettingsRequestState = $state<'idle' | 'waiting' | 'approved' | 'rejected'>('idle');
	let triageSettingsPollIntervalId = $state<ReturnType<typeof setInterval> | null>(null);

	/** Identity registration accept modal */
	let acceptRegModalReg = $state<typeof pending_identity_registrations[0] | null>(null);
	let acceptVerifyFirstName = $state('');
	let acceptVerifyLastName = $state('');
	let acceptVerifyBirthDate = $state('');
	let acceptVerifyCategory = $state('Regular');
	let acceptPossibleMatches = $state<{ id: number; first_name: string; middle_name?: string | null; last_name: string; birth_date: string | null; mobile_masked?: string | null }[]>([]);
	let acceptLinkSearchName = $state('');
	let acceptLinkSearchBirthDate = $state('');
	let acceptLinkSearching = $state(false);
	let acceptLinkSearchPhone = $state('');
	let acceptPhoneSearching = $state(false);
	let acceptChosenClientId = $state<number | null>(null);
	let acceptCreateNew = $state(false);
	let acceptSubmitting = $state(false);
	/** Reveal phone modal (from Accept modal): reason + result. */
	let showAcceptRevealModal = $state(false);
	let acceptRevealReason = $state('');
	let acceptRevealResult = $state<string | null>(null);
	let acceptRevealSubmitting = $state(false);
	/** When possible-matches returns existing_client_by_phone, this is set so we can show verify-existing copy and pre-select. */
	let acceptExistingClientByPhone = $state<{ id: number; first_name: string; middle_name?: string | null; last_name: string; birth_date: string | null } | null>(null);
	let highlightSessionId = $state<number | null>(null);

	/** Staff direct registration request (no token) */
	let showNewRegModal = $state(false);
	/** When true, show manual ID entry in New reg modal optional ID block (same format as public triage start). */

	/** When true, show manual ID entry in Accept modal optional ID block (same format as public triage start). */

	const MANUAL_FOCUS_SECONDS = 10;

	function startManualFocusWindow() {
		if (manualFocusTimeoutId != null) clearTimeout(manualFocusTimeoutId);
		manualFocusActive = true;
		manualFocusTimeoutId = setTimeout(() => {
			manualFocusTimeoutId = null;
			manualFocusActive = false;
			if (effectiveHid) barcodeInputEl?.focus();
		}, MANUAL_FOCUS_SECONDS * 1000);
	}

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
			return { ok: res.ok, data, message: data?.message, errors: data?.errors };
		} catch (e) {
			toaster.error({ title: MSG_NETWORK_ERROR });
			return { ok: false, message: MSG_NETWORK_ERROR };
		}
	}

	async function submitNewRegistrationRequest(payload: import('../../Components/CreateRegistrationModal.svelte').CreateRegistrationPayload) {
		const { ok, data, message } = await api('POST', '/api/identity-registrations/direct', payload);
		const msg = (data as { message?: string })?.message ?? (message as string);
		return { ok, message: msg };
	}

	$effect(() => {
		accountAllowHid = staff_triage_allow_hid_barcode !== false;
		accountAllowCamera = staff_triage_allow_camera_scanner !== false;
	});

	onMount(() => {
		const hidLocal = getLocalAllowHidOnThisDevice('staff_binder');
		localAllowHid = hidLocal !== null ? hidLocal : !isMobileTouch();
		localPersistentHid = getLocalPersistentHidOnThisDevice('staff_binder');
		localAllowCamera = shouldAllowCameraScanner('staff_binder', staff_triage_allow_camera_scanner !== false);
	});

	/** When persistent HID is on, refocus global HID every 2s when scan modal is closed. */
	$effect(() => {
		if (showScanner || !localPersistentHid || !effectiveHid) return;
		const id = setInterval(() => {
			if (!showScanner && localPersistentHid && effectiveHid) barcodeInputEl?.focus();
		}, 2000);
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
		scannerMode = 'token';
		if (localPersistentHid && effectiveHid) barcodeInputEl?.focus();
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
					toaster.error({ title: 'Token is already in use.' });
				} else if (t?.status === 'deactivated') {
					toaster.error({ title: 'Token deactivated.' });
				} else {
					toaster.error({ title: 'Token not found.' });
				}
				scanHandled = false;
			});
		} else {
			manualPhysicalId = raw.slice(0, 10);
			handleLookup();
		}
		barcodeValue = '';
	}

	async function handleLookup() {
		const id = manualPhysicalId.trim();
		if (!id) return;
		scannedToken = null;
		const { ok, data, message } = await api('GET', `/api/sessions/token-lookup?physical_id=${encodeURIComponent(id)}`);
		if (!ok) {
			toaster.error({ title: message ?? 'Token not found.' });
			scanHandled = false;
			return;
		}
		const t = data as { physical_id: string; qr_hash: string; status: string };
		if (t.status !== 'available') {
			toaster.error({ title: t.status === 'in_use' ? 'Token is already in use.' : `Token is marked as ${t.status}.` });
			// Consume the scan so QR scanner doesn't keep firing and re-clearing/setting error (flashing)
			scanHandled = true;
			return;
		}
		scannedToken = t;
	}

	async function handleQrScan(decodedText: string) {
		if (scanHandled) return;
		scanHandled = true;
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
				toaster.error({ title: 'Token is already in use.' });
			} else if (t?.status === 'deactivated') {
				toaster.error({ title: 'Token deactivated.' });
			} else {
				toaster.error({ title: 'Token not found.' });
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
		showScanner = false;
		scannerMode = 'token';
	}

	const STAFF_TRIAGE_SETTINGS_REQUEST_QR_PREFIX = 'flexiqueue:display_settings_request:';

	function openTriageSettingsModal() {
		triageSettingsAccountHid = accountAllowHid;
		triageSettingsAccountCamera = accountAllowCamera;
		triageSettingsLocalHid = localAllowHid;
		triageSettingsLocalPersistentHid = localPersistentHid;
		triageSettingsLocalCamera = localAllowCamera;
		triageSettingsAuthMode = 'pin';
		triageSettingsPin = '';
		triageSettingsQrScanToken = '';
		triageSettingsError = '';
		triageSettingsRequestId = null;
		triageSettingsRequestToken = null;
		triageSettingsRequestState = 'idle';
		if (triageSettingsPollIntervalId) {
			clearInterval(triageSettingsPollIntervalId);
			triageSettingsPollIntervalId = null;
		}
		showTriageSettingsModal = true;
	}

	function cancelTriageSettingsRequest() {
		triageSettingsRequestState = 'idle';
		triageSettingsRequestId = null;
		triageSettingsRequestToken = null;
		if (triageSettingsPollIntervalId) {
			clearInterval(triageSettingsPollIntervalId);
			triageSettingsPollIntervalId = null;
		}
	}

	async function createStaffTriageSettingsRequest() {
		const programId = effectiveProgram?.id;
		if (programId == null || triageSettingsSaving) return;
		triageSettingsSaving = true;
		triageSettingsError = '';
		try {
			const body = {
				program_id: programId,
				enable_public_triage_hid_barcode: triageSettingsAccountHid,
				enable_public_triage_camera_scanner: triageSettingsAccountCamera,
			};
			const res = await fetch('/api/public/display-settings-requests', {
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
			if (!res.ok) {
				triageSettingsError = (data as { message?: string }).message || 'Failed to create request.';
				toaster.error({ title: triageSettingsError });
				return;
			}
			const d = data as { id: number; request_token: string };
			triageSettingsRequestId = d.id;
			triageSettingsRequestToken = d.request_token;
			triageSettingsRequestState = 'waiting';
			const id = d.id;
			const token = d.request_token;
			triageSettingsPollIntervalId = setInterval(async () => {
				try {
					const r = await fetch(
						`/api/public/display-settings-requests/${id}?token=${encodeURIComponent(token)}`,
						{ credentials: 'same-origin' }
					);
					const pollData = await r.json().catch(() => ({}));
					const status = (pollData as { status?: string }).status;
					if (status === 'approved') {
						if (triageSettingsPollIntervalId) clearInterval(triageSettingsPollIntervalId);
						triageSettingsPollIntervalId = null;
						triageSettingsRequestId = null;
						triageSettingsRequestToken = null;
						triageSettingsRequestState = 'idle';
						setLocalAllowHidOnThisDevice('staff_binder', triageSettingsLocalHid);
						localAllowHid = triageSettingsLocalHid;
						setLocalPersistentHidOnThisDevice('staff_binder', triageSettingsLocalPersistentHid);
						localPersistentHid = triageSettingsLocalPersistentHid;
						setLocalAllowCameraOnThisDevice('staff_binder', triageSettingsLocalCamera);
						localAllowCamera = triageSettingsLocalCamera;
						toaster.success({ title: 'Settings applied.' });
						showTriageSettingsModal = false;
					} else if (status === 'rejected' || status === 'cancelled') {
						if (triageSettingsPollIntervalId) clearInterval(triageSettingsPollIntervalId);
						triageSettingsPollIntervalId = null;
						triageSettingsRequestState = 'idle';
						triageSettingsRequestId = null;
						triageSettingsRequestToken = null;
						toaster.warning({ title: status === 'rejected' ? 'Request was rejected.' : 'Request was cancelled.' });
					}
				} catch {
					// ignore
				}
			}, 2000);
		} finally {
			triageSettingsSaving = false;
		}
	}

	async function saveTriageSettings() {
		triageSettingsError = '';
		// Logged-in staff can save without PIN/QR; PIN and QR remain optional for extra verification if desired.
		const authBody = triageSettingsAuthMode === 'pin' ? (triagePinOrQrRef?.buildPinOrQrPayload?.() ?? null) : null;
		if (triageSettingsAuthMode === 'request' && !authBody) {
			triageSettingsError = 'Use "Show QR for supervisor to scan" to apply changes.';
			return;
		}
		triageSettingsSaving = true;
		try {
			const res = await fetch('/api/profile/triage-settings', {
				method: 'PUT',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-CSRF-TOKEN': getCsrfToken(),
					'X-Requested-With': 'XMLHttpRequest',
				},
				credentials: 'same-origin',
				body: JSON.stringify({
					allow_hid_barcode: triageSettingsAccountHid,
					allow_camera_scanner: triageSettingsAccountCamera,
				}),
			});
			const data = await res.json().catch(() => ({}));
			if (res.ok) {
				const d = data as { allow_hid_barcode?: boolean; allow_camera_scanner?: boolean };
				accountAllowHid = d.allow_hid_barcode !== false;
				accountAllowCamera = d.allow_camera_scanner !== false;
				setLocalAllowHidOnThisDevice('staff_binder', triageSettingsLocalHid);
				localAllowHid = triageSettingsLocalHid;
				setLocalPersistentHidOnThisDevice('staff_binder', triageSettingsLocalPersistentHid);
				localPersistentHid = triageSettingsLocalPersistentHid;
				setLocalAllowCameraOnThisDevice('staff_binder', triageSettingsLocalCamera);
				localAllowCamera = triageSettingsLocalCamera;
				showTriageSettingsModal = false;
			} else {
				triageSettingsError = (data as { message?: string }).message ?? 'Failed to save.';
				toaster.error({ title: triageSettingsError });
			}
		} catch (e) {
			toaster.error({ title: MSG_NETWORK_ERROR });
			triageSettingsError = MSG_NETWORK_ERROR;
		} finally {
			triageSettingsSaving = false;
		}
	}

	/** Per ISSUES-ELABORATION §12: clear error and allow scan/lookup again without refresh (e.g. after token freed elsewhere). */
	function tryAgain() {
		scanHandled = false;
	}

	// Identity registration accept/reject
	const ACCEPT_CATEGORIES = [
		{ label: 'Regular', value: 'Regular' },
		{ label: 'PWD / Senior / Pregnant', value: 'PWD / Senior / Pregnant' },
	] as const;

	function openAcceptModal(reg: (typeof pending_identity_registrations)[0]) {
		acceptRegModalReg = reg;
		acceptVerifyFirstName = reg.first_name ?? '';
		acceptVerifyLastName = reg.last_name ?? '';
		acceptVerifyBirthDate = reg.birth_date ?? '';
		acceptVerifyCategory = reg.client_category ?? 'Regular';
		acceptPossibleMatches = [];
		acceptExistingClientByPhone = null;
		acceptLinkSearchName = clientDisplayName(reg);
		acceptLinkSearchBirthDate = reg.birth_date ?? '';
		acceptChosenClientId = null;
		acceptCreateNew = false;
		fetchPossibleMatches(reg.id);
	}

	function closeAcceptModal() {
		acceptRegModalReg = null;
		showAcceptRevealModal = false;
		acceptRevealResult = null;
		acceptRevealReason = '';
	}

	async function fetchPossibleMatches(regId: number) {
		const { ok, data } = await api('GET', `/api/identity-registrations/${regId}/possible-matches`);
		if (!ok || !data) return;
		const payload = data as {
			data?: { id: number; first_name: string; middle_name?: string | null; last_name: string; birth_date: string | null; mobile_masked?: string | null }[];
			existing_client_by_phone?: { id: number; first_name: string; middle_name?: string | null; last_name: string; birth_date: string | null } | null;
		};
		const list = Array.isArray(payload.data) ? payload.data : [];
		const existingByPhone = payload.existing_client_by_phone ?? null;
		acceptExistingClientByPhone = existingByPhone;
		// Ensure existing client by phone is in the list so selection works; prepend if not already present.
		if (existingByPhone && !list.some((c) => c.id === existingByPhone.id)) {
			acceptPossibleMatches = [{ ...existingByPhone }, ...list];
		} else {
			acceptPossibleMatches = list;
		}
		if (existingByPhone) {
			acceptChosenClientId = existingByPhone.id;
			acceptCreateNew = false;
		}
	}

	async function runAcceptLinkSearch(e?: SubmitEvent) {
		e?.preventDefault();
		const name = acceptLinkSearchName.trim();
		if (!name) return;
		acceptLinkSearching = true;
		try {
			const params = new URLSearchParams({ name, per_page: '10', page: '1' });
			if (effectiveProgram?.id != null) params.set('program_id', String(effectiveProgram.id));
			const bd = acceptLinkSearchBirthDate.trim();
			if (bd) params.set('birth_date', bd);
			const { ok, data } = await api('GET', `/api/clients/search?${params.toString()}`);
			if (ok && data) {
				const payload = data as { data?: { id: number; first_name: string; middle_name?: string | null; last_name: string; birth_date: string | null; mobile_masked?: string | null }[] };
				acceptPossibleMatches = payload.data ?? [];
			} else {
				acceptPossibleMatches = [];
			}
		} finally {
			acceptLinkSearching = false;
		}
	}

	async function runAcceptPhoneSearch(e?: SubmitEvent) {
		e?.preventDefault();
		const mobile = acceptLinkSearchPhone.trim();
		if (!mobile) return;
		acceptPhoneSearching = true;
		try {
			const body: Record<string, string | number> = { mobile };
			if (effectiveProgram?.id != null) body.program_id = effectiveProgram.id;
			const { ok, data } = await api('POST', '/api/clients/search-by-phone', body);
			if (ok && data) {
				const payload = data as { match_status?: string; client?: { id: number; first_name: string; middle_name?: string | null; last_name: string; birth_date: string | null; mobile_masked?: string | null } };
				if (payload.match_status === 'existing' && payload.client) {
					acceptPossibleMatches = [payload.client];
					acceptChosenClientId = payload.client.id;
					acceptCreateNew = false;
					acceptVerifyFirstName = payload.client.first_name ?? '';
					acceptVerifyLastName = payload.client.last_name ?? '';
					acceptVerifyBirthDate = payload.client.birth_date ?? '';
				} else {
					acceptPossibleMatches = [];
					acceptChosenClientId = null;
					toaster.warning({ title: 'No client found with that phone number.' });
				}
			} else {
				acceptPossibleMatches = [];
				acceptChosenClientId = null;
				toaster.warning({ title: 'No client found with that phone number.' });
			}
		} finally {
			acceptPhoneSearching = false;
		}
	}

	const acceptCanSubmit = $derived(
		acceptVerifyFirstName.trim() !== '' &&
		acceptVerifyLastName.trim() !== '' &&
		acceptVerifyBirthDate.trim() !== '' &&
		(acceptChosenClientId !== null || acceptCreateNew)
	);

	const acceptSelectedClient = $derived(
		!acceptCreateNew && acceptChosenClientId !== null
			? (acceptPossibleMatches.find((c) => c.id === acceptChosenClientId) ?? null)
			: null
	);

	async function submitAccept() {
		if (!acceptRegModalReg || !acceptCanSubmit || acceptSubmitting) return;
		acceptSubmitting = true;
		const body: Record<string, unknown> = {
			first_name: acceptVerifyFirstName.trim(),
			last_name: acceptVerifyLastName.trim(),
			birth_date: acceptVerifyBirthDate.trim(),
			client_category: acceptVerifyCategory,
			create_new_client: acceptCreateNew,
		};
		if (!acceptCreateNew && acceptChosenClientId) body.client_id = acceptChosenClientId;
		const { ok, message } = await api('POST', `/api/identity-registrations/${acceptRegModalReg.id}/accept`, body);
		acceptSubmitting = false;
		if (ok) {
			const selectedClient = !acceptCreateNew && acceptChosenClientId !== null
				? acceptPossibleMatches.find((c) => c.id === acceptChosenClientId) ?? null
				: null;
			const edited = selectedClient
				? (acceptVerifyFirstName.trim() !== (selectedClient.first_name ?? '') || acceptVerifyLastName.trim() !== (selectedClient.last_name ?? '') || acceptVerifyBirthDate.trim() !== (selectedClient.birth_date ?? '').trim())
				: false;
			if (selectedClient) {
				toaster.success({ title: edited ? 'Edited and verified client.' : 'Verified.' });
			} else {
				toaster.success({ title: 'Registration accepted.' });
			}
			closeAcceptModal();
			router.reload();
		} else {
			toaster.error({ title: (message as string) ?? 'Accept failed.' });
		}
	}

	async function submitReject(regId: number) {
		const { ok } = await api('POST', `/api/identity-registrations/${regId}/reject`, {});
		if (ok) {
			toaster.success({ title: 'Registration rejected.' });
			router.reload();
		}
	}

	/** FLOW A: staff confirms bind_confirmation hold → session created. */
	let confirmBindRegId = $state<number | null>(null);
	async function submitConfirmBind(regId: number) {
		if (confirmBindRegId !== null) return;
		confirmBindRegId = regId;
		const { ok, message } = await api('POST', `/api/identity-registrations/${regId}/confirm-bind`, {});
		confirmBindRegId = null;
		if (ok) {
			toaster.success({ title: 'Visit started.' });
			router.reload();
		} else {
			toaster.error({ title: (message as string) ?? 'Confirm bind failed.' });
		}
	}

	async function submitAcceptReveal() {
		if (!acceptRegModalReg || !acceptRevealReason.trim() || acceptRevealSubmitting) return;
		acceptRevealSubmitting = true;
		acceptRevealResult = null;
		const { ok, data, message } = await api('POST', `/api/identity-registrations/${acceptRegModalReg.id}/reveal-phone`, { reason: acceptRevealReason.trim() });
		acceptRevealSubmitting = false;
		if (ok && data && typeof (data as { mobile?: string }).mobile === 'string') {
			acceptRevealResult = (data as { mobile: string }).mobile;
		} else {
			toaster.error({ title: (message as string) ?? 'Reveal failed.' });
		}
	}

	// Read highlight_session_id from URL (e.g. from station unverified badge click)
	$effect(() => {
		if (typeof window === 'undefined') return;
		const url = new URL(window.location.href);
		const id = url.searchParams.get('highlight_session_id');
		highlightSessionId = id ? Number(id) : null;

		// Make highlight one-shot per redirect: strip param from URL so refresh doesn't re-highlight.
		if (id !== null) {
			url.searchParams.delete('highlight_session_id');
			window.history.replaceState(window.history.state, '', url.toString());
		}
	});

	// Auto-clear highlight a short time after it is applied so it doesn't stay permanently.
	$effect(() => {
		if (highlightSessionId == null) return;
		const timeoutId = setTimeout(() => {
			highlightSessionId = null;
		}, 2000);
		return () => {
			clearTimeout(timeoutId);
		};
	});
</script>

<svelte:head>
	<title>Triage — FlexiQueue</title>
</svelte:head>

<MobileLayout headerTitle="Triage" {queueCount} {processedToday}>
	<div class="flex flex-col gap-4 md:gap-6 text-surface-950 w-full max-w-2xl mx-auto px-4 md:px-6 py-4 md:py-6">
		{#if !effectiveProgram}
			<div class="rounded-container bg-surface-50 border border-surface-200 elevation-card p-6 md:p-8 text-center text-surface-950/80">
				<p class="font-medium">No active program</p>
				<p class="mt-2 text-sm">Activate a program from Admin → Programs.</p>
			</div>
		{:else}
			<div class="flex items-center justify-between gap-2">
				<h1 class="text-xl md:text-2xl font-semibold text-surface-950">Triage</h1>
				<div class="flex items-center gap-2">
					<button
						type="button"
						class="btn btn-icon preset-tonal touch-target"
						aria-label="Triage settings (HID and scanner)"
						title="Settings"
						onclick={openTriageSettingsModal}
					>
						<Settings class="w-5 h-5" />
					</button>
				</div>
			</div>

			{#if pending_identity_registrations?.length > 0}
				<section class="rounded-container bg-surface-50 elevation-card p-4" data-testid="identity-registrations-section">
					<div class="flex items-center justify-between gap-2 mb-2">
						<h2 class="text-sm font-semibold text-surface-950">Identity registrations</h2>
						<button
							type="button"
							class="btn preset-tonal text-sm touch-target-h flex items-center gap-2"
							onclick={() => (showNewRegModal = true)}
							data-testid="triage-new-registration-button"
						>
							<Plus class="w-4 h-4" />
							New client registration
						</button>
					</div>
					<ul class="space-y-2">
						{#each pending_identity_registrations as reg (reg.id)}
							<li
								class="flex flex-wrap items-center justify-between gap-2 py-2 border-b border-surface-200 last:border-b-0 {highlightSessionId === reg.session_id ? 'triage-row-highlight' : ''}"
								data-testid="identity-registration-row-{reg.id}"
							>
								<div class="min-w-0">
									<p class="text-sm font-medium text-surface-900 truncate flex items-center gap-2">
										{clientDisplayName(reg)}
										{#if reg.request_type === 'bind_confirmation'}
											<span class="badge badge-sm badge-tonal-primary" data-testid="identity-registration-bind-confirmation-badge">Verify & start</span>
										{/if}
										{#if reg.id_verified_at}
											<span class="badge badge-sm badge-filled-primary-500" data-testid="identity-registration-verified-badge">Verified</span>
										{/if}
									</p>
									<p class="text-xs text-surface-600">
										Birth date: {reg.birth_date ?? '—'} · Category: {reg.client_category ?? '—'}
										{#if reg.mobile_masked}
											· {reg.mobile_masked}
										{/if}
										{#if reg.request_type === 'bind_confirmation' && (reg.token_physical_id || reg.track_name || reg.client_name)}
											· Token: {reg.token_physical_id ?? '—'} · Track: {reg.track_name ?? '—'} · Client: {reg.client_name ?? '—'}
										{/if}
										{#if reg.session_alias}
											· Session: {reg.session_alias}
										{/if}
									</p>
								</div>
								<div class="flex gap-2 shrink-0">
									{#if reg.request_type === 'bind_confirmation'}
										<button
											type="button"
											class="btn preset-filled-primary-500 text-sm touch-target-h"
											data-testid="identity-registration-confirm-bind-{reg.id}"
											disabled={confirmBindRegId === reg.id}
											onclick={() => submitConfirmBind(reg.id)}
										>
											{confirmBindRegId === reg.id ? 'Starting…' : 'Confirm Bind'}
										</button>
									{:else}
										<button
											type="button"
											class="btn preset-filled-primary-500 text-sm touch-target-h"
											data-testid="identity-registration-verify-{reg.id}"
											onclick={() => openAcceptModal(reg)}
										>
											Verify
										</button>
									{/if}
									<button
										type="button"
										class="btn preset-tonal text-sm touch-target-h"
										data-testid="identity-registration-reject-{reg.id}"
										onclick={() => submitReject(reg.id)}
									>
										Reject
									</button>
								</div>
							</li>
						{/each}
					</ul>
				</section>
			{:else}
				<section class="rounded-container bg-surface-50 elevation-card p-4" data-testid="identity-registrations-section-empty">
					<div class="flex items-center justify-between gap-2">
						<div class="min-w-0">
							<h2 class="text-sm font-semibold text-surface-950">Identity registrations</h2>
							<p class="text-xs text-surface-600">No pending registration requests.</p>
						</div>
						<button
							type="button"
							class="btn preset-tonal text-sm touch-target-h flex items-center gap-2"
							onclick={() => (showNewRegModal = true)}
							data-testid="triage-new-registration-button"
						>
							<Plus class="w-4 h-4" />
							New registration
						</button>
					</div>
				</section>
			{/if}

			{#if effectiveHid}
				<!-- Global HID barcode input (sr-only). Per plan: only focused when scanner modal is open. -->
				<input
					type="text"
					autocomplete="off"
					inputmode="none"
					aria-label="Barcode scanner input; scan with hardware scanner or type and press Enter"
					class="sr-only"
					bind:value={barcodeValue}
					bind:this={barcodeInputEl}
					onkeydown={onBarcodeKeydown}
				/>
			{/if}

			<ScanModal
				open={showScanner}
				onClose={closeScanner}
				title="Scan QR via Camera"
				allowHid={effectiveHid}
				allowCamera={effectiveCamera}
				onScan={handleQrScan}
				wide={true}
			>
				{#snippet extra()}
					{#if scanCountdown > 0}
						<div class="flex flex-wrap items-center justify-center gap-2">
							<p class="text-sm text-surface-600" aria-live="polite">Closing in {scanCountdown}s</p>
							<button type="button" class="btn preset-tonal text-sm py-1.5 px-3" onclick={extendScannerCountdown}>
								Extend (+{Math.max(0, Number(display_scan_timeout_seconds) || 20)}s)
							</button>
						</div>
					{/if}
				{/snippet}
			</ScanModal>

			{#if !scannedToken}
				<!-- Get token: pulsing CTA with camera icon opens modal (same pattern as display). -->
				<div
					class="rounded-container border border-surface-200 bg-surface-50 elevation-card p-4 md:p-6 flex flex-col gap-4"
					data-testid="triage-token-card"
				>
					<div
						class="flex items-center gap-3 rounded-container border-2 border-primary-500/30 bg-primary-500/5 p-4 animate-pulse"
						role="region"
						aria-label="Scan or enter token ID"
					>
						<p class="flex-1 text-base font-medium text-surface-950">Scan or enter token ID</p>
						{#if effectiveCamera}
							<button
								type="button"
								class="btn btn-icon preset-filled-primary-500 shrink-0 touch-target"
								aria-label="Open camera to scan QR"
								title="Tap to scan with device camera"
								onclick={() => {
									showScanner = true;
									scanHandled = false;
								}}
							>
								<Camera class="w-6 h-6" />
							</button>
						{/if}
						{#if effectiveHid}
							<button
								type="button"
								class="btn btn-sm preset-tonal shrink-0 touch-target-h"
								aria-label="Open scan modal for barcode"
								onclick={() => {
									showScanner = true;
									scanHandled = false;
								}}
							>
								Scan with barcode
							</button>
						{/if}
					</div>
					<div class="flex items-center gap-2">
						<span class="text-xs text-surface-950/60 shrink-0">or enter token ID</span>
						<div class="flex-1 border-t border-surface-200"></div>
					</div>
					<div class="flex gap-2">
						<input
							type="text"
							class="input flex-1 rounded-container border border-surface-200 px-3 touch-target-h"
							placeholder="e.g. A1"
							data-testid="triage-token-input"
							bind:value={manualPhysicalId}
							onfocus={startManualFocusWindow}
							onkeydown={(e) => e.key === 'Enter' && handleLookup()}
						/>
						<button
							type="button"
							class="btn preset-filled-primary-500 touch-target px-4"
							data-testid="triage-token-lookup-button"
							onclick={handleLookup}
						>
							Look up
						</button>
					</div>
				</div>

				<Modal open={showTriageSettingsModal} title="Triage settings" onClose={() => { cancelTriageSettingsRequest(); showTriageSettingsModal = false; }}>
					{#snippet children()}
						<div class="flex flex-col gap-6">
							<p class="text-sm text-surface-950/70">You can view and change settings below. Changes are applied only when you save; saving requires PIN or QR (admin scan).</p>
							<div class="flex flex-col gap-4">
								<h3 class="text-sm font-semibold text-surface-950">On this account</h3>
								<p class="text-xs text-surface-950/60">Saved to your account; applies on any device when you are logged in.</p>
								<label class="flex items-center gap-2 cursor-pointer">
									<input type="checkbox" class="checkbox" bind:checked={triageSettingsAccountHid} disabled={triageSettingsSaving} />
									<span class="text-sm text-surface-950">Allow HID barcode scanner</span>
								</label>
								<label class="flex items-center gap-2 cursor-pointer">
									<input type="checkbox" class="checkbox" bind:checked={triageSettingsAccountCamera} disabled={triageSettingsSaving} />
									<span class="text-sm text-surface-950">Allow camera/QR scanner</span>
								</label>
							</div>
							<div class="border-t border-surface-200 pt-4 flex flex-col gap-2">
								<h3 class="text-sm font-semibold text-surface-950">On this device</h3>
								<p class="text-xs text-surface-950/60">Stored on this device only — not saved to server.</p>
								<label class="flex items-center gap-2 cursor-pointer">
									<input type="checkbox" class="checkbox" bind:checked={triageSettingsLocalHid} />
									<span class="text-sm text-surface-950">Allow HID scanner on this device</span>
								</label>
								{#if triageSettingsLocalHid}
									<label class="flex items-center gap-2 cursor-pointer pl-6">
										<input type="checkbox" class="checkbox" bind:checked={triageSettingsLocalPersistentHid} />
										<span class="text-sm text-surface-950">Keep HID ready when scan modal is closed</span>
									</label>
								{/if}
								<label class="flex items-center gap-2 cursor-pointer">
									<input type="checkbox" class="checkbox" bind:checked={triageSettingsLocalCamera} />
									<span class="text-sm text-surface-950">Allow camera/QR scanner on this device</span>
								</label>
							</div>
							<div class="border-t border-surface-200 pt-4 flex flex-col gap-2">
								<h3 class="text-sm font-semibold text-surface-950">Apply changes</h3>
								<p class="text-xs text-surface-950/60">Authorize with PIN or show QR for supervisor to scan. Settings above are applied only when you save.</p>
								<AuthChoiceButtons includeRequest={true} disabled={triageSettingsSaving || triageSettingsRequestState === 'waiting'} bind:mode={triageSettingsAuthMode} />
								{#if triageSettingsRequestState === 'waiting' && triageSettingsRequestId != null && triageSettingsRequestToken != null}
									<div class="flex flex-col items-center gap-3 py-4">
										<p class="text-sm font-medium text-surface-950">Waiting for approval…</p>
										<p class="text-xs text-surface-950/60 text-center">Ask the program supervisor or admin to scan this QR on the Track overrides page.</p>
										<img class="rounded-container border border-surface-200 bg-white p-2" alt="QR for supervisor to scan" width="200" height="200" src={`https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(STAFF_TRIAGE_SETTINGS_REQUEST_QR_PREFIX + triageSettingsRequestId + ':' + triageSettingsRequestToken)}`} />
										<button type="button" class="btn preset-tonal btn-sm touch-target-h" onclick={cancelTriageSettingsRequest}>Cancel request</button>
									</div>
								{:else if triageSettingsAuthMode === 'request'}
									<button type="button" class="btn preset-filled-primary-500" onclick={createStaffTriageSettingsRequest} disabled={triageSettingsSaving || effectiveProgram?.id == null}>
										{triageSettingsSaving ? 'Creating…' : 'Show QR for supervisor to scan'}
									</button>
								{:else}
									<PinOrQrInput bind:this={triagePinOrQrRef} disabled={triageSettingsSaving} mode={triageSettingsAuthMode} bind:pin={triageSettingsPin} bind:qrScanToken={triageSettingsQrScanToken} />
								{/if}
								{#if triageSettingsError}
									<p class="text-sm text-error-600">{triageSettingsError}</p>
								{/if}
							</div>
							<div class="flex flex-wrap gap-2 justify-end pt-2">
								<button type="button" class="btn preset-tonal" onclick={() => { cancelTriageSettingsRequest(); showTriageSettingsModal = false; }} disabled={triageSettingsSaving}>Cancel</button>
								{#if triageSettingsAuthMode !== 'request'}
									<button type="button" class="btn preset-filled-primary-500" onclick={saveTriageSettings} disabled={triageSettingsSaving}>{triageSettingsSaving ? 'Saving…' : 'Save'}</button>
								{/if}
							</div>
						</div>
					{/snippet}
				</Modal>

				<CreateRegistrationModal
					open={showNewRegModal}
					onClose={() => (showNewRegModal = false)}
					onSubmitSuccess={() => {
						toaster.success({ title: 'Registration created.' });
						router.reload();
					}}
					programId={effectiveProgram?.id ?? null}
					submitRequest={submitNewRegistrationRequest}
				/>
			{:else}
				<StaffTriageBindPanel
					program={effectiveProgram}
					token={scannedToken}
					effectiveHid={effectiveHid}
					effectiveCamera={effectiveCamera}
					getCsrfToken={getCsrfToken}
					onCancel={resetScan}
					onBound={() => {
						resetScan();
						toaster.success({ title: 'Visit started.' });
						router.reload();
					}}
				/>
			{/if}
		{/if}

		<Modal
			open={acceptRegModalReg !== null}
			title="Verify identity registration"
			onClose={closeAcceptModal}
			wide={true}
		>
			{#snippet children()}
				{#if acceptRegModalReg}
					<div class="space-y-4" data-testid="accept-registration-form">
						<p class="text-sm text-surface-700">
							Verify details, then choose an existing client to verify (or update their details) or create a new one.
						</p>
						{#if acceptRegModalReg.mobile_masked}
							<p class="text-xs text-surface-600">Phone: {acceptRegModalReg.mobile_masked}</p>
						{/if}
						{#if acceptExistingClientByPhone}
							<div class="rounded-container border border-primary-200 bg-primary-50 p-3 text-sm text-primary-900" data-testid="accept-existing-client-by-phone-banner">
								<p class="font-medium">This phone is already registered to a client.</p>
								<p class="text-xs mt-1">Verify that client below; you can reveal phone for verification or update their details.</p>
							</div>
						{/if}
						{#if acceptSelectedClient}
							<div class="rounded-container border border-primary-200 bg-primary-50/50 p-3 space-y-1">
								<p class="text-xs font-medium text-surface-700">Verify this client; edit details below if needed.</p>
								{#if acceptSelectedClient.mobile_masked}
									<p class="text-xs text-surface-600">Client phone: {acceptSelectedClient.mobile_masked}</p>
								{/if}
							</div>
						{/if}
						<label class="flex flex-col gap-1 text-sm">
							<span class="font-medium text-surface-800">First name (required)</span>
							<input type="text" class="input rounded-container border border-surface-200 px-3 py-2" bind:value={acceptVerifyFirstName} placeholder="First name" />
						</label>
						<label class="flex flex-col gap-1 text-sm">
							<span class="font-medium text-surface-800">Last name (required)</span>
							<input type="text" class="input rounded-container border border-surface-200 px-3 py-2" bind:value={acceptVerifyLastName} placeholder="Last name" />
						</label>
						<label class="flex flex-col gap-1 text-sm">
							<span class="font-medium text-surface-800">Birth date (required)</span>
							<input type="date" class="input rounded-container border border-surface-200 px-3 py-2" bind:value={acceptVerifyBirthDate} />
						</label>
						<label class="flex flex-col gap-1 text-sm">
							<span class="font-medium text-surface-800">Classification (required)</span>
							<select class="select select-theme input rounded-container border border-surface-200 px-3 py-2" bind:value={acceptVerifyCategory}>
								{#each ACCEPT_CATEGORIES as cat (cat.value)}
									<option value={cat.value}>{cat.label}</option>
								{/each}
							</select>
						</label>
						<div class="rounded-container border border-surface-200 bg-surface-50 p-3 space-y-3">
							<p class="text-sm font-medium text-surface-800">Client</p>
							<form class="space-y-2" onsubmit={runAcceptPhoneSearch}>
								<label for="accept-link-search-phone" class="label-text text-xs font-semibold uppercase tracking-wide text-surface-500 block">Search by phone</label>
								<div class="join w-full">
									<div class="join-item flex items-center gap-2 px-3 py-1 border border-surface-300 rounded-l-container bg-surface-50 w-full">
										<Search class="w-4 h-4 my-2 text-surface-400 shrink-0" />
										<input
											type="tel"
											inputmode="numeric"
											id="accept-link-search-phone"
											class="input input-ghost !bg-transparent px-0 py-0 h-auto text-sm w-full focus:!outline-none focus:!ring-0 focus:!border-transparent"
											bind:value={acceptLinkSearchPhone}
											placeholder="e.g. 09171234567"
											data-testid="accept-link-search-phone"
										/>
									</div>
									<button
										type="submit"
										class="join-item btn preset-filled-primary-500 px-4 text-sm shadow-sm !rounded-none !rounded-tr-lg !rounded-br-lg"
										disabled={acceptPhoneSearching || !acceptLinkSearchPhone.trim()}
										data-testid="accept-link-search-phone-button"
									>
										{acceptPhoneSearching ? 'Searching…' : 'Search'}
									</button>
								</div>
							</form>
							<form class="space-y-2" onsubmit={runAcceptLinkSearch}>
								<label for="accept-link-search-name" class="label-text text-xs font-semibold uppercase tracking-wide text-surface-500 block">Search by name</label>
								<div class="join w-full">
									<div class="join-item flex items-center gap-2 px-3 py-1 border border-surface-300 rounded-l-container bg-surface-50 w-full">
										<Search class="w-4 h-4 my-2 text-surface-400 shrink-0" />
										<input
											type="text"
											id="accept-link-search-name"
											class="input input-ghost !bg-transparent px-0 py-0 h-auto text-sm w-full focus:!outline-none focus:!ring-0 focus:!border-transparent"
											bind:value={acceptLinkSearchName}
											placeholder="e.g. Maria Santos"
											data-testid="accept-link-search-name"
										/>
									</div>
									<button
										type="submit"
										class="join-item btn preset-filled-primary-500 px-4 text-sm shadow-sm !rounded-none !rounded-tr-lg !rounded-br-lg"
										disabled={acceptLinkSearching || !acceptLinkSearchName.trim()}
										data-testid="accept-link-search-button"
									>
										{acceptLinkSearching ? 'Searching…' : 'Search'}
									</button>
								</div>
								<label class="flex flex-col gap-1 text-xs">
									<span class="text-surface-700">Birth date (optional)</span>
									<input type="date" class="input rounded-container border border-surface-200 px-3 py-2 text-sm w-full" bind:value={acceptLinkSearchBirthDate} data-testid="accept-link-search-birth-date" />
								</label>
							</form>
							{#if acceptPossibleMatches.length > 0}
								<p class="text-xs text-surface-600">Existing clients</p>
								<ul class="space-y-1 mb-2">
									{#each acceptPossibleMatches as client (client.id)}
										<li>
											<button
												type="button"
												class="btn preset-tonal text-sm w-full text-left justify-start py-3 {acceptChosenClientId === client.id && !acceptCreateNew ? 'ring-2 ring-primary-500' : ''}"
												onclick={() => {
													acceptChosenClientId = client.id;
													acceptCreateNew = false;
													acceptVerifyFirstName = client.first_name ?? '';
													acceptVerifyLastName = client.last_name ?? '';
													acceptVerifyBirthDate = client.birth_date ?? '';
												}}
											>
												<div class="flex flex-col min-w-0">
													<span class="truncate">{clientDisplayName(client)}</span>
													<span class="text-xs text-surface-600">
														{client.birth_date ?? '—'}{#if client.mobile_masked} · {client.mobile_masked}{/if}
													</span>
												</div>
											</button>
										</li>
									{/each}
								</ul>
							{:else}
								<p class="text-xs text-surface-600">No matches found. Create a new client.</p>
							{/if}
							<button
								type="button"
								class="btn preset-tonal text-sm w-full justify-start {acceptCreateNew ? 'ring-2 ring-primary-500' : ''}"
								onclick={() => { acceptCreateNew = true; acceptChosenClientId = null; }}
							>
								Create new client
							</button>
						</div>
						{#if acceptRegModalReg?.mobile_masked}
							<div class="border-t border-surface-200 pt-3">
								<p class="text-xs font-medium text-surface-600 mb-2">Phone on file</p>
								<p class="text-sm text-surface-700">{acceptRegModalReg.mobile_masked}</p>
								<button
									type="button"
									class="btn preset-tonal text-sm mt-2"
									onclick={() => (showAcceptRevealModal = true)}
									data-testid="accept-reveal-phone-btn"
								>
									Reveal phone
								</button>
								{#if acceptRegModalReg?.id_verified_at}
									<p class="text-xs text-surface-600 mt-1">Verified{acceptRegModalReg?.id_verified_by ? ` by ${acceptRegModalReg.id_verified_by}` : ''}{acceptRegModalReg?.id_verified_at ? ` on ${new Date(acceptRegModalReg.id_verified_at).toLocaleString()}` : ''}.</p>
								{/if}
							</div>
						{/if}
						<div class="flex gap-2 pt-2">
							<button type="button" class="btn preset-tonal flex-1" onclick={closeAcceptModal}>Cancel</button>
							<button
								type="button"
								class="btn preset-filled-primary-500 flex-1"
								disabled={!acceptCanSubmit || acceptSubmitting}
								onclick={submitAccept}
								data-testid="accept-registration-submit"
							>
								{#if acceptSubmitting}
									Submitting…
								{:else if acceptSelectedClient}
									Verify existing client
								{:else if acceptCreateNew}
									Create new client and accept
								{:else}
									Submit registration
								{/if}
							</button>
						</div>
					</div>
				{/if}
			{/snippet}
		</Modal>

		<!-- Reveal phone (from Accept modal) -->
		<Modal
			open={showAcceptRevealModal}
			title="Reveal phone"
			onClose={() => { showAcceptRevealModal = false; acceptRevealResult = null; acceptRevealReason = ''; }}
		>
			{#snippet children()}
				{#if acceptRevealResult !== null}
					<div class="space-y-3">
						<p class="text-sm text-surface-700">Phone (for verification only):</p>
						<p class="font-mono text-lg font-medium text-surface-950">{acceptRevealResult}</p>
						<button type="button" class="btn preset-tonal w-full" onclick={() => { showAcceptRevealModal = false; acceptRevealResult = null; acceptRevealReason = ''; }}>Close</button>
					</div>
				{:else}
					<form class="space-y-3" onsubmit={(e) => { e.preventDefault(); submitAcceptReveal(); }}>
						<label class="block">
							<span class="text-sm font-medium text-surface-800">Reason (required)</span>
							<textarea
								class="input textarea w-full mt-1 rounded-container border border-surface-200 px-3 py-2 text-sm"
								rows="2"
								placeholder="e.g. Verification before accept"
								bind:value={acceptRevealReason}
								disabled={acceptRevealSubmitting}
							></textarea>
						</label>
						<div class="flex gap-2 justify-end">
							<button type="button" class="btn preset-tonal" onclick={() => { showAcceptRevealModal = false; acceptRevealReason = ''; }}>Cancel</button>
							<button type="submit" class="btn preset-filled-primary-500" disabled={!acceptRevealReason.trim() || acceptRevealSubmitting}>
								{acceptRevealSubmitting ? 'Revealing…' : 'Reveal'}
							</button>
						</div>
					</form>
				{/if}
			{/snippet}
		</Modal>
	</div>
</MobileLayout>
