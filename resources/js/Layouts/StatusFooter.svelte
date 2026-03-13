<script>
    /**
     * StatusFooter — dual indicator (network + user availability), queue count, clock.
     * Per staff-availability-status plan: [Network] [Availability] | Queue | Processed | time.
     * Per flexiqueue-j4n: Availability is a drop-up menu (Available | On break | Away); offline = read-only.
     * Per flexiqueue-eym: On break shows full-screen overlay with Resume button.
     */
    import { usePage, router } from "@inertiajs/svelte";
    import { Users, CheckCircle2, Clock, ChevronUp, Play } from "lucide-svelte";
    import { toaster } from "../lib/toaster.js";

    /** When true (default), footer is fixed to viewport bottom. When false, it flows in a parent fixed container (e.g. MobileLayout). */
    let { queueCount = 0, processedToday = 0, fixed = true } = $props();

    const page = usePage();
    const user = $derived($page.props?.auth?.user ?? null);
    const csrfToken = $derived($page.props?.csrf_token ?? "");
    /** Current program in context: from controller (e.g. activeProgram) or shared Inertia data (currentProgram). */
    const activeProgram = $derived($page.props?.activeProgram ?? $page.props?.currentProgram ?? null);
    const role = $derived(user?.role ?? "");
    const isSuperAdmin = $derived(role === "super_admin");
    const currentPath = $derived($page.url ?? "");
    const canSwitchProgram = $derived(!!$page.props?.canSwitchProgram);
    const programs = $derived($page.props?.programs ?? []);
    /** Routes where staff run live sessions (station/triage/overrides). Used for program context + dropdown visibility. */
    const isLiveSessionRoute = $derived(
        currentPath === "/station" ||
            currentPath.startsWith("/station/") ||
            currentPath === "/triage" ||
            currentPath === "/program-overrides",
    );
    const isAdminRoute = $derived(currentPath.startsWith("/admin"));
    /** Roles that can switch program from footer (admin/supervisor). Single place for future role changes. */
    const isAdminOrSupervisor = $derived(role === "admin" || role === "supervisor");

    let networkConnected = $state(
        typeof navigator !== "undefined" ? navigator.onLine : true,
    );
    /** Local availability state; synced from auth.user in $effect below */
    let availabilityStatus = $state("offline");
    let isUpdating = $state(false);
    let time = $state("");
    /** Per j4n: drop-up menu open state */
    let menuOpen = $state(false);
    let availabilityWrapEl = $state(null);
    /** Position for fixed-footer availability menu (anchored just above status chip) */
    let menuPosition = $state({ left: 0, bottom: 0 });
    /** Position for fixed-footer program switch drop-up */
    let programSwitchMenuPosition = $state({ left: 0, bottom: 0 });
    /** Per eym: full-screen overlay when on break (only after user selected On break and PATCH succeeded) */
    let showOnBreakOverlay = $state(false);
    let resumeButtonEl = $state(null);

    const MSG_SESSION_EXPIRED = "Session expired. Please refresh and try again.";
    const MSG_NETWORK_ERROR = "Network error. Please try again.";

    const programMode = $derived(
        activeProgram && activeProgram.is_active && !activeProgram.is_paused
            ? "ongoing"
            : "standby",
    );
    const programName = $derived(
        activeProgram?.name ?? "All programs",
    );
    const connectionLabel = $derived(
        networkConnected ? "Connected" : "Offline",
    );
    const isProgramClickable = $derived(
        !!user && !isSuperAdmin && networkConnected && programMode === "ongoing",
    );
    /** Show program dropdown: live-session when canSwitchProgram, or admin pages when admin/supervisor; need 2+ programs. */
    let programSwitchOpen = $state(false);
    const showProgramSwitch = $derived(
        programs.length > 1 &&
            ((isLiveSessionRoute && canSwitchProgram) || (isAdminRoute && isAdminOrSupervisor)),
    );
    const currentProgramId = $derived(activeProgram?.id ?? null);
    /** Main chip click = navigate (program detail or station). Chevron = open program dropdown when showProgramSwitch. */

    $effect(() => {
        const u = $page.props?.auth?.user;
        const status = u?.availability_status ?? "offline";
        availabilityStatus = status;
    });

    $effect(() => {
        const update = () => {
            time = new Date().toLocaleTimeString("en-US", {
                hour: "numeric",
                minute: "2-digit",
                hour12: true,
            });
        };
        update();
        const id = setInterval(update, 1000);
        return () => clearInterval(id);
    });
    $effect(() => {
        if (typeof window === "undefined") return;
        const handler = () => {
            networkConnected = navigator.onLine;
        };
        window.addEventListener("online", handler);
        window.addEventListener("offline", handler);
        return () => {
            window.removeEventListener("online", handler);
            window.removeEventListener("offline", handler);
        };
    });

    /** Per j4n: click outside closes menu */
    $effect(() => {
        if (!menuOpen || typeof document === "undefined") return;
        const fn = (e) => {
            const el = availabilityWrapEl;
            if (el && !el.contains(e.target)) {
                menuOpen = false;
            }
        };
        document.addEventListener("click", fn, true);
        return () => document.removeEventListener("click", fn, true);
    });

    let programSwitchWrapEl = $state(null);
    $effect(() => {
        if (!programSwitchOpen || typeof document === "undefined") return;
        const fn = (e) => {
            if (programSwitchWrapEl && !programSwitchWrapEl.contains(e.target)) {
                programSwitchOpen = false;
            }
        };
        document.addEventListener("click", fn, true);
        return () => document.removeEventListener("click", fn, true);
    });
    $effect(() => {
        if (!programSwitchOpen || typeof document === "undefined") return;
        const fn = (e) => {
            if (e.key === "Escape") programSwitchOpen = false;
        };
        document.addEventListener("keydown", fn);
        return () => document.removeEventListener("keydown", fn);
    });

    /** Per j4n: Escape closes menu */
    $effect(() => {
        if (!menuOpen || typeof document === "undefined") return;
        const fn = (e) => {
            if (e.key === "Escape") menuOpen = false;
        };
        document.addEventListener("keydown", fn);
        return () => document.removeEventListener("keydown", fn);
    });

    const menuOptions = [
        { value: "available", label: "Available" },
        { value: "on_break", label: "On break" },
        { value: "away", label: "Away" },
    ];
    const labels = {
        available: "Available",
        on_break: "On break",
        away: "Away",
        offline: "Offline",
    };

    /** When footer is fixed, keep the menu just above the status chip (not far away). */
    $effect(() => {
        if (!menuOpen || !fixed || typeof window === "undefined") return;
        const update = () => {
            const el = availabilityWrapEl;
            if (!el) return;
            const rect = el.getBoundingClientRect();
            menuPosition = {
                left: rect.left,
                bottom: Math.max(8, window.innerHeight - rect.top + 4),
            };
        };
        update();
        window.addEventListener("resize", update);
        window.addEventListener("scroll", update, true);
        return () => {
            window.removeEventListener("resize", update);
            window.removeEventListener("scroll", update, true);
        };
    });

    /** When footer is fixed, position program switch drop-up above the chip. */
    $effect(() => {
        if (!programSwitchOpen || !fixed || typeof window === "undefined") return;
        const update = () => {
            const el = programSwitchWrapEl;
            if (!el) return;
            const rect = el.getBoundingClientRect();
            programSwitchMenuPosition = {
                left: rect.left,
                bottom: Math.max(8, window.innerHeight - rect.top + 4),
            };
        };
        update();
        window.addEventListener("resize", update);
        window.addEventListener("scroll", update, true);
        return () => {
            window.removeEventListener("resize", update);
            window.removeEventListener("scroll", update, true);
        };
    });

    async function selectAvailability(status) {
        if (!user || isUpdating) return;
        isUpdating = true;
        const previous = availabilityStatus;
        availabilityStatus = status;
        menuOpen = false;
        try {
            const res = await fetch("/api/users/me/availability", {
                method: "PATCH",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": csrfToken,
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: JSON.stringify({ status }),
            });
            if (res.status === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                availabilityStatus = user?.availability_status ?? "offline";
                return;
            }
            if (res.ok) {
                const data = await res.json();
                if (data.availability_status != null) availabilityStatus = data.availability_status;
                if (status === "on_break") showOnBreakOverlay = true;
            } else {
                availabilityStatus = user?.availability_status ?? "offline";
            }
        } catch (e) {
            availabilityStatus = user?.availability_status ?? "offline";
            const isNetwork = e instanceof TypeError && String(e && e.message) === "Failed to fetch";
            if (isNetwork) toaster.error({ title: MSG_NETWORK_ERROR });
        } finally {
            isUpdating = false;
        }
    }

    function getProgramSwitchBasePath() {
        const path = typeof currentPath === "string" ? currentPath : "";
        const pathname = path.split("?")[0] || "/";
        if (pathname.startsWith("/station")) return "/station";
        if (pathname === "/triage") return "/triage";
        if (pathname === "/program-overrides") return "/program-overrides";
        return "/station";
    }

    /**
     * On program select in dropdown: switch context and stay in current "mode".
     * - On admin pages → go to that program's admin page (/admin/programs/{id}).
     * - On station/triage/overrides → set session and stay on same page type (base?program=id).
     */
    function switchProgram(programId) {
        programSwitchOpen = false;
        if (isSuperAdmin) {
            return;
        }
        if (isAdminRoute) {
            router.visit(`/admin/programs/${programId}`);
        } else {
            const base = getProgramSwitchBasePath();
            router.visit(`${base}?program=${programId}`);
        }
    }


    /** Per eym: focus Resume when overlay opens (keyboard-accessible) */
    $effect(() => {
        if (!showOnBreakOverlay || typeof document === "undefined") return;
        const t = setTimeout(() => {
            resumeButtonEl?.focus();
        }, 0);
        return () => clearTimeout(t);
    });

    /** Per eym: Resume from break — PATCH available and hide overlay. Only Resume closes overlay. */
    async function resumeFromBreak() {
        if (!user || isUpdating) return;
        isUpdating = true;
        try {
            const res = await fetch("/api/users/me/availability", {
                method: "PATCH",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": csrfToken,
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: JSON.stringify({ status: "available" }),
            });
            if (res.status === 419) {
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return;
            }
            if (res.ok) {
                const data = await res.json();
                if (data.availability_status != null) availabilityStatus = data.availability_status;
                showOnBreakOverlay = false;
            }
        } catch (e) {
            const isNetwork = e instanceof TypeError && String(e && e.message) === "Failed to fetch";
            if (isNetwork) toaster.error({ title: MSG_NETWORK_ERROR });
        } finally {
            isUpdating = false;
        }
    }

    function toggleMenu() {
        if (!user || isUpdating) return;
        if (!networkConnected) return;
        menuOpen = !menuOpen;
    }

    /**
     * Main chip click: navigate between "program view" (admin) and "station view", keeping the same program context.
     * - On station/triage/overrides → go to this program's admin page.
     * - On admin → go to station with ?program=id when we have one, so station doesn't jump to "first active".
     */
    function handleProgramClick() {
        if (!isProgramClickable || isSuperAdmin) return;

        const program = activeProgram;

        if (isAdminOrSupervisor && isLiveSessionRoute && program?.id) {
            router.visit(`/admin/programs/${program.id}`);
            return;
        }
        if (isAdminOrSupervisor && isAdminRoute) {
            router.visit(program?.id ? `/station?program=${program.id}` : "/station");
            return;
        }
        router.visit("/station");
    }
</script>

<footer
    class="shrink-0 bg-surface-50 border-t border-surface-200 px-3 sm:px-4 py-2.5 flex flex-wrap items-center justify-between gap-x-2 gap-y-2 text-xs font-medium text-surface-600 shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.02)] z-40 min-w-0 {fixed ? 'fixed bottom-0 left-0 right-0' : ''}"
    role="status"
    aria-live="polite"
>
    <div class="flex items-center gap-2 sm:gap-3 min-w-0 flex-1 shrink">
        <!-- Program + Connection: main chip = view switching (uses current program from session); chevron = precise click to open program drop-up. -->
        <div class="relative shrink-0" bind:this={programSwitchWrapEl}>
            <div
                class="flex items-stretch rounded-full border overflow-hidden
                    {programMode === 'ongoing'
                        ? 'bg-success-50 text-success-800 border-success-200'
                        : 'bg-surface-100 text-surface-800 border-surface-200'}"
            >
            <button
                type="button"
                class="flex flex-col items-start justify-center gap-0.5 px-2 sm:px-3 py-1.5 min-h-[44px] text-left transition-all duration-200 shrink-0
                    {isProgramClickable ? 'cursor-pointer hover:bg-success-100' : 'cursor-default'}"
                onclick={handleProgramClick}
                disabled={!isProgramClickable}
                aria-label="{programName} — {connectionLabel}. Click to switch between program and station view"
            >
                <span class="text-[0.65rem] sm:text-[0.72rem] font-semibold uppercase tracking-wide leading-tight whitespace-nowrap max-w-[12rem] sm:max-w-[16rem] truncate">
                    {programName}
                </span>
                <span class="inline-flex items-center gap-1 text-[0.72rem] whitespace-nowrap {programMode === 'ongoing' ? 'text-success-800' : 'text-surface-600'}">
                    <span
                        class="w-1.5 h-1.5 rounded-full shrink-0 animate-pulse {networkConnected
                            ? 'bg-success-500 shadow-[0_0_4px_rgba(34,197,94,0.7)]'
                            : 'bg-error-500 shadow-[0_0_4px_rgba(239,68,68,0.7)]'}"
                        aria-hidden="true"
                    ></span>
                    <span>{connectionLabel}</span>
                </span>
            </button>
            {#if showProgramSwitch}
                <button
                    type="button"
                    class="flex items-center justify-center min-w-[36px] px-2 border-l border-current/20 text-surface-500 hover:bg-black/5 transition-colors touch-target"
                    onclick={(e) => {
                        e.stopPropagation();
                        programSwitchOpen = !programSwitchOpen;
                    }}
                    aria-haspopup="listbox"
                    aria-expanded={programSwitchOpen}
                    aria-label="Change program (current: {activeProgram?.name ?? 'program'})"
                >
                    <ChevronUp
                        class="w-3.5 h-3.5 transition-transform {programSwitchOpen ? 'rotate-180' : ''}"
                        aria-hidden="true"
                    />
                </button>
            {/if}
            </div>
            {#if showProgramSwitch && programSwitchOpen && !fixed}
                <ul
                    role="listbox"
                    class="absolute bottom-full left-0 mb-1 min-w-[11rem] max-h-[12rem] overflow-y-auto py-1 rounded-lg border border-surface-200 bg-surface-50 shadow-lg z-50"
                    aria-label="Select program"
                >
                    {#each programs as p (p.id)}
                        <li role="option" aria-selected={currentProgramId === p.id}>
                            <button
                                type="button"
                                class="w-full text-left flex items-center gap-2 px-3 py-2 text-sm rounded-none
                                    {currentProgramId === p.id
                                        ? 'text-primary-700 font-medium'
                                        : 'text-surface-700 hover:bg-surface-100'}"
                                onclick={() => switchProgram(p.id)}
                            >
                                {#if currentProgramId === p.id}
                                    <CheckCircle2 class="w-4 h-4 text-primary-500 shrink-0" aria-hidden="true" />
                                {/if}
                                <span>{p.name}</span>
                            </button>
                        </li>
                    {/each}
                </ul>
            {/if}
        </div>

        <!-- Availability Status (per j4n: drop-up selector; offline = read-only) -->
        {#if user && !isSuperAdmin}
            <div
                class="relative"
                bind:this={availabilityWrapEl}
            >
                {#if !networkConnected}
                    <div
                        class="flex items-center gap-1.5 rounded-full px-3 py-1.5 border bg-surface-100 text-surface-500 border-surface-200"
                        aria-label="Availability: Offline (no connection)"
                    >
                        <span
                            class="w-2 h-2 bg-surface-400 rounded-full"
                            aria-hidden="true"
                        ></span>
                        <span>{labels.offline}</span>
                    </div>
                {:else}
                    <button
                        type="button"
                        class="flex items-center gap-1 sm:gap-1.5 rounded-full px-2 sm:px-3 py-1.5 border transition-all duration-200 shadow-sm disabled:opacity-50 disabled:cursor-not-allowed shrink-0
						{availabilityStatus === 'available'
                            ? 'bg-success-50 text-success-700 border-success-200 hover:bg-success-100'
                            : ''}
						{availabilityStatus === 'on_break'
                            ? 'bg-warning-50 text-warning-700 border-warning-200 hover:bg-warning-100'
                            : ''}
						{availabilityStatus === 'away'
                            ? 'bg-surface-100 text-surface-700 border-surface-200 hover:bg-surface-200'
                            : ''}
						{availabilityStatus === 'offline'
                            ? 'bg-surface-100 text-surface-500 border-surface-200'
                            : ''}"
                        onclick={toggleMenu}
                        disabled={isUpdating}
                        aria-haspopup="listbox"
                        aria-expanded={menuOpen}
                        aria-label="Availability: {labels[availabilityStatus] ?? availabilityStatus}. Click to change"
                    >
                        {#if availabilityStatus === "available"}
                            <span
                                class="w-2 h-2 bg-success-500 rounded-full shadow-[0_0_4px_rgba(34,197,94,0.6)]"
                                aria-hidden="true"
                            ></span>
                        {:else if availabilityStatus === "on_break"}
                            <span
                                class="w-2 h-2 bg-warning-500 rounded-full"
                                aria-hidden="true"
                            ></span>
                        {:else}
                            <span
                                class="w-2 h-2 bg-surface-400 rounded-full"
                                aria-hidden="true"
                            ></span>
                        {/if}
                        <span>{labels[availabilityStatus] ?? availabilityStatus}</span>
                        <ChevronUp
                            class="w-3.5 h-3.5 transition-transform {menuOpen ? 'rotate-180' : ''}"
                            aria-hidden="true"
                        />
                    </button>
                    {#if menuOpen && !fixed}
                        <ul
                            role="listbox"
                            class="absolute bottom-full left-0 mb-1 min-w-[10rem] py-1 rounded-lg border border-surface-200 bg-surface-50 shadow-lg z-50"
                            aria-label="Set availability"
                        >
                            {#each menuOptions as opt}
                                <li role="option" aria-selected={availabilityStatus === opt.value}>
                                    <button
                                        type="button"
                                        class="w-full text-left flex items-center gap-2 px-3 py-2 text-sm rounded-none
                                            {availabilityStatus === opt.value
                                                ? 'bg-primary-50 text-primary-700'
                                                : 'text-surface-700 hover:bg-surface-100'}"
                                        onclick={() => selectAvailability(opt.value)}
                                        disabled={isUpdating}
                                    >
                                        {#if opt.value === "available"}
                                            <span
                                                class="w-2 h-2 bg-success-500 rounded-full shrink-0"
                                                aria-hidden="true"
                                            ></span>
                                        {:else if opt.value === "on_break"}
                                            <span
                                                class="w-2 h-2 bg-warning-500 rounded-full shrink-0"
                                                aria-hidden="true"
                                            ></span>
                                        {:else}
                                            <span
                                                class="w-2 h-2 bg-surface-400 rounded-full shrink-0"
                                                aria-hidden="true"
                                            ></span>
                                        {/if}
                                        <span>{opt.label}</span>
                                    </button>
                                </li>
                            {/each}
                        </ul>
                    {/if}
                {/if}
            </div>
        {/if}
    </div>

    <div class="flex items-center gap-2 sm:gap-5 shrink-0">
        <div
            class="flex items-center gap-1.5 text-surface-600 hidden sm:flex"
            title="Tokens in Queue"
        >
            <Users class="w-4 h-4 text-surface-400" />
            <span
                >Queue: <strong class="text-surface-900">{queueCount}</strong
                ></span
            >
        </div>
        <div
            class="flex items-center gap-1.5 text-surface-600 hidden sm:flex"
            title="Tokens Processed Today"
        >
            <CheckCircle2 class="w-4 h-4 text-surface-400" />
            <span
                >Processed: <strong class="text-surface-900"
                    >{processedToday}</strong
                ></span
            >
        </div>
        <div
            class="flex items-center gap-1 sm:gap-1.5 px-2 sm:px-3 py-1.5 bg-surface-100/50 rounded-full border border-surface-200 text-surface-700 shrink-0"
            title="Current Time"
        >
            <Clock class="w-3.5 h-3.5" />
            <span
                class="font-mono text-[11px] tracking-wider uppercase"
                aria-label="Current time">{time}</span
            >
        </div>
    </div>

    {#if programSwitchOpen && fixed && showProgramSwitch}
        <ul
            role="listbox"
            class="fixed z-[90] mb-1 min-w-[11rem] max-h-[12rem] overflow-y-auto py-1 rounded-lg border border-surface-200 bg-surface-50 shadow-lg"
            style={`left: ${programSwitchMenuPosition.left}px; bottom: ${programSwitchMenuPosition.bottom}px;`}
            aria-label="Select program"
        >
            {#each programs as p (p.id)}
                <li role="option" aria-selected={currentProgramId === p.id}>
                    <button
                        type="button"
                        class="w-full text-left flex items-center gap-2 px-3 py-2 text-sm rounded-none
                            {currentProgramId === p.id
                                ? 'text-primary-700 font-medium'
                                : 'text-surface-700 hover:bg-surface-100'}"
                        onclick={() => switchProgram(p.id)}
                    >
                        {#if currentProgramId === p.id}
                            <CheckCircle2 class="w-4 h-4 text-primary-500 shrink-0" aria-hidden="true" />
                        {/if}
                        <span>{p.name}</span>
                    </button>
                </li>
            {/each}
        </ul>
    {/if}

    {#if menuOpen && fixed}
        <ul
            role="listbox"
            class="fixed z-[90] mb-1 min-w-[10rem] py-1 rounded-lg border border-surface-200 bg-surface-50 shadow-lg"
            style={`left: ${menuPosition.left}px; bottom: ${menuPosition.bottom}px;`}
            aria-label="Set availability"
        >
            {#each menuOptions as opt}
                <li role="option" aria-selected={availabilityStatus === opt.value}>
                    <button
                        type="button"
                        class="w-full text-left flex items-center gap-2 px-3 py-2 text-sm rounded-none
                            {availabilityStatus === opt.value
                                ? 'bg-primary-50 text-primary-700'
                                : 'text-surface-700 hover:bg-surface-100'}"
                        onclick={() => selectAvailability(opt.value)}
                        disabled={isUpdating}
                    >
                        {#if opt.value === "available"}
                            <span
                                class="w-2 h-2 bg-success-500 rounded-full shrink-0"
                                aria-hidden="true"
                            ></span>
                        {:else if opt.value === "on_break"}
                            <span
                                class="w-2 h-2 bg-warning-500 rounded-full shrink-0"
                                aria-hidden="true"
                            ></span>
                        {:else}
                            <span
                                class="w-2 h-2 bg-surface-400 rounded-full shrink-0"
                                aria-hidden="true"
                            ></span>
                        {/if}
                        <span>{opt.label}</span>
                    </button>
                </li>
            {/each}
        </ul>
    {/if}

    <!-- Per eym: full-screen on-break overlay; only Resume closes it; no backdrop click -->
    {#if showOnBreakOverlay}
        <div
            class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60"
            aria-modal="true"
            role="dialog"
            aria-label="On break — resume when ready"
        >
            <button
                type="button"
                bind:this={resumeButtonEl}
                onclick={resumeFromBreak}
                disabled={isUpdating}
                class="btn preset-filled flex items-center gap-2 px-6 py-3 text-base font-medium shadow-lg focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-50"
                aria-label="Resume — set availability to Available"
            >
                <Play class="w-5 h-5" aria-hidden="true" />
                <span>Resume</span>
            </button>
        </div>
    {/if}
</footer>
