<script lang="ts">
    import AdminLayout from "../../Layouts/AdminLayout.svelte";
    import { Link } from "@inertiajs/svelte";
    import { LayoutDashboard, Building2, Users, Settings } from "lucide-svelte";

    interface Props {
        sites_count: number;
        admins_count: number;
    }

    let { sites_count = 0, admins_count = 0 }: Props = $props();
</script>

<svelte:head>
    <title>Platform Dashboard — FlexiQueue</title>
</svelte:head>

<AdminLayout>
    <div class="flex flex-col gap-6 max-w-[1600px] mx-auto w-full">
        <div>
            <h1 class="text-2xl font-bold text-surface-950 flex items-center gap-2">
                <LayoutDashboard class="h-6 w-6 text-primary-600" aria-hidden="true" />
                Platform Dashboard
            </h1>
            <p class="text-sm text-surface-950/60 mt-1 ml-8">
                Manage sites, admins, and system settings.
            </p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div
                class="rounded-xl border border-surface-200 bg-surface-50/80 dark:bg-slate-800/80 p-5 shadow-sm"
            >
                <p class="text-sm font-medium text-surface-600 dark:text-surface-400">Sites</p>
                <p class="text-2xl font-bold text-surface-950 mt-1">{sites_count}</p>
                <Link
                    href="/admin/sites"
                    class="inline-flex items-center gap-2 mt-3 text-sm font-medium text-primary-600 hover:text-primary-700"
                >
                    <Building2 class="h-4 w-4" aria-hidden="true" />
                    Manage sites
                </Link>
            </div>
            <div
                class="rounded-xl border border-surface-200 bg-surface-50/80 dark:bg-slate-800/80 p-5 shadow-sm"
            >
                <p class="text-sm font-medium text-surface-600 dark:text-surface-400">Admins</p>
                <p class="text-2xl font-bold text-surface-950 mt-1">{admins_count}</p>
                <Link
                    href="/admin/users"
                    class="inline-flex items-center gap-2 mt-3 text-sm font-medium text-primary-600 hover:text-primary-700"
                >
                    <Users class="h-4 w-4" aria-hidden="true" />
                    Manage admins
                </Link>
            </div>
            <div
                class="rounded-xl border border-surface-200 bg-surface-50/80 dark:bg-slate-800/80 p-5 shadow-sm sm:col-span-2 lg:col-span-1 lg:sm:col-span-1"
            >
                <p class="text-sm font-medium text-surface-600 dark:text-surface-400">System</p>
                <p class="text-base text-surface-700 mt-1">Storage &amp; integrations</p>
                <Link
                    href="/admin/settings"
                    class="inline-flex items-center gap-2 mt-3 text-sm font-medium text-primary-600 hover:text-primary-700"
                >
                    <Settings class="h-4 w-4" aria-hidden="true" />
                    System settings
                </Link>
            </div>
        </div>
    </div>
</AdminLayout>
