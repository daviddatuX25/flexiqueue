<script>
	/**
	 * Display/StationBoard.svelte — station-specific informant display (no auth).
	 * Mute/volume from server and /station/*; real-time via .display_station_settings.
	 */
	import { onMount, onDestroy } from 'svelte';
	import { router } from '@inertiajs/svelte';
	import Modal from '../../Components/Modal.svelte';
	import DisplayLayout from '../../Layouts/DisplayLayout.svelte';
	import Marquee from '../../Components/Marquee.svelte';
	import PublicSupervisorAuthBlock from '../../Components/PublicSupervisorAuthBlock.svelte';
	import ThemeToggle from '../../Components/ThemeToggle.svelte';
	import { Settings } from 'lucide-svelte';
	import { prepareDisplayTts, cancelCurrentAnnouncement, playSegmentAQueued } from '../../lib/displayTts.js';
	import { toaster } from '../../lib/toaster.js';
	import {
		THEME_MODES,
		normalizeDisplayZoom,
		applyThemeModeToDocument,
		applyDisplayZoomToDocument,
		DISPLAY_ZOOM_LEVELS,
	} from '../../lib/displayAppearance.js';
	import {
		readStationDisplayDeviceLocal,
		writeStationDisplayDeviceLocal,
		clearStationDisplayDeviceLocal,
	} from '../../lib/stationDisplayDevice.js';

let {
	program_name = null,
	program_id = null,
	program_slug = null,
	site_slug = null,
	date = '',
	station_name = '',
	station_id = 0,
	now_serving = [],
	waiting = [],
	holding = [],
	station_activity = [],
	display_audio_muted = false,
	display_audio_volume = 1,
	/** Server default from /station (staff). Device may override locally until Reset. */
	display_page_zoom = 1,
	tts_active_language = 'en',
	tts_connector_phrase = null,
	station_tts_phrase = null,
	prefer_generated_audio = true,
	segment_2_enabled = true,
	tts_default_pre_phrase = '',
	tts_token_bridge_tail = '',
	tts_closing_without_segment2 = '',
	queueing_method_label = null,
	queue_mode_display = null,
	station_selection_mode = null,
	alternate_ratio = null,
	priority_first = null,
	alternate_priority_first = null,
	max_no_show_attempts = 3,
	/** Shared: when staff/admin, lockout does not apply; can exit without PIN/QR. */
	auth = null,
} = $props();

/** Staff/admin can change device without unlock modal (lockout applies only to non-staff/admin). */
const canBypassDeviceLock = $derived(auth?.can?.public_device_authorize === true);

	/** Server-side defaults (from Inertia + broadcast). */
	let serverMuted = $state(false);
	let serverVolume = $state(1);
	let serverZoom = $state(1);
	/** Non-null = this browser has a device-only override blob in localStorage. */
	let deviceLocal = $state(null);

	const playbackMuted = $derived(
		deviceLocal != null && typeof deviceLocal.muted === 'boolean' ? deviceLocal.muted : serverMuted
	);
	const playbackVolume = $derived(
		deviceLocal != null && typeof deviceLocal.volume === 'number' ? deviceLocal.volume : serverVolume
	);

	let ttsLanguage = $state('en');
let connectorPhrase = $state(null);
	let stationPhrase = $state(null);
	let preferGeneratedAudio = $state(true);
	let segment2Enabled = $state(true);
	let ttsDefaultPrePhrase = $state('');
	let ttsTokenBridgeTail = $state('');
	let ttsClosingWithoutSegment2 = $state('');
	/** Recent activity: from props + real-time .station_activity; max 20, newest first. */
	let activityFeed = $state([]);
	/** Choose device type page URL (for unlock flow). */
	const chooseUrl = $derived(site_slug && program_slug ? `/site/${site_slug}/program/${program_slug}/devices` : null);

	/** Plain-language routing (same copy as Display/Board). */
	const stationRoutingMarqueeLine = $derived.by(() => {
		if (!station_selection_mode) return '';
		const sel = station_selection_mode;
		if (sel === 'fixed') {
			return 'How you’re called: each counter has its own line—you’ll be served at the counter you’re assigned to.';
		}
		if (sel === 'shortest_queue') {
			return 'How you’re called: we send the next person to whichever counter has the shortest line.';
		}
		if (sel === 'least_busy') {
			return 'How you’re called: we send the next person to the counter that isn’t busy right now.';
		}
		if (sel === 'round_robin') {
			return 'How you’re called: counters take turns—the next person goes to the next counter in rotation.';
		}
		if (sel === 'least_recently_served') {
			return 'How you’re called: we send the next person to the counter that hasn’t called someone forward in the longest time.';
		}
		return '';
	});

	const headerMarqueeText = $derived.by(() => {
		const parts = [];
		const qml = queueing_method_label && String(queueing_method_label).trim();
		const qmd = queue_mode_display && String(queue_mode_display).trim();
		if (qml) parts.push(`How this works: ${qml}.`);
		if (qmd) parts.push(`Queue order: ${qmd}.`);
		if (!qml && stationRoutingMarqueeLine) parts.push(stationRoutingMarqueeLine);
		return parts.join(' ').trim();
	});

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
	let showUnlockModal = $state(false);
	let unlockAuthMode = $state('pin');
	let unlockPin = $state('');
	let unlockRequestId = $state(null);
	let unlockRequestToken = $state(null);
	let unlockRequestState = $state('idle');
	let unlockPollIntervalId = null;
	let unlockLoading = $state(false);
	let beforeUnloadHandler = null;

	let showStationSettingsModal = $state(false);
	let displaySettingsSaving = $state(false);
	let displaySettingsError = $state('');
	let stationDisplayAuthMode = $state('pin');
	let stationDisplayAuthWaiting = $state(false);
	let stationDisplayAuthBlockRef = $state(null);
	let stationDisplayAuthSession = $state(0);
	/** Modal fields — “this device only”; saved to station-scoped localStorage on Save. */
	let modalTheme = $state('flexiqueue');
	let modalMuted = $state(false);
	let modalVolume = $state(1);
	let modalZoom = $state(1);
	let baselineTheme = $state('flexiqueue');
	let baselineMuted = $state(false);
	let baselineVolume = $state(1);
	let baselineZoom = $state(1);
	/** True after user clicks Reset; applied on Save (clears device local, follows /station server). */
	let pendingDeviceReset = $state(false);

	const deviceSettingsModalDirty = $derived(
		pendingDeviceReset ||
			modalTheme !== baselineTheme ||
			modalMuted !== baselineMuted ||
			Math.abs(modalVolume - baselineVolume) > 0.001 ||
			normalizeDisplayZoom(modalZoom) !== normalizeDisplayZoom(baselineZoom)
	);

	function readThemeModeFromDocument() {
		if (typeof document === 'undefined') return 'flexiqueue';
		const m = document.documentElement.getAttribute('data-mode') ?? 'flexiqueue';
		return THEME_MODES.includes(m) ? m : 'flexiqueue';
	}

	function getCsrfToken() {
		const meta = typeof document !== 'undefined' ? document.querySelector('meta[name="csrf-token"]') : null;
		return (meta && meta.getAttribute('content')) || '';
	}
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
			} catch {}
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
				} catch {}
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

	function openStationSettingsModal() {
		pendingDeviceReset = false;
		modalTheme = deviceLocal?.theme ?? readThemeModeFromDocument();
		modalMuted = playbackMuted;
		modalVolume = playbackVolume;
		modalZoom = deviceLocal != null ? normalizeDisplayZoom(deviceLocal.zoom) : serverZoom;
		baselineTheme = modalTheme;
		baselineMuted = modalMuted;
		baselineVolume = modalVolume;
		baselineZoom = modalZoom;
		displaySettingsError = '';
		stationDisplayAuthMode = 'pin';
		stationDisplayAuthSession += 1;
		showStationSettingsModal = true;
	}

	function requestDeviceReset() {
		pendingDeviceReset = true;
	}

	function persistStationDeviceOverridesToBrowser() {
		if (pendingDeviceReset) {
			clearStationDisplayDeviceLocal(station_id);
			deviceLocal = null;
			pendingDeviceReset = false;
		} else {
			const payload = {
				theme: modalTheme,
				zoom: normalizeDisplayZoom(modalZoom),
				muted: !!modalMuted,
				volume: Math.max(0, Math.min(1, Number(modalVolume))),
			};
			writeStationDisplayDeviceLocal(station_id, payload);
			deviceLocal = payload;
		}
	}

	async function handleStationDeviceQrApproved() {
		displaySettingsError = '';
		persistStationDeviceOverridesToBrowser();
		toaster.success({ title: 'Device settings applied.' });
		showStationSettingsModal = false;
	}

	async function saveStationDeviceSettings() {
		displaySettingsError = '';
		if (program_id == null) {
			displaySettingsError = 'No program context.';
			return;
		}
		let authBody = null;
		if (canBypassDeviceLock) {
			authBody = {};
		} else {
			authBody = stationDisplayAuthBlockRef?.buildPinOrQrPayload?.() ?? null;
			if (authBody === null) {
				displaySettingsError =
					stationDisplayAuthMode === 'pin'
						? 'Enter a 6-digit PIN to apply changes.'
						: 'Use "Show QR for supervisor to scan" to apply changes.';
				return;
			}
		}
		displaySettingsSaving = true;
		try {
			const res = await fetch('/api/public/display-settings', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-CSRF-TOKEN': getCsrfToken(),
					'X-Requested-With': 'XMLHttpRequest',
				},
				credentials: 'same-origin',
				body: JSON.stringify({ program_id, ...authBody }),
			});
			if (res.status === 419) {
				displaySettingsError = 'Session expired. Refresh and try again.';
				toaster.error({ title: displaySettingsError });
				return;
			}
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
				displaySettingsError = data.message || 'Could not verify.';
				toaster.error({ title: data.message || 'Could not verify.' });
				return;
			}
			persistStationDeviceOverridesToBrowser();
			toaster.success({ title: 'Device settings saved.' });
			showStationSettingsModal = false;
		} catch {
			displaySettingsError = 'Could not save device settings.';
			toaster.error({ title: 'Could not save device settings.' });
		} finally {
			displaySettingsSaving = false;
		}
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

	onDestroy(() => {
		cancelUnlockRequestOnLeave();
		if (typeof window !== 'undefined' && beforeUnloadHandler) {
			window.removeEventListener('beforeunload', beforeUnloadHandler);
		}
	});

	onMount(() => {
		deviceLocal = readStationDisplayDeviceLocal(station_id);
		beforeUnloadHandler = () => cancelUnlockRequestOnLeave();
		window.addEventListener('beforeunload', beforeUnloadHandler);
	});

	$effect(() => {
		serverMuted = !!display_audio_muted;
		serverVolume = Math.max(0, Math.min(1, Number(display_audio_volume ?? 1)));
		serverZoom = normalizeDisplayZoom(display_page_zoom);
	});

	/** Apply theme + zoom without writing global theme/zoom keys (station uses its own localStorage blob). */
	$effect(() => {
		const z =
			deviceLocal != null && deviceLocal.zoom != null
				? normalizeDisplayZoom(deviceLocal.zoom)
				: serverZoom;
		applyDisplayZoomToDocument(z, { persistToGlobalStorage: false });
		const th =
			deviceLocal != null && typeof deviceLocal.theme === 'string' && THEME_MODES.includes(deviceLocal.theme)
				? deviceLocal.theme
				: 'flexiqueue';
		applyThemeModeToDocument(th, { persistToGlobalStorage: false });
	});

	$effect(() => {
		const lang =
			typeof tts_active_language === 'string' && tts_active_language
				? tts_active_language
				: 'en';
		ttsLanguage = ['en', 'fil', 'ilo'].includes(lang) ? lang : 'en';
		connectorPhrase =
			typeof tts_connector_phrase === 'string' && tts_connector_phrase.trim() !== ''
				? tts_connector_phrase.trim()
				: null;
		stationPhrase =
			typeof station_tts_phrase === 'string' && station_tts_phrase.trim() !== ''
				? station_tts_phrase.trim()
				: null;
		preferGeneratedAudio = prefer_generated_audio !== false;
		segment2Enabled = segment_2_enabled !== false;
		ttsDefaultPrePhrase =
			typeof tts_default_pre_phrase === 'string' ? tts_default_pre_phrase.trim() : '';
		ttsTokenBridgeTail =
			typeof tts_token_bridge_tail === 'string' ? tts_token_bridge_tail.trim() : '';
		ttsClosingWithoutSegment2 =
			typeof tts_closing_without_segment2 === 'string'
				? tts_closing_without_segment2.trim()
				: '';
	});
	$effect(() => {
		activityFeed = [...(station_activity ?? [])];
	});

	function refreshStationData() {
		router.reload({
			only: [
				'now_serving',
				'waiting',
				'station_activity',
				'display_audio_muted',
				'display_audio_volume',
				'queueing_method_label',
				'queue_mode_display',
				'alternate_ratio',
				'priority_first',
				'alternate_priority_first',
				'prefer_generated_audio',
				'segment_2_enabled',
				'tts_default_pre_phrase',
				'tts_token_bridge_tail',
				'tts_closing_without_segment2',
				'display_page_zoom',
			],
		});
	}

	/** Actions that actually change the displayed queue state — gate full reload on these only (per docs/necessary-fix.md). */
	const QUEUE_CHANGING_ACTIONS = new Set([
		'bind', 'call', 'serve', 'transfer', 'complete',
		'cancel', 'hold', 'resume', 'no_show', 'enqueue_back',
		'force_complete', 'override'
	]);

	function handleStationActivity(e) {
		if (Number(e?.station_id) !== Number(station_id)) {
			// Different station — only reload if queue-relevant
			if (QUEUE_CHANGING_ACTIONS.has(e?.action_type)) {
				refreshStationData();
			}
			return;
		}
		const item = {
			station_name: e.station_name ?? station_name,
			message: e.message ?? '',
			alias: e.alias ?? '—',
			action_type: e.action_type ?? '',
			created_at: e.created_at ?? new Date().toISOString(),
		};
		activityFeed = [item, ...activityFeed].slice(0, 20);
		if (e?.action_type === 'call') {
			const pronounceAs =
				e.pronounce_as === 'word' || e.pronounce_as === 'custom' ? 'word' : 'letters';
			const byLang = e.token_spoken_by_lang;
			const tokenSpokenPart =
				byLang && typeof byLang === 'object' && typeof byLang[ttsLanguage] === 'string' && byLang[ttsLanguage].trim() !== ''
					? byLang[ttsLanguage].trim()
					: undefined;
			playSegmentAQueued(e.alias, pronounceAs, e.token_id ?? null, {
				muted: playbackMuted,
				volume: playbackVolume,
				preferGeneratedAudio,
				segment2Enabled,
				defaultPrePhrase: ttsDefaultPrePhrase,
				tokenBridgeTail: ttsTokenBridgeTail,
				closingWithoutSegment2: ttsClosingWithoutSegment2,
				...(tokenSpokenPart ? { tokenSpokenPart } : {}),
				onCompleteFailure: () => {
					toaster.warning({ title: 'Audio unavailable', description: 'Call announcement could not be played.' });
				},
			});
		}
		// Only reload for queue-changing events
		if (QUEUE_CHANGING_ACTIONS.has(e?.action_type)) {
			refreshStationData();
		}
	}

onMount(() => {
		prepareDisplayTts();
		if (typeof window === 'undefined' || !window.Echo || !station_id) {
			if (typeof window !== 'undefined') {
				toaster.warning({ title: 'Real-time updates unavailable. Display will not receive live updates.' });
			}
			return () => cancelCurrentAnnouncement();
		}
		const echo = window.Echo;
		const channelName = 'display.station.' + station_id;
		const ch = echo.channel(channelName);
		ch.listen('.station_activity', handleStationActivity);
		ch.listen('.now_serving', refreshStationData);
		ch.listen('.queue_length', refreshStationData);
		ch.listen('.display_station_settings', (e) => {
			serverMuted = !!e.display_audio_muted;
			serverVolume = Math.max(0, Math.min(1, Number(e.display_audio_volume ?? 1)));
			if (e.display_page_zoom != null) {
				serverZoom = normalizeDisplayZoom(e.display_page_zoom);
			}
		});
		return () => {
			cancelCurrentAnnouncement();
			echo.leave(channelName);
		};
	});
</script>

<svelte:head>
	<title>{station_name ? station_name + ' — FlexiQueue' : 'Station Display — FlexiQueue'}</title>
</svelte:head>

<DisplayLayout programName={program_name} {date}>
	<div class="flex flex-col gap-6 max-w-4xl mx-auto">
		<header class="flex flex-col gap-3">
			<div class="flex items-center justify-between gap-2">
				<h1 class="text-2xl font-bold text-surface-950">{station_name}</h1>
			</div>
			<!-- Marquee + change device + settings (same pattern as Display/Board). -->
			<section
				class="rounded-container border border-surface-200 bg-surface-50 px-3 py-2 flex flex-row items-center gap-3 min-h-[3rem]"
				aria-label="Queue routing and display settings"
			>
				<div class="min-w-0 flex-1 flex items-center self-center py-0.5">
					{#if headerMarqueeText}
						<Marquee overflowOnly duration={28} gapEm={3} class="w-full block">
							{#snippet children()}
								<span class="text-sm text-surface-800 leading-snug">{headerMarqueeText}</span>
							{/snippet}
						</Marquee>
					{:else}
						<p class="text-sm text-surface-600">Program queue and routing details will appear here when configured.</p>
					{/if}
				</div>
				<div class="flex items-center gap-2 shrink-0">
					{#if chooseUrl}
						<button
							type="button"
							class="btn preset-tonal btn-sm touch-target-h whitespace-nowrap"
							onclick={handleChangeDeviceClick}
						>
							Change device type
						</button>
					{/if}
					<button
						type="button"
						class="btn btn-icon preset-tonal shrink-0 touch-target"
						aria-label="Display settings"
						title="Settings"
						onclick={openStationSettingsModal}
					>
						<Settings class="w-5 h-5" />
					</button>
				</div>
			</section>
		</header>

		{#if now_serving?.length > 0}
			<section>
				<h2 class="text-xl font-bold text-surface-950 mb-3">NOW SERVING / CALLING</h2>
				{#if now_serving.some((item) => item.status === 'called')}
					<div class="mb-3 p-4 rounded-container bg-primary-500/10 border-2 border-primary-500 text-center" aria-live="polite">
						<p class="text-sm font-semibold text-primary-700 uppercase tracking-wide">Calling now</p>
						<p class="text-3xl font-bold font-mono text-surface-950 mt-1">
							{now_serving.filter((item) => item.status === 'called').map((item) => item.alias).join(', ')}
						</p>
					</div>
				{/if}
				<ul class="space-y-3">
					{#each now_serving as item}
						<li
							class="card rounded-container border p-4 {item.status === 'called'
								? 'bg-primary-500/5 border-primary-500/50 shadow-sm'
								: 'bg-surface-50 border-surface-200'}"
						>
							<div class="flex flex-wrap items-center justify-between gap-2">
								<span class="font-mono font-bold {item.status === 'called' ? 'text-2xl text-primary-700' : 'text-lg text-surface-900'}">{item.alias}</span>
								<span class="text-sm font-medium {item.status === 'called' ? 'preset-filled-warning-500 px-2 py-1 rounded' : 'text-surface-600'}">
									{item.status === 'called' ? 'Calling' : 'Serving'}
								</span>
							</div>
							{#if item.track}
								<p class="text-xs text-surface-500 mt-1">{item.track}</p>
							{/if}
							{#if item.no_show_attempts && item.no_show_attempts > 0}
								<p class="text-xs text-surface-500 mt-2">
									No-shows: {item.no_show_attempts}/{max_no_show_attempts}
								</p>
							{/if}
						</li>
					{/each}
				</ul>
			</section>
		{/if}

		{#if waiting?.length > 0}
			<section>
				<h2 class="text-xl font-bold text-surface-950 mb-3">WAITING</h2>
				<ul class="space-y-3">
					{#each waiting as item}
						<li class="card rounded-container border border-surface-200 bg-surface-50 p-4">
							<div class="flex flex-wrap items-center justify-between gap-2">
								<div class="flex flex-col gap-1">
									<span class="font-mono font-semibold text-lg text-surface-900">{item.alias}</span>
									{#if item.process_name}
										<span class="text-xs text-surface-600">{item.process_name}</span>
									{/if}
									{#if typeof item.position === 'number'}
										<span class="text-xs text-surface-500">#{item.position} in line</span>
									{/if}
								</div>
							</div>
							{#if item.no_show_attempts && item.no_show_attempts > 0}
								<p class="text-xs text-surface-500 mt-2">
									No-shows: {item.no_show_attempts}/{max_no_show_attempts}
								</p>
							{/if}
						</li>
					{/each}
				</ul>
			</section>
		{/if}

		{#if holding?.length > 0}
			<section>
				<h2 class="text-xl font-bold text-surface-950 mb-3">ON HOLD</h2>
				<ul class="space-y-3">
					{#each holding as item}
						<li class="card rounded-container border border-surface-200 bg-surface-50 p-4">
							<div class="flex flex-wrap items-center justify-between gap-2">
								<div class="flex flex-col gap-1">
									<span class="font-mono font-semibold text-lg text-surface-900">{item.alias}</span>
									{#if item.process_name}
										<span class="text-xs text-surface-600">{item.process_name}</span>
									{/if}
									{#if item.track}
										<span class="text-xs text-surface-500">{item.track}</span>
									{/if}
								</div>
								<div class="flex flex-col items-end gap-1">
									{#if item.status === 'awaiting_approval'}
										<span class="text-[11px] px-2 py-1 rounded preset-filled-warning-500/80 text-warning-900 font-semibold uppercase tracking-wide">
											Awaiting approval
										</span>
									{:else}
										<span class="text-xs text-surface-600 capitalize">{item.status}</span>
									{/if}
								</div>
							</div>
							{#if item.held_at}
								<p class="text-xs text-surface-500 mt-2">
									Held since {new Date(item.held_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
								</p>
							{/if}
							{#if item.no_show_attempts && item.no_show_attempts > 0}
								<p class="text-xs text-surface-500 mt-1">
									No-shows: {item.no_show_attempts}/{max_no_show_attempts}
								</p>
							{/if}
						</li>
					{/each}
				</ul>
			</section>
		{/if}

		<!-- Recent activity: max 20 items, fixed-height scroll (like /display). -->
		<section>
			<h2 class="text-xl font-bold text-surface-950 mb-3">RECENT ACTIVITY</h2>
			{#if activityFeed.length > 0}
				<div class="card bg-surface-50 border border-surface-200 rounded-container overflow-hidden">
					<ul class="divide-y divide-surface-200 max-h-[12rem] overflow-y-auto" aria-label="Recent activity">
						{#each activityFeed.slice(0, 20) as item, i (String(i) + (item.created_at ?? '') + (item.message ?? '') + (item.alias ?? ''))}
							<li class="px-4 py-2 text-surface-950/90 text-sm">{item.message}</li>
						{/each}
					</ul>
				</div>
			{:else}
				<div class="card bg-surface-50 border border-surface-200 rounded-container">
					<div class="p-4 py-6 text-center text-surface-950/70">No recent activity.</div>
				</div>
			{/if}
		</section>

		{#if (!now_serving?.length && !waiting?.length && !holding?.length && activityFeed.length === 0)}
			<div class="card bg-surface-50 border border-surface-200 rounded-container p-8 text-center">
				<p class="text-surface-600 text-lg">No activity at this station.</p>
			</div>
		{/if}
	</div>

	<Modal
		open={showStationSettingsModal}
		title="This display device"
		onClose={() => {
			stationDisplayAuthBlockRef?.cancelOngoingRequest?.();
			showStationSettingsModal = false;
			pendingDeviceReset = false;
		}}
	>
		{#snippet children()}
			<div class="flex flex-col gap-6">
				<p class="text-sm text-surface-950/70">
					<strong class="font-medium text-surface-950">Apply to this device only</strong> — saved in this browser’s storage for this station.
					Station-wide defaults (mute, volume, zoom) come from the <strong class="font-medium text-surface-950">staff /station</strong> page.
					{#if canBypassDeviceLock}
						Signed in as staff — you can save without PIN or QR.
					{:else}
						Saving requires supervisor PIN or QR approval (server verifies; device overrides stay in this browser only).
					{/if}
				</p>
				{#if deviceSettingsModalDirty}
					<p
						class="text-xs text-primary-700 dark:text-primary-400 rounded-container border border-primary-500/30 bg-primary-500/5 px-3 py-2"
						role="status"
					>
						<span class="font-medium"
							>Pending until you save{#if !canBypassDeviceLock} (PIN or QR){/if}:</span
						>
						{#if pendingDeviceReset}
							reset to match staff /station defaults (clears this device’s overrides).
						{:else}
							device-only appearance and audio.
						{/if}
					</p>
				{/if}
				<div class="flex flex-col gap-4">
					<h3 class="text-sm font-semibold text-surface-950">Theme &amp; zoom</h3>
					<div class="flex items-center justify-between gap-3 flex-wrap">
						<span class="text-sm font-medium text-surface-950">Theme</span>
						<ThemeToggle
							persistToStorage={false}
							bind:mode={modalTheme}
							disabled={displaySettingsSaving || stationDisplayAuthWaiting}
						/>
					</div>
					<label class="flex flex-col gap-1 max-w-xs">
						<span class="text-sm font-medium text-surface-950">Page zoom</span>
						<select
							class="select select-theme rounded-container border border-surface-200 px-3 py-2 text-sm"
							bind:value={modalZoom}
							disabled={displaySettingsSaving || stationDisplayAuthWaiting}
						>
							{#each DISPLAY_ZOOM_LEVELS as z (z.value)}
								<option value={z.value}>{z.label}</option>
							{/each}
						</select>
					</label>
				</div>
				<div class="border-t border-surface-200 pt-4 flex flex-col gap-4">
					<h3 class="text-sm font-semibold text-surface-950">Audio on this device</h3>
					<p class="text-xs text-surface-950/60">Mute and volume for announcements on this screen only.</p>
					<label for="stn-display-audio-mute" class="flex items-center justify-between cursor-pointer gap-3">
						<span class="text-sm font-medium text-surface-950">Mute</span>
						<div class="relative inline-block w-11 h-5">
							<input
								id="stn-display-audio-mute"
								type="checkbox"
								class="peer appearance-none w-11 h-5 bg-surface-200 rounded-full checked:bg-surface-800 cursor-pointer transition-colors duration-300 disabled:opacity-50"
								bind:checked={modalMuted}
								disabled={displaySettingsSaving || stationDisplayAuthWaiting}
							/>
							<span
								class="absolute top-0 left-0 w-5 h-5 bg-surface-950 rounded-full border border-surface-300 shadow-sm transition-transform duration-300 peer-checked:translate-x-6 peer-checked:border-surface-800 pointer-events-none"
								aria-hidden="true"
							></span>
						</div>
					</label>
					<label class="flex flex-col gap-2">
						<span class="text-sm font-medium text-surface-950">Volume</span>
						<input
							type="range"
							min="0"
							max="1"
							step="0.1"
							class="range range-sm w-full max-w-xs"
							bind:value={modalVolume}
							disabled={displaySettingsSaving || stationDisplayAuthWaiting || modalMuted}
						/>
					</label>
				</div>
				<div class="border-t border-surface-200 pt-4 flex flex-col gap-2">
					<button
						type="button"
						class="btn preset-tonal btn-sm touch-target-h self-start"
						disabled={displaySettingsSaving || stationDisplayAuthWaiting}
						onclick={requestDeviceReset}
					>
						Reset to staff /station defaults
					</button>
					<p class="text-xs text-surface-950/60">
						Clears this device’s overrides and applies the current server settings for this station. Confirm with <strong>Save</strong>.
					</p>
				</div>
				{#key stationDisplayAuthSession}
					<PublicSupervisorAuthBlock
						bind:this={stationDisplayAuthBlockRef}
						bind:authMode={stationDisplayAuthMode}
						bind:authWaiting={stationDisplayAuthWaiting}
						programId={program_id}
						canBypassAuth={canBypassDeviceLock}
						getCsrfToken={getCsrfToken}
						getRequestBody={() => ({})}
						disabled={displaySettingsSaving}
						saving={displaySettingsSaving}
						onQrApproved={handleStationDeviceQrApproved}
						onFlowError={(msg) => {
							displaySettingsError = msg;
						}}
					/>
				{/key}
				{#if displaySettingsError}
					<p class="text-sm text-error-600">{displaySettingsError}</p>
				{/if}
				<div class="flex flex-wrap gap-2 justify-end pt-2">
					<button
						type="button"
						class="btn preset-tonal"
						onclick={() => {
							stationDisplayAuthBlockRef?.cancelOngoingRequest?.();
							showStationSettingsModal = false;
							pendingDeviceReset = false;
						}}
						disabled={displaySettingsSaving}>Cancel</button>
					{#if stationDisplayAuthMode === 'request' && !canBypassDeviceLock}
						<!-- Apply via QR above -->
					{:else}
						<button
							type="button"
							class="btn preset-filled-primary-500"
							onclick={saveStationDeviceSettings}
							disabled={displaySettingsSaving || !deviceSettingsModalDirty}
						>
							{displaySettingsSaving ? 'Saving…' : 'Save'}</button>
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
</DisplayLayout>
