<script lang="ts">
    /**
     * Super admin: TTS Generation — platform budget (global weighted / per-site view),
     * ElevenLabs integrations, vendor usage.
     */
    import ConfirmModal from "../../../Components/ConfirmModal.svelte";
    import Modal from "../../../Components/Modal.svelte";
    import { Link } from "@inertiajs/svelte";
    import { get } from "svelte/store";
    import { onMount } from "svelte";
    import { usePage } from "@inertiajs/svelte";
    import { router } from "@inertiajs/svelte";
    import { toaster } from "../../../lib/toaster.js";
    import {
        AudioLines,
        RefreshCw,
        Trash2,
        Plus,
        Pencil,
        Check,
        BarChart3,
        Gauge,
    } from "lucide-svelte";

    const page = usePage();
    const MSG_SESSION_EXPIRED = "Session expired. Please refresh and try again.";
    const MSG_NETWORK_ERROR = "Network error. Please try again.";

    function getCsrfToken(): string {
        const p = get(page);
        const fromProps = (p?.props as { csrf_token?: string } | undefined)?.csrf_token;
        if (fromProps) return fromProps;
        const metaEl =
            typeof document !== "undefined"
                ? (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content
                : "";
        return metaEl ?? "";
    }

    interface TtsAccountApi {
        id: number;
        label: string;
        provider?: string;
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

    interface DashboardSite {
        site_id: number;
        site_name: string;
        slug: string;
        weight: number;
        effective_limit: number | null;
        chars_used: number;
        period_key: string;
        policy: {
            enabled: boolean;
            limit: number;
            period: string;
            block_on_limit: boolean;
        };
    }

    interface PlatformDashboard {
        global: {
            enabled: boolean;
            period: string;
            mode: "chars";
            char_limit: number;
            block_on_limit: boolean;
            warning_threshold_pct: number;
        };
        global_enforced: boolean;
        period_key: string | null;
        total_chars_used_platform_period: number | null;
        sites: DashboardSite[];
        total_metered_chars_all_sites: number;
    }

    let dashboard = $state<PlatformDashboard | null>(null);
    let dashboardLoading = $state(true);
    let platformSaving = $state(false);

    let formGlobalEnabled = $state(false);
    let formPeriod = $state<"daily" | "monthly">("monthly");
    let formCharLimit = $state(0);
    let formBlockOnLimit = $state(true);
    let formWarningPct = $state(80);
    let formWeights = $state<Record<number, number>>({});

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

    function syncFormFromDashboard(d: PlatformDashboard) {
        formGlobalEnabled = d.global.enabled;
        formPeriod = d.global.period === "daily" ? "daily" : "monthly";
        formCharLimit = d.global.char_limit;
        formBlockOnLimit = d.global.block_on_limit;
        formWarningPct = d.global.warning_threshold_pct;
        const w: Record<number, number> = {};
        for (const s of d.sites) {
            w[s.site_id] = s.weight;
        }
        formWeights = w;
    }

    async function fetchDashboard() {
        dashboardLoading = true;
        try {
            const res = await fetch("/api/admin/tts/platform-budget", {
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            });
            if (res.status === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                dashboard = null;
                return;
            }
            if (res.ok) {
                const json = (await res.json()) as PlatformDashboard;
                dashboard = json;
                syncFormFromDashboard(json);
            } else {
                dashboard = null;
            }
        } catch {
            dashboard = null;
        } finally {
            dashboardLoading = false;
        }
    }

    async function savePlatformBudget() {
        platformSaving = true;
        try {
            const body = {
                global_enabled: formGlobalEnabled,
                period: formPeriod,
                char_limit: formCharLimit,
                block_on_limit: formBlockOnLimit,
                warning_threshold_pct: formWarningPct,
                weights: formWeights,
            };
            const res = await fetch("/api/admin/tts/platform-budget", {
                method: "PUT",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
                body: JSON.stringify(body),
            });
            if (res.status === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return;
            }
            if (res.ok) {
                const json = (await res.json()) as PlatformDashboard;
                dashboard = json;
                syncFormFromDashboard(json);
                toaster.success({ title: "Platform TTS budget saved." });
            } else {
                const err = (await res.json().catch(() => ({}))) as { message?: string };
                toaster.error({ title: err.message ?? "Save failed." });
            }
        } catch (e) {
            const isNetwork = e instanceof TypeError && (e as Error).message === "Failed to fetch";
            toaster.error({ title: isNetwork ? MSG_NETWORK_ERROR : "Save failed." });
        } finally {
            platformSaving = false;
        }
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
            if (res.status === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                elevenLabsStatus = null;
                return;
            }
            const json = (await res.json().catch(() => ({}))) as ElevenLabsStatus | { message?: string };
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
            if (res.status === 419) {
                usageData = { subscription: null, usage_time_series: null, message: "Usage unavailable." };
                return;
            }
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
            if (res.status === 419) {
                voicesList = [];
                return;
            }
            const json = (await res.json().catch(() => ({}))) as { voices?: VoiceItem[] };
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
            if (res.status === 419) {
                accountFormError = MSG_SESSION_EXPIRED;
                return;
            }
            const json = (await res.json().catch(() => ({}))) as TtsAccountApi | { message?: string; errors?: Record<string, string[]> };
            if (!res.ok) {
                const err = json as { message?: string; errors?: Record<string, string[]> };
                accountFormError =
                    err.errors?.api_key?.[0] ??
                    err.errors?.label?.[0] ??
                    err.message ??
                    "Failed to save account.";
                toaster.error({ title: accountFormError });
                return;
            }
            toaster.success({ title: "Account saved." });
            accountFormOpen = false;
            await fetchElevenLabsStatus();
        } catch (e) {
            const isNetwork = e instanceof TypeError && (e as Error).message === "Failed to fetch";
            accountFormError = isNetwork ? MSG_NETWORK_ERROR : (e instanceof Error ? e.message : "Failed to save account.");
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
            const res = await fetch(`/api/admin/integrations/elevenlabs/accounts/${deleteAccountTarget.id}`, {
                method: "DELETE",
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
            if (res.ok) {
                toaster.success({ title: "Account removed." });
                deleteAccountTarget = null;
                await fetchElevenLabsStatus();
            } else {
                const errBody = (await res.json().catch(() => ({}))) as { message?: string };
                toaster.error({ title: errBody.message ?? "Failed to remove account." });
            }
        } catch {
            toaster.error({ title: "Failed to remove account." });
        } finally {
            deleteAccountLoading = false;
        }
    }

    async function handleActivateAccount(account: TtsAccountApi) {
        try {
            const res = await fetch(`/api/admin/integrations/elevenlabs/accounts/${account.id}/activate`, {
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
            if (res.ok) {
                toaster.success({ title: "Account set as active." });
                await fetchElevenLabsStatus();
            } else {
                const json = (await res.json().catch(() => ({}))) as { message?: string };
                toaster.error({ title: json.message ?? "Failed to activate account." });
            }
        } catch {
            toaster.error({ title: "Failed to activate account." });
        }
    }

    /** Pie slices for "decentralized" view: share of each site's usage vs total FlexiQueue-metered. */
    const pieSlices = $derived.by(() => {
        const d = dashboard;
        if (!d?.sites?.length) return [];
        const total = d.total_metered_chars_all_sites;
        if (total <= 0) {
            return d.sites.map((s) => ({
                site_id: s.site_id,
                site_name: s.site_name,
                fraction: 1 / d.sites.length,
                chars_used: s.chars_used,
            }));
        }
        return d.sites.map((s) => ({
            site_id: s.site_id,
            site_name: s.site_name,
            fraction: s.chars_used / total,
            chars_used: s.chars_used,
        }));
    });

    /** SVG path for pie wedge in unit circle, angles in radians from -π/2 */
    function wedgePath(startRad: number, endRad: number, r: number): string {
        const x1 = r * Math.cos(startRad);
        const y1 = r * Math.sin(startRad);
        const x2 = r * Math.cos(endRad);
        const y2 = r * Math.sin(endRad);
        const large = endRad - startRad > Math.PI ? 1 : 0;
        return `M 0 0 L ${x1} ${y1} A ${r} ${r} 0 ${large} 1 ${x2} ${y2} Z`;
    }

    const pieFillColors = [
        "var(--color-primary-500)",
        "var(--color-secondary-500)",
        "oklch(0.65 0.15 145)",
        "oklch(0.75 0.15 85)",
        "oklch(0.65 0.2 25)",
        "oklch(0.55 0.02 260)",
    ];

    const piePaths = $derived.by(() => {
        let angle = -Math.PI / 2;
        return pieSlices.map((slice, i) => {
            const span = slice.fraction * 2 * Math.PI;
            const start = angle;
            const end = angle + span;
            angle = end;
            const d = wedgePath(start, end, 0.95);
            return {
                d,
                fill: pieFillColors[i % pieFillColors.length],
                ...slice,
            };
        });
    });

    function goToSite(siteId: number) {
        router.visit(`/admin/sites/${siteId}`);
    }

    onMount(() => {
        fetchDashboard();
        fetchElevenLabsStatus();
        fetchUsage();
    });
</script>

<div class="flex flex-col gap-8">
    <!-- TTS Global Generation Budget -->
    <section class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-6 flex flex-col gap-4">
        <div class="flex items-center gap-3">
            <Gauge class="w-6 h-6 text-primary-500 shrink-0" />
            <div>
                <h2 class="text-base font-semibold text-surface-950">TTS global generation budget</h2>
                <p class="text-xs text-surface-600 max-w-3xl">
                    When <strong>global budgeting</strong> is on, one character pool is split across sites by <strong>weights</strong>
                    (enforcement uses the platform period). When off, each site keeps its own policy in
                    <Link href="/admin/sites" class="text-primary-600 font-medium hover:underline">site settings</Link>; this view shows usage share and vendor context.
                </p>
            </div>
        </div>

        {#if dashboardLoading && !dashboard}
            <p class="text-sm text-surface-600">Loading…</p>
        {:else if dashboard}
            <form
                class="space-y-4"
                onsubmit={(e) => {
                    e.preventDefault();
                    savePlatformBudget();
                }}
            >
                <div class="flex flex-wrap gap-6 items-start">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" bind:checked={formGlobalEnabled} class="checkbox" />
                        <span class="text-sm font-medium text-surface-900">Global budgeting enabled</span>
                    </label>
                    <div>
                        <label for="plat-period" class="block text-xs font-medium text-surface-600 mb-1">Platform period</label>
                        <select
                            id="plat-period"
                            bind:value={formPeriod}
                            class="select rounded-container border border-surface-200 bg-surface-50 text-sm min-w-[10rem]"
                        >
                            <option value="monthly">Monthly</option>
                            <option value="daily">Daily</option>
                        </select>
                    </div>
                    <div>
                        <label for="plat-limit" class="block text-xs font-medium text-surface-600 mb-1">Pool (characters)</label>
                        <input
                            id="plat-limit"
                            type="number"
                            min="0"
                            bind:value={formCharLimit}
                            class="input rounded-container border border-surface-200 px-3 py-2 w-40 bg-surface-50"
                        />
                    </div>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" bind:checked={formBlockOnLimit} class="checkbox" />
                        <span class="text-sm text-surface-800">Block when over limit</span>
                    </label>
                    <div>
                        <label for="plat-warn" class="block text-xs font-medium text-surface-600 mb-1">Warning threshold %</label>
                        <input
                            id="plat-warn"
                            type="number"
                            min="0"
                            max="100"
                            bind:value={formWarningPct}
                            class="input rounded-container border border-surface-200 px-3 py-2 w-24 bg-surface-50"
                        />
                    </div>
                </div>

                {#if formGlobalEnabled && formCharLimit > 0}
                    <div class="overflow-x-auto rounded-container border border-surface-200">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Site</th>
                                    <th>Weight</th>
                                    <th>Effective limit</th>
                                    <th>Used ({dashboard.period_key ?? "—"})</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                {#each dashboard.sites as s}
                                    <tr>
                                        <td class="font-medium">{s.site_name}</td>
                                        <td>
                                            <input
                                                type="number"
                                                min="1"
                                                class="input input-sm w-24 rounded border border-surface-200"
                                                value={formWeights[s.site_id] ?? 1}
                                                oninput={(e) => {
                                                    const v = parseInt((e.currentTarget as HTMLInputElement).value, 10);
                                                    formWeights = { ...formWeights, [s.site_id]: Number.isFinite(v) && v >= 1 ? v : 1 };
                                                }}
                                            />
                                        </td>
                                        <td>{s.effective_limit != null ? s.effective_limit.toLocaleString() : "—"}</td>
                                        <td>{s.chars_used.toLocaleString()}</td>
                                        <td>
                                            <button
                                                type="button"
                                                class="btn preset-tonal btn-xs"
                                                onclick={() => goToSite(s.site_id)}
                                            >
                                                Site settings
                                            </button>
                                        </td>
                                    </tr>
                                {/each}
                            </tbody>
                        </table>
                    </div>
                    {#if dashboard.global_enforced && dashboard.total_chars_used_platform_period != null}
                        <div class="rounded-container border border-surface-200 bg-surface-100/50 p-4">
                            <p class="text-xs uppercase text-surface-600 font-semibold mb-2">Platform pool usage</p>
                            <p class="text-sm text-surface-900">
                                {dashboard.total_chars_used_platform_period.toLocaleString()}
                                <span class="text-surface-500"> / </span>
                                {formCharLimit.toLocaleString()} characters
                            </p>
                            <div class="w-full h-2 rounded-full bg-surface-200 mt-2 overflow-hidden">
                                <div
                                    class="h-full bg-primary-500 transition-all"
                                    style={`width: ${Math.min(100, formCharLimit > 0 ? (dashboard.total_chars_used_platform_period / formCharLimit) * 100 : 0)}%`}
                                ></div>
                            </div>
                        </div>
                    {/if}
                {:else}
                    <p class="text-sm text-surface-600">
                        Per-site policies apply independently. Below is each site’s share of total FlexiQueue-metered usage and a link to edit that site’s budget.
                    </p>
                    <div class="flex flex-col lg:flex-row gap-6 items-start">
                        <div class="shrink-0">
                            <p class="text-xs uppercase text-surface-600 font-semibold mb-2">Usage mix (click a slice)</p>
                            <svg
                                viewBox="-1 -1 2 2"
                                class="w-44 h-44 mx-auto"
                                role="img"
                                aria-label="Usage share by site"
                            >
                                <title>Usage share by site</title>
                                {#each piePaths as seg}
                                    <path
                                        d={seg.d}
                                        fill={seg.fill}
                                        class="stroke-surface-100 stroke-[0.02] hover:opacity-90 cursor-pointer outline-none focus:ring-2 focus:ring-primary-500"
                                        tabindex="0"
                                        role="button"
                                        onclick={() => goToSite(seg.site_id)}
                                        onkeydown={(e) => {
                                            if (e.key === "Enter" || e.key === " ") {
                                                e.preventDefault();
                                                goToSite(seg.site_id);
                                            }
                                        }}
                                        aria-label={`${seg.site_name} ${(seg.fraction * 100).toFixed(1)} percent, open site settings`}
                                    />
                                {/each}
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0 overflow-x-auto rounded-container border border-surface-200">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Site</th>
                                        <th>Policy</th>
                                        <th>Usage</th>
                                        <th>Share</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {#each dashboard.sites as s}
                                        <tr>
                                            <td class="font-medium">{s.site_name}</td>
                                            <td class="text-sm">
                                                {#if s.policy.enabled}
                                                    {s.policy.limit.toLocaleString()} / {s.policy.period}
                                                {:else}
                                                    <span class="text-surface-500">Not configured</span>
                                                {/if}
                                            </td>
                                            <td>{s.chars_used.toLocaleString()}</td>
                                            <td>
                                                {dashboard.total_metered_chars_all_sites > 0
                                                    ? ((s.chars_used / dashboard.total_metered_chars_all_sites) * 100).toFixed(1)
                                                    : (100 / dashboard.sites.length).toFixed(1)}%
                                            </td>
                                            <td>
                                                <Link href="/admin/sites/{s.site_id}" class="btn preset-tonal btn-xs">Open site</Link>
                                            </td>
                                        </tr>
                                    {/each}
                                </tbody>
                            </table>
                        </div>
                    </div>
                {/if}

                {#if usageData?.subscription && !formGlobalEnabled}
                    {@const sub = usageData.subscription}
                    {@const vendorPct = sub.character_limit > 0 ? (sub.character_count / sub.character_limit) * 100 : 0}
                    <div class="rounded-container border border-warning-200 bg-warning-50/80 p-4 text-sm">
                        <p class="font-semibold text-warning-900 mb-1">Vendor plan (ElevenLabs)</p>
                        <p class="text-warning-900/90">
                            {sub.character_count.toLocaleString()} / {sub.character_limit.toLocaleString()} characters
                            ({vendorPct.toFixed(1)}%). Compare to total metered across sites:
                            <strong>{dashboard.total_metered_chars_all_sites.toLocaleString()}</strong> (FlexiQueue internal meter).
                        </p>
                    </div>
                {/if}

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="btn preset-tonal btn-sm" onclick={fetchDashboard} disabled={dashboardLoading}>
                        Refresh
                    </button>
                    <button type="submit" class="btn preset-filled-primary-500 btn-sm" disabled={platformSaving}>
                        {#if platformSaving}
                            <span class="loading-spinner loading-sm"></span>
                        {:else}
                            Save platform budget
                        {/if}
                    </button>
                </div>
            </form>
        {/if}
    </section>

    <!-- ElevenLabs -->
    <section class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-6 flex flex-col gap-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <AudioLines class="w-6 h-6 text-primary-500" />
                <div>
                    <h2 class="text-base font-semibold text-surface-950">ElevenLabs</h2>
                    <p class="text-xs text-surface-600">API accounts, vendor usage, and voices.</p>
                </div>
            </div>
            {#if elevenLabsLoading}
                <span class="loading-spinner loading-xs"></span>
            {:else if elevenLabsStatus}
                <span
                    class="inline-flex items-center gap-1.5 text-[11px] px-2.5 py-1 rounded-full {elevenLabsStatus.status === 'connected'
                        ? 'bg-success-100 text-success-800 border border-success-300'
                        : 'bg-warning-100 text-warning-800 border border-warning-300'}"
                >
                    {elevenLabsStatus.status === "connected" ? "Connected" : "Not configured"}
                </span>
            {/if}
        </div>

        {#if !elevenLabsLoading && !elevenLabsStatus}
            <div role="alert" class="rounded-container border border-error-200 bg-error-50 p-4 text-sm text-error-900">
                Unable to load ElevenLabs integration status.
            </div>
        {:else if elevenLabsStatus && !elevenLabsLoading}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p class="text-[11px] uppercase tracking-wide text-surface-600 font-semibold">Model</p>
                    <p class="text-sm font-medium text-surface-900 mt-0.5">{elevenLabsStatus.model_id}</p>
                </div>
                <div>
                    <p class="text-[11px] uppercase tracking-wide text-surface-600 font-semibold">Default voice ID</p>
                    <p class="text-sm font-mono text-surface-900 mt-0.5 truncate">{elevenLabsStatus.default_voice_id || "—"}</p>
                </div>
                <div>
                    <p class="text-[11px] uppercase tracking-wide text-surface-600 font-semibold">Configured voices</p>
                    <p class="text-sm font-medium text-surface-900 mt-0.5">{elevenLabsStatus.voices_count}</p>
                </div>
            </div>

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
                                        <span class={usedPercent >= 90 ? "text-warning-700 font-semibold" : ""}
                                            >{sub.character_count.toLocaleString()}</span
                                        >
                                        <span class="text-surface-500"> / </span>
                                        <span>{sub.character_limit.toLocaleString()}</span>
                                    </p>
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
                        </div>
                    {:else if usageData?.message}
                        <p class="text-sm text-surface-600">{usageData.message}</p>
                    {:else}
                        <p class="text-sm text-surface-600">Add an ElevenLabs API account to see usage.</p>
                    {/if}
                </div>
            {/if}

            <div>
                <div class="flex items-center justify-between gap-4 mb-3">
                    <h3 class="text-sm font-semibold text-surface-950">API accounts</h3>
                    <button type="button" class="btn preset-filled-primary-500 btn-sm gap-2" onclick={openAddAccount}>
                        <Plus class="w-4 h-4" />
                        Add account
                    </button>
                </div>
                {#if (elevenLabsStatus.accounts?.length ?? 0) > 0}
                    <div class="space-y-2">
                        {#each elevenLabsStatus.accounts ?? [] as account (account.id)}
                            <div
                                class="rounded-container border border-surface-200 bg-surface-50 p-4 flex flex-wrap items-center justify-between gap-3"
                            >
                                <div>
                                    <p class="font-medium text-surface-900 flex items-center gap-2">
                                        {account.label}
                                        {#if account.is_active}
                                            <span
                                                class="inline-flex items-center gap-1 ml-2 px-1.5 py-0.5 rounded text-[10px] font-medium bg-success-100 text-success-800 border border-success-300"
                                                >Active</span
                                            >
                                        {/if}
                                    </p>
                                    <p class="text-xs text-surface-600 font-mono">{account.model_id}</p>
                                    <p class="text-[11px] text-surface-500 mt-0.5">{account.masked_api_key}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    {#if !account.is_active}
                                        <button
                                            type="button"
                                            class="btn preset-tonal btn-sm gap-1"
                                            onclick={() => handleActivateAccount(account)}
                                        >
                                            <Check class="w-3.5 h-3.5" />
                                            Activate
                                        </button>
                                    {/if}
                                    <button type="button" class="btn preset-tonal btn-sm btn-icon" onclick={() => openEditAccount(account)}>
                                        <Pencil class="w-3.5 h-3.5" />
                                    </button>
                                    <button
                                        type="button"
                                        class="btn preset-filled-error-500 btn-sm btn-icon"
                                        onclick={() => confirmDeleteAccount(account)}
                                    >
                                        <Trash2 class="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            </div>
                        {/each}
                    </div>
                {:else}
                    <div class="rounded-container border border-dashed border-surface-300 bg-surface-50/60 p-4 text-sm text-surface-600">
                        Add your first ElevenLabs API account. Without it, server-side TTS uses fallback behavior.
                    </div>
                {/if}
            </div>

            <div>
                <div class="flex items-center justify-between gap-4 mb-3">
                    <h3 class="text-sm font-semibold text-surface-950">Voices</h3>
                    {#if elevenLabsStatus.status === "connected"}
                        <button type="button" class="btn preset-tonal btn-sm gap-2" disabled={voicesLoading} onclick={fetchVoices}>
                            {#if voicesLoading}
                                <span class="loading-spinner loading-xs"></span>
                            {:else}
                                <RefreshCw class="w-4 h-4" />
                            {/if}
                            Refresh voices
                        </button>
                    {/if}
                </div>
                {#if elevenLabsStatus.status === "connected" && voicesList.length > 0}
                    <div class="max-h-60 overflow-y-auto rounded-container border border-surface-200 bg-surface-50 p-3">
                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2 text-sm">
                            {#each voicesList as voice (voice.id)}
                                <div class="flex items-center justify-between gap-2 py-1.5 px-2 rounded bg-surface-100/80">
                                    <span class="font-medium text-surface-900 truncate">{voice.name}</span>
                                    <span class="text-[11px] font-mono text-surface-500 truncate max-w-[6rem]">{voice.id}</span>
                                </div>
                            {/each}
                        </div>
                    </div>
                {:else if elevenLabsStatus.status === "connected" && !voicesLoading}
                    <p class="text-sm text-surface-600">Click “Refresh voices” to load.</p>
                {/if}
            </div>
        {/if}
    </section>
</div>

<ConfirmModal
    open={!!deleteAccountTarget}
    title="Remove ElevenLabs account"
    message={deleteAccountTarget ? `Remove "${deleteAccountTarget.label}"?` : ""}
    confirmLabel="Remove"
    variant="warning"
    loading={deleteAccountLoading}
    onConfirm={handleDeleteAccount}
    onCancel={closeDeleteAccount}
/>

<Modal open={accountFormOpen} title={accountFormEditing ? "Edit ElevenLabs account" : "Add ElevenLabs account"} onClose={closeAccountForm}>
    {#snippet children()}
        <form
            class="space-y-4"
            onsubmit={(e) => {
                e.preventDefault();
                submitAccountForm();
            }}
        >
            {#if accountFormError}
                <div role="alert" class="rounded-container border border-error-200 bg-error-50 px-3 py-2 text-sm text-error-900">
                    {accountFormError}
                </div>
            {/if}
            <div>
                <label for="tg-account-label" class="block text-sm font-medium text-surface-900 mb-1">Label</label>
                <input
                    id="tg-account-label"
                    type="text"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                    bind:value={accountFormLabel}
                    required
                />
            </div>
            <div>
                <label for="tg-account-api-key" class="block text-sm font-medium text-surface-900 mb-1">
                    API key {#if accountFormEditing}<span class="text-surface-500 font-normal">(leave blank to keep)</span>{/if}
                </label>
                <input
                    id="tg-account-api-key"
                    type="password"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                    bind:value={accountFormApiKey}
                    required={!accountFormEditing}
                />
            </div>
            <div>
                <label for="tg-account-model" class="block text-sm font-medium text-surface-900 mb-1">Model ID</label>
                <input
                    id="tg-account-model"
                    type="text"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                    bind:value={accountFormModelId}
                />
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" class="btn preset-tonal" disabled={accountFormSubmitting} onclick={closeAccountForm}>Cancel</button>
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
