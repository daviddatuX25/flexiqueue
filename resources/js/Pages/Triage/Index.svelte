<script lang="ts">
	import MobileLayout from '../../Layouts/MobileLayout.svelte';
	import Modal from '../../Components/Modal.svelte';
	import QrScanner from '../../Components/QrScanner.svelte';
	import TriageClientBinder, {
		type BindingMode as BinderBindingMode,
		type BinderStatus as BinderComponentStatus,
		type ClientBindingPayload,
	} from '../../Components/TriageClientBinder.svelte';
	import IdNumberInput from '../../Components/IdNumberInput.svelte';
	import { Camera, Plus, Search, Settings } from 'lucide-svelte';
	import { get } from 'svelte/store';
	import { onMount } from 'svelte';
	import { usePage, router } from '@inertiajs/svelte';
	import { toaster } from '../../lib/toaster.js';
	import { getLocalAllowHidOnThisDevice, setLocalAllowHidOnThisDevice, isMobileTouch } from '../../lib/displayHid.js';
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
		identity_binding_mode?: 'disabled' | 'optional' | 'required';
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
		id_types = [],
		pending_identity_registrations = [],
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
		id_types?: string[];
		pending_identity_registrations?: { id: number; name: string | null; birth_year: number | null; client_category: string | null; id_type: string | null; id_number_last4: string | null; id_verified_at: string | null; id_verified_by_user_id: number | null; id_verified_by: string | null; requested_at: string; session_id: number | null; session_alias: string | null }[];
	} = $props();

	const effectiveProgram = $derived(currentProgram ?? program ?? activeProgram);

	const CATEGORIES = [
		{ label: 'Regular', value: 'Regular' },
		{ label: 'PWD / Senior / Pregnant', value: 'PWD / Senior / Pregnant' },
		{ label: 'Incomplete Documents', value: 'Incomplete Documents' },
	] as const;

	/** When track count is at or below this, show buttons instead of dropdown. */
	const MAX_TRACKS_FOR_BUTTONS = 4;
	const showTrackButtons = $derived((effectiveProgram?.tracks?.length ?? 0) <= MAX_TRACKS_FOR_BUTTONS);

	let showScanner = $state(false);
	/** When 'verify_id', scan verifies stored ID; when 'capture_id_accept', scan fills optional ID in Accept modal; when 'capture_id_new_reg', scan fills ID in New registration modal; else token lookup. */
	let scannerMode = $state<'token' | 'verify_id' | 'capture_id_accept' | 'capture_id_new_reg'>('token');
	let scannedToken = $state<{ physical_id: string; qr_hash: string; status: string } | null>(null);
	/** Latch: ignore repeated onScan callbacks after first successful scan (stops flicker). */
	let scanHandled = $state(false);
	let manualPhysicalId = $state('');
	let barcodeValue = $state('');
	let barcodeInputEl = $state<HTMLInputElement | null>(null);
	let barcodeModalInputEl = $state<HTMLInputElement | null>(null);
	/** Per plan: category hidden in staff triage normal bind; default to Regular. */
	let selectedCategory = $state<string>('Regular');
	let selectedTrackId = $state<number | null>(null);
	let isSubmitting = $state(false);
	let scanCountdown = $state(0);
	let scanCountdownIntervalId = $state<ReturnType<typeof setInterval> | null>(null);
	/** Account-level preferences (from props, updated after PUT). */
	let accountAllowHid = $state(true);
	let accountAllowCamera = $state(true);
	/** Device-level (localStorage staff_binder); in sync with modal device toggles. */
	let localAllowHid = $state(true);
	let localAllowCamera = $state(true);
	const effectiveHid = $derived(accountAllowHid && localAllowHid);
	const effectiveCamera = $derived(accountAllowCamera && localAllowCamera);
	/** When true, user is in the "manual token input" focus window; pause HID refocus for 10s then return focus to hidden input. */
	let manualFocusActive = $state(false);
	let manualFocusTimeoutId = $state<ReturnType<typeof setTimeout> | null>(null);

	/** Staff triage settings modal (no PIN; staff manages own prefs). */
	let showTriageSettingsModal = $state(false);
	let triageSettingsSaving = $state(false);
	let triageSettingsAccountHid = $state(true);
	let triageSettingsAccountCamera = $state(true);
	let triageSettingsLocalHid = $state(true);
	let triageSettingsLocalCamera = $state(true);

	let bindingMode = $derived<BinderBindingMode>(
		(effectiveProgram?.identity_binding_mode as BinderBindingMode | undefined) ?? 'disabled',
	);
	let clientBinding = $state<ClientBindingPayload | null>(null);
	let binderStatus = $state<BinderComponentStatus>('idle');

	/** Identity registration accept modal */
	let acceptRegModalReg = $state<typeof pending_identity_registrations[0] | null>(null);
	/** Blocker modal: accepting a registration with unverified ID. */
	let showAcceptUnverifiedIdWarning = $state(false);
	let acceptVerifyName = $state('');
	let acceptVerifyBirthYear = $state('');
	let acceptVerifyCategory = $state('Regular');
	let showVerifyIdManual = $state(false);
	let verifyIdManualValue = $state('');
	let verifyIdManualSubmitting = $state(false);
	let acceptPossibleMatches = $state<{ id: number; name: string; birth_year: number | null; has_id_document?: boolean; id_documents_count?: number }[]>([]);
	let acceptLinkSearchName = $state('');
	let acceptLinkSearchBirthYear = $state('');
	let acceptLinkSearching = $state(false);
	let acceptChosenClientId = $state<number | null>(null);
	let acceptCreateNew = $state(false);
	let acceptRegisterIdType = $state('PhilHealth');
	let acceptRegisterIdNumber = $state('');
	let acceptSubmitting = $state(false);
	let highlightSessionId = $state<number | null>(null);

	/** Staff direct registration request (no token) */
	let showNewRegModal = $state(false);
	let newRegName = $state('');
	let newRegBirthYear = $state('');
	let newRegCategory = $state('Regular');
	let newRegIdType = $state('PhilHealth');
	let newRegIdNumber = $state('');
	let newRegSubmitting = $state(false);
	/** When true, show manual ID entry in New reg modal optional ID block (same format as public triage start). */
	let showNewRegManualId = $state(false);
	let newRegLinkSearchName = $state('');
	let newRegLinkSearchBirthYear = $state('');
	let newRegLinkSearching = $state(false);
	let newRegPossibleMatches = $state<
		{ id: number; name: string; birth_year: number | null; has_id_document?: boolean; id_documents_count?: number }[]
	>([]);
	let newRegChosenClientId = $state<number | null>(null);

	const newRegSelectedClient = $derived(
		newRegChosenClientId !== null
			? (newRegPossibleMatches.find((c) => c.id === newRegChosenClientId) ?? null)
			: null,
	);

	/** When true, show manual ID entry in Accept modal optional ID block (same format as public triage start). */
	let showAcceptRegManualId = $state(false);

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

	async function submitNewRegistrationRequest() {
		if (newRegSubmitting) return;

		// If staff selected an existing client, this modal becomes an "Attach ID" flow (no registration record).
		if (newRegChosenClientId !== null) {
			const idTypeStr = String(newRegIdType ?? '').trim();
			const idNumberStr = String(newRegIdNumber ?? '').trim();
			if (!idTypeStr || !idNumberStr) {
				toaster.error({ title: 'ID type and ID number are required to attach an ID.' });
				return;
			}
			newRegSubmitting = true;
			const { ok, data, message } = await api(
				'POST',
				`/api/clients/${newRegChosenClientId}/id-documents`,
				{
					id_type: idTypeStr,
					id_number: idNumberStr,
				},
			);
			newRegSubmitting = false;

			if (ok) {
				toaster.success({ title: (data as { message?: string })?.message ?? 'ID attached.' });
				showNewRegModal = false;
				newRegName = '';
				newRegBirthYear = '';
				newRegCategory = 'Regular';
				newRegIdType = (id_types?.length ? id_types[0] : 'PhilHealth');
				newRegIdNumber = '';
				showNewRegManualId = false;
				newRegLinkSearchName = '';
				newRegLinkSearchBirthYear = '';
				newRegLinkSearching = false;
				newRegPossibleMatches = [];
				newRegChosenClientId = null;
				router.reload();
				return;
			}

			toaster.error({
				title:
					(data as { message?: string })?.message ??
					(message as string) ??
					'Could not attach ID.',
			});
			return;
		}

		const nameStr = String(newRegName ?? '').trim();
		const birthYearStr = String(newRegBirthYear ?? '').trim();
		const birthYear = birthYearStr ? Number(birthYearStr) : NaN;
		if (!nameStr || !birthYearStr) {
			toaster.error({ title: 'Name and birth year are required to create a registration.' });
			return;
		}
		if (!Number.isFinite(birthYear) || birthYear < 1900 || birthYear > 2100) {
			toaster.error({ title: 'Enter a valid birth year (1900–2100).' });
			return;
		}
		newRegSubmitting = true;
		const idTypeStr = String(newRegIdType ?? '').trim();
		const idNumberStr = String(newRegIdNumber ?? '').trim();

		const body: Record<string, unknown> = {
			...(effectiveProgram?.id != null ? { program_id: effectiveProgram.id } : {}),
			name: nameStr,
			birth_year: birthYear,
			client_category: newRegCategory || 'Regular',
			...(idTypeStr ? { id_type: idTypeStr } : {}),
			...(idNumberStr ? { id_number: idNumberStr } : {}),
		};

		const { ok, data, message } = await api('POST', '/api/identity-registrations/direct', body);
		newRegSubmitting = false;

		if (ok) {
			const d = data as { message?: string } | undefined;
			toaster.success({ title: d?.message ?? 'Registration created.' });
			showNewRegModal = false;
			newRegName = '';
			newRegBirthYear = '';
			newRegCategory = 'Regular';
			newRegIdType = (id_types?.length ? id_types[0] : 'PhilHealth');
			newRegIdNumber = '';
			router.reload();
			return;
		}

		toaster.error({ title: (data as { message?: string })?.message ?? (message as string) ?? 'Could not create registration.' });
	}

	const NEW_REG_LINK_BIRTH_YEAR_MIN = 1900;
	const NEW_REG_LINK_BIRTH_YEAR_MAX = 2100;

	async function runNewRegLinkSearch(e?: SubmitEvent) {
		e?.preventDefault();
		const name = newRegLinkSearchName.trim();
		if (!name) return;
		newRegLinkSearching = true;
		try {
			const birthYearVal = newRegLinkSearchBirthYear.trim();
			const birthYearNum = birthYearVal ? Number(birthYearVal) : NaN;
			const birthYear =
				Number.isInteger(birthYearNum) &&
				birthYearNum >= NEW_REG_LINK_BIRTH_YEAR_MIN &&
				birthYearNum <= NEW_REG_LINK_BIRTH_YEAR_MAX
					? birthYearNum
					: null;
			const params = new URLSearchParams({
				name,
				per_page: '10',
				page: '1',
			});
			if (birthYear != null) params.set('birth_year', String(birthYear));

			const { ok, data } = await api('GET', `/api/clients/search?${params.toString()}`);
			if (ok) {
				const payload = data as {
					data?: { id: number; name: string; birth_year: number | null; has_id_document?: boolean; id_documents_count?: number }[];
				};
				newRegPossibleMatches = payload.data ?? [];
			} else {
				newRegPossibleMatches = [];
			}
		} finally {
			newRegLinkSearching = false;
		}
	}

	function setDefaultTrack() {
		if (effectiveProgram?.tracks?.length) {
			const def = effectiveProgram.tracks.find((t) => t.is_default);
			selectedTrackId = def?.id ?? effectiveProgram.tracks[0]?.id ?? null;
		}
	}

	$effect(() => {
		if (effectiveProgram?.tracks?.length && selectedTrackId === null) {
			setDefaultTrack();
		}
	});

	$effect(() => {
		accountAllowHid = staff_triage_allow_hid_barcode !== false;
		accountAllowCamera = staff_triage_allow_camera_scanner !== false;
	});

	onMount(() => {
		// Match displayHid.js: when not set, default true on desktop and false on mobile (avoids keyboard pop)
		const hidLocal = getLocalAllowHidOnThisDevice('staff_binder');
		localAllowHid = hidLocal !== null ? hidLocal : !isMobileTouch();
		localAllowCamera = shouldAllowCameraScanner('staff_binder');
	});

	/** Per plan: HID only in modal. When modal opens, an HID input inside the modal (first focusable) is focused by Modal's trap; fallback focus global input. */
	$effect(() => {
		if (!showScanner || !effectiveHid) return;
		queueMicrotask(() => {
			requestAnimationFrame(() => {
				barcodeModalInputEl?.focus();
				// Fallback: focus the global hidden input if modal ref isn't available.
				if (document.activeElement !== barcodeModalInputEl) {
					barcodeInputEl?.focus();
				}
			});
		});
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

		if (scannerMode === 'verify_id' && acceptRegModalReg) {
			const idNumber = raw.trim();
			const idType = acceptRegModalReg.id_type ?? '';
			api('POST', `/api/identity-registrations/${acceptRegModalReg.id}/verify-id`, { id_type: idType || undefined, id_number: idNumber }).then(({ ok, data, message }) => {
				if (ok && data && (data as { verified?: boolean }).verified) {
					const d = data as { id_verified_at?: string; id_verified_by_user_id?: number; id_verified_by?: string };
					acceptRegModalReg = acceptRegModalReg ? { ...acceptRegModalReg, id_verified_at: d.id_verified_at ?? null, id_verified_by_user_id: d.id_verified_by_user_id ?? null, id_verified_by: d.id_verified_by ?? null } : null;
					showScanner = false;
					scannerMode = 'token';
					toaster.success({ title: 'ID verified.' });
				} else {
					toaster.error({ title: (message as string) ?? (data as { message?: string })?.message ?? 'ID does not match.' });
					scanHandled = false;
				}
			});
			barcodeValue = '';
			return;
		}
		if (scannerMode === 'capture_id_accept') {
			acceptRegisterIdNumber = raw.trim();
			showAcceptRegManualId = true;
			showScanner = false;
			scannerMode = 'token';
			toaster.success({ title: 'ID number captured. You can edit and submit.' });
			barcodeValue = '';
			return;
		}
		if (scannerMode === 'capture_id_new_reg') {
			newRegIdNumber = raw.trim();
			showNewRegManualId = true;
			showScanner = false;
			scannerMode = 'token';
			toaster.success({ title: 'ID number captured. You can edit and submit.' });
			barcodeValue = '';
			return;
		}

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
		if (scannerMode === 'verify_id' && acceptRegModalReg) {
			scanHandled = true;
			const idNumber = decodedText.trim();
			const idType = acceptRegModalReg.id_type ?? '';
			const { ok, data, message } = await api('POST', `/api/identity-registrations/${acceptRegModalReg.id}/verify-id`, { id_type: idType || undefined, id_number: idNumber });
			if (ok && data && (data as { verified?: boolean }).verified) {
				const d = data as { id_verified_at?: string; id_verified_by_user_id?: number; id_verified_by?: string };
				acceptRegModalReg = acceptRegModalReg ? { ...acceptRegModalReg, id_verified_at: d.id_verified_at ?? null, id_verified_by_user_id: d.id_verified_by_user_id ?? null, id_verified_by: d.id_verified_by ?? null } : null;
				showScanner = false;
				scannerMode = 'token';
				toaster.success({ title: 'ID verified.' });
			} else {
				toaster.error({ title: (message as string) ?? (data as { message?: string })?.message ?? 'ID does not match.' });
				scanHandled = false;
			}
			return;
		}
		if (scannerMode === 'capture_id_accept') {
			scanHandled = true;
			acceptRegisterIdNumber = decodedText.trim();
			showAcceptRegManualId = true;
			showScanner = false;
			scannerMode = 'token';
			toaster.success({ title: 'ID number captured. You can edit and submit.' });
			return;
		}
		if (scannerMode === 'capture_id_new_reg') {
			scanHandled = true;
			newRegIdNumber = decodedText.trim();
			showNewRegManualId = true;
			showScanner = false;
			scannerMode = 'token';
			toaster.success({ title: 'ID number captured. You can edit and submit.' });
			return;
		}
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
		selectedCategory = 'Regular';
		setDefaultTrack();
		showScanner = false;
		scannerMode = 'token';
	}

	function openTriageSettingsModal() {
		triageSettingsAccountHid = accountAllowHid;
		triageSettingsAccountCamera = accountAllowCamera;
		triageSettingsLocalHid = localAllowHid;
		triageSettingsLocalCamera = localAllowCamera;
		showTriageSettingsModal = true;
	}

	async function saveTriageSettings() {
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
				showTriageSettingsModal = false;
			} else {
				toaster.error({ title: (data as { message?: string }).message ?? 'Failed to save.' });
			}
		} catch (e) {
			toaster.error({ title: MSG_NETWORK_ERROR });
		} finally {
			triageSettingsSaving = false;
		}
	}

	/** Per ISSUES-ELABORATION §12: clear error and allow scan/lookup again without refresh (e.g. after token freed elsewhere). */
	function tryAgain() {
		scanHandled = false;
	}

	async function handleConfirm() {
		if (!scannedToken || selectedTrackId === null) return;
		if (bindingMode === 'required' && binderStatus !== 'bound') {
			toaster.error({
				title: 'Client identity binding is required before completing triage.',
			});
			return;
		}
		isSubmitting = true;
		const payload: any = {
			qr_hash: scannedToken.qr_hash,
			track_id: Number(selectedTrackId),
			client_category: selectedCategory,
		};
		if (effectiveProgram?.id != null) {
			payload.program_id = effectiveProgram.id;
		}
		if (clientBinding) {
			payload.client_binding = clientBinding;
		}

		const { ok, data, message } = await api('POST', '/api/sessions/bind', payload);
		isSubmitting = false;
		if (ok) {
			resetScan();
			// Could show toast here
			return;
		}
		const d = data as { active_session?: { alias: string }; token_status?: string } | undefined;
		if (d?.active_session) {
			toaster.error({ title: `Token already in use (${d.active_session.alias}).` });
		} else if (d?.token_status) {
			toaster.error({ title: `Token is marked as ${d.token_status}.` });
		} else {
			toaster.error({ title: message ?? 'Bind failed.' });
		}
	}

	// Identity registration accept/reject
	const ACCEPT_CATEGORIES = [
		{ label: 'Regular', value: 'Regular' },
		{ label: 'PWD / Senior / Pregnant', value: 'PWD / Senior / Pregnant' },
	] as const;

	function openAcceptModal(reg: (typeof pending_identity_registrations)[0]) {
		acceptRegModalReg = reg;
		showVerifyIdManual = false;
		verifyIdManualValue = '';
		verifyIdManualSubmitting = false;
		acceptVerifyName = reg.name ?? '';
		acceptVerifyBirthYear = reg.birth_year != null ? String(reg.birth_year) : '';
		acceptVerifyCategory = reg.client_category ?? 'Regular';
		acceptPossibleMatches = [];
		acceptLinkSearchName = reg.name?.trim() ?? '';
		acceptLinkSearchBirthYear = reg.birth_year != null ? String(reg.birth_year) : '';
		acceptChosenClientId = null;
		acceptCreateNew = false;
		acceptRegisterIdType = (id_types?.length ? id_types[0] : 'PhilHealth');
		acceptRegisterIdNumber = '';
		fetchPossibleMatches(reg.id);
	}

	function closeAcceptModal() {
		acceptRegModalReg = null;
		showAcceptUnverifiedIdWarning = false;
	}

	async function submitVerifyIdManual() {
		if (!acceptRegModalReg || verifyIdManualSubmitting) return;
		const idNumber = verifyIdManualValue.trim();
		if (!idNumber) return;
		verifyIdManualSubmitting = true;
		try {
			const idType = acceptRegModalReg.id_type ?? '';
			const { ok, data, message } = await api('POST', `/api/identity-registrations/${acceptRegModalReg.id}/verify-id`, { id_type: idType || undefined, id_number: idNumber });
			if (ok && data && (data as { verified?: boolean }).verified) {
				const d = data as { id_verified_at?: string; id_verified_by_user_id?: number; id_verified_by?: string };
				acceptRegModalReg = acceptRegModalReg ? { ...acceptRegModalReg, id_verified_at: d.id_verified_at ?? null, id_verified_by_user_id: d.id_verified_by_user_id ?? null, id_verified_by: d.id_verified_by ?? null } : null;
				showVerifyIdManual = false;
				verifyIdManualValue = '';
				toaster.success({ title: 'ID verified.' });
			} else {
				toaster.error({ title: (message as string) ?? (data as { message?: string })?.message ?? 'ID does not match.' });
			}
		} finally {
			verifyIdManualSubmitting = false;
		}
	}

	async function fetchPossibleMatches(regId: number) {
		const { ok, data } = await api('GET', `/api/identity-registrations/${regId}/possible-matches`);
		if (ok && data && Array.isArray((data as { data?: unknown }).data)) {
			acceptPossibleMatches = (data as { data: { id: number; name: string; birth_year: number | null; has_id_document?: boolean; id_documents_count?: number }[] }).data;
		}
	}

	const ACCEPT_LINK_BIRTH_YEAR_MIN = 1900;
	const ACCEPT_LINK_BIRTH_YEAR_MAX = 2100;

	async function runAcceptLinkSearch(e?: SubmitEvent) {
		e?.preventDefault();
		const name = acceptLinkSearchName.trim();
		if (!name) return;
		acceptLinkSearching = true;
		try {
			const birthYearVal = acceptLinkSearchBirthYear.trim();
			const birthYearNum = birthYearVal ? Number(birthYearVal) : NaN;
			const birthYear =
				Number.isInteger(birthYearNum) && birthYearNum >= ACCEPT_LINK_BIRTH_YEAR_MIN && birthYearNum <= ACCEPT_LINK_BIRTH_YEAR_MAX
					? birthYearNum
					: null;
			const params = new URLSearchParams({ name, per_page: '10', page: '1' });
			if (birthYear != null) params.set('birth_year', String(birthYear));
			const { ok, data } = await api('GET', `/api/clients/search?${params.toString()}`);
			if (ok && data) {
				const payload = data as { data?: { id: number; name: string; birth_year: number | null; has_id_document?: boolean; id_documents_count?: number }[] };
				acceptPossibleMatches = payload.data ?? [];
			} else {
				acceptPossibleMatches = [];
			}
		} finally {
			acceptLinkSearching = false;
		}
	}

	const acceptCanSubmit = $derived(
		acceptVerifyName.trim() !== '' &&
		acceptVerifyBirthYear.trim() !== '' &&
		Number(acceptVerifyBirthYear) >= 1900 &&
		Number(acceptVerifyBirthYear) <= 2100 &&
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
			name: acceptVerifyName.trim(),
			birth_year: Number(acceptVerifyBirthYear),
			client_category: acceptVerifyCategory,
			create_new_client: acceptCreateNew,
		};
		if (!acceptCreateNew && acceptChosenClientId) body.client_id = acceptChosenClientId;
		if (acceptRegisterIdNumber.trim()) {
			body.register_id = { id_type: acceptRegisterIdType, id_number: acceptRegisterIdNumber.trim() };
		}
		const { ok, message } = await api('POST', `/api/identity-registrations/${acceptRegModalReg.id}/accept`, body);
		acceptSubmitting = false;
		if (ok) {
			toaster.success({ title: 'Registration accepted.' });
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
										{reg.name ?? '—'}
										{#if reg.id_verified_at}
											<span class="badge badge-sm badge-filled-primary-500" data-testid="identity-registration-verified-badge">Verified</span>
										{/if}
									</p>
									<p class="text-xs text-surface-600">
										Birth year: {reg.birth_year ?? '—'} · Category: {reg.client_category ?? '—'}
										{#if reg.id_type || reg.id_number_last4}
											· ID: {reg.id_type ?? '—'}{#if reg.id_number_last4} …{reg.id_number_last4}{/if}
										{/if}
										{#if reg.session_alias}
											· Session: {reg.session_alias}
										{/if}
									</p>
								</div>
								<div class="flex gap-2 shrink-0">
									<button
										type="button"
										class="btn preset-filled-primary-500 text-sm touch-target-h"
										data-testid="identity-registration-verify-{reg.id}"
										onclick={() => openAcceptModal(reg)}
									>
										Verify
									</button>
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

			<Modal open={showScanner} title={scannerMode === 'verify_id' ? 'Scan ID to verify' : scannerMode === 'capture_id_accept' || scannerMode === 'capture_id_new_reg' ? 'Scan ID to capture number' : 'Scan QR via device'} onClose={closeScanner} wide={true}>
				{#snippet children()}
					<div class="flex flex-col gap-3 w-full min-w-[20rem] mx-auto">
						{#if effectiveHid}
							<!-- First focusable so Modal focus trap focuses it; same value/handler as global input. -->
							<input
								type="text"
								autocomplete="off"
								inputmode="none"
								aria-label="Barcode scanner input"
								class="sr-only"
								bind:value={barcodeValue}
								bind:this={barcodeModalInputEl}
								onkeydown={onBarcodeKeydown}
							/>
						{/if}
						{#if effectiveCamera}
							<QrScanner active={showScanner} cameraOnly={true} onScan={handleQrScan} />
						{/if}
						{#if scanCountdown > 0}
							<div class="flex flex-wrap items-center justify-center gap-2">
								<p class="text-sm text-surface-600" aria-live="polite">Closing in {scanCountdown}s</p>
								<button type="button" class="btn preset-tonal text-sm py-1.5 px-3" onclick={extendScannerCountdown}>
									Extend (+{Math.max(0, Number(display_scan_timeout_seconds) || 20)}s)
								</button>
							</div>
						{/if}
						{#if effectiveHid}
							<p class="text-sm text-surface-600 rounded-container border border-surface-200 bg-surface-50 px-3 py-2" aria-live="polite">HID scanner turned on, waiting for scan.</p>
						{/if}
						<button type="button" class="btn preset-tonal w-full py-3" onclick={closeScanner}>Cancel</button>
					</div>
				{/snippet}
			</Modal>

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

				<Modal open={showTriageSettingsModal} title="Triage settings" onClose={() => (showTriageSettingsModal = false)}>
					{#snippet children()}
						<div class="flex flex-col gap-6">
							<p class="text-sm text-surface-950/70">HID and camera scanner preferences for this account and this device.</p>
							<div class="flex flex-col gap-4">
								<h3 class="text-sm font-semibold text-surface-950">On this account</h3>
								<p class="text-xs text-surface-950/60">Saved to your account; applies on any device when you are logged in.</p>
								<label class="flex items-center gap-2 cursor-pointer">
									<input
										type="checkbox"
										class="checkbox"
										bind:checked={triageSettingsAccountHid}
										disabled={triageSettingsSaving}
									/>
									<span class="text-sm text-surface-950">Allow HID barcode scanner</span>
								</label>
								<label class="flex items-center gap-2 cursor-pointer">
									<input
										type="checkbox"
										class="checkbox"
										bind:checked={triageSettingsAccountCamera}
										disabled={triageSettingsSaving}
									/>
									<span class="text-sm text-surface-950">Allow camera/QR scanner</span>
								</label>
							</div>
							<div class="border-t border-surface-200 pt-4 flex flex-col gap-2">
								<h3 class="text-sm font-semibold text-surface-950">On this device</h3>
								<p class="text-xs text-surface-950/60">Stored on this device only — not saved to server.</p>
								<label class="flex items-center gap-2 cursor-pointer">
									<input
										type="checkbox"
										class="checkbox"
										bind:checked={triageSettingsLocalHid}
										onchange={() => {
											setLocalAllowHidOnThisDevice('staff_binder', triageSettingsLocalHid);
											localAllowHid = triageSettingsLocalHid;
										}}
									/>
									<span class="text-sm text-surface-950">Allow HID scanner on this device</span>
								</label>
								<label class="flex items-center gap-2 cursor-pointer">
									<input
										type="checkbox"
										class="checkbox"
										bind:checked={triageSettingsLocalCamera}
										onchange={() => {
											setLocalAllowCameraOnThisDevice('staff_binder', triageSettingsLocalCamera);
											localAllowCamera = triageSettingsLocalCamera;
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

				<Modal open={showNewRegModal} title="New client registration" onClose={() => (showNewRegModal = false)}>
					{#snippet children()}
						<div class="space-y-4" data-testid="triage-new-registration-modal">
							<p class="text-sm text-surface-700">
								Create a client registration directly. The registration is created immediately; no Accept/Reject step. Use when a client has no token yet and you are entering their details.
							</p>
							{#if newRegSelectedClient}
								<div class="rounded-container border border-surface-200 bg-surface-50 p-3 space-y-1">
									<p class="text-xs font-medium text-surface-600">Selected client</p>
									<p class="text-sm text-surface-950 font-medium">{newRegSelectedClient.name}</p>
									<p class="text-xs text-surface-600">
										Birth year: {newRegSelectedClient.birth_year ?? '—'} · ID docs: {newRegSelectedClient.id_documents_count ?? (newRegSelectedClient.has_id_document ? 1 : 0)}
									</p>
								</div>
							{:else}
								<label class="flex flex-col gap-1 text-sm">
									<span class="font-medium text-surface-800">Name (required)</span>
									<input
										type="text"
										class="input rounded-container border border-surface-200 px-3 py-2"
										placeholder="Full name"
										bind:value={newRegName}
										disabled={newRegSubmitting}
									/>
								</label>
								<label class="flex flex-col gap-1 text-sm">
									<span class="font-medium text-surface-800">Birth year (required)</span>
									<input
										type="number"
										class="input rounded-container border border-surface-200 px-3 py-2"
										placeholder="e.g. 1985"
										min="1900"
										max="2100"
										bind:value={newRegBirthYear}
										disabled={newRegSubmitting}
									/>
								</label>
								<label class="flex flex-col gap-1 text-sm">
									<span class="font-medium text-surface-800">Classification</span>
									<select
										class="select select-theme input rounded-container border border-surface-200 px-3 py-2"
										bind:value={newRegCategory}
										disabled={newRegSubmitting}
									>
										{#each ACCEPT_CATEGORIES as cat (cat.value)}
											<option value={cat.value}>{cat.label}</option>
										{/each}
									</select>
								</label>
							{/if}

							<div class="rounded-container border border-surface-200 bg-surface-50 p-3 space-y-3">
								<p class="text-sm font-medium text-surface-800">
									{newRegSelectedClient ? 'Attach ID to client' : 'Or attach ID to an existing client'}
								</p>
								<form class="space-y-2" onsubmit={runNewRegLinkSearch}>
									<label for="new-reg-link-search-name" class="label-text text-xs font-semibold uppercase tracking-wide text-surface-500 block">Search by name</label>
									<div class="join w-full">
										<div class="join-item flex items-center gap-2 px-3 py-1 border border-surface-300 rounded-l-container bg-surface-50 w-full">
											<Search class="w-4 h-4 my-2 text-surface-400 shrink-0" />
											<input
												type="text"
												id="new-reg-link-search-name"
												class="input input-ghost !bg-transparent px-0 py-0 h-auto text-sm w-full focus:!outline-none focus:!ring-0 focus:!border-transparent"
												bind:value={newRegLinkSearchName}
												placeholder="e.g. Maria Santos"
												data-testid="new-reg-link-search-name"
												disabled={newRegSubmitting}
											/>
										</div>
										<button
											type="submit"
											class="join-item btn preset-filled-primary-500 px-4 text-sm shadow-sm !rounded-none !rounded-tr-lg !rounded-br-lg"
											disabled={newRegLinkSearching || !newRegLinkSearchName.trim() || newRegSubmitting}
											data-testid="new-reg-link-search-button"
										>
											{newRegLinkSearching ? 'Searching…' : 'Search'}
										</button>
									</div>
									<label class="flex flex-col gap-1 text-xs">
										<span class="text-surface-700">Birth year (optional)</span>
										<input
											type="number"
											class="input rounded-container border border-surface-200 px-3 py-2 text-sm w-full"
											bind:value={newRegLinkSearchBirthYear}
											placeholder="e.g. 1985"
											min={NEW_REG_LINK_BIRTH_YEAR_MIN}
											max={NEW_REG_LINK_BIRTH_YEAR_MAX}
											data-testid="new-reg-link-search-birth-year"
											disabled={newRegSubmitting}
										/>
									</label>
								</form>

								{#if newRegPossibleMatches.length > 0}
									<p class="text-xs text-surface-600">Existing clients</p>
									<ul class="space-y-1 mb-2">
										{#each newRegPossibleMatches as client (client.id)}
											<li>
												<button
													type="button"
													class="btn preset-tonal text-sm w-full text-left justify-start py-3 {newRegChosenClientId === client.id ? 'ring-2 ring-primary-500' : ''}"
													onclick={() => {
														newRegChosenClientId = client.id;
														showNewRegManualId = true;
													}}
													disabled={newRegSubmitting}
												>
													<div class="flex flex-col min-w-0">
														<span class="truncate">{client.name}</span>
														<span class="text-xs text-surface-600">
															{client.birth_year ?? '—'} · ID docs: {client.id_documents_count ?? (client.has_id_document ? 1 : 0)}
														</span>
													</div>
												</button>
											</li>
										{/each}
									</ul>
								{:else if newRegLinkSearchName.trim() !== '' && !newRegLinkSearching}
									<p class="text-xs text-surface-600">No matches found.</p>
								{/if}

								<button
									type="button"
									class="btn preset-tonal text-sm w-full justify-start {newRegChosenClientId === null ? 'ring-2 ring-primary-500' : ''}"
									onclick={() => {
										newRegChosenClientId = null;
									}}
									disabled={newRegSubmitting}
								>
									Create new client
								</button>
							</div>
							<div class="border-t border-surface-200 pt-4 space-y-3">
								<div>
									<p class="text-sm font-medium text-surface-800">Optional ID details</p>
									<p class="text-xs text-surface-600 mt-0.5">Used to verify and re-use this ID next time.</p>
								</div>
								<div class="space-y-2">
									{#if effectiveCamera && !showNewRegManualId}
										<button
											type="button"
											class="btn preset-filled-primary-500 w-full text-sm touch-target-h flex items-center justify-center gap-2"
											data-testid="new-reg-scan-id-button"
											onclick={() => { scannerMode = 'capture_id_new_reg'; scanHandled = false; showScanner = true; }}
											disabled={newRegSubmitting}
										>
											<Camera class="w-4 h-4 shrink-0" />
											Scan ID to capture number
										</button>
									{/if}
									{#if showNewRegManualId}
										<div
											class="rounded-container border border-surface-200 bg-surface-50 p-3 space-y-3"
											data-testid="new-reg-manual-id-entry-group"
										>
											<div class="flex items-center justify-between gap-2">
												<span class="text-sm font-medium text-surface-700">Enter ID manually</span>
												{#if effectiveCamera}
													<button
														type="button"
														class="btn btn-sm preset-tonal"
														onclick={() => (showNewRegManualId = false)}
													>
														Use scanner
													</button>
												{/if}
											</div>
											<div class="space-y-1">
												<span class="text-sm text-surface-700">ID number</span>
												<IdNumberInput
													bind:value={newRegIdNumber}
													placeholder="Optional or scan below"
													disabled={newRegSubmitting}
													showMaskToggle={true}
													showScanButton={false}
													scanButtonFullWidth={true}
													testId="new-reg-id-number"
												/>
											</div>
											{#if (id_types ?? []).length}
												<label class="flex flex-col gap-1 text-sm">
													<span class="text-surface-700">ID type</span>
													<select
														class="select select-theme input rounded-container border border-surface-200 px-3 py-2"
														bind:value={newRegIdType}
														disabled={newRegSubmitting}
														aria-label="ID type"
													>
														{#each (id_types ?? []) as type (type)}
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
											data-testid="new-reg-enter-manually-button"
											onclick={() => (showNewRegManualId = true)}
											disabled={newRegSubmitting}
										>
											Enter ID manually
										</button>
									{/if}
								</div>
							</div>
							<div class="flex gap-2 pt-2">
								<button type="button" class="btn preset-tonal flex-1" onclick={() => (showNewRegModal = false)} disabled={newRegSubmitting}>
									Cancel
								</button>
								<button
									type="button"
									class="btn preset-filled-primary-500 flex-1"
									onclick={submitNewRegistrationRequest}
									disabled={
										newRegSubmitting ||
										(newRegChosenClientId !== null &&
											(String(newRegIdType ?? '').trim() === '' || String(newRegIdNumber ?? '').trim() === ''))
									}
									data-testid="triage-new-registration-submit"
								>
									{#if newRegSubmitting}
										{newRegChosenClientId !== null ? 'Attaching…' : 'Creating…'}
									{:else}
										{newRegChosenClientId !== null ? 'Attach ID to client' : 'Create registration'}
									{/if}
								</button>
							</div>
						</div>
					{/snippet}
				</Modal>
			{:else}
				<!-- Category + track + binder + confirm -->
				<div
					class="rounded-container border border-surface-200 bg-surface-50 elevation-card p-4 md:p-6 space-y-4"
					data-testid="triage-confirm-card"
				>
					<p class="font-medium text-surface-950">Token: <span class="font-mono text-primary-500">{scannedToken.physical_id}</span></p>

					<div>
						<p class="text-sm font-medium text-surface-950 mb-2">Track</p>
						{#if showTrackButtons}
							<div class="flex flex-wrap gap-2">
								{#each effectiveProgram?.tracks ?? [] as track (track.id)}
									<button
										type="button"
										class="btn touch-target-h px-4 py-2 {selectedTrackId === track.id ? 'preset-filled-primary-500' : 'preset-tonal'}"
										data-testid={track.name === 'Regular'
											? 'triage-track-regular'
											: 'triage-track-priority'}
										onclick={() => (selectedTrackId = track.id)}
									>
										{track.name}
									</button>
								{/each}
							</div>
						{:else}
<select
							id="triage-track"
							class="select select-theme w-full rounded-container border border-surface-200 px-3 py-2 touch-target-h"
								bind:value={selectedTrackId}
							>
								{#each effectiveProgram?.tracks ?? [] as track (track.id)}
									<option value={track.id}>{track.name}</option>
								{/each}
							</select>
						{/if}
					</div>

					{#if bindingMode !== 'disabled'}
						<div class="border-t border-surface-200 pt-4" data-testid="triage-client-binder-wrapper">
							<TriageClientBinder
								bindingMode={bindingMode}
								allowHid={effectiveHid}
								allowCamera={effectiveCamera}
								id_types={id_types}
								onBindingChange={({ status, client_binding }) => {
									binderStatus = status;
									clientBinding = client_binding;
								}}
							/>
						</div>
					{/if}

					<div class="flex gap-2 pt-2">
						<button type="button" class="btn preset-tonal flex-1 touch-target-h" onclick={resetScan} disabled={isSubmitting}>
							Cancel
						</button>
						<button
							type="button"
							class="btn preset-filled-primary-500 flex-1 touch-target-h"
							data-testid="triage-confirm-button"
							onclick={handleConfirm}
							disabled={
								isSubmitting ||
								selectedTrackId === null ||
								(bindingMode === 'required' && binderStatus !== 'bound')
							}
						>
							{isSubmitting ? 'Binding…' : 'Confirm'}
						</button>
					</div>
				</div>
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
							Verify details, then choose whether to link to an existing client or create a new one.
						</p>
						{#if acceptRegModalReg.id_type || acceptRegModalReg.id_number_last4}
							<p class="text-xs text-surface-600">ID attempted: {acceptRegModalReg.id_type ?? '—'}{#if acceptRegModalReg.id_number_last4} …{acceptRegModalReg.id_number_last4}{/if}</p>
						{/if}
						{#if acceptSelectedClient}
							<div class="rounded-container border border-surface-200 bg-surface-50 p-3 space-y-1">
								<p class="text-xs font-medium text-surface-600">Selected client</p>
								<p class="text-sm text-surface-950 font-medium">{acceptSelectedClient.name}</p>
								<p class="text-xs text-surface-600">
									Birth year: {acceptSelectedClient.birth_year ?? '—'} · ID documents: {acceptSelectedClient.id_documents_count ?? (acceptSelectedClient.has_id_document ? 1 : 0)}
								</p>
								<p class="text-xs text-surface-600">Classification: {acceptVerifyCategory}</p>
							</div>
						{:else}
							<label class="flex flex-col gap-1 text-sm">
								<span class="font-medium text-surface-800">Name (required)</span>
								<input
									type="text"
									class="input rounded-container border border-surface-200 px-3 py-2"
									bind:value={acceptVerifyName}
									placeholder="Full name"
								/>
							</label>
							<label class="flex flex-col gap-1 text-sm">
								<span class="font-medium text-surface-800">Birth year (required)</span>
								<input
									type="number"
									class="input rounded-container border border-surface-200 px-3 py-2"
									bind:value={acceptVerifyBirthYear}
									placeholder="e.g. 1985"
									min="1900"
									max="2100"
								/>
							</label>
							<label class="flex flex-col gap-1 text-sm">
								<span class="font-medium text-surface-800">Classification (required)</span>
								<select class="select select-theme input rounded-container border border-surface-200 px-3 py-2" bind:value={acceptVerifyCategory}>
									{#each ACCEPT_CATEGORIES as cat (cat.value)}
										<option value={cat.value}>{cat.label}</option>
									{/each}
								</select>
							</label>
						{/if}
						<div class="rounded-container border border-surface-200 bg-surface-50 p-3 space-y-3">
							<p class="text-sm font-medium text-surface-800">Link to client</p>
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
									<span class="text-surface-700">Birth year (optional)</span>
									<input
										type="number"
										class="input rounded-container border border-surface-200 px-3 py-2 text-sm w-full"
										bind:value={acceptLinkSearchBirthYear}
										placeholder="e.g. 1985"
										min={ACCEPT_LINK_BIRTH_YEAR_MIN}
										max={ACCEPT_LINK_BIRTH_YEAR_MAX}
										data-testid="accept-link-search-birth-year"
									/>
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
													acceptVerifyName = client.name ?? '';
													acceptVerifyBirthYear = client.birth_year != null ? String(client.birth_year) : '';
												}}
											>
												<div class="flex flex-col min-w-0">
													<span class="truncate">{client.name}</span>
													<span class="text-xs text-surface-600">
														{client.birth_year ?? '—'} · ID docs: {client.id_documents_count ?? (client.has_id_document ? 1 : 0)}
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
						{#if acceptRegModalReg?.id_type || acceptRegModalReg?.id_number_last4}
							<div class="border-t border-surface-200 pt-3">
								<p class="text-xs font-medium text-surface-600 mb-2">ID verification</p>
								{#if acceptRegModalReg?.id_verified_at}
									<p class="text-sm text-surface-700">Verified{acceptRegModalReg?.id_verified_by ? ` by ${acceptRegModalReg.id_verified_by}` : ''}{acceptRegModalReg?.id_verified_at ? ` on ${new Date(acceptRegModalReg.id_verified_at).toLocaleString()}` : ''}.</p>
								{:else}
									<p class="text-sm text-surface-600 mb-2">Not verified yet. Scan the physical ID to verify it matches.</p>
									<button
										type="button"
										class="btn preset-filled-primary-500 text-sm touch-target-h flex items-center justify-center gap-2 w-full"
										data-testid="verify-id-button"
										onclick={() => { scannerMode = 'verify_id'; scanHandled = false; showScanner = true; }}
									>
										<Camera class="w-4 h-4 shrink-0" />
										Verify ID
									</button>
									{#if showVerifyIdManual}
										<div class="rounded-container border border-surface-200 bg-surface-100 p-3 space-y-3 mt-3" data-testid="verify-id-manual-entry-group">
											<div class="flex items-center justify-between gap-2">
												<span class="text-sm font-medium text-surface-700">Enter ID manually</span>
												<button type="button" class="btn btn-sm preset-tonal" onclick={() => (showVerifyIdManual = false)} disabled={verifyIdManualSubmitting}>
													Use scanner
												</button>
											</div>
											<div class="space-y-1">
												<span class="text-sm text-surface-700">ID number</span>
												<IdNumberInput
													bind:value={verifyIdManualValue}
													placeholder="Enter ID number"
													disabled={verifyIdManualSubmitting}
													showMaskToggle={true}
													showScanButton={false}
													testId="verify-id-manual-number"
													onKeydown={(e) => {
														if (e.key === 'Enter') {
															e.preventDefault();
															submitVerifyIdManual();
														}
													}}
												/>
											</div>
											<button
												type="button"
												class="btn preset-filled-primary-500 w-full text-sm touch-target-h"
												onclick={submitVerifyIdManual}
												disabled={!verifyIdManualValue.trim() || verifyIdManualSubmitting}
											>
												{verifyIdManualSubmitting ? 'Verifying…' : 'Verify'}
											</button>
										</div>
									{:else}
										<button
											type="button"
											class="btn preset-tonal w-full touch-target-h mt-3"
											data-testid="verify-id-enter-manually-button"
											onclick={() => (showVerifyIdManual = true)}
										>
											Enter ID manually
										</button>
									{/if}
								{/if}
							</div>
						{:else}
							<div class="rounded-container border border-surface-200 bg-surface-50 p-3 space-y-3">
								<div>
									<p class="text-sm font-medium text-surface-800">Optional: register an ID for this client</p>
									<p class="text-xs text-surface-600 mt-0.5">Used to verify and re-use this ID next time.</p>
								</div>
								<div class="space-y-2">
									{#if effectiveCamera && !showAcceptRegManualId}
										<button
											type="button"
											class="btn preset-filled-primary-500 w-full text-sm touch-target-h flex items-center justify-center gap-2"
											data-testid="accept-reg-scan-id-button"
											onclick={() => { scannerMode = 'capture_id_accept'; scanHandled = false; showScanner = true; }}
										>
											<Camera class="w-4 h-4 shrink-0" />
											Scan ID to capture number
										</button>
									{/if}
									{#if showAcceptRegManualId}
										<div
											class="rounded-container border border-surface-200 bg-surface-100 p-3 space-y-3"
											data-testid="accept-reg-manual-id-entry-group"
										>
											<div class="flex items-center justify-between gap-2">
												<span class="text-sm font-medium text-surface-700">Enter ID manually</span>
												{#if effectiveCamera}
													<button
														type="button"
														class="btn btn-sm preset-tonal"
														onclick={() => (showAcceptRegManualId = false)}
													>
														Use scanner
													</button>
												{/if}
											</div>
											<div class="space-y-1">
												<span class="text-sm text-surface-700">ID number</span>
												<IdNumberInput
													bind:value={acceptRegisterIdNumber}
													placeholder="Optional or scan below"
													showMaskToggle={true}
													showScanButton={false}
													scanButtonFullWidth={true}
													testId="accept-id-number"
												/>
											</div>
											{#if (id_types ?? []).length}
												<label class="flex flex-col gap-1 text-sm">
													<span class="text-surface-700">ID type</span>
													<select class="select select-theme input rounded-container border border-surface-200 px-3 py-2" bind:value={acceptRegisterIdType} aria-label="ID type">
														{#each (id_types ?? []) as type (type)}
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
											data-testid="accept-reg-enter-manually-button"
											onclick={() => (showAcceptRegManualId = true)}
										>
											Enter ID manually
										</button>
									{/if}
								</div>
							</div>
						{/if}
						<div class="flex gap-2 pt-2">
							<button type="button" class="btn preset-tonal flex-1" onclick={closeAcceptModal}>Cancel</button>
							<button
								type="button"
								class="btn preset-filled-primary-500 flex-1"
								disabled={!acceptCanSubmit || acceptSubmitting}
								onclick={submitAccept}
							>
								{#if acceptSubmitting}
									Submitting…
								{:else if acceptSelectedClient && acceptRegModalReg?.id_verified_at}
									Attach ID to client
								{:else}
									Submit registration
								{/if}
							</button>
						</div>
					</div>
				{/if}
			{/snippet}
		</Modal>

		<Modal
			open={showAcceptUnverifiedIdWarning}
			title="Verify ID before accepting"
			onClose={() => (showAcceptUnverifiedIdWarning = false)}
		>
			{#snippet children()}
				<div class="space-y-4" data-testid="accept-registration-unverified-id-warning-modal">
					<p class="text-sm text-surface-700">
						This registration includes an ID, but it hasn’t been verified yet. To avoid mismatches, please scan the physical ID to verify it matches before accepting.
					</p>
					<div class="flex flex-wrap gap-2 justify-end pt-2">
						<button
							type="button"
							class="btn preset-tonal"
							data-testid="accept-registration-unverified-id-warning-cancel"
							onclick={() => (showAcceptUnverifiedIdWarning = false)}
							disabled={acceptSubmitting}
						>
							Cancel
						</button>
						<button
							type="button"
							class="btn preset-filled-primary-500"
							data-testid="accept-registration-unverified-id-warning-verify"
							onclick={() => {
								showAcceptUnverifiedIdWarning = false;
								if (acceptRegModalReg) {
									scannerMode = 'verify_id';
									scanHandled = false;
									showScanner = true;
								}
							}}
							disabled={acceptSubmitting || acceptRegModalReg == null}
						>
							Verify ID now
						</button>
					</div>
				</div>
			{/snippet}
		</Modal>
	</div>
</MobileLayout>
