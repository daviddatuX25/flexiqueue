<script>
    /**
     * MobileLayout — full-width for Triage + Station. Per 09-UI-ROUTES-PHASE1.md Section 2.3.
     * Navbar (top) + scrollable content + dock (bottom bar). Large touch targets.
     */
    import { Link } from "@inertiajs/svelte";
    import { usePage } from "@inertiajs/svelte";
    import { router } from "@inertiajs/svelte";
    import StatusFooter from "./StatusFooter.svelte";
    import FlexiQueueToaster from "../Components/FlexiQueueToaster.svelte";
    import FlashToToast from "../Components/FlashToToast.svelte";
    import ThemeToggle from "../Components/ThemeToggle.svelte";
    import OfflineBanner from "../Components/OfflineBanner.svelte";
    import UserAvatar from "../Components/UserAvatar.svelte";
    import Marquee from "../Components/Marquee.svelte";
    import AppBackground from "../Components/AppBackground.svelte";

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
    const isProgramOverrides = $derived(currentPath === "/program-overrides");
    const isSuperAdmin = $derived(user?.role === "super_admin");
    const isAdminLike = $derived(user?.role === "admin" || user?.role === "super_admin");
    const backHref = $derived(isAdminLike ? "/admin/dashboard" : "/dashboard");
    const backLabel = $derived(isAdminLike ? "Admin panel" : "Dashboard");
</script>

<div class="flex flex-col h-screen overflow-hidden bg-transparent">
    <AppBackground />
    <OfflineBanner />

    <header
        class="flex items-center justify-between gap-2 bg-surface-50/70 dark:bg-slate-900/80 backdrop-blur-xl border-b border-surface-200 px-3 min-h-0 h-14 shrink-0 z-10"
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
            <div
                class="fq-mobile-header-marquee fq-mobile-header-marquee--mobile min-w-0"
            >
                <Marquee
                    overflowOnly={false}
                    duration={5.5}
                    gapEm={1.5}
                    class="fq-mobile-header-marquee__inner w-full"
                >
                    {#snippet children()}
                        <span
                            class="text-base font-semibold text-surface-950 whitespace-nowrap"
                            >{headerTitle}</span
                        >
                    {/snippet}
                </Marquee>
            </div>
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
                    class="absolute right-0 top-full mt-1 z-50 w-48 p-2 rounded-container shadow-xl border border-surface-200 bg-surface-50 hidden group-focus-within:block"
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
                            onclick={() => router.post("/logout")}
                            >Log out</button
                        >
                    </li>
                </ul>
            </div>
        </div>
    </header>

    <main class="flex-1 min-h-0 overflow-y-auto p-4">
        {#if children}
            {@render children()}
        {/if}
    </main>

    <div
        class="shrink-0 bg-surface-50/70 dark:bg-slate-900/80 backdrop-blur-xl z-10 border-t border-surface-200"
    >
        {#if !isSuperAdmin}
            <p
                class="text-[0.65rem] text-surface-950/50 text-center py-1 font-medium uppercase tracking-wide"
            >
                Live Session
            </p>
            <div class="flex justify-around py-2">
                <Link
                    href="/station"
                    class="flex flex-col items-center gap-0.5 touch-target justify-center {isStation
                        ? 'text-primary-500 font-semibold'
                        : 'text-surface-950/70'}"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5"
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
                    <span class="text-[0.6rem]">Station</span>
                </Link>
                <Link
                    href="/triage"
                    class="flex flex-col items-center gap-0.5 touch-target justify-center {isTriage
                        ? 'text-primary-500 font-semibold'
                        : 'text-surface-950/70'}"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        ><path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"
                        /></svg
                    >
                    <span class="text-[0.6rem]">Triage</span>
                </Link>
                <Link
                    href="/program-overrides"
                    class="flex flex-col items-center gap-0.5 touch-target justify-center {isProgramOverrides
                        ? 'text-primary-500 font-semibold'
                        : 'text-surface-950/70'}"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5"
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
                    <span class="text-[0.6rem]">Program Overrides</span>
                </Link>
            </div>
        {:else}
            <p
                class="text-[0.65rem] text-surface-950/60 text-center py-2 font-medium"
            >
                Super admin: use the Admin console on desktop for configuration.
            </p>
        {/if}
    </div>

    <StatusFooter {queueCount} {processedToday} fixed={false} />

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
</style>
