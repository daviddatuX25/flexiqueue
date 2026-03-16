<script lang="ts">
    import AdminLayout from "../../../Layouts/AdminLayout.svelte";
    import ApexChart from "../../../Components/Analytics/ApexChart.svelte";
    import { toaster } from "../../../lib/toaster.js";
    import {
        BarChart3,
        Download,
        TrendingUp,
        TrendingDown,
        Users,
        Clock,
        Activity,
        CheckCircle2,
        Layers,
        AlertTriangle,
        Filter,
        ChevronDown,
        ChevronUp,
    } from "lucide-svelte";
    import { onMount } from "svelte";
    import { get } from "svelte/store";
    import { usePage } from "@inertiajs/svelte";

    type DateRangeKey = "today" | "7" | "30" | "custom";

    interface ProgramItem {
        id: number;
        name: string;
    }

    interface TrackItem {
        id: number;
        name: string;
    }

    interface SummaryData {
        total_clients_served: number;
        median_wait_minutes: number | null;
        p90_wait_minutes: number | null;
        completion_rate: number;
        active_sessions: number;
        trend_total: number;
        trend_median_wait: number | null;
        trend_completion_rate: number | null;
    }

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

    function toYMD(d: Date): string {
        return d.toISOString().slice(0, 10);
    }

    const today = $derived(toYMD(new Date()));

    let programs = $state<ProgramItem[]>([]);
    let tracks = $state<TrackItem[]>([]);
    let dateRangeKey = $state<DateRangeKey>("30");
    let customFrom = $state(toYMD(new Date()));
    let customTo = $state(toYMD(new Date()));
    let programId = $state<string>("");
    let trackId = $state<string>("");
    let filtersExpanded = $state(true);

    let from = $derived.by(() => {
        if (dateRangeKey === "today") return today;
        if (dateRangeKey === "7") return toYMD(new Date(Date.now() - 6 * 24 * 60 * 60 * 1000));
        if (dateRangeKey === "30") return toYMD(new Date(Date.now() - 29 * 24 * 60 * 60 * 1000));
        return customFrom;
    });
    let to = $derived.by(() => {
        if (dateRangeKey === "today" || dateRangeKey === "7" || dateRangeKey === "30") return today;
        return customTo;
    });

    let summary = $state<SummaryData | null>(null);
    let throughput = $state<{ period: string; completed: number; cancelled: number }[]>([]);
    let waitDist = $state<{ buckets: { label: string; count: number }[] } | null>(null);
    let stationUtil = $state<{ stations: { name: string; busy_minutes: number; idle_minutes: number; utilization_percent: number }[] } | null>(null);
    let trackPerf = $state<{ tracks: { track_name: string; avg_total_minutes: number; median_wait_minutes: number | null; completion_rate: number }[] } | null>(null);
    let busiestHours = $state<{ heatmap: { day_of_week: number; hour: number; count: number }[]; days: string[]; hours: number[] } | null>(null);
    let funnel = $state<{ steps: { step: string; count: number }[] } | null>(null);
    let tokenTts = $state<{ by_status: { status: string; count: number }[]; by_tts_status: { tts_status: string; count: number }[] } | null>(null);

    let loadingSummary = $state(true);
    let loadingCharts = $state(true);
    /** Chart lib from dynamic import; avoids Vite resolving apexcharts when compiling ApexChart.svelte */
    let chartLib = $state<{
        new (el: HTMLElement, opts: Record<string, unknown>): { render(): void; updateOptions(o: Record<string, unknown>): void; destroy(): void };
    } | null>(null);

    /** Non-reactive so the scheduleFetch effect does not depend on it (avoids effect_update_depth_exceeded). */
    let debounceTimerRef: ReturnType<typeof setTimeout> | null = null;

    /** Per ui-ux-tasks-checklist: chart text contrast — readable axis/legend; theme-friendly (no hard white). */
    function withChartTheme<T extends Record<string, unknown>>(opts: T): T {
        return {
            ...opts,
            chart: {
                foreColor: "#334155",
                background: "transparent",
                ...((opts.chart as Record<string, unknown>) || {}),
            },
            grid: { borderColor: "#e2e8f0", ...((opts.grid as Record<string, unknown>) || {}) },
        } as T;
    }

    function queryParams(): string {
        const p = new URLSearchParams();
        p.set("from", from);
        p.set("to", to);
        if (programId) p.set("program_id", programId);
        if (trackId) p.set("track_id", trackId);
        return p.toString();
    }

    async function apiGet<T>(path: string): Promise<T> {
        const url = path.includes("?") ? path : `${path}?${queryParams()}`;
        const res = await fetch(url, {
            headers: {
                Accept: "application/json",
                "X-CSRF-TOKEN": getCsrfToken(),
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
        });
        if (res.status === 419) {
            toaster.error({ title: MSG_SESSION_EXPIRED });
            throw new Error("Session expired");
        }
        if (!res.ok) throw new Error(await res.text());
        return res.json() as Promise<T>;
    }

    async function fetchPrograms() {
        try {
            const data = await apiGet<{ programs: ProgramItem[] }>("/api/admin/programs");
            programs = data.programs ?? [];
        } catch (e) {
            programs = [];
            const msg = e instanceof Error ? e.message : "";
            if (msg !== "Session expired" && e instanceof TypeError && msg === "Failed to fetch") {
                toaster.error({ title: MSG_NETWORK_ERROR });
            }
        }
    }

    async function fetchTracks() {
        if (!programId) {
            tracks = [];
            return;
        }
        try {
            const data = await apiGet<{ tracks: TrackItem[] }>(`/api/admin/programs/${programId}/tracks`);
            tracks = data.tracks ?? [];
        } catch (e) {
            tracks = [];
            const msg = e instanceof Error ? e.message : "";
            if (msg !== "Session expired" && e instanceof TypeError && msg === "Failed to fetch") {
                toaster.error({ title: MSG_NETWORK_ERROR });
            }
        }
    }

    async function fetchJson(url: string): Promise<unknown> {
        const res = await fetch(url, {
            headers: {
                Accept: "application/json",
                "X-CSRF-TOKEN": getCsrfToken(),
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
        });
        if (res.status === 419) {
            toaster.error({ title: MSG_SESSION_EXPIRED });
            throw new Error("Session expired");
        }
        return res.ok ? res.json() : null;
    }

    async function fetchAll() {
        loadingSummary = true;
        loadingCharts = true;
        try {
            const q = queryParams();
            const [s, tp, wd, su, tp2, bh, fn, tt] = await Promise.all([
                fetchJson(`/api/admin/analytics/summary?${q}`),
                fetchJson(`/api/admin/analytics/throughput?${q}`),
                fetchJson(`/api/admin/analytics/wait-time-distribution?${q}`),
                fetchJson(`/api/admin/analytics/station-utilization?${q}`),
                fetchJson(`/api/admin/analytics/tracks?${q}`),
                fetchJson(`/api/admin/analytics/busiest-hours?${q}`),
                fetchJson(`/api/admin/analytics/drop-off-funnel?${q}`),
                fetchJson("/api/admin/analytics/token-tts-health"),
            ]);
            summary = s as SummaryData | null;
            throughput = Array.isArray(tp) ? tp : [];
            waitDist = wd;
            stationUtil = su;
            trackPerf = tp2;
            busiestHours = bh;
            funnel = fn;
            tokenTts = tt;
        } catch (e) {
            const msg = e instanceof Error ? e.message : "";
            if (msg === "Session expired") return;
            const isNetwork = e instanceof TypeError && msg === "Failed to fetch";
            toaster.error({ title: isNetwork ? MSG_NETWORK_ERROR : msg || "Failed to load analytics." });
        } finally {
            loadingSummary = false;
            loadingCharts = false;
        }
    }

    function scheduleFetch() {
        if (debounceTimerRef) clearTimeout(debounceTimerRef);
        debounceTimerRef = setTimeout(() => {
            fetchAll();
            debounceTimerRef = null;
        }, 300);
    }

    $effect(() => {
        from;
        to;
        programId;
        trackId;
        scheduleFetch();
    });

    $effect(() => {
        if (programId) fetchTracks();
        else tracks = [];
    });

    function exportCsv() {
        const rows: string[][] = [
            ["Metric", "Value"],
            ["Total clients served", String(summary?.total_clients_served ?? 0)],
            ["Median wait (min)", String(summary?.median_wait_minutes ?? "")],
            ["p90 wait (min)", String(summary?.p90_wait_minutes ?? "")],
            ["Completion rate %", String(summary?.completion_rate ?? "")],
            ["Active sessions", String(summary?.active_sessions ?? 0)],
            [],
            ["Throughput", "Period", "Completed", "Cancelled"],
            ...throughput.map((r) => [r.period, String(r.completed), String(r.cancelled)]),
        ];
        const csv = rows.map((r) => r.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(",")).join("\n");
        const blob = new Blob([csv], { type: "text/csv" });
        const a = document.createElement("a");
        a.href = URL.createObjectURL(blob);
        a.download = `analytics-${from}-${to}.csv`;
        a.click();
        URL.revokeObjectURL(a.href);
    }

    function trendPct(current: number, previous: number): number | null {
        if (previous === 0) return null;
        return Math.round(((current - previous) / previous) * 100);
    }

    onMount(() => {
        fetchPrograms();
        import("apexcharts")
            .then((m: { default: { new (el: HTMLElement, opts: Record<string, unknown>): { render(): void; updateOptions(o: Record<string, unknown>): void; destroy(): void } } }) => {
                chartLib = m.default;
            })
            .catch(() => {});
    });
</script>

<svelte:head>
    <title>Analytics — FlexiQueue</title>
</svelte:head>

<AdminLayout>
    <div class="flex flex-col gap-6 max-w-[1400px] mx-auto pb-12">
        <!-- Zone 1 — Page Header -->
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-[28px] font-semibold text-surface-950 tracking-tight">
                    Analytics
                </h1>
                <p class="mt-1 text-sm text-surface-600 max-w-2xl">
                    Aggregated performance trends across programs and stations.
                </p>
            </div>
            <div class="flex gap-2">
                <button
                    type="button"
                    class="btn preset-outlined-surface btn-sm gap-2 touch-target-h"
                    onclick={exportCsv}
                    disabled={!summary}
                >
                    <Download class="w-4 h-4" />
                    Export CSV
                </button>
                <button
                    type="button"
                    class="btn preset-outlined-surface btn-sm gap-2 touch-target-h"
                    onclick={() => window.print()}
                >
                    <Download class="w-4 h-4" />
                    Export PDF
                </button>
            </div>
        </div>

        <!-- Zone 2 — Filter Bar (Sticky, expand/collapse): date range, program, track -->
        <div
            id="analytics-filter-bar"
            class="sticky top-0 z-10 rounded-container border border-surface-200 bg-surface-50 shadow-sm overflow-hidden"
        >
            <button
                type="button"
                class="w-full flex items-center justify-between gap-2 p-4 text-left hover:bg-surface-100/80 transition-colors touch-target-h"
                onclick={() => (filtersExpanded = !filtersExpanded)}
                aria-expanded={filtersExpanded}
                aria-controls="analytics-filter-controls"
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
                id="analytics-filter-controls"
                class="flex flex-col sm:flex-row sm:flex-wrap items-stretch sm:items-end gap-4 px-4 pb-4 pt-0 border-t border-surface-200/80"
                class:hidden={!filtersExpanded}
            >
            <div class="flex flex-wrap items-center gap-3">
                <span class="text-sm font-medium text-surface-700">Date range</span>
                <!-- Per ui-ux-tasks-checklist: selected date range = green text only, no colored background -->
                <div class="flex rounded-lg border border-surface-200 dark:border-slate-600 overflow-hidden [&_button]:px-3 [&_button]:py-2 [&_button]:text-sm [&_button]:font-medium [&_button]:transition-colors">
                    <button
                        type="button"
                        class="touch-target-h bg-surface-50 dark:bg-slate-800/50 {dateRangeKey === 'today'
                            ? 'text-primary-600 dark:text-primary-400 font-semibold'
                            : 'text-surface-700 dark:text-slate-300 hover:bg-surface-100 dark:hover:bg-slate-700/50'}"
                        onclick={() => (dateRangeKey = "today")}
                    >
                        Today
                    </button>
                    <button
                        type="button"
                        class="touch-target-h border-l border-surface-200 dark:border-slate-600 bg-surface-50 dark:bg-slate-800/50 {dateRangeKey === '7'
                            ? 'text-primary-600 dark:text-primary-400 font-semibold'
                            : 'text-surface-700 dark:text-slate-300 hover:bg-surface-100 dark:hover:bg-slate-700/50'}"
                        onclick={() => (dateRangeKey = "7")}
                    >
                        Last 7 Days
                    </button>
                    <button
                        type="button"
                        class="touch-target-h border-l border-surface-200 dark:border-slate-600 bg-surface-50 dark:bg-slate-800/50 {dateRangeKey === '30'
                            ? 'text-primary-600 dark:text-primary-400 font-semibold'
                            : 'text-surface-700 dark:text-slate-300 hover:bg-surface-100 dark:hover:bg-slate-700/50'}"
                        onclick={() => (dateRangeKey = "30")}
                    >
                        Last 30 Days
                    </button>
                    <button
                        type="button"
                        class="touch-target-h border-l border-surface-200 dark:border-slate-600 bg-surface-50 dark:bg-slate-800/50 {dateRangeKey === 'custom'
                            ? 'text-primary-600 dark:text-primary-400 font-semibold'
                            : 'text-surface-700 dark:text-slate-300 hover:bg-surface-100 dark:hover:bg-slate-700/50'}"
                        onclick={() => (dateRangeKey = "custom")}
                    >
                        Custom
                    </button>
                </div>
                {#if dateRangeKey === "custom"}
                    <div class="flex items-center gap-2">
                        <input
                            type="date"
                            class="input input-sm rounded-container border border-surface-200 w-[140px] touch-target-h"
                            bind:value={customFrom}
                        />
                        <span class="text-surface-500">to</span>
                        <input
                            type="date"
                            class="input input-sm rounded-container border border-surface-200 w-[140px] touch-target-h"
                            bind:value={customTo}
                        />
                    </div>
                {/if}
            </div>
            <div class="form-control">
                <label for="analytics-program" class="label py-0 text-xs font-medium text-surface-600"
                    >Program</label
                >
                <select
                    id="analytics-program"
                    class="select select-sm rounded-container border border-surface-200 w-[180px] touch-target-h"
                    bind:value={programId}
                    onchange={() => (trackId = "")}
                >
                    <option value="">All Programs</option>
                    {#each programs as p (p.id)}
                        <option value={p.id}>{p.name}</option>
                    {/each}
                </select>
            </div>
            <div class="form-control">
                <label for="analytics-track" class="label py-0 text-xs font-medium text-surface-600"
                    >Track</label
                >
                <select
                    id="analytics-track"
                    class="select select-sm rounded-container border border-surface-200 w-[160px] touch-target-h"
                    bind:value={trackId}
                >
                    <option value="">All Tracks</option>
                    {#each tracks as t (t.id)}
                        <option value={t.id}>{t.name}</option>
                    {/each}
                </select>
            </div>
            </div>
        </div>

        {#if !loadingSummary && !loadingCharts && !summary}
            <!-- Page-level empty state when no data for range -->
            <div
                role="status"
                aria-label="No analytics data for this range"
                class="rounded-container border border-surface-200 bg-surface-50 p-12 flex flex-col items-center justify-center text-center shadow-sm"
            >
                <div
                    class="bg-surface-100 p-4 rounded-full text-surface-400 mb-4"
                >
                    <BarChart3 class="w-8 h-8" />
                </div>
                <h3 class="text-lg font-semibold text-surface-950">
                    No data for this range
                </h3>
                <p class="text-surface-600 max-w-sm mt-2 mb-6">
                    Try changing the date range or selecting a different program.
                </p>
                <button
                    type="button"
                    class="btn preset-filled-primary-500 flex items-center gap-2 touch-target-h"
                    onclick={() => { filtersExpanded = true; document.getElementById('analytics-filter-bar')?.scrollIntoView({ behavior: 'smooth' }); }}
                >
                    <Filter class="w-4 h-4" /> Change filters
                </button>
            </div>
        {:else}
        <!-- Zone 3 — KPI Summary Strip -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
            {#if loadingSummary && !summary}
                {#each [1, 2, 3, 4, 5] as _}
                    <div class="rounded-container border border-surface-200 bg-surface-50 p-5 h-[100px] animate-pulse"></div>
                {/each}
            {:else if summary}
                <div
                    class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-5 relative overflow-hidden"
                >
                    <div class="absolute top-2 right-2 text-surface-200">
                        <Users class="w-6 h-6" />
                    </div>
                    <p class="text-xs font-medium text-surface-500 uppercase tracking-wide">Total Clients Served</p>
                    <p class="text-2xl font-bold text-surface-950 mt-0.5">{summary.total_clients_served.toLocaleString()}</p>
                    {#if trendPct(summary.total_clients_served, summary.trend_total) !== null}
                        {@const pct = trendPct(summary.total_clients_served, summary.trend_total)!}
                        <p class="text-xs mt-1 flex items-center gap-1 {pct >= 0 ? 'text-primary-600' : 'text-error-600'}">
                            {#if pct >= 0}<TrendingUp class="w-3.5 h-3.5" />{:else}<TrendingDown class="w-3.5 h-3.5" />{/if}
                            {pct >= 0 ? "↑" : "↓"} {Math.abs(pct)}% vs prev period
                        </p>
                    {/if}
                </div>
                <div
                    class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-5 relative overflow-hidden"
                >
                    <div class="absolute top-2 right-2 text-amber-200">
                        <Clock class="w-6 h-6" />
                    </div>
                    <p class="text-xs font-medium text-surface-500 uppercase tracking-wide">Median Wait Time</p>
                    <p class="text-2xl font-bold text-surface-950 mt-0.5">
                        {summary.median_wait_minutes != null ? `${summary.median_wait_minutes.toFixed(1)} min` : "—"}
                    </p>
                    {#if summary.trend_median_wait != null && summary.median_wait_minutes != null}
                        {@const pct = trendPct(summary.trend_median_wait, summary.median_wait_minutes)}
                        {#if pct !== null && pct !== 0}
                            <p class="text-xs mt-1 {pct < 0 ? 'text-success-600' : 'text-amber-600'}">
                                {pct < 0 ? "↓" : "↑"} {Math.abs(pct)}% vs prev
                            </p>
                        {/if}
                    {/if}
                </div>
                <div
                    class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-5 relative overflow-hidden"
                >
                    <div class="absolute top-2 right-2 text-orange-200">
                        <Clock class="w-6 h-6" />
                    </div>
                    <p class="text-xs font-medium text-surface-500 uppercase tracking-wide">p90 Wait Time</p>
                    <p class="text-2xl font-bold text-surface-950 mt-0.5">
                        {summary.p90_wait_minutes != null ? `${summary.p90_wait_minutes.toFixed(1)} min` : "—"}
                    </p>
                </div>
                <div
                    class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-5 relative overflow-hidden"
                >
                    <div class="absolute top-2 right-2 text-green-200">
                        <CheckCircle2 class="w-6 h-6" />
                    </div>
                    <p class="text-xs font-medium text-surface-500 uppercase tracking-wide">Completion Rate</p>
                    <p class="text-2xl font-bold text-surface-950 mt-0.5">{summary.completion_rate.toFixed(1)}%</p>
                    {#if summary.trend_completion_rate != null}
                        {@const pct = trendPct(summary.completion_rate, summary.trend_completion_rate)}
                        {#if pct !== null && pct !== 0}
                            <p class="text-xs mt-1 {pct >= 0 ? 'text-success-600' : 'text-error-600'}">
                                {pct >= 0 ? "↑" : "↓"} {Math.abs(pct)}% vs prev
                            </p>
                        {/if}
                    {/if}
                </div>
                <div
                    class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-5 relative overflow-hidden"
                >
                    <div class="absolute top-2 right-2 text-purple-200">
                        <Layers class="w-6 h-6" />
                    </div>
                    <p class="text-xs font-medium text-surface-500 uppercase tracking-wide">Active Program Sessions</p>
                    <p class="text-2xl font-bold text-surface-950 mt-0.5">{summary.active_sessions}</p>
                </div>
            {/if}
        </div>

        <!-- Zone 4 — Chart Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Chart 1 — Throughput (full width) -->
            <div class="lg:col-span-2 rounded-container border border-surface-200 bg-surface-50 shadow-sm p-5">
                <h3 class="text-sm font-semibold text-surface-950 mb-4">Throughput Over Time</h3>
                {#if loadingCharts}
                    <div class="h-[320px] rounded-container bg-surface-100 animate-pulse flex items-center justify-center">
                        <span class="text-surface-500 text-sm">Loading…</span>
                    </div>
                {:else if throughput.length > 0}
                    <ApexChart
                        chartLib={chartLib}
                        options={withChartTheme({
                            chart: { type: "area", toolbar: { show: false }, zoom: { enabled: false } },
                            dataLabels: { enabled: false },
                            stroke: { curve: "smooth", width: 2 },
                            fill: { type: "gradient", gradient: { opacityFrom: 0.6, opacityTo: 0.1 } },
                            tooltip: { x: { show: true }, shared: true, intersect: false },
                            xaxis: {
                                categories: throughput.map((r) => r.period),
                                labels: { rotate: -45 },
                            },
                            yaxis: { title: { text: "Sessions" } },
                            colors: ["#0ea5e9", "#94a3b8"],
                            legend: { position: "top" },
                            series: [
                                { name: "Completed", data: throughput.map((r) => r.completed) },
                                { name: "Cancelled / No-show", data: throughput.map((r) => r.cancelled) },
                            ],
                        })}
                        height="320"
                    />
                {:else}
                    <div class="h-[280px] flex items-center justify-center text-surface-500 text-sm rounded-container bg-surface-50">
                        No sessions found for this period.
                    </div>
                {/if}
            </div>

            <!-- Chart 2 — Wait Time Distribution -->
            <div class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-5">
                <h3 class="text-sm font-semibold text-surface-950 mb-4">Wait Time Distribution</h3>
                {#if loadingCharts}
                    <div class="h-[280px] rounded-container bg-surface-100 animate-pulse"></div>
                {:else if waitDist?.buckets?.length}
                    <ApexChart
                        chartLib={chartLib}
                        options={withChartTheme({
                            chart: { type: "bar", toolbar: { show: false } },
                            plotOptions: {
                                bar: { horizontal: false, columnWidth: "70%", borderRadius: 4 },
                            },
                            xaxis: { categories: waitDist.buckets.map((b) => b.label) },
                            yaxis: { title: { text: "Clients" } },
                            colors: ["#22c55e", "#84cc16", "#eab308", "#f97316", "#ef4444"],
                            series: [{ name: "Clients", data: waitDist.buckets.map((b) => b.count) }],
                        })}
                        height="280"
                    />
                {:else}
                    <div class="h-[280px] flex items-center justify-center text-surface-500 text-sm bg-surface-50 rounded-container">
                        No wait time data.
                    </div>
                {/if}
            </div>

            <!-- Chart 3 — Station Utilization (horizontal bar by % utilization) -->
            <div class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-5">
                <h3 class="text-sm font-semibold text-surface-950 mb-4">Station Utilization</h3>
                {#if loadingCharts}
                    <div class="h-[280px] rounded-container bg-surface-100 animate-pulse"></div>
                {:else if stationUtil?.stations?.length}
                    <ApexChart
                        chartLib={chartLib}
                        options={withChartTheme({
                            chart: { type: "bar", toolbar: { show: false } },
                            plotOptions: {
                                bar: {
                                    horizontal: true,
                                    barHeight: "70%",
                                    borderRadius: 4,
                                    dataLabels: { position: "top" as const },
                                },
                            },
                            dataLabels: {
                                enabled: true,
                                formatter: (val: number) => `${Math.round(Number(val))}%`,
                                style: { fontSize: "11px" },
                            },
                            xaxis: { categories: stationUtil.stations.map((s) => s.name) },
                            yaxis: { max: 100, title: { text: "Utilization %" }, tickAmount: 5 },
                            colors: ["#0d9488"],
                            legend: { show: false },
                            tooltip: {
                                y: {
                                    formatter: (val: number, { seriesIndex, dataPointIndex }: { seriesIndex?: number; dataPointIndex?: number }) => {
                                        const s = stationUtil!.stations[dataPointIndex ?? 0];
                                        if (!s) return `${Math.round(Number(val))}%`;
                                        return `${Math.round(s.utilization_percent)}% busy · ${Math.round(s.busy_minutes)} min busy / ${Math.round(s.idle_minutes)} min idle`;
                                    },
                                },
                            },
                            series: [
                                {
                                    name: "Utilization %",
                                    data: stationUtil.stations.map((s) => Math.round(s.utilization_percent * 10) / 10),
                                },
                            ],
                        })}
                        height="280"
                    />
                {:else}
                    <div class="h-[280px] flex items-center justify-center text-surface-500 text-sm bg-surface-50 rounded-container">
                        No station data.
                    </div>
                {/if}
            </div>

            <!-- Chart 4 — Track Performance -->
            <div class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-5">
                <h3 class="text-sm font-semibold text-surface-950 mb-4">Track Performance Comparison</h3>
                {#if loadingCharts}
                    <div class="h-[280px] rounded-container bg-surface-100 animate-pulse"></div>
                {:else if trackPerf?.tracks?.length}
                    <ApexChart
                        chartLib={chartLib}
                        options={withChartTheme({
                            chart: { type: "bar", toolbar: { show: false } },
                            plotOptions: {
                                bar: { horizontal: false, columnWidth: "60%", borderRadius: 4 },
                            },
                            xaxis: { categories: trackPerf.tracks.map((t) => t.track_name) },
                            yaxis: [{ title: { text: "Minutes" } }, { opposite: true, title: { text: "Completion %" } }],
                            colors: ["#0ea5e9", "#f59e0b", "#22c55e"],
                            legend: { position: "top" },
                            series: [
                                { name: "Avg total (min)", data: trackPerf.tracks.map((t) => t.avg_total_minutes) },
                                {
                                    name: "Median wait (min)",
                                    data: trackPerf.tracks.map((t) => t.median_wait_minutes ?? 0),
                                },
                                { name: "Completion %", data: trackPerf.tracks.map((t) => t.completion_rate) },
                            ],
                        })}
                        height="280"
                    />
                {:else}
                    <div class="h-[280px] flex items-center justify-center text-surface-500 text-sm bg-surface-50 rounded-container">
                        No track data.
                    </div>
                {/if}
            </div>

            <!-- Chart 5 — Busiest Hours Heatmap (chart bg transparent = theme; 7 rows = days, 24 cols = hours) -->
            <div class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-5">
                <h3 class="text-sm font-semibold text-surface-950 mb-4">Busiest Hours</h3>
                {#if loadingCharts}
                    <div class="h-[280px] rounded-container bg-surface-100 animate-pulse"></div>
                {:else if busiestHours?.heatmap?.length && busiestHours.days?.length}
                    {@const days = busiestHours.days}
                    {@const map = busiestHours.heatmap.reduce((acc, { day_of_week, hour, count }) => {
                        const k = `${day_of_week}-${hour}`;
                        acc[k] = (acc[k] ?? 0) + count;
                        return acc;
                    }, {} as Record<string, number>)}
                    {@const sortedDayOfWeek = [...new Set(busiestHours.heatmap.map((r) => r.day_of_week))].sort((a, b) => a - b)}
                    {@const maxCount = Math.max(1, ...Object.values(map))}
                    {@const heatmapSeries = sortedDayOfWeek.map((dow, i) => ({
                        name: days[i] ?? `Day ${dow}`,
                        data: Array.from({ length: 24 }, (_, hour) => ({ x: `${hour}`, y: map[`${dow}-${hour}`] ?? 0 })),
                    }))}
                    <ApexChart
                        chartLib={chartLib}
                        options={withChartTheme({
                            chart: { type: "heatmap", toolbar: { show: false } },
                            plotOptions: {
                                heatmap: {
                                    colorScale: {
                                        ranges: [
                                            { from: 0, to: 0, color: "#e2e8f0", name: "0" },
                                            { from: 0.01, to: maxCount * 0.25, color: "#e0f2fe" },
                                            { from: maxCount * 0.25 + 0.01, to: maxCount * 0.5, color: "#7dd3fc" },
                                            { from: maxCount * 0.5 + 0.01, to: maxCount * 0.75, color: "#0ea5e9" },
                                            { from: maxCount * 0.75 + 0.01, to: maxCount, color: "#0369a1" },
                                        ],
                                    },
                                },
                            },
                            xaxis: { categories: Array.from({ length: 24 }, (_, i) => `${i}`) },
                            yaxis: { labels: { show: true } },
                            dataLabels: { enabled: false },
                            legend: { show: false },
                            tooltip: { y: { formatter: (v: number) => `${v} sessions` } },
                            series: heatmapSeries,
                        })}
                        height="280"
                    />
                {:else if busiestHours?.heatmap?.length}
                    {@const byHour = busiestHours.heatmap.reduce((acc, row) => {
                        acc[row.hour] = (acc[row.hour] ?? 0) + row.count;
                        return acc;
                    }, {} as Record<number, number>)}
                    {@const hours = Array.from({ length: 24 }, (_, i) => `${i}:00`)}
                    {@const counts = hours.map((_, i) => byHour[i] ?? 0)}
                    <ApexChart
                        chartLib={chartLib}
                        options={withChartTheme({
                            chart: { type: "bar", toolbar: { show: false } },
                            plotOptions: { bar: { borderRadius: 2 } },
                            xaxis: { categories: hours },
                            yaxis: { title: { text: "Sessions started" } },
                            colors: ["#0ea5e9"],
                            series: [{ name: "Sessions", data: counts }],
                        })}
                        height="280"
                    />
                {:else}
                    <div class="h-[280px] flex items-center justify-center text-surface-500 text-sm rounded-container">
                        No heatmap data.
                    </div>
                {/if}
            </div>

            <!-- Chart 6 — Drop-off Funnel -->
            <div class="lg:col-span-2 rounded-container border border-surface-200 bg-surface-50 shadow-sm p-5">
                <h3 class="text-sm font-semibold text-surface-950 mb-4">Drop-off Funnel</h3>
                {#if loadingCharts}
                    <div class="h-[240px] rounded-container bg-surface-100 animate-pulse"></div>
                {:else if funnel?.steps?.length}
                    <ApexChart
                        chartLib={chartLib}
                        options={withChartTheme({
                            chart: { type: "bar", toolbar: { show: false } },
                            plotOptions: { bar: { horizontal: true, barHeight: "70%", borderRadius: 4 } },
                            xaxis: { categories: funnel.steps.map((s) => s.step) },
                            yaxis: { title: { text: "Count" } },
                            colors: ["#22c55e", "#84cc16", "#eab308", "#22c55e", "#f97316", "#eab308"],
                            series: [{ name: "Count", data: funnel.steps.map((s) => s.count) }],
                        })}
                        height="240"
                    />
                {:else}
                    <div class="h-[200px] flex items-center justify-center text-surface-500 text-sm bg-surface-50 rounded-container">
                        No funnel data.
                    </div>
                {/if}
            </div>

            <!-- Chart 7 — TTS & Token Health -->
            <div class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-5 lg:col-span-2">
                <h3 class="text-sm font-semibold text-surface-950 mb-4">TTS & Token Health</h3>
                {#if loadingCharts}
                    <div class="h-[220px] rounded-container bg-surface-100 animate-pulse"></div>
                {:else if tokenTts}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                        <div>
                            <p class="text-xs font-medium text-surface-500 mb-2">By status</p>
                            {#if tokenTts.by_status.length > 0}
                                <ApexChart
                                    chartLib={chartLib}
                                    options={withChartTheme({
                                        chart: { type: "donut" },
                                        labels: tokenTts.by_status.map((s) => s.status),
                                        series: tokenTts.by_status.map((s) => s.count),
                                        legend: { position: "bottom" },
                                        colors: ["#22c55e", "#0ea5e9", "#94a3b8"],
                                    })}
                                    height="200"
                                />
                            {:else}
                                <p class="text-sm text-surface-500">No token data.</p>
                            {/if}
                        </div>
                        <div>
                            <p class="text-xs font-medium text-surface-500 mb-2">By TTS status</p>
                            {#if tokenTts.by_tts_status.length > 0}
                                <ApexChart
                                    chartLib={chartLib}
                                    options={withChartTheme({
                                        chart: { type: "donut" },
                                        labels: tokenTts.by_tts_status.map((s) => s.tts_status),
                                        series: tokenTts.by_tts_status.map((s) => s.count),
                                        legend: { position: "bottom" },
                                        colors: ["#22c55e", "#0ea5e9", "#eab308", "#ef4444", "#94a3b8"],
                                    })}
                                    height="200"
                                />
                            {:else}
                                <p class="text-sm text-surface-500">No TTS data.</p>
                            {/if}
                        </div>
                    </div>
                {:else}
                    <div class="h-[200px] flex items-center justify-center text-surface-500 text-sm bg-surface-50 rounded-container">
                        No token/TTS data.
                    </div>
                {/if}
            </div>
        </div>
        {/if}
    </div>
</AdminLayout>
