<script>
    /**
     * AdminLayout — extends AppShell with drawer sidebar (240px). Per 09-UI-ROUTES-PHASE1.md Section 2.2.
     * Sidebar: Dashboard, Programs, Tokens, Users, Reports. DaisyUI drawer + menu.
     */
    import { Link } from "@inertiajs/svelte";
    import { router } from "@inertiajs/svelte";
    import { usePage } from "@inertiajs/svelte";
    import StatusFooter from "./StatusFooter.svelte";
    import Toast from "../Components/Toast.svelte";
    import OfflineBanner from "../Components/OfflineBanner.svelte";
    import UserAvatar from "../Components/UserAvatar.svelte";

    let { children } = $props();

    const pageStore = usePage();
    const user = $derived($pageStore.props?.auth?.user ?? null);
    const roleLabel = $derived(user?.role ?? "");
    const currentPath = $derived($pageStore.url ?? "");
</script>

<div class="flex flex-col h-screen overflow-hidden bg-surface-100">
    <OfflineBanner />

    <div class="flex flex-1 min-h-0 lg:flex-row">
        <input
            id="admin-drawer"
            type="checkbox"
            class="peer sr-only"
            aria-hidden="true"
        />

        <div class="flex flex-col flex-1 min-h-0 min-w-0 overflow-hidden lg:order-1">
            <header
                class="flex shrink-0 items-center justify-between bg-surface-50 border-b border-surface-200 px-4 h-14 lg:hidden"
            >
                <label
                    for="admin-drawer"
                    class="p-2 -ml-2 cursor-pointer text-surface-600 hover:text-surface-950 transition-colors flex flex-col gap-[5px] group"
                    aria-label="Open menu"
                >
                    <span
                        class="block w-5 h-0.5 bg-current rounded-full transition-all group-hover:w-6"
                    ></span>
                    <span
                        class="block w-5 h-0.5 bg-current rounded-full transition-all"
                    ></span>
                    <span
                        class="block w-3.5 h-0.5 bg-current rounded-full transition-all group-hover:w-5"
                    ></span>
                </label>
                <span class="font-semibold text-surface-950">FlexiQueue</span>
                <div class="flex items-center gap-1.5">
                    <span
                        class="text-xs px-2 py-0.5 rounded preset-filled-primary-500"
                        >{roleLabel}</span
                    >
                    <button
                        type="button"
                        class="btn preset-tonal btn-sm"
                        onclick={() => router.post("/logout")}>Log out</button
                    >
                </div>
            </header>

            <main class="flex-1 min-h-0 overflow-y-auto p-6 max-w-7xl">
                {#if children}
                    {@render children()}
                {/if}
            </main>

            <StatusFooter />
        </div>

        <div
            class="fixed inset-y-0 right-0 z-40 w-60 transform translate-x-full transition-transform duration-200 peer-checked:translate-x-0 lg:translate-x-0 lg:static lg:order-2"
        >
            <label
                for="admin-drawer"
                class="fixed inset-0 bg-black/50 z-30 lg:hidden"
                aria-label="Close sidebar"
            ></label>
            <aside
                class="relative z-40 bg-surface-800 text-surface-50 w-60 min-h-full flex flex-col"
            >
                <div class="p-5 border-b border-surface-600">
                    <span class="text-lg font-bold">FlexiQueue</span>
                </div>
                <nav class="flex-1 p-3">
                    <ul class="space-y-0.5">
                        <li>
                            <Link
                                href="/admin/dashboard"
                                class="block py-2 px-3 rounded {currentPath ===
                                    '/admin/dashboard' ||
                                currentPath === '/admin'
                                    ? 'bg-primary-500 text-primary-contrast-500'
                                    : 'hover:bg-surface-700'}">Dashboard</Link
                            >
                        </li>
                        <li>
                            <Link
                                href="/admin/programs"
                                class="block py-2 px-3 rounded {currentPath.startsWith(
                                    '/admin/programs',
                                )
                                    ? 'bg-primary-500 text-primary-contrast-500'
                                    : 'hover:bg-surface-700'}">Programs</Link
                            >
                        </li>
                        <li>
                            <Link
                                href="/admin/tokens"
                                class="block py-2 px-3 rounded {currentPath.startsWith(
                                    '/admin/tokens',
                                )
                                    ? 'bg-primary-500 text-primary-contrast-500'
                                    : 'hover:bg-surface-700'}">Tokens</Link
                            >
                        </li>
                        <li>
                            <Link
                                href="/admin/users"
                                class="block py-2 px-3 rounded {currentPath.startsWith(
                                    '/admin/users',
                                )
                                    ? 'bg-primary-500 text-primary-contrast-500'
                                    : 'hover:bg-surface-700'}">Staff</Link
                            >
                        </li>
                        <li>
                            <Link
                                href="/admin/reports"
                                class="block py-2 px-3 rounded {currentPath.startsWith(
                                    '/admin/reports',
                                )
                                    ? 'bg-primary-500 text-primary-contrast-500'
                                    : 'hover:bg-surface-700'}">Reports</Link
                            >
                        </li>
                    </ul>
                </nav>
                <div
                    class="p-4 border-t border-surface-600 flex flex-col items-center gap-2"
                >
                    <UserAvatar {user} size="md" />
                    <span class="text-xs text-surface-400 text-center"
                        >{user?.name ?? "—"}</span
                    >
                    <Link
                        href="/profile"
                        class="btn preset-tonal btn-sm w-full mt-2 text-surface-100"
                        >Profile</Link
                    >
                    <button
                        type="button"
                        class="btn preset-tonal btn-sm w-full text-surface-100"
                        onclick={() => router.post("/logout")}>Log out</button
                    >
                </div>
            </aside>
        </div>
    </div>

    <Toast />
</div>
