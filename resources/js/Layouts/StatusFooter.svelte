<script>
    /**
     * StatusFooter — dual indicator (network + user availability), queue count, clock.
     * Per staff-availability-status plan: [Network] [Availability] | Queue | Processed | time.
     * Network: browser connectivity (Connected/Offline).
     * Availability: user-chosen status (Available | On break | Away). Tap to cycle.
     */
    import { usePage } from "@inertiajs/svelte";
    import { Wifi, WifiOff, Users, CheckCircle2, Clock } from "lucide-svelte";

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

    const cycle = ["available", "on_break", "away"];
    const labels = {
        available: "Available",
        on_break: "On break",
        away: "Away",
        offline: "Offline",
    };

    async function cycleAvailability() {
        if (!user || isUpdating) return;
        const idx = cycle.indexOf(availabilityStatus);
        const next = cycle[(idx + 1) % cycle.length];
        availabilityStatus = next;
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
                body: JSON.stringify({ status: next }),
            });
            if (!res.ok) {
                availabilityStatus = user?.availability_status ?? "offline";
            }
        } catch {
            availabilityStatus = user?.availability_status ?? "offline";
        } finally {
            isUpdating = false;
        }
    }
</script>

<div
    class="bg-white border-t border-surface-200 px-4 py-2.5 flex flex-wrap items-center justify-between gap-y-3 text-xs font-medium text-surface-600 shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.02)] z-40 relative"
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

        <!-- Availability Status -->
        {#if user}
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
                onclick={cycleAvailability}
                disabled={isUpdating}
                aria-label="Availability: {labels[availabilityStatus] ??
                    availabilityStatus}. Tap to change"
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
            </button>
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
</div>
