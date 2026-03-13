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
	import { onMount } from 'svelte';
	import { router, usePage } from '@inertiajs/svelte';
	import DisplayLayout from '../../Layouts/DisplayLayout.svelte';
	import Modal from '../../Components/Modal.svelte';
	import QrScanner from '../../Components/QrScanner.svelte';
	import AuthChoiceButtons from '../../Components/AuthChoiceButtons.svelte';
	import PinOrQrInput from '../../Components/PinOrQrInput.svelte';
	import UserAvatar from '../../Components/UserAvatar.svelte';
	import ThemeToggle from '../../Components/ThemeToggle.svelte';
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
	date = '',
	now_serving = [],
	waiting_by_station = [],
	total_in_queue = 0,
	station_activity = [],
	staff_at_stations = [],
	staff_online = 0,
	display_scan_timeout_seconds = 20,
	program_is_paused = false,
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
} = $props();

/** Effective program: currentProgram with fallback to program for transition. */
const effectiveCurrentProgram = $derived(currentProgram ?? program);

/** True when board content (now serving, queue, etc.) should be shown; false when showing program selector or "no program". */
const showBoardContent = $derived(effectiveCurrentProgram != null && !program_not_found);

/** Synced from prop + .program_status; when true, show "Program is paused" overlay (real-time). */
let programIsPaused = $state(false);
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

	onMount(() => {
		localAllowCameraScanner = shouldAllowCameraScanner('display');
	});

	$effect(() => {
		// If device-local camera scanning is disabled while open, close immediately.
		if (!localAllowCameraScanner) showScanner = false;
	});

	/** Open camera modal when URL has ?scan=1 only if camera scanner is enabled. */
	$effect(() => {
		const pageData = get(page);
		const url = typeof pageData?.url === 'string' ? pageData.url : (typeof window !== 'undefined' ? window.location.href : '');
		try {
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
			// Ignore URL parse errors
		}
	});

	let showScanner = $state(false);
	/** Latch: ignore repeated onScan callbacks after first successful scan (per gotchas — stops flicker / unresponsive OK GOT IT). */
	let scanHandled = $state(false);
	/** Per flexiqueue-87p: countdown when scanner open; 0 = no auto-close. */
	let scanCountdown = $state(0);
	let scanCountdownIntervalId = $state(null);
	/** Hidden input for HID barcode scanner on display; refocus every 2s when camera modal is closed. */
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
	/** 'auth' = PIN/QR step; 'settings' = program + device toggles. */
	let displaySettingsStep = $state('auth');
	let displaySettingsAuthPayload = $state(null);
	let displaySettingsProgramHid = $state(true);
	let displaySettingsCameraScanner = $state(true);
	let displaySettingsMuted = $state(false);
	let displaySettingsVolume = $state(1);
	let displaySettingsLocalAllowHid = $state(false);
	let displaySettingsLocalAllowCamera = $state(true);
	let availableTtsVoices = $state([]);
	$effect(() => {
		activityFeed = [...(station_activity ?? [])];
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
		const authBody = displaySettingsAuthPayload;
		if (!authBody) {
			displaySettingsError = 'Authorize with PIN or QR first.';
			displaySettingsStep = 'auth';
			return;
		}
		displaySettingsSaving = true;
		try {
			const body = {
				...authBody, // { pin } or { qr_scan_token }
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
				displaySettingsStep = 'auth';
				displaySettingsAuthPayload = null;
				return;
			}
			if (res.status === 403) {
				displaySettingsError = data.message || 'Not authorized for this program.';
				toaster.error({ title: data.message || 'Not authorized for this program.' });
				displaySettingsStep = 'auth';
				displaySettingsAuthPayload = null;
				return;
			}
			if (res.status === 429) {
				displaySettingsError = data.message || 'Too many attempts. Try again later.';
				toaster.error({ title: data.message || 'Too many attempts. Try again later.' });
				displaySettingsStep = 'auth';
				displaySettingsAuthPayload = null;
				return;
			}
			if (!res.ok) {
				displaySettingsError = data.message || 'Failed to save.';
				toaster.error({ title: data.message || 'Failed to save.' });
				displaySettingsStep = 'auth';
				displaySettingsAuthPayload = null;
				return;
			}
			toaster.success({ title: 'Display settings saved.' });
			displayAudioMuted = !!data.display_audio_muted;
			displayAudioVolume = Math.max(0, Math.min(1, Number(data.display_audio_volume ?? 1)));
			enableDisplayHidBarcode = !!data.enable_display_hid_barcode;
			if (typeof data.enable_display_camera_scanner === 'boolean') enableDisplayCameraScanner = data.enable_display_camera_scanner;
			// Apply device-local settings only after successful, authenticated save.
			setLocalAllowHidOnThisDevice('display', displaySettingsLocalAllowHid);
			setLocalAllowCameraOnThisDevice('display', displaySettingsLocalAllowCamera);
			localAllowCameraScanner = displaySettingsLocalAllowCamera;
			displaySettingsPin = '';
			displaySettingsQrScanToken = '';
			displaySettingsAuthPayload = null;
			displaySettingsStep = 'auth';
			showDisplaySettingsModal = false;
		} finally {
			displaySettingsSaving = false;
		}
	}

	async function openDisplaySettingsModal() {
		displaySettingsProgramHid = enableDisplayHidBarcode;
		displaySettingsCameraScanner = enableDisplayCameraScanner;
		displaySettingsMuted = displayAudioMuted;
		displaySettingsVolume = displayAudioVolume;
		displaySettingsLocalAllowHid = getLocalAllowHidOnThisDevice('display') === true;
		displaySettingsLocalAllowCamera = shouldAllowCameraScanner('display');
		displaySettingsAuthMode = 'pin';
		displaySettingsPin = '';
		displaySettingsQrScanToken = '';
		displaySettingsError = '';
		displaySettingsStep = 'auth';
		displaySettingsAuthPayload = null;
		showDisplaySettingsModal = true;
		try {
			const res = await fetch('/api/public/tts/voices', { credentials: 'same-origin' });
			const data = await res.json().catch(() => ({}));
			availableTtsVoices = Array.isArray(data.voices) ? data.voices : [];
		} catch {
			availableTtsVoices = [];
		}
	}

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
			refreshBoardData();
		};
		const leaves = [];
		const programActivity = echo.channel(`display.activity.${programId}`);
		programActivity.listen('.station_activity', handler);
		programActivity.listen('.staff_availability', () => {
			router.reload({ only: ['staff_at_stations', 'staff_online'] });
		});
		programActivity.listen('.program_status', (e) => {
			programIsPaused = !!e.program_is_paused;
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
		if (shouldFocusHidInput(enableDisplayHidBarcode, 'display')) displayBarcodeInputEl?.focus();
	}

	/** Add one full timeout period to the scanner modal countdown (extension time from program settings). */
	function extendScannerCountdown() {
		const extra = Math.max(0, Number(display_scan_timeout_seconds) || 20);
		scanCountdown += extra;
	}

	/** Refocus hidden barcode input every 2s when camera modal is closed. Both program and device-local must allow (per plan). */
	$effect(() => {
		if (showScanner || !shouldFocusHidInput(enableDisplayHidBarcode, 'display')) return;
		const id = setInterval(() => {
			if (shouldFocusHidInput(enableDisplayHidBarcode, 'display')) displayBarcodeInputEl?.focus();
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
		const raw = decodedText.trim();
		// If QR contains a URL path (e.g. .../display/status/HASH), use the last segment as qr_hash
		const qrHash = raw.includes('/') ? raw.split('/').pop() ?? raw : raw;
		if (qrHash) {
			showScanner = false;
			router.visit(`/display/status/${encodeURIComponent(qrHash)}`);
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
		for (const row of staff_at_stations ?? []) {
			for (const s of row.staff ?? []) {
				const key = (s.name ?? '') + (s.availability_status ?? '');
				if (seen.has(key)) continue;
				seen.add(key);
				list.push({ ...s, station_name: row.station_name });
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
			<div class="flex flex-col gap-6 max-w-4xl mx-auto pb-28 px-4">
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
										onclick={() => router.visit(`/display?program=${prog.id}`)}
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
		{#if programIsPaused}
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
		<div class="flex flex-col gap-6 max-w-4xl mx-auto pb-28">
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

			<!-- Scan section: HID barcode input and/or camera scanner CTA. Section always visible for Settings. -->
		<section>
			<div class="flex items-center justify-between gap-2 mb-3 flex-wrap">
				<h2 class="text-xl font-bold text-surface-950">CHECK YOUR STATUS</h2>
				<div class="flex items-center gap-2">
					{#if programs && programs.length > 1}
						<button
							type="button"
							class="btn preset-tonal text-sm touch-target-h"
							onclick={() => router.visit('/display')}
						>
							Change program
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
				</div>
			{/if}
		</section>

		<!-- Camera scan modal: camera-only QrScanner, countdown, Cancel -->
		<Modal open={showScanner} title="Scan QR via Device" onClose={closeScanner} wide={true}>
			{#snippet children()}
				<div class="flex flex-col gap-3 w-full min-w-[20rem] mx-auto">
					{#if showScanner}
						<QrScanner active={showScanner} cameraOnly={true} onScan={handleQrScan} />
					{/if}
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
					<button
						type="button"
						class="w-full py-3 text-base font-semibold rounded-container border-2 border-surface-300 bg-surface-50 text-surface-950 shadow-md hover:bg-surface-200 focus:ring-2 focus:ring-offset-2 focus:ring-surface-400"
						onclick={closeScanner}
					>
						Cancel
					</button>
				</div>
			{/snippet}
		</Modal>

		<!-- Display settings modal: PIN + program HID/volume + device-local (per plan) -->
		<Modal open={showDisplaySettingsModal} title="Display settings" onClose={() => (showDisplaySettingsModal = false)}>
			{#snippet children()}
				<div class="flex flex-col gap-6">
					<p class="text-sm text-surface-950/70">Changes to program settings require supervisor or admin authorization.</p>
					<div class="flex flex-col gap-3">
						<div class="flex flex-col gap-2">
							<div class="label"><span class="label-text">Authorize with</span></div>
							<AuthChoiceButtons
								includeRequest={false}
								disabled={displaySettingsSaving}
								bind:mode={displaySettingsAuthMode}
							/>
						</div>
						<PinOrQrInput
							bind:this={displayPinOrQrRef}
							disabled={displaySettingsSaving}
							mode={displaySettingsAuthMode}
							bind:pin={displaySettingsPin}
							bind:qrScanToken={displaySettingsQrScanToken}
						/>
						{#if displaySettingsError}
							<p id="display-settings-pin-error" class="text-sm text-error-600">{displaySettingsError}</p>
						{/if}
					</div>
					{#if displaySettingsStep === 'auth'}
						<div class="flex flex-wrap gap-2 justify-end pt-1">
							<button
								type="button"
								class="btn preset-filled-primary-500"
								onclick={() => {
									displaySettingsError = '';
									const authBody = displayPinOrQrRef?.buildPinOrQrPayload?.() ?? null;
									if (!authBody) {
										displaySettingsError =
											displaySettingsAuthMode === 'pin'
												? 'Enter a 6-digit PIN.'
												: 'Scan QR first.';
										return;
									}
									displaySettingsAuthPayload = authBody;
									displaySettingsStep = 'settings';
								}}
								disabled={displaySettingsSaving}
							>
								Continue
							</button>
						</div>
					{:else}
					<div class="flex flex-col gap-4">
						<h3 class="text-sm font-semibold text-surface-950">Program settings</h3>
						<p class="text-xs text-surface-950/60">Apply to all displays.</p>
						<label class="flex items-center gap-2 cursor-pointer">
							<input
								type="checkbox"
								class="checkbox"
								bind:checked={displaySettingsProgramHid}
								disabled={displaySettingsSaving}
							/>
							<span class="text-sm text-surface-950">Allow HID barcode scanner</span>
						</label>
						<label class="flex items-center gap-2 cursor-pointer">
							<input
								type="checkbox"
								class="checkbox"
								bind:checked={displaySettingsCameraScanner}
								disabled={displaySettingsSaving}
							/>
							<span class="text-sm text-surface-950">Allow camera/QR scanner</span>
						</label>
						<label class="flex items-center gap-2 cursor-pointer">
							<input
								type="checkbox"
								class="checkbox"
								bind:checked={displaySettingsMuted}
								disabled={displaySettingsSaving}
							/>
							<span class="text-sm text-surface-950">Mute</span>
						</label>
						<label class="flex flex-col gap-2">
							<span class="text-sm font-medium text-surface-950">Volume</span>
							<input
								type="range"
								min="0"
								max="1"
								step="0.1"
								class="range range-sm w-full max-w-xs"
								bind:value={displaySettingsVolume}
								disabled={displaySettingsSaving || displaySettingsMuted}
							/>
						</label>
						<!-- TTS source/voice are now global; display uses pre-generated/server/browser automatically. -->
					</div>
					<div class="border-t border-surface-200 pt-4 flex flex-col gap-2">
						<h3 class="text-sm font-semibold text-surface-950">This device</h3>
						<p class="text-xs text-surface-950/60">On this device only — not saved to server.</p>
						<label class="flex items-center gap-2 cursor-pointer">
							<input
								type="checkbox"
								class="checkbox"
								bind:checked={displaySettingsLocalAllowHid}
							/>
							<span class="text-sm text-surface-950">Allow HID scanner on this device</span>
						</label>
						<label class="flex items-center gap-2 cursor-pointer">
							<input
								type="checkbox"
								class="checkbox"
								bind:checked={displaySettingsLocalAllowCamera}
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
							onclick={() => (showDisplaySettingsModal = false)}
							disabled={displaySettingsSaving}
						>
							Cancel
						</button>
						<button
							type="button"
							class="btn preset-filled-primary-500"
							onclick={saveDisplaySettings}
							disabled={displaySettingsSaving}
						>
							{displaySettingsSaving ? 'Saving…' : 'Save'}
						</button>
					</div>
					{/if}
				</div>
			{/snippet}
		</Modal>

		<!-- Now Serving -->
		<section>
			<div class="flex flex-wrap items-center justify-between gap-2 mb-3">
				<h2 class="text-xl font-bold text-surface-950">NOW SERVING</h2>
				{#if staff_online != null && staff_online >= 0}
					<span class="text-xs text-surface-950/60" aria-label="Staff available">{staff_online} staff available</span>
				{/if}
			</div>
			{#if now_serving.length > 0}
				<div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
					{#each now_serving as entry}
						<div class="card bg-surface-50 border border-surface-200 rounded-container shadow-sm">
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
				<!-- Mobile: single-row horizontal scroll; Desktop: multi-column grid -->
				<div class="flex gap-4 overflow-x-auto pb-1 -mx-4 px-4 sm:mx-0 sm:px-0 sm:block">
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

					<!-- Mobile: horizontally scrollable cards, no wrapping -->
					<div class="flex sm:hidden gap-4">
						{#each waiting_by_station as row (row.station_name)}
							<div class="card bg-surface-50 border border-surface-200 rounded-container shadow-sm flex flex-col flex-shrink-0 min-w-[260px] max-w-[280px]">
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
						{#each visibleActivity as item, i (String(i) + (item.created_at ?? '') + (item.alias ?? '') + (item.station_name ?? ''))}
							<li class="px-4 py-2 flex justify-between items-center gap-2">
								<span class="text-surface-950/90">
									<span class="font-semibold text-surface-950">{item.station_name}:</span> {item.message}
								</span>
								<span class="text-xs text-surface-950/60 shrink-0">{formatActivityTime(item.created_at)}</span>
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
		class="display-footer fixed bottom-0 left-0 right-0 z-30 px-4 py-3 bg-surface-800 text-surface-100 shadow-[0_-4px_12px_rgba(0,0,0,0.08)]"
		aria-label="Staff on duty"
	>
		<div class="flex items-center gap-4 min-w-0">
			<span class="text-xs font-semibold uppercase tracking-wider text-surface-300 shrink-0 self-center">Staff on duty</span>
			{#if staffForBar.length > 0}
				<!-- Footer-only: custom [chips][gap][chips][gap] track (not shared Marquee). Reduces loop flicker for this layout. -->
				<div class="display-footer__marquee flex-1 min-w-0 overflow-hidden" aria-label="Staff availability">
					<div class="fq-marquee-track display-footer__marquee-inner flex items-center gap-3" style="animation-duration: 25s; width: max-content;">
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
			{:else}
				<span class="text-xs text-surface-400">No staff assigned</span>
			{/if}
		</div>
	</footer>
	{/if}
	{/snippet}
</DisplayLayout>
