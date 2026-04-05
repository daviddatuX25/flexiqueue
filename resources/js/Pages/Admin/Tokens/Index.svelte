<script lang="ts">
    import AdminLayout from "../../../Layouts/AdminLayout.svelte";
    import Modal from "../../../Components/Modal.svelte";
    import AdminTable from "../../../Components/AdminTable.svelte";
    import { get } from "svelte/store";
    import { Link, usePage } from "@inertiajs/svelte";
    import { toaster } from "../../../lib/toaster.js";
import {
    previewSegment1Text,
    playAdminFullAnnouncementPreview,
    playAdminTtsPreview,
} from "../../../lib/ttsPreview.js";
    import {
        Ticket,
        Plus,
        Printer,
        Search,
        Filter,
        Trash2,
        Ban,
        CheckCircle2,
        PlayCircle,
        XCircle,
        ChevronDown,
        ChevronUp,
        Pencil,
        Volume2,
        FolderPlus,
    } from "lucide-svelte";
    import Marquee from "../../../Components/Marquee.svelte";

    interface TokenItem {
        id: number;
        physical_id: string;
        qr_code_hash: string;
        status: string;
        current_session_id?: number | null;
        pronounce_as?: string;
        is_global?: boolean;
        assigned_programs?: { id: number; name: string }[];
        tts_status?: string | null;
        tts_failure_reason?: string | null;
        has_tts_audio?: boolean;
        tts_settings?: {
            languages?: Record<
                string,
                {
                    voice_id?: string | null;
                    rate?: number | null;
                    pre_phrase?: string | null;
                    token_phrase?: string | null;
                }
            >;
        };
    }

    interface PrintSettingsApi {
        cards_per_page?: number;
        paper?: string;
        orientation?: string;
        show_hint?: boolean;
        show_cut_lines?: boolean;
        logo_url?: string;
        footer_text?: string;
        bg_image_url?: string;
    }

    let tokens = $state<TokenItem[]>([]);
    let loading = $state(true);
    let submitting = $state(false);
    let showBatchModal = $state(false);
    let filterStatus = $state("");
    let searchQuery = $state("");
    let filterPrefix = $state("");
    let filterAssignment = $state("");
    let filterIsGlobal = $state("");
    let filterTtsStatus = $state("");
    let filtersExpanded = $state(true);
    // Pagination (per UI/UX checklist: Tables – Pagination)
    const TOKEN_PER_PAGE_DEFAULT = 25;
    let tokenPage = $state(1);
    let tokenPerPage = $state(TOKEN_PER_PAGE_DEFAULT);
    let tokenMeta = $state<{
        current_page: number;
        last_page: number;
        total: number;
        per_page: number;
    } | null>(null);
    // Batch form
    let batchPrefix = $state("A");
    let batchStart = $state(1);
    let batchCount = $state(50);
    /** letters = letter-by-letter phonetics + digits; word = letter runs as words + digit runs; custom = edit-token-only exact phrase. */
    type PronounceAsOption = "letters" | "word" | "custom";
    type BatchPronounceAs = "letters" | "word";
    let batchPronounceAs = $state<BatchPronounceAs>("letters");
    /** When true, batch create also triggers offline TTS generation for the new tokens. */
    let batchGenerateOfflineTts = $state(true);
    /** When true, new tokens are "use for all programs in this site" (global). Default on. */
    let batchIsGlobal = $state(true);
    type TtsLangKey = "en" | "fil" | "ilo";
    type TtsUiStatus = "not_generated" | "generating" | "ready" | "failed";
    const TTS_LANGS: TtsLangKey[] = ["en", "fil", "ilo"];
    interface BatchTtsConfig {
        voice_id: string;
        rate: number;
        pre_phrase: string;
        token_phrase: string;
        token_bridge_tail: string;
        /** Site default; not edited per token in this UI. */
        closing_without_segment2: string;
    }
    let batchTts = $state<Record<TtsLangKey, BatchTtsConfig>>({
        en: { voice_id: "", rate: 0.84, pre_phrase: "", token_phrase: "", token_bridge_tail: "", closing_without_segment2: "" },
        fil: { voice_id: "", rate: 0.84, pre_phrase: "", token_phrase: "", token_bridge_tail: "", closing_without_segment2: "" },
        ilo: { voice_id: "", rate: 0.84, pre_phrase: "", token_phrase: "", token_bridge_tail: "", closing_without_segment2: "" },
    });
    // Selection for bulk actions
    let selectedIds = $state<Set<number>>(new Set());
    let selectAllCheckbox = $state<HTMLInputElement | null>(null);
    let selectAllCheckboxMobile = $state<HTMLInputElement | null>(null);
    // Edit single token modal
    let editToken = $state<TokenItem | null>(null);
    let editPronounceAs = $state<PronounceAsOption>("letters");
    let editTokenTts = $state<Record<TtsLangKey, BatchTtsConfig>>({
        en: { voice_id: "", rate: 0.84, pre_phrase: "", token_phrase: "", token_bridge_tail: "", closing_without_segment2: "" },
        fil: { voice_id: "", rate: 0.84, pre_phrase: "", token_phrase: "", token_bridge_tail: "", closing_without_segment2: "" },
        ilo: { voice_id: "", rate: 0.84, pre_phrase: "", token_phrase: "", token_bridge_tail: "", closing_without_segment2: "" },
    });

    // Print modal (uniform flow: select template then print)
    let showPrintModal = $state(false);
    let printSettings = $state({
        cards_per_page: 6,
        paper: "a4",
        orientation: "portrait",
        show_hint: true,
        show_cut_lines: true,
        logo_url: "" as string,
        footer_text: "" as string,
        bg_image_url: "" as string,
    });
    // Global token TTS settings (server voice + rate) — used by batch modal and edit token TTS
    let tokenTtsVoiceId = $state<string | null>(null);
    let tokenTtsRate = $state(0.84);
    let tokenTtsLoading = $state(false);
    let tokenTtsVoices = $state<{ id: string; name: string; lang?: string }[]>([]);
    // Global default per-language (EN, FIL, ILO) for batch modal
    let tokenTtsLanguages = $state<Record<TtsLangKey, BatchTtsConfig>>({
        en: { voice_id: "", rate: 0.84, pre_phrase: "", token_phrase: "", token_bridge_tail: "", closing_without_segment2: "" },
        fil: { voice_id: "", rate: 0.84, pre_phrase: "", token_phrase: "", token_bridge_tail: "", closing_without_segment2: "" },
        ilo: { voice_id: "", rate: 0.84, pre_phrase: "", token_phrase: "", token_bridge_tail: "", closing_without_segment2: "" },
    });

    /** Collapsible default token call wording (collapsed by default). */
    let segment1DefaultsExpanded = $state(false);
    let tokenSegment1DefaultsSaving = $state(false);

    // Confirm cancelling an in-use token's current session
    let cancelSessionToken = $state<TokenItem | null>(null);

    // Assign to program modal (row or bulk)
    let showAssignToProgramModal = $state(false);
    let assignToProgramTokenIds = $state<number[]>([]);
    let programsForAssign = $state<{ id: number; name: string }[]>([]);
    let selectedProgramIdForAssign = $state<number | null>(null);
    let assignToProgramLoading = $state(false);
    let assignToProgramSubmitting = $state(false);

    // Unassign: one-program-per-token, single click (no modal)
    let unassigningTokenId = $state<number | null>(null);

    // Programs list for filter dropdown (and optionally for Assign modal reuse)
    let programsForFilter = $state<{ id: number; name: string }[]>([]);

    const page = usePage();
    const edgeMode = $derived(
        ($page?.props as { edge_mode?: { is_edge?: boolean; admin_read_only?: boolean } } | undefined)
            ?.edge_mode ?? null
    );
    const serverTtsConfigured = $derived((get(page)?.props as { server_tts_configured?: boolean } | undefined)?.server_tts_configured ?? true);
    const allowCustomPronunciation = $derived(
        (get(page)?.props as { tts_allow_custom_pronunciation?: boolean } | undefined)?.tts_allow_custom_pronunciation !== false,
    );
    const segment2Enabled = $derived(
        (get(page)?.props as { tts_segment_2_enabled?: boolean } | undefined)?.tts_segment_2_enabled !== false,
    );

    /** Placeholder connector for “Play full” when no program context (matches Audio & TTS sample defaults). */
    const FULL_PREVIEW_CONNECTOR = "please go to";

    type TtsPreviewLock = { mode: "sample" | "full"; lang: TtsLangKey };
    let ttsPreviewLock = $state<TtsPreviewLock | null>(null);

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

    function normalizeTtsUiStatus(raw?: string | null): TtsUiStatus {
        if (raw === "pre_generated" || raw === "ready") return "ready";
        if (raw === "failed") return "failed";
        if (raw === "generating") return "generating";
        return "not_generated";
    }

    function getTokenLangStatus(token: TokenItem, lang: TtsLangKey): TtsUiStatus {
        const langStatus =
            (token.tts_settings?.languages as
                | Record<string, { status?: string | null }>
                | undefined)?.[lang]?.status ?? null;
        if (langStatus) return normalizeTtsUiStatus(langStatus);
        return normalizeTtsUiStatus(token.tts_status ?? null);
    }

    function ttsStatusBadgeClass(status: TtsUiStatus): string {
        if (status === "ready") return "bg-success-50 border-success-200 text-success-700";
        if (status === "failed") return "bg-error-50 border-error-200 text-error-700";
        if (status === "generating") return "bg-primary-50 border-primary-200 text-primary-700";
        return "bg-surface-100 border-surface-200 text-surface-600";
    }

    function ttsStatusLabel(status: TtsUiStatus): string {
        if (status === "ready") return "Generated";
        if (status === "generating") return "Generating";
        return "Not generated";
    }

    async function api(
        method: string,
        url: string,
        body?: object,
    ): Promise<{
        ok: boolean;
        data?: {
            tokens?: TokenItem[];
            token?: TokenItem;
            created?: number;
            print_settings?: PrintSettingsApi;
            programs?: { id: number; name: string }[];
            meta?: { current_page: number; last_page: number; total: number; per_page: number };
        };
        message?: string;
    }> {
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

    function buildTokensUrl(page: number = tokenPage): string {
        const params = new URLSearchParams();
        if (filterStatus) params.set("status", filterStatus);
        if (searchQuery.trim()) params.set("search", searchQuery.trim());
        if (filterPrefix.trim()) params.set("prefix", filterPrefix.trim());
        if (filterAssignment) params.set("assignment", filterAssignment);
        if (filterIsGlobal !== "") params.set("is_global", filterIsGlobal === "1" ? "1" : "0");
        if (filterTtsStatus) params.set("tts_status", filterTtsStatus);
        params.set("page", String(page));
        params.set("per_page", String(tokenPerPage));
        return `/api/admin/tokens?${params.toString()}`;
    }

    async function fetchTokens(optionalPage?: number) {
        const page = optionalPage ?? tokenPage;
        loading = true;
        const { ok, data } = await api("GET", buildTokensUrl(page));
        loading = false;
        if (ok && data?.tokens) {
            tokens = data.tokens;
            tokenMeta =
                data.meta &&
                typeof data.meta.current_page === "number" &&
                typeof data.meta.last_page === "number" &&
                typeof data.meta.total === "number" &&
                typeof data.meta.per_page === "number"
                    ? {
                          current_page: data.meta.current_page,
                          last_page: data.meta.last_page,
                          total: data.meta.total,
                          per_page: data.meta.per_page,
                      }
                    : null;
            if (optionalPage !== undefined) {
                tokenPage = optionalPage;
            }
        } else {
            tokens = [];
            tokenMeta = null;
        }
    }

    function goToTokenPage(pageNum: number) {
        if (tokenMeta && pageNum >= 1 && pageNum <= tokenMeta.last_page) {
            fetchTokens(pageNum);
        }
    }

    async function fetchTokenTtsSettings() {
        tokenTtsLoading = true;
        try {
            const [settingsRes, voicesRes] = await Promise.all([
                fetch("/api/admin/token-tts-settings", {
                    method: "GET",
                    headers: {
                        Accept: "application/json",
                        "X-CSRF-TOKEN": getCsrfToken(),
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    credentials: "same-origin",
                }),
                fetch("/api/public/tts/voices", {
                    method: "GET",
                    headers: {
                        Accept: "application/json",
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    credentials: "same-origin",
                }),
            ]);
            if (settingsRes.status === 419 || voicesRes.status === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return;
            }
            const settingsData = await settingsRes.json().catch(() => ({}));
            const voicesData = await voicesRes.json().catch(() => ({}));

            if (settingsRes.ok && settingsData && "token_tts_settings" in settingsData) {
                const s = settingsData.token_tts_settings as {
                    voice_id?: string | null;
                    rate?: number;
                    languages?: Record<
                        TtsLangKey,
                        {
                            voice_id?: string | null;
                            rate?: number;
                            pre_phrase?: string | null;
                            token_phrase?: string | null;
                            token_bridge_tail?: string | null;
                            closing_without_segment2?: string | null;
                        }
                    >;
                };
                tokenTtsVoiceId = (s.voice_id ?? null) as string | null;
                tokenTtsRate = typeof s.rate === "number" ? s.rate : 0.84;
                const langs = (s.languages ?? {}) as Record<
                    TtsLangKey,
                    {
                        voice_id?: string | null;
                        rate?: number;
                        pre_phrase?: string | null;
                        token_phrase?: string | null;
                        token_bridge_tail?: string | null;
                        closing_without_segment2?: string | null;
                    }
                >;
                tokenTtsLanguages = {
                    en: {
                        voice_id: (langs.en?.voice_id as string | undefined) ?? "",
                        rate: typeof langs.en?.rate === "number" ? langs.en.rate : 0.84,
                        pre_phrase: (langs.en?.pre_phrase as string | undefined) ?? "",
                        token_phrase: (langs.en?.token_phrase as string | undefined) ?? "",
                        token_bridge_tail: (langs.en?.token_bridge_tail as string | undefined) ?? "",
                        closing_without_segment2: (langs.en?.closing_without_segment2 as string | undefined) ?? "",
                    },
                    fil: {
                        voice_id: (langs.fil?.voice_id as string | undefined) ?? "",
                        rate: typeof langs.fil?.rate === "number" ? langs.fil.rate : 0.84,
                        pre_phrase: (langs.fil?.pre_phrase as string | undefined) ?? "",
                        token_phrase: (langs.fil?.token_phrase as string | undefined) ?? "",
                        token_bridge_tail: (langs.fil?.token_bridge_tail as string | undefined) ?? "",
                        closing_without_segment2: (langs.fil?.closing_without_segment2 as string | undefined) ?? "",
                    },
                    ilo: {
                        voice_id: (langs.ilo?.voice_id as string | undefined) ?? "",
                        rate: typeof langs.ilo?.rate === "number" ? langs.ilo.rate : 0.84,
                        pre_phrase: (langs.ilo?.pre_phrase as string | undefined) ?? "",
                        token_phrase: (langs.ilo?.token_phrase as string | undefined) ?? "",
                        token_bridge_tail: (langs.ilo?.token_bridge_tail as string | undefined) ?? "",
                        closing_without_segment2: (langs.ilo?.closing_without_segment2 as string | undefined) ?? "",
                    },
                };
            }

            if (voicesRes.ok && voicesData && "voices" in voicesData && Array.isArray(voicesData.voices)) {
                tokenTtsVoices = voicesData.voices as {
                    id: string;
                    name: string;
                    lang?: string;
                }[];
            } else {
                tokenTtsVoices = [];
            }
        } catch (e) {
            const isNetwork = e instanceof TypeError && (e as Error).message === "Failed to fetch";
            if (isNetwork) toaster.error({ title: MSG_NETWORK_ERROR });
        } finally {
            tokenTtsLoading = false;
        }
    }

    function ttsVoiceIdForConfig(config: BatchTtsConfig): string {
        return ((config.voice_id && config.voice_id.trim()) || tokenTtsVoiceId) ?? "";
    }

    /** Token-only pronunciation preview (no pre-phrase, no tail, no station part). */
    async function playTtsSampleForLang(
        lang: TtsLangKey,
        config: BatchTtsConfig,
        options: { alias: string; pronounce_as: BatchPronounceAs | PronounceAsOption },
    ) {
        if (ttsPreviewLock) return;
        ttsPreviewLock = { mode: "sample", lang };
        try {
            // Sample must include the token-call pre-phrase (AnnouncementBuilder segment 1).
            // If the user leaves a pre-phrase empty, treat it as "inherit" by not overriding defaults.
            const prePhraseOverride =
                typeof config.pre_phrase === "string" && config.pre_phrase.trim() !== "" ? config.pre_phrase.trim() : null;

            const tokenBridgeTailOverride =
                typeof config.token_bridge_tail === "string" && config.token_bridge_tail.trim() !== ""
                    ? config.token_bridge_tail.trim()
                    : null;

            const tokenPhraseOverride =
                typeof config.token_phrase === "string" && config.token_phrase.trim() !== "" ? config.token_phrase.trim() : null;

            const segment1 = await previewSegment1Text({
                lang,
                alias: options.alias || "A1",
                pronounce_as: options.pronounce_as || "letters",
                pre_phrase: prePhraseOverride,
                token_phrase: tokenPhraseOverride,
                token_bridge_tail: tokenBridgeTailOverride,
                getCsrfToken,
            });

            if (segment1.status === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return;
            }

            if (!segment1.ok || !segment1.text) {
                toaster.error({ title: "Could not build token call." });
                return;
            }
            const preview = await playAdminTtsPreview({
                text: segment1.text,
                rate: config.rate,
                voiceId: ttsVoiceIdForConfig(config),
            });
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
            ttsPreviewLock = null;
        }
    }

    /** Full call: segment 1 + station directions or optional closing (same order as informant display). */
    async function playTtsFullSampleForLang(
        lang: TtsLangKey,
        config: BatchTtsConfig,
        options: { alias: string; pronounce_as: BatchPronounceAs | PronounceAsOption },
    ) {
        if (ttsPreviewLock) return;
        ttsPreviewLock = { mode: "full", lang };
        try {
            const bridge =
                (config.token_bridge_tail ?? "").trim() ||
                (tokenTtsLanguages[lang].token_bridge_tail ?? "").trim();
            const closing = (tokenTtsLanguages[lang].closing_without_segment2 ?? "").trim();
            const tokenPhrase =
                allowCustomPronunciation &&
                options.pronounce_as === "custom" &&
                (config.token_phrase ?? "").trim()
                    ? (config.token_phrase ?? "").trim()
                    : undefined;
            const res = await playAdminFullAnnouncementPreview({
                getCsrfToken,
                lang,
                rate: config.rate,
                voiceId: ttsVoiceIdForConfig(config),
                segment2Enabled,
                segment1: {
                    alias: options.alias || "A1",
                    pronounce_as: options.pronounce_as || "letters",
                    pre_phrase: config.pre_phrase ?? "",
                    token_phrase: tokenPhrase,
                    token_bridge_tail: bridge || undefined,
                },
                connectorPhrase: FULL_PREVIEW_CONNECTOR,
                stationName: "Window 1",
                closingWithoutSegment2: segment2Enabled ? undefined : closing,
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
            ttsPreviewLock = null;
        }
    }

    async function playEditTtsSampleForLang(lang: TtsLangKey) {
        if (!editToken) return;
        await playTtsSampleForLang(lang, editTokenTts[lang], {
            alias: editToken.physical_id,
            pronounce_as: editPronounceAs,
        });
    }

    async function playEditTtsFullForLang(lang: TtsLangKey) {
        if (!editToken) return;
        await playTtsFullSampleForLang(lang, editTokenTts[lang], {
            alias: editToken.physical_id,
            pronounce_as: editPronounceAs,
        });
    }

    async function saveSegment1Defaults() {
        tokenSegment1DefaultsSaving = true;
        try {
            const L = tokenTtsLanguages;
            const res = await fetch("/api/admin/token-tts-settings", {
                method: "PUT",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
                body: JSON.stringify({
                    voice_id: tokenTtsVoiceId || null,
                    rate: tokenTtsRate,
                    languages: {
                        en: {
                            voice_id: L.en.voice_id || null,
                            rate: L.en.rate,
                            pre_phrase: L.en.pre_phrase.trim() || null,
                            token_phrase: L.en.token_phrase.trim() || null,
                        },
                        fil: {
                            voice_id: L.fil.voice_id || null,
                            rate: L.fil.rate,
                            pre_phrase: L.fil.pre_phrase.trim() || null,
                            token_phrase: L.fil.token_phrase.trim() || null,
                        },
                        ilo: {
                            voice_id: L.ilo.voice_id || null,
                            rate: L.ilo.rate,
                            pre_phrase: L.ilo.pre_phrase.trim() || null,
                            token_phrase: L.ilo.token_phrase.trim() || null,
                        },
                    },
                }),
            });
            if (res.status === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return;
            }
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                toaster.error({
                    title:
                        (data && typeof data === "object" && "message" in data && (data as { message?: string }).message) ||
                        "Failed to save default phrases.",
                });
                return;
            }
            toaster.success({ title: "Token prephrase saved." });
            await fetchTokenTtsSettings();
        } catch (e) {
            const isNetwork = e instanceof TypeError && (e as Error).message === "Failed to fetch";
            toaster.error({ title: isNetwork ? MSG_NETWORK_ERROR : "Failed to save default phrases." });
        } finally {
            tokenSegment1DefaultsSaving = false;
        }
    }

    async function openBatchModal() {
        batchPrefix = "A";
        batchStart = 1;
        batchCount = 50;
        batchPronounceAs = "letters";
        showBatchModal = true;
        await fetchTokenTtsSettings();
        batchTts = {
            en: {
                voice_id: tokenTtsVoiceId ?? "",
                rate: tokenTtsRate ?? 0.84,
                pre_phrase: tokenTtsLanguages.en.pre_phrase ?? "",
                token_phrase: "",
                token_bridge_tail: tokenTtsLanguages.en.token_bridge_tail ?? "",
                closing_without_segment2: tokenTtsLanguages.en.closing_without_segment2 ?? "",
            },
            fil: {
                voice_id: "",
                rate: tokenTtsRate ?? 0.84,
                pre_phrase: tokenTtsLanguages.fil.pre_phrase ?? "",
                token_phrase: "",
                token_bridge_tail: tokenTtsLanguages.fil.token_bridge_tail ?? "",
                closing_without_segment2: tokenTtsLanguages.fil.closing_without_segment2 ?? "",
            },
            ilo: {
                voice_id: "",
                rate: tokenTtsRate ?? 0.84,
                pre_phrase: tokenTtsLanguages.ilo.pre_phrase ?? "",
                token_phrase: "",
                token_bridge_tail: tokenTtsLanguages.ilo.token_bridge_tail ?? "",
                closing_without_segment2: tokenTtsLanguages.ilo.closing_without_segment2 ?? "",
            },
        };
    }

    function closeBatchModal() {
        showBatchModal = false;
    }

    function clearFilters() {
        searchQuery = "";
        filterStatus = "";
        fetchTokens();
    }

    async function handleBatchCreate() {
        if (batchCount < 1 || batchCount > 500) return;
        submitting = true;
        const { ok, data, message } = await api(
            "POST",
            "/api/admin/tokens/batch",
            {
                prefix: batchPrefix.trim(),
                count: batchCount,
                start_number: batchStart,
                pronounce_as: batchPronounceAs,
                generate_tts: batchGenerateOfflineTts,
                is_global: batchIsGlobal,
            },
        );
        submitting = false;
        if (ok) {
            toaster.success({ title: "Tokens created." });
            closeBatchModal();
            await fetchTokens();
        } else {
            toaster.error({
                title:
                    message ??
                    (data && "errors" in data
                        ? "Validation failed."
                        : "Failed to create tokens."),
            });
        }
    }

    async function setTokenStatus(token: TokenItem, status: string) {
        submitting = true;
        const { ok, data, message } = await api(
            "PUT",
            `/api/admin/tokens/${token.id}`,
            { status },
        );
        submitting = false;
        if (ok && data?.token) {
            toaster.success({ title: "Status updated." });
            tokens = tokens.map((t) =>
                t.id === token.id ? { ...t, status: data.token.status } : t,
            );
        } else {
            toaster.error({ title: message ?? "Failed to update status." });
        }
    }

    function openCancelSessionConfirm(token: TokenItem) {
        cancelSessionToken = token;
    }

    function closeCancelSessionConfirm() {
        cancelSessionToken = null;
    }

    async function cancelTokenSession(token: TokenItem) {
        // Prefer full session cancel so history and transaction logs are correct.
        if (!token.current_session_id) {
            await setTokenStatus(token, "available");
            return;
        }

        submitting = true;
        const { ok, data, message } = await api(
            "POST",
            `/api/sessions/${token.current_session_id}/cancel`,
            { remarks: "" },
        );
        submitting = false;

        if (ok && data?.token) {
            toaster.success({
                title: "Session cancelled. Token is now available.",
            });
            tokens = tokens.map((t) =>
                t.id === token.id ? { ...t, status: data.token.status } : t,
            );
        } else {
            toaster.error({
                title: message ?? "Failed to cancel session.",
            });
        }
    }

    let editIsGlobal = $state(false);

    function openEditModal(token: TokenItem) {
        editToken = token;
        const rawMode =
            token.pronounce_as === "custom"
                ? "custom"
                : token.pronounce_as === "word"
                  ? "word"
                  : "letters";
        editPronounceAs =
            rawMode === "custom" && !allowCustomPronunciation ? "word" : rawMode;
        editIsGlobal = token.is_global === true;
        const langs =
            (token.tts_settings?.languages as
                | Record<string, { voice_id?: string | null; rate?: number | null; pre_phrase?: string | null; token_phrase?: string | null; token_bridge_tail?: string | null }>
                | undefined) ?? {};
        editTokenTts = {
            en: {
                voice_id: (langs.en?.voice_id as string | null | undefined) ?? "",
                rate:
                    typeof langs.en?.rate === "number"
                        ? (langs.en?.rate as number)
                        : tokenTtsRate ?? 0.84,
                pre_phrase: (langs.en?.pre_phrase as string | null | undefined) ?? "",
                token_phrase: (langs.en?.token_phrase as string | null | undefined) ?? "",
                token_bridge_tail:
                    (langs.en?.token_bridge_tail as string | null | undefined) ?? tokenTtsLanguages.en.token_bridge_tail ?? "",
                closing_without_segment2: "",
            },
            fil: {
                voice_id: (langs.fil?.voice_id as string | null | undefined) ?? "",
                rate:
                    typeof langs.fil?.rate === "number"
                        ? (langs.fil?.rate as number)
                        : tokenTtsRate ?? 0.84,
                pre_phrase: (langs.fil?.pre_phrase as string | null | undefined) ?? "",
                token_phrase: (langs.fil?.token_phrase as string | null | undefined) ?? "",
                token_bridge_tail:
                    (langs.fil?.token_bridge_tail as string | null | undefined) ?? tokenTtsLanguages.fil.token_bridge_tail ?? "",
                closing_without_segment2: "",
            },
            ilo: {
                voice_id: (langs.ilo?.voice_id as string | null | undefined) ?? "",
                rate:
                    typeof langs.ilo?.rate === "number"
                        ? (langs.ilo?.rate as number)
                        : tokenTtsRate ?? 0.84,
                pre_phrase: (langs.ilo?.pre_phrase as string | null | undefined) ?? "",
                token_phrase: (langs.ilo?.token_phrase as string | null | undefined) ?? "",
                token_bridge_tail:
                    (langs.ilo?.token_bridge_tail as string | null | undefined) ?? tokenTtsLanguages.ilo.token_bridge_tail ?? "",
                closing_without_segment2: "",
            },
        };
    }

    function resetEditTokenTtsToSiteDefaults() {
        const r = tokenTtsRate ?? 0.84;
        editTokenTts = {
            en: {
                voice_id: "",
                rate: r,
                pre_phrase: "",
                token_phrase: "",
                token_bridge_tail: tokenTtsLanguages.en.token_bridge_tail ?? "",
                closing_without_segment2: "",
            },
            fil: {
                voice_id: "",
                rate: r,
                pre_phrase: "",
                token_phrase: "",
                token_bridge_tail: tokenTtsLanguages.fil.token_bridge_tail ?? "",
                closing_without_segment2: "",
            },
            ilo: {
                voice_id: "",
                rate: r,
                pre_phrase: "",
                token_phrase: "",
                token_bridge_tail: tokenTtsLanguages.ilo.token_bridge_tail ?? "",
                closing_without_segment2: "",
            },
        };
    }

    function setEditPronounceAs(v: PronounceAsOption) {
        editPronounceAs = v;
        if (v === "letters" || v === "word") {
            resetEditTokenTtsToSiteDefaults();
        }
    }

    function closeEditModal() {
        editToken = null;
    }

    async function openAssignToProgramModal(tokenIds: number[]) {
        assignToProgramTokenIds = tokenIds;
        selectedProgramIdForAssign = null;
        showAssignToProgramModal = true;
        assignToProgramLoading = true;
        programsForAssign = [];
        try {
            const { ok, data } = await api("GET", "/api/admin/programs");
            if (ok && data?.programs) {
                programsForAssign = (data.programs as { id: number; name: string }[]) ?? [];
            }
        } finally {
            assignToProgramLoading = false;
        }
    }

    function closeAssignToProgramModal() {
        showAssignToProgramModal = false;
        assignToProgramTokenIds = [];
        selectedProgramIdForAssign = null;
    }

    async function handleAssignToProgramSubmit() {
        const programId = selectedProgramIdForAssign;
        if (programId == null || assignToProgramTokenIds.length === 0) return;
        assignToProgramSubmitting = true;
        try {
            const body =
                assignToProgramTokenIds.length === 1
                    ? { token_id: assignToProgramTokenIds[0] }
                    : { token_ids: assignToProgramTokenIds };
            const { ok, message } = await api(
                "POST",
                `/api/admin/programs/${programId}/tokens`,
                body,
            );
            if (ok) {
                toaster.success({
                    title:
                        assignToProgramTokenIds.length === 1
                            ? "Token assigned to program."
                            : `${assignToProgramTokenIds.length} tokens assigned to program.`,
                });
                closeAssignToProgramModal();
                if (assignToProgramTokenIds.length > 1) {
                    selectedIds = new Set();
                }
            } else {
                toaster.error({ title: message ?? "Failed to assign to program." });
            }
        } finally {
            assignToProgramSubmitting = false;
        }
    }

    /** One token = one program. Unassign token from its single assigned program (no modal). */
    async function unassignToken(token: TokenItem) {
        const program = token.assigned_programs?.[0];
        if (!program) return;
        unassigningTokenId = token.id;
        try {
            const { ok, message } = await api(
                "DELETE",
                `/api/admin/programs/${program.id}/tokens/${token.id}`,
            );
            if (ok) {
                toaster.success({ title: `Unassigned from ${program.name}.` });
                tokens = tokens.map((t) =>
                    t.id === token.id ? { ...t, assigned_programs: [] } : t,
                );
            } else {
                toaster.error({ title: message ?? "Failed to unassign." });
            }
        } finally {
            unassigningTokenId = null;
        }
    }

    async function saveEdit() {
        if (!editToken) return;
        submitting = true;
        const allowTokenPhrase = allowCustomPronunciation && editPronounceAs === "custom";
        const payload =
            editPronounceAs === "letters" || editPronounceAs === "word"
                ? {
                      pronounce_as: editPronounceAs,
                      is_global: editIsGlobal,
                  }
                : {
                      pronounce_as: "custom",
                      is_global: editIsGlobal,
                      tts: {
                          en: {
                              voice_id: editTokenTts.en.voice_id || null,
                              rate: editTokenTts.en.rate,
                              pre_phrase: editTokenTts.en.pre_phrase.trim() || null,
                              token_phrase: allowTokenPhrase
                                  ? editTokenTts.en.token_phrase.trim() || null
                                  : null,
                          },
                          fil: {
                              voice_id: editTokenTts.fil.voice_id || null,
                              rate: editTokenTts.fil.rate,
                              pre_phrase: editTokenTts.fil.pre_phrase.trim() || null,
                              token_phrase: allowTokenPhrase
                                  ? editTokenTts.fil.token_phrase.trim() || null
                                  : null,
                          },
                          ilo: {
                              voice_id: editTokenTts.ilo.voice_id || null,
                              rate: editTokenTts.ilo.rate,
                              pre_phrase: editTokenTts.ilo.pre_phrase.trim() || null,
                              token_phrase: allowTokenPhrase
                                  ? editTokenTts.ilo.token_phrase.trim() || null
                                  : null,
                          },
                      },
                  };
        const { ok, data, message } = await api("PUT", `/api/admin/tokens/${editToken.id}`, payload);
        submitting = false;
        if (ok && data?.token) {
            tokens = tokens.map((t) =>
                t.id === editToken!.id
                    ? {
                          ...t,
                          pronounce_as: data.token.pronounce_as,
                          is_global: data.token.is_global,
                          tts_settings: data.token.tts_settings,
                      }
                    : t,
            );
            closeEditModal();
        } else {
            toaster.error({ title: message ?? "Failed to update token." });
        }
    }

    // Selection (available and deactivated can be bulk deleted/printed; in_use cannot)
    const selectableForDelete = $derived(
        tokens.filter(
            (t) => t.status === "available" || t.status === "deactivated",
        ),
    );
    const allSelected = $derived(
        selectableForDelete.length > 0 &&
            selectableForDelete.every((t) => selectedIds.has(t.id)),
    );
    const someSelected = $derived(selectedIds.size > 0);
    const selectedForPrint = $derived(
        tokens.filter((t) => selectedIds.has(t.id)),
    );

    function toggleSelectAll() {
        if (allSelected) {
            selectedIds = new Set();
        } else {
            selectedIds = new Set(selectableForDelete.map((t) => t.id));
        }
    }

    function toggleSelect(id: number) {
        const next = new Set(selectedIds);
        if (next.has(id)) next.delete(id);
        else next.add(id);
        selectedIds = next;
    }

    function getPrintUrl(ids: number[], opts?: typeof printSettings): string {
        if (ids.length === 0) return "/admin/tokens/print";
        const s = opts ?? printSettings;
        const params = new URLSearchParams();
        params.set("ids", ids.join(","));
        params.set("cards_per_page", String(s.cards_per_page));
        params.set("paper", s.paper);
        params.set("orientation", s.orientation);
        params.set("hint", s.show_hint ? "1" : "0");
        params.set("cutlines", s.show_cut_lines ? "1" : "0");
        if (s.logo_url?.trim()) params.set("logo_url", s.logo_url.trim());
        if (s.footer_text?.trim())
            params.set("footer_text", s.footer_text.trim());
        if (s.bg_image_url?.trim())
            params.set("bg_image_url", s.bg_image_url.trim());
        return `/admin/tokens/print?${params.toString()}`;
    }

    let printTargetIds = $state<number[]>([]);

    function openPrintModal(ids: number[]) {
        printTargetIds = ids;
        showPrintModal = true;
        fetchPrintSettings();
    }

    function closePrintModal() {
        showPrintModal = false;
    }

    async function fetchPrintSettings() {
        const { ok, data } = await api("GET", "/api/admin/print-settings");
        if (ok && data?.print_settings) {
            const s = data.print_settings;
            printSettings = {
                cards_per_page: s.cards_per_page ?? 6,
                paper: s.paper ?? "a4",
                orientation: s.orientation ?? "portrait",
                show_hint: s.show_hint !== false,
                show_cut_lines: s.show_cut_lines !== false,
                logo_url: s.logo_url ?? "",
                footer_text: s.footer_text ?? "",
                bg_image_url: s.bg_image_url ?? "",
            };
        }
    }

    function doPrint() {
        const url = getPrintUrl(printTargetIds);
        window.open(url, "_blank", "noopener,noreferrer");
        closePrintModal();
    }

    const selectedAvailableCount = $derived(
        tokens.filter((t) => selectedIds.has(t.id) && t.status === "available")
            .length,
    );

    async function handleBatchDeactivate() {
        const toDeactivate = tokens.filter(
            (t) => selectedIds.has(t.id) && t.status === "available",
        );
        if (toDeactivate.length === 0) return;
        if (
            !confirm(
                `Deactivate ${toDeactivate.length} token(s)? They will no longer be available for binding until reactivated.`,
            )
        )
            return;
        submitting = true;
        let failed = 0;
        for (const token of toDeactivate) {
            const { ok, message: msg } = await api(
                "PUT",
                `/api/admin/tokens/${token.id}`,
                { status: "deactivated" },
            );
            if (!ok) {
                failed++;
                toaster.error({ title: msg ?? "Failed to deactivate some tokens." });
            }
        }
        submitting = false;
        if (failed === 0) {
            selectedIds = new Set();
            await fetchTokens();
        }
    }

    async function handleBatchDelete() {
        const ids = [...selectedIds];
        if (ids.length === 0) return;
        if (
            !confirm(
                `Delete ${ids.length} token(s)? They will be soft-deleted and can no longer be used.`,
            )
        )
            return;
        submitting = true;
        const { ok, data, message } = await api(
            "POST",
            "/api/admin/tokens/batch-delete",
            { ids },
        );
        submitting = false;
        if (ok) {
            toaster.success({ title: "Tokens deleted." });
            selectedIds = new Set();
            await fetchTokens();
        } else {
            toaster.error({ title: message ?? "Failed to delete tokens." });
        }
    }

    async function handleDeleteToken(token: TokenItem) {
        if (token.status === "in_use") {
            toaster.error({ title: "Cannot delete token in use." });
            return;
        }
        if (
            !confirm(
                `Delete token ${token.physical_id}? It will be soft-deleted.`,
            )
        )
            return;
        submitting = true;
        const { ok, message } = await api(
            "DELETE",
            `/api/admin/tokens/${token.id}`,
        );
        submitting = false;
        if (ok) {
            toaster.success({ title: "Token deleted." });
            tokens = tokens.filter((t) => t.id !== token.id);
        } else {
            toaster.error({ title: message ?? "Failed to delete token." });
        }
    }

    async function generateTtsForTokens(ids: number[]) {
        if (ids.length === 0) return;
        submitting = true;
        try {
            const res = await fetch("/api/admin/tokens/regenerate-tts", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
                body: JSON.stringify({ token_ids: ids }),
            });
            if (res.status === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return;
            }
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                toaster.error({
                    title:
                        (data && "message" in data && typeof data.message === "string"
                            ? data.message
                            : "Failed to start TTS generation.") ?? "Failed to start TTS generation.",
                });
                return;
            }
            await fetchTokens();
            if (editToken && ids.includes(editToken.id)) {
                const updated = tokens.find((t) => t.id === editToken.id);
                if (updated) editToken = updated;
            }
        } catch (e) {
            const isNetwork = e instanceof TypeError && (e as Error).message === "Failed to fetch";
            toaster.error({ title: isNetwork ? MSG_NETWORK_ERROR : "Failed to start TTS generation." });
        } finally {
            submitting = false;
        }
    }

    /** Tokens with failed TTS or not generated (cleared) can be generated. */
    const selectedIdsNeedingTts = $derived(
        [...selectedIds].filter((id) => {
            const t = tokens.find((tok) => tok.id === id);
            return t && (t.tts_status === "failed" || !t.tts_status);
        }),
    );
    const bulkGenerateTtsDisabled = $derived(
        !someSelected ||
        selectedIdsNeedingTts.length === 0 ||
        submitting,
    );

    const hasAnyGenerating = $derived(
        tokens.some((t) => t.tts_status === "generating"),
    );

    // Real-time TTS status: subscribe only while at least one token is generating; leave when done to save memory.
    $effect(() => {
        const win = window as unknown as { Echo?: { private: (ch: string) => { listen: (ev: string, cb: (e: { token_id: number; tts_status: string; tts_settings?: TokenItem["tts_settings"] }) => void) => void }; leave: (ch: string) => void } };
        if (typeof window === "undefined" || !win.Echo) return;
        if (!hasAnyGenerating) return;
        const Echo = win.Echo;
        const ch = "admin.token-tts";
        Echo.private(ch).listen(".token_tts_status_updated", (e: { token_id: number; tts_status: string; tts_settings?: TokenItem["tts_settings"] & { failure_reason?: string | null } }) => {
            tokens = tokens.map((t) => {
                if (t.id !== e.token_id) return t;
                const settings = e.tts_settings ?? t.tts_settings;
                return {
                    ...t,
                    tts_status: e.tts_status,
                    tts_settings: settings,
                    tts_failure_reason: (settings && typeof settings === "object" && "failure_reason" in settings ? (settings.failure_reason as string | null) : t.tts_failure_reason) ?? null,
                };
            });
        });
        return () => {
            Echo.leave("private-" + ch);
        };
    });

    // Load tokens on mount
    $effect(() => {
        fetchTokens();
        fetchTokenTtsSettings();
    });

    // Fetch programs once for filter dropdown
    $effect(() => {
        let cancelled = false;
        (async () => {
            const { ok, data } = await api("GET", "/api/admin/programs");
            if (!cancelled && ok && data?.programs) {
                programsForFilter = (data.programs as { id: number; name: string }[]) ?? [];
            }
        })();
        return () => {
            cancelled = true;
        };
    });

    // Auto-apply filters when any filter changes; reset to page 1 (debounced for text inputs)
    let filterDebounceHandle: ReturnType<typeof setTimeout> | null = null;
    $effect(() => {
        const status = filterStatus;
        const query = searchQuery;
        const prefix = filterPrefix;
        const assignment = filterAssignment;
        const isGlobal = filterIsGlobal;
        const ttsStatus = filterTtsStatus;
        if (filterDebounceHandle) {
            clearTimeout(filterDebounceHandle);
        }
        filterDebounceHandle = setTimeout(() => {
            tokenPage = 1;
            fetchTokens(1);
        }, 300);
        return () => {
            if (filterDebounceHandle) {
                clearTimeout(filterDebounceHandle);
            }
        };
    });

    // Sync select-all checkbox indeterminate state
    $effect(() => {
        const ind = someSelected && !allSelected;
        if (selectAllCheckbox) selectAllCheckbox.indeterminate = ind;
        if (selectAllCheckboxMobile)
            selectAllCheckboxMobile.indeterminate = ind;
    });
</script>

<svelte:head>
    <title>Tokens — FlexiQueue</title>
</svelte:head>

<AdminLayout>
    <div class="flex flex-col gap-6">
        <div
            class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4"
        >
            <div>
                <h1
                    class="text-2xl font-bold text-surface-950 flex items-center gap-2"
                >
                    <Ticket class="w-6 h-6 text-primary-500" />
                    Token Management
                </h1>
                <p class="mt-2 text-surface-600 max-w-3xl leading-relaxed">
                    Manage and print physical tokens with QR codes. Combine with
                    stations to direct users.
                </p>
            </div>
            <div
                class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full sm:w-auto mt-2 sm:mt-0"
            >
                <button
                    type="button"
                    class="btn preset-filled-primary-500 flex justify-center items-center gap-2 w-full sm:w-auto shadow-sm transition-transform active:scale-95 md:flex hidden"
                    onclick={openBatchModal}
                    aria-label="Create Batch"
                    disabled={!!edgeMode?.admin_read_only}
                    title={edgeMode?.admin_read_only ? 'Changes must be made on the central server and re-synced.' : undefined}
                >
                    <Plus class="w-4 h-4" /> Create Batch
                </button>
            </div>
            <!-- Mobile FAB: circular icon-only bottom-right, above footer (per Phase 3 Configuration) -->
            <button
                type="button"
                class="fixed bottom-[87px] right-[23px] z-50 flex md:hidden items-center justify-center w-14 h-14 rounded-full bg-primary-500 text-primary-contrast-500 shadow-lg hover:bg-primary-600 active:scale-95 transition-transform touch-manipulation"
                onclick={openBatchModal}
                aria-label="Create Batch"
                disabled={!!edgeMode?.admin_read_only}
                title={edgeMode?.admin_read_only ? 'Changes must be made on the central server and re-synced.' : undefined}
            >
                <Plus class="w-6 h-6" aria-hidden="true" />
            </button>
        </div>

        {#if serverTtsConfigured === false}
            <div
                class="rounded-container border border-warning-200 bg-warning-50 p-4 flex flex-wrap items-center gap-3"
                role="alert"
            >
                <p class="text-sm text-warning-900 flex-1 min-w-0">
                    Server TTS is not set up. Add an ElevenLabs API account in
                    <Link
                        href="/admin/settings?tab=integrations"
                        class="font-semibold text-warning-800 underline hover:text-warning-950"
                    >
                        Configuration → Integrations
                    </Link>
                    to generate token audio. Displays can still use browser voices.
                </p>
            </div>
        {/if}

        <div
            id="tokens-token-call-defaults-bar"
            class="rounded-container border border-surface-200 bg-surface-50 shadow-sm overflow-hidden"
        >
            <button
                type="button"
                class="w-full flex items-center justify-between gap-2 p-4 text-left hover:bg-surface-100/80 transition-colors touch-target-h"
                onclick={() => (segment1DefaultsExpanded = !segment1DefaultsExpanded)}
                aria-expanded={segment1DefaultsExpanded}
                aria-controls="tokens-token-call-defaults-panel"
            >
                <span class="text-sm font-medium text-surface-700 flex items-center gap-2">
                    <Volume2 class="w-4 h-4 text-surface-500" />
                    Token pre/tail phrase (site-wide)
                </span>
                <span class="text-surface-500" aria-hidden="true">
                    {#if segment1DefaultsExpanded}
                        <ChevronUp class="w-4 h-4" />
                    {:else}
                        <ChevronDown class="w-4 h-4" />
                    {/if}
                </span>
            </button>
            <div
                id="tokens-token-call-defaults-panel"
                class="px-4 pb-4 pt-0 border-t border-surface-200/80 space-y-4"
                class:hidden={!segment1DefaultsExpanded}
            >
                <p class="text-xs text-surface-600 leading-relaxed">
                    <strong>Pre-phrase</strong> is the default spoken intro before the token ID for every token unless overridden in <strong>Edit token</strong> when custom wording is allowed.
                    <Link href="/admin/settings?tab=token-tts" class="font-semibold text-primary-600 hover:text-primary-700 underline">Configuration → Audio &amp; TTS</Link>
                    has voice, speed, and playback toggles; when <strong>station directions</strong> are off, set <strong>token bridge tail</strong> there (optional text after the spoken token in the same call).
                </p>
                <p class="text-xs text-surface-600 leading-relaxed">
                    <strong>Tip:</strong> Pre-phrase <strong>Calling</strong> with an empty token bridge tail (Audio &amp; TTS, when directions are off) yields a short call (“Calling” plus the spoken token) instead of the long default ending.
                </p>
                {#if tokenTtsLoading}
                    <p class="text-sm text-surface-500 py-2">Loading phrase defaults…</p>
                {:else}
                    <div class="space-y-3">
                        {#each ["en", "fil", "ilo"] as lang}
                            {@const key = lang as TtsLangKey}
                            {@const row = tokenTtsLanguages[key]}
                            {@const langLabel = key === "en" ? "English" : key === "fil" ? "Filipino" : "Ilocano"}
                            <div class="p-3 rounded-container border border-surface-200 bg-surface-100/50">
                                <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">{langLabel}</span>
                                <div class="form-control mt-2">
                                    <label class="label py-0" for="seg1-default-{key}-pre"
                                        ><span class="label-text text-xs font-medium">Pre-phrase</span></label
                                    >
                                    <input
                                        id="seg1-default-{key}-pre"
                                        type="text"
                                        class="input input-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm w-full"
                                        bind:value={row.pre_phrase}
                                        disabled={!allowCustomPronunciation}
                                    />
                                </div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        class="btn btn-sm preset-tonal text-surface-700 border border-surface-200 bg-surface-50 hover:bg-surface-100 disabled:opacity-50"
                                        onclick={() =>
                                            playTtsSampleForLang(key, row, {
                                                alias: "A1",
                                                pronounce_as: "letters",
                                            })}
                                        disabled={ttsPreviewLock !== null}
                                    >
                                        {ttsPreviewLock?.mode === "sample" && ttsPreviewLock.lang === key ? "Playing…" : "Play sample"}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-sm preset-filled-primary-500 shadow-sm disabled:opacity-50"
                                        onclick={() =>
                                            playTtsFullSampleForLang(key, row, {
                                                alias: "A1",
                                                pronounce_as: "letters",
                                            })}
                                        disabled={ttsPreviewLock !== null}
                                    >
                                        {ttsPreviewLock?.mode === "full" && ttsPreviewLock.lang === key ? "Playing…" : "Play full"}
                                    </button>
                                </div>
                            </div>
                        {/each}
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-2 pt-2 border-t border-surface-200/80">
                        <button
                            type="button"
                            class="btn btn-sm preset-filled-primary-500 shadow-sm disabled:opacity-50"
                            onclick={saveSegment1Defaults}
                            disabled={!allowCustomPronunciation || tokenSegment1DefaultsSaving || ttsPreviewLock !== null}
                        >
                            {tokenSegment1DefaultsSaving ? "Saving…" : "Save defaults"}
                        </button>
                    </div>
                {/if}
            </div>
        </div>

        <!-- Bulk actions: always visible; buttons disabled when no selection -->
        <div class="flex flex-wrap items-center gap-2">
        </div>

        {#if someSelected}
            <div
                class="flex flex-col gap-3 rounded-container border border-surface-200 bg-surface-50 p-3 shadow-sm border-primary-200 bg-primary-50/50"
                role="toolbar"
                aria-label="Bulk actions"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-xs font-semibold text-surface-900 flex items-center gap-2">
                        <span
                            class="inline-flex items-center justify-center min-w-[1.5rem] h-6 px-1.5 rounded-full bg-primary-500 text-white text-xs font-bold"
                        >
                            {selectedIds.size}
                        </span>
                        token{selectedIds.size > 1 ? "s" : ""} selected
                    </span>
                    <div class="flex flex-wrap items-center gap-1.5 justify-end">
                        <button
                            type="button"
                            class="btn btn-xs preset-filled-primary-500 flex items-center gap-1 px-2 py-1.5 min-h-0 text-xs shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
                            onclick={() => openPrintModal([...selectedIds])}
                            disabled={submitting}
                            title="Print selected"
                        >
                            <Printer class="w-3 h-3" /> Print
                        </button>
                        <button
                            type="button"
                            class="btn btn-xs preset-tonal text-surface-700 hover:text-surface-950 flex items-center gap-1 px-2 py-1.5 min-h-0 text-xs disabled:opacity-50 disabled:cursor-not-allowed"
                            onclick={() => openAssignToProgramModal([...selectedIds])}
                            disabled={submitting}
                            title="Assign selected tokens to a program"
                        >
                            <FolderPlus class="w-3 h-3" /> Assign
                        </button>
                        <button
                            type="button"
                            class="btn btn-xs preset-tonal text-surface-700 hover:text-surface-950 flex items-center gap-1 px-2 py-1.5 min-h-0 text-xs disabled:opacity-50 disabled:cursor-not-allowed"
                            onclick={() => generateTtsForTokens(selectedIdsNeedingTts)}
                            disabled={bulkGenerateTtsDisabled}
                            title={bulkGenerateTtsDisabled ? "Select tokens with failed or missing token audio" : `Generate token audio for ${selectedIdsNeedingTts.length} token(s)`}
                        >
                            <Volume2 class="w-3 h-3" />
                            {submitting ? "Starting…" : "TTS"}
                        </button>
                        <button
                            type="button"
                            class="btn btn-xs preset-tonal text-surface-700 hover:text-surface-950 flex items-center gap-1 px-2 py-1.5 min-h-0 text-xs disabled:opacity-50 disabled:cursor-not-allowed"
                            onclick={handleBatchDeactivate}
                            disabled={submitting || selectedAvailableCount === 0}
                            title={selectedAvailableCount === 0
                                ? "Select available tokens to deactivate"
                                : `Deactivate ${selectedAvailableCount} token(s)`}
                        >
                            <Ban class="w-3 h-3" />
                            {submitting ? "Deactivating…" : "Deactivate"}
                        </button>
                        <button
                            type="button"
                            class="btn btn-xs preset-outlined bg-surface-50 text-error-600 hover:bg-error-50 border-error-200 flex items-center gap-1 px-2 py-1.5 min-h-0 text-xs disabled:opacity-50 disabled:cursor-not-allowed"
                            onclick={handleBatchDelete}
                            disabled={submitting ||
                                selectedForPrint.some((t) => t.status === "in_use")}
                            title={selectedForPrint.some((t) => t.status === "in_use")
                                ? "Cannot delete tokens in use. Deselect them first."
                                : "Delete selected"}
                        >
                            <Trash2 class="w-3 h-3" /> Delete
                        </button>
                        <button
                            type="button"
                            class="btn btn-xs preset-tonal text-surface-600 hover:text-surface-900 flex items-center gap-1 px-2 py-1.5 min-h-0 text-xs"
                            onclick={() => (selectedIds = new Set())}
                            title="Clear selection"
                        >
                            <XCircle class="w-3 h-3" /> Clear
                        </button>
                    </div>
                </div>
            </div>
        {/if}

        <!-- Filter bar (sticky, expand/collapse) -->
        <div
            id="tokens-filter-bar"
            class="sticky top-0 z-10 rounded-container border border-surface-200 bg-surface-50 shadow-sm overflow-hidden"
        >
            <button
                type="button"
                class="w-full flex items-center justify-between gap-2 p-4 text-left hover:bg-surface-100/80 transition-colors touch-target-h"
                onclick={() => (filtersExpanded = !filtersExpanded)}
                aria-expanded={filtersExpanded}
                aria-controls="tokens-filter-controls"
            >
                <span class="text-sm font-medium text-surface-700 flex items-center gap-2">
                    <Filter class="w-4 h-4 text-surface-500" />
                    Filters
                </span>
                <span class="text-surface-500" aria-hidden="true">
                    {#if filtersExpanded}
                        <ChevronUp class="w-4 h-4" />
                    {:else}
                        <ChevronDown class="w-4 h-4" />
                    {/if}
                </span>
            </button>
            <div
                id="tokens-filter-controls"
                class="flex flex-col sm:flex-row sm:flex-wrap items-stretch sm:items-end gap-4 px-4 pb-4 pt-0 border-t border-surface-200/80"
                class:hidden={!filtersExpanded}
            >
            <div class="form-control">
                <label for="tokens-filter-status" class="label py-0 text-xs font-medium text-surface-600">Status</label>
                <select
                    id="tokens-filter-status"
                    class="select select-sm rounded-container border border-surface-200 w-[140px] touch-target-h"
                    bind:value={filterStatus}
                    aria-label="Filter by status"
                >
                    <option value="">All statuses</option>
                    <option value="available">Available</option>
                    <option value="in_use">In use</option>
                    <option value="deactivated">Deactivated</option>
                </select>
            </div>
            <div class="form-control flex-1 min-w-[10rem]">
                <label for="tokens-filter-search" class="label py-0 text-xs font-medium text-surface-600">Search</label>
                <input
                    id="tokens-filter-search"
                    type="text"
                    class="input input-sm rounded-container border border-surface-200 w-full touch-target-h"
                    placeholder="Search token ID (e.g. A1)"
                    bind:value={searchQuery}
                />
            </div>
            <div class="form-control min-w-[8rem]">
                <label for="tokens-filter-prefix" class="label py-0 text-xs font-medium text-surface-600">Prefix</label>
                <input
                    id="tokens-filter-prefix"
                    type="text"
                    class="input input-sm rounded-container border border-surface-200 w-full touch-target-h"
                    placeholder="ID prefix (e.g. A matches A1, A2)"
                    bind:value={filterPrefix}
                />
            </div>
            <div class="form-control">
                <label for="tokens-filter-assignment" class="label py-0 text-xs font-medium text-surface-600">Assignment</label>
                <select
                    id="tokens-filter-assignment"
                    class="select select-sm rounded-container border border-surface-200 w-[160px] touch-target-h"
                    bind:value={filterAssignment}
                    aria-label="Filter by assignment"
                >
                    <option value="">All</option>
                    <option value="unassigned">Unassigned</option>
                    <option value="global">Global</option>
                    {#each programsForFilter as prog (prog.id)}
                        <option value="program_id:{prog.id}">{prog.name}</option>
                    {/each}
                </select>
            </div>
            <div class="form-control">
                <label for="tokens-filter-global" class="label py-0 text-xs font-medium text-surface-600">Is global</label>
                <select
                    id="tokens-filter-global"
                    class="select select-sm rounded-container border border-surface-200 w-[100px] touch-target-h"
                    bind:value={filterIsGlobal}
                    aria-label="Filter by global"
                >
                    <option value="">All</option>
                    <option value="1">Yes</option>
                    <option value="0">No</option>
                </select>
            </div>
            <div class="form-control">
                <label for="tokens-filter-tts" class="label py-0 text-xs font-medium text-surface-600">TTS status</label>
                <select
                    id="tokens-filter-tts"
                    class="select select-sm rounded-container border border-surface-200 w-[140px] touch-target-h"
                    bind:value={filterTtsStatus}
                    aria-label="Filter by TTS status"
                >
                    <option value="">All</option>
                    <option value="not_generated">Not generated</option>
                    <option value="generating">Generating</option>
                    <option value="pre_generated">Pre-generated</option>
                    <option value="failed">Failed</option>
                </select>
            </div>
            </div>
        </div>

        <!-- Select All for card view only (outside filters; hidden on desktop where table has its own column) -->
        <div class="mt-2 lg:hidden flex items-center">
            <label class="flex items-center gap-2 text-sm font-medium text-surface-700 cursor-pointer label py-0">
                <input
                    type="checkbox"
                    class="checkbox checkbox-sm"
                    bind:this={selectAllCheckboxMobile}
                    checked={allSelected}
                    onchange={toggleSelectAll}
                    disabled={selectableForDelete.length === 0}
                />
                Select All
            </label>
        </div>

        {#if loading && tokens.length === 0}
            <div
                class="rounded-container border border-surface-200 bg-surface-50 p-12 flex flex-col items-center justify-center text-center shadow-sm mt-4"
            >
                <span class="loading-spinner loading-lg text-primary-500 mb-4"
                ></span>
                <p class="text-surface-600 font-medium animate-pulse">
                    Loading tokens...
                </p>
            </div>
        {:else if tokens.length === 0}
            <div
                role="status"
                aria-label="No tokens found"
                class="rounded-container border border-surface-200 bg-surface-50 p-12 flex flex-col items-center justify-center text-center shadow-sm mt-4"
            >
                <div
                    class="bg-surface-100 p-4 rounded-full text-surface-400 mb-4"
                >
                    <Ticket class="w-8 h-8" />
                </div>
                <h3 class="text-lg font-semibold text-surface-950">
                    No tokens found
                </h3>
                <p class="text-surface-600 max-w-sm mt-2 mb-6">
                    {searchQuery || filterStatus
                        ? "Try adjusting your search criteria or clear the filters."
                        : "Create a batch of tokens to get started."}
                </p>
                {#if searchQuery || filterStatus}
                    <button
                        type="button"
                        class="btn preset-filled-primary-500 flex items-center gap-2 touch-target-h"
                        onclick={clearFilters}
                    >
                        <Filter class="w-4 h-4" /> Clear filters
                    </button>
                {:else}
                    <button
                        type="button"
                        class="btn preset-filled-primary-500 flex items-center gap-2 touch-target-h"
                        onclick={openBatchModal}
                    >
                        <Plus class="w-4 h-4" /> Create tokens
                    </button>
                {/if}
            </div>
        {:else}
            <!-- Desktop Table View (lg+): scrollable so table keeps width and scrolls horizontally when needed -->
            <AdminTable class="mt-2 hidden lg:block" tableClass="text-sm" scrollable>
                {#snippet head()}
                    <tr>
                        <th class="w-10 py-2 px-2 text-center text-surface-600 font-medium">
                            <label class="flex items-center justify-center gap-1.5 cursor-pointer">
                                <input
                                    type="checkbox"
                                    class="checkbox checkbox-sm"
                                    bind:this={selectAllCheckbox}
                                    checked={allSelected}
                                    onchange={toggleSelectAll}
                                    disabled={selectableForDelete.length === 0}
                                    aria-label="Select all"
                                />
                                <span class="text-xs font-medium">All</span>
                            </label>
                        </th>
                        <th class="w-36 py-2 px-3 text-center text-surface-600 font-medium">Physical ID</th>
                        <th class="w-32 py-2 px-3 text-center text-surface-600 font-medium">Status</th>
                        <th class="w-40 py-2 px-3 text-center text-surface-600 font-medium">Assigned</th>
                        <th class="w-40 py-2 px-3 text-center text-surface-600 font-medium">Offline TTS</th>
                        <th class="py-2 px-3 text-center text-surface-600 font-medium whitespace-nowrap">Actions</th>
                    </tr>
                {/snippet}
                {#snippet body()}
                    {#each tokens as token (token.id)}
                        <tr>
                            <td class="py-2 px-2 text-center align-middle">
                                {#if token.status === "available" || token.status === "deactivated"}
                                    <input
                                        type="checkbox"
                                        class="checkbox checkbox-sm"
                                        checked={selectedIds.has(token.id)}
                                        onchange={() =>
                                            toggleSelect(token.id)}
                                        aria-label="Select {token.physical_id}"
                                    />
                                {:else}
                                    <div
                                        class="flex justify-center"
                                        aria-label="In-use tokens cannot be selected for bulk actions"
                                        title="In-use tokens cannot be selected for bulk actions"
                                    >
                                        <Ban
                                            class="w-4 h-4 text-surface-300"
                                        />
                                    </div>
                                {/if}
                            </td>
                            <td class="py-2 px-3 align-middle">
                                <span
                                    class="font-mono font-semibold text-surface-900"
                                >
                                    {token.physical_id}
                                </span>
                                {#if token.is_global}
                                    <span class="ml-1.5 badge preset-tonal text-[10px] px-2 py-0.5 rounded-full font-medium text-surface-600" title="Available to all programs in this site">Global</span>
                                {/if}
                            </td>
                            <td class="py-2 px-3 align-middle">
                                {#if token.status === "available"}
                                    <span
                                        class="badge token-card-badge-success text-[10px] px-2 py-0.5 rounded-full inline-flex items-center gap-1 w-max font-medium"
                                    >
                                        <CheckCircle2 class="w-3 h-3" />
                                        Available
                                    </span>
                                {:else if token.status === "in_use"}
                                    <span
                                        class="badge token-card-badge-primary text-[10px] px-2 py-0.5 rounded-full inline-flex items-center gap-1 w-max font-medium"
                                    >
                                        <PlayCircle class="w-3 h-3" /> In use
                                    </span>
                                {:else if token.status === "deactivated"}
                                    <span
                                        class="badge preset-tonal text-[10px] px-2 py-0.5 rounded-full inline-flex items-center gap-1 w-max font-medium text-surface-600"
                                    >
                                        <Ban class="w-3 h-3" /> Deactivated
                                    </span>
                                {:else}
                                    <span
                                        class="badge preset-tonal text-[10px] px-2 py-0.5 rounded-full font-medium"
                                    >
                                        {token.status}
                                    </span>
                                {/if}
                            </td>
                            <td class="py-2 px-3 align-middle">
                                {#if token.assigned_programs?.length}
                                    <span class="text-sm text-surface-800 font-medium">
                                        {token.assigned_programs.map((p) => p.name).join(", ")}
                                    </span>
                                {:else if token.is_global}
                                    <span class="badge preset-tonal text-[10px] px-2 py-0.5 rounded-full font-medium text-surface-600">Global</span>
                                {:else}
                                    <span class="text-sm text-surface-500">Unassigned</span>
                                {/if}
                            </td>
                            <td class="py-2 px-3 align-middle">
                                {#if token.tts_status === "generating"}
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full bg-primary-50 border border-primary-200 text-primary-700 text-[10px] px-2 py-0.5 font-medium"
                                    >
                                        <span class="loading-spinner loading-xs"></span>
                                        Generating…
                                    </span>
                                {:else if token.tts_status === "pre_generated"}
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full bg-success-50 border border-success-200 text-success-700 text-[10px] px-2 py-0.5 font-medium"
                                    >
                                        <CheckCircle2 class="w-3 h-3" />
                                        Pre-generated
                                    </span>
                                {:else if token.tts_status === "failed"}
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full bg-error-50 border border-error-200 text-error-700 text-[10px] px-2 py-0.5 font-medium"
                                        title={token.tts_failure_reason || "Generation failed. Display will fall back to live TTS."}
                                    >
                                        <XCircle class="w-3 h-3" />
                                        Failed
                                    </span>
                                {/if}
                                <div class="mt-1 flex flex-wrap gap-1">
                                    {#each TTS_LANGS as lang}
                                        {@const langStatus = getTokenLangStatus(token, lang)}
                                        <span
                                            class="inline-flex items-center gap-0.5 rounded-full border text-[10px] px-1.5 py-0.5 font-medium {ttsStatusBadgeClass(langStatus)}"
                                            title={`${lang.toUpperCase()}: ${ttsStatusLabel(langStatus)}`}
                                        >
                                            <span class="uppercase">{lang}</span>
                                            {#if langStatus === "ready"}
                                                <CheckCircle2 class="w-2.5 h-2.5" />
                                            {:else if langStatus === "generating"}
                                                <span class="loading-spinner loading-2xs"></span>
                                            {:else}
                                                <XCircle class="w-2.5 h-2.5" />
                                            {/if}
                                        </span>
                                    {/each}
                                </div>
                            </td>
                            <td class="py-2 px-3 text-center align-middle">
                                <div class="flex items-center justify-center gap-1.5 flex-wrap">
                                    <button
                                        type="button"
                                        class="btn btn-sm preset-outlined bg-surface-50 text-surface-600 hover:bg-surface-50 flex items-center gap-1 min-h-[2rem] px-2.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                        onclick={() => openEditModal(token)}
                                        disabled={someSelected || submitting}
                                        title="Edit token"
                                    >
                                        <Pencil class="w-3.5 h-3.5" /> Edit
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-sm preset-outlined bg-surface-50 text-surface-600 hover:bg-surface-50 flex items-center gap-1 min-h-[2rem] px-2.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                        onclick={() => openPrintModal([token.id])}
                                        disabled={someSelected || submitting}
                                        title="Print token"
                                    >
                                        <Printer class="w-3.5 h-3.5" />
                                    </button>
                                    {#if !(token.assigned_programs?.length)}
                                        <button
                                            type="button"
                                            class="btn btn-sm preset-outlined bg-surface-50 text-surface-600 hover:bg-surface-50 flex items-center gap-1 min-h-[2rem] px-2.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                            onclick={() => openAssignToProgramModal([token.id])}
                                            disabled={someSelected || submitting}
                                            title="Assign to program"
                                        >
                                            <FolderPlus class="w-3.5 h-3.5" /> Assign
                                        </button>
                                    {:else}
                                        <button
                                            type="button"
                                            class="btn btn-sm preset-outlined bg-surface-50 text-surface-600 hover:bg-surface-50 flex items-center gap-1 min-h-[2rem] px-2.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                            onclick={() => unassignToken(token)}
                                            disabled={someSelected || submitting || unassigningTokenId === token.id}
                                            title="Unassign from program"
                                        >
                                            {unassigningTokenId === token.id ? "Unassigning…" : "Unassign"}
                                        </button>
                                    {/if}
                                    {#if token.status === "in_use"}
                                        <button
                                            type="button"
                                    class="btn btn-sm preset-outlined bg-surface-50 text-surface-600 hover:bg-surface-50 flex items-center gap-1 min-h-[2rem] px-2.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                    onclick={() => openCancelSessionConfirm(token)}
                                            disabled={someSelected || submitting}
                                    title="Cancel current session"
                                        >
                                            <Ban class="w-3.5 h-3.5" />
                                        </button>
                                    {:else if token.status === "available"}
                                        <button
                                            type="button"
                                            class="btn btn-sm preset-outlined bg-surface-50 text-surface-600 hover:bg-surface-50 flex items-center gap-1 min-h-[2rem] px-2.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                            onclick={() => setTokenStatus(token, "deactivated")}
                                            disabled={someSelected || submitting}
                                            title="Deactivate"
                                        >
                                            <Ban class="w-3.5 h-3.5" />
                                        </button>
                                    {:else if token.status === "deactivated"}
                                        <button
                                            type="button"
                                            class="btn btn-sm preset-outlined bg-surface-50 text-surface-600 hover:bg-surface-50 flex items-center gap-1 min-h-[2rem] px-2.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                            onclick={() => setTokenStatus(token, "available")}
                                            disabled={someSelected || submitting}
                                            title="Activate"
                                        >
                                            <CheckCircle2 class="w-3.5 h-3.5" />
                                        </button>
                                    {/if}
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-token-delete flex items-center gap-1 min-h-[2rem] px-2.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                        onclick={() => handleDeleteToken(token)}
                                        disabled={someSelected || submitting || token.status === "in_use" || !!edgeMode?.admin_read_only}
                                        title={edgeMode?.admin_read_only ? 'Changes must be made on the central server and re-synced.' : 'Delete token'}
                                    >
                                        <Trash2 class="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    {/each}
                {/snippet}
            </AdminTable>

            <!-- Card View (mobile + tablet): 2 cols mobile, 3 cols tablet; hidden on lg where table is shown. Per ui-ux: padding-y bigger; program name marquee when long. -->
            <div class="mt-2 lg:hidden grid grid-cols-2 md:grid-cols-3 gap-3">
                {#each tokens as token (token.id)}
                    {@const programNameText = token.assigned_programs?.length ? token.assigned_programs.map((p) => p.name).join(", ") : (token.is_global ? "Global" : "Unassigned")}
                    {@const programNameLong = programNameText.length > 28}
                    <div
                        class="card bg-surface-50 border border-surface-200 shadow-sm rounded-container flex flex-col gap-2 py-4 px-3 max-h-[14rem] min-h-0 hover:border-surface-300 transition-colors"
                    >
                        <!-- Top row: ID left, checkbox top right -->
                        <div class="flex items-start justify-between gap-2 min-h-0 shrink-0">
                            <span class="font-mono font-semibold text-surface-900 text-sm truncate min-w-0">
                                {token.physical_id}
                                {#if token.is_global}
                                    <span class="ml-1 badge preset-tonal text-[9px] px-1.5 py-0 rounded-full font-medium text-surface-600">Global</span>
                                {/if}
                            </span>
                            {#if token.status === "available" || token.status === "deactivated"}
                                <input
                                    type="checkbox"
                                    class="checkbox checkbox-sm shrink-0 mt-0.5"
                                    checked={selectedIds.has(token.id)}
                                    onchange={() => toggleSelect(token.id)}
                                    aria-label="Select {token.physical_id}"
                                />
                            {:else}
                                <span title="In use" aria-label="In use" class="shrink-0 mt-0.5">
                                    <Ban class="w-4 h-4 text-surface-300" />
                                </span>
                            {/if}
                        </div>
                        <!-- Status + TTS: single compact row -->
                        <div class="flex flex-wrap items-center gap-1.5 shrink-0">
                            {#if token.status === "available"}
                                <span class="badge token-card-badge-success text-[10px] px-2 py-0.5 rounded-full inline-flex items-center gap-0.5 font-medium">
                                    <CheckCircle2 class="w-2.5 h-2.5" /> Available
                                </span>
                            {:else if token.status === "in_use"}
                                <span class="badge token-card-badge-primary text-[10px] px-2 py-0.5 rounded-full inline-flex items-center gap-0.5 font-medium">
                                    <PlayCircle class="w-2.5 h-2.5" /> In use
                                </span>
                            {:else if token.status === "deactivated"}
                                <span class="badge preset-tonal text-[10px] px-2 py-0.5 rounded-full inline-flex items-center gap-0.5 font-medium text-surface-600">
                                    <Ban class="w-2.5 h-2.5" /> Deactivated
                                </span>
                            {:else}
                                <span class="badge preset-tonal text-[10px] px-2 py-0.5 rounded-full font-medium">
                                    {token.status}
                                </span>
                            {/if}
                            {#if token.tts_status === "generating"}
                                <span class="inline-flex items-center gap-0.5 text-primary-700 text-[10px]">
                                    <span class="loading-spinner loading-2xs"></span> TTS…
                                </span>
                            {:else if token.tts_status === "pre_generated"}
                                <span class="inline-flex items-center gap-0.5 text-success-700 text-[10px]">
                                    <CheckCircle2 class="w-2.5 h-2.5" /> TTS
                                </span>
                            {:else if token.tts_status === "failed"}
                                <span class="inline-flex items-center gap-0.5 text-error-700 text-[10px]" title={token.tts_failure_reason || "Generation failed"}>
                                    <XCircle class="w-2.5 h-2.5" /> Failed
                                </span>
                            {/if}
                        </div>
                        <div class="flex flex-wrap items-center gap-1 shrink-0">
                            {#each TTS_LANGS as lang}
                                {@const langStatus = getTokenLangStatus(token, lang)}
                                <span
                                    class="inline-flex items-center gap-0.5 rounded-full border text-[9px] px-1.5 py-0.5 font-medium {ttsStatusBadgeClass(langStatus)}"
                                    title={`${lang.toUpperCase()}: ${ttsStatusLabel(langStatus)}`}
                                >
                                    <span class="uppercase">{lang}</span>
                                    {#if langStatus === "ready"}
                                        <CheckCircle2 class="w-2 h-2" />
                                    {:else if langStatus === "generating"}
                                        <span class="loading-spinner loading-2xs"></span>
                                    {:else}
                                        <XCircle class="w-2 h-2" />
                                    {/if}
                                </span>
                            {/each}
                        </div>
                        <!-- Program name: own row, more padding-y; marquee when overflows container (char count > 28) -->
                        <div class="min-w-0 shrink-0 py-1">
                            {#if programNameLong}
                                <div class="overflow-hidden min-w-0">
                                    <Marquee overflowOnly={true} duration={12} gapEm={1.5} class="text-surface-500 text-[10px] block">
                                        {#snippet children()}
                                            <span class="whitespace-nowrap">{programNameText}</span>
                                        {/snippet}
                                    </Marquee>
                                </div>
                            {:else}
                                <span class="text-surface-500 text-[10px] block truncate">{programNameText}</span>
                            {/if}
                        </div>
                        <!-- Card actions (only buttons dim when another card is selected, via disabled state) -->
                        <div class="pt-2 border-t border-surface-200 flex flex-wrap items-center justify-end gap-1 min-h-0">
                            <button
                                type="button"
                                class="btn btn-xs preset-outlined bg-surface-50 text-surface-600 flex items-center gap-1 px-2 py-1 min-h-0 text-xs disabled:opacity-50 disabled:cursor-not-allowed"
                                onclick={() => openEditModal(token)}
                                disabled={someSelected || submitting}
                                aria-label="Edit token"
                            >
                                <Pencil class="w-3 h-3" /> Edit
                            </button>
                            <button
                                type="button"
                                class="btn btn-xs preset-outlined bg-surface-50 text-surface-600 flex items-center gap-1 px-2 py-1 min-h-0 text-xs disabled:opacity-50 disabled:cursor-not-allowed"
                                onclick={() => openPrintModal([token.id])}
                                disabled={someSelected || submitting}
                                aria-label="Print token"
                            >
                                <Printer class="w-3 h-3" />
                            </button>
                            {#if !(token.assigned_programs?.length)}
                                <button
                                    type="button"
                                    class="btn btn-xs preset-outlined bg-surface-50 text-surface-600 flex items-center gap-1 px-2 py-1 min-h-0 text-xs disabled:opacity-50 disabled:cursor-not-allowed"
                                    onclick={() => openAssignToProgramModal([token.id])}
                                    disabled={someSelected || submitting}
                                    aria-label="Assign to program"
                                >
                                    <FolderPlus class="w-3 h-3" /> Assign
                                </button>
                            {:else}
                                <button
                                    type="button"
                                    class="btn btn-xs preset-outlined bg-surface-50 text-surface-600 flex items-center gap-1 px-2 py-1 min-h-0 text-xs disabled:opacity-50 disabled:cursor-not-allowed"
                                    onclick={() => unassignToken(token)}
                                    disabled={someSelected || submitting || unassigningTokenId === token.id}
                                    aria-label="Unassign from program"
                                >
                                    {unassigningTokenId === token.id ? "Unassigning…" : "Unassign"}
                                </button>
                            {/if}
                            {#if token.status === "in_use"}
                                <button
                                    type="button"
                                    class="btn btn-xs preset-outlined bg-surface-50 text-surface-600 flex items-center gap-1 px-2 py-1 min-h-0 text-xs disabled:opacity-50 disabled:cursor-not-allowed"
                                    onclick={() => openCancelSessionConfirm(token)}
                                    disabled={someSelected || submitting}
                                    aria-label="Cancel current session"
                                >
                                    <Ban class="w-3 h-3" />
                                </button>
                            {:else if token.status === "available"}
                                <button
                                    type="button"
                                    class="btn btn-xs preset-outlined bg-surface-50 text-surface-600 flex items-center gap-1 px-2 py-1 min-h-0 text-xs disabled:opacity-50 disabled:cursor-not-allowed"
                                    onclick={() => setTokenStatus(token, "deactivated")}
                                    disabled={someSelected || submitting}
                                    aria-label="Deactivate"
                                >
                                    <Ban class="w-3 h-3" />
                                </button>
                            {:else if token.status === "deactivated"}
                                <button
                                    type="button"
                                    class="btn btn-xs preset-outlined bg-surface-50 text-surface-600 flex items-center gap-1 px-2 py-1 min-h-0 text-xs disabled:opacity-50 disabled:cursor-not-allowed"
                                    onclick={() => setTokenStatus(token, "available")}
                                    disabled={someSelected || submitting}
                                    aria-label="Activate"
                                >
                                    <CheckCircle2 class="w-3 h-3" />
                                </button>
                            {/if}
                            <button
                                type="button"
                                class="btn btn-xs btn-token-delete flex items-center gap-1 px-2 py-1 min-h-0 text-xs disabled:opacity-50 disabled:cursor-not-allowed"
                                onclick={() => handleDeleteToken(token)}
                                disabled={someSelected || submitting || token.status === "in_use" || !!edgeMode?.admin_read_only}
                                aria-label="Delete token"
                                title={edgeMode?.admin_read_only ? 'Changes must be made on the central server and re-synced.' : undefined}
                            >
                                <Trash2 class="w-3 h-3" /> Delete
                            </button>
                        </div>
                    </div>
                {/each}
            </div>

            <!-- Pagination (per UI/UX checklist: Tables – Pagination) -->
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
                            onclick={() => goToTokenPage(tokenMeta!.current_page - 1)}
                        >
                            Previous
                        </button>
                        <button
                            type="button"
                            class="btn preset-outlined bg-surface-50 text-surface-700 hover:bg-surface-50 flex items-center gap-1 shadow-sm px-4 py-1.5 transition-colors disabled:opacity-50 touch-target-h"
                            disabled={tokenMeta.current_page >= totalPages}
                            onclick={() => goToTokenPage(tokenMeta!.current_page + 1)}
                        >
                            Next
                        </button>
                    </div>
                </div>
            {/if}
        {/if}
    </div>
</AdminLayout>

<Modal
    open={showBatchModal}
    title="Create token batch"
    onClose={closeBatchModal}
>
    {#snippet children()}
        <form
            onsubmit={(e) => {
                e.preventDefault();
                handleBatchCreate();
            }}
            class="flex flex-col gap-5"
        >
            <!-- Batch range -->
            <div class="rounded-container border border-surface-200 bg-surface-50/80 p-4">
                <h3 class="text-sm font-semibold text-surface-700 uppercase tracking-wide mb-3">Batch range</h3>
                <div class="grid grid-cols-3 gap-3">
                    <div class="form-control">
                        <label for="batch-prefix" class="label py-0 min-h-0"><span class="label-text text-xs font-medium text-surface-600">Prefix</span></label>
                        <input
                            id="batch-prefix"
                            type="text"
                            class="input input-sm rounded-container border border-surface-200 bg-white px-3 py-2 w-full"
                            placeholder="ID prefix (e.g. A matches A1, A2)"
                            maxlength="10"
                            bind:value={batchPrefix}
                            required
                        />
                    </div>
                    <div class="form-control">
                        <label for="batch-start" class="label py-0 min-h-0"><span class="label-text text-xs font-medium text-surface-600">Start</span></label>
                        <input
                            id="batch-start"
                            type="number"
                            class="input input-sm rounded-container border border-surface-200 bg-white px-3 py-2 w-full"
                            min="0"
                            bind:value={batchStart}
                            required
                        />
                    </div>
                    <div class="form-control">
                        <label for="batch-count" class="label py-0 min-h-0"><span class="label-text text-xs font-medium text-surface-600">Count</span></label>
                        <input
                            id="batch-count"
                            type="number"
                            class="input input-sm rounded-container border border-surface-200 bg-white px-3 py-2 w-full"
                            min="1"
                            max="500"
                            bind:value={batchCount}
                            required
                        />
                    </div>
                </div>
                <div class="mt-3 pt-3 border-t border-surface-200 flex items-center justify-between gap-2">
                    <span class="text-xs font-medium text-surface-500">Sequence</span>
                    <span class="font-mono text-sm font-semibold text-surface-900 tabular-nums">
                        {batchPrefix || "—"}{batchStart ?? "—"}
                        <span class="text-surface-400 font-normal mx-1.5">→</span>
                        {batchPrefix || "—"}{Number(batchStart) + Number(batchCount) - 1}
                    </span>
                </div>
            </div>

            <!-- Speech & playback -->
            <div class="rounded-container border border-surface-200 bg-surface-50/80 p-4">
                <h3 class="text-sm font-semibold text-surface-700 uppercase tracking-wide mb-3">Speech & playback</h3>
                <div class="space-y-4">
                    <div>
                        <span class="text-xs font-medium text-surface-600 block mb-2">Pronounce as</span>
                        <div class="flex flex-wrap gap-x-4 gap-y-2">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="radio"
                                    name="batch-pronounce"
                                    value="letters"
                                    checked={batchPronounceAs === "letters"}
                                    onchange={() => (batchPronounceAs = "letters")}
                                    class="radio radio-sm"
                                />
                                <span class="text-sm">Letters</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="radio"
                                    name="batch-pronounce"
                                    value="word"
                                    checked={batchPronounceAs === "word"}
                                    onchange={() => (batchPronounceAs = "word")}
                                    class="radio radio-sm"
                                />
                                <span class="text-sm">Word</span>
                            </label>
                        </div>
                    </div>
                    <label class="flex items-start gap-3 cursor-pointer group">
                        <input
                            type="checkbox"
                            class="checkbox checkbox-sm mt-0.5"
                            bind:checked={batchIsGlobal}
                        />
                        <div>
                            <span class="text-sm font-medium text-surface-800 group-hover:text-surface-900">Use for all programs in this site</span>
                            <p class="text-xs text-surface-500 mt-0.5">When enabled, these tokens are available to every program in the site without assigning per program.</p>
                        </div>
                    </label>
                    <label class="flex items-start gap-3 cursor-pointer group">
                        <input
                            type="checkbox"
                            class="checkbox checkbox-sm mt-0.5"
                            bind:checked={batchGenerateOfflineTts}
                        />
                        <div>
                            <span class="text-sm font-medium text-surface-800 group-hover:text-surface-900">Generate audio</span>
                        </div>
                    </label>
                    <div class="pt-2">
                        <span class="text-xs font-medium text-surface-600 block mb-2">Preview (first token)</span>
                        <div class="flex flex-wrap gap-x-3 gap-y-2">
                            {#each ["en", "fil", "ilo"] as prevLang}
                                {@const lk = prevLang as TtsLangKey}
                                <div class="flex flex-wrap items-center gap-1">
                                    <span class="text-[10px] font-semibold uppercase text-surface-500 w-8">{lk}</span>
                                    <button
                                        type="button"
                                        class="btn btn-xs preset-tonal border border-surface-200"
                                        onclick={() =>
                                            playTtsSampleForLang(lk, tokenTtsLanguages[lk], {
                                                alias: String(batchPrefix) + String(batchStart),
                                                pronounce_as: batchPronounceAs,
                                            })}
                                        disabled={ttsPreviewLock !== null || tokenTtsLoading}
                                    >
                                        {ttsPreviewLock?.mode === "sample" && ttsPreviewLock.lang === lk ? "…" : "Sample"}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-xs preset-filled-primary-500"
                                        onclick={() =>
                                            playTtsFullSampleForLang(lk, tokenTtsLanguages[lk], {
                                                alias: String(batchPrefix) + String(batchStart),
                                                pronounce_as: batchPronounceAs,
                                            })}
                                        disabled={ttsPreviewLock !== null || tokenTtsLoading}
                                    >
                                        {ttsPreviewLock?.mode === "full" && ttsPreviewLock.lang === lk ? "…" : "Full"}
                                    </button>
                                </div>
                            {/each}
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-2 border-t border-surface-200">
                <button
                    type="button"
                    class="btn preset-tonal"
                    onclick={closeBatchModal}
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    class="btn preset-filled-primary-500"
                    disabled={submitting ||
                        batchCount < 1 ||
                        batchCount > 500 ||
                        !batchPrefix.toString().trim()}
                >
                    {submitting ? "Creating…" : "Create batch"}
                </button>
            </div>
        </form>
    {/snippet}
</Modal>

<Modal
    open={!!cancelSessionToken}
    title="Cancel token session?"
    onClose={closeCancelSessionConfirm}
>
    {#snippet children()}
        <p class="text-sm text-surface-700">
            This will cancel the current queue session for token
            <span class="font-mono font-semibold">
                {cancelSessionToken?.physical_id}
            </span>
            and mark it as available. This cannot be undone.
        </p>
        <div class="flex justify-end gap-3 mt-5">
            <button
                type="button"
                class="btn preset-tonal"
                onclick={closeCancelSessionConfirm}
                disabled={submitting}
            >
                Keep session
            </button>
            <button
                type="button"
                class="btn preset-filled-error-500 flex items-center gap-2"
                onclick={async () => {
                    if (!cancelSessionToken) return;
                    const token = cancelSessionToken;
                    await cancelTokenSession(token);
                    closeCancelSessionConfirm();
                }}
                disabled={submitting}
            >
                <Ban class="w-4 h-4" /> Cancel session
            </button>
        </div>
    {/snippet}
</Modal>

<Modal
    open={editToken !== null}
    title="Edit token"
    onClose={closeEditModal}
>
    {#snippet children()}
        {#if editToken}
            <form
                onsubmit={(e) => {
                    e.preventDefault();
                    saveEdit();
                }}
                class="flex flex-col gap-4"
            >
                <div class="form-control w-full">
                    <div class="label"><span class="label-text font-medium">Physical ID</span></div>
                    <p class="font-mono font-semibold text-surface-900 px-3 py-2 bg-surface-100 rounded-container border border-surface-200 inline-flex items-center gap-2 flex-wrap">
                        {editToken.physical_id}
                        {#if editToken.is_global}
                            <span class="badge preset-tonal text-[10px] px-2 py-0.5 rounded-full font-medium text-surface-600">Global</span>
                        {/if}
                    </p>
                    <p class="label-text-alt mt-1">Token ID cannot be changed.</p>
                </div>
                <div class="form-control w-full">
                    <label class="label cursor-pointer justify-start gap-3 rounded-lg border border-surface-200 bg-surface-50 p-3">
                        <input
                            type="checkbox"
                            class="checkbox checkbox-sm"
                            bind:checked={editIsGlobal}
                        />
                        <div>
                            <span class="label-text font-medium">Use for all programs in this site</span>
                            <p class="text-xs text-surface-600 mt-0.5">When enabled, this token is available to every program in the site without assigning it per program.</p>
                        </div>
                    </label>
                </div>
                <div class="form-control w-full">
                    <span class="label-text font-medium mb-2 block">Pronounce as (TTS)</span>
                    <div class="flex flex-col sm:flex-row sm:flex-wrap gap-2 sm:gap-x-4 sm:gap-y-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input
                                type="radio"
                                name="edit-pronounce"
                                value="letters"
                                checked={editPronounceAs === "letters"}
                                onchange={() => setEditPronounceAs("letters")}
                                class="radio radio-sm"
                            />
                            <span>Letters</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input
                                type="radio"
                                name="edit-pronounce"
                                value="word"
                                checked={editPronounceAs === "word"}
                                onchange={() => setEditPronounceAs("word")}
                                class="radio radio-sm"
                            />
                            <span>Word</span>
                        </label>
                        {#if allowCustomPronunciation}
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="radio"
                                    name="edit-pronounce"
                                    value="custom"
                                    checked={editPronounceAs === "custom"}
                                    onchange={() => setEditPronounceAs("custom")}
                                    class="radio radio-sm"
                                />
                                <span>Custom</span>
                            </label>
                        {/if}
                    </div>
                    <div class="rounded-container border border-surface-200 bg-surface-50/80 p-3 mt-3">
                        <span class="text-xs font-medium text-surface-600 block mb-2">Hear this token (all languages)</span>
                        <div class="flex flex-wrap gap-x-3 gap-y-2">
                            {#each ["en", "fil", "ilo"] as elang}
                                {@const ek = elang as TtsLangKey}
                                <div class="flex flex-wrap items-center gap-1">
                                    <span class="text-[10px] font-semibold uppercase text-surface-500 w-8">{ek}</span>
                                    <button
                                        type="button"
                                        class="btn btn-xs preset-tonal border border-surface-200"
                                        onclick={() => playEditTtsSampleForLang(ek)}
                                        disabled={ttsPreviewLock !== null || !editToken}
                                    >
                                        {ttsPreviewLock?.mode === "sample" && ttsPreviewLock.lang === ek ? "…" : "Sample"}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-xs preset-filled-primary-500"
                                        onclick={() => playEditTtsFullForLang(ek)}
                                        disabled={ttsPreviewLock !== null || !editToken}
                                    >
                                        {ttsPreviewLock?.mode === "full" && ttsPreviewLock.lang === ek ? "…" : "Full"}
                                    </button>
                                </div>
                            {/each}
                        </div>
                    </div>
                </div>

                {#if editPronounceAs === "custom" && allowCustomPronunciation}
                <div class="rounded-container border border-surface-200 bg-surface-50/80 p-4 space-y-3 max-h-[min(55vh,28rem)] overflow-y-auto">
                    <h3 class="text-sm font-semibold text-surface-700 uppercase tracking-wide">
                        Custom token call (this token only)
                    </h3>
                    <div class="space-y-3">
                        <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                            <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                                <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">English</span>
                                <div class="flex gap-1">
                                    <button type="button" class="btn btn-xs preset-tonal" onclick={() => playEditTtsSampleForLang("en")} disabled={ttsPreviewLock !== null}>
                                        {ttsPreviewLock?.mode === "sample" && ttsPreviewLock.lang === "en" ? "Playing…" : "Play sample"}
                                    </button>
                                    <button type="button" class="btn btn-xs preset-filled-primary-500" onclick={() => playEditTtsFullForLang("en")} disabled={ttsPreviewLock !== null}>
                                        {ttsPreviewLock?.mode === "full" && ttsPreviewLock.lang === "en" ? "Playing…" : "Play full"}
                                    </button>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div class="form-control">
                                    <label class="label" for="edit-tts-en-voice"><span class="label-text text-xs font-medium">Voice</span></label>
                                    <select
                                        id="edit-tts-en-voice"
                                        class="select select-sm rounded-container border border-surface-200 bg-white shadow-sm"
                                        bind:value={editTokenTts.en.voice_id}
                                    >
                                        <option value={""}>Use global token voice</option>
                                        {#each tokenTtsVoices as voice}
                                            <option value={voice.id}>
                                                {voice.name}{voice.lang ? ` (${voice.lang})` : ""}
                                            </option>
                                        {/each}
                                    </select>
                                </div>
                                <div class="form-control">
                                    <label class="label" for="edit-tts-en-rate"><span class="label-text text-xs font-medium">Speed</span></label>
                                    <div class="flex items-center gap-3">
                                        <input
                                            id="edit-tts-en-rate"
                                            type="range"
                                            min="0.5"
                                            max="2"
                                            step="0.05"
                                            class="range range-xs max-w-xs"
                                            bind:value={editTokenTts.en.rate}
                                        />
                                        <span class="text-xs text-surface-600 w-14">
                                            {Number(editTokenTts.en.rate).toFixed(2)}x
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="form-control mt-2">
                                <label class="label" for="edit-tts-en-pre"><span class="label-text text-xs font-medium">Pre-phrase (token call)</span></label>
                                <input
                                    id="edit-tts-en-pre"
                                    type="text"
                                    class="input input-sm rounded-container border border-surface-200 bg-white shadow-sm"
                                    bind:value={editTokenTts.en.pre_phrase}
                                />
                            </div>
                            <div class="form-control mt-2">
                                <label class="label" for="edit-tts-en-token"><span class="label-text text-xs font-medium">Exact spoken wording (optional)</span></label>
                                <input
                                    id="edit-tts-en-token"
                                    type="text"
                                    class="input input-sm rounded-container border border-surface-200 bg-white shadow-sm"
                                    bind:value={editTokenTts.en.token_phrase}
                                />
                            </div>
                        </div>
                        <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                            <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                                <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">Filipino</span>
                                <div class="flex gap-1">
                                    <button type="button" class="btn btn-xs preset-tonal" onclick={() => playEditTtsSampleForLang("fil")} disabled={ttsPreviewLock !== null}>
                                        {ttsPreviewLock?.mode === "sample" && ttsPreviewLock.lang === "fil" ? "Playing…" : "Play sample"}
                                    </button>
                                    <button type="button" class="btn btn-xs preset-filled-primary-500" onclick={() => playEditTtsFullForLang("fil")} disabled={ttsPreviewLock !== null}>
                                        {ttsPreviewLock?.mode === "full" && ttsPreviewLock.lang === "fil" ? "Playing…" : "Play full"}
                                    </button>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div class="form-control">
                                    <label class="label" for="edit-tts-fil-voice"><span class="label-text text-xs font-medium">Voice</span></label>
                                    <select
                                        id="edit-tts-fil-voice"
                                        class="select select-sm rounded-container border border-surface-200 bg-white shadow-sm"
                                        bind:value={editTokenTts.fil.voice_id}
                                    >
                                        <option value={""}>Use global token voice</option>
                                        {#each tokenTtsVoices as voice}
                                            <option value={voice.id}>
                                                {voice.name}{voice.lang ? ` (${voice.lang})` : ""}
                                            </option>
                                        {/each}
                                    </select>
                                </div>
                                <div class="form-control">
                                    <label class="label" for="edit-tts-fil-rate"><span class="label-text text-xs font-medium">Speed</span></label>
                                    <div class="flex items-center gap-3">
                                        <input
                                            id="edit-tts-fil-rate"
                                            type="range"
                                            min="0.5"
                                            max="2"
                                            step="0.05"
                                            class="range range-xs max-w-xs"
                                            bind:value={editTokenTts.fil.rate}
                                        />
                                        <span class="text-xs text-surface-600 w-14">
                                            {Number(editTokenTts.fil.rate).toFixed(2)}x
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="form-control mt-2">
                                <label class="label" for="edit-tts-fil-pre"><span class="label-text text-xs font-medium">Pre-phrase (token call)</span></label>
                                <input
                                    id="edit-tts-fil-pre"
                                    type="text"
                                    class="input input-sm rounded-container border border-surface-200 bg-white shadow-sm"
                                    bind:value={editTokenTts.fil.pre_phrase}
                                />
                            </div>
                            <div class="form-control mt-2">
                                <label class="label" for="edit-tts-fil-token"><span class="label-text text-xs font-medium">Exact spoken wording (optional)</span></label>
                                <input
                                    id="edit-tts-fil-token"
                                    type="text"
                                    class="input input-sm rounded-container border border-surface-200 bg-white shadow-sm"
                                    bind:value={editTokenTts.fil.token_phrase}
                                />
                            </div>
                        </div>
                        <div class="p-3 rounded-container border border-surface-200 bg-surface-50">
                            <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                                <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">Ilocano</span>
                                <div class="flex gap-1">
                                    <button type="button" class="btn btn-xs preset-tonal" onclick={() => playEditTtsSampleForLang("ilo")} disabled={ttsPreviewLock !== null}>
                                        {ttsPreviewLock?.mode === "sample" && ttsPreviewLock.lang === "ilo" ? "Playing…" : "Play sample"}
                                    </button>
                                    <button type="button" class="btn btn-xs preset-filled-primary-500" onclick={() => playEditTtsFullForLang("ilo")} disabled={ttsPreviewLock !== null}>
                                        {ttsPreviewLock?.mode === "full" && ttsPreviewLock.lang === "ilo" ? "Playing…" : "Play full"}
                                    </button>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div class="form-control">
                                    <label class="label" for="edit-tts-ilo-voice"><span class="label-text text-xs font-medium">Voice</span></label>
                                    <select
                                        id="edit-tts-ilo-voice"
                                        class="select select-sm rounded-container border border-surface-200 bg-white shadow-sm"
                                        bind:value={editTokenTts.ilo.voice_id}
                                    >
                                        <option value={""}>Use global token voice</option>
                                        {#each tokenTtsVoices as voice}
                                            <option value={voice.id}>
                                                {voice.name}{voice.lang ? ` (${voice.lang})` : ""}
                                            </option>
                                        {/each}
                                    </select>
                                </div>
                                <div class="form-control">
                                    <label class="label" for="edit-tts-ilo-rate"><span class="label-text text-xs font-medium">Speed</span></label>
                                    <div class="flex items-center gap-3">
                                        <input
                                            id="edit-tts-ilo-rate"
                                            type="range"
                                            min="0.5"
                                            max="2"
                                            step="0.05"
                                            class="range range-xs max-w-xs"
                                            bind:value={editTokenTts.ilo.rate}
                                        />
                                        <span class="text-xs text-surface-600 w-14">
                                            {Number(editTokenTts.ilo.rate).toFixed(2)}x
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="form-control mt-2">
                                <label class="label" for="edit-tts-ilo-pre"><span class="label-text text-xs font-medium">Pre-phrase (token call)</span></label>
                                <input
                                    id="edit-tts-ilo-pre"
                                    type="text"
                                    class="input input-sm rounded-container border border-surface-200 bg-white shadow-sm"
                                    bind:value={editTokenTts.ilo.pre_phrase}
                                />
                            </div>
                            <div class="form-control mt-2">
                                <label class="label" for="edit-tts-ilo-token"><span class="label-text text-xs font-medium">Exact spoken wording (optional)</span></label>
                                <input
                                    id="edit-tts-ilo-token"
                                    type="text"
                                    class="input input-sm rounded-container border border-surface-200 bg-white shadow-sm"
                                    bind:value={editTokenTts.ilo.token_phrase}
                                />
                            </div>
                        </div>
                    </div>
                </div>
                {/if}

                {#if editToken.tts_status !== "pre_generated" && editToken.tts_status !== "generating"}
                    <div class="flex flex-wrap items-center gap-2 pt-1">
                        <button
                            type="button"
                            class="btn preset-tonal border border-surface-200"
                            onclick={() => editToken && generateTtsForTokens([editToken.id])}
                            disabled={submitting}
                            title={editToken?.tts_status === "failed" ? "Regenerate audio" : "Generate audio"}
                        >
                            {submitting ? "Starting…" : "Generate audio"}
                        </button>
                    </div>
                {/if}

                <div class="flex justify-end gap-3 mt-4 pt-4 border-t border-surface-200">
                    <button
                        type="button"
                        class="btn preset-tonal"
                        onclick={closeEditModal}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="btn preset-filled-primary-500 shadow-sm"
                        disabled={submitting}
                    >
                        {submitting ? "Saving…" : "Save"}
                    </button>
                </div>
            </form>
        {/if}
    {/snippet}
</Modal>

<Modal
    open={showAssignToProgramModal}
    title="Assign to program"
    onClose={closeAssignToProgramModal}
>
    {#snippet children()}
        <div class="flex flex-col gap-4">
            <p class="text-sm text-surface-950/80">
                Choose a program to assign {assignToProgramTokenIds.length === 1 ? "this token" : `${assignToProgramTokenIds.length} tokens`} to. The token(s) will then be available for that program.
            </p>
            {#if assignToProgramLoading}
                <p class="text-sm text-surface-600">Loading programs…</p>
            {:else if programsForAssign.length === 0}
                <p class="text-sm text-surface-600">No programs in your site. Create a program first.</p>
            {:else}
                <div class="form-control w-full">
                    <span class="label-text font-medium mb-2 block">Program</span>
                    <div class="flex flex-col gap-2 max-h-48 overflow-y-auto rounded-container border border-surface-200 bg-surface-50 p-2">
                        {#each programsForAssign as prog (prog.id)}
                            <label
                                class="label cursor-pointer justify-start gap-3 rounded-lg border border-surface-200 p-3 transition-colors {selectedProgramIdForAssign === prog.id ? 'bg-primary-50 border-primary-200' : 'bg-surface-50 hover:bg-surface-100'}"
                            >
                                <input
                                    type="radio"
                                    name="assign-program"
                                    class="radio radio-primary radio-sm"
                                    checked={selectedProgramIdForAssign === prog.id}
                                    onchange={() => (selectedProgramIdForAssign = prog.id)}
                                    value={prog.id}
                                />
                                <span class="font-medium text-surface-900">{prog.name}</span>
                            </label>
                        {/each}
                    </div>
                </div>
            {/if}
            <div class="flex justify-end gap-3 pt-2 border-t border-surface-200">
                <button
                    type="button"
                    class="btn preset-tonal"
                    onclick={closeAssignToProgramModal}
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="btn preset-filled-primary-500"
                    disabled={selectedProgramIdForAssign == null || assignToProgramSubmitting || programsForAssign.length === 0}
                    onclick={handleAssignToProgramSubmit}
                >
                    {assignToProgramSubmitting ? "Assigning…" : assignToProgramTokenIds.length === 1 ? "Assign token" : `Assign ${assignToProgramTokenIds.length} tokens`}
                </button>
            </div>
        </div>
    {/snippet}
</Modal>

<Modal open={showPrintModal} title="Print tokens" onClose={closePrintModal}>
    {#snippet children()}
        <div class="flex flex-col gap-5">
            <div class="bg-primary-50 dark:bg-slate-800 border border-primary-200 dark:border-slate-600 rounded-container p-3 flex items-start gap-3">
                <Printer class="w-5 h-5 text-primary-500 shrink-0 mt-0.5" />
                <div>
                    <p class="text-sm font-semibold text-surface-900 dark:text-slate-100">Ready to print</p>
                    <p class="text-sm text-surface-700 dark:text-slate-300 mt-0.5">
                        Print {printTargetIds.length} token(s) with current defaults. Change defaults in Configuration → Print settings.
                    </p>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-2 border-t border-surface-100 dark:border-slate-700">
                <button type="button" class="btn preset-tonal" onclick={closePrintModal}>Cancel</button>
                <button
                    type="button"
                    class="btn preset-filled-primary-500 shadow-sm flex items-center gap-2"
                    onclick={doPrint}
                    disabled={printTargetIds.length === 0}
                    title={printTargetIds.length === 0 ? "Select tokens first" : ""}
                >
                    <Printer class="w-4 h-4" /> Print
                </button>
            </div>
        </div>
    {/snippet}
</Modal>
