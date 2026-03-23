<script>
    /**
     * StatusFooter — dual indicator (network + user availability), queue count, clock.
     * Per staff-availability-status plan: [Network] [Availability] | Today's progress (In queue | Served) | time.
     * Per flexiqueue-j4n: Availability is a drop-up menu (Available | On break | Away); offline = read-only.
     * Per flexiqueue-eym: On break shows full-screen overlay with Resume button.
     */
    import { usePage, router } from "@inertiajs/svelte";
    import { Users, CheckCircle2, Clock, ChevronUp, Play, QrCode } from "lucide-svelte";
    import { toaster } from "../lib/toaster.js";
    import ProgramChip from "../Components/ProgramChip.svelte";

    /** When true (default), footer is fixed to viewport bottom. When false, it flows in a parent fixed container (e.g. MobileLayout). */
    /** When true, footer has no background (e.g. inside MobileLayout where white bg is not needed). */
    /** When true and fixed=false: hide time / today's progress; layout: left=program, center=QR (if showQrButton), right=availability. */
    let { queueCount = 0, processedToday = 0, fixed = true, transparent = false, compact = false, showQrButton = false, onQrClick } = $props();

    const page = usePage();
    /** On admin: today's in-queue and served from dashboard API (real-time poll). */
    let adminInQueue = $state(null);
    let adminServedToday = $state(null);
    const user = $derived($page.props?.auth?.user ?? null);
    const csrfToken = $derived($page.props?.csrf_token ?? "");
    /** Current program in context: from controller (e.g. activeProgram) or shared Inertia data (currentProgram). */
    const activeProgram = $derived($page.props?.activeProgram ?? $page.props?.currentProgram ?? null);
    const role = $derived(user?.role ?? "");
    const isSuperAdmin = $derived(role === "super_admin");
    const currentPath = $derived($page.url ?? "");
    const pathOnly = $derived(
        (typeof currentPath === "string" ? currentPath : "").split("?")[0] || "/",
    );
    const canSwitchProgram = $derived(!!$page.props?.canSwitchProgram);
    const programs = $derived($page.props?.programs ?? []);
    /** Routes where staff run live sessions (station / client registration / overrides / display). Used for program context + dropdown visibility. */
    const isLiveSessionRoute = $derived(
        pathOnly === "/station" ||
            pathOnly.startsWith("/station/") ||
            pathOnly === "/client-registration" ||
            pathOnly === "/triage" ||
            pathOnly === "/track-overrides",
    );
    const isDisplayRoute = $derived(
        pathOnly === "/display" || /^\/site\/[^/]+\/display$/.test(pathOnly),
    );
    const isDevicesRoute = $derived(
        pathOnly === "/devices" || pathOnly.startsWith("/devices/"),
    );
    const isAdminRoute = $derived(pathOnly.startsWith("/admin"));
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
    /** Chip clickable when program is ongoing, or when admin/supervisor can switch (e.g. on admin page) so footer is never wrongly disabled. */
    const isProgramClickable = $derived(
        !!user && !isSuperAdmin && networkConnected && (programMode === "ongoing" || showProgramSwitch),
    );
    /** Show program dropdown: live-session when canSwitchProgram, or admin pages when admin/supervisor; need 2+ programs. */
    let programSwitchOpen = $state(false);
    const showProgramSwitch = $derived(
        // Station / triage / overrides: require explicit canSwitchProgram flag
        (isLiveSessionRoute && canSwitchProgram) ||
            // Any admin screen: admin/supervisor can always switch
            (isAdminRoute && isAdminOrSupervisor) ||
            // Display board: admin/supervisor can always switch
            (isDisplayRoute && isAdminOrSupervisor) ||
            // Devices pages: admin/supervisor OR staff with explicit multi-program flag
            (isDevicesRoute && (isAdminOrSupervisor || canSwitchProgram)),
    );
    const currentProgramId = $derived(activeProgram?.id ?? null);
    /** Main chip click = navigate (program detail or station). Chevron = open program dropdown when showProgramSwitch. */
    /** First program (admin context) for fetching today's progress. */
    const firstProgramId = $derived(programs?.length > 0 ? programs[0].id : null);
    /** Displayed today stats: on admin use fetched values (today only), else use props. */
    const displayInQueue = $derived(isAdminRoute && adminInQueue !== null ? adminInQueue : queueCount);
    const displayServedToday = $derived(isAdminRoute && adminServedToday !== null ? adminServedToday : processedToday);

    /** On admin route: poll dashboard stats for today's in-queue and served (real-time). */
    $effect(() => {
        if (!isAdminRoute || !firstProgramId || typeof fetch === "undefined") return;
        const csrf = $page.props?.csrf_token ?? "";
        let cancelled = false;
        async function fetchToday() {
            try {
                const res = await fetch(`/api/dashboard/stats?program_id=${firstProgramId}`, {
                    headers: { Accept: "application/json", "X-CSRF-TOKEN": csrf, "X-Requested-With": "XMLHttpRequest" },
                    credentials: "same-origin",
                });
                if (!res.ok || cancelled) return;
                const data = await res.json().catch(() => ({}));
                if (cancelled) return;
                adminInQueue = data.sessions?.active ?? 0;
                adminServedToday = data.sessions?.completed_today ?? 0;
            } catch (_) {
                if (!cancelled) {
                    adminInQueue = 0;
                    adminServedToday = 0;
                }
            }
        }
        fetchToday();
        const id = setInterval(fetchToday, 30000);
        return () => {
            cancelled = true;
            clearInterval(id);
        };
    });

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
        if (pathname === "/client-registration" || pathname === "/triage") return "/client-registration";
        if (pathname === "/track-overrides") return "/track-overrides";
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

<!-- Per ui-ux-tasks-checklist: footer program status spacing — plenty of space/padding -->
<footer
    class="shrink-0 px-4 sm:px-5 py-3.5 flex flex-wrap items-center justify-between gap-x-4 gap-y-2.5 text-xs font-medium text-surface-600 dark:text-slate-300 z-40 min-w-0 {transparent ? 'bg-transparent' : 'bg-surface-50 dark:bg-slate-900 border-t border-surface-200 dark:border-slate-700 shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.02)]'} {fixed ? 'fixed bottom-0 left-0 right-0' : ''}"
    role="status"
    aria-live="polite"
>
    {#if compact}
        <!-- Compact (mobile) layout: Program | QR (center) | Availability (right) -->
        <div class="flex items-center gap-2 sm:gap-3 min-w-0 flex-1 shrink">
            <div class="relative shrink-0 max-w-[9rem] sm:max-w-[12rem] md:max-w-none" bind:this={programSwitchWrapEl}>
                <ProgramChip
                    {programName}
                    {programMode}
                    {connectionLabel}
                    {networkConnected}
                    {isProgramClickable}
                    {showProgramSwitch}
                    {programs}
                    currentProgramId={currentProgramId}
                    programSwitchOpen={programSwitchOpen}
                    onProgramClick={handleProgramClick}
                    onChevronClick={() => (programSwitchOpen = !programSwitchOpen)}
                    onSwitchProgram={(id) => switchProgram(id)}
                    {fixed}
                    menuPosition={programSwitchMenuPosition}
                />
            </div>
        </div>

        <!-- Center: Admin/supervisor QR scan — brand green, white icon, prominent -->
        <div class="flex items-center justify-center shrink-0">
            {#if showQrButton && onQrClick}
                <button
                    type="button"
                    class="w-14 h-14 rounded-full flex items-center justify-center bg-primary-500 hover:bg-primary-600 active:bg-primary-700 text-white shadow-lg ring-2 ring-primary-400/40 active:scale-95 transition-all touch-target"
                    aria-label="Scan QR to approve"
                    onclick={onQrClick}
                >
                    <QrCode class="h-7 w-7" />
                </button>
            {:else if showQrButton}
                <div
                    class="w-12 h-12 rounded-full flex items-center justify-center bg-surface-200 dark:bg-slate-700 text-surface-500 dark:text-slate-400"
                    aria-hidden="true"
                >
                    <QrCode class="h-6 w-6" />
                </div>
            {/if}
        </div>

        <!-- Right: Staff availability -->
        <div class="flex items-center justify-end min-w-0 flex-1 shrink">
            {#if user && !isSuperAdmin}
                <div class="relative shrink-0" bind:this={availabilityWrapEl}>
                    {#if !networkConnected}
                        <div
                            class="flex items-center gap-1.5 rounded-full px-3 py-2 border bg-surface-100 dark:bg-slate-800 text-surface-500 dark:text-slate-400 border-surface-200 dark:border-slate-600"
                            aria-label="Availability: Offline"
                        >
                            <span class="w-2 h-2 bg-surface-400 rounded-full" aria-hidden="true"></span>
                            <span>{labels.offline}</span>
                        </div>
                    {:else}
                        <button
                            type="button"
                            class="flex items-center gap-1.5 rounded-full px-2 sm:px-3 py-2 border transition-all duration-200 shadow-sm disabled:opacity-50 disabled:cursor-not-allowed shrink-0
                                {availabilityStatus === 'available' ? 'bg-success-50 dark:bg-success-900/30 text-success-700 dark:text-success-300 border-success-200 dark:border-success-700 hover:bg-success-100 dark:hover:bg-success-900/50' : ''}
                                {availabilityStatus === 'on_break' ? 'bg-warning-50 dark:bg-warning-900/30 text-warning-700 dark:text-warning-300 border-warning-200 dark:border-warning-700 hover:bg-warning-100 dark:hover:bg-warning-900/50' : ''}
                                {availabilityStatus === 'away' ? 'bg-surface-100 dark:bg-slate-800 text-surface-700 dark:text-slate-300 border-surface-200 dark:border-slate-600 hover:bg-surface-200 dark:hover:bg-slate-700' : ''}
                                {availabilityStatus === 'offline' ? 'bg-surface-100 dark:bg-slate-800 text-surface-500 dark:text-slate-400 border-surface-200 dark:border-slate-600' : ''}"
                            onclick={toggleMenu}
                            disabled={isUpdating}
                            aria-haspopup="listbox"
                            aria-expanded={menuOpen}
                            aria-label="Availability: {labels[availabilityStatus] ?? availabilityStatus}"
                        >
                            {#if availabilityStatus === "available"}
                                <span class="w-2 h-2 bg-success-500 rounded-full" aria-hidden="true"></span>
                            {:else if availabilityStatus === "on_break"}
                                <span class="w-2 h-2 bg-warning-500 rounded-full" aria-hidden="true"></span>
                            {:else}
                                <span class="w-2 h-2 bg-surface-400 dark:bg-slate-500 rounded-full" aria-hidden="true"></span>
                            {/if}
                            <span>{labels[availabilityStatus] ?? availabilityStatus}</span>
                            <ChevronUp class="w-3.5 h-3.5 transition-transform {menuOpen ? 'rotate-180' : ''}" aria-hidden="true" />
                        </button>
                        {#if menuOpen && !fixed}
                            <ul
                                role="listbox"
                                class="absolute bottom-full right-0 mb-1 min-w-[10rem] py-1 rounded-lg border border-surface-200 dark:border-slate-600 bg-surface-50 dark:bg-slate-800 shadow-lg z-50"
                                aria-label="Set availability"
                            >
                                {#each menuOptions as opt}
                                    <li role="option" aria-selected={availabilityStatus === opt.value}>
                                        <button
                                            type="button"
                                            class="w-full text-left flex items-center gap-2 px-3 py-2 text-sm rounded-none text-surface-800 dark:text-slate-200 {availabilityStatus === opt.value ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300' : 'hover:bg-surface-100 dark:hover:bg-slate-700'}"
                                            onclick={() => selectAvailability(opt.value)}
                                            disabled={isUpdating}
                                        >
                                            {#if opt.value === "available"}
                                                <span class="w-2 h-2 bg-success-500 rounded-full shrink-0" aria-hidden="true"></span>
                                            {:else if opt.value === "on_break"}
                                                <span class="w-2 h-2 bg-warning-500 rounded-full shrink-0" aria-hidden="true"></span>
                                            {:else}
                                                <span class="w-2 h-2 bg-surface-400 rounded-full shrink-0" aria-hidden="true"></span>
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
    {:else}
    <!-- Default layout: left = program + availability, right = today's progress (in queue | served) + time. Program chip uses same mobile-style component as compact. -->
    <div class="flex items-center gap-2 sm:gap-3 min-w-0 flex-1 shrink">
        <div class="relative shrink-0 max-w-[9rem] sm:max-w-[12rem] md:max-w-none" bind:this={programSwitchWrapEl}>
            <ProgramChip
                {programName}
                {programMode}
                {connectionLabel}
                {networkConnected}
                {isProgramClickable}
                {showProgramSwitch}
                {programs}
                currentProgramId={currentProgramId}
                programSwitchOpen={programSwitchOpen}
                onProgramClick={handleProgramClick}
                onChevronClick={() => (programSwitchOpen = !programSwitchOpen)}
                onSwitchProgram={(id) => switchProgram(id)}
                {fixed}
                menuPosition={programSwitchMenuPosition}
            />
        </div>

        <!-- Availability Status (per j4n: drop-up selector; offline = read-only) -->
        {#if user && !isSuperAdmin}
            <div
                class="relative"
                bind:this={availabilityWrapEl}
            >
                {#if !networkConnected}
                    <div
                        class="flex items-center gap-1.5 rounded-full px-3 py-2 border bg-surface-100 text-surface-500 border-surface-200"
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
                        class="flex items-center gap-1 sm:gap-1.5 rounded-full px-2 sm:px-3 py-2 border transition-all duration-200 shadow-sm disabled:opacity-50 disabled:cursor-not-allowed shrink-0
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

    {#if !compact}
    <div class="flex items-center gap-2 sm:gap-5 shrink-0" title="Today's progress">
        <span class="hidden sm:inline text-surface-500 dark:text-slate-500 text-[11px] font-medium uppercase tracking-wide shrink-0">Today's progress</span>
        <div
            class="flex items-center gap-1.5 text-surface-600 dark:text-slate-400 hidden sm:flex"
            title="Sessions in queue today"
        >
            <Users class="w-4 h-4 text-surface-400 dark:text-slate-500" />
            <span
                >In queue: <strong class="text-surface-900 dark:text-slate-100">{displayInQueue}</strong
                ></span
            >
        </div>
        <div
            class="flex items-center gap-1.5 text-surface-600 dark:text-slate-400 hidden sm:flex"
            title="Sessions served today"
        >
            <CheckCircle2 class="w-4 h-4 text-surface-400 dark:text-slate-500" />
            <span
                >Served: <strong class="text-surface-900 dark:text-slate-100"
                    >{displayServedToday}</strong
                ></span
            >
        </div>
        <div
            class="flex items-center gap-1 sm:gap-1.5 px-2 sm:px-3 py-1.5 bg-surface-100/50 dark:bg-slate-800/50 rounded-full border border-surface-200 dark:border-slate-600 text-surface-700 dark:text-slate-300 shrink-0"
            title="Current Time"
        >
            <Clock class="w-3.5 h-3.5" />
            <span
                class="font-mono text-[11px] tracking-wider uppercase"
                aria-label="Current time">{time}</span
            >
        </div>
    </div>
    {/if}
    {/if}

    <!-- Fixed-position program dropdown is rendered inside ProgramChip when fixed=true -->

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
