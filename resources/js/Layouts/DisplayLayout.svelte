<script>
    /**
     * DisplayLayout — client-facing informant (no auth). Per 09-UI-ROUTES-PHASE1 §2.4.
     * Header: FlexiQueue, program name, date, and live time. Main: full-screen content.
     * When device_locked (from shared props), logo is non-clickable with tooltip so user knows exit is via admin scan QR.
     * Client-side navigation guard: when device_lock cookie is set, redirect any Inertia/history navigation outside allowed prefixes to the locked URL.
     * When auth is staff/admin: show staff footer (nav bar + StatusFooter with QR) like MobileLayout on program and device-selection pages.
     */
    import { get } from "svelte/store";
    import { Link, router, usePage } from "@inertiajs/svelte";
    import Marquee from "../Components/Marquee.svelte";
    import AppBackground from "../Components/AppBackground.svelte";
    import FlexiQueueToaster from "../Components/FlexiQueueToaster.svelte";
    import FlashToToast from "../Components/FlashToToast.svelte";
    import StatusFooter from "./StatusFooter.svelte";
    import ScanModal from "../Components/ScanModal.svelte";
    import { handleQrApproveScan } from "../lib/qrApproveHandler.js";
    import { getLocalAllowHidOnThisDevice, isMobileTouch } from "../lib/displayHid.js";
    import { shouldAllowCameraScanner } from "../lib/displayCamera.js";
    import { Monitor, Route } from "lucide-svelte";

    let { children, programName = null, date = "", queueCount = 0, processedToday = 0 } = $props();
    let time = $state("");
    const page = usePage();
    const deviceLocked = $derived((get(page)?.props?.device_locked) === true);
    const deviceLockedRedirectUrl = $derived((get(page)?.props?.device_locked_redirect_url) ?? null);
    const auth = $derived(get(page)?.props?.auth ?? null);
    /** Staff/admin on program or device-selection page: show nav bar + StatusFooter like MobileLayout. */
    const showStaffFooter = $derived(auth?.can?.public_device_authorize === true);
    const currentPath = $derived((get(page)?.url ?? "").split("?")[0]);
    const isStation = $derived(currentPath === "/station" || currentPath.startsWith("/station/"));
    const isTriage = $derived(currentPath === "/triage");
    const isTrackOverrides = $derived(currentPath === "/track-overrides");
    const isDevices = $derived(
        currentPath === "/devices" ||
            currentPath.startsWith("/devices/") ||
            /\/site\/[^/]+\/program\/[^/]+(\/devices)?$/.test(currentPath)
    );
    const showFooterQrButton = $derived(!!(auth?.can?.approve_requests ?? auth?.can_approve_requests));
    let showFooterQrScanner = $state(false);
    let localAllowHid = $state(true);
    let localAllowCamera = $state(true);
    const accountAllowHid = $derived(auth?.user?.staff_triage_allow_hid_barcode !== false);
    const accountAllowCamera = $derived(auth?.user?.staff_triage_allow_camera_scanner !== false);
    const effectiveHid = $derived(accountAllowHid && localAllowHid);
    const effectiveCamera = $derived(accountAllowCamera && localAllowCamera);

    $effect(() => {
        if (!showFooterQrScanner) return;
        const hidLocal = getLocalAllowHidOnThisDevice("staff_binder");
        localAllowHid = hidLocal !== null ? hidLocal : !isMobileTouch();
        localAllowCamera = shouldAllowCameraScanner("staff_binder", accountAllowCamera);
    });

    function onFooterQrScan(decodedText) {
        handleQrApproveScan(decodedText.trim(), {
            onClose: () => (showFooterQrScanner = false),
            onSuccess: () => router.reload(),
        });
    }

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

    function isPrivilegedStaffUser() {
        return auth?.can?.public_device_authorize === true;
    }

    let clearingDeviceLock = false;
    async function clearDeviceLockForPrivilegedUser() {
        if (clearingDeviceLock) return;
        clearingDeviceLock = true;
        try {
            const csrf = (get(page)?.props?.csrf_token ??
                document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ??
                "");
            await fetch("/api/public/device-lock/clear", {
                method: "POST",
                credentials: "include",
                headers: {
                    "X-CSRF-TOKEN": csrf,
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
            });
        } catch {
            // Ignore clear failures; keep navigation unblocked for staff/admin.
        } finally {
            if (typeof sessionStorage !== "undefined") {
                sessionStorage.removeItem(STORAGE_KEY);
            }
            clearingDeviceLock = false;
        }
    }

    function enforceLockClientSide() {
        const path = window.location.pathname;
        if (deviceLocked && deviceLockedRedirectUrl && typeof sessionStorage !== "undefined") {
            sessionStorage.setItem(STORAGE_KEY, deviceLockedRedirectUrl);
        }
        // Do not clear sessionStorage when device_locked is false—cached back-nav pages can have stale false; clear only after consume (unlock).
        const redirectUrl = getEffectiveRedirectUrl();
        if (!redirectUrl) return;
        if (isPrivilegedStaffUser()) {
            void clearDeviceLockForPrivilegedUser();
            return;
        }
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

<div class="flex flex-col min-h-screen {showStaffFooter ? 'h-screen min-h-0' : ''} bg-transparent">
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
                <span class="text-white/70">No active published program available</span>
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

    <main class="flex-1 min-h-0 overflow-auto px-3 py-4 sm:p-4 {showStaffFooter ? 'overflow-y-scroll' : ''}">
        {#if typeof children === "function"}
            {@render children()}
        {/if}
    </main>

    {#if showStaffFooter}
        <!-- Staff footer: nav bar + StatusFooter (like MobileLayout) on program/device-selection pages -->
        <footer class="shrink-0 min-h-0 z-10 flex flex-col">
            <div class="px-3 sm:px-4 pt-3 pb-2">
                <div
                    class="fq-nav-bar relative rounded-3xl border bg-surface-50 dark:bg-slate-900 border-t border-surface-200 dark:border-slate-700 shadow-md overflow-visible h-14 md:h-16 flex items-center justify-between px-4 sm:px-6 md:px-8"
                >
                    <Link
                        href="/station"
                        class="flex flex-col items-center gap-0.5 touch-target justify-center min-w-0 flex-1 py-1 text-surface-800 dark:text-slate-200 {isStation
                            ? 'text-primary-600 dark:text-primary-400 font-semibold'
                            : ''}"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-4 w-4 md:h-5 md:w-5 shrink-0"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                        <span class="text-[0.55rem] md:text-[0.65rem]">Station</span>
                    </Link>
                    <Link
                        href="/triage"
                        class="flex flex-col items-center gap-0.5 touch-target justify-center min-w-0 flex-1 py-1 text-surface-800 dark:text-slate-200 {isTriage
                            ? 'text-primary-600 dark:text-primary-400 font-semibold'
                            : ''}"
                    >
                        <Route class="h-4 w-4 md:h-5 md:w-5 shrink-0" />
                        <span class="text-[0.55rem] md:text-[0.65rem]">Triage</span>
                    </Link>
                    <Link
                        href="/track-overrides"
                        class="flex flex-col items-center gap-0.5 touch-target justify-center min-w-0 flex-1 py-1 text-surface-800 dark:text-slate-200 {isTrackOverrides
                            ? 'text-primary-600 dark:text-primary-400 font-semibold'
                            : ''}"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 md:h-5 md:w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                        <span class="text-[0.55rem] md:text-[0.65rem] text-center leading-tight">Track<br />overrides</span>
                    </Link>
                    <Link
                        href="/devices"
                        class="flex flex-col items-center gap-0.5 touch-target justify-center min-w-0 flex-1 py-1 text-surface-800 dark:text-slate-200 {isDevices
                            ? 'text-primary-600 dark:text-primary-400 font-semibold'
                            : ''}"
                    >
                        <Monitor class="h-4 w-4 md:h-5 md:w-5 shrink-0" />
                        <span class="text-[0.55rem] md:text-[0.65rem]">Devices</span>
                    </Link>
                </div>
            </div>
        </footer>
        <StatusFooter
            {queueCount}
            {processedToday}
            fixed={false}
            compact={true}
            showQrButton={showFooterQrButton}
            onQrClick={() => (showFooterQrScanner = true)}
        />
        <ScanModal
            open={showFooterQrScanner}
            onClose={() => (showFooterQrScanner = false)}
            title="Scan QR to approve"
            description="Scan the QR that a device is showing (settings or device authorize request). This is only for approving requests — not for token lookup."
            allowHid={effectiveHid}
            allowCamera={effectiveCamera}
            onScan={onFooterQrScan}
            soundOnScan={true}
            wide={true}
        />
    {/if}
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
