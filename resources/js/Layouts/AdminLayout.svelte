<script>
    /**
     * AdminLayout — sidebar on the left (per ui-kit-preview-branded), with icons. Drawer on mobile.
     */
    import { Link } from "@inertiajs/svelte";
    import { router } from "@inertiajs/svelte";
    import { usePage } from "@inertiajs/svelte";
    import {
        LayoutDashboard,
        FolderKanban,
        Ticket,
        Users,
        BarChart3,
        PieChart,
        Settings,
        User,
        LogOut,
        Menu,
        Zap,
        X,
        ShieldCheck,
        IdCard,
        Building2,
        ChevronDown,
    } from "lucide-svelte";
    import StatusFooter from "./StatusFooter.svelte";
    import FlexiQueueToaster from "../Components/FlexiQueueToaster.svelte";
    import FlashToToast from "../Components/FlashToToast.svelte";
    import ThemeToggle from "../Components/ThemeToggle.svelte";
    import OfflineBanner from "../Components/OfflineBanner.svelte";
    import EdgeModeBanner from "../Components/EdgeModeBanner.svelte";
    import UserAvatar from "../Components/UserAvatar.svelte";
    import AppBackground from "../Components/AppBackground.svelte";
    import LogoutConfirm from "../Components/LogoutConfirm.svelte";

    let { children } = $props();
    let showLogoutConfirm = $state(false);
    /** Sidebar user menu: drop-up with Profile and Log out (per ui-ux-tasks-checklist) */
    let userMenuOpen = $state(false);
    let userMenuWrapEl = $state(null);

    const pageStore = usePage();
    const user = $derived($pageStore.props?.auth?.user ?? null);
    const roleLabel = $derived(user?.role ?? "");
    const isSuperAdmin = $derived(user?.role === "super_admin");
    const currentPath = $derived($pageStore.url ?? "");
    function isActive(href) {
        if (href === "/admin/dashboard" || href === "/admin")
            return currentPath === href || currentPath === "/admin";
        return currentPath.startsWith(href);
    }

    const superAdminNavHrefs = [
        "/admin/dashboard",
        "/admin/users",
        "/admin/sites",
        "/admin/logs",
        "/admin/settings",
    ];
    const allNavItems = [
        { href: "/admin/dashboard", label: "Dashboard", icon: LayoutDashboard },
        { href: "/admin/programs", label: "Programs", icon: FolderKanban },
        { href: "/admin/clients", label: "Clients", icon: IdCard },
        { href: "/admin/tokens", label: "Tokens", icon: Ticket },
        { href: "/admin/users", label: "Staff", icon: Users },
        { href: "/admin/sites", label: "Sites", icon: Building2 },
        { href: "/admin/logs", label: "Audit log", icon: BarChart3 },
        { href: "/admin/analytics", label: "Analytics", icon: PieChart },
        { href: "/admin/settings", label: "Configuration", icon: Settings },
    ];
    // Super-admin sees full nav including Sites index; site-scoped admin gets a single "Site settings" link to their own site show page.
    const navItems = $derived(
        isSuperAdmin
            ? allNavItems.filter((item) => superAdminNavHrefs.includes(item.href))
            : (() => {
                const baseItems = allNavItems.filter((item) => item.href !== '/admin/sites');
                if (user?.site_id != null) {
                    const siteSettingsItem = { href: `/admin/sites/${user.site_id}`, label: 'Site settings', icon: Building2 };
                    const staffIndex = baseItems.findIndex((item) => item.href === '/admin/users');
                    const insertAt = staffIndex >= 0 ? staffIndex + 1 : baseItems.length;
                    return [...baseItems.slice(0, insertAt), siteSettingsItem, ...baseItems.slice(insertAt)];
                }
                return baseItems;
            })(),
    );

    /** Close user menu on click outside */
    $effect(() => {
        if (!userMenuOpen || typeof document === 'undefined') return;
        const fn = (e) => {
            if (userMenuWrapEl && !userMenuWrapEl.contains(e.target)) userMenuOpen = false;
        };
        document.addEventListener('click', fn, true);
        return () => document.removeEventListener('click', fn, true);
    });
    /** Close user menu on Escape */
    $effect(() => {
        if (!userMenuOpen || typeof document === 'undefined') return;
        const fn = (e) => { if (e.key === 'Escape') userMenuOpen = false; };
        document.addEventListener('keydown', fn);
        return () => document.removeEventListener('keydown', fn);
    });
</script>

<div class="flex flex-col h-screen overflow-hidden bg-transparent">
    <AppBackground />
    <OfflineBanner />

    <div class="flex flex-1 min-h-0 lg:flex-row">
        <input
            id="admin-drawer"
            type="checkbox"
            class="peer sr-only"
            aria-hidden="true"
        />

        <!-- Sidebar: left on desktop, drawer from left on mobile -->
        <div
            class="fixed inset-y-0 left-0 z-40 w-72 transform -translate-x-full transition-transform duration-200 ease-out peer-checked:translate-x-0 lg:translate-x-0 lg:static lg:shrink-0"
        >
            <label
                for="admin-drawer"
                class="fixed inset-0 bg-black/50 z-30 lg:hidden"
                aria-label="Close sidebar"
            ></label>
            <!-- h-full so sidebar fills height; pb-20 so avatar+name sits above fixed StatusFooter (mobile + desktop) -->
            <!-- Per ui-ux-tasks-checklist: sidebar — no unintended gray tint; theme vars only, solid on desktop -->
            <aside
                class="relative z-40 w-72 h-full min-h-0 flex flex-col bg-surface-50 dark:bg-slate-900 border-r border-surface-200 shadow-sm pb-20 md:bg-surface-50 md:dark:bg-slate-900"
            >
                <!-- Brand header -->
                <div
                    class="h-20 flex items-center justify-between px-6 border-b border-surface-200 shrink-0"
                >
                    <Link
                        href="/"
                        class="flex items-center gap-3 no-underline text-inherit hover:opacity-90 transition-opacity"
                    >
                        <img
                            src="/images/logo.png"
                            alt="FlexiQueue logo"
                            class="h-10 w-auto shadow-md shrink-0"
                        />
                        <span
                            class="text-xl font-bold tracking-tight text-surface-950"
                            >FlexiQueue</span
                        >
                    </Link>
                    <!-- Mobile-only close button for sidebar drawer -->
                    <label
                        for="admin-drawer"
                        class="lg:hidden inline-flex items-center justify-center p-2 rounded-lg text-surface-500 hover:text-surface-950 hover:bg-surface-100 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 focus:ring-offset-surface-50 cursor-pointer"
                        aria-label="Close menu"
                    >
                        <X class="w-5 h-5" aria-hidden="true" />
                    </label>
                </div>

                <!-- Nav — padding so sidebar content doesn't overlap footer; scrollbar hidden -->
                <nav
                    class="fq-sidebar-nav flex-1 overflow-y-auto p-4 pb-8 space-y-1"
                    aria-label="Main"
                >
                    <p
                        class="px-4 text-xs font-semibold uppercase tracking-wider text-surface-600 mb-2 mt-2"
                    >
                        Menu
                    </p>
                    {#each navItems as item}
                        {@const active = isActive(item.href)}
                        <Link
                            href={item.href}
                            class="flex items-center gap-3 px-4 py-3 rounded-xl font-medium transition-colors touch-target-h {active
                                ? 'bg-primary-500 text-primary-contrast-500 shadow-sm'
                                : 'text-surface-700 hover:bg-surface-200 hover:text-surface-950'}"
                        >
                            {@const Icon = item.icon}
                            <Icon
                                class="w-5 h-5 shrink-0 {active
                                    ? 'opacity-90'
                                    : 'opacity-70'}"
                                aria-hidden="true"
                            />
                            <span>{item.label}</span>
                        </Link>
                    {/each}
                </nav>

                <!-- User footer: photo + name; click opens drop-up with Profile and Log out. Top bar kept separate. -->
                <div
                    class="relative p-4 border-t border-surface-200 shrink-0"
                    bind:this={userMenuWrapEl}
                >
                    <button
                        type="button"
                        class="w-full flex items-center gap-3 px-2 py-2 rounded-xl text-surface-700 hover:bg-surface-200 hover:text-surface-950 dark:hover:bg-slate-700 dark:hover:text-slate-100 font-medium transition-colors touch-target-h border-0 bg-transparent cursor-pointer text-left"
                        onclick={() => (userMenuOpen = !userMenuOpen)}
                        aria-haspopup="menu"
                        aria-expanded={userMenuOpen}
                        aria-label="User menu"
                    >
                        <UserAvatar {user} size="md" />
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-surface-950 dark:text-slate-100 truncate">
                                {user?.name ?? "—"}
                            </p>
                            <p class="text-xs text-surface-600 dark:text-slate-400 truncate">
                                {user?.email ?? ""}
                            </p>
                        </div>
                        <ChevronDown
                            class="w-4 h-4 shrink-0 text-surface-500 transition-transform {userMenuOpen ? 'rotate-180' : ''}"
                            aria-hidden="true"
                        />
                    </button>
                    {#if userMenuOpen}
                        <ul
                            role="menu"
                            class="absolute left-2 right-2 bottom-full mb-1 py-1 rounded-lg border border-surface-200 dark:border-slate-600 bg-surface-50 dark:bg-slate-800 shadow-lg z-[60]"
                            aria-label="User menu"
                        >
                            <li role="none">
                                <Link
                                    href="/profile"
                                    role="menuitem"
                                    class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-surface-700 dark:text-slate-200 hover:bg-surface-200 dark:hover:bg-slate-700 font-medium transition-colors touch-target-h no-underline w-full text-left"
                                    onclick={() => (userMenuOpen = false)}
                                >
                                    <User class="w-5 h-5 shrink-0 opacity-70" aria-hidden="true" />
                                    <span>Profile</span>
                                </Link>
                            </li>
                            <li role="none">
                                <button
                                    type="button"
                                    role="menuitem"
                                    class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-surface-700 dark:text-slate-200 hover:bg-surface-200 dark:hover:bg-slate-700 font-medium transition-colors touch-target-h w-full text-left border-0 bg-transparent cursor-pointer"
                                    onclick={() => { userMenuOpen = false; showLogoutConfirm = true; }}
                                >
                                    <LogOut class="w-5 h-5 shrink-0 opacity-70" aria-hidden="true" />
                                    <span>Log out</span>
                                </button>
                            </li>
                        </ul>
                    {/if}
                </div>
            </aside>
        </div>

        <!-- Main content -->
        <div class="flex flex-col flex-1 min-h-0 min-w-0 overflow-hidden">
            <header
                class="flex shrink-0 items-center justify-between bg-surface-50/70 dark:bg-slate-900/80 backdrop-blur-xl md:backdrop-blur-none md:bg-surface-50/95 md:dark:bg-slate-900/95 z-10 border-b border-surface-200 px-4 h-14 lg:px-6"
            >
                <label
                    for="admin-drawer"
                    class="p-2 -ml-2 cursor-pointer text-surface-600 hover:text-surface-950 transition-colors rounded-lg hover:bg-surface-200 lg:hidden touch-manipulation touch-target flex items-center justify-center"
                    aria-label="Open menu"
                >
                    <Menu class="w-6 h-6" aria-hidden="true" />
                </label>
                <Link
                    href="/"
                    class="font-semibold text-surface-950 lg:invisible lg:w-0 lg:overflow-hidden no-underline hover:opacity-90"
                >
                    <img
                        src="/images/logo.png"
                        alt="FlexiQueue"
                        class="h-7 w-auto"
                    />
                </Link>
                <div class="flex items-center gap-2 ml-auto">
                    <ThemeToggle />
                    <span
                        class="inline-flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-lg font-semibold bg-primary-500 text-white shadow-sm border border-primary-600/20"
                        title="Admin panel"
                    >
                        <ShieldCheck
                            class="w-4 h-4 shrink-0 opacity-90"
                            aria-hidden="true"
                        />
                        <span class="capitalize">{roleLabel}</span>
                    </span>
                </div>
            </header>

            <!-- Per ui-ux-tasks-checklist: consistent margins — same vertical/horizontal rhythm across admin pages -->
            <!-- Per docs/final-edge-mode-rush-plann.md [DF-17]: edge mode banner inside main content area -->
            <main class="fq-main-scroll flex-1 min-h-0 overflow-y-scroll py-6 px-4 sm:px-6 lg:px-8 pb-24 max-w-7xl mx-auto w-full">
                {#if $pageStore.props?.edge_mode?.is_edge}
                    <EdgeModeBanner />
                {/if}
                {#if children}
                    {@render children()}
                {/if}
            </main>

            <StatusFooter />
        </div>
    </div>

    <LogoutConfirm open={showLogoutConfirm} onClose={() => (showLogoutConfirm = false)} />
    <FlexiQueueToaster />
    <FlashToToast />
</div>
