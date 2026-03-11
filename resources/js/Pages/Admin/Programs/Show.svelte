<script lang="ts">
    import AdminLayout from "../../../Layouts/AdminLayout.svelte";
    import Modal from "../../../Components/Modal.svelte";
    import ConfirmModal from "../../../Components/ConfirmModal.svelte";
    import FlowDiagram from "../../../Components/FlowDiagram.svelte";
    import DiagramCanvas from "../../../Components/ProgramDiagram/DiagramCanvas.svelte";
    import { get } from "svelte/store";
    import { onMount } from "svelte";
    import { Link, router, usePage } from "@inertiajs/svelte";
    import { toaster } from "../../../lib/toaster.js";
    import { ensureVoicesLoaded, speakSample } from "../../../lib/speechUtils.js";

    import {
        Plus,
        Play,
        Pause,
        Square,
        Trash2,
        Edit2,
        Eye,
        FolderOpen,
        AlertCircle,
        AlertTriangle,
        CheckCircle,
        Clock,
        Settings,
        Users,
        ArrowRight,
        Activity,
        Calendar,
        GitMerge,
        FileText,
        Monitor,
        User,
        Power,
        Rocket,
        ChevronLeft,
        ChevronRight,
        Volume2,
    } from "lucide-svelte";

    interface ProgramItem {
        id: number;
        name: string;
        description: string | null;
        is_active: boolean;
        is_paused?: boolean;
        created_at: string | null;
        settings?: {
            no_show_timer_seconds: number;
            require_permission_before_override: boolean;
            priority_first: boolean;
            balance_mode: string;
            station_selection_mode?: string;
            alternate_ratio: [number, number];
            /** Per bead flexiqueue-5gl: in alternate mode, serve priority queue first (true) or regular first (false). Default true. */
            alternate_priority_first?: boolean;
            /** Per flexiqueue-87p: display board scan countdown (0 = no auto-close). */
            display_scan_timeout_seconds?: number;
            /** Per plan: display board TTS mute (admin-controlled). */
            display_audio_muted?: boolean;
            /** Per plan: display board TTS volume 0-1 (admin-controlled). */
            display_audio_volume?: number;
            /** TTS announcement repeat count (1–3: Once, Twice, Three times). Default 1. */
            display_tts_repeat_count?: number;
            /** Delay between repeated announcements in ms (500–10000). Default 2000. */
            display_tts_repeat_delay_ms?: number;
            /** Per plan: allow public self-serve triage at /triage/start. */
            allow_public_triage?: boolean;
            /** Per barcode-hid: enable HID barcode on Display board. Default true. */
            enable_display_hid_barcode?: boolean;
            /** Per barcode-hid: enable HID barcode on Public triage. Default true. */
            enable_public_triage_hid_barcode?: boolean;
        };
    }

    interface StepItem {
        id: number;
        track_id: number;
        process_id: number;
        process_name: string;
        step_order: number;
        is_required: boolean;
        estimated_minutes: number | null;
    }

    interface ProcessItem {
        id: number;
        program_id: number;
        name: string;
        description: string | null;
        expected_time_seconds?: number | null;
        created_at: string | null;
    }

    interface TrackItem {
        id: number;
        program_id: number;
        name: string;
        description: string | null;
        is_default: boolean;
        color_code: string | null;
        created_at: string | null;
        active_sessions_count?: number;
        steps?: StepItem[];
        total_estimated_minutes?: number;
        travel_queue_minutes?: number;
    }

    interface StationItem {
        id: number;
        program_id: number;
        name: string;
        capacity: number;
        client_capacity?: number;
        priority_first_override?: boolean | null;
        is_active: boolean;
        created_at: string | null;
        process_ids?: number[];
        tts?: {
            languages?: Record<
                string,
                {
                    voice_id?: string;
                    rate?: number;
                    station_phrase?: string;
                }
            >;
        };
    }

    interface ProgramStats {
        total_sessions: number;
        active_sessions: number;
        completed_sessions: number;
    }

    let {
        program = undefined,
        tracks = [],
        processes = [],
        stations = [],
        stats = {
            total_sessions: 0,
            active_sessions: 0,
            completed_sessions: 0,
        },
    }: {
        program?: ProgramItem | null;
        tracks?: TrackItem[];
        processes?: ProcessItem[];
        stations?: StationItem[];
        stats?: ProgramStats;
    } = $props();

    const VALID_TABS = ["overview", "processes", "stations", "staff", "tracks", "diagram", "settings"] as const;
    type TabId = (typeof VALID_TABS)[number];
    const TAB_STORAGE_KEY = (programId: number) =>
        `flexiqueue:admin-program-tab-${programId}`;

    function getInitialTab(): TabId {
        if (typeof window === "undefined") return "overview";
        const fromUrl = new URLSearchParams(window.location.search).get("tab");
        if (fromUrl && VALID_TABS.includes(fromUrl as TabId)) return fromUrl as TabId;
        const pid = program?.id;
        if (pid) {
            try {
                const stored = localStorage.getItem(TAB_STORAGE_KEY(pid));
                if (stored && VALID_TABS.includes(stored as TabId)) return stored as TabId;
            } catch {
                /* ignore */
            }
        }
        return "overview";
    }

    let activeTab = $state<TabId>(getInitialTab());
    /** Tab nav scroll: ref and scroll indicators for left/right arrows (no scrollbar). */
    let tabListEl = $state<HTMLDivElement | null>(null);
    let canScrollLeft = $state(false);
    let canScrollRight = $state(false);
    const TAB_SCROLL_PX = 180;
    function updateTabScrollState() {
        const el = tabListEl;
        if (!el) return;
        canScrollLeft = el.scrollLeft > 2;
        canScrollRight = el.scrollLeft < el.scrollWidth - el.clientWidth - 2;
    }
    function scrollTabList(direction: "left" | "right") {
        const el = tabListEl;
        if (!el) return;
        el.scrollBy({ left: direction === "left" ? -TAB_SCROLL_PX : TAB_SCROLL_PX, behavior: "smooth" });
    }

    const TABS: TabId[] = [...VALID_TABS];

    function handleTabKeydown(e: KeyboardEvent) {
        const key = e.key;
        if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(key)) return;
        e.preventDefault();
        const idx = TABS.indexOf(activeTab);
        let nextIdx: number;
        if (key === "ArrowLeft") nextIdx = idx <= 0 ? TABS.length - 1 : idx - 1;
        else if (key === "ArrowRight") nextIdx = idx >= TABS.length - 1 ? 0 : idx + 1;
        else if (key === "Home") nextIdx = 0;
        else nextIdx = TABS.length - 1;
        const nextTab = TABS[nextIdx];
        activeTab = nextTab;
        const btn = document.getElementById(`tab-${nextTab}`);
        if (btn) (btn as HTMLButtonElement).focus();
    }
    $effect(() => {
        const el = tabListEl;
        if (!el) return;
        updateTabScrollState();
        const onScroll = () => updateTabScrollState();
        const ro = new ResizeObserver(() => updateTabScrollState());
        el.addEventListener("scroll", onScroll);
        ro.observe(el);
        return () => {
            el.removeEventListener("scroll", onScroll);
            ro.disconnect();
        };
    });

    $effect(() => {
        const tab = activeTab;
        queueMicrotask(() => {
            const panel = document.getElementById(`tabpanel-${tab}`);
            if (panel) (panel as HTMLElement).focus();
        });
    });
    let showCreateModal = $state(false);
    let editTrack = $state<TrackItem | null>(null);
    let createName = $state("");
    let createDescription = $state("");
    let createIsDefault = $state(false);
    let createColorCode = $state("");
    let editName = $state("");
    let editDescription = $state("");
    let editIsDefault = $state(false);
    let editColorCode = $state("");
    let showCreateStationModal = $state(false);
    let editStation = $state<StationItem | null>(null);
    let createStationName = $state("");
    let createStationCapacity = $state(1);
    let createStationClientCapacity = $state(1);
    let createStationProcessIds = $state<number[]>([]);
    type StationTtsLangKey = "en" | "fil" | "ilo";
    interface StationTtsConfig {
        voice_id: string;
        rate: number;
        station_phrase: string;
    }
    let createStationTts = $state<Record<StationTtsLangKey, StationTtsConfig>>({
        en: { voice_id: "", rate: 0.84, station_phrase: "" },
        fil: { voice_id: "", rate: 0.84, station_phrase: "" },
        ilo: { voice_id: "", rate: 0.84, station_phrase: "" },
    });
    let editStationName = $state("");
    let editStationCapacity = $state(1);
    let editStationClientCapacity = $state(1);
    let editStationPriorityFirstOverride = $state<boolean | null>(null);
    let editStationIsActive = $state(true);
    let editStationProcessIds = $state<number[]>([]);
    let editStationTts = $state<Record<StationTtsLangKey, StationTtsConfig>>({
        en: { voice_id: "", rate: 0.84, station_phrase: "" },
        fil: { voice_id: "", rate: 0.84, station_phrase: "" },
        ilo: { voice_id: "", rate: 0.84, station_phrase: "" },
    });
    let showStepModal = $state(false);
    let stepModalTrack = $state<TrackItem | null>(null);
    /** Steps shown in the modal; updated in place on add/delete/reorder so modal stays realtime */
    let modalSteps = $state<StepItem[]>([]);
    let addStepProcessId = $state<number | "">("");
    let addStepEstimatedMinutes = $state<number | "">("");
    let editingStepId = $state<number | null>(null);
    let editStepProcessId = $state<number | "">("");
    let editStepEstimatedMinutes = $state<number | "">("");
    let editStepIsRequired = $state(true);
    let createProcessName = $state("");
    let createProcessDescription = $state("");
    let creatingProcess = $state(false);
    let submitting = $state(false);
    let settingsNoShowTimer = $state(10);
    /** Per ISSUES-ELABORATION §20: mm:ss display for no-show timer (5–600 seconds). */
    let noShowTimerMinutes = $state(0);
    let noShowTimerSeconds = $state(10);
    let settingsRequireOverride = $state(true);
    let settingsPriorityFirst = $state(true);
    let settingsBalanceMode = $state<"fifo" | "alternate">("fifo");
    let settingsStationSelectionMode = $state<string>("fixed");
    let settingsAlternateRatioP = $state(2);
    let settingsAlternateRatioR = $state(1);
    /** Per bead flexiqueue-5gl: in alternate mode, which queue is served first. Default true = priority first. */
    let settingsAlternatePriorityFirst = $state(true);
    /** Per flexiqueue-87p: display board scan countdown in seconds; 0 = no auto-close. */
    let displayScanTimeoutSeconds = $state(20);
    /** Per plan: display board audio mute (admin-controlled). */
    let displayAudioMuted = $state(false);
    /** Per plan: display board audio volume 0-1 (admin-controlled). */
    let displayAudioVolume = $state(1);
    /** TTS announcement repeat count (1–3). Default 1. */
    let settingsDisplayTtsRepeatCount = $state(1);
    /** Delay between repeats in seconds (0.5–10) for UI; stored as ms in backend. Default 2. */
    let settingsDisplayTtsRepeatDelaySec = $state(2);
    /** Available browser voices for TTS dropdown (loaded on mount). */
    let availableTtsVoices = $state<{ name: string; lang: string }[]>([]);
    let serverTtsVoices = $state<{ id: string; name: string; lang: string }[]>([]);
    /** Program TTS: active language and connector phrases per language. */
    type TtsLangKey = "en" | "fil" | "ilo";
    type TtsLangArrayKey = "en" | "fil" | "ilo";
    interface ConnectorTtsConfig {
        voice_id: string;
        rate: number;
        connector_phrase: string;
    }
    let ttsActiveLanguage = $state<TtsLangKey>("en");
    /** When true, creating a station automatically queues station TTS generation. When false, save/create without triggering generation. */
    let autoGenerateStationTts = $state(true);
    let connectorTts = $state<Record<TtsLangArrayKey, ConnectorTtsConfig>>({
        en: { voice_id: "", rate: 0.84, connector_phrase: "" },
        fil: { voice_id: "", rate: 0.84, connector_phrase: "" },
        ilo: { voice_id: "", rate: 0.84, connector_phrase: "" },
    });
    /** Modal for editing program-wide connector TTS (shown from Stations tab or Settings shortcut). */
    let showProgramConnectorTtsModal = $state(false);
    /** Modal for editing a single station's TTS (voice, rate, station phrase per language). */
    let showStationTtsModal = $state(false);
    /** Station whose TTS is being edited in the dedicated TTS modal (null when closed). */
    let stationTtsModalStation = $state<StationItem | null>(null);
    /** Which language is currently playing a TTS sample in the station TTS modal. */
    let stationTtsSamplePlayingLang = $state<StationTtsLangKey | null>(null);
    /** Show "regenerate station TTS" confirm after saving program connector TTS. */
    let showProgramTtsRegenerateConfirm = $state(false);
    /** Show "regenerate station TTS" confirm after saving a station's TTS. */
    let showStationTtsRegenerateConfirm = $state(false);
    let programTtsRegenerating = $state(false);
    let stationTtsRegenerating = $state(false);
    /** Station ID currently triggering TTS regeneration (for button loading state). */
    let stationRegeneratingId = $state<number | null>(null);
    /** Per plan: allow public self-serve triage at /triage/start. */
    let allowPublicTriage = $state(false);
    /** Per barcode-hid: enable HID barcode on Display board. Default true. */
    let enableDisplayHidBarcode = $state(true);
    /** Per barcode-hid: enable HID barcode on Public triage. Default true. */
    let enablePublicTriageHidBarcode = $state(true);
    /** Per ISSUES-ELABORATION §15: expandable "More details" for station selection. */
    let showStationSelectionDetails = $state(false);
    /** Per bead flexiqueue-5gl: expandable "More details" for priority/regular ratio (alternate mode). */
    let showRatioDetails = $state(false);

    /** Per ISSUES-ELABORATION §17: local copy so we can update station (e.g. is_active) without full reload. */
    let localStations = $state<StationItem[]>([]);
    $effect(() => {
        localStations = [...stations];
    });

    $effect(() => {
        const s = program?.settings;
        if (s) {
            const total = s.no_show_timer_seconds ?? 10;
            settingsNoShowTimer = total;
            noShowTimerMinutes = Math.floor(total / 60);
            noShowTimerSeconds = total % 60;
            settingsRequireOverride =
                s.require_permission_before_override ?? true;
            settingsPriorityFirst = s.priority_first ?? true;
            settingsBalanceMode = (
                s.balance_mode === "alternate" ? "alternate" : "fifo"
            ) as "fifo" | "alternate";
            settingsStationSelectionMode = s.station_selection_mode ?? "fixed";
            const ar = s.alternate_ratio ?? [2, 1];
            settingsAlternateRatioP = ar[0] ?? 2;
            settingsAlternateRatioR = ar[1] ?? 1;
            settingsAlternatePriorityFirst = s.alternate_priority_first !== false;
            displayScanTimeoutSeconds = Math.min(300, Math.max(0, Number(s.display_scan_timeout_seconds ?? 20)));
            displayAudioMuted = s.display_audio_muted === true;
            displayAudioVolume = Math.max(0, Math.min(1, Number(s.display_audio_volume ?? 1)));
            settingsDisplayTtsRepeatCount = Math.max(1, Math.min(3, Math.floor(Number(s.display_tts_repeat_count ?? 1))));
            settingsDisplayTtsRepeatDelaySec = Math.max(0.5, Math.min(10, (Number(s.display_tts_repeat_delay_ms ?? 2000) / 1000)));
            allowPublicTriage = s.allow_public_triage === true;
            enableDisplayHidBarcode = (s.enable_display_hid_barcode ?? true) === true;
            enablePublicTriageHidBarcode = (s.enable_public_triage_hid_barcode ?? true) === true;
            const tts = (s as { tts?: { active_language?: string; auto_generate_station_tts?: boolean; connector?: { languages?: Record<string, { voice_id?: string; rate?: number; connector_phrase?: string }> } } }).tts;
            if (tts) {
                autoGenerateStationTts = tts.auto_generate_station_tts !== false;
                const lang = (tts.active_language as string | undefined) ?? "en";
                ttsActiveLanguage = (["en", "fil", "ilo"].includes(lang) ? lang : "en") as TtsLangKey;
                const langs = (tts.connector?.languages ?? {}) as Record<
                    string,
                    { voice_id?: string; rate?: number; connector_phrase?: string }
                >;
                connectorTts = {
                    en: {
                        voice_id: (langs.en?.voice_id as string | undefined) ?? "",
                        rate: typeof langs.en?.rate === "number" ? (langs.en?.rate as number) : 0.84,
                        connector_phrase: (langs.en?.connector_phrase as string | undefined) ?? "",
                    },
                    fil: {
                        voice_id: (langs.fil?.voice_id as string | undefined) ?? "",
                        rate: typeof langs.fil?.rate === "number" ? (langs.fil?.rate as number) : 0.84,
                        connector_phrase: (langs.fil?.connector_phrase as string | undefined) ?? "",
                    },
                    ilo: {
                        voice_id: (langs.ilo?.voice_id as string | undefined) ?? "",
                        rate: typeof langs.ilo?.rate === "number" ? (langs.ilo?.rate as number) : 0.84,
                        connector_phrase: (langs.ilo?.connector_phrase as string | undefined) ?? "",
                    },
                };
            }
        }
    });

    onMount(() => {
        // Load available server TTS voices for program-level connector settings.
        if (typeof window === "undefined") return;
        fetch("/api/public/tts/voices", {
            method: "GET",
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
            credentials: "same-origin",
        })
            .then((res) => {
                if (res.status === 419) {
                    toaster.error({ title: MSG_SESSION_EXPIRED });
                    return {};
                }
                return res.json().catch(() => ({}));
            })
            .then((data) => {
                if (data && Array.isArray(data.voices)) {
                    serverTtsVoices = data.voices as { id: string; name: string; lang: string }[];
                }
            })
            .catch(() => {
                serverTtsVoices = [];
                toaster.error({ title: MSG_NETWORK_ERROR });
            });
    });

    $effect(() => {
        if (activeTab !== "staff" || !program?.id) return;
        let cancelled = false;
        staffLoading = true;
        const check419 = (r: Response) => {
            if (r.status === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return Promise.resolve({});
            }
            return r.json().catch(() => ({}));
        };
        Promise.all([
            fetch(`/api/admin/programs/${program.id}/staff-assignments`, {
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            }).then(check419),
            fetch(`/api/admin/programs/${program.id}/supervisors`, {
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            }).then(check419),
        ])
            .then(([assignRes, superRes]) => {
                if (cancelled) return;
                const a = assignRes as { assignments?: typeof staffAssignments; stations?: typeof staffStations };
                const b = superRes as { supervisors?: typeof staffSupervisors; staff_with_pin?: typeof staffWithPin };
                staffAssignments = a?.assignments ?? [];
                staffStations = a?.stations ?? [];
                staffSupervisors = b?.supervisors ?? [];
                staffWithPin = b?.staff_with_pin ?? [];
            })
            .catch(() => {
                if (!cancelled) {
                    staffAssignments = [];
                    toaster.error({ title: MSG_NETWORK_ERROR });
                }
            })
            .finally(() => {
                if (!cancelled) staffLoading = false;
            });
        return () => {
            cancelled = true;
        };
    });
    let showReorderConfirm = $state(false);
    let pendingReorderStepIds = $state<number[]>([]);
    let reorderScope = $state<"new_only" | "migrate">("new_only");
    let confirmStopProgram = $state(false);
    /** When true, show warning that stop is not allowed while queue has clients; suggest Pause. Per bead flexiqueue-nlu. */
    let showStopQueueWarning = $state(false);
    let confirmDeleteTrack = $state<TrackItem | null>(null);
    let confirmDeleteStation = $state<StationItem | null>(null);
    let confirmRemoveStep = $state<{ step: StepItem; track: TrackItem } | null>(
        null,
    );
    // Process tab: edit and delete (per ISSUES-ELABORATION §19)
    let editProcess = $state<ProcessItem | null>(null);
    let editProcessName = $state("");
    let editProcessDescription = $state("");
    let editProcessExpectedTimeMmSs = $state("");
    let createProcessExpectedTimeMmSs = $state("");
    let confirmDeleteProcess = $state<ProcessItem | null>(null);

    /** Per flexiqueue-5l7: format seconds as mm:ss (max 10:00). */
    function secondsToMmSs(sec: number | null | undefined): string {
        if (sec == null || sec <= 0) return "";
        const m = Math.floor(sec / 60);
        const s = sec % 60;
        return `${m}:${String(s).padStart(2, "0")}`;
    }

    /** Parse mm:ss to seconds (0–600). Returns null if invalid. */
    function mmSsToSeconds(str: string): number | null {
        const t = str.trim();
        if (!t) return null;
        const parts = t.split(":");
        if (parts.length !== 2) return null;
        const m = parseInt(parts[0], 10);
        const s = parseInt(parts[1], 10);
        if (Number.isNaN(m) || Number.isNaN(s) || m < 0 || s < 0 || s > 59) return null;
        const total = m * 60 + s;
        return total > 600 ? null : total;
    }
    // Staff tab state
    let staffAssignments = $state<
        Array<{
            user_id: number;
            user: { id: number; name: string; email: string };
            station_id: number | null;
            station: { id: number; name: string } | null;
        }>
    >([]);
    let staffStations = $state<Array<{ id: number; name: string; capacity?: number }>>([]);
    let staffSupervisors = $state<
        Array<{ id: number; name: string; email: string }>
    >([]);
    let staffWithPin = $state<
        Array<{
            id: number;
            name: string;
            email: string;
            is_supervisor: boolean;
        }>
    >([]);
    let staffLoading = $state(false);
    let staffAssigningUserId = $state<number | null>(null);
    let staffAssigningStationId = $state<number | null>(null);

    /** One row per (station, slot) for multiple staff per station. Per bead flexiqueue-bci. */
    const staffStationSlots = $derived(
        staffStations.flatMap((station) =>
            Array.from(
                { length: Math.max(1, station.capacity ?? 1) },
                (_, slotIndex) => ({ station, slotIndex }),
            ),
            ),
    );

    /** Staff currently assigned to a station (first if multiple; null if none). Used for backward compatibility. */
    function getAssignedUserIdForStation(stationId: number): number | null {
        const a = staffAssignments.find((x) => x.station_id === stationId);
        return a?.user_id ?? null;
    }

    /** All user IDs assigned to this station (supports multiple staff per station). Per bead flexiqueue-bci. */
    function getAssignedUserIdsForStation(stationId: number): number[] {
        return staffAssignments
            .filter((x) => x.station_id === stationId)
            .map((x) => x.user_id);
    }
    const page = usePage();
    const serverTtsConfigured = $derived((get(page)?.props as { server_tts_configured?: boolean } | undefined)?.server_tts_configured ?? true);

    // Sync activeTab with URL so direct links with ?tab=stations (or other tab) work when already on this page
    $effect(() => {
        const p = $page;
        const url = (p?.url as string) ?? "";
        const search = url.includes("?") ? url.slice(url.indexOf("?")) : "";
        const tab = new URLSearchParams(search).get("tab");
        if (tab && VALID_TABS.includes(tab as TabId)) activeTab = tab as TabId;
    });
    // Persist tab to localStorage so users return to the same tab when navigating back
    $effect(() => {
        const tab = activeTab;
        const pid = program?.id;
        if (!pid || typeof window === "undefined") return;
        try {
            localStorage.setItem(TAB_STORAGE_KEY(pid), tab);
        } catch {
            /* ignore */
        }
    });
    // Real-time station TTS status: when a station finishes generating, update local list
    $effect(() => {
        const win = window as unknown as { Echo?: { private: (ch: string) => { listen: (ev: string, cb: (e: { station_id: number; settings?: { tts?: StationItem["tts"] } }) => void) => void; leave: () => void } } };
        if (typeof window === "undefined" || !win.Echo || localStations.length === 0) return;
        const Echo = win.Echo;
        const ch = "admin.station-tts";
        const channel = Echo.private(ch);
        channel.listen(".station_tts_status_updated", (e: { station_id: number; settings?: { tts?: StationItem["tts"] } }) => {
            localStations = localStations.map((s) =>
                s.id === e.station_id ? { ...s, tts: e.settings?.tts ?? s.tts } : s,
            );
        });
        return () => {
            try {
                channel.leave();
            } catch {
                /* ignore */
            }
        };
    });
    const diagramViewMode = $derived(
        (() => {
            const p = $page;
            const url = (p?.url as string) ?? (typeof window !== "undefined" ? window.location.href : "");
            try {
                const search = url.includes("?") ? url.slice(url.indexOf("?")) : "";
                return new URLSearchParams(search).get("mode") === "view" && activeTab === "diagram";
            } catch {
                return false;
            }
        })()
    );
    function getCsrfToken(): string {
        const p = get(page);
        const fromProps = (p?.props as { csrf_token?: string } | undefined)
            ?.csrf_token;
        if (fromProps) return fromProps;
        const meta =
            typeof document !== "undefined"
                ? (
                      document.querySelector(
                          'meta[name="csrf-token"]',
                      ) as HTMLMetaElement
                  )?.content
                : "";
        return meta ?? "";
    }

    const MSG_SESSION_EXPIRED = "Session expired. Please refresh and try again.";
    const MSG_NETWORK_ERROR = "Network error. Please try again.";

    async function api(
        method: string,
        url: string,
        body?: object,
    ): Promise<{ ok: boolean; data?: object; message?: string }> {
        try {
            const res = await fetch(url, {
                method,
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
                ...(body ? { body: JSON.stringify(body) } : {}),
            });
            if (res.status === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return { ok: false, message: MSG_SESSION_EXPIRED };
            }
            const data = await res.json().catch(() => ({}));
            return { ok: res.ok, data, message: data?.message };
        } catch (e) {
            toaster.error({ title: MSG_NETWORK_ERROR });
            return { ok: false, message: MSG_NETWORK_ERROR };
        }
    }

    async function handlePause() {
        submitting = true;
        const { ok, message } = await api(
            "POST",
            `/api/admin/programs/${program.id}/pause`,
        );
        submitting = false;
        if (ok) router.reload();
        else toaster.error({ title: message ?? "Failed to pause." });
    }

    async function handleResume() {
        submitting = true;
        const { ok, message } = await api(
            "POST",
            `/api/admin/programs/${program.id}/resume`,
        );
        submitting = false;
        if (ok) router.reload();
        else toaster.error({ title: message ?? "Failed to resume." });
    }

    /** Per ISSUES-ELABORATION §16: 422 shows message + optional missing list. */
    const ACTIVATE_MISSING_LABELS: Record<string, string> = {
        no_stations: "Add at least one station.",
        no_processes_with_stations: "Assign at least one process to a station.",
        no_staff_assigned: "Assign at least one staff member to a station.",
        no_tracks: "Add at least one track.",
    };
    let activateMissing = $state<string[]>([]);

    async function handleActivate() {
        submitting = true;
        activateMissing = [];
        const { ok, message, data } = await api(
            "POST",
            `/api/admin/programs/${program.id}/activate`,
        );
        submitting = false;
        if (ok) router.reload();
        else {
            toaster.error({ title: message ?? "Failed to start session." });
            const missing = (data as { missing?: string[] } | undefined)?.missing;
            if (Array.isArray(missing))
                activateMissing = missing.map((k) => ACTIVATE_MISSING_LABELS[k] ?? k);
        }
    }

    /** Per bead flexiqueue-nlu: if queue has active sessions, show warning + suggest Pause; else show confirm then deactivate. */
    function openStopConfirm() {
        const active = stats?.active_sessions ?? 0;
        if (active > 0) {
            showStopQueueWarning = true;
        } else {
            confirmStopProgram = true;
        }
    }

    async function handleStopConfirm() {
        submitting = true;
        const { ok, message } = await api(
            "POST",
            `/api/admin/programs/${program.id}/deactivate`,
        );
        submitting = false;
        if (ok) {
            confirmStopProgram = false;
            router.reload();
        } else {
            toaster.error({
                title: message ?? "You can only stop the session when no clients are in the queue.",
            });
        }
    }

    function closeStopConfirm() {
        confirmStopProgram = false;
    }

    function closeStopQueueWarning() {
        showStopQueueWarning = false;
    }

    async function handlePauseFromStopWarning() {
        closeStopQueueWarning();
        await handlePause();
    }

    function openCreate() {
        createName = "";
        createDescription = "";
        createIsDefault = false;
        createColorCode = "";
        showCreateModal = true;
    }

    function openEdit(t: TrackItem) {
        editTrack = t;
        editName = t.name;
        editDescription = t.description ?? "";
        editIsDefault = t.is_default;
        editColorCode = t.color_code ?? "";
    }

    function openStepModal(t: TrackItem) {
        stepModalTrack = t;
        modalSteps = stepList(t);
        addStepProcessId = "";
        addStepEstimatedMinutes = "";
        editingStepId = null;
        showStepModal = true;
    }

    function openEditStep(step: StepItem) {
        editingStepId = step.id;
        editStepProcessId = step.process_id;
        editStepEstimatedMinutes = step.estimated_minutes ?? "";
        editStepIsRequired = step.is_required;
    }

    function cancelEditStep() {
        editingStepId = null;
    }

    async function handleUpdateStep() {
        if (editingStepId == null) return;
        submitting = true;
        const body: {
            process_id?: number;
            estimated_minutes?: number | null;
            is_required?: boolean;
        } = {};
        if (editStepProcessId !== "")
            body.process_id = editStepProcessId as number;
        body.estimated_minutes =
            editStepEstimatedMinutes === ""
                ? null
                : Number(editStepEstimatedMinutes);
        body.is_required = editStepIsRequired;
        const { ok, data, message } = await api(
            "PUT",
            `/api/admin/steps/${editingStepId}`,
            body,
        );
        submitting = false;
        if (ok && data?.step) {
            const updated = data.step as StepItem;
            modalSteps = modalSteps.map((s) =>
                s.id === updated.id ? updated : s,
            );
            editingStepId = null;
        } else {
            toaster.error({ title: message ?? "Failed to update step." });
        }
    }

    function closeStepModal() {
        showStepModal = false;
        stepModalTrack = null;
        modalSteps = [];
        router.reload();
    }

    function closeModals() {
        showCreateModal = false;
        editTrack = null;
        showCreateStationModal = false;
        editStation = null;
    }

    async function handleCreate() {
        if (!createName.trim()) return;
        submitting = true;
        const { ok, data, message } = await api(
            "POST",
            `/api/admin/programs/${program.id}/tracks`,
            {
                name: createName.trim(),
                description: createDescription.trim() || null,
                is_default: createIsDefault,
                color_code: createColorCode.trim() || null,
            },
        );
        submitting = false;
        if (ok) {
            closeModals();
            router.reload();
        } else {
            toaster.error({
                title:
                    message ??
                    (data && "errors" in data
                        ? JSON.stringify(
                              (data as { errors?: Record<string, string[]> }).errors,
                          )
                        : "Failed to create track."),
            });
        }
    }

    async function handleUpdate() {
        if (!editTrack || !editName.trim()) return;
        submitting = true;
        const { ok, message } = await api(
            "PUT",
            `/api/admin/tracks/${editTrack.id}`,
            {
                name: editName.trim(),
                description: editDescription.trim() || null,
                is_default: editIsDefault,
                color_code: editColorCode.trim() || null,
            },
        );
        submitting = false;
        if (ok) {
            closeModals();
            router.reload();
        } else {
            toaster.error({ title: message ?? "Failed to update track." });
        }
    }

    function openDeleteTrackConfirm(t: TrackItem) {
        confirmDeleteTrack = t;
    }

    async function handleDeleteTrackConfirm() {
        if (!confirmDeleteTrack) return;
        const t = confirmDeleteTrack;
        submitting = true;
        const { ok, message } = await api(
            "DELETE",
            `/api/admin/tracks/${t.id}`,
        );
        submitting = false;
        if (ok) {
            confirmDeleteTrack = null;
            router.reload();
        } else {
            toaster.error({ title: message ?? "Cannot delete: active sessions use this track." });
        }
    }

    function closeDeleteTrackConfirm() {
        confirmDeleteTrack = null;
    }

    function openCreateStation() {
        createStationName = "";
        createStationCapacity = 1;
        createStationClientCapacity = 1;
        createStationProcessIds = [];
        createStationTts = {
            en: { voice_id: "", rate: 0.84, station_phrase: "" },
            fil: { voice_id: "", rate: 0.84, station_phrase: "" },
            ilo: { voice_id: "", rate: 0.84, station_phrase: "" },
        };
        showCreateStationModal = true;
    }

    function openEditStation(s: StationItem) {
        editStation = s;
        editStationName = s.name;
        editStationCapacity = s.capacity;
        editStationClientCapacity = s.client_capacity ?? 1;
        editStationPriorityFirstOverride = s.priority_first_override ?? null;
        editStationIsActive = s.is_active;
        editStationProcessIds = [...(s.process_ids ?? [])];
        const langs =
            (s.tts?.languages as
                | Record<string, { voice_id?: string; rate?: number; station_phrase?: string }>
                | undefined) ?? {};
        editStationTts = {
            en: {
                voice_id: (langs.en?.voice_id as string | undefined) ?? "",
                rate: typeof langs.en?.rate === "number" ? (langs.en?.rate as number) : 0.84,
                station_phrase: (langs.en?.station_phrase as string | undefined) ?? "",
            },
            fil: {
                voice_id: (langs.fil?.voice_id as string | undefined) ?? "",
                rate: typeof langs.fil?.rate === "number" ? (langs.fil?.rate as number) : 0.84,
                station_phrase: (langs.fil?.station_phrase as string | undefined) ?? "",
            },
            ilo: {
                voice_id: (langs.ilo?.voice_id as string | undefined) ?? "",
                rate: typeof langs.ilo?.rate === "number" ? (langs.ilo?.rate as number) : 0.84,
                station_phrase: (langs.ilo?.station_phrase as string | undefined) ?? "",
            },
        };
    }

    /** Open the dedicated Station TTS modal for a station; syncs edit form so Save can call handleUpdateStation. */
    function openEditStationTtsModal(s: StationItem) {
        editStation = s;
        editStationName = s.name;
        editStationCapacity = s.capacity;
        editStationClientCapacity = s.client_capacity ?? 1;
        editStationPriorityFirstOverride = s.priority_first_override ?? null;
        editStationIsActive = s.is_active;
        editStationProcessIds = [...(s.process_ids ?? [])];
        const langs =
            (s.tts?.languages as
                | Record<string, { voice_id?: string; rate?: number; station_phrase?: string }>
                | undefined) ?? {};
        editStationTts = {
            en: {
                voice_id: (langs.en?.voice_id as string | undefined) ?? "",
                rate: typeof langs.en?.rate === "number" ? (langs.en?.rate as number) : 0.84,
                station_phrase: (langs.en?.station_phrase as string | undefined) ?? "",
            },
            fil: {
                voice_id: (langs.fil?.voice_id as string | undefined) ?? "",
                rate: typeof langs.fil?.rate === "number" ? (langs.fil?.rate as number) : 0.84,
                station_phrase: (langs.fil?.station_phrase as string | undefined) ?? "",
            },
            ilo: {
                voice_id: (langs.ilo?.voice_id as string | undefined) ?? "",
                rate: typeof langs.ilo?.rate === "number" ? (langs.ilo?.rate as number) : 0.84,
                station_phrase: (langs.ilo?.station_phrase as string | undefined) ?? "",
            },
        };
        stationTtsModalStation = s;
        showStationTtsModal = true;
    }

    function closeStationTtsModal() {
        showStationTtsModal = false;
        stationTtsModalStation = null;
        stationTtsSamplePlayingLang = null;
    }

    /** Aggregate TTS status from station.settings.tts.languages (ready, failed, or not generated). */
    function getStationTtsStatus(station: StationItem): "ready" | "failed" | null {
        const langs = station.tts?.languages as Record<string, { status?: string }> | undefined;
        if (!langs || typeof langs !== "object") return null;
        let hasReady = false;
        let hasFailed = false;
        for (const lang of Object.keys(langs)) {
            const s = langs[lang]?.status;
            if (s === "ready") hasReady = true;
            if (s === "failed") hasFailed = true;
        }
        if (hasFailed) return "failed";
        if (hasReady) return "ready";
        return null;
    }

    async function regenerateStationTts(station: StationItem) {
        if (stationRegeneratingId !== null || submitting) return;
        stationRegeneratingId = station.id;
        try {
            const res = await fetch(`/api/admin/stations/${station.id}/regenerate-tts`, {
                method: "POST",
                headers: { "Content-Type": "application/json", Accept: "application/json", "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": getCsrfToken() },
                credentials: "same-origin",
            });
            if (res.status === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return;
            }
            if (!res.ok) toaster.error({ title: "Failed to start regeneration." });
            else router.reload();
        } catch (e) {
            const isNetwork = e instanceof TypeError && (e as Error).message === "Failed to fetch";
            toaster.error({ title: isNetwork ? MSG_NETWORK_ERROR : "Failed to start regeneration." });
        } finally {
            stationRegeneratingId = null;
        }
    }

    /** Play TTS sample for station phrase in the given language (connector + station name). */
    async function playStationTtsSample(lang: StationTtsLangKey) {
        if (stationTtsSamplePlayingLang || !stationTtsModalStation || !program) return;
        stationTtsSamplePlayingLang = lang;
        try {
            const connectorPhrase =
                (lang === "en"
                    ? connectorTts.en.connector_phrase
                    : lang === "fil"
                      ? connectorTts.fil.connector_phrase
                      : connectorTts.ilo.connector_phrase) ?? "";
            const phraseParams = new URLSearchParams({
                lang,
                pre_phrase: connectorPhrase.trim(),
                alias: stationTtsModalStation.name || "Station",
                pronounce_as: "word",
            });
            const phraseRes = await fetch(`/api/admin/tts/sample-phrase?${phraseParams.toString()}`, {
                method: "GET",
                headers: { Accept: "application/json", "X-CSRF-TOKEN": getCsrfToken(), "X-Requested-With": "XMLHttpRequest" },
                credentials: "same-origin",
            });
            if (phraseRes.status === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return;
            }
            const phraseData = await phraseRes.json().catch(() => ({}));
            if (!phraseRes.ok || typeof phraseData?.text !== "string") {
                toaster.error({ title: "Could not get sample phrase." });
                return;
            }
            const config = lang === "en" ? editStationTts.en : lang === "fil" ? editStationTts.fil : editStationTts.ilo;
            const voiceId = (config.voice_id && config.voice_id.trim()) || "";
            const ttsParams = new URLSearchParams({ text: phraseData.text, rate: String(config.rate) });
            if (voiceId) ttsParams.set("voice", voiceId);
            const ttsRes = await fetch(`/api/public/tts?${ttsParams.toString()}`, {
                method: "GET",
                headers: { Accept: "audio/mpeg", "X-Requested-With": "XMLHttpRequest" },
                credentials: "same-origin",
            });
            if (ttsRes.status === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return;
            }
            if (!ttsRes.ok) {
                toaster.error({ title: "TTS sample unavailable." });
                return;
            }
            const blob = await ttsRes.blob();
            const objectUrl = URL.createObjectURL(blob);
            await new Promise<void>((resolve, reject) => {
                const audio = new Audio(objectUrl);
                audio.onended = () => {
                    URL.revokeObjectURL(objectUrl);
                    resolve();
                };
                audio.onerror = () => {
                    URL.revokeObjectURL(objectUrl);
                    reject(new Error("Playback failed"));
                };
                audio.volume = 1;
                audio.play().catch(reject);
            });
        } catch (e) {
            const isNetwork = e instanceof TypeError && (e as Error).message === "Failed to fetch";
            toaster.error({ title: isNetwork ? MSG_NETWORK_ERROR : "Failed to play TTS sample." });
        } finally {
            stationTtsSamplePlayingLang = null;
        }
    }

    function toggleCreateProcess(id: number) {
        if (createStationProcessIds.includes(id)) {
            createStationProcessIds = createStationProcessIds.filter(
                (x) => x !== id,
            );
        } else {
            createStationProcessIds = [...createStationProcessIds, id];
        }
    }

    function toggleEditProcess(id: number) {
        if (editStationProcessIds.includes(id)) {
            editStationProcessIds = editStationProcessIds.filter(
                (x) => x !== id,
            );
        } else {
            editStationProcessIds = [...editStationProcessIds, id];
        }
    }

    async function handleCreateStation() {
        if (!createStationName.trim()) return;
        if (createStationProcessIds.length === 0) {
            toaster.error({ title: "Select at least one process." });
            return;
        }
        submitting = true;
        const { ok, data, message } = await api(
            "POST",
            `/api/admin/programs/${program.id}/stations`,
            {
                name: createStationName.trim(),
                capacity: createStationCapacity,
                client_capacity: createStationClientCapacity,
                process_ids: createStationProcessIds,
                tts: {
                    languages: {
                        en: {
                            voice_id: createStationTts.en.voice_id || null,
                            rate: createStationTts.en.rate,
                            station_phrase: createStationTts.en.station_phrase.trim() || null,
                        },
                        fil: {
                            voice_id: createStationTts.fil.voice_id || null,
                            rate: createStationTts.fil.rate,
                            station_phrase: createStationTts.fil.station_phrase.trim() || null,
                        },
                        ilo: {
                            voice_id: createStationTts.ilo.voice_id || null,
                            rate: createStationTts.ilo.rate,
                            station_phrase: createStationTts.ilo.station_phrase.trim() || null,
                        },
                    },
                },
            },
        );
        submitting = false;
        if (ok) {
            closeModals();
            router.reload();
        } else {
            const d = data as {
                message?: string;
                errors?: Record<string, string[]>;
            };
            const firstErr = d?.errors
                ? Object.values(d.errors).flat()[0]
                : null;
            toaster.error({ title: firstErr ?? message ?? "Failed to create station." });
        }
    }

    async function handleCreateProcess() {
        if (!createProcessName.trim()) return;
        creatingProcess = true;
        submitting = true;
        const { ok, data, message } = await api(
            "POST",
            `/api/admin/programs/${program.id}/processes`,
                {
                    name: createProcessName.trim(),
                    description: createProcessDescription.trim() || null,
                    expected_time_seconds: mmSsToSeconds(createProcessExpectedTimeMmSs) ?? null,
                },
        );
        creatingProcess = false;
        submitting = false;
        if (ok) {
            createProcessName = "";
            createProcessDescription = "";
            createProcessExpectedTimeMmSs = "";
            router.reload();
        } else {
            const d = data as {
                message?: string;
                errors?: Record<string, string[]>;
            };
            const firstErr = d?.errors
                ? Object.values(d.errors).flat()[0]
                : null;
            toaster.error({
                title: firstErr ?? d?.message ?? message ?? "Failed to create process.",
            });
        }
    }

    function openEditProcessModal(proc: ProcessItem) {
        editProcess = proc;
        editProcessName = proc.name;
        editProcessDescription = proc.description ?? "";
        editProcessExpectedTimeMmSs = secondsToMmSs(proc.expected_time_seconds ?? null) || "";
    }

    function closeEditProcessModal() {
        editProcess = null;
        editProcessName = "";
        editProcessDescription = "";
        editProcessExpectedTimeMmSs = "";
    }

    async function handleUpdateProcess() {
        if (!editProcess || !program || !editProcessName.trim()) return;
        submitting = true;
        const expectedSeconds = mmSsToSeconds(editProcessExpectedTimeMmSs);
        const { ok, data, message } = await api(
            "PUT",
            `/api/admin/programs/${program.id}/processes/${editProcess.id}`,
            {
                name: editProcessName.trim(),
                description: editProcessDescription.trim() || null,
                expected_time_seconds: expectedSeconds ?? null,
            },
        );
        submitting = false;
        if (ok) {
            closeEditProcessModal();
            router.reload();
        } else {
            const d = data as {
                message?: string;
                errors?: Record<string, string[]>;
            };
            const firstErr = d?.errors
                ? Object.values(d.errors).flat()[0]
                : null;
            toaster.error({
                title: firstErr ?? d?.message ?? message ?? "Failed to update process.",
            });
        }
    }

    async function handleUpdateStation() {
        if (!editStation || !editStationName.trim()) return;
        if (editStationProcessIds.length === 0) {
            toaster.error({ title: "Select at least one process." });
            return;
        }
        submitting = true;
        const { ok, data, message } = await api(
            "PUT",
            `/api/admin/stations/${editStation.id}`,
            {
                name: editStationName.trim(),
                capacity: editStationCapacity,
                client_capacity: editStationClientCapacity,
                priority_first_override: editStationPriorityFirstOverride,
                is_active: editStationIsActive,
                process_ids: editStationProcessIds,
                tts: {
                    languages: {
                        en: {
                            voice_id: editStationTts.en.voice_id || null,
                            rate: editStationTts.en.rate,
                            station_phrase: editStationTts.en.station_phrase.trim() || null,
                        },
                        fil: {
                            voice_id: editStationTts.fil.voice_id || null,
                            rate: editStationTts.fil.rate,
                            station_phrase: editStationTts.fil.station_phrase.trim() || null,
                        },
                        ilo: {
                            voice_id: editStationTts.ilo.voice_id || null,
                            rate: editStationTts.ilo.rate,
                            station_phrase: editStationTts.ilo.station_phrase.trim() || null,
                        },
                    },
                },
            },
        );
        submitting = false;
        if (ok) {
            const d = data as { requires_regeneration?: boolean; errors?: Record<string, string[]> };
            if (d?.requires_regeneration) {
                showStationTtsRegenerateConfirm = true;
            } else {
                closeModals();
                closeStationTtsModal();
                router.reload();
            }
        } else {
            const d = data as {
                message?: string;
                errors?: Record<string, string[]>;
            };
            const firstErr = d?.errors
                ? Object.values(d.errors).flat()[0]
                : null;
            toaster.error({ title: firstErr ?? message ?? "Failed to update station." });
        }
    }

    function openDeleteStationConfirm(s: StationItem) {
        confirmDeleteStation = s;
    }

    async function handleDeleteStationConfirm() {
        if (!confirmDeleteStation) return;
        const s = confirmDeleteStation;
        submitting = true;
        const { ok, message } = await api(
            "DELETE",
            `/api/admin/stations/${s.id}`,
        );
        submitting = false;
        if (ok) {
            confirmDeleteStation = null;
            router.reload();
        } else {
            toaster.error({ title: message ?? "Cannot delete: station is used in track steps." });
        }
    }

    function closeDeleteStationConfirm() {
        confirmDeleteStation = null;
    }

    function openDeleteProcessConfirm(proc: ProcessItem) {
        confirmDeleteProcess = proc;
    }

    async function handleDeleteProcessConfirm() {
        if (!confirmDeleteProcess || !program) return;
        const proc = confirmDeleteProcess;
        submitting = true;
        const { ok, message } = await api(
            "DELETE",
            `/api/admin/programs/${program.id}/processes/${proc.id}`,
        );
        submitting = false;
        if (ok) {
            confirmDeleteProcess = null;
            router.reload();
        } else {
            toaster.error({ title: message ?? "Cannot delete process." });
        }
    }

    function closeDeleteProcessConfirm() {
        confirmDeleteProcess = null;
    }

    async function handleToggleStationActive(s: StationItem) {
        submitting = true;
        // Per ISSUES-ELABORATION §17: include current process_ids so validation passes when only toggling is_active
        const processIds =
            s.process_ids && s.process_ids.length > 0 ? s.process_ids : [];
        const { ok, data, message } = await api(
            "PUT",
            `/api/admin/stations/${s.id}`,
            {
                name: s.name,
                capacity: s.capacity,
                client_capacity: s.client_capacity ?? 1,
                is_active: !s.is_active,
                process_ids: processIds,
            },
        );
        submitting = false;
        if (ok) {
            const payload = data as
                | { station?: StationItem; warning?: string }
                | undefined;
            if (payload?.station) {
                const updated = payload.station;
                localStations = localStations.map((st) =>
                    st.id === updated.id ? { ...st, ...updated } : st,
                );
            }
            if (payload?.warning) {
                toaster.info({ title: payload.warning });
            }
        } else {
            toaster.error({ title: message ?? "Failed to update station." });
        }
    }

    async function handleAddStep() {
        if (!stepModalTrack || addStepProcessId === "") return;
        submitting = true;
        const body: { process_id: number; estimated_minutes?: number } = {
            process_id: addStepProcessId as number,
        };
        if (addStepEstimatedMinutes !== "")
            body.estimated_minutes = addStepEstimatedMinutes as number;
        const { ok, data, message } = await api(
            "POST",
            `/api/admin/tracks/${stepModalTrack.id}/steps`,
            body,
        );
        submitting = false;
        if (ok && data?.step) {
            const s = data.step as StepItem;
            modalSteps = [...modalSteps, s].sort(
                (a, b) => a.step_order - b.step_order,
            );
            addStepProcessId = "";
            addStepEstimatedMinutes = "";
        } else {
            toaster.error({ title: message ?? "Failed to add step." });
        }
    }

    function openRemoveStepConfirm(step: StepItem) {
        if (!stepModalTrack) return;
        confirmRemoveStep = { step, track: stepModalTrack };
    }

    async function handleRemoveStepConfirm() {
        if (!confirmRemoveStep) return;
        const { step } = confirmRemoveStep;
        submitting = true;
        const { ok, message } = await api(
            "DELETE",
            `/api/admin/steps/${step.id}`,
        );
        submitting = false;
        if (ok) {
            modalSteps = modalSteps.filter((s) => s.id !== step.id);
            confirmRemoveStep = null;
        } else {
            toaster.error({ title: message ?? "Failed to delete step." });
        }
    }

    function closeRemoveStepConfirm() {
        confirmRemoveStep = null;
    }

    function stepList(t: TrackItem): StepItem[] {
        const s = (t.steps ?? [])
            .slice()
            .sort((a, b) => a.step_order - b.step_order);
        return s;
    }

    async function handleMoveStepUp(step: StepItem) {
        if (!stepModalTrack) return;
        const list = [...modalSteps];
        const i = list.findIndex((s) => s.id === step.id);
        if (i <= 0) return;
        [list[i - 1], list[i]] = [list[i], list[i - 1]];
        const stepIds = list.map((s) => s.id);
        const activeCount = stepModalTrack.active_sessions_count ?? 0;
        if (activeCount > 0) {
            pendingReorderStepIds = stepIds;
            reorderScope = "new_only";
            showReorderConfirm = true;
        } else {
            await submitReorder(stepModalTrack, stepIds, false);
        }
    }

    async function handleMoveStepDown(step: StepItem) {
        if (!stepModalTrack) return;
        const list = [...modalSteps];
        const i = list.findIndex((s) => s.id === step.id);
        if (i < 0 || i >= list.length - 1) return;
        [list[i], list[i + 1]] = [list[i + 1], list[i]];
        const stepIds = list.map((s) => s.id);
        const activeCount = stepModalTrack.active_sessions_count ?? 0;
        if (activeCount > 0) {
            pendingReorderStepIds = stepIds;
            reorderScope = "new_only";
            showReorderConfirm = true;
        } else {
            await submitReorder(stepModalTrack, stepIds, false);
        }
    }

    function closeReorderConfirm() {
        showReorderConfirm = false;
        pendingReorderStepIds = [];
        reorderScope = "new_only";
    }

    async function confirmReorderApply() {
        if (!stepModalTrack) return;
        await submitReorder(
            stepModalTrack,
            pendingReorderStepIds,
            reorderScope === "migrate",
        );
        closeReorderConfirm();
    }

    async function submitReorder(
        t: TrackItem,
        stepIds: number[],
        migrateSessions: boolean = false,
    ) {
        submitting = true;
        const { ok, data, message } = await api(
            "POST",
            `/api/admin/tracks/${t.id}/steps/reorder`,
            {
                step_ids: stepIds,
                migrate_sessions: migrateSessions,
            },
        );
        submitting = false;
        if (ok && data?.steps) {
            modalSteps = data.steps as StepItem[];
        } else {
            toaster.error({ title: message ?? "Failed to reorder steps." });
        }
    }

    async function handleSaveSettings() {
        submitting = true;
        const { ok, data, message } = await api(
            "PUT",
            `/api/admin/programs/${program.id}`,
            {
                name: program.name,
                description: program.description,
                settings: {
                    no_show_timer_seconds: Math.min(
                        600,
                        Math.max(5, noShowTimerMinutes * 60 + noShowTimerSeconds),
                    ),
                    require_permission_before_override: settingsRequireOverride,
                    priority_first: settingsPriorityFirst,
                    balance_mode: settingsBalanceMode,
                    station_selection_mode: settingsStationSelectionMode,
                    alternate_ratio: [
                        settingsAlternateRatioP,
                        settingsAlternateRatioR,
                    ],
                    alternate_priority_first: settingsAlternatePriorityFirst,
                    display_scan_timeout_seconds: displayScanTimeoutSeconds,
                    display_audio_muted: displayAudioMuted,
                    display_audio_volume: displayAudioVolume,
                    display_tts_repeat_count: Math.max(1, Math.min(3, Math.floor(Number(settingsDisplayTtsRepeatCount) || 1))),
                    display_tts_repeat_delay_ms: Math.round(settingsDisplayTtsRepeatDelaySec * 1000),
                    allow_public_triage: allowPublicTriage,
                    enable_display_hid_barcode: enableDisplayHidBarcode,
                    enable_public_triage_hid_barcode: enablePublicTriageHidBarcode,
                    tts: {
                        active_language: ttsActiveLanguage,
                        auto_generate_station_tts: autoGenerateStationTts,
                        connector: {
                            languages: {
                                en: {
                                    voice_id: connectorTts.en.voice_id || null,
                                    rate: connectorTts.en.rate,
                                    connector_phrase: connectorTts.en.connector_phrase.trim() || null,
                                },
                                fil: {
                                    voice_id: connectorTts.fil.voice_id || null,
                                    rate: connectorTts.fil.rate,
                                    connector_phrase: connectorTts.fil.connector_phrase.trim() || null,
                                },
                                ilo: {
                                    voice_id: connectorTts.ilo.voice_id || null,
                                    rate: connectorTts.ilo.rate,
                                    connector_phrase: connectorTts.ilo.connector_phrase.trim() || null,
                                },
                            },
                        },
                    },
                },
            },
        );
        submitting = false;
        if (ok) {
            toaster.success({ title: "Settings saved." });
            const d = data as { requires_regeneration?: boolean } | undefined;
            if (d?.requires_regeneration) showProgramTtsRegenerateConfirm = true;
            else router.reload();
        } else toaster.error({ title: message ?? "Failed to save settings." });
    }

    /** Per ISSUES-ELABORATION §2: load saved default settings into form (does not save to program until user clicks Save). */
    async function applyDefaultSettings() {
        const { ok, data } = await api("GET", "/api/admin/program-default-settings");
        if (!ok || !data) return;
        const s = (data as { settings?: Record<string, unknown> }).settings ?? {};
        const total = Number(s.no_show_timer_seconds ?? 10);
        settingsNoShowTimer = total;
        noShowTimerMinutes = Math.floor(total / 60);
        noShowTimerSeconds = total % 60;
        settingsRequireOverride = Boolean(s.require_permission_before_override ?? true);
        settingsPriorityFirst = Boolean(s.priority_first ?? true);
        settingsBalanceMode = ((s.balance_mode as string) ?? "fifo") as "fifo" | "alternate";
        settingsStationSelectionMode = String(s.station_selection_mode ?? "fixed");
        const ar = (s.alternate_ratio as number[] | undefined) ?? [2, 1];
        settingsAlternateRatioP = Number(ar[0] ?? 2);
        settingsAlternateRatioR = Number(ar[1] ?? 1);
        settingsAlternatePriorityFirst = (s.alternate_priority_first as boolean | undefined) !== false;
        displayScanTimeoutSeconds = Math.min(300, Math.max(0, Number((s as { display_scan_timeout_seconds?: number }).display_scan_timeout_seconds ?? 20)));
    }

    async function handleAssignStaff(userId: number, stationId: number | null) {
        staffAssigningUserId = userId;
        if (stationId === null) {
            const { ok, message: msg } = await api(
                "DELETE",
                `/api/admin/programs/${program.id}/staff-assignments/${userId}`,
            );
            if (ok) {
                staffAssignments = staffAssignments.map((a) =>
                    a.user_id === userId
                        ? { ...a, station_id: null, station: null }
                        : a,
                );
            } else toaster.error({ title: msg ?? "Failed to unassign." });
        } else {
            const { ok, message: msg } = await api(
                "POST",
                `/api/admin/programs/${program.id}/staff-assignments`,
                {
                    user_id: userId,
                    station_id: stationId,
                },
            );
            if (ok) {
                const station = staffStations.find((s) => s.id === stationId);
                staffAssignments = staffAssignments.map((a) =>
                    a.user_id === userId
                        ? {
                              ...a,
                              station_id: stationId,
                              station: station
                                  ? { id: station.id, name: station.name }
                                  : null,
                          }
                        : a,
                );
            } else toaster.error({ title: msg ?? "Failed to assign." });
        }
        staffAssigningUserId = null;
    }

    /** Station-centric: assign or unassign staff for a station (replaces any existing). */
    async function handleAssignStaffForStation(
        stationId: number,
        userId: number | null,
    ) {
        staffAssigningStationId = stationId;
        const current = getAssignedUserIdForStation(stationId);
        if (userId === null) {
            if (current != null) {
                await handleAssignStaff(current, null);
            }
        } else {
            if (current != null && current !== userId) {
                await handleAssignStaff(current, null);
            }
            await handleAssignStaff(userId, stationId);
        }
        staffAssigningStationId = null;
    }

    /** Per-slot assign/unassign for multiple staff per station (flexiqueue-bci). */
    async function handleAssignStaffForStationSlot(
        stationId: number,
        slotIndex: number,
        newUserId: number | null,
    ) {
        const assignedIds = getAssignedUserIdsForStation(stationId);
        const oldUserId = assignedIds[slotIndex] ?? null;
        if (newUserId === oldUserId) return;
        staffAssigningStationId = stationId;
        if (oldUserId != null) await handleAssignStaff(oldUserId, null);
        if (newUserId != null) await handleAssignStaff(newUserId, stationId);
        staffAssigningStationId = null;
    }

    async function handleAddSupervisor(userId: number) {
        const { ok, message: msg } = await api(
            "POST",
            `/api/admin/programs/${program.id}/supervisors`,
            { user_id: userId },
        );
        if (ok) {
            const u = staffWithPin.find((u) => u.id === userId);
            if (u) {
                staffSupervisors = [
                    ...staffSupervisors,
                    { id: u.id, name: u.name, email: u.email },
                ];
                staffWithPin = staffWithPin.map((x) =>
                    x.id === userId ? { ...x, is_supervisor: true } : x,
                );
            }
        } else toaster.error({ title: msg ?? "Failed to add supervisor." });
    }

    async function handleRemoveSupervisor(userId: number) {
        const removed = staffSupervisors.find((s) => s.id === userId);
        const { ok, message: msg } = await api(
            "DELETE",
            `/api/admin/programs/${program.id}/supervisors/${userId}`,
        );
        if (ok) {
            staffSupervisors = staffSupervisors.filter((s) => s.id !== userId);
            const inList = staffWithPin.some((u) => u.id === userId);
            if (inList) {
                staffWithPin = staffWithPin.map((x) =>
                    x.id === userId ? { ...x, is_supervisor: false } : x,
                );
            } else if (removed) {
                // Ensure removed supervisor can be re-added from the "Add supervisor" list
                staffWithPin = [
                    ...staffWithPin,
                    {
                        id: removed.id,
                        name: removed.name,
                        email: removed.email,
                        is_supervisor: false,
                    },
                ];
            }
        } else toaster.error({ title: msg ?? "Failed to remove supervisor." });
    }

    function formatDate(iso: string | null): string {
        if (!iso) return "";
        try {
            return new Date(iso).toLocaleDateString(undefined, {
                dateStyle: "medium",
            });
        } catch {
            return iso;
        }
    }
</script>

<svelte:head>
    <title>{program?.name ?? "Program"} — Programs — FlexiQueue</title>
</svelte:head>

<AdminLayout>
    {#if !program}
        <div class="p-6 text-surface-600">
            <p>Program not found or still loading.</p>
            <Link href="/admin/programs" class="btn preset-tonal btn-sm mt-4 inline-flex">
                Back to Programs
            </Link>
        </div>
    {:else}
    <div class="flex flex-col gap-6">
        <!-- Back link -->
        <div>
            <Link
                href="/admin/programs"
                class="btn preset-tonal btn-sm flex items-center gap-1.5 text-surface-950 hover:bg-surface-200 transition-colors w-fit"
            >
                <ArrowRight class="w-4 h-4 rotate-180" />
                Programs
            </Link>
        </div>

        <!-- Program header -->
        <div
            class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4"
        >
            <div>
                <h1
                    class="text-2xl font-bold text-surface-950 flex items-center gap-2"
                >
                    {program.name}
                </h1>
                <div
                    class="text-sm text-surface-600 mt-2 flex items-center gap-3 flex-wrap"
                >
                    {#if program.is_active}
                        <span
                            class="text-xs uppercase tracking-wider font-bold px-2.5 py-1 rounded preset-filled-success-500 flex items-center gap-1 shadow-sm"
                        >
                            <Activity class="w-3.5 h-3.5" /> Live
                        </span>
                    {:else}
                        <span
                            class="text-xs uppercase tracking-wider font-bold px-2.5 py-1 rounded preset-tonal text-surface-600 flex items-center gap-1"
                        >
                            <Square class="w-3.5 h-3.5" /> Inactive
                        </span>
                    {/if}
                    <span
                        class="flex items-center gap-1.5 bg-surface-100 px-2.5 py-1 rounded text-surface-700"
                    >
                        <Calendar class="w-3.5 h-3.5" />
                        Created {formatDate(program.created_at)}
                    </span>
                </div>
                {#if program.description}
                    <p class="mt-3 text-surface-600 max-w-3xl leading-relaxed">
                        {program.description}
                    </p>
                {/if}
            </div>
        </div>

        <!-- Status banner + primary actions (dark mode styling via .program-status-banner in theme) -->
        {#if program.is_active && !program.is_paused}
            <div
                class="program-status-banner program-status-banner--live rounded-container border-l-4 border-l-success-500 border border-success-200 bg-success-50 shadow-sm p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-5 transition-all"
            >
                <div class="flex gap-3">
                    <div class="mt-0.5 program-status-banner__icon">
                        <CheckCircle class="w-5 h-5 text-success-600" />
                    </div>
                    <div>
                        <h3 class="program-status-banner__title font-semibold text-success-900">
                            Program is Live
                        </h3>
                        <p class="program-status-banner__desc text-sm text-success-800/80 mt-0.5">
                            Queue times are being actively recorded and tracked.
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <Link
                        href="/station"
                        class="program-status-banner__btn program-status-banner__btn--view btn preset-filled-primary-500 flex items-center gap-2 shadow-sm hover:shadow-md transition-shadow"
                        title="Open station interface to manage the queue"
                    >
                        <Activity class="w-4 h-4" />
                        View Program
                    </Link>
                    <div class="h-6 w-px bg-success-200 hidden sm:block program-status-banner__divider"></div>
                    <button
                        type="button"
                        class="program-status-banner__btn program-status-banner__btn--pause btn preset-tonal flex items-center gap-2 text-warning-700 bg-warning-100 hover:bg-warning-200 transition-colors"
                        disabled={submitting}
                        onclick={handlePause}
                    >
                        <Pause class="w-4 h-4" /> Pause
                    </button>
                    <button
                        type="button"
                        class="program-status-banner__btn program-status-banner__btn--stop btn preset-tonal flex items-center gap-2 text-error-700 bg-error-100 hover:bg-error-200 transition-colors"
                        disabled={submitting}
                        onclick={openStopConfirm}
                        aria-label="Stop session"
                    >
                        <Square class="w-4 h-4" /> Stop Session
                    </button>
                </div>
            </div>
        {:else if program.is_active && program.is_paused}
            <div
                class="program-status-banner program-status-banner--paused rounded-container border-l-4 border-l-warning-500 border border-warning-200 bg-warning-50 shadow-sm p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-5 transition-all"
            >
                <div class="flex gap-3">
                    <div class="mt-0.5">
                        <AlertCircle class="w-5 h-5 text-warning-600" />
                    </div>
                    <div>
                        <h3 class="font-semibold text-warning-900">
                            Program is Paused
                        </h3>
                        <p class="text-sm text-warning-800/80 mt-0.5">
                            Queue operations are temporarily halted.
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    class="btn preset-filled-primary-500 flex items-center gap-2 shrink-0 shadow-sm hover:shadow-md transition-shadow"
                    disabled={submitting}
                    onclick={handleResume}
                >
                    <Play class="w-4 h-4" /> Resume Operations
                </button>
            </div>
        {:else}
            <div
                class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-5 transition-all"
            >
                <div class="flex gap-3">
                    <div
                        class="mt-0.5 bg-primary-100 w-9 h-9 flex items-center justify-center rounded-full shrink-0"
                    >
                        <Rocket class="w-5 h-5 text-primary-600" />
                    </div>
                    <div>
                        <h3 class="font-semibold text-surface-900">
                            Ready to Start
                        </h3>
                        <p class="text-sm text-surface-600 mt-0.5">
                            Start a session to begin routing clients into the
                            queue.
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    class="btn preset-filled-primary-500 flex items-center gap-2 shrink-0 shadow-sm hover:shadow-md transition-shadow"
                    disabled={submitting}
                    onclick={handleActivate}
                >
                    <Play class="w-4 h-4" /> Start Session
                </button>
            </div>
        {/if}

        <!-- Tab navigation: no scrollbar; pulsating arrows when more to scroll (per sketch) -->
        <nav
            aria-label="Program sections"
            class="sticky top-0 z-20 -mx-4 px-4 py-2 bg-surface-50 border-y border-surface-200 shadow-sm sm:relative sm:mx-0 sm:px-0 sm:py-0 sm:border-0 sm:shadow-none sm:rounded-container sm:border sm:border-surface-200 sm:bg-surface-100/80 sm:mt-4"
        >
            <div class="relative flex items-center gap-0 w-full">
                {#if canScrollLeft}
                    <button
                        type="button"
                        aria-label="Scroll tabs left"
                        class="absolute left-0 z-10 flex items-center justify-center w-10 h-full min-h-[52px] bg-surface-100/95 hover:bg-surface-200/95 rounded-l-lg text-surface-600 animate-pulse shrink-0"
                        onclick={() => scrollTabList("left")}
                    >
                        <ChevronLeft class="w-5 h-5" />
                    </button>
                {/if}
                <div
                    role="tablist"
                    tabindex="-1"
                    bind:this={tabListEl}
                    class="flex gap-1 overflow-x-auto overflow-y-hidden flex-nowrap w-full max-w-full py-1 sm:py-2 sm:px-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                    onkeydown={handleTabKeydown}
                >
                    <button type="button" role="tab" id="tab-overview" tabindex={activeTab === 'overview' ? 0 : -1} aria-selected={activeTab === 'overview'} aria-controls="tabpanel-overview" class="tab flex-shrink-0 whitespace-nowrap px-4 py-2.5 touch-target-h flex items-center justify-center rounded-lg font-medium text-sm transition-colors {activeTab === 'overview' ? 'bg-primary-500 text-primary-contrast-500 shadow-sm' : 'bg-transparent text-surface-700 hover:bg-surface-200/80'}" onclick={() => (activeTab = "overview")}>Overview</button>
                    <button type="button" role="tab" id="tab-processes" tabindex={activeTab === 'processes' ? 0 : -1} aria-selected={activeTab === 'processes'} aria-controls="tabpanel-processes" class="tab flex-shrink-0 whitespace-nowrap px-4 py-2.5 touch-target-h flex items-center justify-center rounded-lg font-medium text-sm transition-colors {activeTab === 'processes' ? 'bg-primary-500 text-primary-contrast-500 shadow-sm' : 'bg-transparent text-surface-700 hover:bg-surface-200/80'}" onclick={() => (activeTab = "processes")}>Processes</button>
                    <button type="button" role="tab" id="tab-stations" tabindex={activeTab === 'stations' ? 0 : -1} aria-selected={activeTab === 'stations'} aria-controls="tabpanel-stations" class="tab flex-shrink-0 whitespace-nowrap px-4 py-2.5 touch-target-h flex items-center justify-center rounded-lg font-medium text-sm transition-colors {activeTab === 'stations' ? 'bg-primary-500 text-primary-contrast-500 shadow-sm' : 'bg-transparent text-surface-700 hover:bg-surface-200/80'}" onclick={() => (activeTab = "stations")}>Stations</button>
                    <button type="button" role="tab" id="tab-staff" tabindex={activeTab === 'staff' ? 0 : -1} aria-selected={activeTab === 'staff'} aria-controls="tabpanel-staff" class="tab flex-shrink-0 whitespace-nowrap px-4 py-2.5 touch-target-h flex items-center justify-center rounded-lg font-medium text-sm transition-colors {activeTab === 'staff' ? 'bg-primary-500 text-primary-contrast-500 shadow-sm' : 'bg-transparent text-surface-700 hover:bg-surface-200/80'}" onclick={() => (activeTab = "staff")}>Staff</button>
                    <button type="button" role="tab" id="tab-tracks" tabindex={activeTab === 'tracks' ? 0 : -1} aria-selected={activeTab === 'tracks'} aria-controls="tabpanel-tracks" class="tab flex-shrink-0 whitespace-nowrap px-4 py-2.5 touch-target-h flex items-center justify-center rounded-lg font-medium text-sm transition-colors {activeTab === 'tracks' ? 'bg-primary-500 text-primary-contrast-500 shadow-sm' : 'bg-transparent text-surface-700 hover:bg-surface-200/80'}" onclick={() => (activeTab = "tracks")}>Track</button>
                    <button type="button" role="tab" id="tab-diagram" tabindex={activeTab === 'diagram' ? 0 : -1} aria-selected={activeTab === 'diagram'} aria-controls="tabpanel-diagram" class="tab flex-shrink-0 whitespace-nowrap px-4 py-2.5 touch-target-h flex items-center justify-center rounded-lg font-medium text-sm transition-colors {activeTab === 'diagram' ? 'bg-primary-500 text-primary-contrast-500 shadow-sm' : 'bg-transparent text-surface-700 hover:bg-surface-200/80'}" onclick={() => (activeTab = "diagram")}>Diagram</button>
                    <button type="button" role="tab" id="tab-settings" tabindex={activeTab === 'settings' ? 0 : -1} aria-selected={activeTab === 'settings'} aria-controls="tabpanel-settings" class="tab flex-shrink-0 whitespace-nowrap px-4 py-2.5 touch-target-h flex items-center justify-center rounded-lg font-medium text-sm transition-colors {activeTab === 'settings' ? 'bg-primary-500 text-primary-contrast-500 shadow-sm' : 'bg-transparent text-surface-700 hover:bg-surface-200/80'}" onclick={() => (activeTab = "settings")}>Settings</button>
                </div>
                {#if canScrollRight}
                    <button
                        type="button"
                        aria-label="Scroll tabs right"
                        class="absolute right-0 z-10 flex items-center justify-center w-10 h-full min-h-[52px] bg-surface-100/95 hover:bg-surface-200/95 rounded-r-lg text-surface-600 animate-pulse shrink-0"
                        onclick={() => scrollTabList("right")}
                    >
                        <ChevronRight class="w-5 h-5" />
                    </button>
                {/if}
            </div>
        </nav>


        {#if activeTab === "overview"}
            <div id="tabpanel-overview" role="tabpanel" aria-labelledby="tab-overview" tabindex="-1" class="space-y-8">
                <section>
                    <h2 class="text-lg font-semibold text-surface-950 mb-4">
                        Program stats
                    </h2>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div
                            class="rounded-container bg-surface-50 border border-surface-200 p-5 shadow-sm flex items-center justify-between"
                        >
                            <div>
                                <p
                                    class="text-sm font-medium text-surface-600 mb-1"
                                >
                                    Total sessions
                                </p>
                                <p class="text-3xl font-bold text-surface-950">
                                    {stats.total_sessions}
                                </p>
                            </div>
                            <div class="bg-surface-100 p-3 rounded-full">
                                <FileText class="w-6 h-6 text-surface-500" />
                            </div>
                        </div>
                        <div
                            class="rounded-container bg-surface-50 border border-primary-200 p-5 shadow-sm flex items-center justify-between"
                        >
                            <div>
                                <p
                                    class="text-sm font-medium text-surface-600 mb-1"
                                >
                                    Active in queue
                                </p>
                                <p class="text-3xl font-bold text-primary-600">
                                    {stats.active_sessions}
                                </p>
                            </div>
                            <div class="bg-primary-50 p-3 rounded-full">
                                <Activity class="w-6 h-6 text-primary-500" />
                            </div>
                        </div>
                        <div
                            class="rounded-container bg-surface-50 border border-surface-200 p-5 shadow-sm flex items-center justify-between"
                        >
                            <div>
                                <p
                                    class="text-sm font-medium text-surface-600 mb-1"
                                >
                                    Completed / No-show
                                </p>
                                <p class="text-3xl font-bold text-surface-950">
                                    {stats.completed_sessions}
                                </p>
                            </div>
                            <div class="bg-surface-100 p-3 rounded-full">
                                <CheckCircle class="w-6 h-6 text-surface-500" />
                            </div>
                        </div>
                    </div>
                </section>
                <section>
                    <h2 class="text-lg font-semibold text-surface-950 mb-4">
                        Track flow
                    </h2>
                    <FlowDiagram {tracks} />
                </section>
            </div>
        {:else if activeTab === "diagram"}
            <div id="tabpanel-diagram" role="tabpanel" aria-labelledby="tab-diagram" tabindex="-1" class="space-y-4">
                <section>
                    <h2 class="text-lg font-semibold text-surface-950 mb-2">
                        Program diagram
                    </h2>
                    <p class="text-sm text-surface-600 mb-4">
                        {#if diagramViewMode}
                            View-only. Remove <code class="text-xs bg-surface-200 px-1 rounded">?mode=view</code> from the URL to edit.
                        {:else}
                            Arrange stations, tracks, and decorations on the canvas. Use Save diagram to persist your layout. Add <code class="text-xs bg-surface-200 px-1 rounded">?mode=view</code> for read-only view.
                        {/if}
                    </p>
                    <DiagramCanvas {program} {tracks} {stations} {processes} readOnly={diagramViewMode} />
                </section>
            </div>
        {:else if activeTab === "processes"}
            <div id="tabpanel-processes" role="tabpanel" aria-labelledby="tab-processes" tabindex="-1" class="space-y-6">
                <section>
                    <h2 class="text-lg font-semibold text-surface-950 mb-2">
                        Processes
                    </h2>
                    <p class="text-sm text-surface-600 mb-4">
                        Define logical work types (e.g. Verification, Cash
                        Release). Each track step references a process. Create
                        processes before adding steps to tracks.
                    </p>
                    <div class="flex flex-wrap items-end gap-3 mb-6">
                        <div class="form-control min-w-[200px]">
                            <label for="create-process-name" class="label py-0"
                                ><span class="label-text text-xs">Name</span
                                ></label
                            >
                            <input
                                id="create-process-name"
                                type="text"
                                class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                                placeholder="e.g. Verification"
                                bind:value={createProcessName}
                                maxlength="50"
                            />
                        </div>
                        <div class="form-control min-w-[200px]">
                            <label for="create-process-desc" class="label py-0"
                                ><span class="label-text text-xs"
                                    >Description (optional)</span
                                ></label
                            >
                            <input
                                id="create-process-desc"
                                type="text"
                                class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                                placeholder="Optional"
                                bind:value={createProcessDescription}
                            />
                        </div>
                        <div class="form-control min-w-[120px]">
                            <label for="create-process-expected-time" class="label py-0"
                                ><span class="label-text text-xs">Expected time (mm:ss)</span></label
                            >
                            <input
                                id="create-process-expected-time"
                                type="text"
                                class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                                placeholder="0:00"
                                bind:value={createProcessExpectedTimeMmSs}
                            />
                        </div>
                        <button
                            type="button"
                            class="btn preset-filled-primary-500 flex items-center gap-2"
                            disabled={submitting || !createProcessName.trim()}
                            onclick={handleCreateProcess}
                        >
                            {#if submitting && creatingProcess}
                                <span class="loading-spinner loading-sm"></span>
                                Adding...
                            {:else}
                                <Plus class="w-4 h-4" /> Add Process
                            {/if}
                        </button>
                    </div>
                    {#if processes.length === 0}
                        <div
                            class="rounded-container bg-surface-50 border border-surface-200 p-8 text-center text-surface-600"
                        >
                            No processes yet. Add one above to use in track
                            steps.
                        </div>
                    {:else}
                        <div class="flex flex-wrap gap-2">
                            {#each processes as proc (proc.id)}
                                <span
                                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-surface-100 border border-surface-200 text-sm font-medium text-surface-950"
                                >
                                    <span
                                        >{proc.name}
                                        {#if proc.expected_time_seconds != null && proc.expected_time_seconds > 0}
                                            <span class="text-surface-500 text-xs ml-1"
                                                >— {secondsToMmSs(proc.expected_time_seconds)}</span
                                            >
                                        {/if}
                                        {#if proc.description}
                                            <span
                                                class="text-surface-500 text-xs ml-2"
                                                >— {proc.description}</span
                                            >
                                        {/if}</span
                                    >
                                    <button
                                        type="button"
                                        class="btn btn-ghost btn-sm p-1 min-h-0 h-7"
                                        onclick={() => openEditProcessModal(proc)}
                                        disabled={submitting}
                                        aria-label="Edit process"
                                    >
                                        <Edit2 class="w-4 h-4" />
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-ghost btn-sm p-1 min-h-0 h-7 text-error-500"
                                        onclick={() =>
                                            openDeleteProcessConfirm(proc)}
                                        disabled={submitting}
                                        aria-label="Delete process"
                                    >
                                        <Trash2 class="w-4 h-4" />
                                    </button>
                                </span>
                            {/each}
                        </div>
                    {/if}
                </section>
            </div>
        {:else if activeTab === "stations"}
            <!-- Stations tab (BD-010) -->
            <div id="tabpanel-stations" role="tabpanel" aria-labelledby="tab-stations" tabindex="-1">
            <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
                <div>
                    <h2 class="text-lg font-semibold text-surface-950">
                        Stations
                    </h2>
                    <p class="text-sm text-surface-600 mt-0.5">
                        Manage service points where clients receive attention.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="btn preset-tonal flex items-center gap-2 shadow-sm"
                        onclick={() => (showProgramConnectorTtsModal = true)}
                    >
                        <Volume2 class="w-4 h-4" />
                        Connecting phrase TTS
                    </button>
                    <button
                        type="button"
                        class="btn preset-filled-primary-500 flex items-center gap-2 shadow-sm"
                        onclick={openCreateStation}
                    >
                        <Plus class="w-4 h-4" /> Add Station
                    </button>
                </div>
            </div>
            {#if serverTtsConfigured === false}
                <div class="rounded-container border border-warning-200 bg-warning-50 p-3 mb-4 text-sm text-warning-900" role="alert">
                    Add an ElevenLabs account in <Link href="/admin/settings?tab=integrations" class="font-semibold text-warning-800 underline hover:text-warning-950">Settings → Integrations</Link> before generating station TTS.
                </div>
            {/if}
            {#if localStations.length === 0}
                <div
                    role="status"
                    aria-label="No stations defined"
                    class="rounded-container bg-surface-50 border border-surface-200 p-12 flex flex-col items-center justify-center text-center shadow-sm"
                >
                    <div
                        class="bg-surface-100 p-4 rounded-full text-surface-400 mb-4"
                    >
                        <Monitor class="w-8 h-8" />
                    </div>
                    <h3 class="text-lg font-semibold text-surface-950">
                        No stations defined
                    </h3>
                    <p class="text-surface-600 max-w-sm mt-2 mb-6">
                        Create a station (e.g., Verification Desk, Cashier) for
                        staff to serve clients.
                    </p>
                    <button
                        type="button"
                        class="btn preset-filled-primary-500 flex items-center gap-2"
                        onclick={openCreateStation}
                    >
                        <Plus class="w-4 h-4" /> Create First Station
                    </button>
                </div>
            {:else}
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {#each localStations as station (station.id)}
                        <div
                            class="bg-surface-50 rounded-container elevation-card transition-all hover:shadow-[var(--shadow-raised)] flex flex-col h-full border border-surface-200/50"
                        >
                            <div class="p-5 flex-grow flex flex-col gap-3">
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <div class="flex items-center gap-2.5">
                                        <div
                                            class="bg-surface-100 p-2 rounded-lg text-surface-600"
                                        >
                                            <Monitor class="w-5 h-5" />
                                        </div>
                                        <h3
                                            class="text-lg font-bold text-surface-950 line-clamp-1"
                                        >
                                            {station.name}
                                        </h3>
                                    </div>
                                    <div class="shrink-0 mt-1">
                                        {#if station.is_active}
                                            <span
                                                class="text-[10px] uppercase tracking-wider font-bold px-2 py-1 rounded preset-filled-success-500 shadow-sm flex items-center gap-1"
                                            >
                                                <span
                                                    class="w-1.5 h-1.5 rounded-full bg-surface-950/80 shrink-0 animate-pulse"
                                                ></span> Active
                                            </span>
                                        {:else}
                                            <span
                                                class="text-[10px] uppercase tracking-wider font-bold px-2 py-1 rounded preset-tonal text-surface-600 shadow-sm"
                                            >
                                                Inactive
                                            </span>
                                        {/if}
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-2 mt-2">
                                    <div
                                        class="bg-surface-100/50 rounded p-2 border border-surface-200/50 flex flex-col"
                                    >
                                        <span
                                            class="text-xs font-medium text-surface-500 uppercase tracking-wider mb-1 flex items-center gap-1"
                                            ><Users class="w-3 h-3" /> Staff</span
                                        >
                                        <span
                                            class="text-sm font-semibold text-surface-900"
                                            >{station.capacity} desk{station.capacity !==
                                            1
                                                ? "s"
                                                : ""}</span
                                        >
                                    </div>
                                    <div
                                        class="bg-surface-100/50 rounded p-2 border border-surface-200/50 flex flex-col"
                                    >
                                        <span
                                            class="text-xs font-medium text-surface-500 uppercase tracking-wider mb-1 flex items-center gap-1"
                                            ><User class="w-3 h-3" /> Clients</span
                                        >
                                        <span
                                            class="text-sm font-semibold text-surface-900"
                                            >{station.client_capacity ?? 1} / turn</span
                                        >
                                    </div>
                                </div>
                            </div>
                            <div
                                class="px-5 py-3 border-t border-surface-100 flex items-center justify-between gap-2 flex-wrap bg-surface-50/50 rounded-b-container"
                            >
                                <div class="flex items-center gap-2 flex-wrap">
                                    <button
                                        type="button"
                                        class="text-xs font-medium transition-colors hover:text-surface-900 flex items-center gap-1.5 {station.is_active
                                            ? 'text-error-600 hover:text-error-700'
                                            : 'text-success-600 hover:text-success-700'}"
                                        onclick={() =>
                                            handleToggleStationActive(station)}
                                        disabled={submitting}
                                    >
                                        {#if station.is_active}
                                            <Power class="w-3.5 h-3.5" /> Deactivate
                                        {:else}
                                            <Power class="w-3.5 h-3.5" /> Activate
                                        {/if}
                                    </button>
                                    {#if getStationTtsStatus(station) === "ready"}
                                        <span class="text-[10px] uppercase tracking-wider font-medium px-2 py-0.5 rounded preset-filled-success-500/20 text-success-700">TTS ready</span>
                                    {:else if getStationTtsStatus(station) === "failed"}
                                        <span class="text-[10px] uppercase tracking-wider font-medium px-2 py-0.5 rounded preset-filled-error-500/20 text-error-700">TTS failed</span>
                                    {:else}
                                        <span class="text-[10px] uppercase tracking-wider font-medium px-2 py-0.5 rounded preset-tonal text-surface-500">TTS —</span>
                                    {/if}
                                    <button
                                        type="button"
                                        class="btn btn-xs preset-tonal"
                                        onclick={() => regenerateStationTts(station)}
                                        disabled={submitting || getStationTtsStatus(station) !== "failed" || stationRegeneratingId !== null}
                                        title={getStationTtsStatus(station) === "failed" ? "Regenerate TTS" : "Only available when TTS has failed"}
                                    >
                                        {stationRegeneratingId === station.id ? "Starting…" : "Generate TTS"}
                                    </button>
                                </div>
                                <div class="flex items-center gap-1">
                                    <button
                                        type="button"
                                        class="btn preset-tonal btn-sm p-2"
                                        onclick={() => openEditStationTtsModal(station)}
                                        disabled={submitting}
                                        title="Station TTS"
                                    >
                                        <Volume2
                                            class="w-4 h-4 text-surface-600"
                                        />
                                    </button>
                                    <button
                                        type="button"
                                        class="btn preset-tonal btn-sm p-2"
                                        onclick={() => openEditStation(station)}
                                        disabled={submitting}
                                        title="Edit Station"
                                    >
                                        <Edit2
                                            class="w-4 h-4 text-surface-600"
                                        />
                                    </button>
                                    <button
                                        type="button"
                                        class="btn preset-tonal btn-sm p-2 hover:bg-error-50"
                                        onclick={() =>
                                            openDeleteStationConfirm(station)}
                                        disabled={submitting}
                                        title="Delete Station"
                                    >
                                        <Trash2
                                            class="w-4 h-4 text-error-500"
                                        />
                                    </button>
                                </div>
                            </div>
                        </div>
                    {/each}
                </div>
            {/if}
            </div>
        {:else if activeTab === "staff"}
            <!-- Staff tab: station assignments + supervisors -->
            <div id="tabpanel-staff" role="tabpanel" aria-labelledby="tab-staff" tabindex="-1" class="space-y-8">
                <section>
                    <h2 class="text-lg font-semibold text-surface-950 mb-4">
                        Station assignments
                    </h2>
                    
                    {#if settingsRequireOverride && staffSupervisors.length === 0}
                        <div class="bg-warning-50 text-warning-800 border border-warning-200 rounded-container p-4 flex items-start gap-3 mb-6 shadow-sm">
                            <AlertTriangle class="w-5 h-5 text-warning-600 shrink-0 mt-0.5" />
                            <div>
                                <p class="text-sm font-medium">
                                    Override requires a supervisor PIN but no supervisors are assigned. Assign supervisors below or <button type="button" class="underline hover:text-warning-950" onclick={() => activeTab = 'settings'}>disable this in Settings</button>.
                                </p>
                            </div>
                        </div>
                    {/if}
                    <p class="text-sm text-surface-950/70 mb-4">
                        For each station, select which staff is assigned. You
                        see all stations as rows so role coverage stays clear
                        even with few staff.
                    </p>
                    {#if staffLoading}
                        <div
                            class="rounded-container bg-surface-50 border border-surface-200 p-12 flex flex-col items-center justify-center text-center shadow-sm"
                        >
                            <span
                                class="loading-spinner loading-lg text-primary-500 mb-4"
                            ></span>
                            <p
                                class="text-surface-600 font-medium animate-pulse"
                            >
                                Loading staff data...
                            </p>
                        </div>
                    {:else if staffAssignments.length === 0}
                        <div
                            role="status"
                            aria-label="No staff assigned"
                            class="rounded-container bg-surface-50 border border-surface-200 p-12 flex flex-col items-center justify-center text-center shadow-sm"
                        >
                            <div
                                class="bg-surface-100 p-4 rounded-full text-surface-400 mb-4"
                            >
                                <Users class="w-8 h-8" />
                            </div>
                            <h3 class="text-lg font-semibold text-surface-950">
                                No staff assigned
                            </h3>
                            <p class="text-surface-600 max-w-sm mt-2">
                                Add staff accounts from the main Staff
                                management page first.
                            </p>
                        </div>
                    {:else if staffStations.length === 0}
                        <div
                            class="bg-warning-100 text-warning-900 border border-warning-300 rounded-container p-4"
                        >
                            No stations in this program. Add stations first,
                            then assign staff.
                        </div>
                    {:else}
                        <div class="table-container">
                            <table class="table table-zebra">
                                <thead>
                                    <tr>
                                        <th>Station</th>
                                        <th>Assigned staff</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {#each staffStationSlots as { station, slotIndex } (`${station.id}-${slotIndex}`)}
                                        {@const assignedIds = getAssignedUserIdsForStation(station.id)}
                                        {@const assignedUserId = assignedIds[slotIndex] ?? null}
                                        <tr>
                                            <td>
                                                <span class="font-medium"
                                                    >{station.name}</span
                                                >
                                                {#if (station.capacity ?? 1) > 1}
                                                    <span class="text-surface-500 text-sm ml-1">(slot {slotIndex + 1})</span>
                                                {/if}
                                            </td>
                                            <td>
                                                <select
                                                    class="select rounded-container border border-surface-200 px-3 py-2 select-sm max-w-xs"
                                                    value={assignedUserId ?? ""}
                                                    disabled={staffAssigningStationId ===
                                                        station.id}
                                                    onchange={(e) => {
                                                        const val = (
                                                            e.target as HTMLSelectElement
                                                        ).value;
                                                        const uid =
                                                            val === ""
                                                                ? null
                                                                : Number(val);
                                                        handleAssignStaffForStationSlot(
                                                            station.id,
                                                            slotIndex,
                                                            uid,
                                                        );
                                                    }}
                                                >
                                                    <option value=""
                                                        >— Unassigned —</option
                                                    >
                                                    {#each staffAssignments as a (a.user_id)}
                                                        <option
                                                            value={a.user_id}
                                                            >{a.user.name}
                                                            {#if staffSupervisors.some((s) => s.id === a.user_id)}
                                                                (Supervisor)
                                                            {/if}</option
                                                        >
                                                    {/each}
                                                </select>
                                            </td>
                                        </tr>
                                    {/each}
                                </tbody>
                            </table>
                        </div>
                    {/if}
                </section>

                <section>
                    <h2 class="text-lg font-semibold text-surface-950 mb-4">
                        Supervisors
                    </h2>
                    <p class="text-sm text-surface-950/70 mb-4">
                        Supervisors can approve flow overrides.
                    </p>
                    {#if staffLoading}
                        <div
                            class="rounded-container bg-surface-50 border border-surface-200 p-12 flex flex-col items-center justify-center text-center shadow-sm"
                        >
                            <span
                                class="loading-spinner loading-lg text-primary-500 mb-4"
                            ></span>
                            <p
                                class="text-surface-600 font-medium animate-pulse"
                            >
                                Loading supervisor data...
                            </p>
                        </div>
                    {:else}
                        <div class="space-y-4">
                            <div
                                class="rounded-container bg-surface-50 border border-surface-200 p-5 shadow-sm"
                            >
                                <h3 class="font-medium text-surface-950 mb-2">
                                    Current supervisors
                                </h3>
                                {#if staffSupervisors.length === 0}
                                    <p class="text-sm text-surface-950/70">
                                        None. Add staff with override PINs
                                        below.
                                    </p>
                                {:else}
                                    <ul class="flex flex-wrap gap-2">
                                        {#each staffSupervisors as s (s.id)}
                                            <li
                                                class="text-xs px-2 py-0.5 rounded preset-filled-primary-500 badge-lg gap-1"
                                            >
                                                {s.name}
                                                <button
                                                    type="button"
                                                    class="btn preset-tonal btn-xs"
                                                    aria-label="Remove {s.name}"
                                                    onclick={() =>
                                                        handleRemoveSupervisor(
                                                            s.id,
                                                        )}
                                                    disabled={submitting}
                                                >
                                                    ×
                                                </button>
                                            </li>
                                        {/each}
                                    </ul>
                                {/if}
                            </div>
                            <div
                                class="rounded-container bg-surface-50 border border-surface-200 p-5 shadow-sm"
                            >
                                <h3 class="font-medium text-surface-950 mb-2">
                                    Add supervisor (staff with PIN)
                                </h3>
                                {#if staffWithPin.filter((u) => !u.is_supervisor).length === 0}
                                    <p class="text-sm text-surface-950/70">
                                        No staff with override PIN left to add.
                                    </p>
                                {:else}
                                    <ul class="flex flex-wrap gap-2">
                                        {#each staffWithPin.filter((u) => !u.is_supervisor) as u (u.id)}
                                            <li>
                                                <button
                                                    type="button"
                                                    class="btn preset-outlined btn-sm"
                                                    onclick={() =>
                                                        handleAddSupervisor(
                                                            u.id,
                                                        )}
                                                    disabled={submitting}
                                                >
                                                    + {u.name}
                                                </button>
                                            </li>
                                        {/each}
                                    </ul>
                                {/if}
                            </div>
                        </div>
                    {/if}
                </section>
            </div>
        {:else if activeTab === "tracks"}
            <div id="tabpanel-tracks" role="tabpanel" aria-labelledby="tab-tracks" tabindex="-1">
            <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
                <div>
                    <h2 class="text-lg font-semibold text-surface-950">
                        Tracks
                    </h2>
                    <p class="text-sm text-surface-600 mt-0.5">
                        Define service lanes and their step sequences.
                    </p>
                </div>
                <button
                    type="button"
                    class="btn preset-filled-primary-500 flex items-center gap-2 shadow-sm"
                    onclick={openCreate}
                >
                    <Plus class="w-4 h-4" /> Add Track
                </button>
            </div>
            {#if tracks.length === 0}
                <div
                    role="status"
                    aria-label="No tracks defined"
                    class="rounded-container bg-surface-50 border border-surface-200 p-12 flex flex-col items-center justify-center text-center shadow-sm"
                >
                    <div
                        class="bg-surface-100 p-4 rounded-full text-surface-400 mb-4"
                    >
                        <GitMerge class="w-8 h-8" />
                    </div>
                    <h3 class="text-lg font-semibold text-surface-950">
                        No tracks defined
                    </h3>
                    <p class="text-surface-600 max-w-sm mt-2 mb-6">
                        Create a track to define a service lane (e.g., Regular,
                        Priority) and its sequence of stations.
                    </p>
                    <button
                        type="button"
                        class="btn preset-filled-primary-500 flex items-center gap-2"
                        onclick={openCreate}
                    >
                        <Plus class="w-4 h-4" /> Create First Track
                    </button>
                </div>
            {:else}
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {#each tracks as track (track.id)}
                        <div
                            class="bg-surface-50 rounded-container elevation-card transition-all hover:shadow-[var(--shadow-raised)] flex flex-col h-full border border-surface-200/50"
                        >
                            <div class="p-5 flex-grow flex flex-col gap-3">
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <div class="flex items-center gap-2">
                                        {#if track.color_code}
                                            <span
                                                class="inline-block h-3.5 w-3.5 rounded-full border border-surface-200 shrink-0 shadow-sm"
                                                style="background-color: {track.color_code}"
                                                title={track.color_code}
                                            ></span>
                                        {/if}
                                        <h3
                                            class="text-lg font-bold text-surface-950 line-clamp-1"
                                        >
                                            {track.name}
                                        </h3>
                                    </div>
                                    <div class="shrink-0 mt-1">
                                        {#if track.is_default}
                                            <span
                                                class="text-[10px] uppercase tracking-wider font-bold px-2 py-1 rounded preset-filled-primary-500 shadow-sm"
                                                >Default</span
                                            >
                                        {/if}
                                    </div>
                                </div>
                                {#if track.description}
                                    <p
                                        class="text-sm text-surface-600 line-clamp-2"
                                    >
                                        {track.description}
                                    </p>
                                {:else}
                                    <p class="text-sm text-surface-400 italic">
                                        No description provided.
                                    </p>
                                {/if}
                                {#if (track.steps ?? []).length > 0}
                                    <div
                                        class="mt-2 bg-surface-100/50 rounded p-2 border border-surface-200/50"
                                    >
                                        <p
                                            class="text-xs font-medium text-surface-500 mb-1 uppercase tracking-wider"
                                        >
                                            Flow Sequence
                                        </p>
                                        <p
                                            class="text-sm text-surface-700 flex items-center flex-wrap gap-1.5"
                                        >
                                            {#each track.steps ?? [] as step, i}
                                                <span
                                                    class="inline-block px-2 py-0.5 bg-surface-50 border border-surface-200 rounded text-xs shadow-[0_1px_2px_rgba(0,0,0,0.02)]"
                                                    >{step.process_name}</span
                                                >
                                                {#if i < (track.steps ?? []).length - 1}
                                                    <ArrowRight
                                                        class="w-3 h-3 text-surface-400"
                                                    />
                                                {/if}
                                            {/each}
                                        </p>
                                    </div>
                                {/if}
                            </div>
                            <div
                                class="px-5 py-3 border-t border-surface-100 flex items-center justify-between bg-surface-50/50 rounded-b-container"
                            >
                                <button
                                    type="button"
                                    class="btn preset-tonal btn-sm flex items-center gap-1.5 bg-surface-50 border border-surface-200 shadow-sm hover:bg-surface-50"
                                    onclick={() => openStepModal(track)}
                                    disabled={submitting}
                                >
                                    <GitMerge class="w-3.5 h-3.5" />
                                    {(track.steps ?? []).length > 0 ? "Manage steps" : "Create steps"}
                                </button>
                                <div class="flex items-center gap-1">
                                    <button
                                        type="button"
                                        class="btn preset-tonal btn-sm p-2"
                                        onclick={() => openEdit(track)}
                                        disabled={submitting}
                                        title="Edit Track"
                                    >
                                        <Edit2
                                            class="w-4 h-4 text-surface-600"
                                        />
                                    </button>
                                    <button
                                        type="button"
                                        class="btn preset-tonal btn-sm p-2 hover:bg-error-50"
                                        onclick={() =>
                                            openDeleteTrackConfirm(track)}
                                        disabled={submitting}
                                        title="Delete Track"
                                    >
                                        <Trash2
                                            class="w-4 h-4 text-error-500"
                                        />
                                    </button>
                                </div>
                            </div>
                        </div>
                    {/each}
                </div>
            {/if}
            </div>
        {:else if activeTab === "settings"}
            <div id="tabpanel-settings" role="tabpanel" aria-labelledby="tab-settings" tabindex="-1" class="max-w-3xl">
                <div
                    class="flex flex-wrap items-center justify-between gap-4 mb-4"
                >
                    <div>
                        <h2 class="text-lg font-semibold text-surface-950">
                            Program Settings
                        </h2>
                        <p class="text-sm text-surface-600 mt-0.5">
                            Configure operational rules and routing behavior for
                            this program.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="btn preset-tonal btn-sm"
                        onclick={applyDefaultSettings}
                    >
                        Apply default settings
                    </button>
                </div>
                <div
                    class="rounded-container bg-surface-50 border border-surface-200 shadow-sm flex flex-col overflow-hidden"
                >
                    <div class="p-5 sm:p-6 space-y-6">
                        <!-- Setting 1 -->
                        <div
                            class="flex flex-col sm:flex-row gap-4 pb-6 border-b border-surface-200"
                        >
                            <div class="sm:w-1/3 shrink-0">
                                <h3
                                    class="font-medium text-surface-950 flex items-center gap-2"
                                >
                                    <Clock class="w-4 h-4 text-surface-500" /> No-show
                                    Timer
                                </h3>
                                <p class="text-xs text-surface-500 mt-1">
                                    Wait time before staff can mark a client as
                                    no-show.
                                </p>
                            </div>
                            <div class="sm:w-2/3 form-control">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <label class="flex items-center gap-2">
                                        <span class="text-sm text-surface-600">min</span>
                                        <input
                                            id="no-show-timer-min"
                                            type="number"
                                            class="input rounded-container border border-surface-200 px-3 py-2 w-16 text-surface-950 bg-surface-50 shadow-sm text-center"
                                            min="0"
                                            max="10"
                                            bind:value={noShowTimerMinutes}
                                        />
                                    </label>
                                    <span class="text-surface-400 font-bold">:</span>
                                    <label class="flex items-center gap-2">
                                        <span class="text-sm text-surface-600">sec</span>
                                        <input
                                            id="no-show-timer-sec"
                                            type="number"
                                            class="input rounded-container border border-surface-200 px-3 py-2 w-16 text-surface-950 bg-surface-50 shadow-sm text-center"
                                            min="0"
                                            max="59"
                                            bind:value={noShowTimerSeconds}
                                        />
                                    </label>
                                    <span class="text-sm text-surface-600"
                                        >(total 5s–10m)</span
                                    >
                                </div>
                            </div>
                        </div>

                        <!-- Display scan timeout: scanner modal auto-close and status page auto-dismiss (flexiqueue-87p) -->
                        <div
                            class="flex flex-col sm:flex-row gap-4 pb-6 border-b border-surface-200"
                        >
                            <div class="sm:w-1/3 shrink-0">
                                <h3
                                    class="font-medium text-surface-950 flex items-center gap-2"
                                >
                                    <Monitor class="w-4 h-4 text-surface-500" /> Display scan timeout
                                </h3>
                                <p class="text-xs text-surface-500 mt-1">
                                    Seconds before the camera scanner modal and status page auto-close. 0 = no auto-close.
                                </p>
                            </div>
                            <div class="sm:w-2/3 form-control">
                                <label class="flex items-center gap-2 flex-wrap">
                                    <input
                                        id="informant-desk-activity-buffer"
                                        type="number"
                                        class="input rounded-container border border-surface-200 px-3 py-2 w-20 text-surface-950 bg-surface-50 shadow-sm text-center"
                                        min="0"
                                        max="300"
                                        bind:value={displayScanTimeoutSeconds}
                                    />
                                    <span class="text-sm text-surface-600">seconds (0–300, 0 = no auto-close)</span>
                                </label>
                            </div>
                        </div>

                        <!-- Display board audio: TTS mute and volume (per plan) -->
                        <div
                            class="flex flex-col sm:flex-row gap-4 pb-6 border-b border-surface-200"
                        >
                            <div class="sm:w-1/3 shrink-0">
                                <h3
                                    class="font-medium text-surface-950 flex items-center gap-2"
                                >
                                    <Volume2 class="w-4 h-4 text-surface-500" /> Display board audio
                                </h3>
                                <p class="text-xs text-surface-500 mt-1">
                                    Mute and volume for the general display board TTS announcements (e.g. "Calling A3, please proceed to...").
                                </p>
                            </div>
                            <div class="sm:w-2/3 form-control space-y-3">
                                <label
                                    class="label cursor-pointer justify-start gap-3 w-fit hover:bg-surface-100 p-2 -ml-2 rounded-lg transition-colors"
                                >
                                    <input
                                        type="checkbox"
                                        class="checkbox"
                                        bind:checked={displayAudioMuted}
                                    />
                                    <span class="label-text text-surface-950 font-medium">Mute display board TTS</span>
                                </label>
                                <label class="flex flex-col gap-1">
                                    <span class="text-sm text-surface-600">Volume (0–100%)</span>
                                    <input
                                        type="range"
                                        min="0"
                                        max="1"
                                        step="0.1"
                                        class="range range-sm w-48 max-w-full"
                                        bind:value={displayAudioVolume}
                                        disabled={displayAudioMuted}
                                        aria-label="Display board TTS volume"
                                    />
                                    <span class="text-xs text-surface-500">{Math.round(displayAudioVolume * 100)}%</span>
                                </label>
                                <div class="flex flex-col gap-1">
                                    <label for="display-tts-repeat" class="text-sm text-surface-600">TTS announcement repeat</label>
                                    <select
                                        id="display-tts-repeat"
                                        class="select select-bordered w-fit"
                                        bind:value={settingsDisplayTtsRepeatCount}
                                        aria-label="How many times to repeat each call announcement"
                                    >
                                        <option value={1}>Once</option>
                                        <option value={2}>Twice</option>
                                        <option value={3}>Three times</option>
                                    </select>
                                </div>
                                <div class="flex flex-col gap-1">
                                    <label for="display-tts-repeat-delay" class="text-sm text-surface-600">Delay between repeats (seconds)</label>
                                    <input
                                        id="display-tts-repeat-delay"
                                        type="number"
                                        min="0.5"
                                        max="10"
                                        step="0.5"
                                        class="input input-bordered w-24"
                                        bind:value={settingsDisplayTtsRepeatDelaySec}
                                        aria-label="Delay between repeated announcements in seconds"
                                    />
                                </div>
                                <!-- TTS source/voice are now global; program controls only mute/volume/repeat. -->
                            </div>
                        </div>

                        <!-- Program TTS connector phrases (configured via modal on Stations tab) -->
                        <div
                            class="flex flex-col sm:flex-row gap-4 pb-6 border-b border-surface-200"
                        >
                            <div class="sm:w-1/3 shrink-0">
                                <h3
                                    class="font-medium text-surface-950 flex items-center gap-2"
                                >
                                    <Volume2 class="w-4 h-4 text-surface-500" /> TTS connector phrases
                                </h3>
                                <p class="text-xs text-surface-500 mt-1">
                                    Phrases between token and station (e.g. "Please proceed to"). One set per language.
                                </p>
                            </div>
                            <div class="sm:w-2/3 form-control space-y-3">
                                <p class="text-xs text-surface-600">
                                    Configure connector phrases and voices from the Stations tab. This applies to all stations in the program.
                                </p>
                                <button
                                    type="button"
                                    class="btn preset-tonal w-full sm:w-auto flex items-center gap-2"
                                    onclick={() => {
                                        activeTab = "stations";
                                        showProgramConnectorTtsModal = true;
                                    }}
                                >
                                    <Volume2 class="w-4 h-4" />
                                    Open connector TTS settings
                                </button>
                            </div>
                        </div>

                        <!-- Allow public self-serve triage (per plan) -->
                        <div
                            class="flex flex-col sm:flex-row gap-4 pb-6 border-b border-surface-200"
                        >
                            <div class="sm:w-1/3 shrink-0">
                                <h3 class="font-medium text-surface-950 flex items-center gap-2">
                                    Public self-serve triage
                                </h3>
                                <p class="text-xs text-surface-500 mt-1">
                                    When enabled, clients can open /triage/start (no login) to scan their token and choose a track to start their visit.
                                </p>
                            </div>
                            <div class="sm:w-2/3 form-control pt-1">
                                <label
                                    for="allow-public-triage-switch"
                                    class="label cursor-pointer justify-start gap-3 w-fit hover:bg-surface-100 p-2 -ml-2 rounded-lg transition-colors items-center"
                                >
                                    <div class="relative inline-block w-11 h-5">
                                        <input
                                            id="allow-public-triage-switch"
                                            type="checkbox"
                                            class="peer appearance-none w-11 h-5 bg-surface-200 rounded-full checked:bg-surface-800 cursor-pointer transition-colors duration-300"
                                            bind:checked={allowPublicTriage}
                                        />
                                        <span
                                            class="absolute top-0 left-0 w-5 h-5 bg-surface-950 rounded-full border border-surface-300 shadow-sm transition-transform duration-300 peer-checked:translate-x-6 peer-checked:border-surface-800 pointer-events-none"
                                            aria-hidden="true"
                                        ></span>
                                    </div>
                                    <span class="label-text text-surface-950 font-medium">Allow public self-serve triage</span>
                                </label>
                            </div>
                        </div>

                        <!-- HID barcode: Display board -->
                        <div
                            class="flex flex-col sm:flex-row gap-4 pb-6 border-b border-surface-200"
                        >
                            <div class="sm:w-1/3 shrink-0">
                                <h3 class="font-medium text-surface-950 flex items-center gap-2">
                                    <Monitor class="w-4 h-4 text-surface-500" /> HID barcode (Display)
                                </h3>
                                <p class="text-xs text-surface-500 mt-1">
                                    When on, the Display board keeps focus on the hidden barcode input for hardware scanners.
                                </p>
                            </div>
                            <div class="sm:w-2/3 form-control pt-1">
                                <label class="label cursor-pointer justify-start gap-3 w-fit hover:bg-surface-100 p-2 -ml-2 rounded-lg transition-colors">
                                    <input type="checkbox" class="checkbox" bind:checked={enableDisplayHidBarcode} />
                                    <span class="label-text text-surface-950 font-medium">Enable HID barcode on Display board</span>
                                </label>
                            </div>
                        </div>

                        <!-- HID barcode: Public triage -->
                        <div
                            class="flex flex-col sm:flex-row gap-4 pb-6 border-b border-surface-200"
                        >
                            <div class="sm:w-1/3 shrink-0">
                                <h3 class="font-medium text-surface-950 flex items-center gap-2">
                                    <Users class="w-4 h-4 text-surface-500" /> HID barcode (Public triage)
                                </h3>
                                <p class="text-xs text-surface-500 mt-1">
                                    When on, the public triage page (/triage/start) keeps focus on the hidden barcode input for hardware scanners.
                                </p>
                            </div>
                            <div class="sm:w-2/3 form-control pt-1">
                                <label class="label cursor-pointer justify-start gap-3 w-fit hover:bg-surface-100 p-2 -ml-2 rounded-lg transition-colors">
                                    <input type="checkbox" class="checkbox" bind:checked={enablePublicTriageHidBarcode} />
                                    <span class="label-text text-surface-950 font-medium">Enable HID barcode on Public triage</span>
                                </label>
                            </div>
                        </div>

                        <!-- Setting 2 -->
                        <div
                            class="flex flex-col sm:flex-row gap-4 pb-6 border-b border-surface-200"
                        >
                            <div class="sm:w-1/3 shrink-0">
                                <h3
                                    class="font-medium text-surface-950 flex items-center gap-2"
                                >
                                    <Users class="w-4 h-4 text-surface-500" /> Strict priority first
                                </h3>
                                <p class="text-xs text-surface-500 mt-1">
                                    Call PWD, Senior, and Pregnant clients
                                    before Regular ones.
                                </p>
                            </div>
                            <div class="sm:w-2/3 form-control pt-1">
                                <label
                                    class="label cursor-pointer justify-start gap-3 w-fit hover:bg-surface-100 p-2 -ml-2 rounded-lg transition-colors"
                                >
                                    <input
                                        type="checkbox"
                                        class="checkbox"
                                        bind:checked={settingsPriorityFirst}
                                    />
                                    <span
                                        class="label-text text-surface-950 font-medium"
                                        >Enable strict priority first routing</span
                                    >
                                </label>
                            </div>
                        </div>

                        <!-- Setting 3 -->
                        <div
                            class="flex flex-col sm:flex-row gap-4 pb-6 border-b border-surface-200"
                            class:opacity-60={settingsPriorityFirst}
                        >
                            <div class="sm:w-1/3 shrink-0">
                                <h3
                                    class="font-medium text-surface-950 flex items-center gap-2"
                                >
                                    <GitMerge
                                        class="w-4 h-4 text-surface-500"
                                    /> Balance Mode
                                </h3>
                                <p class="text-xs text-surface-500 mt-1">
                                    How to balance queue when strict priority first
                                    is disabled.
                                </p>
                            </div>
                            <div class="sm:w-2/3 form-control space-y-3">
                                <select
                                    id="balance-mode"
                                    class="select rounded-container border border-surface-200 px-3 py-2 w-full text-surface-950 bg-surface-50 shadow-sm"
                                    bind:value={settingsBalanceMode}
                                    disabled={settingsPriorityFirst}
                                >
                                    <option value="fifo"
                                        >FIFO — Strict arrival order</option
                                    >
                                    <option value="alternate"
                                        >Alternate — Ratio of priority to
                                        regular</option
                                    >
                                </select>

                                {#if settingsBalanceMode === "alternate" && !settingsPriorityFirst}
                                    <div
                                        class="flex flex-col gap-2 bg-surface-100 p-3 rounded-container border border-surface-200 text-sm"
                                    >
                                        <span
                                            class="font-medium text-surface-700"
                                            >Ratio Priority:Regular</span
                                        >
                                        <div
                                            class="flex flex-row items-center gap-2 flex-wrap"
                                        >
                                            <label class="flex items-center gap-1.5">
                                                <span class="text-xs text-surface-600">Priority</span>
                                                <input
                                                    type="number"
                                                    class="input rounded border border-surface-300 px-2 py-1.5 w-16 text-center text-surface-950 bg-surface-50"
                                                    min="1"
                                                    max="10"
                                                    bind:value={
                                                        settingsAlternateRatioP
                                                    }
                                                />
                                            </label>
                                            <span
                                                class="font-bold text-surface-400"
                                                >:</span
                                            >
                                            <label class="flex items-center gap-1.5">
                                                <span class="text-xs text-surface-600">Regular</span>
                                                <input
                                                    type="number"
                                                    class="input rounded border border-surface-300 px-2 py-1.5 w-16 text-center text-surface-950 bg-surface-50"
                                                    min="1"
                                                    max="10"
                                                    bind:value={
                                                        settingsAlternateRatioR
                                                    }
                                                />
                                            </label>
                                        </div>
                                        <!-- Per bead flexiqueue-5gl: toggleable explanation for ratio (alternate mode). -->
                                        <button
                                            type="button"
                                            class="btn preset-tonal btn-sm mt-1 text-surface-600 hover:text-surface-950 w-fit"
                                            onclick={() => (showRatioDetails = !showRatioDetails)}
                                        >
                                            {showRatioDetails ? "Hide details" : "More details"}
                                        </button>
                                        {#if showRatioDetails}
                                            <p class="text-xs text-surface-600 mt-1 bg-surface-50 p-2 rounded border border-surface-200">
                                                Alternate mode serves clients from priority and regular queues in the ratio above. Use strict priority first when the priority queue should always be served before regular. Here you can choose which queue is served first in each ratio cycle.
                                            </p>
                                        {/if}
                                        <!-- Per bead flexiqueue-5gl: choose which queue goes first in alternate mode. Default: priority first. -->
                                        <div class="flex flex-col gap-1.5 mt-2">
                                            <span class="text-xs font-medium text-surface-700">Which goes first in each cycle</span>
                                            <div class="flex flex-wrap gap-2" role="group" aria-label="Which queue first">
                                                <button
                                                    type="button"
                                                    class="btn btn-sm {settingsAlternatePriorityFirst ? 'preset-filled' : 'preset-tonal'}"
                                                    onclick={() => (settingsAlternatePriorityFirst = true)}
                                                >
                                                    Priority first
                                                </button>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm {settingsAlternatePriorityFirst ? 'preset-tonal' : 'preset-filled'}"
                                                    onclick={() => (settingsAlternatePriorityFirst = false)}
                                                >
                                                    Regular first
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                {/if}
                            </div>
                        </div>

                        <!-- Setting 4: Station Selection Mode -->
                        <div
                            class="flex flex-col sm:flex-row gap-4 pb-6 border-b border-surface-200"
                        >
                            <div class="sm:w-1/3 shrink-0">
                                <h3
                                    class="font-medium text-surface-950 flex items-center gap-2"
                                >
                                    <GitMerge
                                        class="w-4 h-4 text-surface-500"
                                    /> Station Selection
                                </h3>
                                <p class="text-xs text-surface-500 mt-1">
                                    When multiple stations serve the same
                                    process, how to pick the station.
                                </p>
                            </div>
                            <div class="sm:w-2/3 form-control">
                                <select
                                    id="station-selection-mode"
                                    class="select rounded-container border border-surface-200 px-3 py-2 w-full text-surface-950 bg-surface-50 shadow-sm"
                                    bind:value={settingsStationSelectionMode}
                                >
                                    <option value="fixed"
                                        >Fixed — First configured station</option
                                    >
                                    <option value="shortest_queue"
                                        >Shortest Queue — Fewest waiting</option
                                    >
                                    <option value="least_busy"
                                        >Least Busy — Lowest active load</option
                                    >
                                    <option value="round_robin"
                                        >Round Robin — Rotate fairly</option
                                    >
                                    <option value="least_recently_served"
                                        >Least Recently Served</option
                                    >
                                </select>
                                <button
                                    type="button"
                                    class="btn preset-tonal btn-sm mt-2 text-surface-600 hover:text-surface-950"
                                    onclick={() => (showStationSelectionDetails = !showStationSelectionDetails)}
                                >
                                    {showStationSelectionDetails ? "Hide details" : "More details"}
                                </button>
                                {#if showStationSelectionDetails}
                                    <ul class="mt-2 space-y-1 text-xs text-surface-600 list-disc list-inside bg-surface-50 p-3 rounded-container border border-surface-200">
                                        <li><strong>Fixed:</strong> Always use the first station configured for that process.</li>
                                        <li><strong>Shortest Queue:</strong> Choose the station with the fewest clients waiting.</li>
                                        <li><strong>Least Busy:</strong> Choose the station with the lowest current load (active servings).</li>
                                        <li><strong>Round Robin:</strong> Rotate among stations fairly over time.</li>
                                        <li><strong>Least Recently Served:</strong> Prefer the station that has not served a client for the longest time.</li>
                                    </ul>
                                {/if}
                            </div>
                        </div>

                        <!-- Setting 5 -->
                        <div class="flex flex-col sm:flex-row gap-4">
                            <div class="sm:w-1/3 shrink-0">
                                <h3
                                    class="font-medium text-surface-950 flex items-center gap-2"
                                >
                                    <AlertCircle
                                        class="w-4 h-4 text-surface-500"
                                    /> Require Override PIN
                                </h3>
                                <p class="text-xs text-surface-500 mt-1">
                                    Require supervisor PIN to redirect clients
                                    to a different flow.
                                </p>
                            </div>
                            <div class="sm:w-2/3 form-control pt-1">
                                <label
                                    class="label cursor-pointer justify-start gap-3 w-fit hover:bg-surface-100 p-2 -ml-2 rounded-lg transition-colors"
                                >
                                    <input
                                        type="checkbox"
                                        class="checkbox"
                                        bind:checked={settingsRequireOverride}
                                    />
                                    <span
                                        class="label-text text-surface-950 font-medium"
                                        >Require supervisor PIN</span
                                    >
                                </label>
                            </div>
                        </div>

                        <!-- Per ISSUES-ELABORATION §5: info on multiple processes per station -->
                        <div class="rounded-container bg-surface-100/80 border border-surface-200 p-4 mt-4">
                            <p class="text-sm font-medium text-surface-800">Multiple processes per station</p>
                            <p class="text-xs text-surface-600 mt-1">
                                You can assign several processes to the same physical station: add the same station to multiple track steps (each step can use a different process), and in Stations ensure that station has multiple processes selected. Station selection mode above then applies when choosing which station serves each process.
                            </p>
                        </div>
                    </div>

                    <div
                        class="bg-surface-100/50 px-5 py-4 border-t border-surface-200 flex justify-end"
                    >
                        <button
                            type="button"
                            class="btn preset-filled-primary-500 flex items-center gap-2 shadow-sm"
                            disabled={submitting}
                            onclick={handleSaveSettings}
                        >
                            {#if submitting}
                                <span class="loading-spinner loading-sm"></span>
                                Saving...
                            {:else}
                                Save settings
                            {/if}
                        </button>
                    </div>
                </div>
            </div>
        {/if}
    </div>
    {/if}
</AdminLayout>

<Modal open={showCreateModal} title="Add Track" onClose={closeModals}>
    {#snippet children()}
        <form
            onsubmit={(e) => {
                e.preventDefault();
                handleCreate();
            }}
            class="flex flex-col gap-4"
        >
            <div class="form-control w-full">
                <label for="create-track-name" class="label"
                    ><span class="label-text">Name</span></label
                >
                <input
                    id="create-track-name"
                    type="text"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                    placeholder="e.g. Priority Lane"
                    maxlength="50"
                    bind:value={createName}
                    required
                />
            </div>
            <div class="form-control w-full">
                <label for="create-track-desc" class="label"
                    ><span class="label-text">Description (optional)</span
                    ></label
                >
                <textarea
                    id="create-track-desc"
                    class="textarea rounded-container border border-surface-200 w-full"
                    rows="2"
                    placeholder="Brief description"
                    bind:value={createDescription}
                ></textarea>
            </div>
            <div class="form-control w-full">
                <label for="create-track-color" class="label"
                    ><span class="label-text">Color (optional, hex)</span
                    ></label
                >
                <input
                    id="create-track-color"
                    type="text"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                    placeholder="#F59E0B"
                    maxlength="7"
                    bind:value={createColorCode}
                />
            </div>
            <div class="form-control">
                <label class="label cursor-pointer justify-start gap-2">
                    <input
                        type="checkbox"
                        class="checkbox checkbox-sm"
                        bind:checked={createIsDefault}
                    />
                    <span class="label-text"
                        >Default track (exactly one per program)</span
                    >
                </label>
            </div>
            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    class="btn preset-tonal"
                    onclick={closeModals}>Cancel</button
                >
                <button
                    type="submit"
                    class="btn preset-filled-primary-500"
                    disabled={submitting || !createName.trim()}
                >
                    {submitting ? "Creating…" : "Create"}
                </button>
            </div>
        </form>
    {/snippet}
</Modal>

{#if editTrack}
    <Modal open={!!editTrack} title="Edit Track" onClose={closeModals}>
        {#snippet children()}
            <form
                onsubmit={(e) => {
                    e.preventDefault();
                    handleUpdate();
                }}
                class="flex flex-col gap-4"
            >
                <div class="form-control w-full">
                    <label for="edit-track-name" class="label"
                        ><span class="label-text">Name</span></label
                    >
                    <input
                        id="edit-track-name"
                        type="text"
                        class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                        maxlength="50"
                        bind:value={editName}
                        required
                    />
                </div>
                <div class="form-control w-full">
                    <label for="edit-track-desc" class="label"
                        ><span class="label-text">Description (optional)</span
                        ></label
                    >
                    <textarea
                        id="edit-track-desc"
                        class="textarea rounded-container border border-surface-200 w-full"
                        rows="2"
                        bind:value={editDescription}
                    ></textarea>
                </div>
                <div class="form-control w-full">
                    <label for="edit-track-color" class="label"
                        ><span class="label-text">Color (optional, hex)</span
                        ></label
                    >
                    <input
                        id="edit-track-color"
                        type="text"
                        class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                        placeholder="#F59E0B"
                        maxlength="7"
                        bind:value={editColorCode}
                    />
                </div>
                <div class="form-control">
                    <label class="label cursor-pointer justify-start gap-2">
                        <input
                            type="checkbox"
                            class="checkbox checkbox-sm"
                            bind:checked={editIsDefault}
                        />
                        <span class="label-text">Default track</span>
                    </label>
                </div>
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="btn preset-tonal"
                        onclick={closeModals}>Cancel</button
                    >
                    <button
                        type="submit"
                        class="btn preset-filled-primary-500"
                        disabled={submitting || !editName.trim()}
                    >
                        {submitting ? "Saving…" : "Save"}
                    </button>
                </div>
            </form>
        {/snippet}
    </Modal>
{/if}

<Modal open={showCreateStationModal} title="Add Station" onClose={closeModals}>
    {#snippet children()}
        <form
            onsubmit={(e) => {
                e.preventDefault();
                handleCreateStation();
            }}
            class="flex flex-col gap-4"
        >
            <div class="form-control w-full">
                <label for="create-station-name" class="label"
                    ><span class="label-text">Name</span></label
                >
                <input
                    id="create-station-name"
                    type="text"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                    placeholder="e.g. Verification Desk"
                    maxlength="50"
                    bind:value={createStationName}
                    required
                />
            </div>
            <div class="form-control w-full">
                <label for="create-station-capacity" class="label"
                    ><span class="label-text">Staff capacity</span></label
                >
                <input
                    id="create-station-capacity"
                    type="number"
                    min="1"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                    bind:value={createStationCapacity}
                    required
                />
            </div>
            <div class="form-control w-full">
                <label for="create-station-client-capacity" class="label"
                    ><span class="label-text">Client capacity</span></label
                >
                <input
                    id="create-station-client-capacity"
                    type="number"
                    min="1"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                    placeholder="Chairs/seats at station"
                    bind:value={createStationClientCapacity}
                    required
                />
            </div>
            <div class="form-control w-full">
                <label class="label"
                    ><span class="label-text">Processes</span></label
                >
                <p class="text-xs text-surface-500 mb-2">
                    Select which work types this station handles. At least one
                    required.
                </p>
                {#if processes.length === 0}
                    <p class="text-sm text-warning-600">
                        Create processes in the Processes tab first.
                    </p>
                {:else}
                    <div class="flex flex-wrap gap-3">
                        {#each processes as proc (proc.id)}
                            <label
                                class="label cursor-pointer justify-start gap-2 w-fit p-2 rounded-lg border border-surface-200 hover:bg-surface-50"
                            >
                                <input
                                    type="checkbox"
                                    class="checkbox checkbox-sm"
                                    checked={createStationProcessIds.includes(
                                        proc.id,
                                    )}
                                    onchange={() =>
                                        toggleCreateProcess(proc.id)}
                                />
                                <span class="label-text">{proc.name}</span>
                            </label>
                        {/each}
                    </div>
                {/if}
            </div>
            <div class="form-control w-full">
                <span class="label-text font-medium mb-1 block">Station TTS (per language)</span>
                <p class="text-sm text-surface-600 mb-2">
                    Voice, speed, and pronunciation for this station in each language. Connector phrase is set via &quot;Connecting phrase TTS&quot; on the Stations tab.
                </p>
                <div class="space-y-3">
                    <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">English</span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div class="form-control">
                                <label class="label" for="create-station-en-voice"><span class="label-text text-xs font-medium">Voice</span></label>
                                <select id="create-station-en-voice" class="select select-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" bind:value={createStationTts.en.voice_id}>
                                    <option value="">Use default</option>
                                    {#each serverTtsVoices as voice}
                                        <option value={voice.id}>{voice.name}{voice.lang ? ` (${voice.lang})` : ""}</option>
                                    {/each}
                                </select>
                            </div>
                            <div class="form-control">
                                <label class="label" for="create-station-en-rate"><span class="label-text text-xs font-medium">Speed</span></label>
                                <div class="flex items-center gap-2">
                                    <input id="create-station-en-rate" type="range" min="0.5" max="2" step="0.05" class="range range-xs flex-1" bind:value={createStationTts.en.rate} />
                                    <span class="text-xs text-surface-600 w-12">{Number(createStationTts.en.rate).toFixed(2)}x</span>
                                </div>
                            </div>
                        </div>
                        <div class="form-control mt-2">
                            <label class="label" for="station-en-phrase"><span class="label-text text-xs font-medium">Station phrase (optional)</span></label>
                            <input id="station-en-phrase" type="text" class="input input-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" placeholder='e.g. "Window one"' bind:value={createStationTts.en.station_phrase} />
                        </div>
                    </div>
                    <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">Filipino</span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div class="form-control">
                                <label class="label" for="create-station-fil-voice"><span class="label-text text-xs font-medium">Voice</span></label>
                                <select id="create-station-fil-voice" class="select select-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" bind:value={createStationTts.fil.voice_id}>
                                    <option value="">Use default</option>
                                    {#each serverTtsVoices as voice}
                                        <option value={voice.id}>{voice.name}{voice.lang ? ` (${voice.lang})` : ""}</option>
                                    {/each}
                                </select>
                            </div>
                            <div class="form-control">
                                <label class="label" for="create-station-fil-rate"><span class="label-text text-xs font-medium">Speed</span></label>
                                <div class="flex items-center gap-2">
                                    <input id="create-station-fil-rate" type="range" min="0.5" max="2" step="0.05" class="range range-xs flex-1" bind:value={createStationTts.fil.rate} />
                                    <span class="text-xs text-surface-600 w-12">{Number(createStationTts.fil.rate).toFixed(2)}x</span>
                                </div>
                            </div>
                        </div>
                        <div class="form-control mt-2">
                            <label class="label" for="station-fil-phrase"><span class="label-text text-xs font-medium">Station phrase (optional)</span></label>
                            <input id="station-fil-phrase" type="text" class="input input-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" placeholder='e.g. "Estasyon ng window one"' bind:value={createStationTts.fil.station_phrase} />
                        </div>
                    </div>
                    <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">Ilocano</span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div class="form-control">
                                <label class="label" for="create-station-ilo-voice"><span class="label-text text-xs font-medium">Voice</span></label>
                                <select id="create-station-ilo-voice" class="select select-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" bind:value={createStationTts.ilo.voice_id}>
                                    <option value="">Use default</option>
                                    {#each serverTtsVoices as voice}
                                        <option value={voice.id}>{voice.name}{voice.lang ? ` (${voice.lang})` : ""}</option>
                                    {/each}
                                </select>
                            </div>
                            <div class="form-control">
                                <label class="label" for="create-station-ilo-rate"><span class="label-text text-xs font-medium">Speed</span></label>
                                <div class="flex items-center gap-2">
                                    <input id="create-station-ilo-rate" type="range" min="0.5" max="2" step="0.05" class="range range-xs flex-1" bind:value={createStationTts.ilo.rate} />
                                    <span class="text-xs text-surface-600 w-12">{Number(createStationTts.ilo.rate).toFixed(2)}x</span>
                                </div>
                            </div>
                        </div>
                        <div class="form-control mt-2">
                            <label class="label" for="station-ilo-phrase"><span class="label-text text-xs font-medium">Station phrase (optional)</span></label>
                            <input id="station-ilo-phrase" type="text" class="input input-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" placeholder='e.g. "Estasyon ti window one"' bind:value={createStationTts.ilo.station_phrase} />
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    class="btn preset-tonal"
                    onclick={closeModals}>Cancel</button
                >
                <button
                    type="submit"
                    class="btn preset-filled-primary-500"
                    disabled={submitting ||
                        !createStationName.trim() ||
                        createStationProcessIds.length === 0 ||
                        processes.length === 0}
                >
                    {submitting ? "Creating…" : "Create"}
                </button>
            </div>
        </form>
    {/snippet}
</Modal>

{#if editProcess}
    <Modal open={!!editProcess} title="Edit process" onClose={closeEditProcessModal}>
        {#snippet children()}
            <form
                onsubmit={(e) => {
                    e.preventDefault();
                    handleUpdateProcess();
                }}
                class="flex flex-col gap-4"
            >
                <div class="form-control w-full">
                    <label for="edit-process-name" class="label"
                        ><span class="label-text">Name</span></label
                    >
                    <input
                        id="edit-process-name"
                        type="text"
                        class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                        placeholder="e.g. Verification"
                        maxlength="50"
                        bind:value={editProcessName}
                        required
                    />
                </div>
                <div class="form-control w-full">
                    <label for="edit-process-desc" class="label"
                        ><span class="label-text">Description (optional)</span></label
                    >
                    <input
                        id="edit-process-desc"
                        type="text"
                        class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                        placeholder="Optional"
                        bind:value={editProcessDescription}
                    />
                </div>
                <div class="form-control w-full">
                    <label for="edit-process-expected-time" class="label"
                        ><span class="label-text">Expected time (mm:ss)</span></label
                    >
                    <input
                        id="edit-process-expected-time"
                        type="text"
                        class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                        placeholder="e.g. 5:30 (max 10:00)"
                        bind:value={editProcessExpectedTimeMmSs}
                    />
                </div>
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="btn preset-tonal"
                        onclick={closeEditProcessModal}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="btn preset-filled-primary-500"
                        disabled={submitting || !editProcessName.trim()}
                    >
                        {submitting ? "Saving…" : "Save"}
                    </button>
                </div>
            </form>
        {/snippet}
    </Modal>
{/if}

{#if editStation}
    <Modal open={!!editStation} title="Edit Station" onClose={closeModals}>
        {#snippet children()}
            <form
                onsubmit={(e) => {
                    e.preventDefault();
                    handleUpdateStation();
                }}
                class="flex flex-col gap-4"
            >
                <div class="form-control w-full">
                    <label for="edit-station-name" class="label"
                        ><span class="label-text">Name</span></label
                    >
                    <input
                        id="edit-station-name"
                        type="text"
                        class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                        maxlength="50"
                        bind:value={editStationName}
                        required
                    />
                </div>
                <div class="form-control w-full">
                    <label for="edit-station-capacity" class="label"
                        ><span class="label-text">Staff capacity</span></label
                    >
                    <input
                        id="edit-station-capacity"
                        type="number"
                        min="1"
                        class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                        bind:value={editStationCapacity}
                        required
                    />
                </div>
                <div class="form-control w-full">
                    <label for="edit-station-client-capacity" class="label"
                        ><span class="label-text">Client capacity</span></label
                    >
                    <input
                        id="edit-station-client-capacity"
                        type="number"
                        min="1"
                        class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                        bind:value={editStationClientCapacity}
                        required
                    />
                </div>
                <div class="form-control w-full">
                    <label class="label"
                        ><span class="label-text">Processes</span></label
                    >
                    <p class="text-xs text-surface-500 mb-2">
                        Select which work types this station handles. At least
                        one required.
                    </p>
                    {#if processes.length === 0}
                        <p class="text-sm text-warning-600">
                            Create processes in the Processes tab first.
                        </p>
                    {:else}
                        <div class="flex flex-wrap gap-3">
                            {#each processes as proc (proc.id)}
                                <label
                                    class="label cursor-pointer justify-start gap-2 w-fit p-2 rounded-lg border border-surface-200 hover:bg-surface-50"
                                >
                                    <input
                                        type="checkbox"
                                        class="checkbox checkbox-sm"
                                        checked={editStationProcessIds.includes(
                                            proc.id,
                                        )}
                                        onchange={() =>
                                            toggleEditProcess(proc.id)}
                                    />
                                    <span class="label-text">{proc.name}</span>
                                </label>
                            {/each}
                        </div>
                    {/if}
                </div>
                <div class="form-control">
                    <label for="edit-priority-override" class="label"
                        ><span class="label-text">Strict priority first override</span
                        ></label
                    >
                    <select
                        id="edit-priority-override"
                        class="select rounded-container border border-surface-200 px-3 py-2 w-full"
                        value={editStationPriorityFirstOverride === null
                            ? "default"
                            : editStationPriorityFirstOverride
                              ? "true"
                              : "false"}
                        onchange={(e) => {
                            const v = (e.target as HTMLSelectElement).value;
                            editStationPriorityFirstOverride =
                                v === "default" ? null : v === "true";
                        }}
                    >
                        <option value="default">Use program default</option>
                        <option value="true">Yes (strict priority first)</option>
                        <option value="false">No (FIFO/alternate)</option>
                    </select>
                    <span class="label-text-alt"
                        >Override program's priority-first setting for this
                        station</span
                    >
                </div>
                <div class="form-control">
                    <label class="label cursor-pointer justify-start gap-2">
                        <input
                            type="checkbox"
                            class="checkbox checkbox-sm"
                            bind:checked={editStationIsActive}
                        />
                        <span class="label-text">Active</span>
                    </label>
                </div>
                <div class="form-control w-full">
                    <span class="label-text font-medium mb-1 block">Station TTS (per language)</span>
                    <p class="text-sm text-surface-600 mb-2">
                        Voice, speed, and pronunciation. For full TTS editing use the <strong>Station TTS</strong> button on the station card.
                    </p>
                    <div class="space-y-3">
                        <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                            <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">English</span>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
                                <div class="form-control">
                                    <label class="label py-0"><span class="label-text text-xs font-medium">Voice</span></label>
                                    <select class="select select-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" bind:value={editStationTts.en.voice_id}>
                                        <option value="">Use default</option>
                                        {#each serverTtsVoices as voice}
                                            <option value={voice.id}>{voice.name}{voice.lang ? ` (${voice.lang})` : ""}</option>
                                        {/each}
                                    </select>
                                </div>
                                <div class="form-control">
                                    <label class="label py-0"><span class="label-text text-xs font-medium">Speed</span></label>
                                    <div class="flex items-center gap-2">
                                        <input type="range" min="0.5" max="2" step="0.05" class="range range-xs flex-1" bind:value={editStationTts.en.rate} />
                                        <span class="text-xs text-surface-600 w-12">{Number(editStationTts.en.rate).toFixed(2)}x</span>
                                    </div>
                                </div>
                            </div>
                            <div class="form-control mt-2">
                                <label class="label py-0" for="edit-station-en-phrase"><span class="label-text text-xs font-medium">Station phrase (optional)</span></label>
                                <input id="edit-station-en-phrase" type="text" class="input input-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" placeholder='e.g. "Window one"' bind:value={editStationTts.en.station_phrase} />
                            </div>
                        </div>
                        <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                            <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">Filipino</span>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
                                <div class="form-control">
                                    <label class="label py-0"><span class="label-text text-xs font-medium">Voice</span></label>
                                    <select class="select select-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" bind:value={editStationTts.fil.voice_id}>
                                        <option value="">Use default</option>
                                        {#each serverTtsVoices as voice}
                                            <option value={voice.id}>{voice.name}{voice.lang ? ` (${voice.lang})` : ""}</option>
                                        {/each}
                                    </select>
                                </div>
                                <div class="form-control">
                                    <label class="label py-0"><span class="label-text text-xs font-medium">Speed</span></label>
                                    <div class="flex items-center gap-2">
                                        <input type="range" min="0.5" max="2" step="0.05" class="range range-xs flex-1" bind:value={editStationTts.fil.rate} />
                                        <span class="text-xs text-surface-600 w-12">{Number(editStationTts.fil.rate).toFixed(2)}x</span>
                                    </div>
                                </div>
                            </div>
                            <div class="form-control mt-2">
                                <label class="label py-0" for="edit-station-fil-phrase"><span class="label-text text-xs font-medium">Station phrase (optional)</span></label>
                                <input id="edit-station-fil-phrase" type="text" class="input input-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" placeholder='e.g. "Estasyon ng window one"' bind:value={editStationTts.fil.station_phrase} />
                            </div>
                        </div>
                        <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                            <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">Ilocano</span>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
                                <div class="form-control">
                                    <label class="label py-0"><span class="label-text text-xs font-medium">Voice</span></label>
                                    <select class="select select-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" bind:value={editStationTts.ilo.voice_id}>
                                        <option value="">Use default</option>
                                        {#each serverTtsVoices as voice}
                                            <option value={voice.id}>{voice.name}{voice.lang ? ` (${voice.lang})` : ""}</option>
                                        {/each}
                                    </select>
                                </div>
                                <div class="form-control">
                                    <label class="label py-0"><span class="label-text text-xs font-medium">Speed</span></label>
                                    <div class="flex items-center gap-2">
                                        <input type="range" min="0.5" max="2" step="0.05" class="range range-xs flex-1" bind:value={editStationTts.ilo.rate} />
                                        <span class="text-xs text-surface-600 w-12">{Number(editStationTts.ilo.rate).toFixed(2)}x</span>
                                    </div>
                                </div>
                            </div>
                            <div class="form-control mt-2">
                                <label class="label py-0" for="edit-station-ilo-phrase"><span class="label-text text-xs font-medium">Station phrase (optional)</span></label>
                                <input id="edit-station-ilo-phrase" type="text" class="input input-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" placeholder='e.g. "Estasyon ti window one"' bind:value={editStationTts.ilo.station_phrase} />
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="btn preset-tonal"
                        onclick={closeModals}>Cancel</button
                    >
                    <button
                        type="submit"
                        class="btn preset-filled-primary-500"
                        disabled={submitting ||
                            !editStationName.trim() ||
                            editStationProcessIds.length === 0 ||
                            processes.length === 0}
                    >
                        {submitting ? "Saving…" : "Save"}
                    </button>
                </div>
            </form>
        {/snippet}
    </Modal>
{/if}

<Modal
    open={showStationTtsModal && !!stationTtsModalStation}
    title={stationTtsModalStation ? `Station TTS – ${stationTtsModalStation.name}` : "Station TTS"}
    onClose={closeStationTtsModal}
>
    {#snippet children()}
        <form
            onsubmit={(e) => {
                e.preventDefault();
                if (editStation) handleUpdateStation();
            }}
            class="flex flex-col gap-4"
        >
            <p class="text-sm text-surface-600">
                Configure how this station name is spoken in each language (voice, speed, and pronunciation). Used for the second part of the call announcement.
            </p>
            <div class="space-y-3 max-h-[24rem] overflow-y-auto pr-1">
                <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                    <div class="flex items-center justify-between gap-2 mb-2">
                        <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">English</span>
                        <button type="button" class="btn btn-xs preset-tonal" disabled={!!stationTtsSamplePlayingLang || submitting} onclick={() => playStationTtsSample("en")}>
                            {stationTtsSamplePlayingLang === "en" ? "Playing…" : "Sample"}
                        </button>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="form-control">
                            <label class="label py-0"><span class="label-text text-xs font-medium">Voice</span></label>
                            <select class="select select-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" bind:value={editStationTts.en.voice_id}>
                                <option value="">Use default</option>
                                {#each serverTtsVoices as voice}
                                    <option value={voice.id}>{voice.name}{voice.lang ? ` (${voice.lang})` : ""}</option>
                                {/each}
                            </select>
                        </div>
                        <div class="form-control">
                            <label class="label py-0"><span class="label-text text-xs font-medium">Speed</span></label>
                            <div class="flex items-center gap-2">
                                <input type="range" min="0.5" max="2" step="0.05" class="range range-xs flex-1" bind:value={editStationTts.en.rate} />
                                <span class="text-xs text-surface-600 w-12">{Number(editStationTts.en.rate).toFixed(2)}x</span>
                            </div>
                        </div>
                    </div>
                    <div class="form-control mt-2">
                        <label class="label py-0"><span class="label-text text-xs font-medium">Station phrase (optional)</span></label>
                        <input type="text" class="input input-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" placeholder='e.g. "Window one"' bind:value={editStationTts.en.station_phrase} />
                    </div>
                </div>
                <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                    <div class="flex items-center justify-between gap-2 mb-2">
                        <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">Filipino</span>
                        <button type="button" class="btn btn-xs preset-tonal" disabled={!!stationTtsSamplePlayingLang || submitting} onclick={() => playStationTtsSample("fil")}>
                            {stationTtsSamplePlayingLang === "fil" ? "Playing…" : "Sample"}
                        </button>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="form-control">
                            <label class="label py-0"><span class="label-text text-xs font-medium">Voice</span></label>
                            <select class="select select-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" bind:value={editStationTts.fil.voice_id}>
                                <option value="">Use default</option>
                                {#each serverTtsVoices as voice}
                                    <option value={voice.id}>{voice.name}{voice.lang ? ` (${voice.lang})` : ""}</option>
                                {/each}
                            </select>
                        </div>
                        <div class="form-control">
                            <label class="label py-0"><span class="label-text text-xs font-medium">Speed</span></label>
                            <div class="flex items-center gap-2">
                                <input type="range" min="0.5" max="2" step="0.05" class="range range-xs flex-1" bind:value={editStationTts.fil.rate} />
                                <span class="text-xs text-surface-600 w-12">{Number(editStationTts.fil.rate).toFixed(2)}x</span>
                            </div>
                        </div>
                    </div>
                    <div class="form-control mt-2">
                        <label class="label py-0"><span class="label-text text-xs font-medium">Station phrase (optional)</span></label>
                        <input type="text" class="input input-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" placeholder='e.g. "Estasyon ng window one"' bind:value={editStationTts.fil.station_phrase} />
                    </div>
                </div>
                <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                    <div class="flex items-center justify-between gap-2 mb-2">
                        <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">Ilocano</span>
                        <button type="button" class="btn btn-xs preset-tonal" disabled={!!stationTtsSamplePlayingLang || submitting} onclick={() => playStationTtsSample("ilo")}>
                            {stationTtsSamplePlayingLang === "ilo" ? "Playing…" : "Sample"}
                        </button>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="form-control">
                            <label class="label py-0"><span class="label-text text-xs font-medium">Voice</span></label>
                            <select class="select select-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" bind:value={editStationTts.ilo.voice_id}>
                                <option value="">Use default</option>
                                {#each serverTtsVoices as voice}
                                    <option value={voice.id}>{voice.name}{voice.lang ? ` (${voice.lang})` : ""}</option>
                                {/each}
                            </select>
                        </div>
                        <div class="form-control">
                            <label class="label py-0"><span class="label-text text-xs font-medium">Speed</span></label>
                            <div class="flex items-center gap-2">
                                <input type="range" min="0.5" max="2" step="0.05" class="range range-xs flex-1" bind:value={editStationTts.ilo.rate} />
                                <span class="text-xs text-surface-600 w-12">{Number(editStationTts.ilo.rate).toFixed(2)}x</span>
                            </div>
                        </div>
                    </div>
                    <div class="form-control mt-2">
                        <label class="label py-0"><span class="label-text text-xs font-medium">Station phrase (optional)</span></label>
                        <input type="text" class="input input-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" placeholder='e.g. "Estasyon ti window one"' bind:value={editStationTts.ilo.station_phrase} />
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" class="btn preset-tonal" onclick={closeStationTtsModal} disabled={submitting}>
                    Cancel
                </button>
                <button type="submit" class="btn preset-filled-primary-500" disabled={submitting || !editStation}>
                    {submitting ? "Saving…" : "Save"}
                </button>
            </div>
        </form>
    {/snippet}
</Modal>

<Modal
    open={showProgramTtsRegenerateConfirm}
    title="Regenerate station TTS"
    onClose={() => { if (!programTtsRegenerating) showProgramTtsRegenerateConfirm = false; }}
>
    {#snippet children()}
        <div class="flex flex-col gap-4">
            <p class="text-sm text-surface-700">
                Connecting phrase TTS was updated. This will regenerate TTS for all stations in this program.
                Regeneration runs in the background; status will update as each station finishes.
            </p>
            <div class="flex justify-end gap-3 pt-2 border-t border-surface-200">
                <button type="button" class="btn preset-tonal" disabled={programTtsRegenerating} onclick={() => { if (!programTtsRegenerating) showProgramTtsRegenerateConfirm = false; }}>
                    Cancel
                </button>
                <button
                    type="button"
                    class="btn preset-filled-primary-500"
                    disabled={programTtsRegenerating}
                    onclick={async () => {
                        if (programTtsRegenerating || !program?.id) return;
                        programTtsRegenerating = true;
                        try {
                            const res = await fetch(`/api/admin/programs/${program.id}/regenerate-station-tts`, { method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json", "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": getCsrfToken() }, credentials: "same-origin" });
                            if (res.status === 419) {
                                toaster.error({ title: MSG_SESSION_EXPIRED });
                                return;
                            }
                            if (!res.ok) toaster.error({ title: "Failed to start regeneration." });
                            else { showProgramTtsRegenerateConfirm = false; router.reload(); }
                        } catch (e) {
                            const isNetwork = e instanceof TypeError && (e as Error).message === "Failed to fetch";
                            toaster.error({ title: isNetwork ? MSG_NETWORK_ERROR : "Failed to start regeneration." });
                        }
                        finally { programTtsRegenerating = false; }
                    }}
                >
                    {programTtsRegenerating ? "Starting…" : "Regenerate all"}
                </button>
            </div>
        </div>
    {/snippet}
</Modal>

<Modal
    open={showStationTtsRegenerateConfirm}
    title="Regenerate station TTS"
    onClose={() => { if (!stationTtsRegenerating) { showStationTtsRegenerateConfirm = false; closeModals(); closeStationTtsModal(); } }}
>
    {#snippet children()}
        <div class="flex flex-col gap-4">
            <p class="text-sm text-surface-700">
                Station TTS was updated and this station had generated audio. Regenerate TTS for this station?
            </p>
            <div class="flex justify-end gap-3 pt-2 border-t border-surface-200">
                <button type="button" class="btn preset-tonal" disabled={stationTtsRegenerating} onclick={() => { if (!stationTtsRegenerating) { showStationTtsRegenerateConfirm = false; closeModals(); closeStationTtsModal(); router.reload(); } }}>
                    Cancel
                </button>
                <button
                    type="button"
                    class="btn preset-filled-primary-500"
                    disabled={stationTtsRegenerating || !editStation}
                    onclick={async () => {
                        if (stationTtsRegenerating || !editStation?.id) return;
                        stationTtsRegenerating = true;
                        try {
                            const res = await fetch(`/api/admin/stations/${editStation.id}/regenerate-tts`, { method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json", "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": getCsrfToken() }, credentials: "same-origin" });
                            if (res.status === 419) {
                                toaster.error({ title: MSG_SESSION_EXPIRED });
                                return;
                            }
                            if (!res.ok) toaster.error({ title: "Failed to start regeneration." });
                            else { showStationTtsRegenerateConfirm = false; closeModals(); closeStationTtsModal(); router.reload(); }
                        } catch (e) {
                            const isNetwork = e instanceof TypeError && (e as Error).message === "Failed to fetch";
                            toaster.error({ title: isNetwork ? MSG_NETWORK_ERROR : "Failed to start regeneration." });
                        }
                        finally { stationTtsRegenerating = false; }
                    }}
                >
                    {stationTtsRegenerating ? "Starting…" : "Regenerate"}
                </button>
            </div>
        </div>
    {/snippet}
</Modal>

{#if stepModalTrack}
    <Modal
        open={!!stepModalTrack}
        title="Steps: {stepModalTrack.name}"
        onClose={closeStepModal}
    >
        {#snippet children()}
            <div class="flex flex-col gap-4">
                <div class="text-sm text-surface-950/70">
                    Define the station sequence for this track. Clients will
                    follow this order.
                </div>
                <ul class="flex flex-col gap-2">
                    {#each modalSteps as step, i (step.id)}
                        <li
                            class="rounded-lg border border-surface-200 bg-surface-100 p-2"
                        >
                            {#if editingStepId === step.id}
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="font-medium text-surface-950/80"
                                            >{step.step_order}.</span
                                        >
                                        <span
                                            class="text-sm text-surface-950/70"
                                            >Edit step</span
                                        >
                                    </div>
                                    <div
                                        class="grid gap-2 sm:grid-cols-[1fr_auto_auto_auto]"
                                    >
                                        <div class="form-control min-w-0">
                                            <label
                                                for="edit-step-process-{step.id}"
                                                class="label py-0"
                                                ><span
                                                    class="label-text text-xs"
                                                    >Process</span
                                                ></label
                                            >
                                            <select
                                                id="edit-step-process-{step.id}"
                                                class="select rounded-container border border-surface-200 px-3 py-2 select-sm w-full"
                                                bind:value={editStepProcessId}
                                                aria-label="Process"
                                            >
                                                {#each processes as proc}
                                                    <option value={proc.id}
                                                        >{proc.name}</option
                                                    >
                                                {/each}
                                            </select>
                                        </div>
                                        <div class="form-control w-28">
                                            <label
                                                for="edit-step-min-{step.id}"
                                                class="label py-0"
                                                ><span
                                                    class="label-text text-xs"
                                                    >Est. time (min)</span
                                                ></label
                                            >
                                            <input
                                                id="edit-step-min-{step.id}"
                                                type="number"
                                                min="0"
                                                max="120"
                                                class="input rounded-container border border-surface-200 px-3 py-2 input-sm w-full"
                                                placeholder="Optional"
                                                bind:value={
                                                    editStepEstimatedMinutes
                                                }
                                                aria-label="Estimated time in minutes"
                                            />
                                        </div>
                                        <div class="form-control justify-end">
                                            <label
                                                class="label cursor-pointer justify-start gap-2 py-0"
                                                for="edit-step-required-{step.id}"
                                            >
                                                <input
                                                    id="edit-step-required-{step.id}"
                                                    type="checkbox"
                                                    class="checkbox checkbox-sm"
                                                    bind:checked={
                                                        editStepIsRequired
                                                    }
                                                    aria-label="Required step"
                                                />
                                                <span class="label-text text-xs"
                                                    >Required</span
                                                >
                                            </label>
                                        </div>
                                        <div class="flex items-end gap-1">
                                            <button
                                                type="button"
                                                class="btn preset-filled-primary-500 btn-sm"
                                                onclick={handleUpdateStep}
                                                disabled={submitting ||
                                                    editStepProcessId === ""}
                                                >Save</button
                                            >
                                            <button
                                                type="button"
                                                class="btn preset-tonal btn-sm"
                                                onclick={cancelEditStep}
                                                disabled={submitting}
                                                >Cancel</button
                                            >
                                        </div>
                                    </div>
                                </div>
                            {:else}
                                <div class="flex items-center gap-2">
                                    <span
                                        class="font-medium text-surface-950/80"
                                        >{step.step_order}.</span
                                    >
                                    <span class="flex-1"
                                        >{step.process_name}</span
                                    >
                                    {#if step.estimated_minutes != null}
                                        <span
                                            class="text-xs text-surface-950/60"
                                            title="Estimated time"
                                            >~{step.estimated_minutes} min</span
                                        >
                                    {:else}
                                        <span
                                            class="text-xs text-surface-950/50"
                                            >—</span
                                        >
                                    {/if}
                                    {#if step.is_required}
                                        <span
                                            class="text-xs px-2 py-0.5 rounded preset-tonal badge-xs"
                                            >Required</span
                                        >
                                    {/if}
                                    <div class="flex gap-1">
                                        <button
                                            type="button"
                                            class="btn preset-tonal btn-xs"
                                            onclick={() => openEditStep(step)}
                                            disabled={submitting ||
                                                editingStepId != null}
                                            title="Edit step">Edit</button
                                        >
                                        <button
                                            type="button"
                                            class="btn preset-tonal btn-xs"
                                            onclick={() =>
                                                handleMoveStepUp(step)}
                                            disabled={submitting || i === 0}
                                            title="Move up">↑</button
                                        >
                                        <button
                                            type="button"
                                            class="btn preset-tonal btn-xs"
                                            onclick={() =>
                                                handleMoveStepDown(step)}
                                            disabled={submitting ||
                                                i === modalSteps.length - 1}
                                            title="Move down">↓</button
                                        >
                                        <button
                                            type="button"
                                            class="btn preset-tonal btn-xs text-error"
                                            onclick={() =>
                                                openRemoveStepConfirm(step)}
                                            disabled={submitting}
                                            title="Remove step">Delete</button
                                        >
                                    </div>
                                </div>
                            {/if}
                        </li>
                    {/each}
                </ul>
                <div class="border-t border-surface-200 pt-3">
                    <p class="label-text mb-2">Add step</p>
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="form-control min-w-[180px]">
                            <label for="add-step-process" class="label py-0"
                                ><span class="label-text text-xs">Process</span
                                ></label
                            >
                            <select
                                id="add-step-process"
                                class="select rounded-container border border-surface-200 px-3 py-2 select-sm w-full"
                                bind:value={addStepProcessId}
                                aria-label="Process"
                            >
                                <option value="">Select process</option>
                                {#each processes as proc}
                                    <option value={proc.id}>{proc.name}</option>
                                {/each}
                            </select>
                        </div>
                        <div class="form-control w-28">
                            <label for="add-step-min" class="label py-0"
                                ><span class="label-text text-xs"
                                    >Est. time (min)</span
                                ></label
                            >
                            <input
                                id="add-step-min"
                                type="number"
                                min="0"
                                max="120"
                                class="input rounded-container border border-surface-200 px-3 py-2 input-sm w-full"
                                placeholder="Optional"
                                bind:value={addStepEstimatedMinutes}
                                aria-label="Estimated time in minutes for queue estimates"
                            />
                        </div>
                        <button
                            type="button"
                            class="btn preset-filled-primary-500 btn-sm"
                            onclick={handleAddStep}
                            disabled={submitting || addStepProcessId === ""}
                            >Add</button
                        >
                    </div>
                    <p class="text-xs text-surface-950/50 mt-1">
                        Estimated time is optional and used for queue time
                        estimates.
                    </p>
                </div>
                <div class="flex justify-end">
                    <button
                        type="button"
                        class="btn preset-tonal"
                        onclick={closeStepModal}>Close</button
                    >
                </div>
            </div>
        {/snippet}
    </Modal>
{/if}

<Modal
    open={showProgramConnectorTtsModal}
    title="Program connecting-phrase TTS"
    onClose={() => (showProgramConnectorTtsModal = false)}
>
    {#snippet children()}
        <form
            onsubmit={(e) => {
                e.preventDefault();
                handleSaveSettings();
                showProgramConnectorTtsModal = false;
            }}
            class="flex flex-col gap-4"
        >
            <p class="text-sm text-surface-600">
                Configure how the connecting phrase between the token and station will sound for each language.
                These settings apply to all stations in this program.
            </p>
            <div class="form-control">
                <label class="label" for="tts-active-language-modal">
                    <span class="label-text text-sm font-medium">Active language</span>
                </label>
                <select
                    id="tts-active-language-modal"
                    class="select rounded-container border border-surface-200 px-3 py-2 w-full text-surface-950 bg-surface-50 shadow-sm max-w-xs"
                    bind:value={ttsActiveLanguage}
                >
                    <option value="en">English</option>
                    <option value="fil">Filipino</option>
                    <option value="ilo">Ilocano</option>
                </select>
                <p class="text-xs text-surface-500 mt-1">
                    Displays use this language first for announcements.
                </p>
            </div>
            <div class="form-control mt-4">
                <label class="label cursor-pointer justify-start gap-3">
                    <input type="checkbox" class="checkbox checkbox-sm" bind:checked={autoGenerateStationTts} />
                    <span class="label-text font-medium">Automatically generate station TTS when creating stations</span>
                </label>
                <p class="text-xs text-surface-500 mt-1 ml-6">
                    When off, new stations are saved without generating audio; use "Regenerate station TTS" when needed.
                </p>
            </div>
            <div class="space-y-3 max-h-[26rem] overflow-y-auto pr-1">
                <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                    <div class="flex items-center justify-between gap-2 mb-2">
                        <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">English</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="form-control">
                            <label class="label" for="connector-en-voice-modal">
                                <span class="label-text text-xs font-medium">Voice</span>
                            </label>
                            <select
                                id="connector-en-voice-modal"
                                class="select select-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm"
                                bind:value={connectorTts.en.voice_id}
                            >
                                <option value={""}>Use global token voice</option>
                                {#each serverTtsVoices as voice}
                                    <option value={voice.id}>
                                        {voice.name}{voice.lang ? ` (${voice.lang})` : ""}
                                    </option>
                                {/each}
                            </select>
                        </div>
                        <div class="form-control">
                            <label class="label" for="connector-en-rate-modal">
                                <span class="label-text text-xs font-medium">Speed</span>
                            </label>
                            <div class="flex items-center gap-3">
                                <input
                                    id="connector-en-rate-modal"
                                    type="range"
                                    min="0.5"
                                    max="2"
                                    step="0.05"
                                    class="range range-xs max-w-xs"
                                    bind:value={connectorTts.en.rate}
                                />
                                <span class="text-xs text-surface-600 w-14">
                                    {Number(connectorTts.en.rate).toFixed(2)}x
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="form-control mt-2">
                        <label class="label" for="connector-en-phrase-modal">
                            <span class="label-text text-xs font-medium">Connector phrase (optional)</span>
                        </label>
                        <input
                            id="connector-en-phrase-modal"
                            type="text"
                            class="input input-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm"
                            placeholder='e.g. "Please proceed to"'
                            bind:value={connectorTts.en.connector_phrase}
                        />
                    </div>
                </div>
                <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                    <div class="flex items-center justify-between gap-2 mb-2">
                        <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">Filipino</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="form-control">
                            <label class="label" for="connector-fil-voice-modal">
                                <span class="label-text text-xs font-medium">Voice</span>
                            </label>
                            <select
                                id="connector-fil-voice-modal"
                                class="select select-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm"
                                bind:value={connectorTts.fil.voice_id}
                            >
                                <option value={""}>Use global token voice</option>
                                {#each serverTtsVoices as voice}
                                    <option value={voice.id}>
                                        {voice.name}{voice.lang ? ` (${voice.lang})` : ""}
                                    </option>
                                {/each}
                            </select>
                        </div>
                        <div class="form-control">
                            <label class="label" for="connector-fil-rate-modal">
                                <span class="label-text text-xs font-medium">Speed</span>
                            </label>
                            <div class="flex items-center gap-3">
                                <input
                                    id="connector-fil-rate-modal"
                                    type="range"
                                    min="0.5"
                                    max="2"
                                    step="0.05"
                                    class="range range-xs max-w-xs"
                                    bind:value={connectorTts.fil.rate}
                                />
                                <span class="text-xs text-surface-600 w-14">
                                    {Number(connectorTts.fil.rate).toFixed(2)}x
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="form-control mt-2">
                        <label class="label" for="connector-fil-phrase-modal">
                            <span class="label-text text-xs font-medium">Connector phrase (optional)</span>
                        </label>
                        <input
                            id="connector-fil-phrase-modal"
                            type="text"
                            class="input input-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm"
                            placeholder='e.g. "Pumunta sa"'
                            bind:value={connectorTts.fil.connector_phrase}
                        />
                    </div>
                </div>
                <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                    <div class="flex items-center justify-between gap-2 mb-2">
                        <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">Ilocano</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="form-control">
                            <label class="label" for="connector-ilo-voice-modal">
                                <span class="label-text text-xs font-medium">Voice</span>
                            </label>
                            <select
                                id="connector-ilo-voice-modal"
                                class="select select-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm"
                                bind:value={connectorTts.ilo.voice_id}
                            >
                                <option value={""}>Use global token voice</option>
                                {#each serverTtsVoices as voice}
                                    <option value={voice.id}>
                                        {voice.name}{voice.lang ? ` (${voice.lang})` : ""}
                                    </option>
                                {/each}
                            </select>
                        </div>
                        <div class="form-control">
                            <label class="label" for="connector-ilo-rate-modal">
                                <span class="label-text text-xs font-medium">Speed</span>
                            </label>
                            <div class="flex items-center gap-3">
                                <input
                                    id="connector-ilo-rate-modal"
                                    type="range"
                                    min="0.5"
                                    max="2"
                                    step="0.05"
                                    class="range range-xs max-w-xs"
                                    bind:value={connectorTts.ilo.rate}
                                />
                                <span class="text-xs text-surface-600 w-14">
                                    {Number(connectorTts.ilo.rate).toFixed(2)}x
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="form-control mt-2">
                        <label class="label" for="connector-ilo-phrase-modal">
                            <span class="label-text text-xs font-medium">Connector phrase (optional)</span>
                        </label>
                        <input
                            id="connector-ilo-phrase-modal"
                            type="text"
                            class="input input-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm"
                            placeholder='e.g. "Mapanen ijay"'
                            bind:value={connectorTts.ilo.connector_phrase}
                        />
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button
                    type="button"
                    class="btn preset-tonal"
                    onclick={() => (showProgramConnectorTtsModal = false)}
                    disabled={submitting}
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    class="btn preset-filled-primary-500"
                    disabled={submitting}
                >
                    {submitting ? "Saving…" : "Save"}
                </button>
            </div>
        </form>
    {/snippet}
</Modal>

{#if showReorderConfirm && stepModalTrack}
    <Modal
        open={showReorderConfirm}
        title="Reorder steps"
        onClose={closeReorderConfirm}
    >
        {#snippet children()}
            <div class="flex flex-col gap-4">
                <p class="text-sm text-surface-950/80">
                    This track has <strong
                        >{stepModalTrack.active_sessions_count ?? 0} active session(s)</strong
                    >. How should the new order apply?
                </p>
                <div class="form-control gap-2">
                    <label
                        class="label cursor-pointer justify-start gap-3 rounded-lg border border-surface-200 bg-surface-100 p-3"
                    >
                        <input
                            type="radio"
                            name="reorder_scope"
                            class="radio radio-primary radio-sm"
                            bind:group={reorderScope}
                            value="new_only"
                        />
                        <div>
                            <span class="font-medium">New sessions only</span>
                            <p class="text-xs text-surface-950/60">
                                Future check-ins follow the new order. Existing
                                sessions keep their current position.
                            </p>
                        </div>
                    </label>
                    <label
                        class="label cursor-pointer justify-start gap-3 rounded-lg border border-surface-200 bg-surface-100 p-3"
                    >
                        <input
                            type="radio"
                            name="reorder_scope"
                            class="radio radio-primary radio-sm"
                            bind:group={reorderScope}
                            value="migrate"
                        />
                        <div>
                            <span class="font-medium"
                                >Migrate existing sessions</span
                            >
                            <p class="text-xs text-surface-950/60">
                                Update active sessions to match the new step
                                order (remap their position).
                            </p>
                        </div>
                    </label>
                </div>
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="btn preset-tonal"
                        onclick={closeReorderConfirm}>Cancel</button
                    >
                    <button
                        type="button"
                        class="btn preset-filled-primary-500"
                        onclick={confirmReorderApply}
                        disabled={submitting}>Apply</button
                    >
                </div>
            </div>
        {/snippet}
    </Modal>
{/if}

<ConfirmModal
    open={confirmStopProgram}
    title="Stop session?"
    message={`Stop session for "${program?.name ?? 'this program'}"? You can only stop when no clients are in the queue.`}
    confirmLabel="Stop session"
    cancelLabel="Cancel"
    variant="danger"
    loading={submitting}
    onConfirm={handleStopConfirm}
    onCancel={closeStopConfirm}
/>

<!-- Per bead flexiqueue-nlu: when queue has clients, show warning and suggest Pause instead of confirm. -->
<ConfirmModal
    open={showStopQueueWarning}
    title="Cannot stop session"
    message="You cannot stop the session while clients are in the queue. Use Pause instead to temporarily halt operations."
    confirmLabel="Pause instead"
    cancelLabel="OK"
    variant="warning"
    loading={submitting}
    onConfirm={handlePauseFromStopWarning}
    onCancel={closeStopQueueWarning}
/>

<ConfirmModal
    open={!!confirmDeleteTrack}
    title="Delete track?"
    message={confirmDeleteTrack
        ? `Delete track "${confirmDeleteTrack.name}"? This is only allowed if no active sessions use it.`
        : ""}
    confirmLabel="Delete"
    cancelLabel="Cancel"
    variant="danger"
    loading={submitting}
    onConfirm={handleDeleteTrackConfirm}
    onCancel={closeDeleteTrackConfirm}
/>

<ConfirmModal
    open={!!confirmDeleteStation}
    title="Delete station?"
    message={confirmDeleteStation
        ? `Delete station "${confirmDeleteStation.name}"? This is only allowed if it is not used in any track steps.`
        : ""}
    confirmLabel="Delete"
    cancelLabel="Cancel"
    variant="danger"
    loading={submitting}
    onConfirm={handleDeleteStationConfirm}
    onCancel={closeDeleteStationConfirm}
/>

<ConfirmModal
    open={!!confirmDeleteProcess}
    title="Delete process?"
    message={confirmDeleteProcess
        ? `Delete process "${confirmDeleteProcess.name}"? This is only allowed if it is not used by any station or track step.`
        : ""}
    confirmLabel="Delete"
    cancelLabel="Cancel"
    variant="danger"
    loading={submitting}
    onConfirm={handleDeleteProcessConfirm}
    onCancel={closeDeleteProcessConfirm}
/>

<ConfirmModal
    open={!!confirmRemoveStep}
    title="Remove step?"
    message={confirmRemoveStep
        ? `Remove step "${confirmRemoveStep.step.process_name}" from this track?`
        : ""}
    confirmLabel="Remove"
    cancelLabel="Cancel"
    variant="warning"
    loading={submitting}
    onConfirm={handleRemoveStepConfirm}
    onCancel={closeRemoveStepConfirm}
/>
