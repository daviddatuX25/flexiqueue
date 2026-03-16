<script lang="ts">
	/**
	 * Public self-serve triage: scan token, choose track, bind. No auth.
	 * Per plan: GET /public-triage; when program allow_public_triage is true, clients can start a visit.
	 */
	import { get } from 'svelte/store';
	import { usePage } from '@inertiajs/svelte';
	import { router } from '@inertiajs/svelte';
import DisplayLayout from '../../Layouts/DisplayLayout.svelte';
import Modal from '../../Components/Modal.svelte';
import ScanModal from '../../Components/ScanModal.svelte';
import CountdownTimer from '../../Components/CountdownTimer.svelte';
import AuthChoiceButtons from '../../Components/AuthChoiceButtons.svelte';
import PinOrQrInput from '../../Components/PinOrQrInput.svelte';
import ThemeToggle from '../../Components/ThemeToggle.svelte';
import { onMount, onDestroy } from 'svelte';
	import { Camera, Settings } from 'lucide-svelte';
	import { toaster } from '../../lib/toaster.js';
	import {
		shouldFocusHidInput,
		shouldUseInputModeNone,
		getLocalAllowHidOnThisDevice,
		setLocalAllowHidOnThisDevice,
		getLocalPersistentHidOnThisDevice,
		setLocalPersistentHidOnThisDevice,
	} from '../../lib/displayHid.js';
	import {
		shouldAllowCameraScanner,
		setLocalAllowCameraOnThisDevice,
	} from '../../lib/displayCamera.js';

/** Per IDENTITY-BINDING-FINAL-IMPLEMENTATION-PLAN: optional washed out; only disabled | required. */
	type IdentityBindingMode = 'disabled' | 'required';

interface Track {
		id: number;
		name: string;
		is_default?: boolean;
	}

let {
		allowed = false,
		program_id = null as number | null,
		site_id = null as number | null,
		site_slug = null as string | null,
		program_slug = null as string | null,
		program_name = null,
		tracks = [],
		date = '',
		display_scan_timeout_seconds = 20,
		enable_public_triage_hid_barcode = true,
		enable_public_triage_camera_scanner = true,
		identity_binding_mode = 'disabled',
		/** When true, request identification registration can create a session (unverified). */
		allow_unverified_entry = false,
		/** Shared: when staff/admin, lockout does not apply; can exit without PIN/QR. */
		auth = null as { user?: { role?: string } } | null,
	}: {
		allowed: boolean;
		program_id: number | null;
		site_id?: number | null;
		site_slug?: string | null;
		program_slug?: string | null;
		program_name: string | null;
		tracks: Track[];
		date: string;
		display_scan_timeout_seconds?: number;
		enable_public_triage_hid_barcode?: boolean;
		enable_public_triage_camera_scanner?: boolean;
		identity_binding_mode?: IdentityBindingMode;
		allow_unverified_entry?: boolean;
		auth?: { user?: { role?: string } } | null;
	} = $props();

	/** Staff/admin can change device without unlock modal (lockout applies only to non-staff/admin). */
	const canBypassDeviceLock = $derived(
		auth?.user && auth.user.role && ['staff', 'admin', 'super_admin'].includes(auth.user.role)
	);

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
/** When true, HID is refocused every 2s when scan modal is closed (Display-like). */
let localPersistentHid = $state(false);
/** Modal HID loses focus → show "click me to allow scans". */
let barcodeValue = $state('');
let barcodeInputEl = $state<HTMLInputElement | null>(null);
	/** Guest registration form (no token yet). */
	let showGuestRegForm = $state(false);
	/** Request identification registration form (when token not found or user chooses to add details). */
	let showRequestRegForm = $state(false);
	/** Warning modal before submitting an unverified registration request. */
	let showUnverifiedRequestWarning = $state(false);
	let regFirstName = $state('');
	let regLastName = $state('');
	let regBirthDate = $state('');
	let regCategory = $state('Regular');
	/** Phone number for registration (optional). Per PRIVACY-BY-DESIGN: mobile only, no ID document. */
	let regMobile = $state('');
	/** FLOW A (verify identity): name, birth year, mobile for POST /api/public/verify-identity. */
	let verifyIdentityFirstName = $state('');
	let verifyIdentityLastName = $state('');
	let verifyIdentityBirthDate = $state('');
	let verifyIdentityMobile = $state('');
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
	/** When true, success card shows "Pending registration" (from create-registration flow). */
	let pendingRegistrationSuccess = $state(false);
	let scannerCountdownRef = $state<{ extend: (seconds: number) => void } | null>(null);
	/** Program HID setting — from props and .display_settings broadcast. */
	let enablePublicTriageHidBarcode = $state(true);
	/** Program camera/QR scanner setting — from props and .display_settings broadcast. */
	let enablePublicTriageCameraScanner = $state(true);
	/** Effective: both program and device must allow. */
	const effectiveAllowCameraScanner = $derived(enablePublicTriageCameraScanner && localAllowCameraScanner);
	/** Settings modal (shown after successful PIN/QR auth). */
	let showTriageSettingsModal = $state(false);
	/** Apply changes: PIN/QR required only when saving. */
	let triageSettingsAuthMode = $state<'pin' | 'qr' | 'request'>('pin');
	let triageSettingsPin = $state('');
	let triageSettingsQrScanToken = $state('');
	let triagePinOrQrRef = $state(null);
	let triageSettingsError = $state('');
	let triageSettingsSaving = $state(false);
	let triageSettingsProgramHid = $state(true);
	let triageSettingsProgramCamera = $state(true);
	let triageSettingsLocalAllowHid = $state(false);
	let triageSettingsLocalPersistentHid = $state(false);
	let triageSettingsLocalAllowCamera = $state(true);
	/** QR flow: create settings request, show QR, poll until approved (admin scan). */
	let triageSettingsRequestId = $state<number | null>(null);
	let triageSettingsRequestToken = $state<string | null>(null);
	let triageSettingsRequestState = $state<'idle' | 'waiting' | 'approved' | 'rejected'>('idle');
	let triageSettingsPollIntervalId = $state<ReturnType<typeof setInterval> | null>(null);

	const TRIAGE_SETTINGS_REQUEST_QR_PREFIX = 'flexiqueue:display_settings_request:';

	/** Choose device type page URL (for unlock flow). */
	const chooseUrl = $derived(
		site_slug && program_slug ? `/site/${site_slug}/program/${program_slug}/devices` : null
	);
	/** Staff/admin: exit immediately without modal; clear lock and redirect to choose page. */
	async function handleChangeDeviceClick() {
		if (canBypassDeviceLock && chooseUrl) {
			try {
				const res = await fetch('/api/public/device-lock/clear', {
					method: 'POST',
					credentials: 'include',
					headers: { 'X-CSRF-TOKEN': getCsrfToken(), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
				});
				if (res.ok) {
					sessionStorage.removeItem('device_lock_redirect_url');
					router.visit(chooseUrl);
					return;
				}
			} catch (_) {}
		}
		showUnlockModal = true;
	}
	/** Unlock flow: change device type. Same PIN/QR as when entering. */
	let showUnlockModal = $state(false);
	let unlockAuthMode = $state<'pin' | 'request'>('pin');
	let unlockPin = $state('');
	let unlockRequestId = $state<number | null>(null);
	let unlockRequestToken = $state<string | null>(null);
	let unlockRequestState = $state<'idle' | 'waiting'>('idle');
	let unlockPollIntervalId = $state<ReturnType<typeof setInterval> | null>(null);
	let unlockLoading = $state(false);
	let beforeUnloadHandler: (() => void) | null = null;

	const DEVICE_UNLOCK_REQUEST_QR_PREFIX = 'flexiqueue:device_unlock_request:';

	async function cancelUnlockRequestOnLeave() {
		if (unlockRequestId != null && unlockRequestToken) {
			try {
				await fetch(`/api/public/device-unlock-requests/${unlockRequestId}/cancel`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), Accept: 'application/json' },
					credentials: 'include',
					body: JSON.stringify({ request_token: unlockRequestToken }),
				});
			} catch {
				// ignore
			}
		}
	}

	async function createUnlockRequest() {
		if (!program_id || !chooseUrl || unlockLoading) return;
		unlockLoading = true;
		unlockRequestState = 'idle';
		unlockRequestId = null;
		unlockRequestToken = null;
		if (unlockPollIntervalId) {
			clearInterval(unlockPollIntervalId);
			unlockPollIntervalId = null;
		}
		try {
			const res = await fetch('/api/public/device-unlock-requests', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-CSRF-TOKEN': getCsrfToken(),
					'X-Requested-With': 'XMLHttpRequest',
				},
				credentials: 'include',
				body: JSON.stringify({ program_id }),
			});
			const data = (await res.json().catch(() => ({}))) as { id?: number; request_token?: string; message?: string };
			if (!res.ok) {
				toaster.error({ title: data.message || 'Failed to create unlock request.' });
				return;
			}
			unlockRequestId = data.id ?? null;
			unlockRequestToken = data.request_token ?? null;
			unlockRequestState = 'waiting';
			const id = data.id!;
			const token = data.request_token!;
			unlockPollIntervalId = setInterval(async () => {
				try {
					const r = await fetch(`/api/public/device-unlock-requests/${id}?token=${encodeURIComponent(token)}`, { credentials: 'include' });
					const d = (await r.json().catch(() => ({}))) as { status?: string };
					if (d.status === 'approved') {
						if (unlockPollIntervalId) clearInterval(unlockPollIntervalId);
						unlockPollIntervalId = null;
						unlockRequestId = null;
						unlockRequestToken = null;
						showUnlockModal = false;
						unlockRequestState = 'idle';
						toaster.success({ title: 'Device unlocked.' });
						const consumeRes = await fetch(`/api/public/device-unlock-requests/${id}/consume`, {
							method: 'POST',
							credentials: 'include',
							headers: {
								'X-CSRF-TOKEN': getCsrfToken(),
								'Content-Type': 'application/json',
								Accept: 'application/json',
							},
							body: JSON.stringify({ request_token: token }),
						});
						const consumeData = (await consumeRes.json().catch(() => ({}))) as { redirect_url?: string };
						if (consumeRes.ok && consumeData.redirect_url) {
							sessionStorage.removeItem('device_lock_redirect_url');
							router.visit(consumeData.redirect_url, { replace: true });
						} else {
							router.visit(chooseUrl!, { replace: true });
						}
					} else if (d.status === 'rejected' || d.status === 'cancelled') {
						if (unlockPollIntervalId) clearInterval(unlockPollIntervalId);
						unlockPollIntervalId = null;
						unlockRequestId = null;
						unlockRequestToken = null;
						unlockRequestState = 'idle';
						toaster.warning({ title: d.status === 'rejected' ? 'Request was rejected.' : 'Request was cancelled.' });
					}
				} catch {
					// ignore
				}
			}, 2000);
		} finally {
			unlockLoading = false;
		}
	}

	function cancelUnlockRequest() {
		unlockRequestState = 'idle';
		unlockRequestId = null;
		unlockRequestToken = null;
		unlockAuthMode = 'pin';
		unlockPin = '';
		if (unlockPollIntervalId) {
			clearInterval(unlockPollIntervalId);
			unlockPollIntervalId = null;
		}
		showUnlockModal = false;
	}

	async function submitUnlockWithPin() {
		const trimmed = unlockPin.replace(/\D/g, '').slice(0, 6);
		if (trimmed.length !== 6) {
			toaster.warning({ title: 'Enter a 6-digit PIN.' });
			return;
		}
		unlockLoading = true;
		try {
			const res = await fetch('/api/public/device-unlock-with-auth', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-CSRF-TOKEN': getCsrfToken(),
					'X-Requested-With': 'XMLHttpRequest',
				},
				credentials: 'include',
				body: JSON.stringify({ pin: trimmed }),
			});
			const data = (await res.json().catch(() => ({}))) as { redirect_url?: string; message?: string };
			if (!res.ok) {
				toaster.error({ title: data.message || 'Unlock failed.' });
				return;
			}
			toaster.success({ title: 'Device unlocked.' });
			sessionStorage.removeItem('device_lock_redirect_url');
			if (data.redirect_url) {
				showUnlockModal = false;
				router.visit(data.redirect_url, { replace: true });
			}
		} catch {
			toaster.error({ title: 'Network error. Try again.' });
		} finally {
			unlockLoading = false;
		}
	}

	onDestroy(() => {
		cancelUnlockRequestOnLeave();
		if (typeof window !== 'undefined' && beforeUnloadHandler) {
			window.removeEventListener('beforeunload', beforeUnloadHandler);
		}
	});

	onMount(() => {
		beforeUnloadHandler = () => {
			cancelUnlockRequestOnLeave();
		};
		window.addEventListener('beforeunload', beforeUnloadHandler);
	});

// Identity binding mode for public triage (per ProgramSettings). Invalid/optional treated as disabled.
	const identityBindingMode = $derived(
		((identity_binding_mode === 'required' ? 'required' : 'disabled') ?? 'disabled') as IdentityBindingMode
	);
	/** When true, show FLOW A (verify identity) + FLOW B (registration only); no plain Start my visit. */
	const showHoldingAreaFlows = $derived(
		identityBindingMode === 'required' && allow_unverified_entry === false
	);

// Per PRIVACY-BY-DESIGN-IDENTITY-BINDING: no lookup-by-id on public. Scanner is token-only.
type ScannerContext = 'token';
let scannerContext = $state<ScannerContext>('token');
type HidMode = 'token' | 'off';
let hidMode = $state<HidMode>('token');

	$effect(() => {
		enablePublicTriageHidBarcode = enable_public_triage_hid_barcode !== false;
		enablePublicTriageCameraScanner = enable_public_triage_camera_scanner !== false;
	});

	onMount(() => {
		localAllowCameraScanner = shouldAllowCameraScanner('triage');
		localPersistentHid = getLocalPersistentHidOnThisDevice('triage');
	});

	$effect(() => {
		if (!effectiveAllowCameraScanner) showScanner = false;
	});

	/** When persistent HID is on, refocus global HID every 2s when scan modal is closed. */
	$effect(() => {
		if (showScanner || !localPersistentHid || !shouldFocusHidInput(enablePublicTriageHidBarcode, 'triage')) return;
		const id = setInterval(() => {
			if (!showScanner && localPersistentHid && shouldFocusHidInput(enablePublicTriageHidBarcode, 'triage')) barcodeInputEl?.focus();
		}, 2000);
		return () => clearInterval(id);
	});

	$effect(() => {
		hidMode = scannedToken ? 'off' : 'token';
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

	function closeScanner() {
		showScanner = false;
		if (localPersistentHid && shouldFocusHidInput(enablePublicTriageHidBarcode, 'triage')) barcodeInputEl?.focus();
	}

	function openTriageSettingsModal() {
		triageSettingsProgramHid = enablePublicTriageHidBarcode;
		triageSettingsProgramCamera = enablePublicTriageCameraScanner;
		triageSettingsLocalAllowHid = getLocalAllowHidOnThisDevice('triage') === true;
		triageSettingsLocalPersistentHid = getLocalPersistentHidOnThisDevice('triage');
		triageSettingsLocalAllowCamera = shouldAllowCameraScanner('triage');
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

	async function createTriageSettingsRequest() {
		if (program_id == null || triageSettingsSaving) return;
		triageSettingsSaving = true;
		triageSettingsError = '';
		try {
			const body = {
				program_id,
				enable_public_triage_hid_barcode: triageSettingsProgramHid,
				enable_public_triage_camera_scanner: triageSettingsProgramCamera,
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
						setLocalAllowHidOnThisDevice('triage', triageSettingsLocalAllowHid);
						setLocalPersistentHidOnThisDevice('triage', triageSettingsLocalPersistentHid);
						localPersistentHid = triageSettingsLocalPersistentHid;
						setLocalAllowCameraOnThisDevice('triage', triageSettingsLocalAllowCamera);
						localAllowCameraScanner = triageSettingsLocalAllowCamera;
						toaster.success({ title: 'Settings applied.' });
						showTriageSettingsModal = false;
						router.reload({ only: ['enable_public_triage_hid_barcode', 'enable_public_triage_camera_scanner'] });
					} else if (status === 'rejected' || status === 'cancelled') {
						if (triageSettingsPollIntervalId) clearInterval(triageSettingsPollIntervalId);
						triageSettingsPollIntervalId = null;
						triageSettingsRequestState = 'idle';
						triageSettingsRequestId = null;
						triageSettingsRequestToken = null;
						toaster.warning({ title: status === 'rejected' ? 'Request was rejected.' : 'Request was cancelled.' });
					}
				} catch {
					// ignore poll errors
				}
			}, 2000);
		} finally {
			triageSettingsSaving = false;
		}
	}

	async function saveTriageSettings() {
		triageSettingsError = '';
		if (program_id == null) {
			triageSettingsError = 'No program selected.';
			return;
		}
		const authBody = triageSettingsAuthMode === 'pin' ? (triagePinOrQrRef?.buildPinOrQrPayload?.() ?? null) : null;
		if (!authBody) {
			triageSettingsError = triageSettingsAuthMode === 'pin' ? 'Enter a 6-digit PIN to apply changes.' : 'Use "Show QR for supervisor to scan" to apply changes.';
			return;
		}
		triageSettingsSaving = true;
		try {
			const body = {
				program_id,
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
			const d = data as { enable_public_triage_hid_barcode?: boolean; enable_public_triage_camera_scanner?: boolean };
			enablePublicTriageHidBarcode = !!d.enable_public_triage_hid_barcode;
			enablePublicTriageCameraScanner = d.enable_public_triage_camera_scanner !== false;
			setLocalAllowHidOnThisDevice('triage', triageSettingsLocalAllowHid);
			setLocalPersistentHidOnThisDevice('triage', triageSettingsLocalPersistentHid);
			localPersistentHid = triageSettingsLocalPersistentHid;
			setLocalAllowCameraOnThisDevice('triage', triageSettingsLocalAllowCamera);
			localAllowCameraScanner = triageSettingsLocalAllowCamera;
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

		// Per PRIVACY-BY-DESIGN: no client_binding on public (no lookup-by-phone).

		const { ok, data } = await api('POST', '/api/public/sessions/bind', body);
		isSubmitting = false;
		if (ok) {
			const d = data as { request_submitted?: boolean; session?: unknown; message?: string; unverified?: boolean; client_already_registered?: boolean };
			if (d?.request_submitted) {
				toaster.success({ title: d?.message ?? 'Your request has been submitted. Staff will verify your identity.' });
				return;
			}
			bindSuccess = true;
			toaster.success({
				title: d?.client_already_registered === true
					? "You're in the queue. This number is already registered."
					: d?.unverified === true
						? "You're in the queue (unverified). Staff may verify your identity later."
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
		pendingRegistrationSuccess = false;
		showRequestRegForm = false;
		regFirstName = '';
		regLastName = '';
		regBirthDate = '';
		regCategory = 'Regular';
		regMobile = '';
		verifyIdentityFirstName = '';
		verifyIdentityLastName = '';
		verifyIdentityBirthDate = '';
		verifyIdentityMobile = '';
	}

	async function submitRequestIdentificationRegistration() {
		if (!scannedToken || selectedTrackId == null) return;
		if (program_id == null) {
			toaster.error({ title: 'Program not set. Please use the triage link for your program.' });
			return;
		}
		isSubmitting = true;
		const first = String(regFirstName ?? '').trim();
		const last = String(regLastName ?? '').trim();
		const birthDate = String(regBirthDate ?? '').trim();
		const mobileStr = String(regMobile ?? '').trim();
		const body: Record<string, unknown> = {
			program_id,
			qr_hash: scannedToken.qr_hash,
			track_id: Number(selectedTrackId),
			identity_registration_request: {
				...(first ? { first_name: first } : {}),
				...(last ? { last_name: last } : {}),
				...(birthDate ? { birth_date: birthDate } : {}),
				...(regCategory ? { client_category: regCategory } : {}),
				...(mobileStr ? { mobile: mobileStr } : {}),
			},
		};
		const { ok, data } = await api('POST', '/api/public/sessions/bind', body);
		isSubmitting = false;
		if (ok) {
			const d = data as { request_submitted?: boolean; session?: unknown; message?: string; unverified?: boolean; client_already_registered?: boolean };
			if (d?.request_submitted) {
				toaster.success({ title: d?.message ?? 'Your request has been submitted. Staff will verify your identity.' });
				showUnverifiedRequestWarning = false;
				showRequestRegForm = false;
				regFirstName = '';
				regLastName = '';
				regBirthDate = '';
				regCategory = 'Regular';
				regMobile = '';
				return;
			}
			bindSuccess = true;
			pendingRegistrationSuccess = true;
			// Create-registration flow with session (allow_unverified): say pending registration, not "on queue"
			toaster.success({
				title: d?.client_already_registered === true
					? "You're in the queue. This number is already registered."
					: "Pending registration. Staff will verify your identity.",
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
		const first = String(regFirstName ?? '').trim();
		const last = String(regLastName ?? '').trim();
		const birthDate = String(regBirthDate ?? '').trim();
		const mobileStr = String(regMobile ?? '').trim();
		const body: Record<string, unknown> = {
			program_id,
			identity_registration_request: {
				...(first ? { first_name: first } : {}),
				...(last ? { last_name: last } : {}),
				...(birthDate ? { birth_date: birthDate } : {}),
				...(regCategory ? { client_category: regCategory } : {}),
				...(mobileStr ? { mobile: mobileStr } : {}),
			},
		};
		const { ok, data } = await api('POST', '/api/public/sessions/bind', body);
		isSubmitting = false;
		if (ok) {
			const d = data as { request_submitted?: boolean; session?: unknown; message?: string; unverified?: boolean; client_already_registered?: boolean };
			if (d?.request_submitted) {
				toaster.success({ title: d?.message ?? 'Your request has been submitted. Staff will verify your identity.' });
				showGuestRegForm = false;
				regFirstName = '';
				regLastName = '';
				regBirthDate = '';
				regCategory = 'Regular';
				regMobile = '';
				return;
			}
			bindSuccess = true;
			pendingRegistrationSuccess = true;
			// Create-registration flow with session: say pending registration, not "on queue"
			toaster.success({
				title: d?.client_already_registered === true
					? "You're in the queue. This number is already registered."
					: "Pending registration. Staff will verify your identity.",
			});
			return;
		}
		toaster.error({ title: (data as { message?: string })?.message ?? 'Could not submit request.' });
	}

	/** FLOW A: POST /api/public/verify-identity — exact match; creates bind_confirmation hold for staff. */
	async function submitVerifyIdentity() {
		if (!scannedToken || selectedTrackId == null || program_id == null) {
			toaster.error({ title: 'Scan a token and choose a track first.' });
			return;
		}
		const first = String(verifyIdentityFirstName ?? '').trim();
		const last = String(verifyIdentityLastName ?? '').trim();
		const birthDate = String(verifyIdentityBirthDate ?? '').trim();
		const mobileStr = String(verifyIdentityMobile ?? '').trim();
		if (!first || !last || !birthDate || !mobileStr) {
			toaster.error({ title: 'First name, last name, birth date, and phone are required to verify identity.' });
			return;
		}
		isSubmitting = true;
		const body = {
			program_id,
			first_name: first,
			last_name: last,
			birth_date: birthDate,
			mobile: mobileStr,
			qr_hash: scannedToken.qr_hash,
			track_id: Number(selectedTrackId),
		};
		const { ok, data } = await api('POST', '/api/public/verify-identity', body);
		isSubmitting = false;
		if (ok) {
			const d = data as { status?: string; verified?: boolean; message?: string };
			if (d?.verified === false) {
				toaster.warning({ title: d?.message ?? 'No matching account found.' });
				return;
			}
			toaster.success({ title: d?.message ?? 'Your identity has been verified. Please see a staff member.' });
			bindSuccess = true;
			pendingRegistrationSuccess = true;
		} else {
			toaster.error({ title: (data as { message?: string })?.message ?? 'Verification failed.' });
		}
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
				{#if pendingRegistrationSuccess}
					<p class="text-lg font-semibold text-success-900 mb-2">Pending registration</p>
					<p class="text-surface-700 mb-4">Staff will verify your identity. You can check status below.</p>
				{:else}
					<p class="text-lg font-semibold text-success-900 mb-2">You're in the queue</p>
					<p class="text-surface-700 mb-4">Token {scannedToken.physical_id}. Check your status below.</p>
				{/if}
				<a href={site_id != null ? `/display/status/${site_id}/${encodeURIComponent(scannedToken.qr_hash)}` : `/display/status/${encodeURIComponent(scannedToken.qr_hash)}`} class="btn preset-filled-primary-500">Check my status</a>
			</div>
		{:else}
			<div class="flex items-center justify-between gap-2">
				<h1 class="text-xl font-bold text-surface-950">Start your visit</h1>
				<div class="flex items-center gap-2">
					{#if chooseUrl}
						<button
							type="button"
							class="btn preset-tonal text-sm touch-target-h"
							onclick={handleChangeDeviceClick}
						>
							Change device type
						</button>
					{/if}
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

			<ScanModal
				open={showScanner}
				title="Scan QR via Camera"
				onClose={closeScanner}
				allowHid={shouldFocusHidInput(enablePublicTriageHidBarcode, 'triage')}
				allowCamera={!!enablePublicTriageCameraScanner}
				onScan={handleQrScan}
				wide={true}
				inputModeNone={shouldUseInputModeNone(enablePublicTriageHidBarcode, 'triage')}
			>
				{#snippet extra()}
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
				{/snippet}
			</ScanModal>

			<Modal open={showUnlockModal} title="Unlock device" onClose={cancelUnlockRequest}>
				{#snippet children()}
					<div class="flex flex-col gap-4">
						<p class="text-sm text-surface-600 dark:text-slate-400">
							Use the same PIN or QR as when entering. Enter supervisor PIN or show QR for them to scan.
						</p>
						{#if unlockRequestState === 'waiting' && unlockRequestId != null && unlockRequestToken}
							<p class="text-sm font-medium text-surface-950">Waiting for approval…</p>
							<div class="flex justify-center">
								<img
									class="rounded-container border border-surface-200 bg-white p-2"
									alt="QR for supervisor to scan"
									width="200"
									height="200"
									src={`https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(DEVICE_UNLOCK_REQUEST_QR_PREFIX + unlockRequestId + ':' + unlockRequestToken)}`}
								/>
							</div>
							<button type="button" class="btn preset-tonal btn-sm touch-target-h" onclick={cancelUnlockRequest}>
								Cancel request
							</button>
						{:else}
							<div class="flex gap-2">
								<button
									type="button"
									class="btn btn-sm flex-1 touch-target-h {unlockAuthMode === 'pin' ? 'preset-filled-primary-500' : 'preset-tonal'}"
									onclick={() => (unlockAuthMode = 'pin')}
								>
									PIN
								</button>
								<button
									type="button"
									class="btn btn-sm flex-1 touch-target-h {unlockAuthMode === 'request' ? 'preset-filled-primary-500' : 'preset-tonal'}"
									onclick={() => (unlockAuthMode = 'request')}
								>
									QR
								</button>
							</div>
							{#if unlockAuthMode === 'pin'}
								<form
									class="flex flex-col gap-3"
									onsubmit={(e) => {
										e.preventDefault();
										submitUnlockWithPin();
									}}
								>
									<label class="block">
										<span class="text-sm font-medium text-surface-700 dark:text-slate-300">Supervisor PIN</span>
										<input
											type="password"
											inputmode="numeric"
											pattern="[0-9]*"
											maxlength="6"
											autocomplete="one-time-code"
											class="input w-full mt-1"
											placeholder="6-digit PIN"
											bind:value={unlockPin}
											disabled={unlockLoading}
										/>
									</label>
									<button type="submit" class="btn preset-filled-primary-500" disabled={unlockLoading}>
										{unlockLoading ? 'Unlocking…' : 'Unlock'}
									</button>
								</form>
							{:else}
								<button
									type="button"
									class="btn preset-filled-primary-500"
									disabled={unlockLoading}
									onclick={createUnlockRequest}
								>
									{unlockLoading ? 'Creating…' : 'Show QR for supervisor to scan'}
								</button>
							{/if}
						{/if}
						{#if unlockRequestState !== 'waiting'}
							<button type="button" class="btn preset-tonal" onclick={cancelUnlockRequest}>
								Cancel
							</button>
						{/if}
					</div>
				{/snippet}
			</Modal>

			<Modal open={showTriageSettingsModal} title="Triage settings" onClose={() => { cancelTriageSettingsRequest(); showTriageSettingsModal = false; }}>
				{#snippet children()}
					<div class="flex flex-col gap-6">
						<p class="text-sm text-surface-950/70">You can view and change settings below. Changes are applied only when you save; saving requires PIN or QR (admin scan).</p>
						<div class="flex flex-col gap-4">
							<h3 class="text-sm font-semibold text-surface-950">Program settings</h3>
							<p class="text-xs text-surface-950/60">Apply to all triage pages.</p>
							<label class="flex items-center gap-2 cursor-pointer">
								<input type="checkbox" class="checkbox" bind:checked={triageSettingsProgramHid} disabled={triageSettingsSaving} />
								<span class="text-sm text-surface-950">Allow HID barcode scanner</span>
							</label>
							<label class="flex items-center gap-2 cursor-pointer">
								<input type="checkbox" class="checkbox" bind:checked={triageSettingsProgramCamera} disabled={triageSettingsSaving} />
								<span class="text-sm text-surface-950">Allow camera/QR scanner</span>
							</label>
						</div>
						<div class="border-t border-surface-200 pt-4 flex flex-col gap-2">
							<h3 class="text-sm font-semibold text-surface-950">This device</h3>
							<p class="text-xs text-surface-950/60">On this device only — not saved to server.</p>
							<label class="flex items-center gap-2 cursor-pointer">
								<input type="checkbox" class="checkbox" bind:checked={triageSettingsLocalAllowHid} />
								<span class="text-sm text-surface-950">Allow HID scanner on this device</span>
							</label>
							{#if triageSettingsLocalAllowHid}
								<label class="flex items-center gap-2 cursor-pointer pl-6">
									<input type="checkbox" class="checkbox" bind:checked={triageSettingsLocalPersistentHid} />
									<span class="text-sm text-surface-950">Keep HID ready when scan modal is closed</span>
								</label>
							{/if}
							<label class="flex items-center gap-2 cursor-pointer">
								<input type="checkbox" class="checkbox" bind:checked={triageSettingsLocalAllowCamera} />
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
						<div class="border-t border-surface-200 pt-4 flex flex-col gap-2">
							<h3 class="text-sm font-semibold text-surface-950">Apply changes</h3>
							<p class="text-xs text-surface-950/60">Authorize with PIN or show QR for supervisor to scan. Settings above are applied only when you save.</p>
							<AuthChoiceButtons includeRequest={true} disabled={triageSettingsSaving || triageSettingsRequestState === 'waiting'} bind:mode={triageSettingsAuthMode} />
							{#if triageSettingsRequestState === 'waiting' && triageSettingsRequestId != null && triageSettingsRequestToken != null}
								<div class="flex flex-col items-center gap-3 py-4">
									<p class="text-sm font-medium text-surface-950">Waiting for approval…</p>
									<p class="text-xs text-surface-950/60 text-center">Ask the program supervisor or admin to scan this QR on the Track overrides page.</p>
									<img class="rounded-container border border-surface-200 bg-white p-2" alt="QR for supervisor to scan" width="200" height="200" src={`https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(TRIAGE_SETTINGS_REQUEST_QR_PREFIX + triageSettingsRequestId + ':' + triageSettingsRequestToken)}`} />
									<button type="button" class="btn preset-tonal btn-sm touch-target-h" onclick={cancelTriageSettingsRequest}>Cancel request</button>
								</div>
							{:else if triageSettingsAuthMode === 'request'}
								<button type="button" class="btn preset-filled-primary-500" onclick={createTriageSettingsRequest} disabled={triageSettingsSaving || program_id == null}>
									{triageSettingsSaving ? 'Creating…' : 'Show QR for supervisor to scan'}
								</button>
							{:else}
								<PinOrQrInput bind:this={triagePinOrQrRef} disabled={triageSettingsSaving} mode={triageSettingsAuthMode} bind:pin={triageSettingsPin} bind:qrScanToken={triageSettingsQrScanToken} />
							{/if}
							{#if triageSettingsError}
								<p id="triage-settings-pin-error" class="text-sm text-error-600">{triageSettingsError}</p>
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
								<li>Incorrect name, birth year, or phone may delay help or be linked to the wrong person.</li>
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
						{#if shouldFocusHidInput(enablePublicTriageHidBarcode, 'triage')}
							<button
								type="button"
								class="btn btn-sm preset-tonal shrink-0 touch-target-h"
								aria-label="Open scan modal for barcode"
								onclick={() => {
									scannerContext = 'token';
									showScanner = true;
									scanHandled = false;
								}}
							>
								Scan with barcode
							</button>
						{/if}
					</div>
				</section>
				{#if identityBindingMode !== 'disabled'}
					<section class="rounded-container border border-surface-200 bg-surface-50 p-4 md:p-6 space-y-3">
						<div class="space-y-1">
							<p class="text-sm font-semibold text-surface-950">
								{showHoldingAreaFlows ? 'Submit registration for staff' : 'Need help linking your ID?'}
							</p>
							<p class="text-xs text-surface-700">
								{showHoldingAreaFlows
									? 'Submit your details so staff can process your request. No token needed.'
									: 'Request registration so staff can verify your identity and link your ID to your record (new client or existing).'}
							</p>
						</div>
						<button
							type="button"
							class="btn preset-tonal w-full touch-target-h"
							onclick={() => (showGuestRegForm = !showGuestRegForm)}
							disabled={isSubmitting}
							data-testid="public-guest-request-registration-toggle"
						>
							{showGuestRegForm ? 'Hide registration form' : 'Request registration'}
						</button>
						{#if showGuestRegForm}
							<div class="rounded-container border border-surface-200 bg-surface-100 p-3 space-y-2" data-testid="public-guest-request-registration-form">
								<p class="text-xs font-medium text-surface-800">Enter your details (all optional). Staff will verify.</p>
								<label class="flex flex-col gap-1 text-xs">
									<span class="text-surface-600">First name</span>
									<input type="text" class="input rounded-container border border-surface-200 px-3 py-2" placeholder="First name" bind:value={regFirstName} />
								</label>
								<label class="flex flex-col gap-1 text-xs">
									<span class="text-surface-600">Last name</span>
									<input type="text" class="input rounded-container border border-surface-200 px-3 py-2" placeholder="Last name" bind:value={regLastName} />
								</label>
								<label class="flex flex-col gap-1 text-xs">
									<span class="text-surface-600">Birth date</span>
									<input type="date" class="input rounded-container border border-surface-200 px-3 py-2" bind:value={regBirthDate} />
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
								<label class="flex flex-col gap-1 text-xs">
									<span class="text-surface-600">Phone (optional)</span>
									<input
										type="tel"
										inputmode="numeric"
										class="input rounded-container border border-surface-200 px-3 py-2"
										placeholder="e.g. 09171234567"
										bind:value={regMobile}
										data-testid="public-guest-mobile"
									/>
								</label>
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

{#if showHoldingAreaFlows}
						<!-- FLOW A: verify identity. Form binds: verifyIdentityFirstName, verifyIdentityLastName, verifyIdentityBirthDate, verifyIdentityMobile (all $state). -->
						<div class="rounded-container border border-surface-200 bg-surface-100 p-3 space-y-3 mt-2" data-testid="public-verify-identity-form">
							<p class="text-xs font-medium text-surface-800">Verify your identity so staff can start your visit.</p>
							<label class="flex flex-col gap-1 text-xs">
								<span class="text-surface-600">First name</span>
								<input type="text" class="input rounded-container border border-surface-200 px-3 py-2" placeholder="First name" bind:value={verifyIdentityFirstName} />
							</label>
							<label class="flex flex-col gap-1 text-xs">
								<span class="text-surface-600">Last name</span>
								<input type="text" class="input rounded-container border border-surface-200 px-3 py-2" placeholder="Last name" bind:value={verifyIdentityLastName} />
							</label>
							<label class="flex flex-col gap-1 text-xs">
								<span class="text-surface-600">Birth date</span>
								<input type="date" class="input rounded-container border border-surface-200 px-3 py-2" bind:value={verifyIdentityBirthDate} />
							</label>
							<label class="flex flex-col gap-1 text-xs">
								<span class="text-surface-600">Phone</span>
								<input type="tel" inputmode="numeric" class="input rounded-container border border-surface-200 px-3 py-2" placeholder="e.g. 09171234567" bind:value={verifyIdentityMobile} />
							</label>
							<button
								type="button"
								class="btn preset-filled-primary-500 w-full touch-target-h"
								disabled={isSubmitting || selectedTrackId == null}
								onclick={submitVerifyIdentity}
								data-testid="public-verify-identity-button"
							>
								{isSubmitting ? 'Verifying…' : 'Verify identity'}
							</button>
						</div>
					{:else if identityBindingMode !== 'disabled'}
						<div
							class="rounded-container border border-surface-200 bg-surface-100 p-3 space-y-3"
							data-testid="public-identity-optional-details"
						>
							<p class="text-xs text-surface-700">
								Optionally add your details so staff can verify your identity. You can still start your visit without this.
							</p>
							<button
								type="button"
								class="btn preset-tonal w-full touch-target-h"
								data-testid="public-request-registration-toggle"
								onclick={() => (showRequestRegForm = !showRequestRegForm)}
								disabled={isSubmitting}
							>
								{showRequestRegForm ? 'Hide details form' : 'Add details / Request registration'}
							</button>
							{#if showRequestRegForm}
								<div class="rounded-container border border-surface-200 bg-surface-50 p-3 space-y-3 mt-2" data-testid="public-request-registration-form">
									<label class="flex flex-col gap-1 text-xs">
										<span class="text-surface-600">First name (optional)</span>
										<input type="text" class="input rounded-container border border-surface-200 px-3 py-2" placeholder="First name" bind:value={regFirstName} />
									</label>
									<label class="flex flex-col gap-1 text-xs">
										<span class="text-surface-600">Last name (optional)</span>
										<input type="text" class="input rounded-container border border-surface-200 px-3 py-2" placeholder="Last name" bind:value={regLastName} />
									</label>
									<label class="flex flex-col gap-1 text-xs">
										<span class="text-surface-600">Birth date (optional)</span>
										<input type="date" class="input rounded-container border border-surface-200 px-3 py-2" bind:value={regBirthDate} />
									</label>
									<label class="flex flex-col gap-1 text-xs">
										<span class="text-surface-600">Classification</span>
										<select class="select select-theme input rounded-container border border-surface-200 px-3 py-2" bind:value={regCategory}>
											{#each REGISTRATION_CATEGORIES as cat (cat.value)}
												<option value={cat.value}>{cat.label}</option>
											{/each}
										</select>
									</label>
									<label class="flex flex-col gap-1 text-xs">
										<span class="text-surface-600">Phone (optional)</span>
										<input type="tel" inputmode="numeric" class="input rounded-container border border-surface-200 px-3 py-2" placeholder="e.g. 09171234567" bind:value={regMobile} data-testid="public-request-reg-mobile" />
									</label>
									<button
										type="button"
										class="btn preset-filled-primary-500 w-full touch-target-h"
										data-testid="public-request-registration-submit"
										disabled={isSubmitting}
										onclick={() => (showUnverifiedRequestWarning = true)}
									>
										{isSubmitting ? 'Submitting…' : 'Submit details and start visit'}
									</button>
								</div>
							{/if}
						</div>
					{/if}

					{#if identityBindingMode === 'disabled'}
						<p class="text-xs text-surface-600 mt-2">
							Have a registration first submitted, or proceed to start your visit.
						</p>
					{:else if allow_unverified_entry && !showHoldingAreaFlows}
						<p class="text-xs text-surface-600 mt-2">
							To verify identity you need the PIN/QR of a supervisor or admin to proceed, or have a registration first submitted.
						</p>
					{/if}
					<div class="flex gap-2 pt-2">
						<button type="button" class="btn preset-tonal flex-1 touch-target-h" onclick={reset} disabled={isSubmitting}>
							Cancel
						</button>
						{#if !showHoldingAreaFlows}
							<button
								type="button"
								class="btn preset-filled-primary-500 flex-1 touch-target-h"
								onclick={handleBind}
								disabled={isSubmitting || selectedTrackId == null}
								data-testid="public-start-visit-button"
							>
								{isSubmitting ? 'Starting…' : 'Start my visit'}
							</button>
						{/if}
					</div>
				</div>
			{/if}
		{/if}
	</div>
</DisplayLayout>
