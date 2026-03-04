<script lang="ts">
	import MobileLayout from '../../Layouts/MobileLayout.svelte';
	import Modal from '../../Components/Modal.svelte';
	import QrScanner from '../../Components/QrScanner.svelte';
	import { Volume2 } from 'lucide-svelte';
	import { get } from 'svelte/store';
	import { tick, onMount } from 'svelte';
	import { usePage } from '@inertiajs/svelte';
	import { ensureVoicesLoaded, speakSample } from '../../lib/speechUtils.js';
	import { router } from '@inertiajs/svelte';

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
	}

	interface WaitingSession {
		session_id: number;
		alias: string;
		track: string;
		client_category: string;
		status: string;
		queued_at: string;
		station_queue_position?: number;
		process_id?: number | null;
		process_name?: string | null;
	}

	interface QueueData {
		station: { id: number; name: string; client_capacity?: number; serving_count?: number };
		serving: ServingSession[];
		no_show_timer_seconds: number;
		waiting: WaitingSession[];
		priority_first?: boolean;
		require_permission_before_override?: boolean;
		call_next_requires_override?: boolean;
		balance_mode?: string;
		next_to_call?: { session_id: number; alias: string } | null;
		stats: { total_waiting: number; total_served_today: number; avg_service_time_minutes: number };
		display_audio_muted?: boolean;
		display_audio_volume?: number;
		display_tts_voice?: string | null;
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
	let error = $state('');
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
	let showCallNextOverrideModal = $state(false);
	let callNextSession = $state<{ session_id: number; alias: string } | null>(null);

	/** Scanner modal countdown (when showQrScanner): decrement scanCountdown so Extend works. */
	let scanCountdown = $state(0);
	let scanCountdownIntervalId = $state<ReturnType<typeof setInterval> | null>(null);

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

	async function api(
		method: string,
		url: string,
		body?: object
	): Promise<{ ok: boolean; data?: object; message?: string }> {
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
		const data = await res.json().catch(() => ({}));
		return { ok: res.ok, data, message: (data as { message?: string })?.message };
	}

	/**
	 * Fetch queue data. Use silent=true for refetch after actions (Call Next, Serve, etc.)
	 * so only the queue sections update without showing the full-page spinner.
	 * Optional onSuccess runs in same tick as queue/noShowCountdown to batch renders.
	 */
	async function fetchQueue(silent = false, onSuccess?: () => void) {
		if (!station) return;
		if (!silent) loading = true;
		error = '';
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
			error = (data as { message?: string })?.message ?? 'Failed to load queue';
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
		display_tts_voice?: string | null;
	}) {
		if (!station || !queue || displaySettingsSaving) return;
		displaySettingsSaving = true;
		const body: {
			display_audio_muted?: boolean;
			display_audio_volume?: number;
			display_tts_voice?: string | null;
		} = {};
		if (updates.display_audio_muted !== undefined) body.display_audio_muted = updates.display_audio_muted;
		if (updates.display_audio_volume !== undefined) body.display_audio_volume = updates.display_audio_volume;
		if (updates.display_tts_voice !== undefined) body.display_tts_voice = updates.display_tts_voice;
		const { ok, data } = await api('PUT', `/api/stations/${station.id}/display-settings`, body);
		displaySettingsSaving = false;
		if (ok && data && typeof data === 'object' && 'display_audio_muted' in data) {
			const d = data as {
				display_audio_muted?: boolean;
				display_audio_volume?: number;
				display_tts_voice?: string | null;
			};
			queue = {
				...queue,
				display_audio_muted: d.display_audio_muted ?? queue.display_audio_muted,
				display_audio_volume: d.display_audio_volume ?? queue.display_audio_volume,
				display_tts_voice: d.display_tts_voice ?? queue.display_tts_voice,
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
			error = 'Failed to update priority setting';
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
			error = (data as { message?: string })?.message ?? 'Call failed';
		}
	}

	async function callNextWithAuth() {
		const s = callNextSession;
		if (!s || actionLoading) return;
		const authBody = buildAuthBody();
		if (!authBody) return;
		actionLoading = 'call';
		error = '';
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
			error = (data as { message?: string })?.message ?? 'Call failed';
		}
	}

	async function serve(s: ServingSession) {
		if (!s || s.status !== 'called' || actionLoading) return;
		actionLoading = `serve-${s.session_id}`;
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/serve`, {});
		if (ok) {
			await fetchQueue(true, () => { actionLoading = null; });
		} else {
			actionLoading = null;
			error = (data as { message?: string })?.message ?? 'Serve failed';
		}
	}

	async function transfer(s: ServingSession) {
		if (!s || s.status !== 'serving' || actionLoading) return;
		actionLoading = `transfer-${s.session_id}`;
		error = '';
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/transfer`, { mode: 'standard' });
		if (ok) {
			const d = data as { action_required?: string };
			if (d?.action_required === 'complete') {
				error = 'No next process in track. Complete the session instead.';
			}
			await fetchQueue(true, () => { actionLoading = null; });
		} else {
			actionLoading = null;
			error = (data as { message?: string })?.message ?? 'Transfer failed';
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
			error = (data as { message?: string })?.message ?? 'Complete failed';
		}
	}

	async function cancel(s: ServingSession) {
		if (!s || actionLoading) return;
		actionLoading = `cancel-${s.session_id}`;
		const { ok } = await api('POST', `/api/sessions/${s.session_id}/cancel`, { remarks: 'Cancelled by staff' });
		if (ok) {
			await fetchQueue(true, () => { actionLoading = null; });
		} else {
			actionLoading = null;
			error = 'Cancel failed';
		}
	}

	function openNoShowModal(s: ServingSession) {
		noShowModalSession = s;
	}

	async function noShow() {
		const s = noShowModalSession;
		if (!s || actionLoading) return;
		actionLoading = 'noShow';
		noShowModalSession = null;
		const { ok, data } = await api('POST', `/api/sessions/${s.session_id}/no-show`, {});
		if (ok) {
			await fetchQueue(true, () => { actionLoading = null; });
		} else {
			actionLoading = null;
			error = (data as { message?: string })?.message ?? 'No-show failed';
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
		if (!s || (!overrideTargetTrackId && !overrideIsCustom) || !overrideReason.trim() || actionLoading) return;
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
		error = '';
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
			error = (data as { message?: string })?.message ?? 'Override failed';
		}
	}

	async function requestApprovalOverride() {
		const s = overrideSession;
		if (!s || (!overrideTargetTrackId && !overrideIsCustom) || !overrideReason.trim() || actionLoading) return;
		actionLoading = 'override';
		error = '';
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
			const data = (await res.json().catch(() => ({}))) as { message?: string };
			ok = res.ok;
		} catch (e) {
			actionLoading = null;
			const isAbort = e instanceof Error && e.name === 'AbortError';
			error = isAbort
				? 'Request timed out. The request may have succeeded – check Track Overrides.'
				: 'Failed to send request';
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
			window.location.href = '/track-overrides';
		} else {
			error = 'Failed to send request';
		}
	}

	async function requestApprovalForceComplete() {
		const s = forceCompleteSession;
		if (!s || !forceCompleteReason.trim() || actionLoading) return;
		actionLoading = 'force-complete';
		error = '';
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
			router.visit('/track-overrides');
		} else {
			error = 'Failed to send request';
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
		error = '';
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
			error = (data as { message?: string })?.message ?? 'Force complete failed';
		}
	}

	function isLastStep(s: ServingSession): boolean {
		return (s.current_step_order ?? 0) >= (s.total_steps ?? 1);
	}

	function canNoShow(s: ServingSession): boolean {
		if (s.status !== 'called') return false;
		const remaining = noShowCountdown[s.session_id] ?? 0;
		return remaining <= 0;
	}

	function noShowButtonLabel(s: ServingSession): string {
		const remaining = noShowCountdown[s.session_id] ?? 0;
		if (remaining > 0) return `No-Show (${remaining}s)`;
		return s.no_show_attempts > 0 ? `No-Show (${s.no_show_attempts}/3)` : 'No-Show';
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
	<div class="flex flex-col gap-4 md:gap-6 text-surface-950 w-full max-w-2xl md:max-w-5xl lg:max-w-6xl mx-auto px-4 md:px-6 py-4 md:py-6">
		{#if !station}
			<div class="rounded-container bg-surface-50 border border-surface-200 elevation-card p-6 md:p-8 text-center text-surface-950/80">
				{#if canSwitchStation && stations.length > 0}
					<p class="font-medium mb-4">Select a station</p>
					<div class="flex flex-col gap-3 max-w-xs mx-auto">
						{#each stations as s (s.id)}
							<button type="button" class="btn preset-outlined min-h-[48px] w-full" onclick={() => switchStation(s)}>
								{s.name}
							</button>
						{/each}
					</div>
				{:else}
					<p class="font-medium">No station assigned</p>
					<p class="mt-2 text-sm">Contact admin to assign you to a station.</p>
				{/if}
			</div>
		{:else if loading}
			<div class="flex justify-center py-16 md:py-24">
				<span class="loading-spinner loading-lg text-primary-500"></span>
			</div>
		{:else if error}
			<div class="rounded-container bg-error-100 text-error-900 border border-error-300 p-4 md:p-5">{error}</div>
		{:else if queue}
			<!-- Station switcher (pill bar) -->
			{#if canSwitchStation && stations.length > 1}
				<div class="flex gap-2 overflow-x-auto pb-1 -mx-1" role="tablist" aria-label="Switch station">
					{#each stations as s (s.id)}
						<button
							type="button"
							role="tab"
							aria-selected={s.id === station.id}
							class="btn btn-sm shrink-0 min-h-[44px] px-4 {s.id === station.id ? 'preset-filled-primary-500' : 'preset-tonal'}"
							onclick={() => switchStation(s)}
						>
							{s.name}
						</button>
					{/each}
				</div>
			{/if}

			<!-- Toolbar: capacity, priority, display audio (single row, wraps on small) -->
			<div class="flex flex-wrap items-center justify-between gap-3 py-2 px-3 rounded-container bg-surface-50/80 border border-surface-200 elevation-card">
				<div class="flex items-center gap-3 min-h-[44px]">
					<span class="text-sm font-medium text-surface-950/80 tabular-nums" aria-label="Serving count">
						Serving {servingCount}/{clientCapacity}
					</span>
					{#if canSwitchStation}
						<label for="priority-first-switch" class="label cursor-pointer gap-2 items-center min-h-[44px]">
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
					class="btn preset-tonal btn-sm gap-2 min-h-[44px] min-w-[44px] md:min-w-[auto] px-3"
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
					<!-- Serving / Called cards (one per client) -->
					{#each queue.serving as s (s.session_id)}
						<div class="rounded-container bg-surface-50 border border-surface-200 elevation-card p-4 md:p-5 space-y-3">
							<p class="text-xs font-medium text-surface-950/70 uppercase tracking-wide">
								{s.status === 'called' ? 'Calling' : 'Now Serving'}
							</p>
							<p class="text-2xl md:text-4xl font-bold text-primary-500 tabular-nums">{s.alias}</p>
							<div class="flex flex-wrap gap-2">
								{#if s.process_name}
									<span class="text-xs px-2 py-0.5 rounded preset-filled-primary-500/20 text-primary-700" title="Current process">{s.process_name}</span>
								{/if}
								<span class="text-xs px-2 py-0.5 rounded preset-outlined text-surface-950">{s.track}</span>
								<span class="badge {categoryBadgeClass(s.client_category)} text-sm font-semibold text-surface-950">
									{s.client_category ?? 'Regular'}
								</span>
							</div>
							<p class="text-sm text-surface-950/70">
								Step {s.current_step_order} of {s.total_steps}
								{#if s.status === 'serving'}
									· Started {formatDuration(s.started_at)} ago
								{/if}
							</p>
							<div class="flex flex-col gap-2 pt-2">
								{#if s.status === 'called'}
									<button
										type="button"
										class="btn preset-filled-primary-500 btn-lg min-h-[48px]"
										disabled={!!actionLoading}
										onclick={() => serve(s)}
									>
										{actionLoading === `serve-${s.session_id}` ? 'Processing…' : 'Serve'}
									</button>
									<button
										type="button"
										class="btn btn-sm min-h-[44px] {s.no_show_attempts >= 2 ? 'preset-filled-warning-500' : 'preset-tonal'}"
										disabled={!!actionLoading || !canNoShow(s)}
										onclick={() => openNoShowModal(s)}
									>
										{noShowButtonLabel(s)}
									</button>
								{:else}
									{#if isLastStep(s)}
										<button
											type="button"
											class="btn preset-filled-primary-500 btn-lg min-h-[48px]"
											disabled={!!actionLoading}
											onclick={() => complete(s)}
										>
											{actionLoading === `complete-${s.session_id}` ? 'Completing…' : 'Complete Session'}
										</button>
									{:else}
										<button
											type="button"
											class="btn preset-filled-primary-500 btn-lg min-h-[48px]"
											disabled={!!actionLoading}
											onclick={() => transfer(s)}
										>
											{actionLoading === `transfer-${s.session_id}` ? 'Transferring…' : 'Send to next process'}
										</button>
									{/if}
									<div class="flex flex-wrap gap-2">
										<button type="button" class="btn preset-outlined btn-sm min-h-[44px]" disabled={!!actionLoading} onclick={() => openOverrideModal(s)}>Override</button>
										<button type="button" class="btn preset-filled-warning-500 btn-sm min-h-[44px]" disabled={!!actionLoading} onclick={() => openForceCompleteModal(s)}>Force Complete</button>
										<button type="button" class="btn preset-tonal btn-sm min-h-[44px]" disabled={!!actionLoading} onclick={() => cancel(s)}>Cancel</button>
									</div>
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
								<p class="text-sm text-surface-950/60 mb-3">
									Next: <span class="font-mono font-semibold text-surface-950">{nextSession.alias}</span>
									<span class="text-xs px-2 py-0.5 rounded preset-tonal badge-sm ml-1 text-surface-950">{nextSession.client_category ?? 'Regular'}</span>
									({nextSession.track}{#if nextSession.process_name}) — {nextSession.process_name}{/if})
								</p>
								<button
									type="button"
									class="btn preset-filled-primary-500 btn-lg min-h-[52px] w-full sm:w-auto px-8"
									disabled={!!actionLoading}
									onclick={callNext}
								>
									{actionLoading === 'call' ? 'Calling…' : 'Call Next'}
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

				<!-- Right: Queue + stats (desktop) / below left on mobile -->
				<div class="lg:col-span-5 flex flex-col gap-4 md:gap-5">
					{#if queue.waiting.length > 0}
						<div class="rounded-container bg-surface-50 border border-surface-200 elevation-card p-4 md:p-5">
							<h3 class="text-xs font-semibold text-surface-950/80 uppercase tracking-wide mb-3">Waiting — {queue.waiting.length}</h3>
							<ul class="space-y-2 max-h-[280px] lg:max-h-[360px] overflow-y-auto">
								{#each queue.waiting as w (w.session_id)}
									<li class="flex justify-between items-center text-sm gap-2 text-surface-950 py-1.5 border-b border-surface-100 last:border-0">
										<div class="flex items-center gap-2 min-w-0">
											<span class="font-mono font-medium tabular-nums">{w.alias}</span>
											<span class="badge {categoryBadgeClass(w.client_category)} badge-sm shrink-0">{w.client_category ?? 'Regular'}</span>
											{#if w.process_name}
												<span class="text-xs px-2 py-0.5 rounded preset-tonal text-surface-950/80 truncate max-w-[8rem]">{w.process_name}</span>
											{/if}
										</div>
										<span class="text-surface-950/60 shrink-0 text-xs">{w.track} · {formatDuration(w.queued_at)}</span>
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
			<details class="rounded-box bg-surface-50 border border-surface-200 elevation-card overflow-hidden" open={notesExpanded}>
				<summary class="cursor-pointer px-4 py-3 font-medium text-surface-950 select-none flex items-center justify-between gap-2 min-h-[48px]">
					<span>Station notes</span>
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
							<button type="submit" class="btn preset-filled-primary-500 btn-sm min-h-[44px] px-4" disabled={noteSubmitting}>
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
		<dialog open class="modal-dialog-center rounded-container" oncancel={(e) => e.preventDefault()}>
			<div class="card bg-surface-50 rounded-container shadow-xl p-6 text-surface-950">
				<h3 class="font-bold text-lg">Mark No-Show</h3>
				<p class="py-2">
					Mark <span class="font-mono font-semibold text-surface-950">{noShowModalSession.alias}</span> as no-show?
					{noShowModalSession.no_show_attempts >= 2
						? 'This will end the session and free the token (3/3).'
						: 'Client can be called again.'}
				</p>
				<div class="flex justify-end gap-2 mt-4">
					<button type="button" class="btn preset-tonal" onclick={() => (noShowModalSession = null)}>Cancel</button>
					<button type="button" class="btn preset-filled-warning-500" onclick={noShow} disabled={!!actionLoading}>
						{actionLoading === 'noShow' ? 'Processing…' : 'Mark No-Show'}
					</button>
				</div>
			</div>
			<!-- Per flexiqueue-ldd: backdrop does not close modal; only Cancel/buttons do -->
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
					<label for="override-reason" class="label"><span class="label-text">Reason (required)</span></label>
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
					<label class="label"><span class="label-text">Authorize with</span></label>
					<div class="join join-horizontal w-full">
						<button type="button" class="btn btn-sm flex-1 {authType === 'pin' ? 'preset-filled-primary-500' : 'preset-tonal'}" onclick={() => { authType = 'pin'; tempQrScanToken = ''; showQrScanner = false; }} title="Enter 6-digit code">PIN</button>
						<button type="button" class="btn btn-sm flex-1 {authType === 'qr' ? 'preset-filled-primary-500' : 'preset-tonal'}" onclick={() => { authType = 'qr'; tempCodeEntered = ''; qrScanHandled = false; showQrScanner = true; }} title="Scan QR">QR</button>
						<button type="button" class="btn btn-sm flex-1 {authType === 'request_approval' ? 'preset-filled-primary-500' : 'preset-tonal'}" onclick={() => { authType = 'request_approval'; tempCodeEntered = ''; tempQrScanToken = ''; showQrScanner = false; }} title="Request supervisor approval">Request</button>
					</div>
				</div>
				{#if authType === 'request_approval'}
					<p class="text-sm text-surface-950/70 mt-2">Request will be sent to supervisor. Check Track Overrides page for status.</p>
				{:else if authType === 'pin'}
					<div class="form-control w-full mt-2">
						<label class="label"><span class="label-text">Enter 6-digit code from supervisor</span></label>
						<input type="text" inputmode="numeric" class="input rounded-container border border-surface-200 px-3 py-2 w-full font-mono" placeholder="Enter 6-digit code" maxlength="6" bind:value={tempCodeEntered} oninput={(e) => { tempCodeEntered = (e.currentTarget.value || '').replace(/\D/g, '').slice(0, 6); }} />
					</div>
				{:else if authType === 'qr'}
					<div class="form-control w-full mt-2">
						<QrScanner active={showQrScanner} onScan={handleQrScan} soundOnScan={true} />
						{#if showQrScanner && scanCountdown > 0}
							<div class="flex flex-wrap items-center gap-2 mt-2">
								<span class="text-sm text-surface-950/70" aria-live="polite">Closing in {scanCountdown}s</span>
								<button type="button" class="btn preset-tonal btn-sm min-h-[2.75rem] px-3" onclick={extendScannerCountdown}>Extend (+{Math.max(0, Number(display_scan_timeout_seconds) || 20)}s)</button>
							</div>
						{/if}
						<button type="button" class="btn preset-tonal btn-sm mt-1 min-h-[2.75rem]" onclick={() => { showQrScanner = false; qrScanHandled = true; }}>Cancel scan</button>
						{#if tempQrScanToken}<p class="text-xs text-success-500 mt-1">✓ QR scanned</p>{/if}
					</div>
				{/if}
				{/if}
				{#if error}
					<div class="bg-error-100 text-error-900 border border-error-300 rounded-container p-4 mt-2 text-sm">{error}</div>
				{/if}
				<div class="flex justify-end gap-2 mt-4">
					<button
						type="button"
						class="btn preset-tonal"
						onclick={() => {
							showOverrideModal = false;
							overrideSession = null;
							overrideTargetTrackId = null;
							overrideIsCustom = false;
							overrideReason = '';
							overridePin = '';
							overrideSupervisorId = null;
							resetAuthState();
							error = '';
						}}
					>
						Cancel
					</button>
					<button
						type="button"
						class="btn preset-filled-primary-500"
						disabled={(!overrideTargetTrackId && !overrideIsCustom) || !overrideReason.trim() || (needsAuthForOverride && !canConfirmAuth()) || !!actionLoading}
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
					<label class="label"><span class="label-text">Authorize with</span></label>
					<div class="join join-horizontal w-full">
						<button type="button" class="btn btn-sm flex-1 {authType === 'pin' ? 'preset-filled-primary-500' : 'preset-tonal'}" onclick={() => { authType = 'pin'; tempQrScanToken = ''; showQrScanner = false; }}>PIN</button>
						<button type="button" class="btn btn-sm flex-1 {authType === 'qr' ? 'preset-filled-primary-500' : 'preset-tonal'}" onclick={() => { authType = 'qr'; tempCodeEntered = ''; qrScanHandled = false; showQrScanner = true; }}>QR</button>
						<button type="button" class="btn btn-sm flex-1 {authType === 'request_approval' ? 'preset-filled-primary-500' : 'preset-tonal'}" onclick={() => { authType = 'request_approval'; tempCodeEntered = ''; tempQrScanToken = ''; showQrScanner = false; }}>Request</button>
					</div>
				</div>
				{#if authType === 'request_approval'}
					<p class="text-sm text-surface-950/70 mt-2">Request will be sent to supervisor. Check Track Overrides page for status.</p>
				{:else if authType === 'pin'}
					<div class="form-control w-full mt-2">
						<label class="label"><span class="label-text">Enter 6-digit code from supervisor</span></label>
						<input type="text" inputmode="numeric" class="input rounded-container border border-surface-200 px-3 py-2 w-full font-mono" placeholder="Enter 6-digit code" maxlength="6" bind:value={tempCodeEntered} oninput={(e) => { tempCodeEntered = (e.currentTarget.value || '').replace(/\D/g, '').slice(0, 6); }} />
					</div>
				{:else if authType === 'qr'}
					<div class="form-control w-full mt-2">
						<QrScanner active={showQrScanner} onScan={handleQrScan} soundOnScan={true} />
						{#if showQrScanner && scanCountdown > 0}
							<div class="flex flex-wrap items-center gap-2 mt-2">
								<span class="text-sm text-surface-950/70" aria-live="polite">Closing in {scanCountdown}s</span>
								<button type="button" class="btn preset-tonal btn-sm min-h-[2.75rem] px-3" onclick={extendScannerCountdown}>Extend (+{Math.max(0, Number(display_scan_timeout_seconds) || 20)}s)</button>
							</div>
						{/if}
						<button type="button" class="btn preset-tonal btn-sm mt-1 min-h-[2.75rem]" onclick={() => { showQrScanner = false; qrScanHandled = true; }}>Cancel scan</button>
						{#if tempQrScanToken}<p class="text-xs text-success-500 mt-1">✓ QR scanned</p>{/if}
					</div>
				{/if}
				{/if}
				{#if error}<div class="bg-error-100 text-error-900 border border-error-300 rounded-container p-4 mt-2 text-sm">{error}</div>{/if}
				<div class="flex justify-end gap-2 mt-4">
					<button type="button" class="btn preset-tonal" onclick={() => { showForceCompleteModal = false; forceCompleteSession = null; forceCompleteReason = ''; overridePin = ''; overrideSupervisorId = null; resetAuthState(); error = ''; }}>Cancel</button>
					<button type="button" class="btn preset-filled-primary-500" disabled={!forceCompleteReason.trim() || (needsAuthForOverride && !canConfirmAuth()) || !!actionLoading} onclick={forceComplete}>
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
					<label class="label"><span class="label-text">Authorize with</span></label>
					<div class="join join-horizontal w-full">
						<button type="button" class="btn btn-sm flex-1 {authType === 'pin' ? 'preset-filled-primary-500' : 'preset-tonal'}" onclick={() => { authType = 'pin'; tempQrScanToken = ''; showQrScanner = false; }}>PIN</button>
						<button type="button" class="btn btn-sm flex-1 {authType === 'qr' ? 'preset-filled-primary-500' : 'preset-tonal'}" onclick={() => { authType = 'qr'; tempCodeEntered = ''; qrScanHandled = false; showQrScanner = true; }}>QR</button>
					</div>
				</div>
				{#if authType === 'pin'}
					<div class="form-control w-full mt-2">
						<label class="label"><span class="label-text">Enter 6-digit code from supervisor</span></label>
						<input type="text" inputmode="numeric" class="input rounded-container border border-surface-200 px-3 py-2 w-full font-mono" placeholder="Enter 6-digit code" maxlength="6" bind:value={tempCodeEntered} oninput={(e) => { tempCodeEntered = (e.currentTarget.value || '').replace(/\D/g, '').slice(0, 6); }} />
					</div>
				{:else if authType === 'qr'}
					<div class="form-control w-full mt-2">
						<QrScanner active={showQrScanner} onScan={handleQrScan} soundOnScan={true} />
						{#if showQrScanner && scanCountdown > 0}
							<div class="flex flex-wrap items-center gap-2 mt-2">
								<span class="text-sm text-surface-950/70" aria-live="polite">Closing in {scanCountdown}s</span>
								<button type="button" class="btn preset-tonal btn-sm min-h-[2.75rem] px-3" onclick={extendScannerCountdown}>Extend (+{Math.max(0, Number(display_scan_timeout_seconds) || 20)}s)</button>
							</div>
						{/if}
						<button type="button" class="btn preset-tonal btn-sm mt-1 min-h-[2.75rem]" onclick={() => { showQrScanner = false; qrScanHandled = true; }}>Cancel scan</button>
						{#if tempQrScanToken}<p class="text-xs text-success-500 mt-1">✓ QR scanned</p>{/if}
					</div>
				{/if}
				{#if error}<div class="bg-error-100 text-error-900 border border-error-300 rounded-container p-4 mt-2 text-sm">{error}</div>{/if}
				<div class="flex justify-end gap-2 mt-4">
					<button type="button" class="btn preset-tonal" onclick={() => { showCallNextOverrideModal = false; callNextSession = null; overridePin = ''; overrideSupervisorId = null; resetAuthState(); error = ''; }}>Cancel</button>
					<button type="button" class="btn preset-filled-primary-500" disabled={!canConfirmAuth() || !!actionLoading} onclick={callNextWithAuth}>
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
				<label class="flex flex-col gap-2">
					<span class="text-sm font-medium text-surface-950">TTS voice</span>
					<div class="flex flex-wrap items-center gap-2">
						<select
							class="select select-sm bg-surface-50 border border-surface-300 rounded-lg"
							value={queue?.display_tts_voice ?? ''}
							disabled={displaySettingsSaving}
							aria-label="Display TTS voice"
							onchange={(e) => {
								const val = (e.target as HTMLSelectElement).value;
								saveDisplaySettings({ display_tts_voice: val || null });
							}}
						>
							<option value="">Use program default</option>
							{#each availableTtsVoices as voice}
								<option value={voice.name}>{voice.name}{voice.lang ? ` (${voice.lang})` : ''}</option>
							{/each}
						</select>
						<button
							type="button"
							class="btn preset-tonal btn-sm"
							onclick={() => speakSample("Calling A 3, please proceed to window 1.", (queue?.display_tts_voice ?? '') || null)}
							aria-label="Play sample phrase with selected voice"
						>
							Play sample
						</button>
					</div>
					<span class="text-xs text-surface-500">Voice for “Calling…” on this station’s display.</span>
				</label>
				{#if displaySettingsSaving}
					<span class="text-xs text-surface-950/50">Saving…</span>
				{/if}
			</div>
		{/snippet}
	</Modal>
</MobileLayout>
