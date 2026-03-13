<script lang="ts">
    /**
     * Per central-edge B.5: Sites list — link to create and to each site show.
     */
    import AdminLayout from "../../../Layouts/AdminLayout.svelte";
    import { Link } from "@inertiajs/svelte";
    import { Building2, Plus } from "lucide-svelte";

    interface SiteItem {
        id: number;
        name: string;
        slug: string;
        created_at: string | null;
    }

    let {
        sites = [],
        auth_is_super_admin = false,
    }: { sites: SiteItem[]; auth_is_super_admin?: boolean } = $props();
</script>

<svelte:head>
    <title>Sites — FlexiQueue</title>
</svelte:head>

<AdminLayout>
    <div class="flex flex-col gap-6">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-surface-950 flex items-center gap-2">
                    <Building2 class="w-6 h-6 text-primary-500" />
                    Sites
                </h1>
                <p class="mt-2 text-surface-600 max-w-3xl leading-relaxed">
                    Manage sites and API keys for edge mode and sync.
                </p>
            </div>
            {#if auth_is_super_admin}
                <div class="flex flex-col gap-3 w-full sm:w-auto sm:min-w-[200px]">
                    <Link
                        href="/admin/sites/create"
                        class="btn preset-filled-primary-500 flex items-center gap-2 w-full sm:w-auto justify-center touch-target-h"
                    >
                        <Plus class="w-4 h-4" />
                        Add site
                    </Link>
                </div>
            {/if}
        </div>

        {#if sites.length === 0}
            <div
                role="status"
                class="rounded-container bg-surface-50 border border-surface-200 p-12 flex flex-col items-center justify-center text-center shadow-sm"
                aria-label="No sites yet"
            >
                <div class="bg-surface-100 p-4 rounded-full text-surface-400 mb-4">
                    <Building2 class="w-8 h-8" />
                </div>
                <h3 class="text-lg font-semibold text-surface-950">
                    Create your first site
                </h3>
                <p class="text-surface-600 max-w-sm mt-2 mb-6">
                    Add a site to manage API keys and edge settings.
                </p>
                {#if auth_is_super_admin}
                    <Link
                        href="/admin/sites/create"
                        class="btn preset-filled-primary-500 flex items-center gap-2 touch-target-h"
                    >
                        <Plus class="w-4 h-4" /> Add site
                    </Link>
                {:else}
                    <p class="text-surface-500 text-sm">Only a super admin can create sites.</p>
                {/if}
            </div>
        {:else}
            <div class="grid gap-5 grid-cols-1 md:grid-cols-2 xl:grid-cols-3">
                {#each sites as site (site.id)}
                    <Link
                        href="/admin/sites/{site.id}"
                        class="block p-5 rounded-container bg-surface-50 border border-surface-200/50 elevation-card transition-all hover:shadow-[var(--shadow-raised)] hover:bg-surface-100/50"
                        aria-label="Manage site {site.name}"
                    >
                        <div class="flex items-start gap-3">
                            <div
                                class="shrink-0 w-10 h-10 rounded-lg bg-primary-100 dark:bg-primary-900/40 flex items-center justify-center"
                            >
                                <Building2
                                    class="w-5 h-5 text-primary-600 dark:text-primary-400"
                                    aria-hidden="true"
                                />
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="font-semibold text-surface-950 truncate">
                                    {site.name}
                                </h3>
                                <p class="text-sm text-surface-500 mt-0.5">
                                    {site.slug}
                                </p>
                            </div>
                        </div>
                    </Link>
                {/each}
            </div>
        {/if}
    </div>
</AdminLayout>
