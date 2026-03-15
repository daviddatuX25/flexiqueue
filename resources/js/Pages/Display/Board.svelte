<script>
	/**
	 * Display/Board.svelte — client-facing "Now Serving" informant. Per 09-UI-ROUTES-PHASE1 §3.4.
	 * Public, no auth. Shows program name, date, staff availability (profile bar + status icons),
	 * Scan section, Now Serving grid, waiting by station, station activity.
	 *
	 * Plan: Staff availability from backend (staff_at_stations[].staff[].availability_status).
	 * Profile bar at top (compact list with avatar + status dot + name); STAFF ON DUTY section
	 * shows same status dot per staff. No Echo for status in Phase 1 (page load only).
	 *
	 * Edge cases: No active program → staff_at_stations empty, bar hidden. No staff assigned →
	 * bar hidden, section shows "No staff assigned". Mixed statuses → dot by status (available /
	 * on_break / away|offline). Mobile: bar wraps. Empty state: existing copy.
	 */
	import { get } from 'svelte/store';
	import { onMount, onDestroy, tick } from 'svelte';
	import { router, usePage } from '@inertiajs/svelte';
	import DisplayLayout from '../../Layouts/DisplayLayout.svelte';
	import Modal from '../../Components/Modal.svelte';
	import ScanModal from '../../Components/ScanModal.svelte';
	import AuthChoiceButtons from '../../Components/AuthChoiceButtons.svelte';
	import PinOrQrInput from '../../Components/PinOrQrInput.svelte';
	import UserAvatar from '../../Components/UserAvatar.svelte';
	import ThemeToggle from '../../Components/ThemeToggle.svelte';
	import { scrollBooster } from '../../lib/scrollBooster.js';
	import { Camera, Settings, Monitor, FolderOpen, AlertCircle } from 'lucide-svelte';
	import {
		prepareDisplayTts,
		cancelCurrentAnnouncement,
		playFullAnnouncement,
		createFullAnnouncementParams,
	} from '../../lib/displayTts.js';
	import {
		shouldFocusHidInput,
		shouldUseInputModeNone,
		getLocalAllowHidOnThisDevice,
		setLocalAllowHidOnThisDevice,
		getLocalPersistentHidOnThisDevice,
		setLocalPersistentHidOnThisDevice,
		isMobileTouch,
	} from '../../lib/displayHid.js';
	import {
		shouldAllowCameraScanner,
		setLocalAllowCameraOnThisDevice,
	} from '../../lib/displayCamera.js';
	import { toaster } from '../../lib/toaster.js';

	const page = usePage();

	function getCsrfToken() {
		const p = get(page)?.props;
		const fromProps = (p && typeof p === 'object' && 'csrf_token' in p) ? p.csrf_token : undefined;
		if (fromProps) return fromProps;
		if (typeof document !== 'undefined') {
			const meta = document.querySelector('meta[name="csrf-token"]');
			return (meta && meta.getAttribute('content')) || '';
		}
		return '';
	}

/** A.2.4: programs list for selector when no program in URL; currentProgram when ?program= set; program_not_found when invalid/inactive id. A.4.2: fallback to program for transition. */
let {
	programs = [],
	currentProgram = null,
	program = null,
	program_not_found = false,
	program_name = null,
	/** When set (per-site URL), program links use /site/{site_slug}/display?program= so server can return DeviceAuthorize. */
	site_slug = null,
	program_slug = null,
	date = '',
	now_serving = [],
	waiting_by_station = [],
	total_in_queue = 0,
	station_activity = [],
	staff_at_stations = [],
	staff_online = 0,
	display_scan_timeout_seconds = 20,
	program_is_paused = false,
	program_is_active = true,
	display_audio_muted = false,
	display_audio_volume = 1,
	enable_display_hid_barcode = true,
	enable_display_camera_scanner = true,
	tts_active_language = 'en',
	tts_connector_phrase = null,
	station_tts_by_name = {},
	display_tts_repeat_count = 1,
	display_tts_repeat_delay_ms = 2000,
	queueing_method_label = null,
	queue_mode_display = null,
	alternate_ratio = null,
	station_selection_mode = null,
	/** Per public-site plan: read-only board at /site/{site}/program/{program}/view; no device controls. */
	publicView = false,
} = $props();

/** Effective program: currentProgram with fallback to program for transition. */
const effectiveCurrentProgram = $derived(currentProgram ?? program);

/** True when board content (now serving, queue, etc.) should be shown; false when showing program selector or "no program". */
const showBoardContent = $derived(effectiveCurrentProgram != null && !program_not_found);

/** Base URL for display: per-site when site_slug set, else legacy /display. Used so clicking a program stays on same site and triggers device auth. */
const displayBase = $derived(site_slug ? `/site/${site_slug}/display` : '/display');
/** Choose device type page URL (for unlock flow). Only set when locked to a program with site. */
const chooseUrl = $derived(
	site_slug && (effectiveCurrentProgram?.slug ?? program_slug)
		? `/site/${site_slug}/program/${effectiveCurrentProgram?.slug ?? program_slug}/devices`
		: null
);

/** Synced from prop + .program_status; when true, show "Program is paused" overlay (real-time). */
let programIsPaused = $state(false);
/** Per plan Step 5: when false (program closed), show "Program is not currently running" overlay. */
let programIsActive = $state(true);
/** Per plan: display board TTS mute/volume — from props and .display_settings broadcast. */
let displayAudioMuted = $state(false);
let displayAudioVolume = $state(1);
/** TTS announcement repeat (1–3) and delay between repeats (ms); from props and .display_settings broadcast. */
let displayTtsRepeatCount = $state(1);
let displayTtsRepeatDelayMs = $state(2000);
/** Active TTS language and phrases from program/station. */
let ttsLanguage = $state('en');
let connectorPhrase = $state(null);
let stationTtsByName = $state({});
	/** Program HID setting — from props and .display_settings broadcast; both program and device-local decide focus. */
	let enableDisplayHidBarcode = $state(true);
	/** Program camera/QR scanner setting — from props and .display_settings broadcast. */
	let enableDisplayCameraScanner = $state(true);
	/** Device-local camera/QR scanner allow (default ON when unset). */
	let localAllowCameraScanner = $state(true);
	const effectiveAllowCameraScanner = $derived(enableDisplayCameraScanner && localAllowCameraScanner);

	$effect(() => {
		programIsPaused = !!program_is_paused;
		programIsActive = program_is_active !== false;
	});
	$effect(() => {
		displayAudioMuted = !!display_audio_muted;
		displayAudioVolume = Math.max(0, Math.min(1, Number(display_audio_volume ?? 1)));
		displayTtsRepeatCount = Math.max(1, Math.min(3, Math.floor(Number(display_tts_repeat_count) || 1)));
		displayTtsRepeatDelayMs = Math.max(500, Math.min(10000, Math.floor(Number(display_tts_repeat_delay_ms) || 2000)));
		enableDisplayHidBarcode = enable_display_hid_barcode !== false;
		enableDisplayCameraScanner = enable_display_camera_scanner !== false;
		const lang =
			typeof tts_active_language === 'string' && tts_active_language
				? tts_active_language
				: 'en';
		ttsLanguage = ['en', 'fil', 'ilo'].includes(lang) ? lang : 'en';
		connectorPhrase =
			typeof tts_connector_phrase === 'string' && tts_connector_phrase.trim() !== ''
				? tts_connector_phrase.trim()
				: null;
		stationTtsByName =
			station_tts_by_name && typeof station_tts_by_name === 'object'
				? station_tts_by_name
				: {};
	});

	$effect(() => {
		if (!localAllowCameraScanner) showScanner = false;
	});

	/** Open camera modal when URL has ?scan=1 only if camera scanner is enabled. */
	$effect(() => {
		try {
			const pageData = get(page);
			if (!pageData || typeof pageData !== 'object') return;
			const url = typeof pageData.url === 'string' ? pageData.url : (typeof window !== 'undefined' ? window.location.href : '');
			const parsed = new URL(url, typeof window !== 'undefined' ? window.location.origin : 'http://localhost');
			if (parsed.searchParams.get('scan') === '1') {
				if (effectiveAllowCameraScanner) {
					showScanner = true;
					scanHandled = false;
				}
				if (typeof window !== 'undefined') {
					parsed.searchParams.delete('scan');
					const search = parsed.searchParams.toString();
					window.history.replaceState({}, '', parsed.pathname + (search ? '?' + search : ''));
				}
			}
		} catch {
			// Ignore URL parse errors or missing page
		}
	});

	let showScanner = $state(false);
	let scanHandled = $state(false);
	let scanCountdown = $state(0);
	let scanCountdownIntervalId = null;
	/** When true, HID is refocused every 2s when scan modal is closed (Display-like). When false, HID only in modal (Triage-like). */
	let localPersistentHid = $state(true);
	/** Modal HID input loses focus → show "click me to allow scans"; no 2s refocus inside modal. */
	/** Hidden input for HID barcode scanner on display; refocus every 2s when modal closed and persistent HID on. */
	let displayBarcodeValue = $state('');
	let displayBarcodeInputEl = $state(null);
	/** Activity feed: synced from props (and after reload), prepended by real-time events */
	let activityFeed = $state([]);
	/** Display settings modal (PIN/QR auth first, then program + device-local). */
	let showDisplaySettingsModal = $state(false);
	let displaySettingsAuthMode = $state('pin');
	let displaySettingsPin = $state('');
	let displaySettingsQrScanToken = $state('');
	let displayPinOrQrRef = $state(null);
	let displaySettingsError = $state('');
	let displaySettingsSaving = $state(false);
	let displaySettingsProgramHid = $state(true);
	let displaySettingsCameraScanner = $state(true);
	let displaySettingsMuted = $state(false);
	let displaySettingsVolume = $state(1);
	let displaySettingsLocalAllowHid = $state(false);
	let displaySettingsLocalPersistentHid = $state(true);
	let displaySettingsLocalAllowCamera = $state(true);
	let availableTtsVoices = $state([]);
	/** QR flow: create display-settings-request, show QR, poll until approved/rejected */
	let displaySettingsRequestId = $state(null);
	let displaySettingsRequestToken = $state(null);
	let displaySettingsRequestState = $state('idle'); // idle | waiting | approved | rejected
	let displaySettingsPollIntervalId = null;

	/** Footer staff strip: click → horizontally scrollable; blur/click outside or after idle → back to marquee. */
	let footerStaffMode = $state('marquee'); // 'marquee' | 'scrollable'
	let footerScrollableTimeoutId = null;
	let footerStaffRef = $state(null);
	const FOOTER_SCROLLABLE_IDLE_MS = 4000;

	function setFooterToScrollable() {
		footerStaffMode = 'scrollable';
		scheduleFooterBackToMarquee();
		tick().then(() => footerStaffRef?.focus());
	}

	function switchFooterToMarquee() {
		if (footerScrollableTimeoutId != null) {
			clearTimeout(footerScrollableTimeoutId);
			footerScrollableTimeoutId = null;
		}
		footerStaffMode = 'marquee';
	}

	function scheduleFooterBackToMarquee() {
		if (footerScrollableTimeoutId != null) clearTimeout(footerScrollableTimeoutId);
		footerScrollableTimeoutId = setTimeout(switchFooterToMarquee, FOOTER_SCROLLABLE_IDLE_MS);
	}

	function resetFooterScrollableIdle() {
		if (footerStaffMode !== 'scrollable') return;
		scheduleFooterBackToMarquee();
	}

	function handleFooterPointerDownOutside(e) {
		if (footerStaffMode !== 'scrollable' || !footerStaffRef) return;
		if (footerStaffRef.contains(e.target)) return;
		switchFooterToMarquee();
	}

	function handleFooterFocusOut(e) {
		if (footerStaffMode !== 'scrollable' || !footerStaffRef) return;
		const next = e.relatedTarget;
		if (next != null && footerStaffRef.contains(next)) return;
		switchFooterToMarquee();
	}

	/** Unlock flow: change device type (back to choose page). Same PIN/QR as when entering. */
	let showUnlockModal = $state(false);
	let unlockAuthMode = $state('pin'); // 'pin' | 'request'
	let unlockPin = $state('');
	let unlockRequestId = $state(null);
	let unlockRequestToken = $state(null);
	let unlockRequestState = $state('idle');
	let unlockPollIntervalId = null;
	let unlockLoading = $state(false);
	$effect(() => {
		const raw = station_activity ?? [];
		activityFeed = Array.isArray(raw) ? [...raw] : [];
	});

	/** Recent activity: max 20 items, fixed-height scroll (shows ~5 items). No View more/less. */
	const visibleActivity = $derived(activityFeed.slice(0, 20));

	function refreshBoardData() {
		router.reload({
			only: [
				'now_serving',
				'waiting_by_station',
				'total_in_queue',
				'station_activity',
				'program_is_paused',
				'display_audio_muted',
				'display_audio_volume',
				'queueing_method_label',
				'queue_mode_display',
				'alternate_ratio',
				'station_selection_mode',
			],
		});
	}

	async function saveDisplaySettings() {
		displaySettingsError = '';
		// PIN/QR required at save time (not at open)
		const authBody = displaySettingsAuthMode === 'pin' ? (displayPinOrQrRef?.buildPinOrQrPayload?.() ?? null) : null;
		if (!authBody) {
			displaySettingsError = displaySettingsAuthMode === 'pin' ? 'Enter a 6-digit PIN to apply changes.' : 'Use "Show QR for supervisor to scan" to apply changes.';
			return;
		}
		displaySettingsSaving = true;
		try {
			const prog = effectiveCurrentProgram;
			if (!prog?.id) {
				displaySettingsError = 'No program selected.';
				return;
			}
			const body = {
				program_id: prog.id,
				...authBody,
				enable_display_hid_barcode: displaySettingsProgramHid,
				enable_display_camera_scanner: displaySettingsCameraScanner,
				display_audio_muted: displaySettingsMuted,
				display_audio_volume: displaySettingsVolume,
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
				displaySettingsError = data.message || 'Authorization failed.';
				toaster.error({ title: data.message || 'Authorization failed.' });
				return;
			}
			if (res.status === 403) {
				displaySettingsError = data.message || 'Not authorized for this program.';
				toaster.error({ title: data.message || 'Not authorized for this program.' });
				return;
			}
			if (res.status === 429) {
				displaySettingsError = data.message || 'Too many attempts. Try again later.';
				toaster.error({ title: data.message || 'Too many attempts. Try again later.' });
				return;
			}
			if (!res.ok) {
				displaySettingsError = data.message || 'Failed to save.';
				toaster.error({ title: data.message || 'Failed to save.' });
				return;
			}
			toaster.success({ title: 'Display settings saved.' });
			displayAudioMuted = !!data.display_audio_muted;
			displayAudioVolume = Math.max(0, Math.min(1, Number(data.display_audio_volume ?? 1)));
			enableDisplayHidBarcode = !!data.enable_display_hid_barcode;
			if (typeof data.enable_display_camera_scanner === 'boolean') enableDisplayCameraScanner = data.enable_display_camera_scanner;
			setLocalAllowHidOnThisDevice('display', displaySettingsLocalAllowHid);
			setLocalPersistentHidOnThisDevice('display', displaySettingsLocalPersistentHid);
			localPersistentHid = displaySettingsLocalPersistentHid;
			setLocalAllowCameraOnThisDevice('display', displaySettingsLocalAllowCamera);
			localAllowCameraScanner = displaySettingsLocalAllowCamera;
			displaySettingsPin = '';
			displaySettingsQrScanToken = '';
			showDisplaySettingsModal = false;
		} finally {
			displaySettingsSaving = false;
		}
	}

	const DISPLAY_SETTINGS_REQUEST_QR_PREFIX = 'flexiqueue:display_settings_request:';

	async function createDisplaySettingsRequest() {
		const prog = effectiveCurrentProgram;
		if (!prog?.id || displaySettingsSaving) return;
		displaySettingsSaving = true;
		displaySettingsError = '';
		try {
			const body = {
				program_id: prog.id,
				display_audio_muted: displaySettingsMuted,
				display_audio_volume: displaySettingsVolume,
				enable_display_hid_barcode: displaySettingsProgramHid,
				enable_display_camera_scanner: displaySettingsCameraScanner,
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
				displaySettingsError = (data).message || 'Failed to create request.';
				toaster.error({ title: displaySettingsError });
				return;
			}
			displaySettingsRequestId = data.id;
			displaySettingsRequestToken = data.request_token;
			displaySettingsRequestState = 'waiting';
			const id = data.id;
			const token = data.request_token;
			displaySettingsPollIntervalId = setInterval(async () => {
				try {
					const r = await fetch(`/api/public/display-settings-requests/${id}?token=${encodeURIComponent(token)}`, { credentials: 'same-origin' });
					const d = await r.json().catch(() => ({}));
					if (d.status === 'approved') {
						if (displaySettingsPollIntervalId) clearInterval(displaySettingsPollIntervalId);
						displaySettingsPollIntervalId = null;
						displaySettingsRequestId = null;
						displaySettingsRequestToken = null;
						displaySettingsRequestState = 'idle';
						setLocalAllowHidOnThisDevice('display', displaySettingsLocalAllowHid);
						setLocalPersistentHidOnThisDevice('display', displaySettingsLocalPersistentHid);
						localPersistentHid = displaySettingsLocalPersistentHid;
						setLocalAllowCameraOnThisDevice('display', displaySettingsLocalAllowCamera);
						localAllowCameraScanner = displaySettingsLocalAllowCamera;
						toaster.success({ title: 'Settings applied.' });
						showDisplaySettingsModal = false;
						refreshBoardData();
					} else if (d.status === 'rejected' || d.status === 'cancelled') {
						if (displaySettingsPollIntervalId) clearInterval(displaySettingsPollIntervalId);
						displaySettingsPollIntervalId = null;
						displaySettingsRequestState = 'idle';
						displaySettingsRequestId = null;
						displaySettingsRequestToken = null;
						toaster.warning({ title: d.status === 'rejected' ? 'Request was rejected.' : 'Request was cancelled.' });
					}
				} catch {
					// ignore poll errors
				}
			}, 2000);
		} finally {
			displaySettingsSaving = false;
		}
	}

	function cancelDisplaySettingsRequest() {
		displaySettingsRequestState = 'idle';
		displaySettingsRequestId = null;
		displaySettingsRequestToken = null;
		if (displaySettingsPollIntervalId) {
			clearInterval(displaySettingsPollIntervalId);
			displaySettingsPollIntervalId = null;
		}
	}

	/** Cancel pending display-settings or unlock request on leave (avoid orphan entries). */
	async function cancelPendingRequestsOnLeave() {
		if (displaySettingsRequestId != null && displaySettingsRequestToken) {
			try {
				await fetch(`/api/public/display-settings-requests/${displaySettingsRequestId}/cancel`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'Accept': 'application/json' },
					credentials: 'include',
					body: JSON.stringify({ request_token: displaySettingsRequestToken }),
				});
			} catch {
				// ignore
			}
		}
		if (unlockRequestId != null && unlockRequestToken) {
			try {
				await fetch(`/api/public/device-unlock-requests/${unlockRequestId}/cancel`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'Accept': 'application/json' },
					credentials: 'include',
					body: JSON.stringify({ request_token: unlockRequestToken }),
				});
			} catch {
				// ignore
			}
		}
	}

	const DEVICE_UNLOCK_REQUEST_QR_PREFIX = 'flexiqueue:device_unlock_request:';

	async function createUnlockRequest() {
		if (!effectiveCurrentProgram?.id || !chooseUrl || unlockLoading) return;
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
				body: JSON.stringify({ program_id: effectiveCurrentProgram.id }),
			});
			const data = await res.json().catch(() => ({}));
			if (!res.ok) {
				toaster.error({ title: data.message || 'Failed to create unlock request.' });
				return;
			}
			unlockRequestId = data.id;
			unlockRequestToken = data.request_token;
			unlockRequestState = 'waiting';
			const id = data.id;
			const token = data.request_token;
			unlockPollIntervalId = setInterval(async () => {
				try {
					const r = await fetch(`/api/public/device-unlock-requests/${id}?token=${encodeURIComponent(token)}`, { credentials: 'include' });
					const d = await r.json().catch(() => ({}));
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
						const consumeData = await consumeRes.json().catch(() => ({}));
						if (consumeRes.ok && consumeData.redirect_url) {
							sessionStorage.removeItem('device_lock_redirect_url');
							router.visit(consumeData.redirect_url, { replace: true });
						} else {
							router.visit(chooseUrl, { replace: true });
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
			const data = await res.json().catch(() => ({}));
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

	let beforeUnloadHandler = $state(null);
	let footerPointerDownCleanup = $state(null);
	onDestroy(() => {
		cancelPendingRequestsOnLeave();
		if (footerScrollableTimeoutId != null) clearTimeout(footerScrollableTimeoutId);
		if (typeof document !== 'undefined' && footerPointerDownCleanup) footerPointerDownCleanup();
		if (typeof window !== 'undefined' && beforeUnloadHandler) {
			window.removeEventListener('beforeunload', beforeUnloadHandler);
		}
	});

	onMount(() => {
		localAllowCameraScanner = shouldAllowCameraScanner('display');
		localPersistentHid = getLocalPersistentHidOnThisDevice('display');
		beforeUnloadHandler = () => cancelPendingRequestsOnLeave();
		window.addEventListener('beforeunload', beforeUnloadHandler);
		document.addEventListener('pointerdown', handleFooterPointerDownOutside, true);
		footerPointerDownCleanup = () => document.removeEventListener('pointerdown', handleFooterPointerDownOutside, true);
	});

	async function openDisplaySettingsModal() {
		displaySettingsProgramHid = enableDisplayHidBarcode;
		displaySettingsCameraScanner = enableDisplayCameraScanner;
		displaySettingsMuted = displayAudioMuted;
		displaySettingsVolume = displayAudioVolume;
		displaySettingsLocalAllowHid = getLocalAllowHidOnThisDevice('display') === true;
		displaySettingsLocalPersistentHid = getLocalPersistentHidOnThisDevice('display');
		displaySettingsLocalAllowCamera = shouldAllowCameraScanner('display');
		displaySettingsAuthMode = 'pin';
		displaySettingsPin = '';
		displaySettingsQrScanToken = '';
		displaySettingsError = '';
		displaySettingsRequestId = null;
		displaySettingsRequestToken = null;
		displaySettingsRequestState = 'idle';
		if (displaySettingsPollIntervalId) {
			clearInterval(displaySettingsPollIntervalId);
			displaySettingsPollIntervalId = null;
		}
		showDisplaySettingsModal = true;
		try {
			const res = await fetch('/api/public/tts/voices', { credentials: 'same-origin' });
			const data = await res.json().catch(() => ({}));
			availableTtsVoices = Array.isArray(data.voices) ? data.voices : [];
		} catch {
			availableTtsVoices = [];
		}
	}

	/** Actions that actually change the displayed queue state — gate full reload on these only (per docs/necessary-fix.md). */
	const QUEUE_CHANGING_ACTIONS = new Set([
		'bind', 'call', 'serve', 'transfer', 'complete',
		'cancel', 'hold', 'resume', 'no_show', 'enqueue_back',
		'force_complete', 'override'
	]);

	/** A.5: Subscribe to program-scoped channels only (display.activity.{programId}, queue.{programId}). No legacy channels. */
	function setupEcho(programId) {
		if (typeof window === 'undefined' || !window.Echo) return () => {};
		if (programId == null) return () => {};
		const echo = window.Echo;
		const handler = (e) => {
			const item = {
				station_name: e.station_name ?? '—',
				message: e.message ?? '',
				alias: e.alias ?? '—',
				action_type: e.action_type ?? '',
				created_at: e.created_at ?? new Date().toISOString(),
			};
			activityFeed = [item, ...activityFeed].slice(0, 20);
			if (e.action_type === 'call') {
				playFullAnnouncement(
					createFullAnnouncementParams(e, {
						connectorPhrase,
						stationTtsByName,
						muted: displayAudioMuted,
						volume: displayAudioVolume,
						onFallback: (reason, text) => { console.warn?.('TTS fallback', reason, text); },
						onCompleteFailure: (reason, text) => { console.warn?.('TTS complete failure', reason, text); },
						repeatCount: displayTtsRepeatCount,
						repeatDelayMs: displayTtsRepeatDelayMs,
					})
				);
			}
			// Only reload if this action changes queue state (note, staff_availability, etc. — no reload)
			if (QUEUE_CHANGING_ACTIONS.has(e.action_type)) {
				refreshBoardData();
			}
		};
		const leaves = [];
		const programActivity = echo.channel(`display.activity.${programId}`);
		programActivity.listen('.station_activity', handler);
		programActivity.listen('.staff_availability', () => {
			router.reload({ only: ['staff_at_stations', 'staff_online'] });
		});
		programActivity.listen('.program_status', (e) => {
			programIsPaused = !!e.program_is_paused;
			if (typeof e.program_is_active === 'boolean') programIsActive = e.program_is_active;
		});
		programActivity.listen('.display_settings', (e) => {
			displayAudioMuted = !!e.display_audio_muted;
			displayAudioVolume = Math.max(0, Math.min(1, Number(e.display_audio_volume ?? 1)));
			if (typeof e.enable_display_hid_barcode === 'boolean') enableDisplayHidBarcode = e.enable_display_hid_barcode;
			if (typeof e.enable_display_camera_scanner === 'boolean') {
				enableDisplayCameraScanner = e.enable_display_camera_scanner;
				if (!e.enable_display_camera_scanner) showScanner = false;
			}
			if (typeof e.display_tts_repeat_count === 'number') displayTtsRepeatCount = Math.max(1, Math.min(3, e.display_tts_repeat_count));
			if (typeof e.display_tts_repeat_delay_ms === 'number') displayTtsRepeatDelayMs = Math.max(500, Math.min(10000, e.display_tts_repeat_delay_ms));
		});
		leaves.push(`display.activity.${programId}`);
		const programQueue = echo.channel(`queue.${programId}`);
		programQueue.listen('.now_serving', refreshBoardData);
		programQueue.listen('.queue_length', refreshBoardData);
		leaves.push(`queue.${programId}`);
		return () => {
			cancelCurrentAnnouncement();
			leaves.forEach((ch) => echo.leave(ch));
		};
	}

	onMount(() => {
		prepareDisplayTts((voices) => {
			availableTtsVoices = (voices || []).map((v) => ({ name: v.name, lang: v.lang || '' }));
		});
		const programId = effectiveCurrentProgram?.id ?? null;
		const teardown = setupEcho(programId);
		return () => {
			if (teardown) teardown();
		};
	});

	function formatActivityTime(iso) {
		try {
			const d = new Date(iso);
			const now = new Date();
			const secs = Math.floor((now.getTime() - d.getTime()) / 1000);
			if (secs < 60) return 'just now';
			if (secs < 3600) return `${Math.floor(secs / 60)}m ago`;
			return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
		} catch {
			return '';
		}
	}

	function closeScanner() {
		if (scanCountdownIntervalId != null) {
			clearInterval(scanCountdownIntervalId);
			scanCountdownIntervalId = null;
		}
		scanCountdown = 0;
		showScanner = false;
		if (localPersistentHid && shouldFocusHidInput(enableDisplayHidBarcode, 'display')) displayBarcodeInputEl?.focus();
	}

	function extendScannerCountdown() {
		const extra = Math.max(0, Number(display_scan_timeout_seconds) || 20);
		scanCountdown += extra;
	}

	/** Refocus hidden barcode input every 2s when scan modal is closed and persistent HID is on. */
	$effect(() => {
		if (showScanner || !localPersistentHid || !shouldFocusHidInput(enableDisplayHidBarcode, 'display')) return;
		const id = setInterval(() => {
			if (!showScanner && localPersistentHid && shouldFocusHidInput(enableDisplayHidBarcode, 'display')) displayBarcodeInputEl?.focus();
		}, 2000);
		return () => clearInterval(id);
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
			clearInterval(id);
			scanCountdownIntervalId = null;
		};
	});

	function handleQrScan(decodedText) {
		if (scanHandled) return;
		scanHandled = true;
		const raw = (typeof decodedText === 'string' ? decodedText : '').trim();
		// If QR contains full URL (e.g. .../display/status/SITE_ID/HASH or .../display/status/HASH), use pathname so site-scoped and legacy both work
		if (raw.includes('/display/status/')) {
			try {
				const pathname = new URL(raw, window.location.origin).pathname;
				showScanner = false;
				router.visit(pathname);
			} catch {
				const qrHash = raw.split('/').pop() ?? raw;
				if (qrHash) {
					showScanner = false;
					router.visit(`/display/status/${encodeURIComponent(qrHash)}`);
				}
			}
		} else if (raw) {
			showScanner = false;
			router.visit(`/display/status/${encodeURIComponent(raw)}`);
		}
	}

	function onDisplayBarcodeKeydown(e) {
		if (e.key !== 'Enter') return;
		const raw = displayBarcodeValue.trim();
		if (!raw) return;
		e.preventDefault();
		handleQrScan(raw);
		displayBarcodeValue = '';
		if (shouldFocusHidInput(enableDisplayHidBarcode, 'display')) displayBarcodeInputEl?.focus();
	}

	/** Status icon/dot class per 07-UI-UX-SPECS and StatusFooter/StationStatusTable. */
	function availabilityDotClass(status) {
		switch (status) {
			case 'available':
				return 'bg-success-500';
			case 'on_break':
				return 'bg-warning-500';
			default:
				return 'bg-surface-400';
		}
	}

	function availabilityLabel(status) {
		switch (status) {
			case 'available': return 'Available';
			case 'on_break': return 'On break';
			case 'away': return 'Away';
			default: return 'Offline';
		}
	}

	/** Max tokens to show per station in \"Currently waiting\" before collapsing into \"+N more\". */
	const MAX_VISIBLE_WAITING = 7;

	/** Flatten staff from all stations for profile bar (no duplicates by name). */
	const staffForBar = $derived.by(() => {
		const seen = new Set();
		const list = [];
		const rows = Array.isArray(staff_at_stations) ? staff_at_stations : [];
		for (const row of rows) {
			const staffList = Array.isArray(row?.staff) ? row.staff : [];
			for (const s of staffList) {
				const key = (s?.name ?? '') + (s?.availability_status ?? '');
				if (seen.has(key)) continue;
				seen.add(key);
				list.push({ ...s, station_name: row?.station_name });
			}
		}
		return list;
	});
</script>

<svelte:head>
	<title>Now Serving — FlexiQueue</title>
</svelte:head>

<DisplayLayout programName={program_name ?? (effectiveCurrentProgram?.name ?? null)} {date}>
	{#snippet children()}
	<div class="relative">
		{#if !showBoardContent}
			<!-- A.2.4: Program selector / empty state — per 07-UI-UX-SPECS empty-state pattern and PublicStart "not available" (neutral surface, no warning/error). -->
			<div class="flex flex-col gap-6 max-w-4xl mx-auto pb-28 px-3 sm:px-4">
				{#if program_not_found}
					<div
						role="status"
						class="rounded-container bg-surface-50 border border-surface-200 p-6 md:p-8 text-center shadow-sm"
						aria-label="Program not found"
					>
						<div class="bg-surface-100 p-4 rounded-full text-surface-500 inline-flex mb-4" aria-hidden="true">
							<AlertCircle class="w-8 h-8" />
						</div>
						<h2 class="text-xl font-bold text-surface-950 mb-2">Program not found</h2>
						<p class="text-surface-600 mb-4">The selected program is invalid or inactive. Choose a program below.</p>
					</div>
				{/if}
				{#if programs && programs.length > 0}
					<section
						role="status"
						class="flex flex-col gap-6"
						aria-label="Select a program"
					>
						<div class="text-center">
							<h1 class="text-xl font-bold text-surface-950 mb-1">Select a program</h1>
							<p class="text-surface-600 text-sm">Choose a program to view its display board.</p>
						</div>
						<ul class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
							{#each programs as prog (prog.id)}
								<li>
									<button
										type="button"
										class="card bg-surface-50 border border-surface-200 rounded-container shadow-sm w-full flex flex-col items-center justify-center p-6 min-h-[120px] touch-target-h text-center hover:border-primary-300 hover:bg-primary-50/50 focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 transition-colors"
										onclick={() => router.visit(`${displayBase}?program=${prog.id}`)}
									>
										<div class="bg-surface-100 p-3 rounded-full text-surface-500 mb-3" aria-hidden="true">
											<Monitor class="w-6 h-6" />
										</div>
										<span class="font-semibold text-surface-950">{prog.name}</span>
										<span class="text-xs text-surface-500 mt-1">View board</span>
									</button>
								</li>
							{/each}
						</ul>
					</section>
				{:else}
					<div
						role="status"
						class="rounded-container bg-surface-50 border border-surface-200 p-8 md:p-12 flex flex-col items-center justify-center text-center shadow-sm"
						aria-label="No active program"
					>
						<div class="bg-surface-100 p-4 rounded-full text-surface-400 mb-4" aria-hidden="true">
							<FolderOpen class="w-8 h-8" />
						</div>
						<h2 class="text-lg font-semibold text-surface-950 mb-2">No active program</h2>
						<p class="text-surface-600 max-w-sm mt-1">There are no programs available for the display board.</p>
					</div>
				{/if}
			</div>
		{:else}
		{#if !programIsActive}
			<div
				class="absolute inset-0 z-10 flex items-center justify-center bg-surface-950/80 rounded-container min-h-[280px]"
				aria-live="polite"
			>
				<div class="card bg-surface-50 border border-surface-200 rounded-container shadow-lg p-8 max-w-md mx-4 text-center">
					<p class="text-xl font-semibold text-surface-950">Program is not currently running.</p>
					<p class="text-surface-600 mt-2">Please check back later or scan the QR again.</p>
				</div>
			</div>
		{:else if programIsPaused}
			<div
				class="absolute inset-0 z-10 flex items-center justify-center bg-surface-950/80 rounded-container min-h-[280px]"
				aria-live="polite"
			>
				<div class="card bg-surface-50 border border-surface-200 rounded-container shadow-lg p-8 max-w-md mx-4 text-center">
					<p class="text-xl font-semibold text-surface-950">Program is paused</p>
					<p class="text-surface-600 mt-2">Service will resume shortly.</p>
				</div>
			</div>
		{/if}
		<div class="flex flex-col gap-6 max-w-4xl mx-auto pb-28 px-3 sm:px-4">
			{#if publicView}
				<div class="flex items-center justify-end gap-3 flex-wrap">
					<span class="text-sm text-surface-500 dark:text-slate-400 font-medium">View only</span>
					<ThemeToggle />
				</div>
			{/if}
			<!-- Station routing: how clients are sent to stations (per flexiqueue-syam). -->
			{#if program_name && station_selection_mode}
				{@const sel = station_selection_mode}
				<section
					class="rounded-container border border-surface-200 bg-surface-50 p-4"
					aria-label="How clients are routed to stations"
				>
					<h2 class="text-base font-bold text-surface-950 mb-1">Station routing</h2>
					<p class="text-lg font-semibold text-surface-900">
						{sel === 'fixed'
							? 'Fixed'
							: sel === 'shortest_queue'
								? 'Shortest queue'
								: sel === 'least_busy'
									? 'Least busy'
									: sel === 'round_robin'
										? 'Round robin'
										: sel === 'least_recently_served'
											? 'Least recently served'
											: 'Fixed'}
					</p>
					<p class="text-sm text-surface-600 mt-1">
						{sel === 'fixed'
							? 'Each station serves its assigned clients.'
							: sel === 'shortest_queue'
								? 'Clients are sent to the station with the fewest people waiting.'
								: sel === 'least_busy'
									? 'Clients are sent to the least busy station (fewer active services).'
									: sel === 'round_robin'
										? 'Stations take turns receiving new clients.'
										: sel === 'least_recently_served'
											? 'The station that served clients longest ago receives the next one.'
											: 'Each station serves its assigned clients.'}
					</p>
				</section>
			{/if}

			<!-- Scan section: HID barcode input and/or camera scanner CTA. Hidden in public (view-only) mode. -->
		{#if !publicView}
		<section>
			<div class="flex items-center justify-between gap-2 mb-3 flex-wrap">
				<h2 class="text-xl font-bold text-surface-950">CHECK YOUR STATUS</h2>
				<div class="flex items-center gap-2">
					{#if chooseUrl}
						<button
							type="button"
							class="btn preset-tonal text-sm touch-target-h"
							onclick={() => (showUnlockModal = true)}
						>
							Change device type
						</button>
					{/if}
				<button
					type="button"
					class="btn btn-icon preset-tonal shrink-0 touch-target"
					aria-label="Display settings"
					title="Settings"
					onclick={openDisplaySettingsModal}
				>
					<Settings class="w-5 h-5" />
				</button>
				</div>
			</div>
			{#if !enableDisplayHidBarcode && !effectiveAllowCameraScanner}
				<p class="text-sm text-surface-950/80 rounded-container border border-surface-200 bg-surface-50 p-4">
					Scanning is disabled. Use Settings to enable HID or camera scanner.
				</p>
			{:else}
				{#if enableDisplayHidBarcode}
					<input
						type="text"
						autocomplete="off"
						inputmode={shouldUseInputModeNone(enableDisplayHidBarcode, 'display') ? 'none' : 'text'}
						aria-label="Barcode scanner input; scan with hardware scanner or type and press Enter"
						class="sr-only"
						bind:value={displayBarcodeValue}
						bind:this={displayBarcodeInputEl}
						onkeydown={onDisplayBarcodeKeydown}
					/>
				{/if}
				<div
					class="flex items-center gap-3 rounded-container border-2 border-primary-500/30 bg-primary-500/5 p-4 animate-pulse"
					role="region"
					aria-label="Scan to check status"
				>
					<p class="flex-1 text-base font-medium text-surface-950">
						Scan your QR or barcode to check status
					</p>
					{#if effectiveAllowCameraScanner}
						<button
							type="button"
							class="btn btn-icon preset-filled-primary-500 shrink-0 touch-target"
							aria-label="Open camera to scan QR code"
							title="Tap to scan with device camera"
							onclick={() => {
								showScanner = true;
								scanHandled = false;
							}}
						>
							<Camera class="w-6 h-6" />
						</button>
					{/if}
					{#if shouldFocusHidInput(enableDisplayHidBarcode, 'display')}
						<button
							type="button"
							class="btn btn-sm preset-tonal shrink-0 touch-target-h"
							aria-label="Open scan modal for barcode"
							title="Scan with barcode scanner"
							onclick={() => {
								showScanner = true;
								scanHandled = false;
							}}
						>
							Scan with barcode
						</button>
					{/if}
				</div>
			{/if}
		</section>
		{/if}

		<!-- Scan modal: same HID + camera layout as mobile footer and triage (ScanModal). -->
		<ScanModal
			open={showScanner}
			title="Scan QR via Camera"
			onClose={closeScanner}
			allowHid={shouldFocusHidInput(enableDisplayHidBarcode, 'display')}
			allowCamera={!!effectiveAllowCameraScanner}
			onScan={handleQrScan}
			wide={true}
			inputModeNone={shouldUseInputModeNone(enableDisplayHidBarcode, 'display')}
		>
			{#snippet extra()}
				{#if scanCountdown > 0}
					<div class="flex flex-wrap items-center justify-center gap-2">
						<p class="text-sm text-surface-600" aria-live="polite">Closing in {scanCountdown}s</p>
						<button
							type="button"
							class="btn preset-tonal text-sm py-1.5 px-3"
							onclick={extendScannerCountdown}
						>
							Extend (+{Math.max(0, Number(display_scan_timeout_seconds) || 20)}s)
						</button>
					</div>
				{/if}
			{/snippet}
		</ScanModal>

		<!-- Display settings modal: view/edit anytime; apply only on Save with PIN/QR -->
		<Modal open={showDisplaySettingsModal} title="Display settings" onClose={() => { cancelDisplaySettingsRequest(); showDisplaySettingsModal = false; }}>
			{#snippet children()}
				<div class="flex flex-col gap-6">
					<p class="text-sm text-surface-950/70">You can view and change settings below. Changes are applied only when you save; saving requires PIN or QR (admin scan).</p>
					<div class="flex flex-col gap-4">
						<h3 class="text-sm font-semibold text-surface-950">Program settings</h3>
						<p class="text-xs text-surface-950/60">Apply to all displays.</p>
						<label class="flex items-center gap-2 cursor-pointer">
							<input type="checkbox" class="checkbox" bind:checked={displaySettingsProgramHid} disabled={displaySettingsSaving} />
							<span class="text-sm text-surface-950">Allow HID barcode scanner</span>
						</label>
						<label class="flex items-center gap-2 cursor-pointer">
							<input type="checkbox" class="checkbox" bind:checked={displaySettingsCameraScanner} disabled={displaySettingsSaving} />
							<span class="text-sm text-surface-950">Allow camera/QR scanner</span>
						</label>
						<label class="flex items-center gap-2 cursor-pointer">
							<input type="checkbox" class="checkbox" bind:checked={displaySettingsMuted} disabled={displaySettingsSaving} />
							<span class="text-sm text-surface-950">Mute</span>
						</label>
						<label class="flex flex-col gap-2">
							<span class="text-sm font-medium text-surface-950">Volume</span>
							<input type="range" min="0" max="1" step="0.1" class="range range-sm w-full max-w-xs" bind:value={displaySettingsVolume} disabled={displaySettingsSaving || displaySettingsMuted} />
						</label>
					</div>
					<div class="border-t border-surface-200 pt-4 flex flex-col gap-2">
						<h3 class="text-sm font-semibold text-surface-950">This device</h3>
						<p class="text-xs text-surface-950/60">On this device only — not saved to server.</p>
						<label class="flex items-center gap-2 cursor-pointer">
							<input type="checkbox" class="checkbox" bind:checked={displaySettingsLocalAllowHid} />
							<span class="text-sm text-surface-950">Allow HID scanner on this device</span>
						</label>
						{#if displaySettingsLocalAllowHid}
							<label class="flex items-center gap-2 cursor-pointer pl-6">
								<input type="checkbox" class="checkbox" bind:checked={displaySettingsLocalPersistentHid} />
								<span class="text-sm text-surface-950">Keep HID ready when scan modal is closed</span>
							</label>
						{/if}
						<label class="flex items-center gap-2 cursor-pointer">
							<input type="checkbox" class="checkbox" bind:checked={displaySettingsLocalAllowCamera} />
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
						<AuthChoiceButtons includeRequest={true} disabled={displaySettingsSaving || displaySettingsRequestState === 'waiting'} bind:mode={displaySettingsAuthMode} />
						{#if displaySettingsRequestState === 'waiting' && displaySettingsRequestId != null && displaySettingsRequestToken != null}
							<div class="flex flex-col items-center gap-3 py-4">
								<p class="text-sm font-medium text-surface-950">Waiting for approval…</p>
								<p class="text-xs text-surface-950/60 text-center">Ask the program supervisor or admin to scan this QR on the Track overrides page.</p>
								<img class="rounded-container border border-surface-200 bg-white p-2" alt="QR for supervisor to scan" width="200" height="200" src={`https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(DISPLAY_SETTINGS_REQUEST_QR_PREFIX + displaySettingsRequestId + ':' + displaySettingsRequestToken)}`} />
								<button type="button" class="btn preset-tonal btn-sm touch-target-h" onclick={cancelDisplaySettingsRequest}>Cancel request</button>
							</div>
						{:else if displaySettingsAuthMode === 'request'}
							<button type="button" class="btn preset-filled-primary-500" onclick={createDisplaySettingsRequest} disabled={displaySettingsSaving || !effectiveCurrentProgram?.id}>
								{displaySettingsSaving ? 'Creating…' : 'Show QR for supervisor to scan'}
							</button>
						{:else}
							<PinOrQrInput bind:this={displayPinOrQrRef} disabled={displaySettingsSaving} mode={displaySettingsAuthMode} bind:pin={displaySettingsPin} bind:qrScanToken={displaySettingsQrScanToken} />
						{/if}
						{#if displaySettingsError}
							<p id="display-settings-pin-error" class="text-sm text-error-600">{displaySettingsError}</p>
						{/if}
					</div>
					<div class="flex flex-wrap gap-2 justify-end pt-2">
						<button type="button" class="btn preset-tonal" onclick={() => { cancelDisplaySettingsRequest(); showDisplaySettingsModal = false; }} disabled={displaySettingsSaving}>Cancel</button>
						{#if displaySettingsAuthMode === 'request'}
							<!-- Apply via QR request above -->
						{:else}
							<button type="button" class="btn preset-filled-primary-500" onclick={saveDisplaySettings} disabled={displaySettingsSaving}>{displaySettingsSaving ? 'Saving…' : 'Save'}</button>
						{/if}
					</div>
				</div>
			{/snippet}
		</Modal>

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

		<!-- Now Serving: mobile horizontal scroll with snap; desktop grid -->
		<section>
			<div class="flex flex-wrap items-center justify-between gap-2 mb-3">
				<h2 class="text-xl font-bold text-surface-950">NOW SERVING</h2>
				{#if staff_online != null && staff_online >= 0}
					<span class="text-xs text-surface-950/60" aria-label="Staff available">{staff_online} staff available</span>
				{/if}
			</div>
			{#if now_serving.length > 0}
				<div class="flex flex-nowrap overflow-x-auto gap-3 pb-2 snap-x snap-mandatory md:grid md:grid-cols-2 md:lg:grid-cols-3 md:overflow-visible md:pb-0">
					{#each now_serving as entry}
						<div class="card bg-surface-50 border border-surface-200 rounded-container shadow-sm snap-start shrink-0 w-[min(100%,280px)] md:w-auto">
							<div class="p-4 text-center">
								<div class="text-4xl font-bold text-primary-500">{entry.alias}</div>
								<div class="text-sm text-surface-950/80">{entry.station_name}</div>
								<div class="flex justify-center flex-wrap gap-1 mt-1">
									{#if entry.status === 'called'}
										<span class="text-xs px-2 py-0.5 rounded preset-filled-warning-500">Calling</span>
									{/if}
									{#if entry.process_name}
										<span class="text-xs px-2 py-0.5 rounded preset-filled-primary-500/20 text-primary-700">{entry.process_name}</span>
									{/if}
									<span class="text-xs px-2 py-0.5 rounded preset-tonal">{entry.track}</span>
								</div>
							</div>
						</div>
					{/each}
				</div>
			{:else}
				<div class="card bg-surface-50 border border-surface-200 rounded-container">
					<div class="p-4 py-8 text-center text-surface-950/70">
						No one is being served right now.
					</div>
				</div>
			{/if}
		</section>

		<!-- Currently Waiting: columns per station, queue in order (per plan) -->
		<section>
			<h2 class="text-xl font-bold text-surface-950 mb-3">CURRENTLY WAITING</h2>
			{#if waiting_by_station.length > 0}
				<!-- Mobile: single-row horizontal scroll with snap; Desktop: multi-column grid -->
				<div class="flex flex-nowrap overflow-x-auto gap-4 pb-2 snap-x snap-mandatory -mx-3 px-3 sm:mx-0 sm:px-0 sm:block sm:overflow-visible sm:pb-0">
					<!-- Desktop/grid wrapper -->
					<div class="hidden sm:grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 w-full">
						{#each waiting_by_station as row (row.station_name)}
							<div class="card bg-surface-50 border border-surface-200 rounded-container shadow-sm flex flex-col">
								<div class="p-4 py-3 shrink-0">
									<h3 class="font-semibold text-surface-950">{row.station_name}</h3>
									<div class="flex flex-wrap items-center gap-2 mt-1">
										{#if row.serving_count != null && row.client_capacity != null}
											<span class="text-xs px-2 py-0.5 rounded preset-filled-primary-500"
												>{row.serving_count}/{row.client_capacity} serving</span
											>
										{/if}
										<span class="text-surface-950/80 text-sm">
											{row.count}
											{row.count === 1 ? 'client' : 'clients'} waiting
										</span>
									</div>
								</div>
								{#if row.waiting_clients?.length > 0}
									<ul class="px-4 pb-4 space-y-1 text-sm text-surface-950/90" aria-label="Queue order for {row.station_name}">
										{#each row.waiting_clients.slice(0, MAX_VISIBLE_WAITING) as client (client.alias)}
											<li>
												<span class="font-mono font-medium">{client.alias}</span>
												{#if client.process_name}
													<span class="text-surface-950/70 ml-1">— {client.process_name}</span>
												{/if}
											</li>
										{/each}
										{#if row.waiting_clients.length > MAX_VISIBLE_WAITING}
											<li class="text-xs text-surface-950/60">
												+{row.waiting_clients.length - MAX_VISIBLE_WAITING} more
											</li>
										{/if}
									</ul>
								{/if}
							</div>
						{/each}
					</div>

					<!-- Mobile: horizontally scrollable cards with snap (same treatment as Now Serving) -->
					<div class="flex flex-nowrap sm:hidden gap-4">
						{#each waiting_by_station as row (row.station_name)}
							<div class="card bg-surface-50 border border-surface-200 rounded-container shadow-sm flex flex-col shrink-0 w-[min(100%,280px)] snap-start">
								<div class="p-4 py-3 shrink-0">
									<h3 class="font-semibold text-surface-950 truncate">{row.station_name}</h3>
									<div class="flex flex-wrap items-center gap-2 mt-1">
										{#if row.serving_count != null && row.client_capacity != null}
											<span class="text-xs px-2 py-0.5 rounded preset-filled-primary-500 whitespace-nowrap"
												>{row.serving_count}/{row.client_capacity} serving</span
											>
										{/if}
										<span class="text-surface-950/80 text-sm whitespace-nowrap">
											{row.count}
											{row.count === 1 ? 'client' : 'clients'} waiting
										</span>
									</div>
								</div>
								{#if row.waiting_clients?.length > 0}
									<ul class="px-4 pb-4 space-y-1 text-sm text-surface-950/90" aria-label="Queue order for {row.station_name}">
										{#each row.waiting_clients.slice(0, MAX_VISIBLE_WAITING) as client (client.alias)}
											<li>
												<span class="font-mono font-medium">{client.alias}</span>
												{#if client.process_name}
													<span class="text-surface-950/70 ml-1">— {client.process_name}</span>
												{/if}
											</li>
										{/each}
										{#if row.waiting_clients.length > MAX_VISIBLE_WAITING}
											<li class="text-xs text-surface-950/60">
												+{row.waiting_clients.length - MAX_VISIBLE_WAITING} more
											</li>
										{/if}
									</ul>
								{/if}
							</div>
						{/each}
					</div>
				</div>
				<p class="mt-2 text-sm text-surface-950/70">
					Total in queue: <strong>{total_in_queue}</strong>
				</p>
			{:else}
				<div class="card bg-surface-50 border border-surface-200 rounded-container">
					<div class="p-4 py-6 text-center text-surface-950/70">
						No one is currently waiting.
					</div>
				</div>
			{/if}
		</section>

		<!-- Station activity (real-time): max 20 items, scroll to see all (~5 visible). -->
		<section>
			<h2 class="text-xl font-bold text-surface-950 mb-3">RECENT ACTIVITY</h2>
			{#if activityFeed.length > 0}
				<div class="card bg-surface-50 border border-surface-200 rounded-container overflow-hidden">
					<ul class="divide-y divide-surface-200 max-h-[12rem] overflow-y-auto" aria-label="Recent activity">
						{#each visibleActivity as item, i (String(i) + (item?.created_at ?? '') + (item?.alias ?? '') + (item?.station_name ?? ''))}
							<li class="px-4 py-2 flex justify-between items-center gap-2">
								<span class="text-surface-950/90">
									<span class="font-semibold text-surface-950">{item?.station_name ?? '—'}:</span> {item?.message ?? ''}
								</span>
								<span class="text-xs text-surface-950/60 shrink-0">{formatActivityTime(item?.created_at)}</span>
							</li>
						{/each}
					</ul>
				</div>
			{:else}
				<div class="card bg-surface-50 border border-surface-200 rounded-container">
					<div class="p-4 py-6 text-center text-surface-950/70">
						No recent activity.
					</div>
				</div>
			{/if}
		</section>
		</div>
		{/if}
	</div>

	<!-- Fixed footer: staff and availability only when a program is selected. -->
	{#if showBoardContent}
	<footer
		bind:this={footerStaffRef}
		tabindex="-1"
		class="display-footer fixed bottom-0 left-0 right-0 z-30 px-4 py-3 bg-surface-800 text-surface-100 shadow-[0_-4px_12px_rgba(0,0,0,0.08)]"
		aria-label="Staff on duty"
		onfocusout={handleFooterFocusOut}
	>
		<div class="flex items-center gap-4 min-w-0">
			<span class="text-xs font-semibold uppercase tracking-wider text-surface-300 shrink-0 self-center">Staff on duty</span>
			{#if staffForBar.length > 0}
				<!-- Two modes: marquee (default) or scrollable. Click → scrollable; after idle → back to marquee with opacity transition. Min-height so absolute children have a visible area (container has no in-flow content). -->
				<div class="display-footer__marquee flex-1 min-w-0 min-h-10 relative" aria-label="Staff availability">
					<!-- Marquee layer: auto-scroll; click switches to scrollable -->
					<div
						class="absolute inset-0 overflow-hidden transition-opacity duration-300 {footerStaffMode === 'marquee' ? 'opacity-100' : 'opacity-0 pointer-events-none'}"
						role="button"
						tabindex="0"
						aria-label="Staff on duty — tap to scroll manually"
						onclick={setFooterToScrollable}
						onkeydown={(e) => e.key === 'Enter' && setFooterToScrollable()}
					>
						<div class="fq-marquee-track display-footer__marquee-inner flex items-center gap-3 h-full" style="animation-duration: 25s; width: max-content;">
							<div class="flex items-center gap-3 shrink-0">
								{#each staffForBar as s ((s.name ?? '') + (s.station_name ?? '') + (s.availability_status ?? ''))}
									<div
										class="display-footer__chip inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-surface-700/80 text-surface-100 shrink-0"
										title="{s.name} — {availabilityLabel(s.availability_status)}"
									>
										<UserAvatar user={s} size="sm" />
										<span
											class="w-2 h-2 rounded-full shrink-0 {availabilityDotClass(s.availability_status)}"
											aria-label="{availabilityLabel(s.availability_status)}"
										></span>
										<span class="text-sm max-w-[6rem] truncate">{s.name}</span>
									</div>
								{/each}
							</div>
							<span class="display-footer__marquee-gap shrink-0 w-8" aria-hidden="true"></span>
							<div class="flex items-center gap-3 shrink-0">
								{#each staffForBar as s ((s.name ?? '') + (s.station_name ?? '') + (s.availability_status ?? ''))}
									<div
										class="display-footer__chip inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-surface-700/80 text-surface-100 shrink-0"
										title="{s.name} — {availabilityLabel(s.availability_status)}"
									>
										<UserAvatar user={s} size="sm" />
										<span
											class="w-2 h-2 rounded-full shrink-0 {availabilityDotClass(s.availability_status)}"
											aria-label="{availabilityLabel(s.availability_status)}"
										></span>
										<span class="text-sm max-w-[6rem] truncate">{s.name}</span>
									</div>
								{/each}
							</div>
							<span class="display-footer__marquee-gap shrink-0 w-8" aria-hidden="true"></span>
						</div>
					</div>
					<!-- Scrollable layer: ScrollBooster drag-to-scroll; scrollbar hidden; idle/blur/click-outside returns to marquee -->
					<div
						role="region"
						class="display-footer__scrollable-bar absolute inset-0 overflow-x-auto overflow-y-hidden flex items-center transition-opacity duration-300 {footerStaffMode === 'scrollable' ? 'opacity-100' : 'opacity-0 pointer-events-none'}"
						aria-label="Staff on duty — scroll to browse; stops scrolling after a moment"
						use:scrollBooster
						onpointerdown={resetFooterScrollableIdle}
						onscroll={resetFooterScrollableIdle}
					>
						<div class="flex items-center gap-3 shrink-0 w-max px-px">
							{#each staffForBar as s ((s.name ?? '') + (s.station_name ?? '') + (s.availability_status ?? ''))}
								<div
									class="display-footer__chip inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-surface-700/80 text-surface-100 shrink-0"
									title="{s.name} — {availabilityLabel(s.availability_status)}"
								>
									<UserAvatar user={s} size="sm" />
									<span
										class="w-2 h-2 rounded-full shrink-0 {availabilityDotClass(s.availability_status)}"
										aria-label="{availabilityLabel(s.availability_status)}"
									></span>
									<span class="text-sm max-w-[6rem] truncate">{s.name}</span>
								</div>
							{/each}
						</div>
					</div>
				</div>
			{:else}
				<span class="text-xs text-surface-400">No staff assigned</span>
			{/if}
		</div>
	</footer>
	{/if}
	{/snippet}
</DisplayLayout>
