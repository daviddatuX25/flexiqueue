<script lang="ts">
    import AdminLayout from "../../../Layouts/AdminLayout.svelte";
    import AdminTable from "../../../Components/AdminTable.svelte";
    import Modal from "../../../Components/Modal.svelte";
    import ConfirmModal from "../../../Components/ConfirmModal.svelte";
    import FlowDiagram from "../../../Components/FlowDiagram.svelte";
    import QrDisplay from "../../../Components/QrDisplay.svelte";
    import DiagramCanvas from "../../../Components/ProgramDiagram/DiagramCanvas.svelte";
    import ScopedRbacTeamAccessPanel from "../../../Components/admin/ScopedRbacTeamAccessPanel.svelte";
    import { get } from "svelte/store";
    import { onMount } from "svelte";
    import { Link, router, usePage } from "@inertiajs/svelte";
    import { toaster } from "../../../lib/toaster.js";
    import { compressImage, HERO_BANNER_PRESET, getUploadHint } from "../../../lib/imageUtils.js";
    import { ensureVoicesLoaded, speakSample, speakSampleAsync } from "../../../lib/speechUtils.js";
    import { playAdminFullAnnouncementPreview, playAdminTtsPreview, previewSegment2Text } from "../../../lib/ttsPreview.js";

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
        Camera,
        User,
        Power,
        Rocket,
        ChevronLeft,
        ChevronRight,
        Volume2,
        Key,
        XCircle,
    } from "lucide-svelte";

    interface ProgramItem {
        id: number;
        name: string;
        slug?: string;
        description: string | null;
        is_active: boolean;
        is_paused?: boolean;
        created_at: string | null;
        settings?: {
            no_show_timer_seconds: number;
            max_no_show_attempts?: number;
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
            /** Legacy mirror of kiosk self-service; prefer kiosk_self_service_triage_enabled. */
            allow_public_triage?: boolean;
            kiosk_self_service_triage_enabled?: boolean;
            kiosk_status_checker_enabled?: boolean;
            kiosk_enable_hid_barcode?: boolean;
            kiosk_enable_camera_scanner?: boolean;
            kiosk_modal_idle_seconds?: number;
            /** Per identity-registration plan: when true, public triage may create a session alongside an identity registration (unverified). Default false. */
            allow_unverified_entry?: boolean;
            /** Per flexiqueue-xm2o: per-program identity binding mode. */
            identity_binding_mode?: "disabled" | "required";
            /** Per barcode-hid: enable HID barcode on Display board. Default true. */
            enable_display_hid_barcode?: boolean;
            /** Per barcode-hid: enable HID barcode on kiosk (legacy key). */
            enable_public_triage_hid_barcode?: boolean;
            /** Camera on kiosk (legacy key). */
            enable_public_triage_camera_scanner?: boolean;
            /** Per plan: enable camera/QR scanner on Display board. Default true. */
            enable_display_camera_scanner?: boolean;
            /** Per addition-to-public-site-plan: public page and program key. */
            public_access_key?: string | null;
            public_access_expiry_hours?: number;
            page_description?: string | null;
            page_announcement?: string | null;
            page_banner_image_url?: string | null;
        };
        is_published?: boolean;
        short_links?: { type: string; code: string; url: string; has_embedded_key?: boolean }[];
        edge_locked_by_device_id?: number | null;
        edge_locked_by_device_name?: string | null;
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
                    status?: string;
                    failure_reason?: string | null;
                    audio_path?: string | null;
                    updated_at?: string | null;
                }
            >;
        };
    }

    /** Per Phase C: token assigned to program or global (from GET .../tokens). */
    interface TokenItem {
        id: number;
        physical_id: string;
        status?: string;
        is_global?: boolean;
        source?: "assigned" | "global";
        can_unassign?: boolean;
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
        site_slug = null,
        app_url = "",
        rbac_team = null as {
            id: number;
            type: string;
            site_id: number;
            scope_label: string;
        } | null,
    }: {
        program?: ProgramItem | null;
        tracks?: TrackItem[];
        processes?: ProcessItem[];
        stations?: StationItem[];
        stats?: ProgramStats;
        site_slug?: string | null;
        app_url?: string;
        rbac_team?: {
            id: number;
            type: string;
            site_id: number;
            scope_label: string;
        } | null;
    } = $props();

    const VALID_TABS = ["overview", "public-page", "processes", "stations", "staff", "tokens", "tracks", "diagram", "settings"] as const;
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

    $effect(() => {
        if (activeTab === "public-page" && publicPagePrivate && program?.id) {
            fetchAccessTokens();
            const t = setInterval(fetchAccessTokens, 30000);
            return () => clearInterval(t);
        }
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
    let showCreateProcessModal = $state(false);
    let editStation = $state<StationItem | null>(null);
    let createStationName = $state("");
    let createStationCapacity = $state(1);
    let createStationClientCapacity = $state(1);
    let createStationProcessIds = $state<number[]>([]);
    let createStationGenerateAudio = $state(true);
    type StationTtsLangKey = "en" | "fil" | "ilo";
    type StationTtsUiStatus = "not_generated" | "generating" | "ready" | "failed";
    const STATION_TTS_LANGS: StationTtsLangKey[] = ["en", "fil", "ilo"];
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
    let maxNoShowAttempts = $state(3);
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
    /** Station edit modal: segment-2-only vs full-call preview. */
    let stationTtsAudioPlaying = $state<null | { mode: "sample" | "full"; lang: StationTtsLangKey }>(null);
    /** Add-station modal: same preview pattern before save. */
    let createStationTtsAudioPlaying = $state<null | { mode: "sample" | "full"; lang: StationTtsLangKey }>(null);
    let connectorTtsAudioPlaying = $state<null | { mode: "sample" | "full"; lang: TtsLangKey }>(null);
    /** Show "regenerate station TTS" confirm after saving program connector TTS. */
    let showProgramTtsRegenerateConfirm = $state(false);
    /** Show "regenerate station TTS" confirm after saving a station's TTS. */
    let showStationTtsRegenerateConfirm = $state(false);
    let programTtsRegenerating = $state(false);
    let stationTtsRegenerating = $state(false);
    /** Public Page tab state. */
    let publicPagePublished = $state(true);
    let publicPagePrivate = $state(false);
    let publicPageKey = $state("");
    let publicPageExpiryHours = $state(24);
    let publicPageDescription = $state("");
    let publicPageAnnouncement = $state("");
    let publicPageBannerUrl = $state<string | null>(null);
    let publicPageBannerUploading = $state(false);
    let bannerDragging = $state(false);
    let bannerInputEl = $state<HTMLInputElement | null>(null);
    let publicPageSaving = $state(false);
    let publicPageSavingQr = $state(false);
    let accessTokensCount = $state(0);
    let accessTokensList = $state<{ id: number; token_ref: string; issued_at: string; expires_at: string }[]>([]);
    /** Station ID currently triggering TTS regeneration (for button loading state). */
    let stationRegeneratingId = $state<number | null>(null);
    /** Kiosk: allow self-service triage (scan token, choose track, start visit). */
    let allowPublicTriage = $state(false);
    /** Kiosk: allow scanning a token already in the queue to open queue status. */
    let kioskStatusCheckerEnabled = $state(true);
    /**
     * Identity policy at triage. Per IDENTITY-BINDING-FINAL-IMPLEMENTATION-PLAN: only none | required; optional washed out.
     * When "required", allow_unverified_entry controls whether public triage can start a visit before staff verification.
     */
    let identityPolicy = $state<"none" | "required">("none");
    /** When identityPolicy === "required", allow unverified registrations to start visits from public triage. */
    let allowUnverifiedEntry = $state(false);
    /** Underlying identity binding mode (disabled | required). */
    let identityBindingMode = $state<"disabled" | "required">("disabled");
    /** Per barcode-hid: enable HID barcode on Display board. Default true. */
    let enableDisplayHidBarcode = $state(true);
    /** Per barcode-hid: enable HID barcode on kiosk. Default true. */
    let enablePublicTriageHidBarcode = $state(true);
    /** Camera / QR on kiosk. */
    let enablePublicTriageCameraScanner = $state(true);
    /** Per plan: enable camera/QR scanner on Display board. Default true. */
    let enableDisplayCameraScanner = $state(true);
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
            maxNoShowAttempts = Math.max(1, Math.min(10, Number((s as { max_no_show_attempts?: number }).max_no_show_attempts ?? 3)));
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
            settingsDisplayTtsRepeatDelaySec = Math.max(
                0.5,
                Math.min(
                    10,
                    (Number(s.display_tts_repeat_delay_ms ?? 2000) / 1000),
                ),
            );
            {
                const kss = (s as { kiosk_self_service_triage_enabled?: boolean })
                    .kiosk_self_service_triage_enabled;
                allowPublicTriage =
                    kss !== undefined ? kss === true : s.allow_public_triage === true;
            }
            kioskStatusCheckerEnabled =
                (s as { kiosk_status_checker_enabled?: boolean }).kiosk_status_checker_enabled !== false;
            const rawMode = s.identity_binding_mode;
            const allowUnverified =
                (s as { allow_unverified_entry?: boolean })
                    .allow_unverified_entry === true;
            if (rawMode === "disabled" || !rawMode) {
                identityPolicy = "none";
                identityBindingMode = "disabled";
                allowUnverifiedEntry = false;
            } else {
                identityPolicy = "required";
                identityBindingMode = "required";
                allowUnverifiedEntry = allowUnverified;
            }
            enableDisplayHidBarcode = (s.enable_display_hid_barcode ?? true) === true;
            enablePublicTriageHidBarcode =
                (s as { kiosk_enable_hid_barcode?: boolean }).kiosk_enable_hid_barcode !== undefined
                    ? (s as { kiosk_enable_hid_barcode?: boolean }).kiosk_enable_hid_barcode === true
                    : (s.enable_public_triage_hid_barcode ?? true) === true;
            enablePublicTriageCameraScanner =
                (s as { kiosk_enable_camera_scanner?: boolean }).kiosk_enable_camera_scanner !== undefined
                    ? (s as { kiosk_enable_camera_scanner?: boolean }).kiosk_enable_camera_scanner !== false
                    : (s as { enable_public_triage_camera_scanner?: boolean })
                          .enable_public_triage_camera_scanner !== false;
            enableDisplayCameraScanner = (s.enable_display_camera_scanner ?? true) === true;
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
            publicPagePublished = (program as { is_published?: boolean }).is_published !== false;
            const pk = (s as { public_access_key?: string | null }).public_access_key;
            publicPagePrivate = pk != null && pk !== "";
            publicPageKey = pk ?? "";
            publicPageExpiryHours = Math.max(1, Math.min(168, Number((s as { public_access_expiry_hours?: number }).public_access_expiry_hours ?? 24)));
            publicPageDescription = (s as { page_description?: string | null }).page_description ?? "";
            publicPageAnnouncement = (s as { page_announcement?: string | null }).page_announcement ?? "";
            publicPageBannerUrl = (s as { page_banner_image_url?: string | null }).page_banner_image_url ?? null;
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

    // Tokens tab: fetch program tokens when tab is selected (Phase C.3). Assigned only, paginated.
    $effect(() => {
        if (activeTab !== "tokens" || !program?.id) return;
        const page = tokenPage;
        let cancelled = false;
        tokensLoading = true;
        const check419 = (r: Response) => {
            if (r.status === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return Promise.resolve({});
            }
            return r.json().catch(() => ({}));
        };
        const params = new URLSearchParams({
            per_page: String(TOKENS_PER_PAGE),
            page: String(page),
            assigned_only: "1",
        });
        fetch(`/api/admin/programs/${program.id}/tokens?${params.toString()}`, {
            headers: {
                Accept: "application/json",
                "X-CSRF-TOKEN": getCsrfToken(),
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
        })
            .then(check419)
            .then((data: { tokens?: TokenItem[]; meta?: { current_page: number; last_page: number; total: number; per_page: number } }) => {
                if (cancelled) return;
                programTokens = (data?.tokens ?? []) as TokenItem[];
                tokenMeta = data?.meta ?? null;
            })
            .catch(() => {
                if (!cancelled) {
                    programTokens = [];
                    tokenMeta = null;
                    toaster.error({ title: MSG_NETWORK_ERROR });
                }
            })
            .finally(() => {
                if (!cancelled) tokensLoading = false;
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

    // Tokens tab state (Phase C.3 — read-only list; assign/unassign from Tokens page)
    let programTokens = $state<TokenItem[]>([]);
    let tokensLoading = $state(false);
    let tokenPage = $state(1);
    const TOKENS_PER_PAGE = 10;
    let tokenMeta = $state<{ current_page: number; last_page: number; total: number; per_page: number } | null>(null);

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
    const allowCustomPronunciation = $derived(
        (get(page)?.props as { tts_allow_custom_pronunciation?: boolean } | undefined)?.tts_allow_custom_pronunciation !== false,
    );
    const segment2Enabled = $derived(
        (get(page)?.props as { tts_segment_2_enabled?: boolean } | undefined)?.tts_segment_2_enabled !== false,
    );
    const serverTtsConfigured = $derived((get(page)?.props as { server_tts_configured?: boolean } | undefined)?.server_tts_configured ?? true);
    /** Per docs/final-edge-mode-rush-plann.md [DF-18]: edge mode from shared props for read-only UI and sync card. */
    const edgeMode = $derived(($page?.props as { edge_mode?: { is_edge?: boolean; admin_read_only?: boolean } } | undefined)?.edge_mode ?? null);
    let edgeSyncing = $state(false);

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

    /** Per docs/final-edge-mode-rush-plann.md [DF-18]: trigger edge package import from Programs/Show. */
    async function triggerEdgeSync() {
        if (!program?.id || edgeSyncing) return;
        edgeSyncing = true;
        const res = await fetch("/api/admin/edge/import", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": getCsrfToken(),
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
            body: JSON.stringify({ program_id: program.id }),
        });
        if (!res.ok && res.status !== 409) {
            toaster.error({ title: "Failed to trigger sync." });
            edgeSyncing = false;
        }
        // Don't reset edgeSyncing — EdgeModeBanner handles progress display; reload will reset UI
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
        if (ok) {
            const warning = (data as { warning?: string } | undefined)?.warning;
            if (warning) {
                toaster.warning({ title: warning });
            }
            router.reload();
        } else {
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
        showCreateProcessModal = false;
        editStation = null;
        stationTtsAudioPlaying = null;
        createStationTtsAudioPlaying = null;
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
        createStationGenerateAudio = autoGenerateStationTts;
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

    function closeProgramConnectorTtsModal() {
        showProgramConnectorTtsModal = false;
        connectorTtsAudioPlaying = null;
    }

    function normalizeStationTtsStatus(raw?: string | null): StationTtsUiStatus {
        if (raw === "ready") return "ready";
        if (raw === "failed") return "failed";
        if (raw === "generating") return "generating";
        return "not_generated";
    }

    function getStationLanguageTtsStatus(
        station: StationItem,
        lang: StationTtsLangKey,
    ): StationTtsUiStatus {
        const langs = station.tts?.languages as
            | Record<string, { status?: string }>
            | undefined;
        return normalizeStationTtsStatus(langs?.[lang]?.status);
    }

    function getStationLanguageTtsStatuses(
        station: StationItem,
    ): Record<StationTtsLangKey, StationTtsUiStatus> {
        return {
            en: getStationLanguageTtsStatus(station, "en"),
            fil: getStationLanguageTtsStatus(station, "fil"),
            ilo: getStationLanguageTtsStatus(station, "ilo"),
        };
    }

    function getStationTtsButtonLabel(station: StationItem): string {
        const statuses = Object.values(getStationLanguageTtsStatuses(station));
        if (statuses.some((s) => s === "ready" || s === "failed")) return "Regenerate station TTS";
        return "Generate station TTS";
    }

    function stationTtsStatusClass(status: StationTtsUiStatus): string {
        if (status === "ready") return "bg-success-50 border-success-200 text-success-700";
        if (status === "failed") return "bg-error-50 border-error-200 text-error-700";
        if (status === "generating") return "bg-primary-50 border-primary-200 text-primary-700";
        return "bg-surface-100 border-surface-200 text-surface-600";
    }

    function stationTtsStatusLabel(status: StationTtsUiStatus): string {
        if (status === "ready") return "Generated";
        if (status === "generating") return "Generating";
        return "Not generated";
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

    /** Station directions only (segment 2) for the station open in the edit modal. */
    async function playStationDirectionsSample(lang: StationTtsLangKey) {
        if (stationTtsAudioPlaying || !editStation || !program) return;
        stationTtsAudioPlaying = { mode: "sample", lang };
        try {
            const connectorRaw =
                (lang === "en"
                    ? connectorTts.en.connector_phrase
                    : lang === "fil"
                      ? connectorTts.fil.connector_phrase
                      : connectorTts.ilo.connector_phrase) ?? "";
            const connectorPhrase = connectorRaw.trim();
            const config = lang === "en" ? editStationTts.en : lang === "fil" ? editStationTts.fil : editStationTts.ilo;
            const stationPhrase = allowCustomPronunciation ? (config.station_phrase ?? "").trim() : "";
            const pr = await previewSegment2Text({
                lang,
                connector_phrase: connectorPhrase,
                station_name: (editStation.name || "Station").trim(),
                station_phrase: stationPhrase || undefined,
                getCsrfToken: () => getCsrfToken(),
            });
            if (pr.status === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return;
            }
            if (!pr.ok || !pr.text) {
                toaster.error({ title: "Could not build preview phrase." });
                return;
            }
            const voiceId = (config.voice_id && config.voice_id.trim()) || "";
            const preview = await playAdminTtsPreview({ text: pr.text, rate: config.rate, voiceId });
            if (preview.code === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return;
            }
            if (!preview.ok) {
                toaster.error({ title: "Failed to play TTS sample." });
            }
        } catch (e) {
            const isNetwork = e instanceof TypeError && (e as Error).message === "Failed to fetch";
            toaster.error({ title: isNetwork ? MSG_NETWORK_ERROR : "Failed to play TTS sample." });
        } finally {
            stationTtsAudioPlaying = null;
        }
    }

    /** Full call: sample token (A1, site defaults) + this station’s directions. */
    async function playStationFullAnnouncementSample(lang: StationTtsLangKey) {
        if (stationTtsAudioPlaying || !editStation || !program) return;
        stationTtsAudioPlaying = { mode: "full", lang };
        try {
            const connectorRaw =
                (lang === "en"
                    ? connectorTts.en.connector_phrase
                    : lang === "fil"
                      ? connectorTts.fil.connector_phrase
                      : connectorTts.ilo.connector_phrase) ?? "";
            const connectorPhrase = connectorRaw.trim();
            const config = lang === "en" ? editStationTts.en : lang === "fil" ? editStationTts.fil : editStationTts.ilo;
            const stationPhrase = allowCustomPronunciation ? (config.station_phrase ?? "").trim() : "";
            const voiceId = (config.voice_id && config.voice_id.trim()) || "";
            const res = await playAdminFullAnnouncementPreview({
                getCsrfToken: () => getCsrfToken(),
                lang,
                rate: config.rate,
                voiceId,
                segment2Enabled: true,
                segment1: { alias: "A1", pronounce_as: "letters" },
                connectorPhrase,
                stationName: (editStation.name || "Station").trim(),
                stationPhrase: stationPhrase || undefined,
            });
            if (res.code === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return;
            }
            if (!res.ok) {
                const msg =
                    res.step === "segment1"
                        ? "Could not build token call."
                        : res.step === "segment2"
                          ? "Could not build station directions."
                          : "Failed to play full preview.";
                toaster.error({ title: msg });
            }
        } catch (e) {
            const isNetwork = e instanceof TypeError && (e as Error).message === "Failed to fetch";
            toaster.error({ title: isNetwork ? MSG_NETWORK_ERROR : "Failed to play full preview." });
        } finally {
            stationTtsAudioPlaying = null;
        }
    }

    async function playCreateStationDirectionsSample(lang: StationTtsLangKey) {
        if (createStationTtsAudioPlaying || !program) return;
        const nm = createStationName.trim();
        if (!nm) {
            toaster.error({ title: "Enter a station name first." });
            return;
        }
        createStationTtsAudioPlaying = { mode: "sample", lang };
        try {
            const connectorRaw =
                (lang === "en"
                    ? connectorTts.en.connector_phrase
                    : lang === "fil"
                      ? connectorTts.fil.connector_phrase
                      : connectorTts.ilo.connector_phrase) ?? "";
            const connectorPhrase = connectorRaw.trim();
            const config = lang === "en" ? createStationTts.en : lang === "fil" ? createStationTts.fil : createStationTts.ilo;
            const stationPhrase = allowCustomPronunciation ? (config.station_phrase ?? "").trim() : "";
            const pr = await previewSegment2Text({
                lang,
                connector_phrase: connectorPhrase,
                station_name: nm,
                station_phrase: stationPhrase || undefined,
                getCsrfToken: () => getCsrfToken(),
            });
            if (pr.status === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return;
            }
            if (!pr.ok || !pr.text) {
                toaster.error({ title: "Could not build preview phrase." });
                return;
            }
            const voiceId = (config.voice_id && config.voice_id.trim()) || "";
            const preview = await playAdminTtsPreview({ text: pr.text, rate: config.rate, voiceId });
            if (preview.code === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return;
            }
            if (!preview.ok) {
                toaster.error({ title: "Failed to play TTS sample." });
            }
        } catch (e) {
            const isNetwork = e instanceof TypeError && (e as Error).message === "Failed to fetch";
            toaster.error({ title: isNetwork ? MSG_NETWORK_ERROR : "Failed to play TTS sample." });
        } finally {
            createStationTtsAudioPlaying = null;
        }
    }

    async function playCreateStationFullSample(lang: StationTtsLangKey) {
        if (createStationTtsAudioPlaying || !program) return;
        const nm = createStationName.trim();
        if (!nm) {
            toaster.error({ title: "Enter a station name first." });
            return;
        }
        createStationTtsAudioPlaying = { mode: "full", lang };
        try {
            const connectorRaw =
                (lang === "en"
                    ? connectorTts.en.connector_phrase
                    : lang === "fil"
                      ? connectorTts.fil.connector_phrase
                      : connectorTts.ilo.connector_phrase) ?? "";
            const connectorPhrase = connectorRaw.trim();
            const config = lang === "en" ? createStationTts.en : lang === "fil" ? createStationTts.fil : createStationTts.ilo;
            const stationPhrase = allowCustomPronunciation ? (config.station_phrase ?? "").trim() : "";
            const voiceId = (config.voice_id && config.voice_id.trim()) || "";
            const res = await playAdminFullAnnouncementPreview({
                getCsrfToken: () => getCsrfToken(),
                lang,
                rate: config.rate,
                voiceId,
                segment2Enabled: true,
                segment1: { alias: "A1", pronounce_as: "letters" },
                connectorPhrase,
                stationName: nm,
                stationPhrase: stationPhrase || undefined,
            });
            if (res.code === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return;
            }
            if (!res.ok) {
                toaster.error({
                    title:
                        res.step === "segment1"
                            ? "Could not build token call."
                            : res.step === "segment2"
                              ? "Could not build station directions."
                              : "Failed to play full preview.",
                });
            }
        } catch (e) {
            const isNetwork = e instanceof TypeError && (e as Error).message === "Failed to fetch";
            toaster.error({ title: isNetwork ? MSG_NETWORK_ERROR : "Failed to play full preview." });
        } finally {
            createStationTtsAudioPlaying = null;
        }
    }

    /** Connector phrase only. */
    async function playConnectorTtsSample(lang: TtsLangKey) {
        if (connectorTtsAudioPlaying || submitting) return;
        const config = lang === "en" ? connectorTts.en : lang === "fil" ? connectorTts.fil : connectorTts.ilo;
        const sampleText = (config.connector_phrase ?? "").trim();
        if (!sampleText) {
            toaster.error({ title: "Enter a connector phrase to preview." });
            return;
        }
        connectorTtsAudioPlaying = { mode: "sample", lang };
        try {
            const voiceId = (config.voice_id && config.voice_id.trim()) || "";
            const preview = await playAdminTtsPreview({ text: sampleText, rate: config.rate, voiceId });
            if (preview.code === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return;
            }
            if (!preview.ok) {
                toaster.error({ title: "Failed to play TTS sample." });
            }
        } catch (e) {
            const isNetwork = e instanceof TypeError && (e as Error).message === "Failed to fetch";
            toaster.error({ title: isNetwork ? MSG_NETWORK_ERROR : "Failed to play TTS sample." });
        } finally {
            connectorTtsAudioPlaying = null;
        }
    }

    /** Full call using this connector + placeholder station (Window 1). */
    async function playConnectorTtsFullSample(lang: TtsLangKey) {
        if (connectorTtsAudioPlaying || submitting) return;
        const config = lang === "en" ? connectorTts.en : lang === "fil" ? connectorTts.fil : connectorTts.ilo;
        connectorTtsAudioPlaying = { mode: "full", lang };
        try {
            const voiceId = (config.voice_id && config.voice_id.trim()) || "";
            const res = await playAdminFullAnnouncementPreview({
                getCsrfToken: () => getCsrfToken(),
                lang,
                rate: config.rate,
                voiceId,
                segment2Enabled: true,
                segment1: { alias: "A1", pronounce_as: "letters" },
                connectorPhrase: (config.connector_phrase ?? "").trim(),
                stationName: "Window 1",
            });
            if (res.code === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return;
            }
            if (!res.ok) {
                toaster.error({
                    title:
                        res.step === "segment1"
                            ? "Could not build token call."
                            : res.step === "segment2"
                              ? "Could not build directions."
                              : "Failed to play full preview.",
                });
            }
        } catch (e) {
            const isNetwork = e instanceof TypeError && (e as Error).message === "Failed to fetch";
            toaster.error({ title: isNetwork ? MSG_NETWORK_ERROR : "Failed to play full preview." });
        } finally {
            connectorTtsAudioPlaying = null;
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
                generate_tts: createStationGenerateAudio,
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
            closeCreateProcessModal();
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

    function openCreateProcessModal() {
        createProcessName = "";
        createProcessDescription = "";
        createProcessExpectedTimeMmSs = "";
        showCreateProcessModal = true;
    }

    function closeCreateProcessModal() {
        showCreateProcessModal = false;
        createProcessName = "";
        createProcessDescription = "";
        createProcessExpectedTimeMmSs = "";
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
        let effectiveIdentityMode: "disabled" | "required" = "disabled";
        let effectiveAllowUnverified = false;
        if (identityPolicy === "required") {
            effectiveIdentityMode = "required";
            effectiveAllowUnverified = allowUnverifiedEntry;
        }
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
                    max_no_show_attempts: Math.max(1, Math.min(10, Math.floor(Number(maxNoShowAttempts) || 3))),
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
                    display_tts_repeat_count: Math.max(
                        1,
                        Math.min(
                            3,
                            Math.floor(
                                Number(settingsDisplayTtsRepeatCount) || 1,
                            ),
                        ),
                    ),
                    display_tts_repeat_delay_ms: Math.round(
                        settingsDisplayTtsRepeatDelaySec * 1000,
                    ),
                    allow_public_triage: allowPublicTriage,
                    kiosk_self_service_triage_enabled: allowPublicTriage,
                    kiosk_status_checker_enabled: kioskStatusCheckerEnabled,
                    kiosk_enable_hid_barcode: enablePublicTriageHidBarcode,
                    kiosk_enable_camera_scanner: enablePublicTriageCameraScanner,
                    kiosk_modal_idle_seconds: displayScanTimeoutSeconds,
                    allow_unverified_entry: effectiveAllowUnverified,
                    identity_binding_mode: effectiveIdentityMode,
                    enable_display_hid_barcode: enableDisplayHidBarcode,
                    enable_public_triage_hid_barcode:
                        enablePublicTriageHidBarcode,
                    enable_public_triage_camera_scanner: enablePublicTriageCameraScanner,
                    enable_display_camera_scanner: enableDisplayCameraScanner,
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
        maxNoShowAttempts = Math.max(1, Math.min(10, Number((s as { max_no_show_attempts?: number }).max_no_show_attempts ?? 3)));
        displayAudioMuted = (s as { display_audio_muted?: boolean }).display_audio_muted === true;
        displayAudioVolume = Math.max(
            0,
            Math.min(
                1,
                Number((s as { display_audio_volume?: number }).display_audio_volume ?? 1),
            ),
        );
        settingsDisplayTtsRepeatCount = Math.max(
            1,
            Math.min(
                3,
                Math.floor(
                    Number(
                        (s as { display_tts_repeat_count?: number }).display_tts_repeat_count ??
                            1,
                    ),
                ),
            ),
        );
        settingsDisplayTtsRepeatDelaySec = Math.max(
            0.5,
            Math.min(
                10,
                (Number(
                    (s as { display_tts_repeat_delay_ms?: number })
                        .display_tts_repeat_delay_ms ?? 2000,
                ) /
                    1000),
            ),
        );
        {
            const kss = (s as { kiosk_self_service_triage_enabled?: boolean })
                .kiosk_self_service_triage_enabled;
            allowPublicTriage =
                kss !== undefined ? kss === true : (s as { allow_public_triage?: boolean }).allow_public_triage === true;
        }
        kioskStatusCheckerEnabled =
            (s as { kiosk_status_checker_enabled?: boolean }).kiosk_status_checker_enabled !== false;
        const rawMode = (s as { identity_binding_mode?: string }).identity_binding_mode;
        const allowUnverified =
            (s as { allow_unverified_entry?: boolean }).allow_unverified_entry === true;
        if (rawMode === "disabled" || !rawMode) {
            identityPolicy = "none";
            identityBindingMode = "disabled";
            allowUnverifiedEntry = false;
        } else {
            identityPolicy = "required";
            identityBindingMode = "required";
            allowUnverifiedEntry = allowUnverified;
        }
        enableDisplayHidBarcode = (s as { enable_display_hid_barcode?: boolean })
            .enable_display_hid_barcode !== false;
        enablePublicTriageHidBarcode =
            (s as { kiosk_enable_hid_barcode?: boolean }).kiosk_enable_hid_barcode !== undefined
                ? (s as { kiosk_enable_hid_barcode?: boolean }).kiosk_enable_hid_barcode === true
                : (s as { enable_public_triage_hid_barcode?: boolean }).enable_public_triage_hid_barcode !== false;
        enablePublicTriageCameraScanner =
            (s as { kiosk_enable_camera_scanner?: boolean }).kiosk_enable_camera_scanner !== undefined
                ? (s as { kiosk_enable_camera_scanner?: boolean }).kiosk_enable_camera_scanner !== false
                : (s as { enable_public_triage_camera_scanner?: boolean })
                      .enable_public_triage_camera_scanner !== false;
        enableDisplayCameraScanner = (s as { enable_display_camera_scanner?: boolean })
            .enable_display_camera_scanner !== false;
        const tts = (s as { tts?: { active_language?: string } }).tts;
        const lang = (tts?.active_language as string | undefined) ?? "en";
        ttsActiveLanguage = (["en", "fil", "ilo"].includes(lang) ? lang : "en") as TtsLangKey;
    }

    async function savePublicPage() {
        if (!program) return;
        publicPageSaving = true;
        const current = (program.settings ?? {}) as Record<string, unknown>;
        const { ok, message: msg } = await api("PUT", `/api/admin/programs/${program.id}`, {
            name: program.name,
            description: program.description,
            is_published: publicPagePublished,
            settings: {
                ...current,
                public_access_key: publicPagePrivate ? (publicPageKey || null) : null,
                public_access_expiry_hours: publicPageExpiryHours,
                page_description: publicPageDescription || null,
                page_announcement: publicPageAnnouncement || null,
            },
        });
        publicPageSaving = false;
        if (ok) {
            toaster.success({ title: "Public page settings saved." });
            router.reload();
        } else toaster.error({ title: msg ?? "Failed to save." });
    }

    async function generateQr(type: "public" | "private_prompt" | "private_scannable") {
        if (!program) return;
        publicPageSavingQr = true;
        const { ok, data } = await api("POST", `/api/admin/programs/${program.id}/generate-qr`, { type });
        publicPageSavingQr = false;
        if (ok && data) {
            toaster.success({ title: "QR link generated." });
            router.reload();
        } else toaster.error({ title: "Failed to generate QR." });
    }

    function copyShortUrl(url: string) {
        navigator.clipboard?.writeText(url).then(() => toaster.success({ title: "Link copied." })).catch(() => {});
    }

    async function uploadBannerFile(file: File) {
        if (!program) return;
        publicPageBannerUploading = true;
        try {
            const compressed = await compressImage(file, HERO_BANNER_PRESET);
            const fd = new FormData();
            fd.append("image", compressed);
            const res = await fetch(`/api/admin/programs/${program.id}/banner-image`, { method: "POST", body: fd, credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" } });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data?.url) {
                publicPageBannerUrl = data.url;
                router.reload();
            } else toaster.error({ title: "Upload failed." });
        } finally {
            publicPageBannerUploading = false;
        }
    }

    function handleBannerUpload(e: Event) {
        const input = e.target as HTMLInputElement;
        const file = input?.files?.[0];
        if (file) uploadBannerFile(file);
        input.value = "";
    }

    function handleBannerReplace(e: Event) {
        handleBannerUpload(e);
    }

    async function handleBannerRemove() {
        if (!program) return;
        const { ok } = await api("DELETE", `/api/admin/programs/${program.id}/banner-image`);
        if (ok) {
            publicPageBannerUrl = null;
            router.reload();
        } else toaster.error({ title: "Failed to remove." });
    }

    async function fetchAccessTokens() {
        if (!program) return;
        const { ok, data } = await api("GET", `/api/admin/programs/${program.id}/access-tokens`);
        if (ok && data && typeof (data as { active_count?: number }).active_count === "number") {
            const d = data as { active_count: number; tokens: { id: number; token_ref: string; issued_at: string; expires_at: string }[] };
            accessTokensCount = d.active_count;
            accessTokensList = d.tokens ?? [];
        }
    }

    async function revokeToken(id: number) {
        if (!program) return;
        const { ok } = await api("DELETE", `/api/admin/programs/${program.id}/access-tokens/${id}`);
        if (ok) {
            await fetchAccessTokens();
        } else toaster.error({ title: "Failed to revoke." });
    }

    async function revokeAllTokens() {
        if (!program || !confirm("Revoke all tokens? All devices will need to re-enter the program key.")) return;
        const { ok } = await api("DELETE", `/api/admin/programs/${program.id}/access-tokens`);
        if (ok) {
            toaster.success({ title: "All tokens revoked." });
            await fetchAccessTokens();
        } else toaster.error({ title: "Failed to revoke." });
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
    <div class="flex flex-col gap-4">
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

        <!-- Edge lock alert -->
        {#if program?.edge_locked_by_device_id}
            <div class="alert preset-tonal-warning flex items-start gap-3 mb-4" role="alert">
                <span class="text-xl flex-shrink-0">🔒</span>
                <div>
                    <p class="font-semibold text-sm">Edge-locked by {program.edge_locked_by_device_name ?? "an edge device"}</p>
                    <p class="text-xs text-surface-600 mt-0.5">
                        This program is assigned to an edge device. Go to Site Settings → Edge Devices to unassign it before starting a session from central.
                    </p>
                </div>
            </div>
        {/if}

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

        <!-- Tab navigation: one slim row; scroll arrows in left/right gutter, vertically centered (gray, match StatusFooter). -->
        <nav
            aria-label="Program sections"
            class="sticky top-0 z-20 -mx-4 pl-4 pr-4 sm:mx-0 sm:px-0 py-0.5 bg-surface-50 border border-surface-200 shadow-sm rounded-container sm:border sm:border-surface-200 sm:bg-surface-100/80 -mt-2"
        >
            <div class="relative flex items-center w-full">
                {#if canScrollLeft}
                    <button
                        type="button"
                        aria-label="Scroll tabs left"
                        class="absolute left-0 inset-y-0 z-10 flex items-center justify-center w-10 rounded-l-lg bg-surface-100/95 text-surface-700 hover:bg-surface-200/90 transition-colors shrink-0 animate-pulse border border-surface-200/80 border-r-0"
                        onclick={() => scrollTabList("left")}
                    >
                        <ChevronLeft class="w-5 h-5 drop-shadow-sm" />
                    </button>
                {/if}
                <div
                    role="tablist"
                    tabindex="-1"
                    bind:this={tabListEl}
                    class="flex gap-1 overflow-x-auto overflow-y-hidden flex-nowrap w-full max-w-full min-h-0 py-0.5 sm:px-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                    onkeydown={handleTabKeydown}
                >
                    <button type="button" role="tab" id="tab-overview" tabindex={activeTab === 'overview' ? 0 : -1} aria-selected={activeTab === 'overview'} aria-controls="tabpanel-overview" class="tab flex-shrink-0 whitespace-nowrap px-3 py-1 flex items-center justify-center rounded-lg font-medium text-sm transition-colors {activeTab === 'overview' ? 'bg-primary-500 text-primary-contrast-500 shadow-sm' : 'bg-transparent text-surface-700 hover:bg-surface-200/80'}" onclick={() => (activeTab = "overview")}>Overview</button>
                    <button type="button" role="tab" id="tab-public-page" tabindex={activeTab === 'public-page' ? 0 : -1} aria-selected={activeTab === 'public-page'} aria-controls="tabpanel-public-page" class="tab flex-shrink-0 whitespace-nowrap px-3 py-1 flex items-center justify-center rounded-lg font-medium text-sm transition-colors {activeTab === 'public-page' ? 'bg-primary-500 text-primary-contrast-500 shadow-sm' : 'bg-transparent text-surface-700 hover:bg-surface-200/80'}" onclick={() => (activeTab = "public-page")}>Public Page</button>
                    <button type="button" role="tab" id="tab-processes" tabindex={activeTab === 'processes' ? 0 : -1} aria-selected={activeTab === 'processes'} aria-controls="tabpanel-processes" class="tab flex-shrink-0 whitespace-nowrap px-3 py-1 flex items-center justify-center rounded-lg font-medium text-sm transition-colors {activeTab === 'processes' ? 'bg-primary-500 text-primary-contrast-500 shadow-sm' : 'bg-transparent text-surface-700 hover:bg-surface-200/80'}" onclick={() => (activeTab = "processes")}>Processes</button>
                    <button type="button" role="tab" id="tab-stations" tabindex={activeTab === 'stations' ? 0 : -1} aria-selected={activeTab === 'stations'} aria-controls="tabpanel-stations" class="tab flex-shrink-0 whitespace-nowrap px-3 py-1 flex items-center justify-center rounded-lg font-medium text-sm transition-colors {activeTab === 'stations' ? 'bg-primary-500 text-primary-contrast-500 shadow-sm' : 'bg-transparent text-surface-700 hover:bg-surface-200/80'}" onclick={() => (activeTab = "stations")}>Stations</button>
                    <button type="button" role="tab" id="tab-staff" tabindex={activeTab === 'staff' ? 0 : -1} aria-selected={activeTab === 'staff'} aria-controls="tabpanel-staff" class="tab flex-shrink-0 whitespace-nowrap px-3 py-1 flex items-center justify-center rounded-lg font-medium text-sm transition-colors {activeTab === 'staff' ? 'bg-primary-500 text-primary-contrast-500 shadow-sm' : 'bg-transparent text-surface-700 hover:bg-surface-200/80'}" onclick={() => (activeTab = "staff")}>Staff</button>
                    <button type="button" role="tab" id="tab-tokens" tabindex={activeTab === 'tokens' ? 0 : -1} aria-selected={activeTab === 'tokens'} aria-controls="tabpanel-tokens" class="tab flex-shrink-0 whitespace-nowrap px-3 py-1 flex items-center justify-center rounded-lg font-medium text-sm transition-colors {activeTab === 'tokens' ? 'bg-primary-500 text-primary-contrast-500 shadow-sm' : 'bg-transparent text-surface-700 hover:bg-surface-200/80'}" onclick={() => (activeTab = "tokens")}>Tokens</button>
                    <button type="button" role="tab" id="tab-tracks" tabindex={activeTab === 'tracks' ? 0 : -1} aria-selected={activeTab === 'tracks'} aria-controls="tabpanel-tracks" class="tab flex-shrink-0 whitespace-nowrap px-3 py-1 flex items-center justify-center rounded-lg font-medium text-sm transition-colors {activeTab === 'tracks' ? 'bg-primary-500 text-primary-contrast-500 shadow-sm' : 'bg-transparent text-surface-700 hover:bg-surface-200/80'}" onclick={() => (activeTab = "tracks")}>Track</button>
                    <button type="button" role="tab" id="tab-diagram" tabindex={activeTab === 'diagram' ? 0 : -1} aria-selected={activeTab === 'diagram'} aria-controls="tabpanel-diagram" class="tab flex-shrink-0 whitespace-nowrap px-3 py-1 flex items-center justify-center rounded-lg font-medium text-sm transition-colors {activeTab === 'diagram' ? 'bg-primary-500 text-primary-contrast-500 shadow-sm' : 'bg-transparent text-surface-700 hover:bg-surface-200/80'}" onclick={() => (activeTab = "diagram")}>Diagram</button>
                    <button type="button" role="tab" id="tab-settings" tabindex={activeTab === 'settings' ? 0 : -1} aria-selected={activeTab === 'settings'} aria-controls="tabpanel-settings" class="tab flex-shrink-0 whitespace-nowrap px-3 py-1 flex items-center justify-center rounded-lg font-medium text-sm transition-colors {activeTab === 'settings' ? 'bg-primary-500 text-primary-contrast-500 shadow-sm' : 'bg-transparent text-surface-700 hover:bg-surface-200/80'}" onclick={() => (activeTab = "settings")}>Settings</button>
                </div>
                {#if canScrollRight}
                    <button
                        type="button"
                        aria-label="Scroll tabs right"
                        class="absolute right-0 inset-y-0 z-10 flex items-center justify-center w-10 rounded-r-lg bg-surface-100/95 text-surface-700 hover:bg-surface-200/90 transition-colors shrink-0 animate-pulse border border-surface-200/80 border-l-0"
                        onclick={() => scrollTabList("right")}
                    >
                        <ChevronRight class="w-5 h-5 drop-shadow-sm" />
                    </button>
                {/if}
            </div>
        </nav>


        {#if activeTab === "overview"}
            <div id="tabpanel-overview" role="tabpanel" aria-labelledby="tab-overview" tabindex="-1" class="space-y-8">
                {#if edgeMode?.is_edge}
                    <div class="rounded-container bg-warning-50 dark:bg-warning-900/30 border border-warning-200 dark:border-warning-700 p-4 flex flex-wrap items-center justify-between gap-3 mb-4">
                        <div>
                            <p class="text-sm font-semibold text-warning-700 dark:text-warning-300">Edge Package</p>
                            <p class="text-xs text-warning-600 dark:text-warning-400 mt-0.5">
                                This program was synced from the central server.
                                Re-sync to get the latest configuration.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="btn preset-tonal btn-sm touch-target-h"
                            onclick={triggerEdgeSync}
                            disabled={edgeSyncing}
                        >
                            {edgeSyncing ? "Syncing…" : "Re-sync from central"}
                        </button>
                    </div>
                {/if}
                {#if rbac_team}
                    <ScopedRbacTeamAccessPanel rbacTeam={rbac_team} />
                {/if}
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
        {:else if activeTab === "public-page"}
            <div id="tabpanel-public-page" role="tabpanel" aria-labelledby="tab-public-page" tabindex="-1" class="space-y-8 max-w-3xl">
                <section class="rounded-container bg-surface-50 border border-surface-200 shadow-sm p-5 space-y-4">
                    <h2 class="text-lg font-semibold text-surface-950">Visibility</h2>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" class="checkbox" bind:checked={publicPagePublished} />
                        <span class="text-sm text-surface-700">Published (show on site landing)</span>
                    </label>
                    {#if !publicPagePublished}
                        <p class="text-sm text-warning-700 dark:text-warning-400">This program will not appear on the site landing page.</p>
                    {/if}
                    <label class="flex items-center gap-2">
                        <input type="checkbox" class="checkbox" bind:checked={publicPagePrivate} />
                        <span class="text-sm text-surface-700">Private (require program key)</span>
                    </label>
                    {#if publicPagePrivate}
                        <div class="form-control">
                            <label class="label text-sm text-surface-600">Program key</label>
                            <input type="text" class="input input-md max-w-xs" maxlength="20" placeholder="e.g. AICS-PRIV" bind:value={publicPageKey} />
                            <p class="text-xs text-surface-500 mt-1">Clients must enter this key to access the program. It will not appear on the site landing.</p>
                        </div>
                        <div class="form-control">
                            <label class="label text-sm text-surface-600">Access expiry</label>
                            <select class="select select-theme select-md max-w-xs" bind:value={publicPageExpiryHours}>
                                <option value={8}>8 hours</option>
                                <option value={24}>24 hours</option>
                                <option value={48}>48 hours</option>
                                <option value={72}>72 hours</option>
                                <option value={168}>1 week</option>
                            </select>
                        </div>
                    {/if}
                </section>
                <section class="rounded-container bg-surface-50 border border-surface-200 shadow-sm p-5 space-y-4">
                    <h2 class="text-lg font-semibold text-surface-950">Program public landing</h2>
                    <p class="text-sm text-surface-600 -mt-1 mb-2">Content for the public program info page (when users open this program from the site landing).</p>
                    <div class="form-control">
                        <label class="label text-sm text-surface-600">Description</label>
                        <textarea class="textarea textarea-md max-h-32" maxlength="500" placeholder="Short description" bind:value={publicPageDescription}></textarea>
                    </div>
                    <div class="form-control">
                        <label class="label text-sm text-surface-600">Announcement (prominent, time-sensitive)</label>
                        <textarea class="textarea textarea-md max-h-24" maxlength="200" placeholder="e.g. Open until 4PM today" bind:value={publicPageAnnouncement}></textarea>
                    </div>
                    <div class="form-control">
                        <label class="label text-sm text-surface-600">Banner image</label>
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="hidden"
                            bind:this={bannerInputEl}
                            onchange={handleBannerUpload}
                        />
                        {#if publicPageBannerUrl}
                            <div class="flex items-center gap-3">
                                <img src={publicPageBannerUrl} alt="" class="h-20 w-auto rounded object-cover" />
                                <div class="flex gap-2">
                                    <button type="button" class="btn variant-outline btn-sm" disabled={publicPageBannerUploading} onclick={() => bannerInputEl?.click()}>Replace</button>
                                    <button type="button" class="btn variant-ghost-error btn-sm" onclick={handleBannerRemove}>Remove</button>
                                </div>
                            </div>
                        {:else}
                            <div
                                class="border-2 border-dashed rounded-container p-6 text-center transition-colors cursor-pointer {bannerDragging ? 'border-primary-500 bg-primary-50' : 'border-surface-300 hover:border-primary-400 bg-surface-50/50 hover:bg-surface-100/50'}"
                                role="button"
                                tabindex="0"
                                aria-label="Upload banner image"
                                ondragover={(e) => { e.preventDefault(); bannerDragging = true; }}
                                ondragleave={() => (bannerDragging = false)}
                                ondrop={(e) => {
                                    e.preventDefault();
                                    bannerDragging = false;
                                    const file = e.dataTransfer?.files?.[0];
                                    if (file && file.type?.startsWith('image/')) uploadBannerFile(file);
                                }}
                                onclick={() => bannerInputEl?.click()}
                                onkeydown={(e) => e.key === 'Enter' && bannerInputEl?.click()}
                            >
                                <div class="flex flex-col items-center justify-center gap-2 pointer-events-none">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-surface-400 {bannerDragging ? 'text-primary-500' : ''}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                                    <div class="text-sm">
                                        <span class="font-semibold text-primary-600">Click to upload</span> or drag and drop
                                    </div>
                                    <p class="text-xs text-surface-500">JPEG, PNG or WebP; {getUploadHint('hero')}</p>
                                </div>
                            </div>
                            {#if publicPageBannerUploading}
                                <p class="text-sm text-surface-600 mt-2">Uploading…</p>
                            {/if}
                        {/if}
                        <p class="text-xs text-surface-500 mt-1">Images are compressed for web.</p>
                    </div>
                </section>
                <section class="rounded-container bg-surface-50 border border-surface-200 shadow-sm p-5 space-y-4">
                    <h2 class="text-lg font-semibold text-surface-950">QR codes</h2>
                    {#if publicPagePublished && !publicPagePrivate}
                        {@const publicLink = program?.short_links?.find((l: { type: string }) => l.type === 'program_public')}
                        {#if publicLink}
                            <QrDisplay url={publicLink.url} label="Public program" />
                        {:else}
                            <button type="button" class="btn preset-filled-primary-500 btn-sm" onclick={() => generateQr('public')} disabled={publicPageSavingQr}>Generate public QR</button>
                        {/if}
                    {/if}
                    {#if publicPagePrivate}
                        {@const promptLink = program?.short_links?.find((l: { type: string; has_embedded_key?: boolean }) => l.type === 'program_private' && !l.has_embedded_key)}
                        {@const scannableLink = program?.short_links?.find((l: { type: string; has_embedded_key?: boolean }) => l.type === 'program_private' && l.has_embedded_key)}
                        <p class="text-sm text-surface-600 font-medium">Key-entry QR (user types key after scan)</p>
                        {#if promptLink}
                            <QrDisplay url={promptLink.url} label="Key-entry QR" />
                        {:else}
                            <button type="button" class="btn variant-outline btn-sm" onclick={() => generateQr('private_prompt')} disabled={publicPageSavingQr}>Generate QR with key prompt</button>
                        {/if}
                        <p class="text-sm text-surface-600 font-medium mt-4">Scannable key QR (immediate access)</p>
                        <p class="text-xs text-warning-700">Anyone who scans this gets immediate access. Treat it like a physical key.</p>
                        {#if scannableLink}
                            <QrDisplay url={scannableLink.url} label="Scannable key QR" />
                        {:else}
                            <button type="button" class="btn variant-outline btn-sm" onclick={() => generateQr('private_scannable')} disabled={publicPageSavingQr}>Generate scannable key QR</button>
                        {/if}
                    {/if}
                </section>
                {#if publicPagePrivate}
                    <section class="rounded-container bg-surface-50 border border-surface-200 shadow-sm p-5 space-y-4">
                        <h2 class="text-lg font-semibold text-surface-950">Active access tokens</h2>
                        <p class="text-sm text-surface-600">{accessTokensCount} active token(s)</p>
                        {#if accessTokensList.length > 0}
                            <ul class="space-y-2">
                                {#each accessTokensList as token (token.id)}
                                    <li class="flex items-center justify-between gap-2 text-sm">
                                        <span>…{token.token_ref} — expires {token.expires_at}</span>
                                        <button type="button" class="btn variant-ghost-error btn-xs" onclick={() => revokeToken(token.id)}>Revoke</button>
                                    </li>
                                {/each}
                            </ul>
                            <button type="button" class="btn variant-outline-error btn-sm" onclick={revokeAllTokens}>Revoke all</button>
                        {:else}
                            <p class="text-sm text-surface-500">No active tokens. Devices will need to enter the program key to gain access.</p>
                        {/if}
                    </section>
                {/if}
                <div class="flex justify-end">
                    <button type="button" class="btn preset-filled-primary-500" onclick={savePublicPage} disabled={publicPageSaving}>Save</button>
                </div>
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
                    <div class="flex flex-wrap items-center gap-3 mb-6">
                        <button
                            type="button"
                            class="btn preset-filled-primary-500 flex items-center gap-2"
                            onclick={openCreateProcessModal}
                            disabled={!!edgeMode?.admin_read_only}
                            title={edgeMode?.admin_read_only ? "Changes must be made on the central server and re-synced to this device." : undefined}
                        >
                            <Plus class="w-4 h-4" /> Add Process
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
                                <div
                                    class="inline-flex items-start gap-2 px-3 py-2 rounded-lg bg-surface-100 border border-surface-200 text-sm text-surface-950"
                                >
                                    <div class="flex flex-col gap-0.5 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="font-medium">{proc.name}</span>
                                            {#if proc.expected_time_seconds != null && proc.expected_time_seconds > 0}
                                                <span class="text-surface-500 text-xs">{secondsToMmSs(proc.expected_time_seconds)}</span>
                                            {/if}
                                        </div>
                                        {#if proc.description}
                                            <span class="text-surface-500 text-xs">{proc.description}</span>
                                        {/if}
                                    </div>
                                    <div class="flex items-center gap-0.5 shrink-0">
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
                                    </div>
                                </div>
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
                </div>
                <div class="flex w-full sm:w-auto flex-col sm:flex-row sm:items-center gap-2 sm:gap-3 sm:ml-auto">
                    {#if segment2Enabled}
                        <button
                            type="button"
                            class="btn preset-tonal flex items-center gap-2 shadow-sm w-full sm:w-auto"
                            onclick={() => (showProgramConnectorTtsModal = true)}
                        >
                            <Volume2 class="w-4 h-4" />
                            Connecting phrase TTS
                        </button>
                    {:else}
                        <p class="text-sm text-surface-600 max-w-lg">
                            Station directions are off site-wide.
                            <Link href="/admin/settings?tab=token-tts" class="font-semibold text-primary-600 hover:text-primary-700 underline">Configuration → Audio &amp; TTS</Link>
                        </p>
                    {/if}
                    <button
                        type="button"
                        class="btn preset-filled-primary-500 flex items-center gap-2 shadow-sm sm:ml-auto shrink-0"
                        onclick={openCreateStation}
                        disabled={!!edgeMode?.admin_read_only}
                        title={edgeMode?.admin_read_only ? "Changes must be made on the central server and re-synced to this device." : undefined}
                    >
                        <Plus class="w-4 h-4" /> Add Station
                    </button>
                </div>
            </div>
            {#if serverTtsConfigured === false}
                <div class="rounded-container border border-warning-200 bg-warning-50 p-3 mb-4 text-sm text-warning-900" role="alert">
                    Add an ElevenLabs account in <Link href="/admin/settings?tab=integrations" class="font-semibold text-warning-800 underline hover:text-warning-950">Configuration → Integrations</Link> before generating station TTS.
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
                        disabled={!!edgeMode?.admin_read_only}
                        title={edgeMode?.admin_read_only ? "Changes must be made on the central server and re-synced to this device." : undefined}
                    >
                        <Plus class="w-4 h-4" /> Create First Station
                    </button>
                </div>
            {:else}
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {#each localStations as station (station.id)}
                        <div
                            class="bg-surface-100/80 md:bg-surface-50 rounded-container elevation-card transition-all hover:shadow-[var(--shadow-raised)] flex flex-col h-full border border-surface-200/60"
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
                                            class="text-base sm:text-lg font-bold text-surface-950 line-clamp-2 sm:line-clamp-1"
                                        >
                                            {station.name}
                                        </h3>
                                    </div>
                                    <div class="shrink-0 mt-1">
                                        {#if station.is_active}
                                            <span
                                                class="text-[10px] uppercase tracking-wider font-bold px-2 py-1 rounded flex items-center gap-1.5 text-success-700 sm:bg-success-50 sm:shadow-sm"
                                            >
                                                <span
                                                    class="w-2.5 h-2.5 rounded-full bg-success-500 shrink-0 animate-pulse"
                                                ></span>
                                                <span class="hidden sm:inline">
                                                    Active
                                                </span>
                                            </span>
                                        {:else}
                                            <span
                                                class="text-[10px] uppercase tracking-wider font-bold px-2 py-1 rounded flex items-center gap-1.5 text-error-700 sm:bg-error-50 sm:shadow-sm"
                                            >
                                                <span
                                                    class="w-2.5 h-2.5 rounded-full bg-error-500 shrink-0 animate-pulse"
                                                ></span>
                                                <span class="hidden sm:inline">
                                                    Inactive
                                                </span>
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
                                class="px-5 py-3 border-t border-surface-100 flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3 bg-surface-50/50 rounded-b-container"
                            >
                                <!-- Status and non-TTS card actions -->
                                <div class="w-full md:w-auto">
                                    <div class="grid grid-cols-2 gap-2 w-full">
                                        <button
                                            type="button"
                                            class="text-xs font-medium transition-colors hover:text-surface-900 flex items-center justify-center md:justify-start gap-1.5 w-full {station.is_active
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
                                        <div class="hidden md:block"></div>
                                    </div>
                                </div>
                                <!-- Per-language TTS statuses + core actions -->
                                <div class="w-full md:w-auto">
                                    <div class="flex flex-col gap-2 w-full md:w-auto">
                                        <div class="grid grid-cols-3 gap-1.5">
                                            {#each STATION_TTS_LANGS as lang}
                                                {@const langStatus = getStationLanguageTtsStatus(station, lang)}
                                                <span
                                                    class="inline-flex items-center justify-center gap-1 rounded-full border text-[10px] px-2 py-0.5 font-medium {stationTtsStatusClass(langStatus)}"
                                                    title={`${lang.toUpperCase()}: ${stationTtsStatusLabel(langStatus)}`}
                                                >
                                                    <span class="uppercase">{lang}</span>
                                                    {#if langStatus === "ready"}
                                                        <CheckCircle class="w-3 h-3" />
                                                    {:else if langStatus === "generating"}
                                                        <span class="loading-spinner loading-2xs"></span>
                                                    {:else}
                                                        <XCircle class="w-3 h-3" />
                                                    {/if}
                                                </span>
                                            {/each}
                                        </div>
                                        <div class="grid grid-cols-2 gap-2 w-full md:w-auto">
                                        <button
                                            type="button"
                                            class="btn preset-tonal p-2 w-full justify-center"
                                            onclick={() => openEditStation(station)}
                                            disabled={submitting}
                                            title="Edit Station"
                                        >
                                            <Edit2
                                                class="w-5 h-5 text-surface-600"
                                            />
                                        </button>
                                        <button
                                            type="button"
                                            class="btn preset-tonal p-2 hover:bg-error-50 w-full justify-center"
                                            onclick={() =>
                                                openDeleteStationConfirm(station)}
                                            disabled={submitting}
                                            title="Delete Station"
                                        >
                                            <Trash2
                                                class="w-5 h-5 text-error-500"
                                            />
                                        </button>
                                        </div>
                                    </div>
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
                                                        station.id || !!edgeMode?.admin_read_only}
                                                    title={edgeMode?.admin_read_only ? "Changes must be made on the central server and re-synced to this device." : undefined}
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
        {:else if activeTab === "tokens"}
            <!-- Tokens tab: read-only list of assigned tokens only; assign/unassign on Tokens page -->
            <div id="tabpanel-tokens" role="tabpanel" aria-labelledby="tab-tokens" tabindex="-1" class="space-y-6">
                <section>
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-surface-950">
                            Tokens assigned to this program
                        </h2>
                        <p class="text-sm text-surface-950/70 mt-0.5">
                            Assigned tokens only. To assign or unassign, use the <Link href="/admin/tokens" class="font-semibold text-primary-600 hover:text-primary-700 underline">Tokens</Link> page.
                        </p>
                    </div>

                    {#if tokensLoading}
                        <div
                            class="rounded-container bg-surface-50 border border-surface-200 p-12 flex flex-col items-center justify-center text-center shadow-sm"
                        >
                            <span class="loading-spinner loading-lg text-primary-500 mb-4"></span>
                            <p class="text-surface-600 font-medium animate-pulse">Loading tokens...</p>
                        </div>
                    {:else if programTokens.length === 0}
                        <div
                            role="status"
                            aria-label="No tokens assigned"
                            class="rounded-container bg-surface-50 border border-surface-200 p-12 flex flex-col items-center justify-center text-center shadow-sm"
                        >
                            <div class="bg-surface-100 p-4 rounded-full text-surface-400 mb-4">
                                <Key class="w-8 h-8" />
                            </div>
                            <h3 class="text-lg font-semibold text-surface-950">No tokens assigned</h3>
                            <p class="text-surface-600 max-w-sm mt-2">
                                Assign tokens from the Tokens page.
                            </p>
                        </div>
                    {:else}
                        <!-- Desktop: read-only table (content width, no scrollable) -->
                        <AdminTable class="hidden lg:block w-max max-w-full" tableClass="text-sm">
                            {#snippet head()}
                                <tr>
                                    <th class="w-36 py-2 px-3 text-center text-surface-600 font-medium">Physical ID</th>
                                    <th class="w-32 py-2 px-3 text-center text-surface-600 font-medium">Status</th>
                                </tr>
                            {/snippet}
                            {#snippet body()}
                                {#each programTokens as token (token.id)}
                                    <tr>
                                        <td class="py-2 px-3 align-middle">
                                            <span class="font-mono font-semibold text-surface-900">{token.physical_id}</span>
                                        </td>
                                        <td class="py-2 px-3 align-middle">
                                            {token.status ?? "—"}
                                        </td>
                                    </tr>
                                {/each}
                            {/snippet}
                        </AdminTable>

                        <!-- Mobile/tablet: read-only card grid -->
                        <div class="mt-2 lg:hidden grid grid-cols-2 md:grid-cols-3 gap-3">
                            {#each programTokens as token (token.id)}
                                <div
                                    class="card bg-surface-50 border border-surface-200 shadow-sm rounded-container flex flex-col gap-2 p-3 max-h-[10rem] min-h-0"
                                >
                                    <span class="font-mono font-semibold text-surface-900 text-sm truncate min-w-0">
                                        {token.physical_id}
                                    </span>
                                    <div class="shrink-0 text-xs text-surface-600">
                                        {token.status ?? "—"}
                                    </div>
                                </div>
                            {/each}
                        </div>

                        <!-- Pagination (match Tokens page styling) -->
                        {#if tokenMeta && tokenMeta.last_page > 1}
                            {@const totalPages = tokenMeta.last_page}
                            <div
                                class="flex flex-col sm:flex-row justify-between items-center gap-4 mt-6 mb-4 px-2"
                            >
                                <span class="text-sm font-medium text-surface-600">
                                    Showing page
                                    <span class="text-surface-950 font-semibold">{tokenMeta.current_page}</span>
                                    of
                                    <span class="text-surface-950 font-semibold">{totalPages}</span>
                                    ({tokenMeta.total} tokens)
                                </span>
                                <div class="flex gap-2">
                                    <button
                                        type="button"
                                        class="btn preset-outlined bg-surface-50 text-surface-700 hover:bg-surface-50 flex items-center gap-1 shadow-sm px-4 py-1.5 transition-colors disabled:opacity-50 touch-target-h"
                                        disabled={tokenMeta.current_page <= 1}
                                        onclick={() => (tokenPage = tokenMeta!.current_page - 1)}
                                    >
                                        Previous
                                    </button>
                                    <button
                                        type="button"
                                        class="btn preset-outlined bg-surface-50 text-surface-700 hover:bg-surface-50 flex items-center gap-1 shadow-sm px-4 py-1.5 transition-colors disabled:opacity-50 touch-target-h"
                                        disabled={tokenMeta.current_page >= totalPages}
                                        onclick={() => (tokenPage = tokenMeta!.current_page + 1)}
                                    >
                                        Next
                                    </button>
                                </div>
                            </div>
                        {/if}
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
                    disabled={!!edgeMode?.admin_read_only}
                    title={edgeMode?.admin_read_only ? "Changes must be made on the central server and re-synced to this device." : undefined}
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
                        disabled={!!edgeMode?.admin_read_only}
                        title={edgeMode?.admin_read_only ? "Changes must be made on the central server and re-synced to this device." : undefined}
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
                            Queue and service rules, display board options, and kiosk / public triage—grouped below so each surface is easier to find.
                        </p>
                    </div>
                    {#if !edgeMode?.admin_read_only}
                        <button
                            type="button"
                            class="btn preset-tonal btn-sm"
                            onclick={applyDefaultSettings}
                        >
                            Apply default settings
                        </button>
                    {:else}
                        <p class="text-sm text-amber-700">Settings must be changed on the central server and re-synced.</p>
                    {/if}
                </div>
                <div
                    class="rounded-container bg-surface-50 border border-surface-200 shadow-sm flex flex-col overflow-hidden"
                >
                    <div class="p-5 sm:p-6 flex flex-col gap-10">
                        <section class="space-y-6" aria-labelledby="program-settings-queue">
                        <div class="rounded-lg bg-surface-100/70 border border-surface-200 px-4 py-3">
                            <h2 id="program-settings-queue" class="text-sm font-semibold text-surface-950">Queue & service rules</h2>
                            <p class="text-xs text-surface-600 mt-1">No-shows, priority and balancing, station selection, and supervisor PIN overrides.</p>
                        </div>
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

                        <!-- Max no-show attempts: after this many no-shows staff must choose Extend or Last call -->
                        <div
                            class="flex flex-col sm:flex-row gap-4 pb-6 border-b border-surface-200"
                        >
                            <div class="sm:w-1/3 shrink-0">
                                <h3
                                    class="font-medium text-surface-950 flex items-center gap-2"
                                >
                                    <Clock class="w-4 h-4 text-surface-500" /> Max no-show attempts
                                </h3>
                                <p class="text-xs text-surface-500 mt-1">
                                    After this many no-shows, staff must choose Extend or Last call. 1–10, default 3.
                                </p>
                            </div>
                            <div class="sm:w-2/3 form-control">
                                <input
                                    type="number"
                                    class="input rounded-container border border-surface-200 px-3 py-2 w-20 text-surface-950 bg-surface-50 shadow-sm text-center"
                                    min="1"
                                    max="10"
                                    bind:value={maxNoShowAttempts}
                                />
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
                        <div class="flex flex-col sm:flex-row gap-4 pb-6 border-b border-surface-200">
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
                        <div class="rounded-container bg-surface-100/80 border border-surface-200 p-4">
                            <p class="text-sm font-medium text-surface-800">Multiple processes per station</p>
                            <p class="text-xs text-surface-600 mt-1">
                                You can assign several processes to the same physical station: add the same station to multiple track steps (each step can use a different process), and in Stations ensure that station has multiple processes selected. Station selection mode above then applies when choosing which station serves each process.
                            </p>
                        </div>
                        </section>

                        <section class="space-y-6" aria-labelledby="program-settings-display">
                        <div class="rounded-lg bg-surface-100/70 border border-surface-200 px-4 py-3">
                            <h2 id="program-settings-display" class="text-sm font-semibold text-surface-950">Display board</h2>
                            <p class="text-xs text-surface-600 mt-1">Public display: scan timeout, spoken announcements, and barcode or camera on the board.</p>
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
                                    Mute, volume, and repeat for this program’s display board only. Site-wide token voice and “station directions on/off” are under Configuration → Audio &amp; TTS.
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

                        <!-- Camera/QR scanner: Display board -->
                        <div
                            class="flex flex-col sm:flex-row gap-4 pb-6 border-b border-surface-200"
                        >
                            <div class="sm:w-1/3 shrink-0">
                                <h3 class="font-medium text-surface-950 flex items-center gap-2">
                                    <Camera class="w-4 h-4 text-surface-500" /> Camera/QR scanner (Display)
                                </h3>
                                <p class="text-xs text-surface-500 mt-1">
                                    When on, the Display board shows the button to open the camera and scan a QR code.
                                </p>
                            </div>
                            <div class="sm:w-2/3 form-control pt-1">
                                <label class="label cursor-pointer justify-start gap-3 w-fit hover:bg-surface-100 p-2 -ml-2 rounded-lg transition-colors">
                                    <input type="checkbox" class="checkbox" bind:checked={enableDisplayCameraScanner} />
                                    <span class="label-text text-surface-950 font-medium">Enable camera/QR scanner on Display board</span>
                                </label>
                            </div>
                        </div>
                        </section>

                        <section class="space-y-6" aria-labelledby="program-settings-kiosk">
                        <div class="rounded-lg bg-surface-100/70 border border-surface-200 px-4 py-3">
                            <h2 id="program-settings-kiosk" class="text-sm font-semibold text-surface-950">Kiosk & client registration</h2>
                            <p class="text-xs text-surface-600 mt-1">Self-service kiosk, identity and registration on kiosk plus staff client registration, and scanner options for the kiosk.</p>
                        </div>

                        <!-- Kiosk device (self-service + status) -->
                        <div
                            class="flex flex-col sm:flex-row gap-4 pb-6 border-b border-surface-200"
                        >
                            <div class="sm:w-1/3 shrink-0">
                                <h3 class="font-medium text-surface-950 flex items-center gap-2">
                                    Kiosk device
                                </h3>
                                <p class="text-xs text-surface-500 mt-1">
                                    Controls the public self-service kiosk (site URL <code class="text-xs bg-surface-100 px-1 rounded">/site/…/kiosk/…</code>). Self-service starts a visit; status checker opens queue status when the token is already in the queue.
                                </p>
                            </div>
                            <div class="sm:w-2/3 form-control pt-1 space-y-4">
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
                                    <span class="label-text text-surface-950 font-medium">Self-service triage</span>
                                </label>
                                <p class="text-xs text-surface-500 -mt-2 ml-14">
                                    Scan token, choose a track, start a visit (when the device is unlocked for this program).
                                </p>
                                <label
                                    for="kiosk-status-checker-switch"
                                    class="label cursor-pointer justify-start gap-3 w-fit hover:bg-surface-100 p-2 -ml-2 rounded-lg transition-colors items-center"
                                >
                                    <div class="relative inline-block w-11 h-5">
                                        <input
                                            id="kiosk-status-checker-switch"
                                            type="checkbox"
                                            class="peer appearance-none w-11 h-5 bg-surface-200 rounded-full checked:bg-surface-800 cursor-pointer transition-colors duration-300"
                                            bind:checked={kioskStatusCheckerEnabled}
                                        />
                                        <span
                                            class="absolute top-0 left-0 w-5 h-5 bg-surface-950 rounded-full border border-surface-300 shadow-sm transition-transform duration-300 peer-checked:translate-x-6 peer-checked:border-surface-800 pointer-events-none"
                                            aria-hidden="true"
                                        ></span>
                                    </div>
                                    <span class="label-text text-surface-950 font-medium">Queue status checker</span>
                                </label>
                                <p class="text-xs text-surface-500 -mt-2 ml-14">
                                    When a scanned token is already in the queue, send the visitor to the public status screen.
                                </p>
                            </div>
                        </div>

                        <!-- Identity: kiosk self-service + staff client registration -->
                        <div
                            class="flex flex-col sm:flex-row gap-4 pb-6 border-b border-surface-200"
                        >
                            <div class="sm:w-1/3 shrink-0">
                                <h3 class="font-medium text-surface-950 flex items-center gap-2">
                                    Kiosk & staff: verification / registration
                                </h3>
                                <p class="text-xs text-surface-500 mt-1 space-y-1">
                                    <span class="block">
                                        When on, visitors and staff see phone-based identity verification and client registration flows (kiosk and <strong>Client registration</strong> page).
                                    </span>
                                    <span class="block">
                                        Then choose: <strong>allow unverified to proceed to queue</strong>, or <strong>hold for staff</strong> (registration only until verified).
                                    </span>
                                </p>
                                {#if !allowPublicTriage}
                                    <p class="text-[11px] text-surface-500 mt-2">
                                        Kiosk self-service is off; these identity rules still apply to <strong>Client registration</strong> (staff). Turn on self-service above to use the same rules on the kiosk.
                                    </p>
                                {:else}
                                    <p class="text-[11px] text-surface-500 mt-2">
                                        With kiosk self-service on, these settings apply to both the kiosk and staff <strong>Client registration</strong>.
                                    </p>
                                {/if}
                            </div>
                            <div class="sm:w-2/3 form-control pt-1 space-y-2">
                                <label
                                    class="label cursor-pointer justify-start gap-3 w-fit hover:bg-surface-100 p-2 -ml-2 rounded-lg transition-colors items-center"
                                >
                                    <div class="relative inline-block w-11 h-5">
                                        <input
                                            type="checkbox"
                                            class="peer appearance-none w-11 h-5 bg-surface-200 rounded-full checked:bg-surface-800 cursor-pointer transition-colors duration-300"
                                            checked={identityPolicy !== "none"}
                                            onchange={() => {
                                                if (identityPolicy === "none") {
                                                    identityPolicy = "required";
                                                } else {
                                                    identityPolicy = "none";
                                                }
                                            }}
                                        />
                                        <span
                                            class="absolute top-0 left-0 w-5 h-5 bg-surface-950 rounded-full border border-surface-300 shadow-sm transition-transform duration-300 peer-checked:translate-x-6 peer-checked:border-surface-800 pointer-events-none"
                                            aria-hidden="true"
                                        ></span>
                                    </div>
                                    <span class="label-text text-surface-950 font-medium">
                                        Require identity / registration (kiosk + staff)
                                    </span>
                                </label>
                                {#if identityPolicy !== "none"}
                                    <div class="ml-7">
                                        <label
                                            class="flex items-start gap-3 cursor-pointer hover:bg-surface-100 p-2 -ml-2 rounded-lg transition-colors"
                                        >
                                            <input
                                                type="checkbox"
                                                class="checkbox checkbox-sm mt-0.5"
                                                bind:checked={allowUnverifiedEntry}
                                                disabled={!allowPublicTriage}
                                            />
                                            <span class="text-sm text-surface-950">
                                                <span class="font-medium text-surface-950"
                                                    >Allow unverified to proceed to queue</span
                                                >
                                                <span class="block text-surface-500 text-xs">
                                                    When checked, the kiosk may create a queue session from a registration that is not yet verified; the session stays marked unverified until staff accept. When unchecked, visitors submit registration for staff (<strong>Client registration</strong>) and do not enter the queue until processed.
                                                </span>
                                            </span>
                                        </label>
                                    </div>
                                {/if}
                            </div>
                        </div>

                        <!-- HID barcode: Kiosk -->
                        <div
                            class="flex flex-col sm:flex-row gap-4 pb-6 border-b border-surface-200"
                        >
                            <div class="sm:w-1/3 shrink-0">
                                <h3 class="font-medium text-surface-950 flex items-center gap-2">
                                    <Users class="w-4 h-4 text-surface-500" /> HID barcode (Kiosk)
                                </h3>
                                <p class="text-xs text-surface-500 mt-1">
                                    When on, the kiosk page keeps focus on the hidden barcode input for hardware scanners.
                                </p>
                            </div>
                            <div class="sm:w-2/3 form-control pt-1">
                                <label class="label cursor-pointer justify-start gap-3 w-fit hover:bg-surface-100 p-2 -ml-2 rounded-lg transition-colors">
                                    <input type="checkbox" class="checkbox" bind:checked={enablePublicTriageHidBarcode} />
                                    <span class="label-text text-surface-950 font-medium">Enable HID barcode on kiosk</span>
                                </label>
                            </div>
                        </div>

                        <!-- Camera: Kiosk -->
                        <div
                            class="flex flex-col sm:flex-row gap-4 pb-6 border-b border-surface-200"
                        >
                            <div class="sm:w-1/3 shrink-0">
                                <h3 class="font-medium text-surface-950 flex items-center gap-2">
                                    <Camera class="w-4 h-4 text-surface-500" /> Camera / QR (Kiosk)
                                </h3>
                                <p class="text-xs text-surface-500 mt-1">
                                    When on, the kiosk shows the camera scan button to read token QR codes.
                                </p>
                            </div>
                            <div class="sm:w-2/3 form-control pt-1">
                                <label class="label cursor-pointer justify-start gap-3 w-fit hover:bg-surface-100 p-2 -ml-2 rounded-lg transition-colors">
                                    <input type="checkbox" class="checkbox" bind:checked={enablePublicTriageCameraScanner} />
                                    <span class="label-text text-surface-950 font-medium">Enable camera / QR scanner on kiosk</span>
                                </label>
                            </div>
                        </div>

                        </section>
                    </div>

                    <div
                        class="bg-surface-100/50 px-5 py-4 border-t border-surface-200 flex justify-end"
                    >
                        {#if !edgeMode?.admin_read_only}
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
                        {:else}
                            <p class="text-sm text-amber-700">Settings must be changed on the central server and re-synced.</p>
                        {/if}
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
                        onclick={() => editStation && regenerateStationTts(editStation)}
                        disabled={submitting || !segment2Enabled || !editStation || stationRegeneratingId !== null}
                        title={segment2Enabled ? getStationTtsButtonLabel(editStation) : "Station directions are off site-wide"}
                    >
                        {stationRegeneratingId === editStation?.id ? "Starting…" : getStationTtsButtonLabel(editStation)}
                    </button>
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
            {#if segment2Enabled}
            <label class="label cursor-pointer justify-start gap-3 rounded-lg border border-surface-200 bg-surface-50 p-3">
                <input
                    type="checkbox"
                    class="checkbox checkbox-sm"
                    bind:checked={createStationGenerateAudio}
                />
                <span class="label-text">Generate audio on create</span>
            </label>
            <div class="form-control w-full">
                <span class="label-text font-medium mb-1 block">Station directions audio (per language)</span>
                <div class="space-y-3">
                    <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                            <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">English</span>
                            <div class="flex flex-wrap gap-1">
                                <button type="button" class="btn btn-xs preset-tonal" disabled={createStationTtsAudioPlaying !== null || submitting} onclick={() => playCreateStationDirectionsSample("en")}>
                                    {createStationTtsAudioPlaying?.mode === "sample" && createStationTtsAudioPlaying.lang === "en" ? "Playing…" : "Play sample"}
                                </button>
                                <button type="button" class="btn btn-xs preset-filled-primary-500" disabled={createStationTtsAudioPlaying !== null || submitting} onclick={() => playCreateStationFullSample("en")}>
                                    {createStationTtsAudioPlaying?.mode === "full" && createStationTtsAudioPlaying.lang === "en" ? "Playing…" : "Play full"}
                                </button>
                            </div>
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
                            <label class="label" for="station-en-phrase"><span class="label-text text-xs font-medium">Station wording (optional)</span></label>
                            <input id="station-en-phrase" type="text" class="input input-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" bind:value={createStationTts.en.station_phrase} disabled={!allowCustomPronunciation} />
                        </div>
                    </div>
                    <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                            <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">Filipino</span>
                            <div class="flex flex-wrap gap-1">
                                <button type="button" class="btn btn-xs preset-tonal" disabled={createStationTtsAudioPlaying !== null || submitting} onclick={() => playCreateStationDirectionsSample("fil")}>
                                    {createStationTtsAudioPlaying?.mode === "sample" && createStationTtsAudioPlaying.lang === "fil" ? "Playing…" : "Play sample"}
                                </button>
                                <button type="button" class="btn btn-xs preset-filled-primary-500" disabled={createStationTtsAudioPlaying !== null || submitting} onclick={() => playCreateStationFullSample("fil")}>
                                    {createStationTtsAudioPlaying?.mode === "full" && createStationTtsAudioPlaying.lang === "fil" ? "Playing…" : "Play full"}
                                </button>
                            </div>
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
                            <label class="label" for="station-fil-phrase"><span class="label-text text-xs font-medium">Station wording (optional)</span></label>
                            <input id="station-fil-phrase" type="text" class="input input-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" bind:value={createStationTts.fil.station_phrase} disabled={!allowCustomPronunciation} />
                        </div>
                    </div>
                    <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                            <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">Ilocano</span>
                            <div class="flex flex-wrap gap-1">
                                <button type="button" class="btn btn-xs preset-tonal" disabled={createStationTtsAudioPlaying !== null || submitting} onclick={() => playCreateStationDirectionsSample("ilo")}>
                                    {createStationTtsAudioPlaying?.mode === "sample" && createStationTtsAudioPlaying.lang === "ilo" ? "Playing…" : "Play sample"}
                                </button>
                                <button type="button" class="btn btn-xs preset-filled-primary-500" disabled={createStationTtsAudioPlaying !== null || submitting} onclick={() => playCreateStationFullSample("ilo")}>
                                    {createStationTtsAudioPlaying?.mode === "full" && createStationTtsAudioPlaying.lang === "ilo" ? "Playing…" : "Play full"}
                                </button>
                            </div>
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
                            <label class="label" for="station-ilo-phrase"><span class="label-text text-xs font-medium">Station wording (optional)</span></label>
                            <input id="station-ilo-phrase" type="text" class="input input-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" bind:value={createStationTts.ilo.station_phrase} disabled={!allowCustomPronunciation} />
                        </div>
                    </div>
                </div>
            </div>
            {:else}
                <p class="text-sm text-surface-600">
                    Station directions after the token call are off site-wide. Enable them under
                    <Link href="/admin/settings?tab=token-tts" class="font-semibold text-primary-600 hover:text-primary-700 underline">Configuration → Audio &amp; TTS</Link>
                    to configure connecting phrases and station direction audio.
                </p>
            {/if}
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

<Modal open={showCreateProcessModal} title="Add Process" onClose={closeCreateProcessModal}>
    {#snippet children()}
        <form
            onsubmit={(e) => {
                e.preventDefault();
                handleCreateProcess();
            }}
            class="flex flex-col gap-4"
        >
            <div class="form-control w-full">
                <label for="create-process-name" class="label"
                    ><span class="label-text">Name</span></label
                >
                <input
                    id="create-process-name"
                    type="text"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                    placeholder="e.g. Verification"
                    bind:value={createProcessName}
                    maxlength="50"
                    required
                />
            </div>
            <div class="form-control w-full">
                <label for="create-process-desc" class="label"
                    ><span class="label-text">Description (optional)</span></label
                >
                <input
                    id="create-process-desc"
                    type="text"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                    placeholder="Optional"
                    bind:value={createProcessDescription}
                />
            </div>
            <div class="form-control w-full">
                <label for="create-process-expected-time" class="label"
                    ><span class="label-text">Expected time (mm:ss)</span></label
                >
                <input
                    id="create-process-expected-time"
                    type="text"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                    placeholder="e.g. 0:00 or 5:30"
                    bind:value={createProcessExpectedTimeMmSs}
                />
            </div>
            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    class="btn preset-tonal"
                    onclick={closeCreateProcessModal}
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    class="btn preset-filled-primary-500"
                    disabled={submitting || !createProcessName.trim()}
                >
                    {#if submitting && creatingProcess}
                        <span class="loading-spinner loading-sm"></span>
                        Adding...
                    {:else}
                        Create
                    {/if}
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
                {#if segment2Enabled}
                <div class="form-control w-full">
                    <span class="label-text font-medium mb-1 block">Station directions audio (per language)</span>
                    <div class="space-y-3">
                        <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                            <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                                <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">English</span>
                                <div class="flex flex-wrap gap-1">
                                    <button type="button" class="btn btn-xs preset-tonal" disabled={stationTtsAudioPlaying !== null || submitting} onclick={() => playStationDirectionsSample("en")}>
                                        {stationTtsAudioPlaying?.mode === "sample" && stationTtsAudioPlaying.lang === "en" ? "Playing…" : "Play sample"}
                                    </button>
                                    <button type="button" class="btn btn-xs preset-filled-primary-500" disabled={stationTtsAudioPlaying !== null || submitting} onclick={() => playStationFullAnnouncementSample("en")}>
                                        {stationTtsAudioPlaying?.mode === "full" && stationTtsAudioPlaying.lang === "en" ? "Playing…" : "Play full"}
                                    </button>
                                </div>
                            </div>
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
                                <label class="label py-0" for="edit-station-en-phrase"><span class="label-text text-xs font-medium">Station wording (optional)</span></label>
                                <input id="edit-station-en-phrase" type="text" class="input input-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" bind:value={editStationTts.en.station_phrase} disabled={!allowCustomPronunciation} />
                            </div>
                        </div>
                        <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                            <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                                <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">Filipino</span>
                                <div class="flex flex-wrap gap-1">
                                    <button type="button" class="btn btn-xs preset-tonal" disabled={stationTtsAudioPlaying !== null || submitting} onclick={() => playStationDirectionsSample("fil")}>
                                        {stationTtsAudioPlaying?.mode === "sample" && stationTtsAudioPlaying.lang === "fil" ? "Playing…" : "Play sample"}
                                    </button>
                                    <button type="button" class="btn btn-xs preset-filled-primary-500" disabled={stationTtsAudioPlaying !== null || submitting} onclick={() => playStationFullAnnouncementSample("fil")}>
                                        {stationTtsAudioPlaying?.mode === "full" && stationTtsAudioPlaying.lang === "fil" ? "Playing…" : "Play full"}
                                    </button>
                                </div>
                            </div>
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
                                <label class="label py-0" for="edit-station-fil-phrase"><span class="label-text text-xs font-medium">Station wording (optional)</span></label>
                                <input id="edit-station-fil-phrase" type="text" class="input input-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" bind:value={editStationTts.fil.station_phrase} disabled={!allowCustomPronunciation} />
                            </div>
                        </div>
                        <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                            <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                                <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">Ilocano</span>
                                <div class="flex flex-wrap gap-1">
                                    <button type="button" class="btn btn-xs preset-tonal" disabled={stationTtsAudioPlaying !== null || submitting} onclick={() => playStationDirectionsSample("ilo")}>
                                        {stationTtsAudioPlaying?.mode === "sample" && stationTtsAudioPlaying.lang === "ilo" ? "Playing…" : "Play sample"}
                                    </button>
                                    <button type="button" class="btn btn-xs preset-filled-primary-500" disabled={stationTtsAudioPlaying !== null || submitting} onclick={() => playStationFullAnnouncementSample("ilo")}>
                                        {stationTtsAudioPlaying?.mode === "full" && stationTtsAudioPlaying.lang === "ilo" ? "Playing…" : "Play full"}
                                    </button>
                                </div>
                            </div>
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
                                <label class="label py-0" for="edit-station-ilo-phrase"><span class="label-text text-xs font-medium">Station wording (optional)</span></label>
                                <input id="edit-station-ilo-phrase" type="text" class="input input-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full" bind:value={editStationTts.ilo.station_phrase} disabled={!allowCustomPronunciation} />
                            </div>
                        </div>
                    </div>
                </div>
                {:else}
                <p class="text-sm text-surface-600">
                    Station directions are off site-wide.
                    <Link href="/admin/settings?tab=token-tts" class="font-semibold text-primary-600 hover:text-primary-700 underline">Configuration → Audio &amp; TTS</Link>
                </p>
                {/if}
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="btn preset-tonal"
                        onclick={() => editStation && regenerateStationTts(editStation)}
                        disabled={submitting || !segment2Enabled || !editStation || stationRegeneratingId !== null}
                        title={segment2Enabled ? getStationTtsButtonLabel(editStation) : "Station directions are off site-wide"}
                    >
                        {stationRegeneratingId === editStation?.id ? "Starting…" : getStationTtsButtonLabel(editStation).replace("station TTS", "audio")}
                    </button>
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
    onClose={() => { if (!stationTtsRegenerating) { showStationTtsRegenerateConfirm = false; closeModals(); } }}
>
    {#snippet children()}
        <div class="flex flex-col gap-4">
            <p class="text-sm text-surface-700">
                Station TTS was updated and this station had generated audio. Regenerate TTS for this station?
            </p>
            <div class="flex justify-end gap-3 pt-2 border-t border-surface-200">
                <button type="button" class="btn preset-tonal" disabled={stationTtsRegenerating} onclick={() => { if (!stationTtsRegenerating) { showStationTtsRegenerateConfirm = false; closeModals(); router.reload(); } }}>
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
                            else { showStationTtsRegenerateConfirm = false; closeModals(); router.reload(); }
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
    title="Station directions — connecting phrase"
    onClose={closeProgramConnectorTtsModal}
>
    {#snippet children()}
        <form
            onsubmit={(e) => {
                e.preventDefault();
                handleSaveSettings();
                closeProgramConnectorTtsModal();
            }}
            class="flex flex-col gap-4"
        >
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
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                        <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">English</span>
                        <div class="flex flex-wrap gap-1">
                            <button
                                type="button"
                                class="btn btn-xs preset-tonal"
                                disabled={connectorTtsAudioPlaying !== null || submitting}
                                onclick={() => playConnectorTtsSample("en")}
                            >
                                {connectorTtsAudioPlaying?.mode === "sample" && connectorTtsAudioPlaying.lang === "en" ? "Playing…" : "Play sample"}
                            </button>
                            <button
                                type="button"
                                class="btn btn-xs preset-filled-primary-500"
                                disabled={connectorTtsAudioPlaying !== null || submitting}
                                onclick={() => playConnectorTtsFullSample("en")}
                            >
                                {connectorTtsAudioPlaying?.mode === "full" && connectorTtsAudioPlaying.lang === "en" ? "Playing…" : "Play full"}
                            </button>
                        </div>
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
                            bind:value={connectorTts.en.connector_phrase}
                            disabled={!allowCustomPronunciation}
                        />
                    </div>
                </div>
                <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                        <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">Filipino</span>
                        <div class="flex flex-wrap gap-1">
                            <button
                                type="button"
                                class="btn btn-xs preset-tonal"
                                disabled={connectorTtsAudioPlaying !== null || submitting}
                                onclick={() => playConnectorTtsSample("fil")}
                            >
                                {connectorTtsAudioPlaying?.mode === "sample" && connectorTtsAudioPlaying.lang === "fil" ? "Playing…" : "Play sample"}
                            </button>
                            <button
                                type="button"
                                class="btn btn-xs preset-filled-primary-500"
                                disabled={connectorTtsAudioPlaying !== null || submitting}
                                onclick={() => playConnectorTtsFullSample("fil")}
                            >
                                {connectorTtsAudioPlaying?.mode === "full" && connectorTtsAudioPlaying.lang === "fil" ? "Playing…" : "Play full"}
                            </button>
                        </div>
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
                            bind:value={connectorTts.fil.connector_phrase}
                            disabled={!allowCustomPronunciation}
                        />
                    </div>
                </div>
                <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                        <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">Ilocano</span>
                        <div class="flex flex-wrap gap-1">
                            <button
                                type="button"
                                class="btn btn-xs preset-tonal"
                                disabled={connectorTtsAudioPlaying !== null || submitting}
                                onclick={() => playConnectorTtsSample("ilo")}
                            >
                                {connectorTtsAudioPlaying?.mode === "sample" && connectorTtsAudioPlaying.lang === "ilo" ? "Playing…" : "Play sample"}
                            </button>
                            <button
                                type="button"
                                class="btn btn-xs preset-filled-primary-500"
                                disabled={connectorTtsAudioPlaying !== null || submitting}
                                onclick={() => playConnectorTtsFullSample("ilo")}
                            >
                                {connectorTtsAudioPlaying?.mode === "full" && connectorTtsAudioPlaying.lang === "ilo" ? "Playing…" : "Play full"}
                            </button>
                        </div>
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
                            bind:value={connectorTts.ilo.connector_phrase}
                            disabled={!allowCustomPronunciation}
                        />
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button
                    type="button"
                    class="btn preset-tonal"
                    onclick={closeProgramConnectorTtsModal}
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
