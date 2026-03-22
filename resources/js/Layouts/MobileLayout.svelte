<script>
    /**
     * MobileLayout — full-width for Triage + Station. Per 09-UI-ROUTES-PHASE1.md Section 2.3.
     * Navbar (top) + scrollable content + dock (bottom bar). Large touch targets.
     * Bottom nav: Station | Triage (elevated QR circle + directions icon) | Track overrides.
     */
    import { Link } from "@inertiajs/svelte";
    import { usePage } from "@inertiajs/svelte";
    import { router } from "@inertiajs/svelte";
    import { Monitor, Route } from "lucide-svelte";
    import StatusFooter from "./StatusFooter.svelte";
    import FlexiQueueToaster from "../Components/FlexiQueueToaster.svelte";
    import FlashToToast from "../Components/FlashToToast.svelte";
    import ThemeToggle from "../Components/ThemeToggle.svelte";
    import OfflineBanner from "../Components/OfflineBanner.svelte";
    import UserAvatar from "../Components/UserAvatar.svelte";
    import AppBackground from "../Components/AppBackground.svelte";
    import LogoutConfirm from "../Components/LogoutConfirm.svelte";
    import ScanModal from "../Components/ScanModal.svelte";
    import StatusCheckerModal from "../Components/StatusCheckerModal.svelte";
    import StaffTriageBindModal from "../Components/StaffTriageBindModal.svelte";
    import { handleQrApproveScan } from "../lib/qrApproveHandler.js";
    import { isApprovePayload, resolveStaffTokenScan } from "../lib/qrScanResolve.js";
    import { toaster } from "../lib/toaster.js";
    import { getLocalAllowHidOnThisDevice, isMobileTouch } from "../lib/displayHid.js";
    import { shouldAllowCameraScanner } from "../lib/displayCamera.js";

    let {
        children,
        headerTitle = "FlexiQueue",
        queueCount = 0,
        processedToday = 0,
    } = $props();

    const pageStore = usePage();
    const user = $derived($pageStore.props?.auth?.user ?? null);
    const roleLabel = $derived(user?.role ?? "staff");
    const currentPath = $derived($pageStore.url ?? "");
    const isStation = $derived(
        currentPath === "/station" || currentPath.startsWith("/station/"),
    );
    const isTriage = $derived(currentPath === "/triage");
    const isTrackOverrides = $derived(currentPath === "/track-overrides");
    const isDevices = $derived(currentPath === "/devices" || currentPath.startsWith("/devices/"));
    const isSuperAdmin = $derived(user?.role === "super_admin");
    const isAdminLike = $derived(user?.role === "admin" || user?.role === "super_admin");
    const backHref = $derived(isAdminLike ? "/admin/dashboard" : "/dashboard");
    const backLabel = $derived(isAdminLike ? "Admin panel" : "Dashboard");
    const auth = $derived($pageStore.props?.auth);
    const canApproveRequests = $derived(auth?.can?.approve_requests ?? auth?.can_approve_requests ?? false);
    /** Approve flows + staff token scan (status / triage); staff.operations covers staff QR. */
    const showFooterQrButton = $derived(!!canApproveRequests || auth?.can?.staff_operations === true);
    const staffTriagePageEnabled = $derived($pageStore.props?.staff_triage_page_enabled !== false);
    const triageNavHref = $derived(staffTriagePageEnabled ? "/triage" : "/station");
    /** Account-level scan preferences (same as Triage); auth.user has staff_triage_allow_* when serialized. */
    const accountAllowHid = $derived(user?.staff_triage_allow_hid_barcode !== false);
    const accountAllowCamera = $derived(user?.staff_triage_allow_camera_scanner !== false);
    /** Device-level (staff_binder); synced when modal opens so Scan QR to approve matches Triage settings. */
    let localAllowHid = $state(true);
    let localAllowCamera = $state(true);
    const effectiveHid = $derived(accountAllowHid && localAllowHid);
    const effectiveCamera = $derived(accountAllowCamera && localAllowCamera);

    let showLogoutConfirm = $state(false);
    let showFooterQrScanner = $state(false);
    let showStatusCheckerModal = $state(false);
    let statusCheckerQrHash = $state("");
    let showStaffTriageBindModal = $state(false);
    let staffTriageBindToken = $state(null);

    $effect(() => {
        if (!showFooterQrScanner) return;
        const hidLocal = getLocalAllowHidOnThisDevice("staff_binder");
        localAllowHid = hidLocal !== null ? hidLocal : !isMobileTouch();
        localAllowCamera = shouldAllowCameraScanner("staff_binder", accountAllowCamera);
    });

    function getCsrfToken() {
        const fromProps = $pageStore.props?.csrf_token;
        if (fromProps) return fromProps;
        if (typeof document !== "undefined") {
            return document.querySelector('meta[name="csrf-token"]')?.content ?? "";
        }
        return "";
    }

    async function onFooterQrScan(decodedText) {
        const raw = decodedText.trim();
        if (isApprovePayload(raw)) {
            if (!canApproveRequests) {
                toaster.error({ title: "Only supervisors or admins can approve device requests." });
                showFooterQrScanner = false;
                return;
            }
            await handleQrApproveScan(raw, {
                onClose: () => (showFooterQrScanner = false),
                onSuccess: () => router.reload(),
            });
            return;
        }
        const res = await resolveStaffTokenScan(raw, { csrfToken: getCsrfToken() });
        if (res.kind === "not_token") {
            if (canApproveRequests) {
                await handleQrApproveScan(raw, {
                    onClose: () => (showFooterQrScanner = false),
                    onSuccess: () => router.reload(),
                });
                return;
            }
            toaster.warning({ title: "Unrecognized QR code." });
            showFooterQrScanner = false;
            return;
        }
        if (res.kind === "lookup_error") {
            toaster.error({ title: res.message });
            showFooterQrScanner = false;
            return;
        }
        if (res.kind === "token_deactivated") {
            toaster.error({ title: "Token deactivated." });
            showFooterQrScanner = false;
            return;
        }
        if (res.kind === "status") {
            statusCheckerQrHash = res.qr_hash;
            showStatusCheckerModal = true;
            showFooterQrScanner = false;
            return;
        }
        if (res.kind === "triage") {
            staffTriageBindToken = res.token;
            showStaffTriageBindModal = true;
            showFooterQrScanner = false;
        }
    }

    function closeStatusCheckerModal() {
        showStatusCheckerModal = false;
        statusCheckerQrHash = "";
    }

    function closeStaffTriageBindModal() {
        showStaffTriageBindModal = false;
        staffTriageBindToken = null;
    }
</script>

<div class="flex flex-col h-screen min-h-0 overflow-hidden bg-transparent">
    <AppBackground />
    <OfflineBanner />

    <!-- Per ui-ux-tasks-checklist: profile/marquee header padding-x = padding-y for balanced rhythm -->
    <!-- z-[100] so user dropdown stays above StatusFooter (z-40) and any footer chevrons -->
    <header
        class="flex items-center justify-between gap-2 bg-surface-50/70 dark:bg-slate-900/80 backdrop-blur-xl border-b border-surface-200 p-2 min-h-0 h-14 shrink-0 z-[100]"
    >
        <div class="min-w-0 flex-1 flex items-center gap-2">
            <Link
                href="/"
                class="shrink-0 no-underline hover:opacity-90 transition-opacity flex items-center gap-2"
            >
                <img
                    src="/images/logo.png"
                    alt="FlexiQueue logo"
                    class="h-7 w-auto"
                />
                <span
                    class="hidden sm:inline text-surface-950 text-sm font-bold tracking-tight"
                    >FlexiQueue</span
                >
            </Link>
            <!-- Staff header: static title only (no marquee on station/triage/track overrides) -->
            <span
                class="fq-mobile-header-marquee--mobile text-base font-semibold text-surface-950 truncate min-w-0"
                >{headerTitle}</span
            >
            <span
                class="fq-mobile-header-marquee--desktop text-base font-semibold text-surface-950"
                >{headerTitle}</span
            >
        </div>
        <div class="flex items-center gap-1.5 shrink-0">
            <ThemeToggle />
            <span class="text-xs px-2 py-0.5 rounded preset-filled-tertiary-500"
                >{roleLabel}</span
            >
            <div class="relative group">
                <div
                    tabindex="0"
                    role="button"
                    class="btn preset-tonal btn-icon touch-target rounded-full p-0"
                >
                    <UserAvatar {user} size="sm" />
                </div>
                <!-- Profile dropdown: no Station/Triage/Program Overrides (they are in bottom nav). Per ISSUES-ELABORATION §3. -->
                <ul
                    role="menu"
                    tabindex="0"
                    class="absolute right-0 top-full mt-1 z-[110] w-48 p-2 rounded-container shadow-xl border border-surface-200 bg-surface-50 hidden group-focus-within:block"
                >
                    <li>
                        <span
                            class="text-xs text-surface-950/60 font-medium block pt-1 pb-2 px-2 truncate leading-tight"
                            >{user?.name ?? "—"}</span
                        >
                    </li>
                    <li class="pt-1 border-t border-surface-200 mt-1 mb-1"></li>
                    <li>
                        <Link
                            href={backHref}
                            class="block py-2 px-2 rounded hover:bg-surface-100 text-surface-950 text-sm"
                            >{backLabel}</Link
                        >
                    </li>
                    <li>
                        <Link
                            href="/profile"
                            class="block py-2 px-2 rounded hover:bg-surface-100 text-surface-950 text-sm"
                            >Profile</Link
                        >
                    </li>
                    <li class="pt-1 border-t border-surface-200 mt-1">
                        <button
                            type="button"
                            class="w-full text-left py-2 px-2 rounded hover:bg-error-50 text-error-600 text-sm font-medium transition-colors"
                            onclick={() => (showLogoutConfirm = true)}
                            >Log out</button
                        >
                    </li>
                </ul>
            </div>
        </div>
    </header>

    <!-- Only this main area scrolls; header and footer stay fixed in view -->
    <!-- Per ui-ux-tasks-checklist: scrollbar always visible for consistent layout -->
    <main class="fq-main-scroll flex-1 min-h-0 min-w-0 overflow-y-scroll overflow-x-hidden px-3 py-4 sm:p-4">
        {#if children}
            {@render children()}
        {/if}
    </main>

    <!-- Footer: one strip with matching background; nav bar + status footer (program | QR | availability). -->
    <footer class="shrink-0 min-h-0 z-10 flex flex-col ">
        <!-- Nav bar: Station | Triage | Track overrides; same theme as status bar below -->
        <div class="px-3 sm:px-4 pt-3 pb-2">
            <div
                class="fq-nav-bar relative rounded-3xl border bg-surface-50 dark:bg-slate-900 border-t border-surface-200 dark:border-slate-700 shadow-md overflow-visible h-14 md:h-16 flex items-center justify-between px-4 sm:px-6 md:px-8"
            >
                <!-- Left: Station -->
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
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
                        />
                    </svg>
                    <span class="text-[0.55rem] md:text-[0.65rem]">Station</span>
                </Link>

                <!-- Center: Triage (or Station when full-page triage is off) -->
                <Link
                    href={triageNavHref}
                    class="flex flex-col items-center gap-0.5 touch-target justify-center min-w-0 flex-1 py-1 text-surface-800 dark:text-slate-200 {isTriage
                        ? 'text-primary-600 dark:text-primary-400 font-semibold'
                        : ''}"
                >
                    <Route class="h-4 w-4 md:h-5 md:w-5 shrink-0" />
                    <span class="text-[0.55rem] md:text-[0.65rem]">Triage</span>
                </Link>

                    <!-- Right: Track overrides -->
                <Link
                    href="/track-overrides"
                    class="flex flex-col items-center gap-0.5 touch-target justify-center min-w-0 flex-1 py-1 text-surface-800 dark:text-slate-200 {isTrackOverrides
                        ? 'text-primary-600 dark:text-primary-400 font-semibold'
                        : ''}"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-4 w-4 md:h-5 md:w-5 shrink-0"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        ><path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
                        /></svg
                    >
                    <span class="text-[0.55rem] md:text-[0.65rem] text-center leading-tight">Track<br />overrides</span>
                </Link>

                <!-- Devices -->
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

    <LogoutConfirm open={showLogoutConfirm} onClose={() => (showLogoutConfirm = false)} />
    <ScanModal
        open={showFooterQrScanner}
        onClose={() => (showFooterQrScanner = false)}
        title="Scan QR"
        description={canApproveRequests
            ? "Approve device requests, or scan a token QR to check queue status or start a visit."
            : "Scan a token QR to check queue status or start a visit."}
        allowHid={effectiveHid}
        allowCamera={effectiveCamera}
        onScan={onFooterQrScan}
        soundOnScan={true}
        wide={true}
    />
    <StatusCheckerModal
        open={showStatusCheckerModal}
        onClose={closeStatusCheckerModal}
        qrHash={statusCheckerQrHash}
        siteId={user?.site_id ?? null}
        csrfToken={getCsrfToken()}
    />
    <StaffTriageBindModal
        open={showStaffTriageBindModal}
        onClose={closeStaffTriageBindModal}
        token={staffTriageBindToken}
        getCsrfToken={getCsrfToken}
        staffTriageAllowHid={accountAllowHid}
        staffTriageAllowCamera={accountAllowCamera}
        onBound={() => {
            closeStaffTriageBindModal();
            toaster.success({ title: "Visit started." });
            router.reload();
        }}
    />
    <FlexiQueueToaster />
    <FlashToToast />
</div>

<style>
    /* Mobile: marquee (global fq-marquee-track). Desktop: static title. */
    .fq-mobile-header-marquee--mobile {
        width: 100%;
    }
    @media (min-width: 768px) {
        .fq-mobile-header-marquee--mobile {
            display: none;
        }
    }
    .fq-mobile-header-marquee--desktop {
        display: none;
    }
    @media (min-width: 768px) {
        .fq-mobile-header-marquee--desktop {
            display: block;
            text-align: center;
        }
    }

    .fq-nav-qr-button {
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.15), 0 2px 6px rgba(0, 0, 0, 0.1);
    }
    :global(.dark) .fq-nav-qr-button {
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.35), 0 2px 6px rgba(0, 0, 0, 0.2);
    }
</style>
