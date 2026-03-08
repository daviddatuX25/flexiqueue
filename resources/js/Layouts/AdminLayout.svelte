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
        KeyRound,
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
    } from "lucide-svelte";
    import StatusFooter from "./StatusFooter.svelte";
    import FlexiQueueToaster from "../Components/FlexiQueueToaster.svelte";
    import FlashToToast from "../Components/FlashToToast.svelte";
    import ThemeToggle from "../Components/ThemeToggle.svelte";
    import OfflineBanner from "../Components/OfflineBanner.svelte";
    import UserAvatar from "../Components/UserAvatar.svelte";

    let { children } = $props();

    const pageStore = usePage();
    const user = $derived($pageStore.props?.auth?.user ?? null);
    const roleLabel = $derived(user?.role ?? "");
    const currentPath = $derived($pageStore.url ?? "");

    function isActive(href) {
        if (href === "/admin/dashboard" || href === "/admin") return currentPath === href || currentPath === "/admin";
        return currentPath.startsWith(href);
    }

    const navItems = [
        { href: "/admin/dashboard", label: "Dashboard", icon: LayoutDashboard },
        { href: "/admin/programs", label: "Programs", icon: FolderKanban },
        { href: "/admin/tokens", label: "Tokens", icon: KeyRound },
        { href: "/admin/users", label: "Staff", icon: Users },
        { href: "/admin/logs", label: "Audit log", icon: BarChart3 },
        { href: "/admin/analytics", label: "Analytics", icon: PieChart },
        { href: "/admin/settings", label: "System", icon: Settings },
    ];
</script>

<div class="flex flex-col h-screen overflow-hidden bg-surface-100">
    <OfflineBanner />

    <div class="flex flex-1 min-h-0 lg:flex-row">
        <input id="admin-drawer" type="checkbox" class="peer sr-only" aria-hidden="true" />

        <!-- Sidebar: left on desktop, drawer from left on mobile -->
        <div
            class="fixed inset-y-0 left-0 z-40 w-72 transform -translate-x-full transition-transform duration-200 ease-out peer-checked:translate-x-0 lg:translate-x-0 lg:static lg:shrink-0"
        >
            <label
                for="admin-drawer"
                class="fixed inset-0 bg-black/50 z-30 lg:hidden"
                aria-label="Close sidebar"
            ></label>
            <aside
                class="relative z-40 w-72 min-h-full flex flex-col bg-surface-50 border-r border-surface-200 shadow-sm"
            >
                <!-- Brand header -->
                <div class="h-20 flex items-center justify-between px-6 border-b border-surface-200 shrink-0">
                    <div class="flex items-center gap-3">
                        <div
                            class="w-10 h-10 rounded-xl bg-primary-500 flex items-center justify-center text-white shadow-md shrink-0"
                            aria-hidden="true"
                        >
                            <Zap class="w-5 h-5" />
                        </div>
                        <span class="text-xl font-bold tracking-tight text-surface-950">FlexiQueue</span>
                    </div>
                    <!-- Mobile-only close button for sidebar drawer -->
                    <label
                        for="admin-drawer"
                        class="lg:hidden inline-flex items-center justify-center p-2 rounded-lg text-surface-500 hover:text-surface-950 hover:bg-surface-100 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 focus:ring-offset-surface-50 cursor-pointer"
                        aria-label="Close menu"
                    >
                        <X class="w-5 h-5" aria-hidden="true" />
                    </label>
                </div>

                <!-- Nav -->
                <nav class="flex-1 overflow-y-auto p-4 space-y-1" aria-label="Main">
                    <p class="px-4 text-xs font-semibold uppercase tracking-wider text-surface-600 mb-2 mt-2">
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
                            <Icon class="w-5 h-5 shrink-0 {active ? 'opacity-90' : 'opacity-70'}" aria-hidden="true" />
                            <span>{item.label}</span>
                        </Link>
                    {/each}
                </nav>

                <!-- User footer -->
                <div class="p-4 border-t border-surface-200 flex flex-col gap-3 shrink-0">
                    <div class="flex items-center gap-3 px-2 py-1.5 rounded-xl">
                        <UserAvatar {user} size="md" />
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-surface-950 truncate">{user?.name ?? "—"}</p>
                            <p class="text-xs text-surface-600 truncate">{user?.email ?? ""}</p>
                        </div>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Link
                            href="/profile"
                            class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-surface-700 hover:bg-surface-200 hover:text-surface-950 font-medium transition-colors touch-target-h"
                        >
                            <User class="w-5 h-5 shrink-0 opacity-70" aria-hidden="true" />
                            <span>Profile</span>
                        </Link>
                        <button
                            type="button"
                            class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-surface-700 hover:bg-surface-200 hover:text-surface-950 font-medium transition-colors touch-target-h w-full text-left border-0 bg-transparent cursor-pointer"
                            onclick={() => router.post("/logout")}
                        >
                            <LogOut class="w-5 h-5 shrink-0 opacity-70" aria-hidden="true" />
                            <span>Log out</span>
                        </button>
                    </div>
                </div>
            </aside>
        </div>

        <!-- Main content -->
        <div class="flex flex-col flex-1 min-h-0 min-w-0 overflow-hidden">
            <header
                class="flex shrink-0 items-center justify-between bg-surface-50 border-b border-surface-200 px-4 h-14 lg:px-6"
            >
                <label
                    for="admin-drawer"
                    class="p-2 -ml-2 cursor-pointer text-surface-600 hover:text-surface-950 transition-colors rounded-lg hover:bg-surface-200 lg:hidden touch-manipulation touch-target flex items-center justify-center"
                    aria-label="Open menu"
                >
                    <Menu class="w-6 h-6" aria-hidden="true" />
                </label>
                <span class="font-semibold text-surface-950 lg:invisible lg:w-0 lg:overflow-hidden">FlexiQueue</span>
                <div class="flex items-center gap-2 ml-auto">
                    <ThemeToggle />
                    <span
                        class="inline-flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-lg font-semibold bg-primary-500 text-white shadow-sm border border-primary-600/20"
                        title="Admin panel"
                    >
                        <ShieldCheck class="w-4 h-4 shrink-0 opacity-90" aria-hidden="true" />
                        <span class="capitalize">{roleLabel}</span>
                    </span>
                </div>
            </header>

            <main class="flex-1 min-h-0 overflow-y-auto p-6 pb-24 max-w-7xl">
                {#if children}
                    {@render children()}
                {/if}
            </main>

            <StatusFooter />
        </div>
    </div>

    <FlexiQueueToaster />
    <FlashToToast />
</div>
