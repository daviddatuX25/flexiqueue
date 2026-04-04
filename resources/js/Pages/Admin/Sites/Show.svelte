<script lang="ts">
    /**
     * Per central-edge B.5: Site show — masked API key, regenerate, edge settings form.
     */
    import AdminLayout from "../../../Layouts/AdminLayout.svelte";
    import { Link, router } from "@inertiajs/svelte";
    import { get } from "svelte/store";
    import { usePage } from "@inertiajs/svelte";
    import Modal from "../../../Components/Modal.svelte";
    import ConfirmModal from "../../../Components/ConfirmModal.svelte";
    import { toaster } from "../../../lib/toaster.js";
    import { compressImage, HERO_BANNER_PRESET, getUploadHint } from "../../../lib/imageUtils.js";
    import QrDisplay from "../../../Components/QrDisplay.svelte";
    import ScopedRbacTeamAccessPanel from "../../../Components/admin/ScopedRbacTeamAccessPanel.svelte";
    import EdgeDevicesPanel from "../../../Components/admin/EdgeDevicesPanel.svelte";
    import {
        Building2,
        Key,
        RefreshCw,
        Copy,
        ArrowLeft,
        Save,
        Share2,
        ChevronUp,
        ChevronDown,
        Pencil,
        Trash2,
        Plus,
        Gauge,
        Info,
        AlertTriangle,
    } from "lucide-svelte";

    interface EdgeSettings {
        sync_clients: boolean;
        sync_client_scope: "program_history" | "all";
        sync_tokens: boolean;
        sync_tts: boolean;
        bridge_enabled: boolean;
        offline_binding_mode_override: "optional" | "required";
        scheduled_sync_time: string;
        offline_allow_client_creation: boolean;
        max_edge_devices?: number;
    }

    const DEFAULT_EDGE: EdgeSettings = {
        sync_clients: true,
        sync_client_scope: "program_history",
        sync_tokens: true,
        sync_tts: true,
        bridge_enabled: false,
        offline_binding_mode_override: "optional",
        scheduled_sync_time: "17:00",
        offline_allow_client_creation: true,
        max_edge_devices: 0,
    };

    interface SiteOption {
        id: number;
        name: string;
        slug: string;
        is_default?: boolean;
    }

    let {
        site,
        site_landing_url = "",
        site_entry_short_url = null,
        api_key_masked = "sk_live_...****",
        default_site_id = null,
        auth_is_super_admin = false,
        sites = [],
        programs = [],
        rbac_team = null as {
            id: number;
            type: string;
            site_id: number;
            scope_label: string;
        } | null,
    }: {
        site: {
            id: number;
            name: string;
            slug: string;
            is_default?: boolean;
            settings: Record<string, unknown>;
            edge_settings: Partial<EdgeSettings>;
            created_at: string | null;
            updated_at: string | null;
            landing_hero_image_url?: string | null;
        };
        site_landing_url?: string;
        site_entry_short_url?: string | null;
        api_key_masked?: string;
        default_site_id?: number | null;
        auth_is_super_admin?: boolean;
        sites?: SiteOption[];
        programs?: { id: number; name: string; edge_locked_by_device_id: number | null }[];
        rbac_team?: {
            id: number;
            type: string;
            site_id: number;
            scope_label: string;
        } | null;
    } = $props();

    const page = usePage();

    let edge = $state<EdgeSettings>({
        ...DEFAULT_EDGE,
        ...(site?.edge_settings ?? {}),
    });
    let submitting = $state(false);
    let showRegenerateConfirm = $state(false);
    let showNewKeyModal = $state(false);
    let newKeyOnce = $state<string | null>(null);
    let edgeErrors = $state<Record<string, string>>({});

    /** Per public-site plan: public access key and landing settings (from site.settings). */
    let publicAccessKey = $state<string>((site?.settings?.public_access_key as string) ?? "");
    let landingHeroTitle = $state<string>((site?.settings?.landing_hero_title as string) ?? "");
    let landingHeroDescription = $state<string>((site?.settings?.landing_hero_description as string) ?? "");
    let landingShowStats = $state<boolean>(!!site?.settings?.landing_show_stats);

    let heroUploading = $state(false);
    let heroDragging = $state(false);
    let heroInputEl = $state<HTMLInputElement | null>(null);
    let siteEntryQrGenerating = $state(false);

    type LandingSection = { type: string; title: string; body?: string };
    function getInitialLandingSections(): LandingSection[] {
        const raw = site?.settings?.landing_sections;
        if (!Array.isArray(raw)) return [];
        return raw.map((s: unknown) => {
            const o = s as Record<string, unknown>;
            return {
                type: typeof o?.type === "string" ? o.type : "text",
                title: typeof o?.title === "string" ? o.title : "",
                body: typeof o?.body === "string" ? o.body : "",
            };
        });
    }
    let landingSections = $state<LandingSection[]>(getInitialLandingSections());
    let editingSectionIndex = $state<number | null>(null);

    /** TTS generation budget (`sites.settings.tts_budget`) — per docs/architecture/TTS.md */
    interface TtsBudgetForm {
        enabled: boolean;
        mode: "chars";
        period: "daily" | "monthly";
        limit: number;
        warning_threshold_pct: number;
        block_on_limit: boolean;
    }
    function parseTtsBudget(raw: unknown): TtsBudgetForm {
        const b = raw && typeof raw === "object" ? (raw as Record<string, unknown>) : {};
        const period = b.period === "daily" ? "daily" : "monthly";
        return {
            enabled: !!b.enabled,
            mode: "chars",
            period,
            limit: Math.max(0, Number(b.limit ?? 0)),
            warning_threshold_pct: Math.min(
                100,
                Math.max(0, Number(b.warning_threshold_pct ?? 80)),
            ),
            block_on_limit: !!b.block_on_limit,
        };
    }
    let ttsBudget = $state<TtsBudgetForm>(
        parseTtsBudget(site?.settings?.tts_budget),
    );
    let budgetSubmitting = $state(false);

    /** When true, per-site TTS budget policy is managed platform-wide (super admin Configuration). */
    const ttsGlobalBudgetEnabled = $derived(
        (($page?.props as { tts_global_budget_enabled?: boolean } | undefined)
            ?.tts_global_budget_enabled) ?? false,
    );

    interface TtsBudgetMonitorPayload {
        platform_global_budget_enabled?: boolean;
        global_monitoring?: {
            period_key?: string;
            effective_char_limit?: number;
            chars_used?: number;
            remaining?: number;
            at_limit?: boolean;
            platform_char_limit?: number;
            platform_chars_used_total?: number;
            warning_threshold_pct?: number;
            message?: string;
        };
        policy?: { enabled?: boolean; limit?: number; warning_threshold_pct?: number };
        usage?: { chars_used: number; period_key: string } | null;
        remaining?: number | null;
        at_limit?: boolean;
    }
    let ttsBudgetMonitor = $state<TtsBudgetMonitorPayload | null>(null);
    let ttsBudgetMonitorLoading = $state(false);

    const ttsGlobalMon = $derived(ttsBudgetMonitor?.global_monitoring);
    const ttsGlobalMonUsagePct = $derived.by(() => {
        const g = ttsBudgetMonitor?.global_monitoring;
        if (
            !g ||
            g.effective_char_limit === undefined ||
            g.effective_char_limit === null ||
            g.effective_char_limit <= 0
        ) {
            return 0;
        }
        return Math.min(
            100,
            ((g.chars_used ?? 0) / g.effective_char_limit) * 100,
        );
    });
    const ttsGlobalMonIsWarning = $derived(
        ttsGlobalMonUsagePct >= (ttsGlobalMon?.warning_threshold_pct ?? 80),
    );

    const isDefaultSite = $derived(default_site_id !== null && site.id === default_site_id);

    /** When false (edge Pi; SYNC_BACK=false), Edge section is read-only — settings are configured on central. */
    const syncBack = $derived(($page?.props as { edge_mode?: { sync_back?: boolean } } | undefined)?.edge_mode?.sync_back ?? false);
    const edgeSectionDisabled = $derived(!syncBack);

    async function handleSetAsDefault(): Promise<void> {
        if (submitting) return;
        submitting = true;
        const result = await api("PATCH", `/api/admin/sites/${site.id}/default`);
        submitting = false;
        if (result.ok) {
            toaster.success({ title: "Default site updated." });
            router.reload();
        } else {
            toaster.error({
                title: (result as { message?: string })?.message ?? "Failed to set default site.",
            });
        }
    }

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

    $effect(() => {
        if (!ttsGlobalBudgetEnabled || !site?.id) {
            ttsBudgetMonitor = null;
            ttsBudgetMonitorLoading = false;
            return;
        }
        let cancelled = false;
        ttsBudgetMonitorLoading = true;
        (async () => {
            try {
                const res = await fetch(`/api/admin/sites/${site.id}/tts-budget`, {
                    headers: {
                        Accept: "application/json",
                        "X-CSRF-TOKEN": getCsrfToken(),
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    credentials: "same-origin",
                });
                const json = (await res.json().catch(() => null)) as TtsBudgetMonitorPayload | null;
                if (!cancelled && res.ok && json) {
                    ttsBudgetMonitor = json;
                } else if (!cancelled) {
                    ttsBudgetMonitor = null;
                }
            } catch {
                if (!cancelled) ttsBudgetMonitor = null;
            } finally {
                if (!cancelled) ttsBudgetMonitorLoading = false;
            }
        })();
        return () => {
            cancelled = true;
        };
    });

    async function handleHeroUpload(file: File): Promise<void> {
        if (heroUploading) return;
        heroUploading = true;
        try {
            const compressed = await compressImage(file, HERO_BANNER_PRESET);
            const formData = new FormData();
            formData.append("image", compressed);
            const res = await fetch(`/api/admin/sites/${site.id}/hero-image`, {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
                body: formData,
            });
            if (res.ok) {
                toaster.success({ title: "Hero image uploaded." });
                router.reload();
            } else {
                const data = await res.json().catch(() => ({}));
                toaster.error({
                    title: (data as { message?: string })?.message ?? "Upload failed.",
                });
            }
        } catch {
            toaster.error({ title: "Upload failed." });
        } finally {
            heroUploading = false;
        }
    }

    async function handleHeroRemove(): Promise<void> {
        if (heroUploading) return;
        if (!confirm("Remove hero image?")) return;
        heroUploading = true;
        try {
            const res = await fetch(`/api/admin/sites/${site.id}/hero-image`, {
                method: "DELETE",
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            });
            if (res.ok) {
                toaster.success({ title: "Hero image removed." });
                router.reload();
            } else {
                toaster.error({ title: "Failed to remove image." });
            }
        } catch {
            toaster.error({ title: "Failed to remove image." });
        } finally {
            heroUploading = false;
        }
    }

    function moveSectionUp(i: number): void {
        if (i <= 0) return;
        landingSections = [
            ...landingSections.slice(0, i - 1),
            landingSections[i],
            landingSections[i - 1],
            ...landingSections.slice(i + 1),
        ];
        if (editingSectionIndex === i) editingSectionIndex = i - 1;
        else if (editingSectionIndex !== null && editingSectionIndex === i - 1) editingSectionIndex = i;
    }

    function moveSectionDown(i: number): void {
        if (i >= landingSections.length - 1) return;
        landingSections = [
            ...landingSections.slice(0, i),
            landingSections[i + 1],
            landingSections[i],
            ...landingSections.slice(i + 2),
        ];
        if (editingSectionIndex === i) editingSectionIndex = i + 1;
        else if (editingSectionIndex !== null && editingSectionIndex === i + 1) editingSectionIndex = i;
    }

    function removeSection(i: number): void {
        if (!confirm("Remove this section?")) return;
        landingSections = landingSections.filter((_, idx) => idx !== i);
        if (editingSectionIndex === i) editingSectionIndex = null;
        else if (editingSectionIndex !== null && editingSectionIndex > i) editingSectionIndex--;
    }

    function addSection(): void {
        landingSections = [...landingSections, { type: "text", title: "", body: "" }];
        editingSectionIndex = landingSections.length - 1;
    }

    async function api(
        method: string,
        url: string,
        body?: object,
    ): Promise<{
        ok: boolean;
        data?: object;
        message?: string;
        errors?: Record<string, string>;
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
                toaster.error({
                    title: "Session expired. Please refresh and try again.",
                });
                return { ok: false };
            }
            const data = await res.json().catch(() => ({}));
            const errMsg = (data as { message?: string })?.message;
            const errors = (data as { errors?: Record<string, string[]> })
                ?.errors;
            return {
                ok: res.ok,
                data,
                message: errMsg,
                errors: errors
                    ? Object.fromEntries(
                          Object.entries(errors).map(([k, v]) => [
                              k,
                              Array.isArray(v) ? v[0] : String(v),
                          ]),
                      )
                    : undefined,
            };
        } catch {
            toaster.error({ title: "Network error. Please try again." });
            return { ok: false };
        }
    }

    async function handleRegenerate(): Promise<void> {
        showRegenerateConfirm = false;
        submitting = true;
        const { ok, data } = await api(
            "POST",
            `/api/admin/sites/${site.id}/regenerate-key`,
        );
        submitting = false;
        if (ok && data) {
            const key = (data as { api_key?: string })?.api_key;
            if (typeof key === "string") {
                newKeyOnce = key;
                showNewKeyModal = true;
            }
            toaster.success({ title: "New API key generated." });
        } else {
            toaster.error({
                title: (data as { message?: string })?.message ?? "Failed to regenerate key.",
            });
        }
    }

    function copyNewKey(): void {
        if (!newKeyOnce) return;
        navigator.clipboard
            .writeText(newKeyOnce)
            .then(() => toaster.success({ title: "API key copied." }))
            .catch(() => toaster.error({ title: "Could not copy." }));
    }

    function closeNewKeyModal(): void {
        showNewKeyModal = false;
        newKeyOnce = null;
        router.reload();
    }

    function validateTime(value: string): boolean {
        return /^\d{2}:\d{2}$/.test(value);
    }

    async function handleEdgeSubmit(e: SubmitEvent): Promise<void> {
        e.preventDefault();
        if (edgeSectionDisabled) return;
        edgeErrors = {};
        if (!validateTime(edge.scheduled_sync_time)) {
            edgeErrors["edge_settings.scheduled_sync_time"] =
                "Use HH:MM 24-hour format.";
        }
        const [h, m] = edge.scheduled_sync_time.split(":").map(Number);
        if (
            Object.keys(edgeErrors).length === 0 &&
            (h < 0 || h > 23 || m < 0 || m > 59)
        ) {
            edgeErrors["edge_settings.scheduled_sync_time"] =
                "Valid 00:00–23:59.";
        }
        if (Object.keys(edgeErrors).length > 0) {
            toaster.error({ title: "Fix the errors below." });
            return;
        }
        submitting = true;
        const result = await api(
            "PUT",
            `/api/admin/sites/${site.id}`,
            { edge_settings: edge },
        );
        submitting = false;
        const errs = result.errors;
        if (result.ok) {
            toaster.success({ title: "Edge settings saved." });
            if (errs) edgeErrors = {};
            router.reload();
        } else {
            if (errs) edgeErrors = errs;
            toaster.error({
                title: (result as { message?: string })?.message ?? "Failed to save.",
            });
        }
    }

    async function handleTtsBudgetSubmit(e: SubmitEvent): Promise<void> {
        e.preventDefault();
        if (ttsGlobalBudgetEnabled) return;
        if (budgetSubmitting) return;
        budgetSubmitting = true;
        const result = await api("PUT", `/api/admin/sites/${site.id}`, {
            settings: {
                tts_budget: {
                    enabled: ttsBudget.enabled,
                    mode: ttsBudget.mode,
                    period: ttsBudget.period,
                    limit: Math.max(0, Math.floor(ttsBudget.limit)),
                    warning_threshold_pct: Math.min(
                        100,
                        Math.max(0, Math.floor(ttsBudget.warning_threshold_pct)),
                    ),
                    block_on_limit: ttsBudget.block_on_limit,
                },
            },
        });
        budgetSubmitting = false;
        if (result.ok) {
            toaster.success({ title: "TTS generation budget saved." });
            router.reload();
        } else {
            toaster.error({
                title:
                    (result as { message?: string })?.message ??
                    "Failed to save TTS budget.",
            });
        }
    }

</script>

<svelte:head>
    <title>{site.name} — Sites — FlexiQueue</title>
</svelte:head>

<AdminLayout>
    <div class="flex flex-col gap-6 max-w-4xl">
        <div class="flex items-center gap-4">
            {#if auth_is_super_admin}
                <Link
                    href="/admin/sites"
                    class="btn btn-ghost btn-square btn-sm"
                    aria-label="Back to sites"
                >
                    <ArrowLeft class="w-5 h-5" />
                </Link>
            {/if}
            <div class="flex-1 min-w-0">
                <h1 class="text-2xl font-bold text-surface-950 flex items-center gap-2">
                    <Building2 class="w-6 h-6 text-primary-500 shrink-0" />
                    {site.name}
                </h1>
                <p class="mt-1 text-sm text-surface-600 font-mono">{site.slug}</p>
            </div>
        </div>

        {#if auth_is_super_admin && sites && sites.length > 1}
            <section
                class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-4 flex flex-wrap items-center gap-3"
                aria-label="Default site for display and public triage"
            >
                {#if isDefaultSite}
                    <p class="text-sm text-surface-600">
                        This site is the default for display and public triage.
                    </p>
                {:else}
                    <p class="text-sm text-surface-600 shrink-0">
                        Use this site as the default for display and public triage.
                    </p>
                    <button
                        type="button"
                        class="btn preset-tonal flex items-center gap-2 touch-target-h"
                        onclick={handleSetAsDefault}
                        disabled={submitting}
                    >
                        Use as default site
                    </button>
                {/if}
            </section>
        {/if}

        <!-- Public site URL (share); future: blog-like content here -->
        <section
            class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-4 flex flex-wrap items-center gap-3"
            aria-label="Public site URL"
        >
            <p class="text-sm text-surface-600 shrink-0">
                Public site landing: clients open this URL to see active programs and choose display or triage.
            </p>
            {#if site_landing_url}
                <div class="flex flex-wrap items-center gap-2 min-w-0 flex-1">
                    <code class="flex-1 min-w-0 text-sm font-mono text-surface-900 bg-surface-100 border border-surface-200 px-2 py-1.5 rounded truncate" title={site_landing_url}>{site_landing_url}</code>
                    <button
                        type="button"
                        class="btn preset-tonal flex items-center gap-2 touch-target-h"
                        onclick={() => {
                            navigator.clipboard.writeText(site_landing_url).then(
                                () => toaster.success({ title: "Copied public site URL." }),
                                () => toaster.error({ title: "Could not copy." })
                            );
                        }}
                        aria-label="Copy public site URL"
                    >
                        <Share2 class="w-4 h-4" />
                        Share site URL
                    </button>
                </div>
            {/if}
        </section>

        <!-- Public access & landing (per public-site plan) -->
        <section
            class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-6 flex flex-col gap-6"
            aria-labelledby="public-access-heading"
        >
            <h2 id="public-access-heading" class="text-base font-semibold text-surface-950 flex items-center gap-2 mb-1">
                    Public access & landing
                </h2>
                <p class="text-xs text-surface-600 mb-4">
                    Site key lets public devices discover this site from the homepage. Landing fields customize the public site page.
                </p>
            <form
                class="space-y-4"
                onsubmit={async (e) => {
                    e.preventDefault();
                    submitting = true;
                    const result = await api("PUT", `/api/admin/sites/${site.id}`, {
                        settings: {
                            public_access_key: publicAccessKey.trim() || null,
                            landing_hero_title: landingHeroTitle.trim() || null,
                            landing_hero_description: landingHeroDescription.trim() || null,
                            landing_show_stats: landingShowStats,
                            landing_sections: landingSections,
                        },
                    });
                    submitting = false;
                    if (result.ok) {
                        toaster.success({ title: "Saved." });
                        router.reload();
                    } else {
                        toaster.error({ title: (result as { message?: string })?.message ?? "Failed to save." });
                    }
                }}
            >
                <div>
                    <label for="public-access-key" class="block text-sm font-medium text-surface-900 mb-1">Site key</label>
                    <input
                        id="public-access-key"
                        type="text"
                        class="input w-full max-w-xs"
                        placeholder="e.g. TAGUDIN8"
                        bind:value={publicAccessKey}
                        maxlength={20}
                    />
                    {#if !publicAccessKey.trim()}
                        <p class="text-warning-600 text-sm mt-1">No public access key set — public devices cannot discover this site.</p>
                    {/if}
                </div>
                <div>
                    <span class="block text-sm font-medium text-surface-900 mb-1">Site entry QR</span>
                    <p class="text-xs text-surface-600 mb-2">Short link for QR codes: devices scan → land on homepage with site key hint.</p>
                    {#if site_entry_short_url}
                        <div class="mt-2">
                            <QrDisplay url={site_entry_short_url} label="Site entry" />
                        </div>
                    {:else}
                        <button
                            type="button"
                            class="btn variant-outline text-sm"
                            disabled={siteEntryQrGenerating}
                            onclick={async () => {
                                siteEntryQrGenerating = true;
                                const res = await api("POST", `/api/admin/sites/${site.id}/generate-qr`);
                                siteEntryQrGenerating = false;
                                if (res.ok) router.reload();
                                else toaster.error({ title: (res as { message?: string }).message ?? "Failed to generate." });
                            }}
                        >
                            {siteEntryQrGenerating ? "Generating…" : "Generate site entry QR"}
                        </button>
                    {/if}
                </div>
                <div>
                    <label for="landing-hero-title" class="block text-sm font-medium text-surface-900 mb-1">Landing page title</label>
                    <input
                        id="landing-hero-title"
                        type="text"
                        class="input w-full max-w-md"
                        placeholder={site.name}
                        bind:value={landingHeroTitle}
                        maxlength={120}
                    />
                </div>
                <div>
                    <label for="landing-hero-desc" class="block text-sm font-medium text-surface-900 mb-1">Landing description</label>
                    <textarea
                        id="landing-hero-desc"
                        class="input w-full max-w-md min-h-[80px]"
                        placeholder="Short description for the public site page"
                        bind:value={landingHeroDescription}
                        maxlength={500}
                    />
                </div>
                <div>
                    <span class="block text-sm font-medium text-surface-900 mb-1">Hero image</span>
                    <input
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        class="hidden"
                        bind:this={heroInputEl}
                        onchange={(e) => {
                            const file = (e.target as HTMLInputElement)?.files?.[0];
                            if (file) handleHeroUpload(file);
                            (e.target as HTMLInputElement).value = "";
                        }}
                    />
                    {#if site.landing_hero_image_url}
                        <div class="flex flex-wrap items-center gap-3 mt-2">
                            <img
                                src={site.landing_hero_image_url}
                                alt=""
                                class="max-h-20 rounded-lg border border-surface-200 object-cover"
                            />
                            <div class="flex gap-2">
                                <button
                                    type="button"
                                    class="btn variant-outline text-sm"
                                    disabled={heroUploading}
                                    onclick={() => heroInputEl?.click()}
                                >
                                    Replace
                                </button>
                                <button
                                    type="button"
                                    class="btn variant-outline text-sm"
                                    disabled={heroUploading}
                                    onclick={() => handleHeroRemove()}
                                >
                                    Remove
                                </button>
                            </div>
                        </div>
                    {:else}
                        <div
                            class="border-2 border-dashed rounded-container p-6 text-center transition-colors cursor-pointer {heroDragging ? 'border-primary-500 bg-primary-50' : 'border-surface-300 hover:border-primary-400 bg-surface-50/50 hover:bg-surface-100/50'}"
                            role="button"
                            tabindex="0"
                            aria-label="Upload hero image"
                            ondragover={(e) => { e.preventDefault(); heroDragging = true; }}
                            ondragleave={() => (heroDragging = false)}
                            ondrop={(e) => {
                                e.preventDefault();
                                heroDragging = false;
                                const file = e.dataTransfer?.files?.[0];
                                if (file && file.type?.startsWith('image/')) handleHeroUpload(file);
                            }}
                            onclick={() => heroInputEl?.click()}
                            onkeydown={(e) => e.key === 'Enter' && heroInputEl?.click()}
                        >
                            <div class="flex flex-col items-center justify-center gap-2 pointer-events-none">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-surface-400 {heroDragging ? 'text-primary-500' : ''}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                                <div class="text-sm">
                                    <span class="font-semibold text-primary-600">Click to upload</span> or drag and drop
                                </div>
                                <p class="text-xs text-surface-500">JPEG, PNG or WebP; {getUploadHint('hero')}</p>
                            </div>
                        </div>
                        {#if heroUploading}
                            <p class="text-sm text-surface-600 mt-2">Uploading…</p>
                        {/if}
                    {/if}
                    <p class="text-xs text-surface-500 mt-1">{getUploadHint('hero')}</p>
                </div>
                <div class="flex items-center gap-2">
                    <input
                        id="landing-show-stats"
                        type="checkbox"
                        class="checkbox"
                        bind:checked={landingShowStats}
                    />
                    <label for="landing-show-stats" class="text-sm text-surface-900">Show served stats on public landing</label>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-surface-950 mb-2">Content sections</h3>
                    <ul class="space-y-2">
                        {#each landingSections as section, i (i)}
                            <li class="rounded-lg border border-surface-200 bg-surface-100 p-3">
                                {#if editingSectionIndex === i}
                                    <div class="space-y-2">
                                        <input
                                            type="text"
                                            class="input w-full text-sm"
                                            placeholder="Section title"
                                            bind:value={landingSections[i].title}
                                        />
                                        <textarea
                                            class="input w-full text-sm min-h-[60px]"
                                            placeholder="Body (optional)"
                                            bind:value={landingSections[i].body}
                                        ></textarea>
                                        <button
                                            type="button"
                                            class="btn variant-outline text-sm"
                                            onclick={() => (editingSectionIndex = null)}
                                        >
                                            Done
                                        </button>
                                    </div>
                                {:else}
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium text-surface-900">
                                            {section.title || "Untitled"}
                                        </span>
                                        <span class="text-surface-600 text-sm truncate max-w-[200px]">
                                            {section.body ? (section.body.slice(0, 60) + (section.body.length > 60 ? "…" : "")) : ""}
                                        </span>
                                        <div class="flex gap-1 ml-auto">
                                            <button
                                                type="button"
                                                class="btn variant-ghost size-sm p-1"
                                                title="Move up"
                                                disabled={i === 0}
                                                onclick={() => moveSectionUp(i)}
                                            >
                                                <ChevronUp class="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                class="btn variant-ghost size-sm p-1"
                                                title="Move down"
                                                disabled={i === landingSections.length - 1}
                                                onclick={() => moveSectionDown(i)}
                                            >
                                                <ChevronDown class="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                class="btn variant-ghost size-sm p-1"
                                                title="Edit"
                                                onclick={() => (editingSectionIndex = i)}
                                            >
                                                <Pencil class="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                class="btn variant-ghost size-sm p-1 text-red-600"
                                                title="Remove"
                                                onclick={() => removeSection(i)}
                                            >
                                                <Trash2 class="h-4 w-4" />
                                            </button>
                                        </div>
                                    </div>
                                {/if}
                            </li>
                        {/each}
                    </ul>
                    <button
                        type="button"
                        class="btn variant-outline text-sm mt-2 flex items-center gap-1"
                        onclick={addSection}
                    >
                        <Plus class="h-4 w-4" />
                        Add section
                    </button>
                </div>
                <button type="submit" class="btn preset-filled-primary-500" disabled={submitting}>
                    {submitting ? "Saving…" : "Save"}
                </button>
            </form>
        </section>

        <!-- TTS generation budget (site policy; metering + guard — see docs/architecture/TTS.md) -->
        <section
            class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-6"
            aria-labelledby="tts-budget-heading"
        >
            <h2
                id="tts-budget-heading"
                class="text-base font-semibold text-surface-950 flex items-center gap-2 mb-1"
            >
                <Gauge class="w-5 h-5 text-primary-500 shrink-0" />
                TTS generation budget
            </h2>
            {#if ttsGlobalBudgetEnabled}
                <div
                    class="rounded-lg border border-primary-200 bg-primary-50/70 p-4 mb-4 flex gap-3 text-sm text-surface-800"
                    role="status"
                >
                    <Info class="w-5 h-5 text-primary-600 shrink-0 mt-0.5" aria-hidden="true" />
                    <div class="space-y-2 min-w-0">
                        <p class="font-medium text-surface-900">
                            Platform-wide TTS budget is enabled
                        </p>
                        <p class="text-surface-600 text-xs leading-relaxed">
                            Per-site limits are not edited here. Usage below reflects your site’s share of
                            the platform pool (weights and pool size are set in Configuration).
                        </p>
                        {#if auth_is_super_admin}
                            <Link
                                href="/admin/settings?tab=tts-generation"
                                class="btn btn-sm preset-tonal w-fit touch-target-h"
                            >
                                Open Configuration → TTS generation
                            </Link>
                        {:else}
                            <p class="text-xs text-surface-600">
                                Ask your organization super admin to adjust the shared pool or site weights in
                                Configuration.
                            </p>
                        {/if}
                    </div>
                </div>
                {#if ttsBudgetMonitorLoading}
                    <div
                        class="rounded-lg border border-surface-200 bg-surface-100/80 p-4 animate-pulse h-24"
                        aria-busy="true"
                    ></div>
                {:else if ttsGlobalMon != null && ttsGlobalMon.effective_char_limit != null}
                    <div class="space-y-3 max-w-xl">
                        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-surface-500">
                            {#if ttsGlobalMon.period_key}
                                <span class="font-mono">{ttsGlobalMon.period_key}</span>
                            {/if}
                            <span>Effective limit (this site)</span>
                        </div>
                        <div class="flex items-baseline gap-2">
                            <span class="text-lg font-semibold text-surface-900"
                                >{(ttsGlobalMon.chars_used ?? 0).toLocaleString()}</span
                            >
                            <span class="text-sm text-surface-600"
                                >/ {(ttsGlobalMon.effective_char_limit ?? 0).toLocaleString()} chars</span
                            >
                        </div>
                        <div class="w-full bg-surface-200 rounded-full h-2">
                            <div
                                class="h-2 rounded-full transition-all {ttsGlobalMon.at_limit
                                    ? 'bg-error'
                                    : ttsGlobalMonIsWarning
                                      ? 'bg-warning'
                                      : 'bg-primary'}"
                                style="width: {Math.min(100, ttsGlobalMonUsagePct)}%"
                            ></div>
                        </div>
                        <div class="flex items-center justify-between text-xs text-surface-600">
                            <span>{(ttsGlobalMon.remaining ?? 0).toLocaleString()} remaining</span>
                            {#if ttsGlobalMon.at_limit}
                                <span class="flex items-center gap-1 text-error font-medium">
                                    <AlertTriangle class="w-3.5 h-3.5" aria-hidden="true" />
                                    Limit reached — generation blocked
                                </span>
                            {/if}
                        </div>
                        {#if ttsGlobalMon.platform_char_limit != null && ttsGlobalMon.platform_chars_used_total != null}
                            <p class="text-xs text-surface-500">
                                Platform pool (all sites):
                                <span class="font-mono"
                                    >{(ttsGlobalMon.platform_chars_used_total ?? 0).toLocaleString()}</span
                                >
                                /
                                <span class="font-mono"
                                    >{(ttsGlobalMon.platform_char_limit ?? 0).toLocaleString()}</span
                                >
                                chars
                            </p>
                        {/if}
                    </div>
                {:else if ttsGlobalMon?.message}
                    <p class="text-sm text-surface-600 max-w-xl">{ttsGlobalMon.message}</p>
                {/if}
            {:else}
                <p class="text-xs text-surface-600 mb-4">
                    Limits apply to server-side synthesis (jobs and authenticated admin preview) for this site.
                    Usage is tracked in rollups; when enabled with a limit &gt; 0, optional blocking can stop
                    generation at the cap.
                </p>
                <form class="space-y-4 max-w-xl" onsubmit={handleTtsBudgetSubmit}>
                    <label class="flex items-center gap-3 cursor-pointer touch-target-h min-h-[48px] text-surface-900">
                        <input
                            type="checkbox"
                            class="checkbox checkbox-primary"
                            bind:checked={ttsBudget.enabled}
                        />
                        <span>Enable budget tracking for this site</span>
                    </label>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="tts-budget-mode" class="block text-sm font-medium text-surface-900 mb-1"
                                >Measure usage by</label
                            >
                            <select
                                id="tts-budget-mode"
                                class="select select-bordered w-full select-theme"
                                bind:value={ttsBudget.mode}
                            >
                                <option value="chars">Characters generated</option>
                            </select>
                        </div>
                        <div>
                            <label for="tts-budget-period" class="block text-sm font-medium text-surface-900 mb-1"
                                >Reset period</label
                            >
                            <select
                                id="tts-budget-period"
                                class="select select-bordered w-full select-theme"
                                bind:value={ttsBudget.period}
                            >
                                <option value="daily">Daily</option>
                                <option value="monthly">Monthly</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label for="tts-budget-limit" class="block text-sm font-medium text-surface-900 mb-1"
                            >Limit (0 = no numeric cap; policy still “off” for enforcement until enabled + limit)</label
                        >
                        <input
                            id="tts-budget-limit"
                            type="number"
                            min="0"
                            class="input w-full max-w-xs font-mono"
                            bind:value={ttsBudget.limit}
                        />
                    </div>
                    <div>
                        <label
                            for="tts-budget-warn"
                            class="block text-sm font-medium text-surface-900 mb-1"
                            >Warning threshold (% of limit)</label
                        >
                        <input
                            id="tts-budget-warn"
                            type="number"
                            min="0"
                            max="100"
                            class="input w-full max-w-xs font-mono"
                            bind:value={ttsBudget.warning_threshold_pct}
                        />
                    </div>
                    <label class="flex items-center gap-3 cursor-pointer touch-target-h min-h-[48px] text-surface-900">
                        <input
                            type="checkbox"
                            class="checkbox checkbox-primary"
                            bind:checked={ttsBudget.block_on_limit}
                        />
                        <span>Block new generation when over limit</span>
                    </label>
                    <button
                        type="submit"
                        class="btn preset-filled-primary-500 touch-target-h"
                        disabled={budgetSubmitting}
                    >
                        {budgetSubmitting ? "Saving…" : "Save TTS budget"}
                    </button>
                </form>
            {/if}
        </section>

        <!-- API key (masked) + Regenerate -->
        <section
            class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-6"
            aria-labelledby="api-key-heading"
        >
            <h2 id="api-key-heading" class="text-base font-semibold text-surface-950 flex items-center gap-2 mb-2">
                <Key class="w-5 h-5 text-primary-500" />
                API key
            </h2>
            <p class="text-xs text-surface-600 mb-3">
                The key is never shown again after create or regenerate. Store it in Pi <code class="bg-surface-100 border border-surface-200 px-1 rounded text-xs text-surface-900">.env</code> as <code class="bg-surface-100 border border-surface-200 px-1 rounded text-xs text-surface-900">CENTRAL_API_KEY</code>.
            </p>
            <div class="flex flex-wrap items-center gap-3">
                <code class="px-3 py-2 rounded-lg bg-surface-100 border border-surface-200 font-mono text-sm text-surface-900">
                    {api_key_masked}
                </code>
                <button
                    type="button"
                    class="btn preset-tonal flex items-center gap-2 touch-target-h"
                    onclick={() => (showRegenerateConfirm = true)}
                    disabled={submitting}
                >
                    <RefreshCw class="w-4 h-4" />
                    Regenerate key
                </button>
            </div>
        </section>

        {#if rbac_team}
            <ScopedRbacTeamAccessPanel rbacTeam={rbac_team} />
        {/if}

        <!-- Edge settings form (central only when SYNC_BACK=true; on edge SYNC_BACK=false disables inputs) -->
        <section
            class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-6"
            aria-labelledby="edge-settings-heading"
        >
            <h2 id="edge-settings-heading" class="text-base font-semibold text-surface-950 mb-4">
                Edge settings
            </h2>
            {#if edgeSectionDisabled}
                <p class="text-sm text-surface-600 mb-4">
                    Edge settings are configured on the central server. This device does not sync back; re-sync from central to get the latest program and settings.
                </p>
            {/if}
            <form onsubmit={handleEdgeSubmit} class="space-y-6">
                <div class="grid gap-6 sm:grid-cols-2">
                    <label class="flex items-center gap-3 cursor-pointer touch-target-h min-h-[48px] text-surface-900 {edgeSectionDisabled ? 'opacity-60' : ''}">
                        <input
                            type="checkbox"
                            class="checkbox checkbox-primary"
                            bind:checked={edge.sync_clients}
                            disabled={edgeSectionDisabled}
                        />
                        <span>Sync clients</span>
                    </label>
                    <label class="flex items-center gap-3 cursor-pointer touch-target-h min-h-[48px] text-surface-900 {edgeSectionDisabled ? 'opacity-60' : ''}">
                        <input
                            type="checkbox"
                            class="checkbox checkbox-primary"
                            bind:checked={edge.sync_tokens}
                            disabled={edgeSectionDisabled}
                        />
                        <span>Sync tokens</span>
                    </label>
                    <label class="flex items-center gap-3 cursor-pointer touch-target-h min-h-[48px] text-surface-900 {edgeSectionDisabled ? 'opacity-60' : ''}">
                        <input
                            type="checkbox"
                            class="checkbox checkbox-primary"
                            bind:checked={edge.sync_tts}
                            disabled={edgeSectionDisabled}
                        />
                        <span>Sync TTS</span>
                    </label>
                    <label class="flex items-center gap-3 cursor-pointer touch-target-h min-h-[48px] text-surface-900 {edgeSectionDisabled ? 'opacity-60' : ''}">
                        <input
                            type="checkbox"
                            class="checkbox checkbox-primary"
                            bind:checked={edge.bridge_enabled}
                            disabled={edgeSectionDisabled}
                        />
                        <span>Bridge enabled</span>
                    </label>
                    <label class="flex items-center gap-3 cursor-pointer touch-target-h min-h-[48px] text-surface-900 {edgeSectionDisabled ? 'opacity-60' : ''}">
                        <input
                            type="checkbox"
                            class="checkbox checkbox-primary"
                            bind:checked={edge.offline_allow_client_creation}
                            disabled={edgeSectionDisabled}
                        />
                        <span>Offline allow client creation</span>
                    </label>
                </div>

                <div>
                    <label for="sync_client_scope" class="block text-sm font-medium text-surface-900 mb-1">
                        Sync client scope
                    </label>
                    <select
                        id="sync_client_scope"
                        class="select select-bordered w-full max-w-xs select-theme"
                        bind:value={edge.sync_client_scope}
                        disabled={edgeSectionDisabled}
                    >
                        <option value="program_history">program_history</option>
                        <option value="all">all</option>
                    </select>
                </div>

                <div>
                    <label for="offline_binding_mode" class="block text-sm font-medium text-surface-900 mb-1">
                        Offline binding mode override
                    </label>
                    <select
                        id="offline_binding_mode"
                        class="select select-bordered w-full max-w-xs select-theme"
                        bind:value={edge.offline_binding_mode_override}
                        disabled={edgeSectionDisabled}
                    >
                        <option value="optional">optional</option>
                        <option value="required">required</option>
                    </select>
                </div>

                <div>
                    <label for="scheduled_sync_time" class="block text-sm font-medium text-surface-900 mb-1">
                        Scheduled sync time (HH:MM 24h)
                    </label>
                    <input
                        id="scheduled_sync_time"
                        type="text"
                        class="input input-bordered w-full max-w-[8rem] font-mono"
                        placeholder="17:00"
                        bind:value={edge.scheduled_sync_time}
                        maxlength="5"
                        disabled={edgeSectionDisabled}
                    />
                    {#if edgeErrors["edge_settings.scheduled_sync_time"]}
                        <p class="text-sm text-error-600 mt-1">
                            {edgeErrors["edge_settings.scheduled_sync_time"]}
                        </p>
                    {/if}
                </div>

                {#if auth_is_super_admin}
                    <div>
                        <label class="label" for="max-edge-devices">
                            <span class="label-text text-xs font-semibold uppercase tracking-wide text-surface-500">Max Edge Devices</span>
                        </label>
                        <input
                            id="max-edge-devices"
                            type="number"
                            min="0"
                            max="50"
                            class="input input-sm w-24 mt-1"
                            bind:value={edge.max_edge_devices}
                            disabled={edgeSectionDisabled}
                        />
                        <p class="text-xs text-surface-500 mt-1">
                            Number of edge devices allowed for this site (0 = disabled). Super admin only.
                        </p>
                    </div>
                {/if}

                <div class="flex gap-3 pt-2">
                    <button
                        type="submit"
                        class="btn preset-filled-primary-500 touch-target-h"
                        disabled={submitting || edgeSectionDisabled}
                    >
                        <Save class="w-4 h-4" />
                        Save edge settings
                    </button>
                </div>
            </form>
        </section>

        {#if (site?.edge_settings?.max_edge_devices ?? 0) > 0}
            <section
                id="edge-devices-section"
                class="card p-6 flex flex-col gap-4"
                aria-labelledby="edge-devices-heading"
            >
                <EdgeDevicesPanel
                    siteId={site.id}
                    slotsTotal={site?.edge_settings?.max_edge_devices ?? 0}
                    {programs}
                />
            </section>
        {/if}
    </div>
</AdminLayout>

<ConfirmModal
    open={showRegenerateConfirm}
    title="Regenerate API key?"
    message="The current key will stop working immediately. Update the Pi .env with the new key. The new key will be shown only once."
    confirmLabel="Regenerate"
    onConfirm={handleRegenerate}
    onCancel={() => (showRegenerateConfirm = false)}
/>

<Modal
    open={showNewKeyModal}
    title="New API key — copy now"
    onClose={closeNewKeyModal}
>
    <p class="text-sm text-surface-600 mb-4">
        This key is shown only once. Copy it and update your Pi
        <code class="text-sm bg-surface-100 border border-surface-200 px-1 rounded text-surface-900">.env</code>.
    </p>
    <div class="flex flex-wrap items-center gap-2 mb-4 p-3 rounded-lg bg-surface-100 border border-surface-200 font-mono text-sm break-all text-surface-900">
        {newKeyOnce ?? ""}
    </div>
    <div class="flex flex-wrap gap-3">
        <button
            type="button"
            class="btn preset-tonal flex items-center gap-2 touch-target-h"
            onclick={copyNewKey}
        >
            <Copy class="w-4 h-4" />
            Copy key
        </button>
        <button
            type="button"
            class="btn preset-filled-primary-500 touch-target-h"
            onclick={closeNewKeyModal}
        >
            Done
        </button>
    </div>
</Modal>
