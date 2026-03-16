<script lang="ts">
    import AdminLayout from "../../../Layouts/AdminLayout.svelte";
    import { get } from "svelte/store";
    import { onMount } from "svelte";
    import { usePage } from "@inertiajs/svelte";
    import { toaster } from "../../../lib/toaster.js";
    import {
        FileText,
        Download,
        Filter,
        Search,
        Calendar,
        Clock,
        Activity,
        Users,
        Monitor,
        AlertCircle,
        ChevronDown,
    } from "lucide-svelte";

    interface ProgramItem {
        id: number;
        name: string;
    }

    interface StationItem {
        id: number;
        name: string;
        program_id: number;
    }

    interface StaffUserItem {
        id: number;
        name: string;
    }

    interface ProgramSessionItem {
        id: number;
        program_id: number;
        program_name: string;
        started_at: string;
        ended_at: string | null;
        started_by: string;
    }

    interface AuditLogEntry {
        id: number | string;
        source?: string;
        session_alias: string;
        action_type: string;
        station: string;
        staff: string;
        remarks: string | null;
        created_at: string;
    }

    let {
        programs = [],
        stations = [],
        staffUsers = [],
    }: {
        programs: ProgramItem[];
        stations: StationItem[];
        staffUsers: StaffUserItem[];
    } = $props();

    let data = $state<AuditLogEntry[]>([]);
    let meta = $state<{
        total: number;
        per_page: number;
        current_page: number;
    } | null>(null);
    let loading = $state(true);

    // Filters
    let showFilters = $state(false);
    let filterProgramId = $state<number | "">("");
    let filterFrom = $state("");
    let filterTo = $state("");
    let filterActionType = $state("");
    let filterStationId = $state<number | "">("");
    let filterStaffUserId = $state<number | "">("");
    let filterProgramSessionId = $state<number | "">("");

    let activeFiltersCount = $derived(
        [
            filterProgramId,
            filterFrom,
            filterTo,
            filterActionType,
            filterStationId,
            filterStaffUserId,
            filterProgramSessionId,
        ].filter((v) => v !== "").length,
    );

    let programSessions = $state<ProgramSessionItem[]>([]);
    let programSessionsLoading = $state(false);

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

    const MSG_SESSION_EXPIRED = "Session expired. Please refresh and try again.";
    const MSG_NETWORK_ERROR = "Network error. Please try again.";

    async function api(
        method: string,
        url: string,
    ): Promise<{
        ok: boolean;
        data?: { data: AuditLogEntry[]; meta: typeof meta };
    }> {
        try {
            const res = await fetch(url, {
                method,
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            });
            if (res.status === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return { ok: false, data: undefined };
            }
            const json = await res.json().catch(() => ({}));
            return { ok: res.ok, data: json };
        } catch (e) {
            toaster.error({ title: MSG_NETWORK_ERROR });
            return { ok: false, data: undefined };
        }
    }

    function buildAuditUrl(pageNum = 1): string {
        const params = new URLSearchParams();
        if (filterProgramId) params.set("program_id", String(filterProgramId));
        if (filterFrom) params.set("from", filterFrom);
        if (filterTo) params.set("to", filterTo);
        if (filterActionType) params.set("action_type", filterActionType);
        if (filterStationId) params.set("station_id", String(filterStationId));
        if (filterStaffUserId)
            params.set("staff_user_id", String(filterStaffUserId));
        if (filterProgramSessionId)
            params.set("program_session_id", String(filterProgramSessionId));
        params.set("page", String(pageNum));
        return `/api/admin/logs/audit?${params.toString()}`;
    }

    function buildExportUrl(): string {
        const params = new URLSearchParams();
        if (filterProgramId) params.set("program_id", String(filterProgramId));
        if (filterFrom) params.set("from", filterFrom);
        if (filterTo) params.set("to", filterTo);
        if (filterActionType) params.set("action_type", filterActionType);
        if (filterStationId) params.set("station_id", String(filterStationId));
        if (filterStaffUserId)
            params.set("staff_user_id", String(filterStaffUserId));
        if (filterProgramSessionId)
            params.set("program_session_id", String(filterProgramSessionId));
        const q = params.toString();
        return q
            ? `/api/admin/logs/audit/export?${q}`
            : "/api/admin/logs/audit/export";
    }

    let exportLoading = $state(false);

    async function downloadCsv() {
        exportLoading = true;
        try {
            const res = await fetch(buildExportUrl(), {
                method: "GET",
                headers: {
                    Accept: "text/csv, application/csv",
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
                const blob = await res.blob();
                const url = URL.createObjectURL(blob);
                const a = document.createElement("a");
                a.href = url;
                a.download = `audit-log-${new Date().toISOString().slice(0, 10)}.csv`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                toaster.success({ title: "Export downloaded" });
            } else {
                const msg =
                    (await res.json().catch(() => ({})))?.message ??
                    "Export failed";
                toaster.error({ title: msg });
            }
        } catch (e) {
            const isNetwork = e instanceof TypeError && (e as Error).message === "Failed to fetch";
            toaster.error({ title: isNetwork ? MSG_NETWORK_ERROR : "Export failed" });
        } finally {
            exportLoading = false;
        }
    }

    async function fetchProgramSessions() {
        programSessionsLoading = true;
        const params = new URLSearchParams();
        if (filterProgramId) params.set("program_id", String(filterProgramId));
        if (filterFrom) params.set("from", filterFrom);
        if (filterTo) params.set("to", filterTo);
        const url = `/api/admin/logs/program-sessions?${params.toString()}`;
        const res = await api("GET", url);
        programSessionsLoading = false;
        if (
            res.ok &&
            (res.data as unknown as { program_sessions?: ProgramSessionItem[] })
                ?.program_sessions !== undefined
        ) {
            programSessions = (
                res.data as unknown as { program_sessions: ProgramSessionItem[] }
            ).program_sessions;
        } else {
            programSessions = [];
        }
    }

    async function fetchAudit(pageNum = 1) {
        loading = true;
        const res = await api("GET", buildAuditUrl(pageNum));
        loading = false;
        if (res.ok && res.data?.data !== undefined) {
            data = res.data.data;
            meta = res.data.meta ?? null;
        } else {
            data = [];
            meta = null;
            toaster.error({ title: "Failed to load audit log." });
        }
    }

    function applyFilters() {
        fetchProgramSessions();
        fetchAudit(1);
    }

    function clearFilters() {
        filterProgramId = "";
        filterFrom = "";
        filterTo = "";
        filterActionType = "";
        filterStationId = "";
        filterStaffUserId = "";
        filterProgramSessionId = "";
        fetchProgramSessions();
        fetchAudit(1);
    }

    function goToPage(pageNum: number) {
        if (
            meta &&
            pageNum >= 1 &&
            pageNum <= Math.ceil(meta.total / meta.per_page)
        ) {
            fetchAudit(pageNum);
        }
    }

    // Per 09-UI-ROUTES: LogRow color-coded by action_type; program session start/stop
    function actionBadgeClass(actionType: string): string {
        const map: Record<string, string> = {
            bind: "preset-filled-primary-500",
            call: "preset-filled-primary-500",
            check_in: "preset-filled-success-500",
            transfer: "preset-filled-warning-500",
            override: "preset-filled-error-500",
            complete: "preset-filled-success-500",
            cancel: "preset-tonal",
            no_show: "preset-filled-warning-500",
            reorder: "preset-tonal",
            force_complete: "preset-filled-error-500",
            identity_mismatch: "preset-filled-error-500",
            hold: "preset-tonal",
            resume_from_hold: "preset-filled-primary-500",
            enqueue_back: "preset-outlined",
            session_start: "preset-filled-success-500",
            session_stop: "preset-tonal",
            availability_change: "preset-filled-warning-500",
        };
        return `badge ${map[actionType] ?? "preset-tonal"}`;
    }

    function formatProgramSessionLabel(ps: ProgramSessionItem): string {
        const start = ps.started_at ? formatDate(ps.started_at) : "—";
        const end = ps.ended_at ? formatDate(ps.ended_at) : "ongoing";
        return `${ps.program_name} — ${start} to ${end}`;
    }

    function formatDate(iso: string): string {
        if (!iso) return "—";
        try {
            const d = new Date(iso);
            return d.toLocaleString(undefined, {
                dateStyle: "short",
                timeStyle: "medium",
            });
        } catch {
            return iso;
        }
    }

    const ACTION_TYPES = [
        "bind",
        "call",
        "check_in",
        "transfer",
        "override",
        "complete",
        "cancel",
        "no_show",
        "reorder",
        "force_complete",
        "identity_mismatch",
        "hold",
        "resume_from_hold",
        "enqueue_back",
        "session_start",
        "session_stop",
    ];

    const stationsForProgram = $derived(
        filterProgramId
            ? stations.filter((s) => s.program_id === filterProgramId)
            : stations,
    );

    const totalPages = $derived(
        meta ? Math.ceil(meta.total / meta.per_page) : 0,
    );

    onMount(() => {
        fetchProgramSessions();
        fetchAudit(1);
    });
</script>

<svelte:head>
    <title>Audit log — FlexiQueue</title>
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
                    <FileText class="w-6 h-6 text-primary-500" />
                    Audit log
                </h1>
                <p class="mt-2 text-surface-600 max-w-3xl leading-relaxed">
                    View transaction logs and export to CSV for COA compliance.
                </p>
            </div>
        </div>

        <!-- Filter panel -->
        <div
            class="rounded-container bg-surface-50 border border-surface-200 shadow-sm mt-2 overflow-hidden"
        >
            <button
                type="button"
                class="w-full text-left p-4 sm:p-5 flex items-center justify-between gap-2 bg-surface-100/30 hover:bg-surface-200/50 transition-colors cursor-pointer touch-target-h"
                onclick={() => (showFilters = !showFilters)}
                aria-expanded={showFilters}
                aria-controls="filter-panel-content"
            >
                <div class="flex items-center gap-2">
                    <Filter class="w-4 h-4 text-surface-500" />
                    <h2
                        class="text-base font-semibold text-surface-950 flex items-center gap-2"
                    >
                        Filter Records
                        {#if activeFiltersCount > 0}
                            <span
                                class="badge preset-filled-primary-500 text-[10px] px-1.5 py-0.5 rounded-full min-w-[20px] text-center"
                                >{activeFiltersCount}</span
                            >
                        {/if}
                    </h2>
                </div>
                <ChevronDown
                    class="w-5 h-5 text-surface-500 transition-transform duration-200 {showFilters
                        ? 'rotate-180'
                        : ''}"
                />
            </button>

            {#if showFilters}
                <div
                    id="filter-panel-content"
                    class="p-4 sm:p-5 border-t border-surface-200"
                >
                    <div
                        class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-x-4 gap-y-5"
                    >
                        <div class="form-control">
                            <label for="filter-program" class="label pb-1.5">
                                <span
                                    class="label-text text-sm font-medium text-surface-700"
                                    >Program</span
                                >
                            </label>
                            <select
                                id="filter-program"
                                class="select rounded-container border border-surface-200 px-3 py-2 text-sm bg-surface-50 shadow-sm w-full touch-target-h"
                                bind:value={filterProgramId}
                                onchange={() => {
                                    filterStationId = "";
                                    filterProgramSessionId = "";
                                }}
                            >
                                <option value="">All programs</option>
                                {#each programs as p (p.id)}
                                    <option value={p.id}>{p.name}</option>
                                {/each}
                            </select>
                        </div>
                        <div class="form-control">
                            <label for="filter-from" class="label pb-1.5">
                                <span
                                    class="label-text text-sm font-medium text-surface-700"
                                    >From Date</span
                                >
                            </label>
                            <input
                                id="filter-from"
                                type="date"
                                class="input rounded-container border border-surface-200 px-3 py-2 text-sm bg-surface-50 shadow-sm w-full touch-target-h"
                                bind:value={filterFrom}
                            />
                        </div>
                        <div class="form-control">
                            <label for="filter-to" class="label pb-1.5">
                                <span
                                    class="label-text text-sm font-medium text-surface-700"
                                    >To Date</span
                                >
                            </label>
                            <input
                                id="filter-to"
                                type="date"
                                class="input rounded-container border border-surface-200 px-3 py-2 text-sm bg-surface-50 shadow-sm w-full touch-target-h"
                                bind:value={filterTo}
                            />
                        </div>
                        <div class="form-control">
                            <label
                                for="filter-program-session"
                                class="label pb-1.5"
                            >
                                <span
                                    class="label-text text-sm font-medium text-surface-700"
                                    >Program Session</span
                                >
                            </label>
                            <select
                                id="filter-program-session"
                                class="select rounded-container border border-surface-200 px-3 py-2 text-sm bg-surface-50 shadow-sm w-full touch-target-h"
                                bind:value={filterProgramSessionId}
                                disabled={programSessionsLoading}
                            >
                                <option value="">All sessions</option>
                                {#each programSessions as ps (ps.id)}
                                    <option value={ps.id}
                                        >{formatProgramSessionLabel(ps)}</option
                                    >
                                {/each}
                            </select>
                        </div>
                        <div class="form-control">
                            <label for="filter-action" class="label pb-1.5">
                                <span
                                    class="label-text text-sm font-medium text-surface-700"
                                    >Action Type</span
                                >
                            </label>
                            <select
                                id="filter-action"
                                class="select rounded-container border border-surface-200 px-3 py-2 text-sm bg-surface-50 shadow-sm w-full touch-target-h"
                                bind:value={filterActionType}
                            >
                                <option value="">All actions</option>
                                {#each ACTION_TYPES as at}
                                    <option value={at}
                                        >{at.replace(/_/g, " ")}</option
                                    >
                                {/each}
                            </select>
                        </div>
                        <div class="form-control">
                            <label for="filter-station" class="label pb-1.5">
                                <span
                                    class="label-text text-sm font-medium text-surface-700"
                                    >Station</span
                                >
                            </label>
                            <select
                                id="filter-station"
                                class="select rounded-container border border-surface-200 px-3 py-2 text-sm bg-surface-50 shadow-sm w-full touch-target-h"
                                bind:value={filterStationId}
                            >
                                <option value="">All stations</option>
                                {#each stationsForProgram as s (s.id)}
                                    <option value={s.id}>{s.name}</option>
                                {/each}
                            </select>
                        </div>
                        <div class="form-control">
                            <label for="filter-staff" class="label pb-1.5">
                                <span
                                    class="label-text text-sm font-medium text-surface-700"
                                    >Staff</span
                                >
                            </label>
                            <select
                                id="filter-staff"
                                class="select rounded-container border border-surface-200 px-3 py-2 text-sm bg-surface-50 shadow-sm w-full touch-target-h"
                                bind:value={filterStaffUserId}
                            >
                                <option value="">All staff</option>
                                {#each staffUsers as u (u.id)}
                                    <option value={u.id}>{u.name}</option>
                                {/each}
                            </select>
                        </div>
                    </div>
                    <div
                        class="flex justify-end mt-6 pt-5 border-t border-surface-100"
                    >
                        <button
                            type="button"
                            class="btn preset-filled-primary-500 flex items-center gap-2 shadow-sm touch-target-h"
                            onclick={applyFilters}
                            disabled={loading}
                        >
                            <Search class="w-4 h-4" /> Apply filters
                        </button>
                    </div>
                </div>
            {/if}
        </div>

        <!-- Export bar -->
        <div
            class="flex flex-wrap items-center justify-between gap-4 mt-8 mb-4"
        >
            <div
                class="text-sm font-medium text-surface-700 bg-surface-100 px-3 py-1.5 rounded-full inline-flex items-center gap-2"
            >
                <span
                    class="w-2 h-2 rounded-full {loading
                        ? 'bg-surface-400 animate-pulse'
                        : 'bg-success-500'}"
                ></span>
                {#if meta}
                    {meta.total} record{meta.total === 1 ? "" : "s"} found
                {:else if loading}
                    Loading records...
                {:else}
                    0 records
                {/if}
            </div>
            <button
                type="button"
                class="btn preset-outlined flex items-center gap-2 shadow-sm hover:bg-surface-50 touch-target-h"
                onclick={downloadCsv}
                disabled={loading || exportLoading}
                aria-label="Download CSV export"
            >
                {#if exportLoading}
                    <span class="loading-spinner loading-sm"></span>
                {:else}
                    <Download class="w-4 h-4" />
                {/if}
                Download CSV
            </button>
        </div>

        <!-- Audit log table -->
        {#if loading}
            <div
                class="rounded-container border border-surface-200 bg-surface-50 p-12 flex flex-col items-center justify-center text-center shadow-sm mt-4"
            >
                <span class="loading-spinner loading-lg text-primary-500 mb-4"
                ></span>
                <p class="text-surface-600 font-medium animate-pulse">
                    Loading audit logs...
                </p>
            </div>
        {:else if data.length === 0}
            <div
                role="status"
                aria-label="No audit log entries"
                class="rounded-container border border-surface-200 bg-surface-50 p-12 flex flex-col items-center justify-center text-center shadow-sm mt-4"
            >
                <div
                    class="bg-surface-100 p-4 rounded-full text-surface-400 mb-4"
                >
                    <Search class="w-8 h-8" />
                </div>
                <h3 class="text-lg font-semibold text-surface-950">
                    No records found
                </h3>
                <p class="text-surface-600 max-w-sm mt-2 mb-6">
                    No audit log entries match the current filters. Try changing
                    your search criteria or clear filters.
                </p>
                <button
                    type="button"
                    class="btn preset-filled-primary-500 flex items-center gap-2 touch-target-h"
                    onclick={clearFilters}
                >
                    <Filter class="w-4 h-4" /> Clear filters
                </button>
            </div>
        {:else}
            <div
                class="table-container mt-2 hidden md:block border border-surface-200 rounded-container overflow-hidden shadow-sm bg-surface-50"
            >
                <table class="table table-zebra w-full relative">
                    <thead class="bg-surface-50 border-b border-surface-200">
                        <tr>
                            <th>Time</th>
                            <th>Session</th>
                            <th>Action</th>
                            <th>Station</th>
                            <th>Staff</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-100">
                        {#each data as entry (String(entry.id))}
                            <tr
                                class="hover:bg-surface-50/50 transition-colors"
                            >
                                <td class="whitespace-nowrap text-surface-700"
                                    >{formatDate(entry.created_at)}</td
                                >
                                <td class="font-medium text-surface-900"
                                    >{entry.session_alias}</td
                                >
                                <td>
                                    <span
                                        class="{actionBadgeClass(
                                            entry.action_type,
                                        )} shadow-sm font-semibold capitalize tracking-wide text-[11px] px-2.5 py-1 rounded-full"
                                        >{entry.action_type.replace(
                                            /_/g,
                                            " ",
                                        )}</span
                                    >
                                    {#if entry.source === "program_session"}
                                        <span
                                            class="text-[10px] px-1.5 py-0.5 rounded preset-tonal text-surface-500 border border-surface-200 ml-1 uppercase tracking-wider font-bold"
                                            >program</span
                                        >
                                    {:else if entry.source === "staff_activity"}
                                        <span
                                            class="text-[10px] px-1.5 py-0.5 rounded preset-tonal text-warning-600 border border-warning-200 ml-1 uppercase tracking-wider font-bold"
                                            >Staff status</span
                                        >
                                    {/if}
                                </td>
                                <td class="text-surface-700">{entry.station}</td
                                >
                                <td class="text-surface-700">{entry.staff}</td>
                                <td
                                    class="max-w-xs truncate text-surface-600"
                                    title={entry.remarks ?? ""}
                                    >{entry.remarks ?? "—"}</td
                                >
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>

            <!-- Mobile Card View -->
            <div class="grid grid-cols-1 gap-4 mt-4 md:hidden">
                {#each data as entry (String(entry.id))}
                    <div
                        class="card bg-surface-50 border border-surface-200 shadow-sm p-4 flex flex-col gap-3"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <span
                                    class="font-semibold text-surface-950 block"
                                    >{entry.session_alias}</span
                                >
                                <span class="text-sm text-surface-500 block"
                                    >{formatDate(entry.created_at)}</span
                                >
                            </div>
                            <div
                                class="flex flex-col items-end gap-1.5 min-w-max"
                            >
                                <span
                                    class="{actionBadgeClass(
                                        entry.action_type,
                                    )} shadow-sm font-semibold capitalize tracking-wide text-[11px] px-2.5 py-1 rounded-full"
                                >
                                    {entry.action_type.replace(/_/g, " ")}
                                </span>
                                {#if entry.source === "program_session"}
                                    <span
                                        class="text-[10px] px-1.5 py-0.5 rounded preset-tonal text-surface-500 border border-surface-200 uppercase tracking-wider font-bold"
                                    >
                                        program
                                    </span>
                                {:else if entry.source === "staff_activity"}
                                    <span
                                        class="text-[10px] px-1.5 py-0.5 rounded preset-tonal text-warning-600 border border-warning-200 uppercase tracking-wider font-bold"
                                    >
                                        Staff status
                                    </span>
                                {/if}
                            </div>
                        </div>
                        <div
                            class="grid grid-cols-2 gap-2 text-sm bg-surface-100/50 p-3 rounded-container border border-surface-200"
                        >
                            <div>
                                <span
                                    class="text-xs text-surface-500 block mb-0.5 uppercase tracking-wider font-semibold"
                                    >Station</span
                                >
                                <span
                                    class="text-surface-950 font-medium truncate block"
                                    title={entry.station}>{entry.station}</span
                                >
                            </div>
                            <div>
                                <span
                                    class="text-xs text-surface-500 block mb-0.5 uppercase tracking-wider font-semibold"
                                    >Staff</span
                                >
                                <span
                                    class="text-surface-950 font-medium truncate block"
                                    title={entry.staff}>{entry.staff}</span
                                >
                            </div>
                        </div>
                        {#if entry.remarks}
                            <div
                                class="text-sm text-surface-600 bg-surface-50 p-3 rounded-container border border-surface-200"
                            >
                                <span
                                    class="block text-[10px] font-semibold uppercase tracking-wider text-surface-500 mb-1"
                                    >Remarks</span
                                >
                                {entry.remarks}
                            </div>
                        {/if}
                    </div>
                {/each}
            </div>
        {/if}

        <!-- Pagination -->
        {#if meta && totalPages > 1}
            <div
                class="flex flex-col sm:flex-row justify-between items-center sm:items-center mt-6 mb-8 gap-4 px-2"
            >
                <span class="text-sm font-medium text-surface-600">
                    Showing page <span class="text-surface-950 font-semibold"
                        >{meta.current_page}</span
                    >
                    of
                    <span class="text-surface-950 font-semibold"
                        >{totalPages}</span
                    >
                </span>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="btn preset-outlined bg-surface-50 text-surface-700 hover:bg-surface-50 flex items-center gap-1 shadow-sm px-4 py-1.5 transition-colors disabled:opacity-50 touch-target-h"
                        disabled={meta.current_page <= 1}
                        onclick={() => goToPage(meta!.current_page - 1)}
                    >
                        Previous
                    </button>
                    <button
                        type="button"
                        class="btn preset-outlined bg-surface-50 text-surface-700 hover:bg-surface-50 flex items-center gap-1 shadow-sm px-4 py-1.5 transition-colors disabled:opacity-50 touch-target-h"
                        disabled={meta.current_page >= totalPages}
                        onclick={() => goToPage(meta!.current_page + 1)}
                    >
                        Next
                    </button>
                </div>
            </div>
        {/if}
    </div></AdminLayout
>
