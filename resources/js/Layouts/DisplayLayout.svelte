<script>
    /**
     * DisplayLayout — client-facing informant (no auth). Per 09-UI-ROUTES-PHASE1 §2.4.
     * Header: FlexiQueue, program name, date, and live time. Main: full-screen content.
     * When device_locked (from shared props), logo is non-clickable with tooltip so user knows exit is via admin scan QR.
     * Client-side navigation guard: when device_lock cookie is set, redirect any Inertia/history navigation outside allowed prefixes to the locked URL.
     */
    import { get } from "svelte/store";
    import { Link, router, usePage } from "@inertiajs/svelte";
    import Marquee from "../Components/Marquee.svelte";
    import AppBackground from "../Components/AppBackground.svelte";
    import FlexiQueueToaster from "../Components/FlexiQueueToaster.svelte";
    import FlashToToast from "../Components/FlashToToast.svelte";

    let { children, programName = null, date = "" } = $props();
    let time = $state("");
    const page = usePage();
    const deviceLocked = $derived((get(page)?.props?.device_locked) === true);
    const deviceLockedRedirectUrl = $derived((get(page)?.props?.device_locked_redirect_url) ?? null);

    const STORAGE_KEY = "device_lock_redirect_url";

    /** Whether the current path is allowed when locked (redirectUrl from server defines the allowed prefix). */
    function isPathAllowedWhenLocked(path, redirectUrl) {
        if (!redirectUrl || typeof path !== "string") return false;
        const pathPrefix = redirectUrl.split("?")[0];
        return path === pathPrefix || path.startsWith(pathPrefix + "/") || path.startsWith(pathPrefix + "?");
    }

    function getEffectiveRedirectUrl() {
        return deviceLockedRedirectUrl ?? (typeof sessionStorage !== "undefined" ? sessionStorage.getItem(STORAGE_KEY) : null);
    }

    function enforceLockClientSide() {
        const path = window.location.pathname;
        if (deviceLocked && deviceLockedRedirectUrl && typeof sessionStorage !== "undefined") {
            sessionStorage.setItem(STORAGE_KEY, deviceLockedRedirectUrl);
        }
        // Do not clear sessionStorage when device_locked is false—cached back-nav pages can have stale false; clear only after consume (unlock).
        const redirectUrl = getEffectiveRedirectUrl();
        if (!redirectUrl) return;
        const allowed = isPathAllowedWhenLocked(path, redirectUrl);
        if (!allowed) {
            router.visit(redirectUrl, { replace: true });
        }
    }

    $effect(() => {
        const removeListener = router.on("navigate", () => {
            enforceLockClientSide();
        });
        return () => removeListener();
    });

    $effect(() => {
        if (typeof window === "undefined") return;
        const handlePopState = () => {
            setTimeout(() => enforceLockClientSide(), 0);
        };
        window.addEventListener("popstate", handlePopState);
        return () => window.removeEventListener("popstate", handlePopState);
    });

    $effect(() => {
        const update = () => {
            time = new Date().toLocaleTimeString("en-US", {
                hour: "numeric",
                minute: "2-digit",
                second: "2-digit",
                hour12: true,
            });
        };
        update();
        const id = setInterval(update, 1000);
        return () => clearInterval(id);
    });
</script>

<div class="flex flex-col min-h-screen bg-transparent">
    <AppBackground />
    <FlexiQueueToaster />
    <FlashToToast />
    <header
        class="flex items-center justify-between gap-4 bg-primary-600/80 dark:bg-slate-900/80 backdrop-blur-xl md:backdrop-blur-none md:bg-primary-600/95 md:dark:bg-slate-900/95 border-b border-primary-700/50 text-white px-4 py-2.5 shrink-0 z-10"
    >
        <div class="shrink-0">
            {#if deviceLocked}
                <span
                    class="text-lg font-bold text-inherit flex items-center gap-2 cursor-default"
                    title="Device locked. Ask supervisor or admin to scan QR to change."
                >
                    <img
                        src="/images/logo.png"
                        alt="FlexiQueue logo"
                        class="h-8 w-auto"
                    />
                    <span class="hidden sm:inline">FlexiQueue</span>
                </span>
            {:else}
                <Link
                    href="/"
                    class="text-lg font-bold text-inherit no-underline hover:opacity-90 transition-opacity flex items-center gap-2"
                >
                    <img
                        src="/images/logo.png"
                        alt="FlexiQueue logo"
                        class="h-8 w-auto"
                    />
                    <span class="hidden sm:inline">FlexiQueue</span>
                </Link>
            {/if}
        </div>
        <div
            class="flex-1 flex flex-col justify-center items-center min-w-0 gap-0.5"
        >
            {#if programName}
                <div
                    class="fq-header-marquee fq-header-marquee--mobile min-w-0"
                >
                    <Marquee
                        overflowOnly={false}
                        duration={12}
                        gapEm={2}
                        class="fq-header-marquee__inner w-full"
                    >
                        {#snippet children()}<span
                                class="text-base font-semibold whitespace-nowrap"
                                >{programName}</span
                            >{/snippet}
                    </Marquee>
                </div>
                <span class="fq-header-marquee--desktop text-base font-semibold"
                    >{programName}</span
                >
            {:else}
                <span class="text-white/70">No active program</span>
            {/if}
        </div>
        <div
            class="flex flex-col items-end gap-0.5 shrink-0 sm:flex-row sm:items-center sm:gap-4"
        >
            <span class="text-sm opacity-90">{date}</span>
            <span
                class="text-sm font-mono tabular-nums"
                aria-label="Current time">{time}</span
            >
        </div>
    </header>

    <main class="flex-1 overflow-auto px-3 py-4 sm:p-4">
        {#if typeof children === "function"}
            {@render children()}
        {/if}
    </main>
</div>

<style>
    /* Mobile: marquee (global fq-marquee-track). Desktop: static centered text. */
    .fq-header-marquee--mobile {
        width: 100%;
    }
    @media (min-width: 640px) {
        .fq-header-marquee--mobile {
            display: none;
        }
    }
    .fq-header-marquee--desktop {
        display: none;
    }
    @media (min-width: 640px) {
        .fq-header-marquee--desktop {
            display: block;
            text-align: center;
        }
    }
</style>
