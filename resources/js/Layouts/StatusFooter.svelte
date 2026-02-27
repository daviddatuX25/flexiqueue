<script>
    /**
     * StatusFooter — dual indicator (network + user availability), queue count, clock.
     * Per staff-availability-status plan: [Network] [Availability] | Queue | Processed | time.
     * Per flexiqueue-j4n: Availability is a drop-up menu (Available | On break | Away); offline = read-only.
     * Per flexiqueue-eym: On break shows full-screen overlay with Resume button.
     */
    import { usePage } from "@inertiajs/svelte";
    import { Wifi, WifiOff, Users, CheckCircle2, Clock, ChevronUp, Play } from "lucide-svelte";

    let { queueCount = 0, processedToday = 0 } = $props();

    const page = usePage();
    const user = $derived($page.props?.auth?.user ?? null);
    const csrfToken = $derived($page.props?.csrf_token ?? "");

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
    /** Per eym: full-screen overlay when on break (only after user selected On break and PATCH succeeded) */
    let showOnBreakOverlay = $state(false);
    let resumeButtonEl = $state(null);

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
            if (res.ok) {
                const data = await res.json();
                if (data.availability_status != null) availabilityStatus = data.availability_status;
                if (status === "on_break") showOnBreakOverlay = true;
            } else {
                availabilityStatus = user?.availability_status ?? "offline";
            }
        } catch {
            availabilityStatus = user?.availability_status ?? "offline";
        } finally {
            isUpdating = false;
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
            if (res.ok) {
                const data = await res.json();
                if (data.availability_status != null) availabilityStatus = data.availability_status;
                showOnBreakOverlay = false;
            }
        } catch {
            // Keep overlay open so user can retry
        } finally {
            isUpdating = false;
        }
    }

    function toggleMenu() {
        if (!user || isUpdating) return;
        if (!networkConnected) return;
        menuOpen = !menuOpen;
    }
</script>

<footer
    class="shrink-0 bg-white border-t border-surface-200 px-4 py-2.5 flex flex-wrap items-center justify-between gap-y-3 text-xs font-medium text-surface-600 shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.02)] z-40"
    role="status"
    aria-live="polite"
>
    <div class="flex items-center gap-3">
        <!-- Network Status -->
        <div
            class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-full {networkConnected
                ? 'bg-success-50 text-success-700 border border-success-100'
                : 'bg-error-50 text-error-700 border border-error-100'}"
            aria-label="Network status"
        >
            {#if networkConnected}
                <Wifi class="w-3.5 h-3.5" />
                <span>Connected</span>
            {:else}
                <WifiOff class="w-3.5 h-3.5 animate-pulse" />
                <span>Offline</span>
            {/if}
        </div>

        <!-- Availability Status (per j4n: drop-up selector; offline = read-only) -->
        {#if user}
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
                        class="flex items-center gap-1.5 rounded-full px-3 py-1.5 border transition-all duration-200 shadow-sm disabled:opacity-50 disabled:cursor-not-allowed
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
                    {#if menuOpen}
                        <ul
                            role="listbox"
                            class="absolute bottom-full left-0 mb-1 min-w-[10rem] py-1 rounded-lg border border-surface-200 bg-white shadow-lg z-50"
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

    <div class="flex items-center gap-5">
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
            class="flex items-center gap-1.5 px-3 py-1.5 bg-surface-100/50 rounded-full border border-surface-200 text-surface-700"
            title="Current Time"
        >
            <Clock class="w-3.5 h-3.5" />
            <span
                class="font-mono text-[11px] tracking-wider uppercase"
                aria-label="Current time">{time}</span
            >
        </div>
    </div>

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
