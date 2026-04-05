<script lang="ts">
    import AdminLayout from "../../../Layouts/AdminLayout.svelte";
    import ConfirmModal from "../../../Components/ConfirmModal.svelte";
    import { Link } from "@inertiajs/svelte";
    import { get } from "svelte/store";
    import { onMount } from "svelte";
    import { usePage } from "@inertiajs/svelte";
    import { toaster } from "../../../lib/toaster.js";
    import ProgramDefaultsTab from "./ProgramDefaultsTab.svelte";
    import PrintSettingsTab from "./PrintSettingsTab.svelte";
    import TokenTtsSettingsTab from "./TokenTtsSettingsTab.svelte";
    import TtsGenerationTab from "./TtsGenerationTab.svelte";
    import {
        HardDrive,
        Database,
        AudioLines,
        Image as ImageIcon,
        FileText,
        AlertTriangle,
        RefreshCw,
        Trash2,
        Printer,
        FolderKanban,
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
    type AuthProps = {
        auth?: {
            is_super_admin?: boolean;
            user?: { role?: string | { value?: string } };
        };
        edge_mode?: { is_edge?: boolean };
    };

    /** Laravel may serialize UserRole enum as string or { value: "super_admin" }. */
    function resolveIsSuperAdmin(auth: AuthProps["auth"] | undefined): boolean {
        if (auth?.is_super_admin === true) return true;
        const r = auth?.user?.role;
        if (r === "super_admin") return true;
        if (r && typeof r === "object" && "value" in r) {
            return (r as { value?: string }).value === "super_admin";
        }
        return false;
    }

    /**
     * usePage() returns a store — use $page.props (not page.props) or get(page).props.
     * Using page.props left auth undefined, so super_admin always saw the site-admin UI.
     */
    const authProps = $derived($page.props.auth);
    const isSuperAdmin = $derived(resolveIsSuperAdmin(authProps));
    const isEdge = $derived($page.props.edge_mode?.is_edge === true);
    const adminReadOnly = $derived($page.props.edge_mode?.admin_read_only === true);
    /** Site admin: Storage tab only on edge Pi. Super admin: Storage on central server only. */
    const showStorageForSiteAdmin = $derived(!isSuperAdmin && isEdge);
    const showStorageForSuperAdmin = $derived(isSuperAdmin && !isEdge);
    const showStorageTab = $derived(
        showStorageForSiteAdmin || showStorageForSuperAdmin,
    );

    const MSG_SESSION_EXPIRED = "Session expired. Please refresh and try again.";
    const MSG_NETWORK_ERROR = "Network error. Please try again.";

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
    let showClearTtsConfirm = $state(false);
    let clearTtsLoading = $state(false);
    let showClearOrphanedTtsConfirm = $state(false);
    let clearOrphanedTtsLoading = $state(false);

    type AdminSettingsTab = "storage" | "program-defaults" | "print" | "token-tts";
    type SuperAdminSettingsTab =
        | "tts-generation"
        | "program-defaults"
        | "print-platform"
        | "storage";
    type SettingsTab = AdminSettingsTab | SuperAdminSettingsTab;

    /** Default; onMount sets edge admin to Storage and super admin to TTS Generation. */
    let activeTab = $state<SettingsTab>("program-defaults");

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
        try {
            const res = await fetch("/api/admin/system/storage", {
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            });
            if (res.status === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                summary = null;
                return;
            }
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
            const isNetwork = e instanceof TypeError && (e as Error).message === "Failed to fetch";
            toaster.error({
                title: isNetwork ? MSG_NETWORK_ERROR : (e instanceof Error ? e.message : "Failed to load system storage."),
            });
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
            if (res.status === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return;
            }
            const json = (await res.json().catch(() => ({}))) as
                | { cleared?: { bytes: number; file_count: number }; message?: string }
                | { message?: string };
            if (!res.ok) {
                throw new Error(
                    (json as { message?: string }).message ?? "Failed to clear TTS cache.",
                );
            }
            const cleared = (json as { cleared?: { bytes: number; file_count: number } }).cleared;
            toaster.success({
                title:
                    cleared?.file_count != null && cleared?.bytes != null
                        ? `Cleared ${formatBytes(cleared.bytes)} (${cleared.file_count} file${cleared.file_count === 1 ? "" : "s"}).`
                        : "TTS cache cleared.",
            });
            showClearTtsConfirm = false;
            await fetchSummary();
        } catch (e) {
            console.error(e);
            const isNetwork = e instanceof TypeError && (e as Error).message === "Failed to fetch";
            toaster.error({ title: isNetwork ? MSG_NETWORK_ERROR : (e instanceof Error ? e.message : "Failed to clear TTS cache.") });
        } finally {
            clearTtsLoading = false;
        }
    }

    function closeClearTtsConfirm() {
        if (!clearTtsLoading) showClearTtsConfirm = false;
    }

    async function handleClearOrphanedTtsConfirm() {
        clearOrphanedTtsLoading = true;
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
            if (res.status === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return;
            }
            const json = (await res.json().catch(() => ({}))) as
                | { cleared?: { bytes: number; file_count: number }; message?: string }
                | { message?: string };
            if (!res.ok) {
                throw new Error(
                    (json as { message?: string }).message ?? "Failed to remove orphan TTS files.",
                );
            }
            const cleared = (json as { cleared?: { bytes: number; file_count: number } }).cleared;
            toaster.success({
                title:
                    cleared?.file_count != null && cleared?.bytes != null
                        ? `Removed ${formatBytes(cleared.bytes)} (${cleared.file_count} unused file${cleared.file_count === 1 ? "" : "s"}).`
                        : "Unused TTS files removed.",
            });
            showClearOrphanedTtsConfirm = false;
            await fetchSummary();
        } catch (e) {
            console.error(e);
            const isNetwork = e instanceof TypeError && (e as Error).message === "Failed to fetch";
            toaster.error({ title: isNetwork ? MSG_NETWORK_ERROR : (e instanceof Error ? e.message : "Failed to remove orphan TTS files.") });
        } finally {
            clearOrphanedTtsLoading = false;
        }
    }

    function closeClearOrphanedTtsConfirm() {
        if (!clearOrphanedTtsLoading) showClearOrphanedTtsConfirm = false;
    }

    function selectTab(tab: SettingsTab) {
        activeTab = tab;
        if (typeof window !== "undefined") {
            const url = new URL(window.location.href);
            url.searchParams.set("tab", tab);
            window.history.replaceState({}, "", url.pathname + "?" + url.searchParams.toString());
        }
        if (tab === "storage") {
            void fetchSummary();
        }
    }

    onMount(() => {
        const p = get(page);
        const auth = (p?.props as AuthProps | undefined)?.auth;
        const superAdmin = resolveIsSuperAdmin(auth);
        const edge = (p?.props as AuthProps | undefined)?.edge_mode?.is_edge === true;

        if (superAdmin) {
            loading = false;
            if (typeof window !== "undefined") {
                const params = new URLSearchParams(window.location.search);
                const raw = params.get("tab");
                if (raw === "integrations") {
                    const url = new URL(window.location.href);
                    url.searchParams.set("tab", "tts-generation");
                    window.history.replaceState(
                        {},
                        "",
                        url.pathname + "?" + url.searchParams.toString(),
                    );
                    activeTab = "tts-generation";
                } else if (
                    raw === "program-defaults" ||
                    raw === "print-platform" ||
                    raw === "tts-generation"
                ) {
                    activeTab = raw as SuperAdminSettingsTab;
                } else if (raw === "storage" && !edge) {
                    activeTab = "storage";
                    void fetchSummary();
                } else {
                    activeTab = "tts-generation";
                }
            } else {
                activeTab = "tts-generation";
            }
            return;
        }

        const params = typeof window !== "undefined" ? new URLSearchParams(window.location.search) : null;
        const rawTab = params?.get("tab");

        if (edge) {
            if (
                rawTab === "storage" ||
                rawTab === "program-defaults" ||
                rawTab === "print" ||
                rawTab === "token-tts"
            ) {
                activeTab = rawTab as AdminSettingsTab;
            } else {
                activeTab = "storage";
            }
            void fetchSummary();
        } else {
            loading = false;
            if (
                rawTab === "program-defaults" ||
                rawTab === "print" ||
                rawTab === "token-tts"
            ) {
                activeTab = rawTab as AdminSettingsTab;
            } else {
                activeTab = "program-defaults";
            }
        }
    });
</script>

<svelte:head>
    <title>Configuration — FlexiQueue</title>
</svelte:head>

<AdminLayout>
    <div class="flex flex-col gap-6 max-w-[1200px] mx-auto">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1
                    class="text-2xl font-bold text-surface-950 flex items-center gap-2"
                >
                    {#if isSuperAdmin}
                        <AudioLines class="w-6 h-6 text-primary-500" />
                        TTS &amp; platform
                    {:else}
                        <HardDrive class="w-6 h-6 text-primary-500" />
                        Configuration
                    {/if}
                </h1>
                <p class="mt-1 text-sm text-surface-600 max-w-2xl">
                    {#if isSuperAdmin}
                        Platform TTS generation, platform program defaults for new sites, and default print settings for new sites.
                    {:else}
                        Storage, program defaults, print and TTS settings.
                    {/if}
                </p>
            </div>
            {#if !isSuperAdmin && showStorageTab}
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
            {:else if isSuperAdmin && showStorageForSuperAdmin && activeTab === "storage"}
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
            {/if}
        </div>

        {#if isSuperAdmin}
        <div class="flex flex-wrap gap-1 rounded-container border border-surface-200 bg-surface-50 p-1 shadow-sm">
            <button
                type="button"
                class="flex items-center gap-2 px-4 py-2.5 rounded-lg font-medium text-sm transition-colors touch-target-h {activeTab === 'tts-generation'
                    ? 'bg-primary-500 text-primary-contrast-500 shadow-sm'
                    : 'text-surface-700 hover:bg-surface-200 hover:text-surface-950'}"
                onclick={() => selectTab("tts-generation")}
            >
                <AudioLines class="w-4 h-4 shrink-0" />
                TTS Generation
            </button>
            <button
                type="button"
                class="flex items-center gap-2 px-4 py-2.5 rounded-lg font-medium text-sm transition-colors touch-target-h {activeTab === 'program-defaults'
                    ? 'bg-primary-500 text-primary-contrast-500 shadow-sm'
                    : 'text-surface-700 hover:bg-surface-200 hover:text-surface-950'}"
                onclick={() => selectTab("program-defaults")}
            >
                <FolderKanban class="w-4 h-4 shrink-0" />
                Platform program defaults
            </button>
            <button
                type="button"
                class="flex items-center gap-2 px-4 py-2.5 rounded-lg font-medium text-sm transition-colors touch-target-h {activeTab === 'print-platform'
                    ? 'bg-primary-500 text-primary-contrast-500 shadow-sm'
                    : 'text-surface-700 hover:bg-surface-200 hover:text-surface-950'}"
                onclick={() => selectTab("print-platform")}
            >
                <Printer class="w-4 h-4 shrink-0" />
                Default print settings
            </button>
            {#if showStorageForSuperAdmin}
            <button
                type="button"
                class="flex items-center gap-2 px-4 py-2.5 rounded-lg font-medium text-sm transition-colors touch-target-h {activeTab === 'storage'
                    ? 'bg-primary-500 text-primary-contrast-500 shadow-sm'
                    : 'text-surface-700 hover:bg-surface-200 hover:text-surface-950'}"
                onclick={() => selectTab("storage")}
            >
                <HardDrive class="w-4 h-4 shrink-0" />
                Storage
            </button>
            {/if}
        </div>
        {:else}
        <div class="flex flex-wrap gap-1 rounded-container border border-surface-200 bg-surface-50 p-1 shadow-sm">
            {#if showStorageForSiteAdmin}
            <button
                type="button"
                class="flex items-center gap-2 px-4 py-2.5 rounded-lg font-medium text-sm transition-colors touch-target-h {activeTab === 'storage'
                    ? 'bg-primary-500 text-primary-contrast-500 shadow-sm'
                    : 'text-surface-700 hover:bg-surface-200 hover:text-surface-950'}"
                onclick={() => selectTab("storage")}
            >
                <HardDrive class="w-4 h-4 shrink-0" />
                Storage
            </button>
            {/if}
            <button
                type="button"
                class="flex items-center gap-2 px-4 py-2.5 rounded-lg font-medium text-sm transition-colors touch-target-h {activeTab === 'program-defaults'
                    ? 'bg-primary-500 text-primary-contrast-500 shadow-sm'
                    : 'text-surface-700 hover:bg-surface-200 hover:text-surface-950'}"
                onclick={() => selectTab("program-defaults")}
            >
                <FolderKanban class="w-4 h-4 shrink-0" />
                Program defaults
            </button>
            <button
                type="button"
                class="flex items-center gap-2 px-4 py-2.5 rounded-lg font-medium text-sm transition-colors touch-target-h {activeTab === 'print'
                    ? 'bg-primary-500 text-primary-contrast-500 shadow-sm'
                    : 'text-surface-700 hover:bg-surface-200 hover:text-surface-950'}"
                onclick={() => selectTab("print")}
            >
                <Printer class="w-4 h-4 shrink-0" />
                Print settings
            </button>
            <button
                type="button"
                class="flex items-center gap-2 px-4 py-2.5 rounded-lg font-medium text-sm transition-colors touch-target-h {activeTab === 'token-tts'
                    ? 'bg-primary-500 text-primary-contrast-500 shadow-sm'
                    : 'text-surface-700 hover:bg-surface-200 hover:text-surface-950'}"
                onclick={() => selectTab("token-tts")}
            >
                <AudioLines class="w-4 h-4 shrink-0" />
                Audio &amp; TTS
            </button>
        </div>
        {/if}

        <fieldset disabled={adminReadOnly} class="contents">
        {#if isSuperAdmin && activeTab === "tts-generation"}
        <TtsGenerationTab />
        {:else if isSuperAdmin && activeTab === "program-defaults"}
        <section
            class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-6"
        >
            <h2
                class="text-base font-semibold text-surface-950 mb-2 flex items-center gap-2"
            >
                <FolderKanban class="w-5 h-5 text-primary-500" />Platform program
                defaults
            </h2>
            <p class="text-sm text-surface-600 mb-4 max-w-3xl">
                Template copied into each new site&apos;s program defaults (same idea as default print settings). Site admins can then change their site without affecting this platform template.
            </p>
            <ProgramDefaultsTab variant="platform" />
        </section>
        {:else if isSuperAdmin && activeTab === "print-platform"}
        <section
            class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-6"
        >
            <h2
                class="text-base font-semibold text-surface-950 mb-2 flex items-center gap-2"
            >
                <Printer class="w-5 h-5 text-primary-500" />Default print settings
            </h2>
            <p class="text-sm text-surface-600 mb-4 max-w-3xl">
                Template copied into each new site&apos;s print settings when the site
                is created. Site admins can then change their site-scoped print
                settings without affecting this platform default.
            </p>
            <PrintSettingsTab variant="platform" />
        </section>
        {:else if activeTab === "storage"}
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
                            role={summary.disk.used_percent >= 85 ? "alert" : undefined}
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
                    class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-6 flex flex-col gap-4 lg:col-span-2"
                >
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <AudioLines class="w-6 h-6 text-primary-600" />
                            <div>
                                <h2
                                    class="text-sm font-semibold text-surface-950"
                                >
                                    TTS audio cache
                                </h2>
                                <p class="text-xs text-surface-600">
                                    Total size of generated TTS voices and
                                    per-token audio on disk.
                                </p>
                            </div>
                        </div>
                        {#if ttsWarning}
                            <span
                                role="alert"
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
                                class="text-[11px] uppercase tracking-wide text-surface-600 font-semibold"
                            >
                                TTS size
                            </p>
                            <p
                                class="text-lg font-semibold text-surface-950 mt-0.5"
                            >
                                {formatBytes(ttsCategory.bytes)}
                            </p>
                            <p class="text-[11px] text-surface-500 mt-1">
                                {ttsCategory.file_count} file{ttsCategory
                                .file_count === 1 ? "" : "s"}
                            </p>
                        </div>
                        <div>
                            <p
                                class="text-[11px] uppercase tracking-wide text-surface-600 font-semibold"
                            >
                                Share of disk
                            </p>
                            <p
                                class="text-lg font-semibold text-surface-950 mt-0.5"
                            >
                                {formatPercent(ttsShareOfDisk)}
                            </p>
                            <p class="text-[11px] text-surface-500 mt-1">
                                Includes cache and token audio.
                            </p>
                        </div>
                        <div>
                            <p
                                class="text-[11px] uppercase tracking-wide text-surface-600 font-semibold"
                            >
                                Guidance
                            </p>
                            <p class="text-[11px] text-surface-600 mt-0.5">
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
                        <p class="text-[11px] text-surface-500 -mt-1">
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
        {:else if activeTab === "program-defaults"}
        <section class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-6">
            <h2 class="text-base font-semibold text-surface-950 mb-2 flex items-center gap-2"><FolderKanban class="w-5 h-5 text-primary-500" />Program default settings</h2>
            <ProgramDefaultsTab />
        </section>
        {:else if activeTab === "print"}
        <section class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-6">
            <h2 class="text-base font-semibold text-surface-950 mb-2 flex items-center gap-2"><Printer class="w-5 h-5 text-primary-500" />Print settings</h2>
            <PrintSettingsTab />
        </section>
        {:else if activeTab === "token-tts"}
        <section class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-6 flex flex-col gap-4">
            <div>
                <h2 class="text-base font-semibold text-surface-950 mb-1 flex items-center gap-2"><AudioLines class="w-5 h-5 text-primary-500" />Audio &amp; TTS</h2>
                <p class="text-sm text-surface-600 max-w-3xl">
                    Set <strong>voice</strong>, <strong>speed</strong>, and <strong>playback</strong> toggles here; when station directions are off, you can set an optional line after the token call. Default <strong>token call</strong> phrasing (pre-phrase and token wording) is edited on the
                    <Link href="/admin/tokens" class="font-semibold text-primary-600 underline hover:text-primary-700">Tokens</Link>
                    page. <strong>Station directions</strong> (connecting phrase + window or station) are per program: Program → Stations → <em>Connecting phrase TTS</em> and each station&apos;s direction audio when enabled site-wide.
                </p>
            </div>
            <div class="rounded-container border border-surface-200 bg-surface-100/50 p-4 text-sm text-surface-700">
                <span class="font-medium text-surface-900">Quick links</span>
                <ul class="list-disc list-inside mt-2 space-y-1">
                    <li><Link href="/admin/tokens" class="text-primary-600 underline hover:text-primary-700">Tokens</Link> — default token call wording, per-token overrides, samples, generate token call audio</li>
                    <li><Link href="/admin/programs" class="text-primary-600 underline hover:text-primary-700">Programs</Link> — connecting phrases and station direction audio</li>
                </ul>
            </div>
            <TokenTtsSettingsTab />
        </section>
        {/if}
        </fieldset>
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
</AdminLayout>

