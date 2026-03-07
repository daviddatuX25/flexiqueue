<script lang="ts">
    import AdminLayout from "../../../Layouts/AdminLayout.svelte";
    import ConfirmModal from "../../../Components/ConfirmModal.svelte";
    import Modal from "../../../Components/Modal.svelte";
    import { Link } from "@inertiajs/svelte";
    import { get } from "svelte/store";
    import { onMount } from "svelte";
    import { usePage } from "@inertiajs/svelte";
    import {
        HardDrive,
        Database,
        AudioLines,
        Image as ImageIcon,
        FileText,
        AlertTriangle,
        RefreshCw,
        Trash2,
        Plug2,
        ExternalLink,
        Plus,
        Pencil,
        Check,
    } from "lucide-svelte";

    interface StorageCategory {
        bytes: number;
        file_count: number;
        orphaned_bytes?: number;
        orphaned_file_count?: number;
    }

    interface StorageSummary {
        disk: {
            total_bytes: number;
            free_bytes: number;
            used_bytes: number;
            used_percent: number;
        };
        categories: {
            tts_audio?: StorageCategory;
            profile_avatars?: StorageCategory;
            print_images?: StorageCategory;
            logs?: StorageCategory;
            database?: StorageCategory;
            [key: string]: StorageCategory | undefined;
        };
        generated_at: string;
    }

    const page = usePage();

    function getCsrfToken(): string {
        const p = get(page);
        const fromProps = (p?.props as { csrf_token?: string } | undefined)
            ?.csrf_token;
        if (fromProps) return fromProps;
        const metaEl =
            typeof document !== "undefined"
                ? (
                      document.querySelector(
                          'meta[name="csrf-token"]',
                      ) as HTMLMetaElement
                  )?.content
                : "";
        return metaEl ?? "";
    }

    let summary = $state<StorageSummary | null>(null);
    let loading = $state(true);
    let error = $state("");
    let showClearTtsConfirm = $state(false);
    let clearTtsLoading = $state(false);
    let showClearOrphanedTtsConfirm = $state(false);
    let clearOrphanedTtsLoading = $state(false);
    let successMessage = $state("");

    type SettingsTab = "storage" | "integrations";
    let activeTab = $state<SettingsTab>("storage");

    interface TtsAccountApi {
        id: number;
        label: string;
        model_id: string;
        is_active: boolean;
        masked_api_key: string;
    }

    interface ElevenLabsStatus {
        status: "connected" | "not_configured";
        driver: string;
        model_id: string;
        default_voice_id: string;
        voices_count: number;
        accounts?: TtsAccountApi[];
        active_account_id?: number | null;
    }

    interface VoiceItem {
        id: string;
        name: string;
        lang?: string | null;
    }

    interface ElevenLabsUsageSubscription {
        character_count: number;
        character_limit: number;
        next_reset_unix: number | null;
        tier: string | null;
    }

    interface ElevenLabsUsageResponse {
        subscription: ElevenLabsUsageSubscription | null;
        usage_time_series?: { time: number[]; usage: Record<string, number[]> } | null;
        message?: string | null;
    }

    let elevenLabsStatus = $state<ElevenLabsStatus | null>(null);
    let elevenLabsLoading = $state(true);
    let voicesList = $state<VoiceItem[]>([]);
    let voicesLoading = $state(false);
    let accountFormOpen = $state(false);
    let accountFormEditing = $state<TtsAccountApi | null>(null);
    let accountFormLabel = $state("");
    let accountFormApiKey = $state("");
    let accountFormModelId = $state("eleven_multilingual_v2");
    let accountFormSubmitting = $state(false);
    let accountFormError = $state("");
    let deleteAccountTarget = $state<TtsAccountApi | null>(null);
    let deleteAccountLoading = $state(false);
    let usageData = $state<ElevenLabsUsageResponse | null>(null);
    let usageLoading = $state(false);

    function formatBytes(bytes: number): string {
        if (!bytes || bytes <= 0) return "0 B";
        const units = ["B", "KB", "MB", "GB", "TB"];
        const i = Math.min(
            Math.floor(Math.log(bytes) / Math.log(1024)),
            units.length - 1,
        );
        const value = bytes / Math.pow(1024, i);
        return `${value.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
    }

    function formatPercent(value: number): string {
        if (Number.isNaN(value)) return "0%";
        return `${value.toFixed(1)}%`;
    }

    async function fetchSummary() {
        loading = true;
        error = "";
        try {
            const res = await fetch("/api/admin/system/storage", {
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            });
            const json = (await res.json().catch(() => ({}))) as
                | StorageSummary
                | { message?: string };
            if (!res.ok) {
                throw new Error(
                    (json as { message?: string }).message ??
                        "Failed to load system storage.",
                );
            }
            summary = json as StorageSummary;
        } catch (e) {
            console.error(e);
            error =
                e instanceof Error
                    ? e.message
                    : "Failed to load system storage.";
            summary = null;
        } finally {
            loading = false;
        }
    }

    const ttsCategory = $derived(
        summary?.categories?.tts_audio ?? {
            bytes: 0,
            file_count: 0,
            orphaned_bytes: 0,
            orphaned_file_count: 0,
        },
    );
    const ttsOrphanedCount = $derived(ttsCategory.orphaned_file_count ?? 0);
    const ttsOrphanedBytes = $derived(ttsCategory.orphaned_bytes ?? 0);
    const hasOrphanedTts = $derived(ttsOrphanedCount > 0);
    const avatarsCategory = $derived(
        summary?.categories?.profile_avatars ?? { bytes: 0, file_count: 0 },
    );
    const printCategory = $derived(
        summary?.categories?.print_images ?? { bytes: 0, file_count: 0 },
    );
    const logsCategory = $derived(
        summary?.categories?.logs ?? { bytes: 0, file_count: 0 },
    );
    const dbCategory = $derived(
        summary?.categories?.database ?? { bytes: 0, file_count: 0 },
    );

    const ttsShareOfDisk = $derived(
        !summary || !summary.disk.total_bytes
            ? 0
            : (ttsCategory.bytes / summary.disk.total_bytes) * 100,
    );

    const ttsWarning = $derived(
        !!summary &&
            (ttsShareOfDisk >= 20 ||
                ttsCategory.bytes > 0.5 * 1024 * 1024 * 1024),
    );

    async function handleClearTtsConfirm() {
        clearTtsLoading = true;
        error = "";
        successMessage = "";
        try {
            const res = await fetch("/api/admin/system/storage/clear", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
                body: JSON.stringify({ category: "tts_audio" }),
            });
            const json = (await res.json().catch(() => ({}))) as
                | { cleared?: { bytes: number; file_count: number }; message?: string }
                | { message?: string };
            if (!res.ok) {
                throw new Error(
                    (json as { message?: string }).message ?? "Failed to clear TTS cache.",
                );
            }
            const cleared = (json as { cleared?: { bytes: number; file_count: number } }).cleared;
            successMessage =
                cleared?.file_count != null && cleared?.bytes != null
                    ? `Cleared ${formatBytes(cleared.bytes)} (${cleared.file_count} file${cleared.file_count === 1 ? "" : "s"}).`
                    : "TTS cache cleared.";
            showClearTtsConfirm = false;
            await fetchSummary();
        } catch (e) {
            console.error(e);
            error =
                e instanceof Error ? e.message : "Failed to clear TTS cache.";
        } finally {
            clearTtsLoading = false;
        }
    }

    function closeClearTtsConfirm() {
        if (!clearTtsLoading) showClearTtsConfirm = false;
    }

    async function handleClearOrphanedTtsConfirm() {
        clearOrphanedTtsLoading = true;
        error = "";
        successMessage = "";
        try {
            const res = await fetch("/api/admin/system/storage/clear-orphaned-tts", {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            });
            const json = (await res.json().catch(() => ({}))) as
                | { cleared?: { bytes: number; file_count: number }; message?: string }
                | { message?: string };
            if (!res.ok) {
                throw new Error(
                    (json as { message?: string }).message ?? "Failed to remove orphan TTS files.",
                );
            }
            const cleared = (json as { cleared?: { bytes: number; file_count: number } }).cleared;
            successMessage =
                cleared?.file_count != null && cleared?.bytes != null
                    ? `Removed ${formatBytes(cleared.bytes)} (${cleared.file_count} unused file${cleared.file_count === 1 ? "" : "s"}).`
                    : "Unused TTS files removed.";
            showClearOrphanedTtsConfirm = false;
            await fetchSummary();
        } catch (e) {
            console.error(e);
            error =
                e instanceof Error ? e.message : "Failed to remove orphan TTS files.";
        } finally {
            clearOrphanedTtsLoading = false;
        }
    }

    function closeClearOrphanedTtsConfirm() {
        if (!clearOrphanedTtsLoading) showClearOrphanedTtsConfirm = false;
    }

    async function fetchElevenLabsStatus() {
        elevenLabsLoading = true;
        try {
            const res = await fetch("/api/admin/integrations/elevenlabs", {
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            });
            const json = (await res.json().catch(() => ({}))) as
                | ElevenLabsStatus
                | { message?: string };
            if (res.ok && "status" in json) {
                elevenLabsStatus = json as ElevenLabsStatus;
            } else {
                elevenLabsStatus = null;
            }
        } catch {
            elevenLabsStatus = null;
        } finally {
            elevenLabsLoading = false;
        }
    }

    function selectTab(tab: SettingsTab) {
        activeTab = tab;
        if (tab === "integrations") {
            fetchElevenLabsStatus();
            fetchUsage();
        }
    }

    async function fetchUsage() {
        usageLoading = true;
        usageData = null;
        try {
            const res = await fetch("/api/admin/integrations/elevenlabs/usage", {
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            });
            const json = (await res.json().catch(() => ({}))) as ElevenLabsUsageResponse & { error?: string };
            if (res.ok) {
                usageData = json;
            } else {
                usageData = { subscription: null, usage_time_series: null, message: json.message ?? "Usage unavailable." };
            }
        } catch {
            usageData = { subscription: null, usage_time_series: null, message: "Usage unavailable." };
        } finally {
            usageLoading = false;
        }
    }

    async function fetchVoices() {
        voicesLoading = true;
        try {
            const res = await fetch("/api/admin/integrations/elevenlabs/voices", {
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            });
            const json = (await res.json().catch(() => ({}))) as
                | { voices: VoiceItem[] }
                | { message?: string };
            if (res.ok && Array.isArray(json?.voices)) {
                voicesList = json.voices;
            } else {
                voicesList = [];
            }
        } catch {
            voicesList = [];
        } finally {
            voicesLoading = false;
        }
    }

    function openAddAccount() {
        accountFormEditing = null;
        accountFormLabel = "";
        accountFormApiKey = "";
        accountFormModelId = "eleven_multilingual_v2";
        accountFormError = "";
        accountFormOpen = true;
    }

    function openEditAccount(account: TtsAccountApi) {
        accountFormEditing = account;
        accountFormLabel = account.label;
        accountFormApiKey = "";
        accountFormModelId = account.model_id || "eleven_multilingual_v2";
        accountFormError = "";
        accountFormOpen = true;
    }

    function closeAccountForm() {
        if (!accountFormSubmitting) {
            accountFormOpen = false;
            accountFormEditing = null;
            accountFormError = "";
        }
    }

    async function submitAccountForm() {
        accountFormError = "";
        if (!accountFormLabel.trim()) {
            accountFormError = "Label is required.";
            return;
        }
        if (!accountFormEditing && !accountFormApiKey.trim()) {
            accountFormError = "API key is required when adding a new account.";
            return;
        }
        if (accountFormEditing && accountFormApiKey.trim() && accountFormApiKey.length < 10) {
            accountFormError = "API key must be at least 10 characters.";
            return;
        }

        accountFormSubmitting = true;
        try {
            const isEdit = !!accountFormEditing;
            const url = isEdit
                ? `/api/admin/integrations/elevenlabs/accounts/${accountFormEditing!.id}`
                : "/api/admin/integrations/elevenlabs/accounts";
            const body: Record<string, string | boolean> = {
                label: accountFormLabel.trim(),
                model_id: accountFormModelId.trim() || "eleven_multilingual_v2",
            };
            if (accountFormApiKey.trim()) {
                body.api_key = accountFormApiKey.trim();
            }
            const res = await fetch(url, {
                method: isEdit ? "PUT" : "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
                body: JSON.stringify(body),
            });
            const json = (await res.json().catch(() => ({}))) as
                | TtsAccountApi
                | { message?: string; errors?: Record<string, string[]> };
            if (!res.ok) {
                const err = json as { message?: string; errors?: Record<string, string[]> };
                accountFormError =
                    err.errors?.api_key?.[0] ??
                    err.errors?.label?.[0] ??
                    err.message ??
                    "Failed to save account.";
                return;
            }
            accountFormOpen = false;
            await fetchElevenLabsStatus();
        } catch (e) {
            accountFormError =
                e instanceof Error ? e.message : "Failed to save account.";
        } finally {
            accountFormSubmitting = false;
        }
    }

    function confirmDeleteAccount(account: TtsAccountApi) {
        deleteAccountTarget = account;
    }

    function closeDeleteAccount() {
        if (!deleteAccountLoading) deleteAccountTarget = null;
    }

    async function handleDeleteAccount() {
        if (!deleteAccountTarget) return;
        deleteAccountLoading = true;
        try {
            const res = await fetch(
                `/api/admin/integrations/elevenlabs/accounts/${deleteAccountTarget.id}`,
                {
                    method: "DELETE",
                    headers: {
                        Accept: "application/json",
                        "X-CSRF-TOKEN": getCsrfToken(),
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    credentials: "same-origin",
                },
            );
            if (res.ok) {
                deleteAccountTarget = null;
                await fetchElevenLabsStatus();
            }
        } finally {
            deleteAccountLoading = false;
        }
    }

    async function handleActivateAccount(account: TtsAccountApi) {
        try {
            const res = await fetch(
                `/api/admin/integrations/elevenlabs/accounts/${account.id}/activate`,
                {
                    method: "POST",
                    headers: {
                        Accept: "application/json",
                        "X-CSRF-TOKEN": getCsrfToken(),
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    credentials: "same-origin",
                },
            );
            if (res.ok) {
                await fetchElevenLabsStatus();
            }
        } catch {
            /* ignore */
        }
    }

    onMount(() => {
        fetchSummary();
        const params = typeof window !== "undefined" ? new URLSearchParams(window.location.search) : null;
        if (params?.get("tab") === "integrations") {
            activeTab = "integrations";
            fetchElevenLabsStatus();
            fetchUsage();
        }
    });
</script>

<svelte:head>
    <title>System Settings — FlexiQueue</title>
</svelte:head>

<AdminLayout>
    <div class="flex flex-col gap-6 max-w-[1200px] mx-auto">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1
                    class="text-2xl font-bold text-surface-950 flex items-center gap-2"
                >
                    <HardDrive class="w-6 h-6 text-primary-500" />
                    System Settings
                </h1>
                <p class="mt-1 text-sm text-surface-600 max-w-2xl">
                    Monitor storage, integrations, and system configuration.
                </p>
            </div>
            <button
                type="button"
                class="btn preset-filled-primary-500 btn-sm gap-2 shadow-sm hover:shadow-md transition-all"
                onclick={fetchSummary}
                disabled={loading}
            >
                {#if loading}
                    <span class="loading-spinner loading-sm"></span>
                {:else}
                    <RefreshCw class="w-4 h-4" />
                {/if}
                Refresh
            </button>
        </div>

        <!-- Tab switcher -->
        <div class="flex gap-1 rounded-container border border-surface-200 bg-surface-50 p-1 shadow-sm">
            <button
                type="button"
                class="flex items-center gap-2 px-4 py-2.5 rounded-lg font-medium text-sm transition-colors min-h-[2.75rem] {activeTab === 'storage'
                    ? 'bg-primary-500 text-primary-contrast-500 shadow-sm'
                    : 'text-surface-700 hover:bg-surface-200 hover:text-surface-950'}"
                onclick={() => selectTab("storage")}
            >
                <HardDrive class="w-4 h-4 shrink-0" />
                Storage
            </button>
            <button
                type="button"
                class="flex items-center gap-2 px-4 py-2.5 rounded-lg font-medium text-sm transition-colors min-h-[2.75rem] {activeTab === 'integrations'
                    ? 'bg-primary-500 text-primary-contrast-500 shadow-sm'
                    : 'text-surface-700 hover:bg-surface-200 hover:text-surface-950'}"
                onclick={() => selectTab("integrations")}
            >
                <Plug2 class="w-4 h-4 shrink-0" />
                Integrations
            </button>
        </div>

        {#if activeTab === "storage"}
        {#if error}
            <div
                class="bg-error-50 text-error-900 border border-error-200 rounded-container p-4 flex items-center gap-3 shadow-sm"
            >
                <AlertTriangle class="w-5 h-5 text-error-600" />
                <div>
                    <p class="font-semibold text-sm">Failed to load status</p>
                    <p class="text-xs mt-0.5">{error}</p>
                </div>
            </div>
        {/if}

        {#if loading && !summary}
            <div
                class="rounded-container border border-surface-200 bg-surface-50 p-10 flex flex-col items-center justify-center text-center shadow-sm"
            >
                <span
                    class="loading-spinner loading-lg text-primary-500 mb-4"
                ></span>
                <p class="text-surface-600 font-medium animate-pulse">
                    Loading system storage snapshot...
                </p>
            </div>
        {:else if summary}
            <!-- Global disk overview -->
            <section
                class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-6 flex flex-col gap-6"
            >
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <HardDrive class="w-6 h-6 text-primary-500" />
                        <div>
                            <h2
                                class="text-base font-semibold text-surface-950"
                            >
                                Disk usage
                            </h2>
                            <p class="text-xs text-surface-600">
                                Using
                                <span class="font-semibold text-surface-900"
                                    >{formatBytes(
                                        summary.disk.used_bytes,
                                    )}</span
                                >
                                of
                                <span class="font-semibold text-surface-900"
                                    >{formatBytes(
                                        summary.disk.total_bytes,
                                    )}</span
                                >
                                ({formatPercent(
                                    summary.disk.used_percent,
                                )}
                                used)
                            </p>
                        </div>
                    </div>
                    <p class="text-[11px] text-surface-500">
                        Snapshot at
                        <span class="font-medium text-surface-700"
                            >{new Date(
                                summary.generated_at,
                            ).toLocaleString()}</span
                        >
                    </p>
                </div>

                <div class="space-y-2">
                    <div
                        class="w-full h-3 rounded-full overflow-hidden bg-surface-100 border border-surface-200"
                        aria-hidden="true"
                    >
                        <div
                            class="h-full bg-primary-500 transition-all"
                            style={`width: ${Math.min(
                                Math.max(summary.disk.used_percent, 0),
                                100,
                            )}%`}
                        ></div>
                    </div>
                    <div
                        class="flex justify-between text-[11px] text-surface-500"
                    >
                        <span>Free: {formatBytes(summary.disk.free_bytes)}</span>
                        <span
                            class={summary.disk.used_percent >= 85
                                ? "text-error-600 font-semibold"
                                : ""}
                        >
                            {summary.disk.used_percent >= 85
                                ? "Warning: low free space"
                                : "Healthy"}
                        </span>
                    </div>
                </div>
            </section>

            <!-- Storage monitoring: TTS first -->
            <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- TTS audio focus card -->
                <div
                    class="rounded-container border border-primary-200 bg-primary-50/80 shadow-sm p-6 flex flex-col gap-4 lg:col-span-2"
                >
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <AudioLines class="w-6 h-6 text-primary-600" />
                            <div>
                                <h2
                                    class="text-sm font-semibold text-primary-900"
                                >
                                    TTS audio cache
                                </h2>
                                <p class="text-xs text-primary-900/70">
                                    Total size of generated TTS voices and
                                    per-token audio on disk.
                                </p>
                            </div>
                        </div>
                        {#if ttsWarning}
                            <span
                                class="inline-flex items-center gap-1.5 text-[11px] px-2.5 py-1 rounded-full bg-error-100 text-error-800 border border-error-300"
                            >
                                <AlertTriangle
                                    class="w-3.5 h-3.5"
                                />{formatPercent(ttsShareOfDisk)} of disk
                            </span>
                        {/if}
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        <div>
                            <p
                                class="text-[11px] uppercase tracking-wide text-primary-900/70 font-semibold"
                            >
                                TTS size
                            </p>
                            <p
                                class="text-lg font-semibold text-primary-950 mt-0.5"
                            >
                                {formatBytes(ttsCategory.bytes)}
                            </p>
                            <p class="text-[11px] text-primary-900/70 mt-1">
                                {ttsCategory.file_count} file{ttsCategory
                                .file_count === 1 ? "" : "s"}
                            </p>
                        </div>
                        <div>
                            <p
                                class="text-[11px] uppercase tracking-wide text-primary-900/70 font-semibold"
                            >
                                Share of disk
                            </p>
                            <p
                                class="text-lg font-semibold text-primary-950 mt-0.5"
                            >
                                {formatPercent(ttsShareOfDisk)}
                            </p>
                            <p class="text-[11px] text-primary-900/70 mt-1">
                                Includes cache and token audio.
                            </p>
                        </div>
                        <div>
                            <p
                                class="text-[11px] uppercase tracking-wide text-primary-900/70 font-semibold"
                            >
                                Guidance
                            </p>
                            <p class="text-[11px] text-primary-900/80 mt-0.5">
                                If this grows too large, consider trimming old
                                tokens or regenerating only for active sets.
                            </p>
                        </div>
                    </div>
                    {#if hasOrphanedTts}
                        <div
                            class="rounded-container border border-warning-200 bg-warning-50 flex items-start gap-3 p-4"
                        >
                            <AlertTriangle
                                class="w-5 h-5 text-warning-600 shrink-0 mt-0.5"
                            />
                            <div>
                                <p class="text-sm font-semibold text-warning-900">
                                    Unused files detected
                                </p>
                                <p class="text-xs text-warning-900/80 mt-1">
                                    {ttsOrphanedCount} file{ttsOrphanedCount === 1
                                        ? ""
                                        : "s"} ({formatBytes(
                                        ttsOrphanedBytes,
                                    )}) {ttsOrphanedCount === 1
                                        ? "is"
                                        : "are"} not referenced by any token or
                                    station (e.g. old cache or removed tokens).
                                    Clearing the TTS cache is recommended to free
                                    space.
                                </p>
                            </div>
                        </div>
                    {/if}
                    {#if successMessage}
                        <div
                            class="rounded-container border border-primary-200 bg-primary-100/80 px-4 py-2 text-sm text-primary-900"
                        >
                            {successMessage}
                        </div>
                    {/if}
                    <div class="flex flex-wrap justify-end gap-2">
                        {#if hasOrphanedTts}
                            <button
                                type="button"
                                class="btn preset-tonal btn-sm gap-2"
                                disabled={loading || clearOrphanedTtsLoading}
                                onclick={() => (showClearOrphanedTtsConfirm = true)}
                            >
                                <Trash2 class="w-4 h-4" />
                                Remove unused TTS files
                            </button>
                        {/if}
                        <button
                            type="button"
                            class="btn preset-filled-warning-500 btn-sm gap-2"
                            disabled={loading || clearTtsLoading || ttsCategory.file_count === 0}
                            onclick={() => (showClearTtsConfirm = true)}
                        >
                            <Trash2 class="w-4 h-4" />
                            Clear TTS cache
                        </button>
                    </div>
                    {#if hasOrphanedTts}
                        <p class="text-[11px] text-primary-900/70 -mt-1">
                            Remove unused: only deletes files not referenced by any token or station. Clear TTS cache: deletes all TTS files and clears references.
                        </p>
                    {/if}
                </div>

                <!-- Other heavy areas -->
                <div
                    class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-6 flex flex-col gap-4"
                >
                    <h2
                        class="text-sm font-semibold text-surface-950 flex items-center gap-2"
                    >
                        <Database class="w-5 h-5 text-surface-700" />
                        Other storage areas
                    </h2>
                    <div class="space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <ImageIcon class="w-4 h-4 text-surface-600" />
                                <span class="font-medium text-surface-900"
                                    >Profile avatars</span
                                >
                            </div>
                            <div class="text-right text-xs text-surface-600">
                                <div class="font-semibold text-surface-900">
                                    {formatBytes(avatarsCategory.bytes)}
                                </div>
                                <div>
                                    {avatarsCategory.file_count} file
                                    {avatarsCategory.file_count === 1
                                        ? ""
                                        : "s"}
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <ImageIcon class="w-4 h-4 text-surface-600" />
                                <span class="font-medium text-surface-900"
                                    >Print template images</span
                                >
                            </div>
                            <div class="text-right text-xs text-surface-600">
                                <div class="font-semibold text-surface-900">
                                    {formatBytes(printCategory.bytes)}
                                </div>
                                <div>
                                    {printCategory.file_count} file
                                    {printCategory.file_count === 1
                                        ? ""
                                        : "s"}
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <FileText class="w-4 h-4 text-surface-600" />
                                <span class="font-medium text-surface-900"
                                    >Application logs</span
                                >
                            </div>
                            <div class="text-right text-xs text-surface-600">
                                <div class="font-semibold text-surface-900">
                                    {formatBytes(logsCategory.bytes)}
                                </div>
                                <div>
                                    {logsCategory.file_count} file
                                    {logsCategory.file_count === 1
                                        ? ""
                                        : "s"}
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <Database class="w-4 h-4 text-surface-600" />
                                <span class="font-medium text-surface-900"
                                    >Database files</span
                                >
                            </div>
                            <div class="text-right text-xs text-surface-600">
                                <div class="font-semibold text-surface-900">
                                    {formatBytes(dbCategory.bytes)}
                                </div>
                                <div>
                                    {dbCategory.file_count} file
                                    {dbCategory.file_count === 1
                                        ? ""
                                        : "s"}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        {/if}

        <!-- Placeholder for future sections: ZeroTier, OTA, health -->
        <section
            class="rounded-container border border-dashed border-surface-300 bg-surface-50/60 p-5 text-xs text-surface-500 mt-2"
        >
            <p class="font-semibold text-surface-700 mb-1">
                Coming next: network & OTA controls
            </p>
            <p>
                This System page is the home for network (ZeroTier) status and
                over-the-air update controls. For now, it focuses on storage so
                TTS audio and other heavy assets stay within safe limits.
            </p>
        </section>
        {:else if activeTab === "integrations"}
        <!-- Integrations: ElevenLabs -->
        <section
            class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-6 flex flex-col gap-6"
        >
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <AudioLines class="w-6 h-6 text-primary-500" />
                    <div>
                        <h2 class="text-base font-semibold text-surface-950">
                            ElevenLabs
                        </h2>
                        <p class="text-xs text-surface-600">
                            TTS for token call phrases and station announcements.
                        </p>
                    </div>
                </div>
                {#if elevenLabsLoading}
                    <span
                        class="inline-flex items-center gap-1.5 text-[11px] px-2.5 py-1 rounded-full bg-surface-200 text-surface-700"
                    >
                        <span class="loading-spinner loading-xs"></span>
                        Loading…
                    </span>
                {:else if elevenLabsStatus}
                    <span
                        class="inline-flex items-center gap-1.5 text-[11px] px-2.5 py-1 rounded-full {elevenLabsStatus.status === 'connected'
                            ? 'bg-success-100 text-success-800 border border-success-300'
                            : 'bg-warning-100 text-warning-800 border border-warning-300'}"
                    >
                        {elevenLabsStatus.status === "connected"
                            ? "Connected"
                            : "Not configured"}
                    </span>
                {/if}
            </div>

            {#if !elevenLabsLoading && !elevenLabsStatus}
                <div
                    class="rounded-container border border-error-200 bg-error-50 p-4 text-sm text-error-900"
                >
                    Unable to load ElevenLabs integration status.
                </div>
            {:else if elevenLabsStatus && !elevenLabsLoading}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <p class="text-[11px] uppercase tracking-wide text-surface-600 font-semibold">
                            Model
                        </p>
                        <p class="text-sm font-medium text-surface-900 mt-0.5">
                            {elevenLabsStatus.model_id}
                        </p>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-wide text-surface-600 font-semibold">
                            Default voice ID
                        </p>
                        <p class="text-sm font-mono text-surface-900 mt-0.5 truncate" title={elevenLabsStatus.default_voice_id}>
                            {elevenLabsStatus.default_voice_id || "—"}
                        </p>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-wide text-surface-600 font-semibold">
                            Configured voices
                        </p>
                        <p class="text-sm font-medium text-surface-900 mt-0.5">
                            {elevenLabsStatus.voices_count}
                        </p>
                    </div>
                </div>

                <!-- API usage -->
                {#if elevenLabsStatus.status === "connected"}
                    <div>
                        <div class="flex items-center justify-between gap-4 mb-3">
                            <h3 class="text-sm font-semibold text-surface-950">API usage</h3>
                            <button
                                type="button"
                                class="btn preset-tonal btn-sm gap-2"
                                disabled={usageLoading}
                                onclick={fetchUsage}
                            >
                                {#if usageLoading}
                                    <span class="loading-spinner loading-xs"></span>
                                {:else}
                                    <RefreshCw class="w-4 h-4" />
                                {/if}
                                Refresh usage
                            </button>
                        </div>
                        {#if usageLoading && !usageData}
                            <p class="text-sm text-surface-600">Loading usage…</p>
                        {:else if usageData?.subscription}
                            {@const sub = usageData.subscription}
                            {@const usedPercent = sub.character_limit > 0 ? (sub.character_count / sub.character_limit) * 100 : 0}
                            <div class="rounded-container border border-surface-200 bg-surface-50 p-4 space-y-3">
                                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                                    <div>
                                        <p class="text-[11px] uppercase tracking-wide text-surface-600 font-semibold">Characters</p>
                                        <p class="text-sm font-medium text-surface-900 mt-0.5">
                                            <span class={usedPercent >= 90 ? "text-warning-700 font-semibold" : ""}>{sub.character_count.toLocaleString()}</span>
                                            <span class="text-surface-500"> / </span>
                                            <span>{sub.character_limit.toLocaleString()}</span>
                                        </p>
                                        {#if usedPercent >= 90 && sub.character_limit > 0}
                                            <p class="text-[11px] text-warning-700 mt-0.5">
                                                {usedPercent >= 100 ? "Over limit" : "Near limit"}
                                            </p>
                                        {/if}
                                    </div>
                                    <div>
                                        <p class="text-[11px] uppercase tracking-wide text-surface-600 font-semibold">Resets</p>
                                        <p class="text-sm text-surface-900 mt-0.5">
                                            {#if sub.next_reset_unix}
                                                {new Date(sub.next_reset_unix * 1000).toLocaleDateString(undefined, { dateStyle: "medium" })}
                                            {:else}
                                                —
                                            {/if}
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-[11px] uppercase tracking-wide text-surface-600 font-semibold">Tier</p>
                                        <p class="text-sm text-surface-900 mt-0.5">{sub.tier ?? "—"}</p>
                                    </div>
                                </div>
                                {#if usageData?.usage_time_series?.time?.length && usageData.usage_time_series.usage?.All?.length}
                                    {@const series = usageData.usage_time_series}
                                    <div>
                                        <p class="text-[11px] uppercase tracking-wide text-surface-600 font-semibold mb-2">Usage by day (last 30 days)</p>
                                        <div class="max-h-40 overflow-y-auto rounded border border-surface-200 bg-surface-100/50 p-2">
                                            <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                                {#each series.time as timestamp, idx}
                                                    {@const dayVal = series.usage?.All?.[idx] ?? 0}
                                                    {@const dayLabel = new Date(timestamp).toLocaleDateString(undefined, { month: "short", day: "numeric" })}
                                                    {#if dayVal > 0 || idx === series.time.length - 1}
                                                        <span class="text-surface-700">{dayLabel}: <span class="font-medium text-surface-900">{Math.round(dayVal).toLocaleString()}</span></span>
                                                    {/if}
                                                {/each}
                                            </div>
                                        </div>
                                    </div>
                                {/if}
                            </div>
                        {:else if usageData?.message}
                            <p class="text-sm text-surface-600">{usageData.message}</p>
                        {:else}
                            <p class="text-sm text-surface-600">Add an ElevenLabs API account to see usage.</p>
                        {/if}
                    </div>
                {/if}

                <!-- API accounts -->
                <div>
                    <div class="flex items-center justify-between gap-4 mb-3">
                        <h3 class="text-sm font-semibold text-surface-950">API accounts</h3>
                        {#if (elevenLabsStatus.accounts?.length ?? 0) > 0 || elevenLabsStatus.status === "connected"}
                            <button
                                type="button"
                                class="btn preset-filled-primary-500 btn-sm gap-2"
                                onclick={openAddAccount}
                            >
                                <Plus class="w-4 h-4" />
                                Add account
                            </button>
                        {:else}
                            <button
                                type="button"
                                class="btn preset-filled-primary-500 btn-sm gap-2"
                                onclick={openAddAccount}
                            >
                                <Plus class="w-4 h-4" />
                                Add first account
                            </button>
                        {/if}
                    </div>
                    {#if (elevenLabsStatus.accounts?.length ?? 0) > 0}
                        <div class="space-y-2">
                            {#each elevenLabsStatus.accounts ?? [] as account (account.id)}
                                <div
                                    class="rounded-container border border-surface-200 bg-surface-50 p-4 flex flex-wrap items-center justify-between gap-3"
                                >
                                    <div class="flex items-center gap-3">
                                        <div>
                                            <p class="font-medium text-surface-900">{account.label}</p>
                                            <p class="text-xs text-surface-600">
                                                Model: <span class="font-mono">{account.model_id}</span>
                                                {#if account.is_active}
                                                    <span
                                                        class="inline-flex items-center gap-1 ml-2 px-1.5 py-0.5 rounded text-[10px] font-medium bg-success-100 text-success-800 border border-success-300"
                                                    >
                                                        Active
                                                    </span>
                                                {/if}
                                            </p>
                                            <p class="text-[11px] text-surface-500 mt-0.5">{account.masked_api_key}</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        {#if !account.is_active}
                                            <button
                                                type="button"
                                                class="btn preset-tonal btn-sm gap-1"
                                                onclick={() => handleActivateAccount(account)}
                                                title="Set as active"
                                            >
                                                <Check class="w-3.5 h-3.5" />
                                                Activate
                                            </button>
                                        {/if}
                                        <button
                                            type="button"
                                            class="btn preset-tonal btn-sm btn-icon"
                                            onclick={() => openEditAccount(account)}
                                            title="Edit"
                                        >
                                            <Pencil class="w-3.5 h-3.5" />
                                        </button>
                                        <button
                                            type="button"
                                            class="btn preset-filled-error-500 btn-sm btn-icon"
                                            onclick={() => confirmDeleteAccount(account)}
                                            title="Delete"
                                        >
                                            <Trash2 class="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                </div>
                            {/each}
                        </div>
                    {:else}
                        <div class="rounded-container border border-dashed border-surface-300 bg-surface-50/60 p-4 text-sm text-surface-600">
                            <p>
                                Add your first ElevenLabs API account below. Token and station TTS will use this account; you can add more and switch the active one later. Without an account, server-side TTS is disabled and displays use browser voices.
                            </p>
                        </div>
                    {/if}
                </div>

                <!-- Voices -->
                <div>
                    <div class="flex items-center justify-between gap-4 mb-3">
                        <h3 class="text-sm font-semibold text-surface-950">Voices</h3>
                        {#if elevenLabsStatus.status === "connected"}
                            <button
                                type="button"
                                class="btn preset-tonal btn-sm gap-2"
                                disabled={voicesLoading}
                                onclick={fetchVoices}
                            >
                                {#if voicesLoading}
                                    <span class="loading-spinner loading-xs"></span>
                                {:else}
                                    <RefreshCw class="w-4 h-4" />
                                {/if}
                                Refresh voices
                            </button>
                        {/if}
                    </div>
                    {#if elevenLabsStatus.status === "connected"}
                        {#if voicesList.length > 0}
                            <div class="max-h-60 overflow-y-auto rounded-container border border-surface-200 bg-surface-50 p-3">
                                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2 text-sm">
                                    {#each voicesList as voice (voice.id)}
                                        <div class="flex items-center justify-between gap-2 py-1.5 px-2 rounded bg-surface-100/80">
                                            <span class="font-medium text-surface-900 truncate">{voice.name}</span>
                                            <span class="text-[11px] font-mono text-surface-500 shrink-0 truncate max-w-[6rem]" title={voice.id}>{voice.id}</span>
                                        </div>
                                    {/each}
                                </div>
                            </div>
                        {:else if !voicesLoading}
                            <p class="text-sm text-surface-600">
                                Click "Refresh voices" to load voices from the active ElevenLabs account.
                            </p>
                        {/if}
                    {:else}
                        <p class="text-sm text-surface-600">
                            Add an ElevenLabs API account or configure .env to see available voices.
                        </p>
                    {/if}
                </div>

                <p class="text-sm text-surface-600">
                    <Link
                        href="/admin/tokens"
                        class="inline-flex items-center gap-1.5 text-primary-600 hover:text-primary-700 font-medium"
                    >
                        Configure default voices per language on Tokens page
                        <ExternalLink class="w-4 h-4 shrink-0" />
                    </Link>
                </p>
            {/if}
        </section>
        {/if}
    </div>

    <ConfirmModal
        open={showClearTtsConfirm}
        title="Clear TTS cache"
        message="This will delete all cached TTS and token voice files and clear references. Tokens can be regenerated later. Continue?"
        confirmLabel="Clear cache"
        variant="warning"
        loading={clearTtsLoading}
        onConfirm={handleClearTtsConfirm}
        onCancel={closeClearTtsConfirm}
    />
    <ConfirmModal
        open={showClearOrphanedTtsConfirm}
        title="Remove unused TTS files"
        message="Only files not referenced by any token or station will be deleted. Token and station data will not be changed. Continue?"
        confirmLabel="Remove unused"
        variant="warning"
        loading={clearOrphanedTtsLoading}
        onConfirm={handleClearOrphanedTtsConfirm}
        onCancel={closeClearOrphanedTtsConfirm}
    />

    <ConfirmModal
        open={!!deleteAccountTarget}
        title="Remove ElevenLabs account"
        message={deleteAccountTarget ? `Remove "${deleteAccountTarget.label}"? TTS will use another account or .env if available.` : ""}
        confirmLabel="Remove"
        variant="warning"
        loading={deleteAccountLoading}
        onConfirm={handleDeleteAccount}
        onCancel={closeDeleteAccount}
    />

    <Modal
        open={accountFormOpen}
        title={accountFormEditing ? "Edit ElevenLabs account" : "Add ElevenLabs account"}
        onClose={closeAccountForm}
    >
        {#snippet children()}
            <form
                class="space-y-4"
                onsubmit={(e) => {
                    e.preventDefault();
                    submitAccountForm();
                }}
            >
                {#if accountFormError}
                    <div class="rounded-container border border-error-200 bg-error-50 px-3 py-2 text-sm text-error-900">
                        {accountFormError}
                    </div>
                {/if}
                <div>
                    <label for="account-label" class="block text-sm font-medium text-surface-900 mb-1">Label</label>
                    <input
                        id="account-label"
                        type="text"
                        class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                        placeholder="e.g. Production, Free tier"
                        bind:value={accountFormLabel}
                        required
                    />
                </div>
                <div>
                    <label for="account-api-key" class="block text-sm font-medium text-surface-900 mb-1">
                        API key
                        {#if accountFormEditing}
                            <span class="text-surface-500 font-normal">(leave blank to keep current)</span>
                        {/if}
                    </label>
                    <input
                        id="account-api-key"
                        type="password"
                        class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                        placeholder={accountFormEditing ? "••••••••" : "Your ElevenLabs API key"}
                        bind:value={accountFormApiKey}
                        required={!accountFormEditing}
                    />
                    <p class="text-xs text-surface-500 mt-0.5">
                        Get your key from <a href="https://elevenlabs.io/app/settings/api-keys" target="_blank" rel="noopener noreferrer" class="text-primary-600 hover:underline">ElevenLabs API settings</a>.
                    </p>
                </div>
                <div>
                    <label for="account-model" class="block text-sm font-medium text-surface-900 mb-1">Model ID</label>
                    <input
                        id="account-model"
                        type="text"
                        class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                        placeholder="eleven_multilingual_v2"
                        bind:value={accountFormModelId}
                    />
                    <p class="text-xs text-surface-500 mt-0.5">
                        Default: eleven_multilingual_v2
                    </p>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="btn preset-tonal" disabled={accountFormSubmitting} onclick={closeAccountForm}>
                        Cancel
                    </button>
                    <button type="submit" class="btn preset-filled-primary-500" disabled={accountFormSubmitting}>
                        {#if accountFormSubmitting}
                            <span class="loading-spinner loading-sm"></span>
                        {:else}
                            {accountFormEditing ? "Update" : "Add account"}
                        {/if}
                    </button>
                </div>
            </form>
        {/snippet}
    </Modal>
</AdminLayout>

