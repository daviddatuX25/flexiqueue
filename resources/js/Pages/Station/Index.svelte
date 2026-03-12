<script lang="ts">
	import MobileLayout from '../../Layouts/MobileLayout.svelte';
	import Modal from '../../Components/Modal.svelte';
	import QrScanner from '../../Components/QrScanner.svelte';
import CategoryBadge from '../../Components/CategoryBadge.svelte';
import {
	Volume2,
	ChevronDown,
	ChevronUp,
	X,
	ArrowRight,
	CheckCircle,
	PauseCircle,
	UserX,
	RotateCcw,
	Shuffle,
	AlertTriangle,
	MoreHorizontal,
	Monitor,
	ArrowUpRight,
	StickyNote,
} from 'lucide-svelte';
	import { get } from 'svelte/store';
	import { tick, onMount } from 'svelte';
	import { usePage } from '@inertiajs/svelte';
	import { ensureVoicesLoaded, speakSample } from '../../lib/speechUtils.js';
	import { router } from '@inertiajs/svelte';
	import { toaster } from '../../lib/toaster.js';
	import { shouldFocusHidInput, shouldUseInputModeNone } from '../../lib/displayHid.js';

	type AuthType = 'pin' | 'qr' | 'request_approval';

	interface StationInfo {
		id: number;
		name: string;
	}

	interface ServingSession {
		session_id: number;
		alias: string;
		track: string;
		client_category: string;
		status: 'called' | 'serving';
		current_step_order: number;
		total_steps: number;
		started_at: string;
		no_show_attempts: number;
		process_id?: number | null;
		process_name?: string | null;
		unverified?: boolean;
	}

	interface WaitingSession {
		session_id: number;
		alias: string;
		track: string;
		client_name?: string | null;
		client_category: string;
		status: string;
		queued_at: string;
		station_queue_position?: number;
		process_id?: number | null;
		process_name?: string | null;
		unverified?: boolean;
	}

	/** Resolved session from station token scan (session-by-token API). */
	interface ScannedSession {
		session_id: number;
		alias: string;
		track: string;
		status: string;
		current_station_id: number;
		current_station: string;
		client_category: string;
		current_step_order: number;
		total_steps: number;
		at_this_station: boolean;
		unverified?: boolean;
	}

	interface HoldingSession {
		session_id: number;
		alias: string;
		track: string;
		client_category: string;
		status: string;
		held_at: string;
		process_id?: number | null;
		process_name?: string | null;
		current_step_order: number;
		total_steps: number;
	}

	interface QueueData {
		station: { id: number; name: string; client_capacity?: number; serving_count?: number; holding_capacity?: number; holding_count?: number };
		serving: ServingSession[];
		holding?: HoldingSession[];
		no_show_timer_seconds: number;
		max_no_show_attempts?: number;
		waiting: WaitingSession[];
		priority_first?: boolean;
		require_permission_before_override?: boolean;
		call_next_requires_override?: boolean;
		balance_mode?: string;
		next_to_call?: { session_id: number; alias: string } | null;
		stats: { total_waiting: number; total_served_today: number; avg_service_time_minutes: number };
		display_audio_muted?: boolean;
		display_audio_volume?: number;
	}

	let {
		station = null,
		stations = [],
		tracks = [],
		canSwitchStation = false,
		queueCount = 0,
		processedToday = 0,
		display_scan_timeout_seconds = 20,
	}: {
		station: StationInfo | null;
		stations: StationInfo[];
		tracks: StationInfo[];
		canSwitchStation: boolean;
		queueCount?: number;
		processedToday?: number;
		display_scan_timeout_seconds?: number;
	} = $props();

	let queue = $state<QueueData | null>(null);
	let loading = $state(true);
	let actionLoading = $state<string | null>(null);
	let showOverrideModal = $state(false);
	let overrideTargetTrackId = $state<number | null>(null);
	let overrideIsCustom = $state(false);
	let overrideReason = $state('');
	let overridePin = $state('');
	let overrideSupervisorId = $state<number | null>(null);
	let overrideSession = $state<ServingSession | null>(null);
	let authType = $state<AuthType>('pin');
	let tempCodeEntered = $state('');
	let tempQrScanToken = $state('');
	let showQrScanner = $state(false);
	let qrScanHandled = $state(false);
	let showForceCompleteModal = $state(false);
	let forceCompleteSession = $state<ServingSession | null>(null);
	let forceCompleteReason = $state('');
	let noShowModalSession = $state<ServingSession | null>(null);
	let backModalSession = $state<ServingSession | null>(null);
	let showCallNextOverrideModal = $state(false);
	let callNextSession = $state<{ session_id: number; alias: string } | null>(null);
	let cancelModalSession = $state<{ session_id: number; alias: string; status: string } | null>(null);
	let showMoreFor = $state<number | null>(null);

	/** Station token scan: identify client by QR, then show actions. Separate from override-auth scanner. */
	let showStationTokenScanner = $state(false);
	let stationScanHandled = $state(false);
	let scannedSession = $state<ScannedSession | null>(null);
	let scannedSessionError = $state('');
	/** When set (e.g. 'not_registered'), frontend can show triage redirect. */
	let scannedSessionErrorCode = $state<string | null>(null);

	/** Scanner modal countdown (when showQrScanner): decrement scanCountdown so Extend works. */
	let scanCountdown = $state(0);
	let scanCountdownIntervalId = $state<ReturnType<typeof setInterval> | null>(null);

	// HID barcode input shared across Station token scanner and QR auth modals (override/force-complete/call-next).
	let hidScanValue = $state('');
	let hidGlobalInputEl = $state<HTMLInputElement | null>(null);
	let hidModalInputEl = $state<HTMLInputElement | null>(null);

	/** Station notes: shared note visible to staff at this station. Real-time via Reverb. */
	let stationNote = $state<{ message: string; author_name?: string; updated_at?: string } | null>(null);
	let noteMessage = $state('');
	let noteSubmitting = $state(false);
	let notesExpanded = $state(true);

	/** Countdown per called session_id: when 0, No-show button enabled. Set when session enters 'called'. */
	let noShowCountdown = $state<Record<number, number>>({});

	/** Display board audio (for /display/station/{id}): saving state. */
	let displaySettingsSaving = $state(false);
	let showDisplayAudioModal = $state(false);
	/** Available browser TTS voices for dropdown (loaded on mount). */
	let availableTtsVoices = $state<{ name: string; lang: string }[]>([]);

	const page = usePage();
	const authUser = $derived((get(page)?.props as { auth?: { user?: { id: number; role?: string | { value?: string } } } })?.auth?.user ?? null);
	const userRole = $derived(
		typeof authUser?.role === 'string' ? authUser.role : (authUser?.role as { value?: string } | undefined)?.value ?? ''
	);

	const clientCapacity = $derived(queue?.station?.client_capacity ?? 1);
	const servingCount = $derived(queue?.station?.serving_count ?? queue?.serving?.length ?? 0);
	const atCapacity = $derived(servingCount >= clientCapacity);
	const noShowTimerSeconds = $derived(queue?.no_show_timer_seconds ?? 10);

	/** Staff needs auth for override/force-complete when program requires it; admin/supervisor never. Per flexiqueue-i87: when require_permission_before_override is OFF, reason-only (no PIN/QR). */
	const needsAuthForOverride = $derived(!canSwitchStation && (queue?.require_permission_before_override ?? true));

	function getCsrfToken(): string {
		const p = get(page);
		const fromProps = (p?.props as { csrf_token?: string } | undefined)?.csrf_token;
		if (fromProps) return fromProps;
		const meta =
			typeof document !== 'undefined'
				? (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content
				: '';
		return meta ?? '';
	}

	const MSG_SESSION_EXPIRED = 'Session expired. Please refresh and try again.';
	const MSG_NETWORK_ERROR = 'Network error. Please try again.';

	async function api(
		method: string,
		url: string,
		body?: object
	): Promise<{ ok: boolean; data?: object; message?: string }> {
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
			return { ok: res.ok, data, message: (data as { message?: string })?.message };
		} catch (e) {
			toaster.error({ title: MSG_NETWORK_ERROR });
			return { ok: false, message: MSG_NETWORK_ERROR };
		}
	}

	/**
	 * Fetch queue data. Use silent=true for refetch after actions (Call Next, Serve, etc.)
	 * so only the queue sections update without showing the full-page spinner.
	 * Optional onSuccess runs in same tick as queue/noShowCountdown to batch renders.
	 */
	async function fetchQueue(silent = false, onSuccess?: () => void) {
		if (!station) return;
		if (!silent) loading = true;
		const { ok, data } = await api('GET', `/api/stations/${station.id}/queue`);
		if (!silent) loading = false;
		if (ok) {
			const q = data as QueueData;
			queue = q;
			// Sync noShowCountdown in same tick as queue to avoid extra render (flicker)
			const serving = q?.serving ?? [];
			const called = serving.filter((s: { status: string }) => s.status === 'called');
			const timerSecs = q?.no_show_timer_seconds ?? 10;
			const next: Record<number, number> = {};
			for (const s of called) {
				next[s.session_id] = noShowCountdown[s.session_id] ?? timerSecs;
			}
			noShowCountdown = next;
			onSuccess?.();
		} else {
			toaster.error({ title: (data as { message?: string })?.message ?? 'Failed to load queue' });
		}
	}

	$effect(() => {
		if (station?.id) {
			fetchQueue();
			fetchNote();
		} else {
			queue = null;
			stationNote = null;
			loading = false;
		}
	});

	async function fetchNote() {
		if (!station) return;
		const { ok, data } = await api('GET', `/api/stations/${station.id}/notes`);
		if (ok && data) {
			const n = (data as { note?: { message: string; author_name?: string; updated_at?: string } | null }).note;
			stationNote = n;
			if (n?.message) noteMessage = n.message;
		}
	}

	async function submitNote(e: Event) {
		e.preventDefault();
		if (!station || noteSubmitting) return;
		noteSubmitting = true;
		const { ok, data } = await api('PUT', `/api/stations/${station.id}/notes`, { message: noteMessage });
		if (ok && data) {
			const n = (data as { note?: { message: string; author_name?: string; updated_at?: string } }).note;
			stationNote = n ?? null;
		}
		noteSubmitting = false;
	}

	$effect(() => {
		const sid = station?.id;
		if (!sid || typeof window === 'undefined') return;
		const w = window as unknown as { Echo?: { channel: (n: string) => { listen: (e: string, cb: (x: unknown) => void) => void }; leave: (n: string) => void } };
		if (!w.Echo) return;
		const Echo = w.Echo;
		const ch = Echo.channel('station.' + sid);
		ch.listen('.StationNoteUpdated', (e: { message?: string; author_name?: string; updated_at?: string }) => {
			stationNote = {
				message: e.message ?? '',
				author_name: e.author_name,
				updated_at: e.updated_at,
			};
		});
		// Real-time: refetch queue when status changes (call, serve, transfer, complete, no-show, etc.)
		ch.listen('.status_update', () => {
			fetchQueue(true);
		});
		return () => {
			Echo.leave('station.' + sid);
		};
	});

	// Real-time: refetch when client arrives or status changes (e.g. after custom path approve)
	$effect(() => {
		const hasCountdowns = Object.values(noShowCountdown).some((v) => v > 0);
		if (!hasCountdowns) return;
		const iv = setInterval(() => {
			noShowCountdown = Object.fromEntries(
				Object.entries(noShowCountdown).map(([k, v]) => [k, Math.max(0, v - 1)])
			);
		}, 1000);
		return () => clearInterval(iv);
	});

	/** Scanner modal countdown: decrement scanCountdown so Extend adds time correctly. */
	$effect(() => {
		if (!showQrScanner) {
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
				// Defer close to avoid "Cannot transition to a new state, already under transition"
				queueMicrotask(() => { showQrScanner = false; });
			}
		}, 1000);
		scanCountdownIntervalId = id;
		return () => {
			if (scanCountdownIntervalId != null) clearInterval(scanCountdownIntervalId);
			scanCountdownIntervalId = null;
		};
	});

	function extendScannerCountdown() {
		scanCountdown += Math.max(0, Number(display_scan_timeout_seconds) || 20);
	}

	// Ensure HID input inside Scan token dialog receives focus when dialog opens.
	$effect(() => {
		if (!showStationTokenScanner || !hidModalInputEl) return;
		queueMicrotask(() => {
			hidModalInputEl?.focus();
		});
	});

	// Ensure HID input inside QR auth modals receives focus when QR scanner is active.
	$effect(() => {
		if (!showQrScanner || authType !== 'qr' || !hidModalInputEl) return;
		queueMicrotask(() => {
			hidModalInputEl?.focus();
		});
	});

	// HID keyboard-wedge handler: route scans from hidden inputs to the appropriate scanner flow.
	function onHidKeydown(e: KeyboardEvent) {
		if (e.key !== 'Enter') return;
		e.preventDefault();
		const raw = hidScanValue.trim();
		if (!raw) return;
		if (showStationTokenScanner) {
			handleStationTokenScan(raw);
		} else if (showQrScanner && authType === 'qr') {
			handleQrScan(raw);
		}
		hidScanValue = '';
	}

	function formatDuration(iso: string): string {
		const d = new Date(iso);
		const now = new Date();
		const mins = Math.floor((now.getTime() - d.getTime()) / 60000);
		if (mins < 1) return '\u003C 1 min';
		if (mins < 60) return `${mins} min`;
		return `${Math.floor(mins / 60)}h ${mins % 60}m`;
	}

	function categoryBadgeClass(cat: string): string {
		const c = (cat || 'Regular').toLowerCase();
		if (c === 'pwd' || c === 'senior' || c === 'pregnant') return 'preset-filled-warning-500';
		return 'preset-tonal text-surface-950';
	}

	function switchStation(s: StationInfo) {
		router.visit(`/station/${s.id}`);
	}

	onMount(() => {
		ensureVoicesLoaded((voices) => {
			availableTtsVoices = voices.map((v) => ({ name: v.name, lang: v.lang || '' }));
		});
	});

	async function saveDisplaySettings(updates: {
		display_audio_muted?: boolean;
		display_audio_volume?: number;
	}) {
		if (!station || !queue || displaySettingsSaving) return;
		displaySettingsSaving = true;
		const body: {
			display_audio_muted?: boolean;
			display_audio_volume?: number;
		} = {};
		if (updates.display_audio_muted !== undefined) body.display_audio_muted = updates.display_audio_muted;
		if (updates.display_audio_volume !== undefined) body.display_audio_volume = updates.display_audio_volume;
		const { ok, data } = await api('PUT', `/api/stations/${station.id}/display-settings`, body);
		displaySettingsSaving = false;
		if (ok && data && typeof data === 'object' && 'display_audio_muted' in data) {
			const d = data as {
				display_audio_muted?: boolean;
				display_audio_volume?: number;
			};
			queue = {
				...queue,
				display_audio_muted: d.display_audio_muted ?? queue.display_audio_muted,
				display_audio_volume: d.display_audio_volume ?? queue.display_audio_volume,
			};
		}
	}

	async function togglePriorityFirst(priorityFirst: boolean) {
		if (!station || actionLoading || !queue) return;
		const prev = queue.priority_first ?? true;
		// Optimistic update: reflect toggle immediately so it feels responsive
		queue = { ...queue, priority_first: priorityFirst };
		actionLoading = 'toggle';
		const { ok } = await api('POST', `/api/stations/${station.id}/priority-first`, {
			priority_first: priorityFirst,
		});
		if (ok) {
			await fetchQueue(true, () => { actionLoading = null; });
		} else {
			actionLoading = null;
			// Revert on failure
			queue = { ...queue, priority_first: prev };
			toaster.error({ title: 'Failed to update priority setting' });
		}
	}

	async function callNext() {
		const target = queue?.next_to_call ?? queue?.waiting?.[0];
		if (!target || actionLoading || atCapacity) return;
		const sessionId = (target as { session_id: number }).session_id;
		const alias = (target as { session_id: number; alias?: string }).alias ?? (queue?.waiting?.find((w) => w.session_id === sessionId)?.alias ?? '');

		// Flow redirection: when call would skip priority, staff needs auth; admin/supervisor call directly
		if (queue?.call_next_requires_override && needsAuthForOverride) {
			callNextSession = { session_id: sessionId, alias };
			showCallNextOverrideModal = true;
			resetAuthState();
			if (authUser && authUser.id) overrideSupervisorId = authUser.id;
			return;
		}

		actionLoading = 'call';
		const { ok, data } = await api('POST', `/api/sessions/${sessionId}/call`, {});
		if (ok) {
			await fetchQueue(true, () => { actionLoading = null; });
		} else {
			actionLoading = null;
			toaster.error({ title: (data as { message?: string })?.message ?? 'Call failed' });
		}
	}

	async function callNextWithAuth() {
		const s = callNextSession;
		if (!s || actionLoading) return;
		const authBody = buildAuthBody();
		if (!authBody) return;
		actionLoading = 'call';
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/call`, authBody);
		actionLoading = null;
		if (ok) {
			showCallNextOverrideModal = false;
			callNextSession = null;
			overridePin = '';
			overrideSupervisorId = null;
			resetAuthState();
			await fetchQueue(true, () => { actionLoading = null; });
		} else {
			toaster.error({ title: (data as { message?: string })?.message ?? 'Call failed' });
		}
	}

	async function serve(s: ServingSession) {
		if (!s || s.status !== 'called' || actionLoading) return;
		actionLoading = `serve-${s.session_id}`;
		const body = station ? { station_id: station.id } : {};
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/serve`, body);
		if (ok) {
			await fetchQueue(true, () => { actionLoading = null; });
		} else {
			actionLoading = null;
			toaster.error({ title: (data as { message?: string })?.message ?? 'Serve failed' });
		}
	}

	async function serveFromWaiting(w: WaitingSession) {
		if (!w || !station || actionLoading) return;
		actionLoading = `serve-${w.session_id}`;
		const { ok, data } = await api('POST', `/api/sessions/${w.session_id}/serve`, {
			station_id: station.id,
		});
		if (ok) {
			await fetchQueue(true, () => { actionLoading = null; });
		} else {
			actionLoading = null;
			toaster.error({ title: (data as { message?: string })?.message ?? 'Serve failed' });
		}
	}

	async function handleStationTokenScan(decodedText: string) {
		if (stationScanHandled || !station) return;
		stationScanHandled = true;
		scannedSessionError = '';
		scannedSessionErrorCode = null;
		const raw = decodedText.trim();
		const qrHash = raw.includes('/') ? (raw.split('/').pop() ?? raw).split('?')[0].trim() : raw;
		if (!qrHash) {
			toaster.create({ type: 'error', title: 'Invalid scan.' });
			showStationTokenScanner = false;
			return;
		}
		showStationTokenScanner = false;
		try {
			const { ok, data } = await api('GET', `/api/stations/${station.id}/session-by-token?qr_hash=${encodeURIComponent(qrHash)}`);
			if (ok && data && typeof data === 'object' && 'session_id' in data) {
				const s = data as ScannedSession;
				scannedSession = s;
				scannedSessionError = '';
				scannedSessionErrorCode = null;
				if (!s.at_this_station) {
					toaster.create({
						type: 'warning',
						title: 'Client at another station',
						description: `This client is at ${s.current_station ?? 'another station'}, not here.`,
						duration: 8000
					});
				} else if (s.status === 'waiting' || s.status === 'called') {
					toaster.create({
						type: 'success',
						title: `Scanned: ${s.alias}`,
						description: `${s.track} · Step ${s.current_step_order} of ${s.total_steps}. Ready to serve.`,
						action: { label: 'Mark as serving', onClick: serveScannedSession },
						duration: 10000
					});
				} else if (s.status === 'serving') {
					const isLast = (s.current_step_order ?? 0) >= (s.total_steps ?? 1);
					toaster.create({
						type: 'success',
						title: `Scanned: ${s.alias}`,
						description: `${s.track} · Step ${s.current_step_order} of ${s.total_steps}.`,
						action: {
							label: isLast ? 'Complete session' : 'Send to next process',
							onClick: isLast ? completeScannedSession : transferScannedSession
						},
						duration: 10000
					});
				}
			} else {
				scannedSession = null;
				const payload = (data && typeof data === 'object' ? data : {}) as { message?: string; error_code?: string };
				scannedSessionError = payload?.message ?? 'Token not found or not in use.';
				scannedSessionErrorCode = payload?.error_code ?? null;
				if (payload?.error_code === 'not_registered') {
					toaster.create({
						type: 'warning',
						title: 'Token not registered',
						description: 'Send the client to triage to register and get in the queue.',
						action: {
							label: 'Go to triage',
							onClick: () => router.visit('/triage')
						},
						duration: 10000
					});
				} else {
					toaster.create({
						type: 'error',
						title: payload?.message ?? 'Token not found or not in use.',
						duration: 8000
					});
				}
			}
		} catch {
			scannedSession = null;
			toaster.create({
				type: 'error',
				title: 'Request failed. Check your connection and try again.',
				duration: 8000
			});
			scannedSessionError = '';
			scannedSessionErrorCode = null;
		}
	}

	function closeScannedSessionPanel() {
		scannedSession = null;
		scannedSessionError = '';
		scannedSessionErrorCode = null;
		stationScanHandled = false;
	}

	function openStationTokenScanner() {
		scannedSession = null;
		scannedSessionError = '';
		scannedSessionErrorCode = null;
		stationScanHandled = false;
		showStationTokenScanner = true;
	}

	async function serveScannedSession() {
		const s = scannedSession;
		if (!s || !station || actionLoading || !s.at_this_station) return;
		if (s.status !== 'waiting' && s.status !== 'called') return;
		actionLoading = `serve-${s.session_id}`;
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/serve`, { station_id: station.id });
		actionLoading = null;
		if (ok) {
			closeScannedSessionPanel();
			await fetchQueue(true);
		} else {
			toaster.error({ title: (data as { message?: string })?.message ?? 'Serve failed' });
		}
	}

	async function transferScannedSession() {
		const s = scannedSession;
		if (!s || actionLoading || s.status !== 'serving') return;
		actionLoading = `transfer-${s.session_id}`;
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/transfer`, { mode: 'standard' });
		actionLoading = null;
		if (ok) {
			closeScannedSessionPanel();
			await fetchQueue(true);
		} else {
			toaster.error({ title: (data as { message?: string })?.message ?? 'Transfer failed' });
		}
	}

	async function completeScannedSession() {
		const s = scannedSession;
		if (!s || actionLoading || s.status !== 'serving') return;
		actionLoading = `complete-${s.session_id}`;
		const { ok } = await api('POST', `/api/sessions/${s.session_id}/complete`, {});
		actionLoading = null;
		if (ok) {
			closeScannedSessionPanel();
			await fetchQueue(true);
		} else {
			toaster.error({ title: 'Complete failed' });
		}
	}

	async function transfer(s: ServingSession) {
		if (!s || s.status !== 'serving' || actionLoading) return;
		actionLoading = `transfer-${s.session_id}`;
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/transfer`, { mode: 'standard' });
		if (ok) {
			const d = data as { action_required?: string };
			if (d?.action_required === 'complete') {
				toaster.error({ title: 'No next process in track. Complete the session instead.' });
			}
			await fetchQueue(true, () => { actionLoading = null; });
		} else {
			actionLoading = null;
			toaster.error({ title: (data as { message?: string })?.message ?? 'Transfer failed' });
		}
	}

	async function complete(s: ServingSession) {
		if (!s || s.status !== 'serving' || actionLoading) return;
		actionLoading = `complete-${s.session_id}`;
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/complete`, {});
		if (ok) {
			await fetchQueue(true, () => { actionLoading = null; });
		} else {
			actionLoading = null;
			toaster.error({ title: (data as { message?: string })?.message ?? 'Complete failed' });
		}
	}

	function openCancelModal(s: { session_id: number; alias: string; status: string }) {
		if (!s || actionLoading) return;
		cancelModalSession = { session_id: s.session_id, alias: s.alias, status: s.status };
	}

	async function confirmCancel() {
		const s = cancelModalSession;
		if (!s || actionLoading) return;
		actionLoading = `cancel-${s.session_id}`;
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/cancel`, {});
		actionLoading = null;
		if (ok) {
			cancelModalSession = null;
			await fetchQueue(true);
		} else {
			toaster.error({ title: (data as { message?: string })?.message ?? 'Cancel failed' });
		}
	}

	async function hold(s: ServingSession) {
		if (!s || s.status !== 'serving' || actionLoading) return;
		if ((queue?.station?.holding_count ?? 0) >= (queue?.station?.holding_capacity ?? 3)) {
			toaster.error({ title: 'Holding area full (3/3). Resume a client first.' });
			return;
		}
		actionLoading = `hold-${s.session_id}`;
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/hold`, {});
		actionLoading = null;
		if (ok) {
			await fetchQueue(true);
		} else {
			const payload = (data as { message?: string; error_code?: string }) ?? {};
			const msg = payload.message ?? 'Move to holding failed';
			if (payload.error_code === 'holding_full') {
				toaster.error({ title: 'Holding area full. Resume a client first.' });
			} else {
				toaster.error({ title: msg });
			}
		}
	}

	async function resumeFromHold(h: HoldingSession) {
		if (!h || actionLoading) return;
		actionLoading = `resume-${h.session_id}`;
		const { ok, data } = await api('POST', `/api/sessions/${h.session_id}/resume-from-hold`, {});
		actionLoading = null;
		if (ok) {
			await fetchQueue(true);
		} else {
			const payload = (data as { message?: string; error_code?: string }) ?? {};
			if (payload.error_code === 'at_capacity') {
				toaster.error({ title: 'Station at capacity. Complete or transfer a client first.' });
			} else {
				toaster.error({ title: payload.message ?? 'Resume failed' });
			}
		}
	}

	function openNoShowModal(s: ServingSession) {
		noShowModalSession = s;
	}

	async function noShowWithOptions(enqueueBackOption: boolean, extend: boolean, lastCall: boolean) {
		const s = noShowModalSession;
		if (!s || actionLoading) return;
		actionLoading = 'noShow';
		noShowModalSession = null;
		const body: { enqueue_back?: boolean; extend?: boolean; last_call?: boolean } = {};
		if (enqueueBackOption) body.enqueue_back = true;
		if (extend) body.extend = true;
		if (lastCall) body.last_call = true;
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/no-show`, body);
		if (ok) {
			await fetchQueue(true, () => { actionLoading = null; });
		} else {
			actionLoading = null;
			toaster.error({ title: (data as { message?: string })?.message ?? 'No-show failed' });
		}
	}

	function resetAuthState() {
		authType = 'pin';
		tempCodeEntered = '';
		tempQrScanToken = '';
		showQrScanner = false;
		qrScanHandled = false;
	}

	function openOverrideModal(s: ServingSession) {
		overrideSession = s;
		showOverrideModal = true;
		resetAuthState();
		if (authUser && authUser.id) overrideSupervisorId = authUser.id;
	}

	function openForceCompleteModal(s: ServingSession) {
		forceCompleteSession = s;
		forceCompleteReason = '';
		showForceCompleteModal = true;
		resetAuthState();
		if (authUser && authUser.id) overrideSupervisorId = authUser.id;
	}

	function handleQrScan(decodedText: string) {
		if (qrScanHandled) return;
		qrScanHandled = true;
		tempQrScanToken = decodedText.trim();
		showQrScanner = false;
	}

	function buildAuthBody(): Record<string, unknown> | null {
		if (authType === 'pin') {
			const code = tempCodeEntered.trim();
			if (code.length !== 6) return null;
			return { auth_type: 'pin', temp_code: code };
		}
		if (authType === 'qr') {
			if (!tempQrScanToken.trim()) return null;
			return { auth_type: 'qr', qr_scan_token: tempQrScanToken.trim() };
		}
		return null;
	}

	function canConfirmAuth(): boolean {
		if (authType === 'request_approval') return true;
		if (authType === 'pin') return tempCodeEntered.trim().length === 6;
		if (authType === 'qr') return !!tempQrScanToken.trim();
		return false;
	}

	async function override() {
		const s = overrideSession;
		if (!s || (!overrideTargetTrackId && !overrideIsCustom) || (overrideIsCustom && !overrideReason.trim()) || actionLoading) return;
		if (needsAuthForOverride && authType === 'request_approval') {
			await requestApprovalOverride();
			return;
		}
		const authBody = needsAuthForOverride ? buildAuthBody() : {};
		if (needsAuthForOverride && !authBody) return;
		if (overrideIsCustom) {
			// Staff cannot define custom path; use request approval (must run before setting actionLoading)
			await requestApprovalOverride();
			return;
		}
		actionLoading = 'override';
		const overrideBody: { target_track_id?: number; custom_steps?: number[]; reason: string } = {
			reason: overrideReason.trim(),
			target_track_id: overrideTargetTrackId ?? undefined,
		};
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/override`, {
			...overrideBody,
			...authBody,
		});
		actionLoading = null;
		if (ok) {
			showOverrideModal = false;
			overrideSession = null;
			overrideTargetTrackId = null;
			overrideReason = '';
			overridePin = '';
			overrideSupervisorId = null;
			resetAuthState();
			await fetchQueue(true);
		} else {
			toaster.error({ title: (data as { message?: string })?.message ?? 'Override failed' });
		}
	}

	async function requestApprovalOverride() {
		const s = overrideSession;
		if (!s || (!overrideTargetTrackId && !overrideIsCustom) || (overrideIsCustom && !overrideReason.trim()) || actionLoading) return;
		actionLoading = 'override';
		const body: { session_id: number; action_type: string; reason: string; target_track_id?: number | null; is_custom?: boolean } = {
			session_id: s.session_id,
			action_type: 'override',
			reason: overrideReason.trim(),
		};
		if (overrideIsCustom) {
			body.is_custom = true;
		} else {
			body.target_track_id = overrideTargetTrackId ?? undefined;
		}
		let ok = false;
		try {
			// Use AbortController timeout: server completes but client may not receive response (e.g. Docker/proxy)
			const ac = new AbortController();
			const t = setTimeout(() => ac.abort(), 10000);
			const res = await fetch('/api/permission-requests', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-CSRF-TOKEN': getCsrfToken(),
					'X-Requested-With': 'XMLHttpRequest',
				},
				credentials: 'same-origin',
				body: JSON.stringify(body),
				signal: ac.signal,
			});
			clearTimeout(t);
			if (res.status === 419) {
				toaster.error({ title: MSG_SESSION_EXPIRED });
				return;
			}
			const data = (await res.json().catch(() => ({}))) as { message?: string };
			ok = res.ok;
		} catch (e) {
			actionLoading = null;
			const isAbort = e instanceof Error && e.name === 'AbortError';
			const isNetwork = e instanceof TypeError && (e as Error).message === 'Failed to fetch';
			toaster.error({
				title: isAbort
					? 'Request timed out. The request may have succeeded – check Program Overrides.'
					: isNetwork
						? MSG_NETWORK_ERROR
						: 'Failed to send request',
			});
			return;
		}
		actionLoading = null;
		if (ok) {
			showOverrideModal = false;
			overrideSession = null;
			overrideTargetTrackId = null;
			overrideIsCustom = false;
			overrideReason = '';
			resetAuthState();
			window.location.href = '/program-overrides';
		} else {
			toaster.error({ title: 'Failed to send request' });
		}
	}

	async function requestApprovalForceComplete() {
		const s = forceCompleteSession;
		if (!s || !forceCompleteReason.trim() || actionLoading) return;
		actionLoading = 'force-complete';
		const { ok } = await api('POST', '/api/permission-requests', {
			session_id: s.session_id,
			action_type: 'force_complete',
			reason: forceCompleteReason.trim(),
		});
		actionLoading = null;
		if (ok) {
			showForceCompleteModal = false;
			forceCompleteSession = null;
			forceCompleteReason = '';
			resetAuthState();
			await fetchQueue(true);
			router.visit('/program-overrides');
		} else {
			toaster.error({ title: 'Failed to send request' });
		}
	}

	async function forceComplete() {
		const s = forceCompleteSession;
		if (!s || !forceCompleteReason.trim() || actionLoading) return;
		if (needsAuthForOverride && authType === 'request_approval') {
			await requestApprovalForceComplete();
			return;
		}
		const authBody = needsAuthForOverride ? buildAuthBody() : {};
		if (needsAuthForOverride && !authBody) return;
		actionLoading = 'force-complete';
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/force-complete`, {
			reason: forceCompleteReason.trim(),
			...authBody,
		});
		actionLoading = null;
		if (ok) {
			showForceCompleteModal = false;
			forceCompleteSession = null;
			forceCompleteReason = '';
			overridePin = '';
			overrideSupervisorId = null;
			resetAuthState();
			await fetchQueue(true);
		} else {
			toaster.error({ title: (data as { message?: string })?.message ?? 'Force complete failed' });
		}
	}

	function isLastStep(s: ServingSession): boolean {
		return (s.current_step_order ?? 0) >= (s.total_steps ?? 1);
	}

	function canNoShow(s: ServingSession): boolean {
		if (s.status === 'serving') return true;
		if (s.status !== 'called') return false;
		const remaining = noShowCountdown[s.session_id] ?? 0;
		return remaining <= 0;
	}

	const maxNoShowAttempts = $derived(queue?.max_no_show_attempts ?? 3);

	function noShowButtonLabel(s: ServingSession): string {
		const remaining = noShowCountdown[s.session_id] ?? 0;
		if (remaining > 0 && s.status === 'called') return `No-Show (${remaining}s)`;
		return s.no_show_attempts > 0 ? `No-Show (${s.no_show_attempts}/${maxNoShowAttempts})` : 'No-Show';
	}

	function toggleMore(s: ServingSession) {
		showMoreFor = showMoreFor === s.session_id ? null : s.session_id;
	}

	function openBackModal(s: ServingSession) {
		backModalSession = s;
	}

	function closeBackModal() {
		backModalSession = null;
	}

	async function sendBackOnlyFromModal() {
		const s = backModalSession;
		if (!s || actionLoading) return;
		actionLoading = `enqueue-back-${s.session_id}`;
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/enqueue-back`, {});
		actionLoading = null;
		if (ok) {
			backModalSession = null;
			await fetchQueue(true);
		} else {
			toaster.error({ title: (data as { message?: string })?.message ?? 'Enqueue back failed' });
		}
	}

	async function noShowAndBackFromModal() {
		const s = backModalSession;
		if (!s || actionLoading) return;
		if (!canNoShow(s)) {
			toaster.error({ title: 'No-show not yet available for this client.' });
			return;
		}
		const atMax = (s.no_show_attempts ?? 0) >= maxNoShowAttempts;
		if (atMax) {
			toaster.error({ title: 'Max no-show attempts reached for this client.' });
			return;
		}
		actionLoading = 'noShowBack';
		const body: { enqueue_back?: boolean; extend?: boolean; last_call?: boolean } = { enqueue_back: true };
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/no-show`, body);
		if (ok) {
			backModalSession = null;
			await fetchQueue(true, () => {
				actionLoading = null;
			});
		} else {
			actionLoading = null;
			toaster.error({ title: (data as { message?: string })?.message ?? 'No-show failed' });
		}
	}

	async function enqueueBack(s: ServingSession) {
		if (!s || actionLoading) return;
		actionLoading = `enqueue-back-${s.session_id}`;
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/enqueue-back`, {});
		actionLoading = null;
		if (ok) {
			await fetchQueue(true);
		} else {
			toaster.error({ title: (data as { message?: string })?.message ?? 'Enqueue back failed' });
		}
	}

	/** Format note updated_at for display (e.g. "2 min ago", "Today 10:30"). */
	function formatNoteTime(iso: string | undefined): string {
		if (!iso) return '';
		try {
			const d = new Date(iso);
			const now = new Date();
			const diffMs = now.getTime() - d.getTime();
			const diffMins = Math.floor(diffMs / 60000);
			const diffHours = Math.floor(diffMins / 60);
			const diffDays = Math.floor(diffHours / 24);
			if (diffMins < 1) return 'Just now';
			if (diffMins < 60) return `${diffMins} min ago`;
			if (diffHours < 24) return `${diffHours}h ago`;
			if (diffDays === 1) return 'Yesterday';
			if (diffDays < 7) return `${diffDays} days ago`;
			return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: d.getFullYear() !== now.getFullYear() ? 'numeric' : undefined });
		} catch {
			return '';
		}
	}
</script>

<svelte:head>
	<title>Station — FlexiQueue</title>
</svelte:head>

<MobileLayout headerTitle={station?.name ?? 'Station'} {queueCount} {processedToday}>
	<!-- Global HID barcode input (sr-only) to support Station token scanner and QR auth modals. Always enabled on this page. -->
	<input
		type="text"
		autocomplete="off"
		inputmode="none"
		aria-label="Barcode scanner input"
		class="sr-only"
		bind:value={hidScanValue}
		bind:this={hidGlobalInputEl}
		onkeydown={onHidKeydown}
	/>
	<div class="flex flex-col gap-4 md:gap-6 text-surface-950 w-full max-w-2xl md:max-w-5xl lg:max-w-6xl mx-auto px-4 md:px-6 py-4 md:py-6">
		{#if !station}
			<div class="w-full max-w-4xl mx-auto space-y-8 py-8">
				<div class="text-center space-y-3">
					<div class="mx-auto w-16 h-16 rounded-2xl bg-primary-500/10 flex items-center justify-center text-primary-500 mb-2">
						<Monitor class="w-8 h-8" />
					</div>
					<h1 class="text-3xl font-bold tracking-tight text-surface-950">Select your station</h1>
					<p class="text-surface-950/60 max-w-md mx-auto">Choose a station to begin managing the queue and serving clients.</p>
				</div>

				{#if canSwitchStation && stations.length > 0}
					<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 auto-rows-fr">
						{#each stations as s (s.id)}
							<button 
								type="button" 
								class="group relative flex flex-col p-6 rounded-2xl border border-surface-200 bg-surface-50 hover:bg-white hover:border-primary-500/30 hover:shadow-xl hover:shadow-primary-500/5 transition-all duration-300 transform hover:-translate-y-1 text-left"
								onclick={() => switchStation(s)}
							>
								<div class="flex justify-between items-start mb-4">
									<div class="p-2.5 rounded-xl bg-surface-100 dark:bg-white/5 group-hover:bg-primary-500/10 transition-colors">
										<Monitor class="w-6 h-6 text-surface-600 group-hover:text-primary-500 transition-colors" />
									</div>
									<ArrowUpRight class="w-5 h-5 text-surface-300 group-hover:text-primary-500 opacity-0 group-hover:opacity-100 transition-all transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" />
								</div>
								
								<div class="mt-auto">
									<h3 class="font-bold text-lg text-surface-950 group-hover:text-primary-600 transition-colors">{s.name}</h3>
									<p class="text-sm text-surface-500 mt-1">Click to select station</p>
								</div>

								<!-- Subtle interactive overlay -->
								<div class="absolute inset-0 rounded-2xl ring-1 ring-inset ring-primary-500/0 group-hover:ring-primary-500/20 transition-all"></div>
							</button>
						{/each}
					</div>
				{:else}
					<div class="bg-surface-50 border border-surface-200 rounded-2xl p-12 text-center max-w-md mx-auto">
						<div class="mx-auto w-12 h-12 rounded-full bg-surface-100 flex items-center justify-center text-surface-400 mb-4">
							<AlertTriangle class="w-6 h-6" />
						</div>
						<p class="font-bold text-surface-950 text-lg">No station assigned</p>
						<p class="mt-2 text-surface-950/60">Contact your administrator to assign you to a station or grant switching permissions.</p>
					</div>
				{/if}
			</div>
		{:else if loading}
			<div class="flex justify-center py-16 md:py-24">
				<span class="loading-spinner loading-lg text-primary-500"></span>
			</div>
		{:else if queue}
			<!-- Station switcher (pill bar) -->
			{#if canSwitchStation && stations.length > 1}
				<div class="flex gap-2 overflow-x-auto pb-1 -mx-1" role="tablist" aria-label="Switch station">
					{#each stations as s (s.id)}
						<button
							type="button"
							role="tab"
							aria-selected={s.id === station.id}
							class="btn btn-sm shrink-0 touch-target-h px-4 {s.id === station.id ? 'preset-filled-primary-500' : 'preset-tonal'}"
							onclick={() => switchStation(s)}
						>
							{s.name}
						</button>
					{/each}
				</div>
			{/if}

			<!-- Toolbar: capacity, priority, display audio (single row, wraps on small) -->
			<div class="flex flex-wrap items-center justify-between gap-3 py-2 px-3 rounded-container bg-surface-50/80 border border-surface-200 elevation-card">
				<div class="flex items-center gap-3 touch-target-h">
					<span class="text-sm font-medium text-surface-950/80 tabular-nums" aria-label="Serving count">
						Serving {servingCount}/{clientCapacity}
					</span>
					{#if canSwitchStation}
						<label for="priority-first-switch" class="label cursor-pointer gap-2 items-center touch-target-h">
							<span class="label-text text-sm">Priority first</span>
							<div class="relative inline-block w-11 h-5">
								<input
									id="priority-first-switch"
									type="checkbox"
									class="peer appearance-none w-11 h-5 bg-surface-200 rounded-full checked:bg-surface-800 cursor-pointer transition-colors duration-300 disabled:opacity-50 disabled:cursor-not-allowed"
									checked={queue?.priority_first ?? true}
									disabled={actionLoading === 'toggle'}
									onchange={(e) => togglePriorityFirst((e.target as HTMLInputElement).checked)}
								/>
								<span class="absolute top-0 left-0 w-5 h-5 bg-surface-950 rounded-full border border-surface-300 shadow-sm transition-transform duration-300 peer-checked:translate-x-6 peer-checked:border-surface-800 pointer-events-none" aria-hidden="true"></span>
							</div>
						</label>
					{/if}
				</div>
				<button
					type="button"
					class="btn preset-tonal btn-sm gap-2 touch-target md:min-w-auto px-3"
					title="Scan client token to identify and act"
					onclick={openStationTokenScanner}
					aria-label="Scan token"
				>
					<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-surface-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
						<path stroke-linecap="round" stroke-linejoin="round" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
					</svg>
					<span class="hidden md:inline text-sm">Scan token</span>
				</button>
				<button
					type="button"
					class="btn preset-tonal btn-sm gap-2 touch-target md:min-w-auto px-3"
					title="Display board audio"
					onclick={() => (showDisplayAudioModal = true)}
					aria-label="Display board audio settings"
				>
					<Volume2 class="w-5 h-5 text-surface-600 shrink-0" />
					<span class="hidden md:inline text-sm">Display audio</span>
				</button>
			</div>

			<!-- Main content: grid on desktop, stack on mobile -->
			<div class="grid grid-cols-1 lg:grid-cols-12 gap-4 md:gap-6">
				<!-- Left: Active (serving + call next) -->
				<div class="lg:col-span-7 flex flex-col gap-4 md:gap-5">
					{#if scannedSession}
						<div class="rounded-container bg-surface-50 border border-surface-200 elevation-card p-4 md:p-5 space-y-2">
							<div class="flex items-start justify-between gap-3">
								<div class="space-y-1">
									<p class="text-xs font-semibold text-surface-950/70 uppercase tracking-wide">Scanned session</p>
									<p class="text-sm text-surface-950">
										Token
										<span class="font-mono font-semibold text-primary-500">{scannedSession.alias}</span>
										<span class="text-surface-950/60">
											· {scannedSession.track} · Step {scannedSession.current_step_order} of {scannedSession.total_steps}
										</span>
									</p>
									<p class="text-xs text-surface-600">
										Status: {scannedSession.status} · At {scannedSession.current_station}
										{#if !scannedSession.at_this_station}
											<span class="text-warning-700"> (not at this station)</span>
										{/if}
									</p>
									{#if scannedSession.unverified}
										<div class="flex flex-wrap items-center gap-2 mt-1">
											<span
												class="text-[11px] px-2 py-0.5 rounded preset-filled-warning-500/30 text-warning-800"
												title="Identity not yet verified"
											>
												Unverified
											</span>
											<a
												href="/triage?highlight_session_id={scannedSession.session_id}"
												class="text-[11px] px-2 py-0.5 rounded preset-tonal text-surface-900 hover:underline touch-target-h"
												title="Open triage and highlight this client"
											>
												View in triage
											</a>
										</div>
									{/if}
								</div>
								<button
									type="button"
									class="btn btn-sm preset-tonal touch-target-h px-2"
									onclick={closeScannedSessionPanel}
									aria-label="Close scanned session summary"
								>
									<X class="w-4 h-4" />
								</button>
							</div>
						</div>
					{/if}

					<!-- Serving / Called cards (one per client) -->
					{#each queue.serving as s (s.session_id)}
						<div class="relative rounded-container bg-surface-50 border border-surface-200 elevation-card p-4 md:p-5 space-y-3">
							<button
								type="button"
								class="btn btn-sm preset-tonal touch-target-h px-2 absolute top-3 right-3"
								disabled={!!actionLoading}
								aria-label="Cancel session"
								title="Cancel"
								onclick={() => openCancelModal(s)}
							>
								<X class="w-4 h-4" />
							</button>
							<p class="text-xs font-medium text-surface-950/70 uppercase tracking-wide">
								{s.status === 'called' ? 'Calling' : 'Now Serving'}
							</p>
							<p class="text-2xl md:text-4xl font-bold text-primary-500 tabular-nums">{s.alias}</p>
							<div class="flex flex-wrap gap-2">
								{#if s.process_name}
									<span class="text-xs px-2 py-0.5 rounded preset-filled-primary-500/20 text-primary-700" title="Current process">{s.process_name}</span>
								{/if}
								<span class="text-xs px-2 py-0.5 rounded preset-outlined text-surface-950">{s.track}</span>
								<CategoryBadge category={s.client_category ?? 'Regular'} badgeClass={categoryBadgeClass(s.client_category)} />
								{#if s.unverified}
									<a
										href="/triage?highlight_session_id={s.session_id}"
										class="text-xs px-2 py-0.5 rounded preset-filled-warning-500/30 text-warning-800 hover:preset-filled-warning-500/50 touch-target-h inline-block"
										title="Identity not yet verified — go to triage"
									>
										Unverified
									</a>
								{/if}
							</div>
							<p class="text-sm text-surface-950/70">
								Step {s.current_step_order} of {s.total_steps}
								{#if s.status === 'serving'}
									· Started {formatDuration(s.started_at)} ago
								{/if}
							</p>
							<div class="flex flex-col gap-3 pt-2">
								{#if s.status === 'called'}
									<button
										type="button"
										class="btn preset-filled-primary-500 btn-lg touch-target-h"
										disabled={!!actionLoading}
										onclick={() => serve(s)}
									>
										<span class="inline-flex items-center justify-center gap-2">
											<ArrowRight class="w-5 h-5" aria-hidden="true" />
											<span>{actionLoading === `serve-${s.session_id}` ? 'Processing…' : 'Serve'}</span>
										</span>
									</button>
									<div class="flex flex-wrap gap-2 items-center mt-2">
										<button
											type="button"
											class="btn preset-outlined btn-sm touch-target-h"
											disabled={!!actionLoading}
											onclick={() => openBackModal(s)}
										>
											<span class="inline-flex items-center justify-center gap-1.5">
												<RotateCcw class="w-4 h-4" aria-hidden="true" />
												<span>Back</span>
											</span>
										</button>
									</div>
								{:else}
									{#if isLastStep(s)}
										<button
											type="button"
											class="btn preset-filled-primary-500 btn-lg touch-target-h"
											disabled={!!actionLoading}
											onclick={() => complete(s)}
										>
											<span class="inline-flex items-center justify-center gap-2">
												<CheckCircle class="w-5 h-5" aria-hidden="true" />
												<span>{actionLoading === `complete-${s.session_id}` ? 'Completing…' : 'Complete session'}</span>
											</span>
										</button>
									{:else}
										<button
											type="button"
											class="btn preset-filled-primary-500 btn-lg touch-target-h"
											disabled={!!actionLoading}
											onclick={() => transfer(s)}
										>
											<span class="inline-flex items-center justify-center gap-2">
												<ArrowRight class="w-5 h-5" aria-hidden="true" />
												<span>{actionLoading === `transfer-${s.session_id}` ? 'Transferring…' : 'Send to next process'}</span>
											</span>
										</button>
									{/if}
									<!-- Actions row: Hold, Back, More (compact buttons) -->
									<div class="flex flex-wrap gap-2 mt-2">
										<button
											type="button"
											class="btn preset-tonal btn-sm touch-target-h"
											disabled={!!actionLoading || (queue?.station?.holding_count ?? 0) >= (queue?.station?.holding_capacity ?? 3)}
											title={(queue?.station?.holding_count ?? 0) >= (queue?.station?.holding_capacity ?? 3)
												? 'Holding area full. Resume a client first.'
												: 'Move client to holding'}
											onclick={() => hold(s)}
										>
											<span class="inline-flex items-center justify-center gap-1">
												<PauseCircle class="w-4 h-4" aria-hidden="true" />
												<span>Hold</span>
											</span>
										</button>
										<button
											type="button"
											class="btn preset-outlined btn-sm touch-target-h"
											disabled={!!actionLoading}
											onclick={() => openBackModal(s)}
										>
											<span class="inline-flex items-center justify-center gap-1">
												<RotateCcw class="w-4 h-4" aria-hidden="true" />
												<span>Back</span>
											</span>
										</button>
										<button
											type="button"
											class="btn btn-ghost btn-sm touch-target-h md:hidden"
											disabled={!!actionLoading}
											onclick={() => toggleMore(s)}
											aria-expanded={showMoreFor === s.session_id}
											aria-controls={`more-${s.session_id}`}
											title="More actions"
										>
											<span class="inline-flex items-center justify-center gap-1">
												<MoreHorizontal class="w-4 h-4" aria-hidden="true" />
												<span>More</span>
											</span>
										</button>
									</div>

									<!-- Exceptions cluster (desktop) -->
									<div class="mt-3 pt-3 border-t border-surface-100 hidden md:flex flex-wrap items-center justify-between gap-2">
										<span class="text-xs text-surface-950/60">Exceptions</span>
										<div class="flex flex-wrap gap-2">
											<button
												type="button"
												class="btn preset-outlined btn-sm touch-target-h"
												disabled={!!actionLoading}
												onclick={() => openOverrideModal(s)}
												title="Override standard track"
											>
												<span class="inline-flex items-center justify-center gap-1.5">
													<Shuffle class="w-4 h-4" aria-hidden="true" />
													<span>Override</span>
												</span>
											</button>
											<button
												type="button"
												class="btn preset-outlined btn-sm touch-target-h text-warning-700 border-warning-300"
												disabled={!!actionLoading}
												onclick={() => openForceCompleteModal(s)}
												title="End session outside the normal flow"
											>
												<span class="inline-flex items-center justify-center gap-1.5">
													<AlertTriangle class="w-4 h-4" aria-hidden="true" />
													<span>Force complete</span>
												</span>
											</button>
										</div>
									</div>
									{#if showMoreFor === s.session_id}
										<div
											id={`more-${s.session_id}`}
											class="mt-2 rounded-container border border-surface-200 bg-surface-50 shadow-sm p-2 md:hidden"
										>
											<div class="flex flex-col gap-1.5">
												<button
													type="button"
													class="btn preset-outlined btn-sm w-full justify-between touch-target-h"
													disabled={!!actionLoading}
													onclick={() => {
														openOverrideModal(s);
														showMoreFor = null;
													}}
													title="Override standard track"
												>
													<span class="inline-flex items-center gap-1.5">
														<Shuffle class="w-4 h-4" aria-hidden="true" />
														<span>Override</span>
													</span>
												</button>
												<button
													type="button"
													class="btn preset-outlined btn-sm w-full justify-between touch-target-h text-warning-700 border-warning-300"
													disabled={!!actionLoading}
													onclick={() => {
														openForceCompleteModal(s);
														showMoreFor = null;
													}}
													title="End session outside the normal flow"
												>
													<span class="inline-flex items-center gap-1.5">
														<AlertTriangle class="w-4 h-4" aria-hidden="true" />
														<span>Force complete</span>
													</span>
												</button>
											</div>
										</div>
									{/if}
								{/if}
							</div>
						</div>
					{/each}

					<!-- Call Next (when capacity allows) -->
					{#if queue.serving.length === 0 || !atCapacity}
						<div class="rounded-container bg-surface-50 border border-surface-200 elevation-card p-5 md:p-6 text-center">
							<p class="text-surface-950/70 font-medium mb-3 text-sm md:text-base">
								{queue.serving.length > 0 ? 'Call another client' : 'No client active'}
							</p>
							{#if queue.waiting.length > 0 && !atCapacity}
					{@const nextSession = queue.next_to_call ? queue.waiting.find((w) => w.session_id === queue.next_to_call!.session_id) ?? queue.waiting[0] : queue.waiting[0]}
								<p class="text-sm text-surface-950/60 mb-3 flex flex-wrap items-center gap-1.5">
									<span class="flex items-center gap-1.5">
										<span>Next:</span>
										<span class="font-mono font-semibold text-surface-950">{nextSession.alias}</span>
										{#if nextSession.client_name}
											<span class="text-surface-950/80">· {nextSession.client_name}</span>
										{/if}
									</span>
									<span>
										<CategoryBadge
											category={nextSession.client_category ?? 'Regular'}
											badgeClass={categoryBadgeClass(nextSession.client_category)}
											size="sm"
										/>
									</span>
									{#if nextSession.unverified}
										<span
											class="text-[11px] px-2 py-0.5 rounded preset-filled-warning-500/30 text-warning-800"
											title="Identity not yet verified — see triage list"
										>
											Unverified
										</span>
									{/if}
								</p>
								<button
									type="button"
									class="btn preset-filled-primary-500 btn-lg min-h-[52px] w-full sm:w-auto px-8"
									disabled={!!actionLoading}
									onclick={callNext}
								>
									<span class="inline-flex items-center justify-center gap-2">
										<Volume2 class="w-5 h-5" aria-hidden="true" />
										<span>{actionLoading === 'call' ? 'Calling…' : 'Call Next'}</span>
									</span>
								</button>
							{:else if atCapacity}
								<p class="text-sm text-surface-950/60">At capacity ({servingCount}/{clientCapacity}). Transfer or complete a client first.</p>
							{:else}
								<p class="text-sm text-surface-950/60">Queue is empty</p>
								<p class="text-xs text-surface-950/50 mt-1">
									Tip: Sessions from triage go to the track's first station. Assign staff to that station in Admin → Users.
								</p>
							{/if}
						</div>
					{/if}
				</div>

				<!-- Right: Queue + holding + stats (desktop) / below left on mobile -->
				<div class="lg:col-span-5 flex flex-col gap-4 md:gap-5">
					<!-- On hold -->
					<div class="rounded-container bg-surface-50 border border-surface-200 elevation-card p-4 md:p-5">
						<h3 class="text-xs font-semibold text-surface-950/80 uppercase tracking-wide mb-3">On hold — {(queue.holding ?? []).length}/{queue.station?.holding_capacity ?? 3}</h3>
						{#if (queue.holding ?? []).length > 0}
							<ul class="space-y-2 max-h-[200px] lg:max-h-[260px] overflow-y-auto">
								{#each (queue.holding ?? []) as h (h.session_id)}
									<li class="grid gap-2 md:gap-3 items-center text-sm text-surface-950 py-1.5 border-b border-surface-100 last:border-0" style="grid-template-columns: minmax(0, 3ch) 1fr 1fr 1fr;">
										<span class="font-mono font-medium tabular-nums min-w-0 truncate">{h.alias}</span>
										<div class="flex items-center gap-1.5 min-w-0 overflow-hidden">
											<CategoryBadge category={h.client_category ?? 'Regular'} badgeClass={categoryBadgeClass(h.client_category)} size="sm" />
											{#if h.process_name}
												<span class="text-xs px-2 py-0.5 rounded preset-tonal text-surface-950/80 truncate">{h.process_name}</span>
											{/if}
										</div>
										<span class="text-surface-950/60 text-xs min-w-0 truncate">Held {formatDuration(h.held_at)}</span>
										<div class="flex justify-center min-w-0">
											<button
												type="button"
												class="btn preset-filled-primary-500 btn-sm touch-target-h"
												disabled={!!actionLoading}
												onclick={() => resumeFromHold(h)}
											>
												{actionLoading === `resume-${h.session_id}` ? '…' : 'Resume'}
											</button>
										</div>
									</li>
								{/each}
							</ul>
						{:else}
							<p class="text-sm text-surface-950/60 italic">No clients on hold.</p>
						{/if}
					</div>
					{#if queue.waiting.length > 0}
						<div class="rounded-container bg-surface-50 border border-surface-200 elevation-card p-4 md:p-5">
							<h3 class="text-xs font-semibold text-surface-950/80 uppercase tracking-wide mb-3">Waiting — {queue.waiting.length}</h3>
							<ul class="space-y-2 max-h-[280px] lg:max-h-[360px] overflow-y-auto">
								{#each queue.waiting as w (w.session_id)}
									<li class="grid gap-2 md:gap-3 items-center text-sm text-surface-950 py-1.5 border-b border-surface-100 last:border-0" style="grid-template-columns: minmax(0, 3ch) 1fr max-content auto;">
										<span class="font-mono font-medium tabular-nums min-w-0 truncate">{w.alias}</span>
										<div class="flex items-center gap-1.5 min-w-0 overflow-hidden flex-wrap">
											<CategoryBadge category={w.client_category ?? 'Regular'} badgeClass={categoryBadgeClass(w.client_category)} size="sm" />
											{#if w.unverified}
												<a
													href="/triage?highlight_session_id={w.session_id}"
													class="text-xs px-2 py-0.5 rounded preset-filled-warning-500/30 text-warning-800 hover:preset-filled-warning-500/50 touch-target-h"
													title="Identity not yet verified — go to triage"
												>
													Unverified
												</a>
											{/if}
											{#if w.process_name}
												<span class="text-xs px-2 py-0.5 rounded preset-tonal text-surface-950/80 truncate">{w.process_name}</span>
											{/if}
										</div>
										<span class="text-surface-950/60 text-xs min-w-0 truncate" title="{w.track}">{formatDuration(w.queued_at)}</span>
										<div class="flex justify-center min-w-0 gap-2">
											<button
												type="button"
												class="btn preset-filled-primary-500 btn-sm touch-target-h"
												disabled={!!actionLoading || atCapacity}
												title={atCapacity ? 'At capacity' : 'Start serving (no call)'}
												onclick={() => serveFromWaiting(w)}
											>
												{actionLoading === `serve-${w.session_id}` ? '…' : 'Start serving'}
											</button>
											<button
												type="button"
												class="btn preset-tonal btn-sm touch-target-h px-2"
												disabled={!!actionLoading}
												aria-label="Cancel session"
												title="Cancel"
												onclick={() => openCancelModal(w)}
											>
												<X class="w-4 h-4" />
											</button>
										</div>
									</li>
								{/each}
							</ul>
						</div>
					{/if}
					<div class="rounded-container bg-surface-50 border border-surface-200 p-4 md:p-5 text-center text-sm text-surface-950/70">
						Today: <strong class="text-surface-950">{queue.stats.total_served_today}</strong> served · Avg <strong class="text-surface-950">{queue.stats.avg_service_time_minutes}</strong> min
					</div>
				</div>

				<!-- Station notes: full width -->
				<div class="lg:col-span-12">
			<details class="rounded-container bg-surface-50 border border-surface-200 elevation-card overflow-hidden" bind:open={notesExpanded}>
				<summary class="cursor-pointer px-4 py-3 font-medium text-surface-950 select-none flex items-center justify-between gap-2 touch-target-h">
					<span class="flex items-center gap-2">
						<span class="inline-flex items-center gap-1">
							<StickyNote class="w-4 h-4 text-primary-500" aria-hidden="true" />
							<span>Station notes</span>
						</span>
						{#if notesExpanded}
							<ChevronUp class="size-5 shrink-0 text-surface-950/60" aria-hidden="true" />
						{:else}
							<ChevronDown class="size-5 shrink-0 text-surface-950/60" aria-hidden="true" />
						{/if}
					</span>
					{#if stationNote?.author_name}
						<span class="text-xs font-normal text-surface-950/60 truncate max-w-[50%]" title={stationNote.author_name}>
							by {stationNote.author_name}
						</span>
					{/if}
				</summary>
				<div class="px-4 pb-4 pt-1 space-y-4">
					{#if stationNote?.message}
						<div class="rounded-container bg-surface-100/80 border border-surface-200 p-3 space-y-2">
							<p class="text-sm text-surface-950/90 whitespace-pre-wrap break-words">{stationNote.message}</p>
							<div class="flex flex-wrap items-center gap-2 text-xs text-surface-950/70">
								{#if stationNote.author_name}
									<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded preset-filled-primary-500/20 text-primary-700 font-medium" title="Note author">
										{stationNote.author_name}
									</span>
								{/if}
								{#if stationNote.updated_at}
									<span class="text-surface-950/60">{formatNoteTime(stationNote.updated_at)}</span>
								{/if}
							</div>
						</div>
					{:else}
						<p class="text-sm text-surface-950/60 italic">No note yet. Add one below.</p>
					{/if}
					<form onsubmit={submitNote} class="flex flex-col gap-3">
						<label for="station-note-input" class="label py-0"><span class="label-text text-sm">Add or update note</span></label>
						<textarea
							id="station-note-input"
							class="textarea textarea-sm rounded-container border border-surface-200 w-full min-h-[80px]"
							placeholder="e.g. Back in 5 min"
							bind:value={noteMessage}
							maxlength="500"
							rows="2"
						></textarea>
						<div class="flex items-center justify-between gap-2">
							<span class="text-xs text-surface-950/50">{noteMessage.length}/500</span>
							<button type="submit" class="btn preset-filled-primary-500 btn-sm touch-target-h px-4" disabled={noteSubmitting}>
								{noteSubmitting ? 'Saving…' : 'Save note'}
							</button>
						</div>
					</form>
				</div>
			</details>
				</div>
			</div>
		{/if}
	</div>

	{#if noShowModalSession}
		{@const atMax = (noShowModalSession.no_show_attempts ?? 0) >= maxNoShowAttempts}
		<dialog open class="modal-dialog-center rounded-container" oncancel={(e) => e.preventDefault()}>
			<div class="card bg-surface-50 rounded-container shadow-xl p-6 text-surface-950">
				<h3 class="font-bold text-lg">Mark No-Show</h3>
				<p class="py-2">
					Mark <span class="font-mono font-semibold text-surface-950">{noShowModalSession.alias}</span> as no-show?
					{#if atMax}
						({noShowModalSession.no_show_attempts}/{maxNoShowAttempts}) Extend to allow one more call, or end the session.
					{:else}
						Client can be called again. Stay at front or send back to end of queue?
					{/if}
				</p>
				<div class="flex flex-wrap justify-end gap-2 mt-4">
					<button type="button" class="btn preset-tonal touch-target-h" onclick={() => (noShowModalSession = null)}>Cancel</button>
					{#if atMax}
						<button type="button" class="btn preset-tonal touch-target-h" onclick={() => noShowWithOptions(false, true, false)} disabled={!!actionLoading}>
							{actionLoading === 'noShow' ? 'Processing…' : 'Extend no show'}
						</button>
						<button type="button" class="btn preset-filled-warning-500 touch-target-h" onclick={() => noShowWithOptions(false, false, true)} disabled={!!actionLoading}>
							{actionLoading === 'noShow' ? 'Processing…' : 'Mark no-show (last call)'}
						</button>
					{:else}
						<button type="button" class="btn preset-tonal touch-target-h" onclick={() => noShowWithOptions(false, false, false)} disabled={!!actionLoading}>
							{actionLoading === 'noShow' ? 'Processing…' : 'Mark no-show only'}
						</button>
						<button type="button" class="btn preset-filled-warning-500 touch-target-h" onclick={() => noShowWithOptions(true, false, false)} disabled={!!actionLoading}>
							{actionLoading === 'noShow' ? 'Processing…' : 'Mark no-show and enqueue back'}
						</button>
					{/if}
				</div>
			</div>
			<!-- Per flexiqueue-ldd: backdrop does not close modal; only Cancel/buttons do -->
			<div class="modal-backdrop" aria-hidden="true"></div>
		</dialog>
	{/if}

	{#if backModalSession}
		{@const remaining = noShowCountdown[backModalSession.session_id] ?? 0}
		{@const atMax = (backModalSession.no_show_attempts ?? 0) >= maxNoShowAttempts}
		{@const canBackNoShow = canNoShow(backModalSession) && !atMax}
		<dialog open class="modal-dialog-center rounded-container" oncancel={(e) => e.preventDefault()}>
			<div class="card bg-surface-50 rounded-container shadow-xl p-6 text-surface-950 max-w-md">
				<h3 class="font-bold text-lg">Send back or mark no-show?</h3>
				<p class="text-sm text-surface-950/70 py-2">
					Choose what happens next for
					<span class="font-mono font-semibold text-surface-950">{backModalSession.alias}</span>.
				</p>
				<div class="space-y-3 mt-2">
					<button
						type="button"
						class="btn preset-outlined w-full justify-between touch-target-h"
						disabled={!!actionLoading}
						onclick={sendBackOnlyFromModal}
					>
						<span class="inline-flex items-center gap-2">
							<RotateCcw class="w-4 h-4" aria-hidden="true" />
							<span>Send back only</span>
						</span>
					</button>
					<div class="space-y-1">
						<button
							type="button"
							class="btn preset-filled-warning-500 w-full justify-between touch-target-h"
							disabled={!canBackNoShow || !!actionLoading}
							onclick={noShowAndBackFromModal}
						>
							<span class="inline-flex items-center gap-2">
								<UserX class="w-4 h-4" aria-hidden="true" />
								<span>Mark no-show &amp; send back</span>
							</span>
						</button>
						{#if backModalSession.status === 'called' && remaining > 0}
							<p class="text-xs text-surface-950/60">No-show available in {remaining}s.</p>
						{:else if atMax}
							<p class="text-xs text-surface-950/60">Max no-show attempts reached for this client.</p>
						{/if}
					</div>
				</div>
				<div class="flex justify-end gap-2 mt-4">
					<button
						type="button"
						class="btn preset-tonal touch-target-h"
						onclick={closeBackModal}
						disabled={!!actionLoading}
					>
						Cancel
					</button>
				</div>
			</div>
			<div class="modal-backdrop" aria-hidden="true"></div>
		</dialog>
	{/if}

	{#if cancelModalSession}
		<dialog open class="modal-dialog-center rounded-container" oncancel={(e) => e.preventDefault()}>
			<div class="card bg-surface-50 rounded-container shadow-xl p-6 text-surface-950 max-w-md">
				<h3 class="font-bold text-lg">Cancel session</h3>
				<p class="py-2 text-sm text-surface-950/80">
					Cancel token <span class="font-mono font-semibold text-surface-950">{cancelModalSession.alias}</span>?
					This removes them from the active queue.
				</p>
				<div class="flex flex-wrap justify-end gap-2 mt-4">
					<button type="button" class="btn preset-tonal touch-target-h" onclick={() => (cancelModalSession = null)} disabled={!!actionLoading}>
						Back
					</button>
					<button
						type="button"
						class="btn preset-filled-warning-500 touch-target-h"
						onclick={confirmCancel}
						disabled={!!actionLoading}
					>
						{actionLoading === `cancel-${cancelModalSession.session_id}` ? 'Cancelling…' : 'Confirm cancel'}
					</button>
				</div>
			</div>
			<div class="modal-backdrop" aria-hidden="true"></div>
		</dialog>
	{/if}

	{#if showStationTokenScanner}
		<dialog open class="modal-dialog-center rounded-container" oncancel={(e) => e.preventDefault()}>
			<div class="card bg-surface-50 rounded-container shadow-xl p-6 text-surface-950 max-w-md">
				<h3 class="font-bold text-lg mb-3">Scan client token</h3>
				<p class="text-sm text-surface-950/70 mb-4">Scan the client's QR to identify their session and show actions.</p>
				<!-- First focusable so dialog focuses it; same value/handler as global input. Always enabled for Station. -->
				<input
					type="text"
					autocomplete="off"
					inputmode="none"
					aria-label="Barcode scanner input"
					class="sr-only"
					bind:value={hidScanValue}
					bind:this={hidModalInputEl}
					onkeydown={onHidKeydown}
				/>
				<QrScanner
					active={showStationTokenScanner}
					onScan={handleStationTokenScan}
					soundOnScan={true}
					cameraOnly={true}
				/>
				<p
					class="text-sm text-surface-600 rounded-container border border-surface-200 bg-surface-50 px-3 py-2 mt-3"
					aria-live="polite"
				>
					HID scanner turned on, waiting for scan.
				</p>
				<button
					type="button"
					class="btn preset-tonal btn-sm mt-4 touch-target-h w-full"
					onclick={() => { showStationTokenScanner = false; stationScanHandled = true; }}
				>
					Cancel
				</button>
			</div>
			<div class="modal-backdrop" aria-hidden="true"></div>
		</dialog>
	{/if}

	{#if showOverrideModal}
		<dialog open class="modal-dialog-center rounded-container" oncancel={(e) => e.preventDefault()}>
			<div class="card bg-surface-50 rounded-container shadow-xl p-6 max-w-md text-surface-950">
				<h3 class="font-bold text-lg">Override standard flow</h3>
				<p class="text-sm text-surface-950/70 py-2">
					Send client <span class="font-mono font-semibold text-surface-950">{overrideSession?.alias ?? ''}</span> to a different track.
					{#if needsAuthForOverride}
						Authorize with PIN or QR (get code from supervisor).
					{/if}
				</p>
				<div class="form-control w-full mt-2">
					<label for="override-target" class="label"><span class="label-text">Target track</span></label>
					<select
						id="override-target"
						class="select rounded-container border border-surface-200 px-3 py-2 w-full"
						value={overrideIsCustom ? 'custom' : (overrideTargetTrackId ?? '')}
						onchange={(e) => {
							const v = (e.target as HTMLSelectElement).value;
							if (v === 'custom') {
								overrideIsCustom = true;
								overrideTargetTrackId = null;
							} else {
								overrideIsCustom = false;
								overrideTargetTrackId = v === '' ? null : Number(v);
							}
						}}
					>
						<option value="">Select…</option>
						{#each tracks as t (t.id)}
							<option value={t.id}>{t.name}</option>
						{/each}
						<option value="custom">Custom (admin defines path)</option>
					</select>
				</div>
				<div class="form-control w-full mt-2">
					<label for="override-reason" class="label"><span class="label-text">Reason {overrideIsCustom ? '(required)' : '(optional)'}</span></label>
					<textarea
						id="override-reason"
						class="textarea rounded-container border border-surface-200 w-full"
						placeholder="e.g. Needs legal assistance first"
						bind:value={overrideReason}
						rows="2"
					></textarea>
				</div>
				{#if needsAuthForOverride}
				<!-- Auth: PIN | QR | Request approval (icon toggle row) -->
				<div class="form-control w-full mt-2">
					<div class="label"><span class="label-text">Authorize with</span></div>
					<div class="join join-horizontal w-full">
						<button type="button" class="btn btn-sm flex-1 touch-target-h {authType === 'pin' ? 'preset-filled-primary-500' : 'preset-tonal'}" onclick={() => { authType = 'pin'; tempQrScanToken = ''; showQrScanner = false; }} title="Enter 6-digit code">PIN</button>
						<button type="button" class="btn btn-sm flex-1 touch-target-h {authType === 'qr' ? 'preset-filled-primary-500' : 'preset-tonal'}" onclick={() => { authType = 'qr'; tempCodeEntered = ''; }} title="Scan QR">QR</button>
						<button type="button" class="btn btn-sm flex-1 touch-target-h {authType === 'request_approval' ? 'preset-filled-primary-500' : 'preset-tonal'}" onclick={() => { authType = 'request_approval'; tempCodeEntered = ''; tempQrScanToken = ''; showQrScanner = false; }} title="Request supervisor approval">Request</button>
					</div>
				</div>
				{#if authType === 'request_approval'}
					<p class="text-sm text-surface-950/70 mt-2">Request will be sent to supervisor. Check Program Overrides page for status.</p>
				{:else if authType === 'pin'}
					<div class="form-control w-full mt-2">
						<div class="label"><span class="label-text">Enter 6-digit code from supervisor</span></div>
						<input type="text" inputmode="numeric" class="input rounded-container border border-surface-200 px-3 py-2 w-full font-mono" placeholder="Enter 6-digit code" maxlength="6" bind:value={tempCodeEntered} oninput={(e) => { tempCodeEntered = (e.currentTarget.value || '').replace(/\D/g, '').slice(0, 6); }} />
					</div>
				{:else if authType === 'qr'}
					<div class="form-control w-full mt-2">
						<!-- First focusable so dialog focuses it; same value/handler as global input. Always enabled for Station. -->
						<input
							type="text"
							autocomplete="off"
							inputmode="none"
							aria-label="Barcode scanner input"
							class="sr-only"
							bind:value={hidScanValue}
							bind:this={hidModalInputEl}
							onkeydown={onHidKeydown}
						/>
						<QrScanner active={showQrScanner} onScan={handleQrScan} soundOnScan={true} />
						{#if showQrScanner && scanCountdown > 0}
							<div class="flex flex-wrap items-center gap-2 mt-2">
								<span class="text-sm text-surface-950/70" aria-live="polite">Closing in {scanCountdown}s</span>
								<button type="button" class="btn preset-tonal btn-sm touch-target-h px-3" onclick={extendScannerCountdown}>Extend (+{Math.max(0, Number(display_scan_timeout_seconds) || 20)}s)</button>
							</div>
						{/if}
						<p
							class="text-sm text-surface-600 rounded-container border border-surface-200 bg-surface-50 px-3 py-2 mt-2"
							aria-live="polite"
						>
							HID scanner turned on, waiting for scan.
						</p>
						<button type="button" class="btn preset-tonal btn-sm mt-1 touch-target-h" onclick={() => { showQrScanner = false; qrScanHandled = true; }}>Cancel scan</button>
						{#if tempQrScanToken}<p class="text-xs text-success-500 mt-1">✓ QR scanned</p>{/if}
					</div>
				{/if}
				{/if}
				<div class="flex justify-end gap-2 mt-4">
					<button
						type="button"
						class="btn preset-tonal touch-target-h"
						onclick={() => {
							showOverrideModal = false;
							overrideSession = null;
							overrideTargetTrackId = null;
							overrideIsCustom = false;
							overrideReason = '';
							overridePin = '';
							overrideSupervisorId = null;
							resetAuthState();
						}}
					>
						Cancel
					</button>
					<button
						type="button"
						class="btn preset-filled-primary-500 touch-target-h"
						disabled={(!overrideTargetTrackId && !overrideIsCustom) || (overrideIsCustom && !overrideReason.trim()) || (needsAuthForOverride && !canConfirmAuth()) || !!actionLoading}
						onclick={override}
					>
						{actionLoading === 'override' ? 'Processing…' : 'Confirm Override'}
					</button>
				</div>
			</div>
			<div class="modal-backdrop" aria-hidden="true"></div>
		</dialog>
	{/if}

	{#if showForceCompleteModal}
		<dialog open class="modal-dialog-center rounded-container" oncancel={(e) => e.preventDefault()}>
			<div class="card bg-surface-50 rounded-container shadow-xl p-6 max-w-md text-surface-950">
				<h3 class="font-bold text-lg">Force complete session</h3>
				<p class="text-sm text-surface-950/70 py-2">
					End session for <span class="font-mono font-semibold text-surface-950">{forceCompleteSession?.alias ?? ''}</span> without normal flow (e.g. client left).
					{#if needsAuthForOverride}
						Requires authorization (get code from supervisor).
					{/if}
				</p>
				<div class="form-control w-full mt-2">
					<label for="fc-reason" class="label"><span class="label-text">Reason (required)</span></label>
					<textarea id="fc-reason" class="textarea rounded-container border border-surface-200 w-full" placeholder="e.g. Client left without completing" bind:value={forceCompleteReason} rows="2"></textarea>
				</div>
				{#if needsAuthForOverride}
				<div class="form-control w-full mt-2">
					<div class="label"><span class="label-text">Authorize with</span></div>
					<div class="join join-horizontal w-full">
						<button type="button" class="btn btn-sm flex-1 touch-target-h {authType === 'pin' ? 'preset-filled-primary-500' : 'preset-tonal'}" onclick={() => { authType = 'pin'; tempQrScanToken = ''; showQrScanner = false; }}>PIN</button>
						<button type="button" class="btn btn-sm flex-1 touch-target-h {authType === 'qr' ? 'preset-filled-primary-500' : 'preset-tonal'}" onclick={() => { authType = 'qr'; tempCodeEntered = ''; }}>QR</button>
						<button type="button" class="btn btn-sm flex-1 touch-target-h {authType === 'request_approval' ? 'preset-filled-primary-500' : 'preset-tonal'}" onclick={() => { authType = 'request_approval'; tempCodeEntered = ''; tempQrScanToken = ''; showQrScanner = false; }}>Request</button>
					</div>
				</div>
				{#if authType === 'request_approval'}
					<p class="text-sm text-surface-950/70 mt-2">Request will be sent to supervisor. Check Program Overrides page for status.</p>
				{:else if authType === 'pin'}
					<div class="form-control w-full mt-2">
						<div class="label"><span class="label-text">Enter 6-digit code from supervisor</span></div>
						<input type="text" inputmode="numeric" class="input rounded-container border border-surface-200 px-3 py-2 w-full font-mono" placeholder="Enter 6-digit code" maxlength="6" bind:value={tempCodeEntered} oninput={(e) => { tempCodeEntered = (e.currentTarget.value || '').replace(/\D/g, '').slice(0, 6); }} />
					</div>
				{:else if authType === 'qr'}
					<div class="form-control w-full mt-2">
						<QrScanner active={showQrScanner} onScan={handleQrScan} soundOnScan={true} />
						{#if showQrScanner && scanCountdown > 0}
							<div class="flex flex-wrap items-center gap-2 mt-2">
								<span class="text-sm text-surface-950/70" aria-live="polite">Closing in {scanCountdown}s</span>
								<button type="button" class="btn preset-tonal btn-sm touch-target-h px-3" onclick={extendScannerCountdown}>Extend (+{Math.max(0, Number(display_scan_timeout_seconds) || 20)}s)</button>
							</div>
						{/if}
						<button type="button" class="btn preset-tonal btn-sm mt-1 touch-target-h" onclick={() => { showQrScanner = false; qrScanHandled = true; }}>Cancel scan</button>
						{#if tempQrScanToken}<p class="text-xs text-success-500 mt-1">✓ QR scanned</p>{/if}
					</div>
				{/if}
				{/if}
				<div class="flex justify-end gap-2 mt-4">
					<button type="button" class="btn preset-tonal touch-target-h" onclick={() => { showForceCompleteModal = false; forceCompleteSession = null; forceCompleteReason = ''; overridePin = ''; overrideSupervisorId = null; resetAuthState(); }}>Cancel</button>
					<button type="button" class="btn preset-filled-primary-500 touch-target-h" disabled={!forceCompleteReason.trim() || (needsAuthForOverride && !canConfirmAuth()) || !!actionLoading} onclick={forceComplete}>
						{actionLoading === 'force-complete' ? 'Processing…' : 'Force Complete'}
					</button>
				</div>
			</div>
			<div class="modal-backdrop" aria-hidden="true"></div>
		</dialog>
	{/if}

	{#if showCallNextOverrideModal}
		<dialog open class="modal-dialog-center rounded-container" oncancel={(e) => e.preventDefault()}>
			<div class="card bg-surface-50 rounded-container shadow-xl p-6 max-w-md text-surface-950">
				<h3 class="font-bold text-lg">Call Next (Override Priority)</h3>
				<p class="text-sm text-surface-950/70 py-2">
					Calling <span class="font-mono font-semibold text-surface-950">{callNextSession?.alias ?? ''}</span> would skip priority clients. Get authorization from supervisor.
				</p>
				<div class="form-control w-full mt-2">
					<div class="label"><span class="label-text">Authorize with</span></div>
					<div class="join join-horizontal w-full">
						<button type="button" class="btn btn-sm flex-1 touch-target-h {authType === 'pin' ? 'preset-filled-primary-500' : 'preset-tonal'}" onclick={() => { authType = 'pin'; tempQrScanToken = ''; showQrScanner = false; }}>PIN</button>
						<button type="button" class="btn btn-sm flex-1 touch-target-h {authType === 'qr' ? 'preset-filled-primary-500' : 'preset-tonal'}" onclick={() => { authType = 'qr'; tempCodeEntered = ''; }}>QR</button>
					</div>
				</div>
				{#if authType === 'pin'}
					<div class="form-control w-full mt-2">
						<div class="label"><span class="label-text">Enter 6-digit code from supervisor</span></div>
						<input type="text" inputmode="numeric" class="input rounded-container border border-surface-200 px-3 py-2 w-full font-mono" placeholder="Enter 6-digit code" maxlength="6" bind:value={tempCodeEntered} oninput={(e) => { tempCodeEntered = (e.currentTarget.value || '').replace(/\D/g, '').slice(0, 6); }} />
					</div>
				{:else if authType === 'qr'}
					<div class="form-control w-full mt-2">
						<QrScanner active={showQrScanner} onScan={handleQrScan} soundOnScan={true} />
						{#if showQrScanner && scanCountdown > 0}
							<div class="flex flex-wrap items-center gap-2 mt-2">
								<span class="text-sm text-surface-950/70" aria-live="polite">Closing in {scanCountdown}s</span>
								<button type="button" class="btn preset-tonal btn-sm touch-target-h px-3" onclick={extendScannerCountdown}>Extend (+{Math.max(0, Number(display_scan_timeout_seconds) || 20)}s)</button>
							</div>
						{/if}
						<button type="button" class="btn preset-tonal btn-sm mt-1 touch-target-h" onclick={() => { showQrScanner = false; qrScanHandled = true; }}>Cancel scan</button>
						{#if tempQrScanToken}<p class="text-xs text-success-500 mt-1">✓ QR scanned</p>{/if}
					</div>
				{/if}
				<div class="flex justify-end gap-2 mt-4">
					<button type="button" class="btn preset-tonal touch-target-h" onclick={() => { showCallNextOverrideModal = false; callNextSession = null; overridePin = ''; overrideSupervisorId = null; resetAuthState(); }}>Cancel</button>
					<button type="button" class="btn preset-filled-primary-500 touch-target-h" disabled={!canConfirmAuth() || !!actionLoading} onclick={callNextWithAuth}>
						{actionLoading === 'call' ? 'Calling…' : 'Call Next'}
					</button>
				</div>
			</div>
			<div class="modal-backdrop" aria-hidden="true"></div>
		</dialog>
	{/if}

	<Modal
		open={showDisplayAudioModal}
		title="Display board audio"
		onClose={() => (showDisplayAudioModal = false)}
	>
		{#snippet children()}
			<p class="text-sm text-surface-950/70 mb-4">Mute and volume for the public display at this station.</p>
			<div class="flex flex-col gap-4">
				<label for="display-audio-mute-switch" class="flex items-center justify-between cursor-pointer gap-3">
					<span class="text-sm font-medium text-surface-950">Mute</span>
					<div class="relative inline-block w-11 h-5">
						<input
							id="display-audio-mute-switch"
							type="checkbox"
							class="peer appearance-none w-11 h-5 bg-surface-200 rounded-full checked:bg-surface-800 cursor-pointer transition-colors duration-300 disabled:opacity-50"
							checked={queue?.display_audio_muted ?? false}
							disabled={displaySettingsSaving}
							onchange={(e) => saveDisplaySettings({ display_audio_muted: (e.target as HTMLInputElement).checked })}
						/>
						<span class="absolute top-0 left-0 w-5 h-5 bg-surface-950 rounded-full border border-surface-300 shadow-sm transition-transform duration-300 peer-checked:translate-x-6 peer-checked:border-surface-800 pointer-events-none" aria-hidden="true"></span>
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
						value={queue?.display_audio_volume ?? 1}
						disabled={displaySettingsSaving || (queue?.display_audio_muted ?? false)}
						oninput={(e) => {
							const v = parseFloat((e.target as HTMLInputElement).value);
							saveDisplaySettings({ display_audio_volume: v });
						}}
					/>
				</label>
				<!-- TTS source/voice are now global; station controls only mute/volume. -->
				{#if displaySettingsSaving}
					<span class="text-xs text-surface-950/50">Saving…</span>
				{/if}
			</div>
		{/snippet}
	</Modal>
</MobileLayout>

