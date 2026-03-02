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
	import { onMount } from 'svelte';
	import { router, usePage } from '@inertiajs/svelte';
	import DisplayLayout from '../../Layouts/DisplayLayout.svelte';
	import Modal from '../../Components/Modal.svelte';
	import QrScanner from '../../Components/QrScanner.svelte';
	import UserAvatar from '../../Components/UserAvatar.svelte';
	import { Camera } from 'lucide-svelte';
	import { getFemaleVoice, getVoiceByName, ensureVoicesLoaded, TTS_DEFAULT_RATE } from '../../lib/speechUtils.js';

	const page = usePage();

	let {
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
		display_tts_voice = null,
		enable_display_hid_barcode = true,
	} = $props();

	/** Synced from prop + .program_status; when true, show "Program is paused" overlay (real-time). */
	let programIsPaused = $state(false);
	/** Per plan: display board TTS mute/volume/voice — from props and .display_settings broadcast. */
	let displayAudioMuted = $state(false);
	let displayAudioVolume = $state(1);
	let displayTtsVoice = $state(null);

	$effect(() => {
		programIsPaused = !!program_is_paused;
	});
	$effect(() => {
		displayAudioMuted = !!display_audio_muted;
		displayAudioVolume = Math.max(0, Math.min(1, Number(display_audio_volume ?? 1)));
		displayTtsVoice = display_tts_voice ?? null;
	});

	/** Effective TTS voice: prefer synced state, fall back to prop so refresh/reload always applies. */
	const effectiveTtsVoice = $derived(displayTtsVoice ?? display_tts_voice ?? null);

	/** Open camera modal when URL has ?scan=1 (e.g. "Scan again" from Status page). */
	$effect(() => {
		const url = typeof page?.url === 'string' ? page.url : (typeof window !== 'undefined' ? window.location.href : '');
		try {
			const parsed = new URL(url, typeof window !== 'undefined' ? window.location.origin : 'http://localhost');
			if (parsed.searchParams.get('scan') === '1') {
				showScanner = true;
				scanHandled = false;
				if (typeof window !== 'undefined') {
					window.history.replaceState({}, '', '/display');
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
	/** Pending second-speak timeout for TTS repeat; cleared when new call or unmount. */
	let ttsRepeatTimeoutId = $state(null);
	$effect(() => {
		activityFeed = [...(station_activity ?? [])];
	});

	/** Recent activity: max 20 items, fixed-height scroll (shows ~5 items). No View more/less. */
	const visibleActivity = $derived(activityFeed.slice(0, 20));

	/** Phonetic words for letters so TTS says "ay" not "uh". */
	const LETTER_PHONETIC = {
		a: 'ay', b: 'bee', c: 'see', d: 'dee', e: 'ee', f: 'eff', g: 'jee', h: 'aych',
		i: 'eye', j: 'jay', k: 'kay', l: 'ell', m: 'em', n: 'en', o: 'oh', p: 'pee',
		q: 'cue', r: 'ar', s: 'ess', t: 'tee', u: 'you', v: 'vee', w: 'double you',
		x: 'ex', y: 'why', z: 'zee',
	};

	/** Build alias text for TTS: letters = phonetic + digit runs; word = as-is. */
	function aliasForSpeech(alias, pronounceAs) {
		const raw = (alias ?? 'client').toString().trim() || 'client';
		if (pronounceAs === 'word') return raw;
		const segments = [];
		let i = 0;
		while (i < raw.length) {
			if (/[a-zA-Z]/.test(raw[i])) {
				let run = '';
				while (i < raw.length && /[a-zA-Z]/.test(raw[i])) {
					run += raw[i++];
				}
				for (const c of run) {
					const ph = LETTER_PHONETIC[c.toLowerCase()];
					if (ph) segments.push(ph);
				}
			} else if (/\d/.test(raw[i])) {
				let run = '';
				while (i < raw.length && /\d/.test(raw[i])) {
					run += raw[i++];
				}
				segments.push(run);
			} else {
				i++;
			}
		}
		return segments.length ? segments.join(' ') : raw;
	}

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
				'display_tts_voice',
			],
		});
	}

	/** Per plan: TTS — female voice, rate 0.8, repeat 2x with 2s gap; pronounce_as letters/word. */
	function speakCallAnnouncement(alias, stationName, pronounceAs = 'letters') {
		if (typeof window === 'undefined' || displayAudioMuted || !window.speechSynthesis) return;
		if (ttsRepeatTimeoutId != null) {
			clearTimeout(ttsRepeatTimeoutId);
			ttsRepeatTimeoutId = null;
		}
		const aliasSpoken = aliasForSpeech(alias, pronounceAs);
		const stationSpoken = (stationName ?? 'your station').toString().trim() || 'your station';
		const text = `Calling ${aliasSpoken}, please proceed to ${stationSpoken}`;
		const doSpeak = () => {
			if (displayAudioMuted) return;
			const u = new SpeechSynthesisUtterance(text);
			u.rate = TTS_DEFAULT_RATE;
			u.volume = Math.max(0, Math.min(1, displayAudioVolume));
			const voice = (effectiveTtsVoice && getVoiceByName(effectiveTtsVoice)) || getFemaleVoice();
			if (voice) u.voice = voice;
			window.speechSynthesis.speak(u);
		};
		doSpeak();
		ttsRepeatTimeoutId = setTimeout(() => {
			doSpeak();
			ttsRepeatTimeoutId = null;
		}, 2000);
	}

	onMount(() => {
		ensureVoicesLoaded();
		if (typeof window === 'undefined' || !window.Echo) return;
		const echo = window.Echo;
		const activityChannel = echo.channel('display.activity');
		activityChannel.listen('.station_activity', (e) => {
			const item = {
				station_name: e.station_name ?? '—',
				message: e.message ?? '',
				alias: e.alias ?? '—',
				action_type: e.action_type ?? '',
				created_at: e.created_at ?? new Date().toISOString(),
			};
			activityFeed = [item, ...activityFeed].slice(0, 20);
			if (e.action_type === 'call') {
				const pronounceAs = e.pronounce_as === 'word' ? 'word' : 'letters';
				speakCallAnnouncement(e.alias, e.station_name, pronounceAs);
			}
			// Per ISSUES-ELABORATION §10: refresh waiting/now serving so "Currently waiting" updates in realtime
			refreshBoardData();
		});
		// Per flexiqueue-wrx: staff availability changes → refresh staff footer only
		activityChannel.listen('.staff_availability', () => {
			router.reload({ only: ['staff_at_stations', 'staff_online'] });
		});
		// Program paused/resumed → show or hide blocker overlay in real time
		activityChannel.listen('.program_status', (e) => {
			programIsPaused = !!e.program_is_paused;
		});
		// Per plan: admin changed display board audio (mute/volume/voice) → update local state for TTS
		activityChannel.listen('.display_settings', (e) => {
			displayAudioMuted = !!e.display_audio_muted;
			displayAudioVolume = Math.max(0, Math.min(1, Number(e.display_audio_volume ?? 1)));
			displayTtsVoice = e.display_tts_voice ?? null;
		});
		// Real-time: refresh Now Serving and waiting list when serve/transfer/complete (not only on activity)
		const queueChannel = echo.channel('global.queue');
		queueChannel.listen('.now_serving', refreshBoardData);
		queueChannel.listen('.queue_length', refreshBoardData);
		return () => {
			if (ttsRepeatTimeoutId != null) clearTimeout(ttsRepeatTimeoutId);
			echo.leave('display.activity');
			echo.leave('global.queue');
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
		displayBarcodeInputEl?.focus();
	}

	/** Add one full timeout period to the scanner modal countdown (extension time from program settings). */
	function extendScannerCountdown() {
		const extra = Math.max(0, Number(display_scan_timeout_seconds) || 20);
		scanCountdown += extra;
	}

	/** Refocus hidden barcode input every 2s when camera modal is closed (so HID scanner keeps working). Only when admin enabled HID. */
	$effect(() => {
		if (showScanner || !enable_display_hid_barcode) return;
		const id = setInterval(() => {
			displayBarcodeInputEl?.focus();
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
		displayBarcodeInputEl?.focus();
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

<DisplayLayout programName={program_name} {date}>
	<div class="relative">
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
			<!-- Scan section: hidden input for HID barcode; pulsing CTA + camera icon opens camera modal. Per display scanner refactor plan. -->
		<section>
			<h2 class="text-xl font-bold text-surface-950 mb-3">CHECK YOUR STATUS</h2>
			<input
				type="text"
				autocomplete="off"
				inputmode="text"
				aria-label="Barcode scanner input; scan with hardware scanner or type and press Enter"
				class="sr-only"
				bind:value={displayBarcodeValue}
				bind:this={displayBarcodeInputEl}
				onkeydown={onDisplayBarcodeKeydown}
			/>
			<div
				class="flex items-center gap-3 rounded-container border-2 border-primary-500/30 bg-primary-500/5 p-4 animate-pulse"
				role="region"
				aria-label="Scan to check status"
			>
				<p class="flex-1 text-base font-medium text-surface-950">
					Scan your QR or barcode to check status
				</p>
				<button
					type="button"
					class="btn btn-icon preset-filled-primary-500 shrink-0 min-h-[48px] min-w-[48px]"
					aria-label="Open camera to scan QR code"
					title="Tap to scan with device camera"
					onclick={() => {
						showScanner = true;
						scanHandled = false;
					}}
				>
					<Camera class="w-6 h-6" />
				</button>
			</div>
		</section>

		<!-- Camera scan modal: camera-only QrScanner, countdown, Cancel -->
		<Modal open={showScanner} title="Scan QR via Device" onClose={closeScanner} wide={true}>
			{#snippet children()}
				<div class="flex flex-col gap-3 w-full min-w-[20rem] mx-auto">
					<QrScanner active={true} cameraOnly={true} onScan={handleQrScan} />
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
				<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
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
									{#each row.waiting_clients as client (client.alias)}
										<li>
											<span class="font-mono font-medium">{client.alias}</span>
											{#if client.process_name}
												<span class="text-surface-950/70 ml-1">— {client.process_name}</span>
											{/if}
										</li>
									{/each}
								</ul>
							{/if}
						</div>
					{/each}
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
	</div>

	<!-- Fixed footer: staff and availability only. Marquee-style single row in dark mode. -->
	<footer
		class="display-footer fixed bottom-0 left-0 right-0 z-30 px-4 py-3 bg-surface-800 text-surface-100 shadow-[0_-4px_12px_rgba(0,0,0,0.08)]"
		aria-label="Staff on duty"
	>
		<div class="flex items-center gap-4 min-w-0">
			<span class="text-xs font-semibold uppercase tracking-wider text-surface-300 shrink-0 self-center">Staff on duty</span>
			{#if staffForBar.length > 0}
				<div class="display-footer__marquee flex-1 min-w-0 overflow-hidden" aria-label="Staff availability">
					<div class="display-footer__marquee-inner flex items-center gap-3">
						{#each [...staffForBar, ...staffForBar] as s, i (String(i) + (s.name ?? '') + (s.station_name ?? '') + (s.availability_status ?? ''))}
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
			{:else}
				<span class="text-xs text-surface-400">No staff assigned</span>
			{/if}
		</div>
	</footer>
</DisplayLayout>
