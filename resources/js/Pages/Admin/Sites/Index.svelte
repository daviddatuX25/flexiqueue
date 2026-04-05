<script lang="ts">
    /**
     * Per central-edge B.5: Sites list — link to create and to each site show.
     * Per default-site plan: super_admin can set which site is default for display/triage.
     */
    import AdminLayout from "../../../Layouts/AdminLayout.svelte";
    import { Link, router, usePage } from "@inertiajs/svelte";
    import { get } from "svelte/store";
    import { Building2, Plus, Star } from "lucide-svelte";
    import { toaster } from "../../../lib/toaster.js";

    interface SiteItem {
        id: number;
        name: string;
        slug: string;
        is_default?: boolean;
        created_at: string | null;
    }

    let {
        sites = [],
        default_site_id = null,
        auth_is_super_admin = false,
    }: {
        sites: SiteItem[];
        default_site_id?: number | null;
        auth_is_super_admin?: boolean;
    } = $props();

    const page = usePage();
    const edgeMode = $derived(
        ($page?.props as { edge_mode?: { is_edge?: boolean; admin_read_only?: boolean } } | undefined)
            ?.edge_mode ?? null
    );
    let settingDefault = $state(false);

    function getCsrfToken(): string {
        const p = get(page);
        const fromProps = (p?.props as { csrf_token?: string } | undefined)?.csrf_token;
        if (fromProps) return fromProps;
        const meta =
            typeof document !== "undefined"
                ? (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content
                : "";
        return meta ?? "";
    }

    async function handleSetDefault(siteId: number): Promise<void> {
        if (settingDefault) return;
        settingDefault = true;
        try {
            const res = await fetch(`/api/admin/sites/${siteId}/default`, {
                method: "PATCH",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok) {
                toaster.success({ title: "Default site updated." });
                router.reload();
            } else {
                toaster.error({
                    title: (data as { message?: string })?.message ?? "Failed to set default site.",
                });
            }
        } finally {
            settingDefault = false;
        }
    }
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
            {#if auth_is_super_admin && !edgeMode?.admin_read_only}
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
                {#if auth_is_super_admin && !edgeMode?.admin_read_only}
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
            {#if auth_is_super_admin && sites.length > 1}
                <div
                    class="rounded-container bg-surface-50 border border-surface-200/50 p-4 flex flex-col sm:flex-row sm:items-center gap-3"
                    role="group"
                    aria-label="Default site for display and public triage"
                >
                    <label for="default-site-select" class="text-sm font-medium text-surface-700 shrink-0">
                        Default site for display and public triage
                    </label>
                    <select
                        id="default-site-select"
                        class="input filled surface max-w-xs"
                        disabled={settingDefault || !!edgeMode?.admin_read_only}
                        value={default_site_id ?? ""}
                        onchange={(e) => {
                            const id = Number((e.currentTarget as HTMLSelectElement).value);
                            if (id && id !== default_site_id) handleSetDefault(id);
                        }}
                    >
                        {#each sites as s (s.id)}
                            <option value={s.id}>{s.name}</option>
                        {/each}
                    </select>
                    {#if settingDefault}
                        <span class="text-sm text-surface-500">Updating…</span>
                    {/if}
                </div>
            {/if}
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
                                <div class="flex items-center gap-2 flex-wrap">
                                    <h3 class="font-semibold text-surface-950 truncate">
                                        {site.name}
                                    </h3>
                                    {#if site.id === default_site_id}
                                        <span
                                            class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs font-medium bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300"
                                            title="Default site for display and public triage"
                                        >
                                            <Star class="w-3 h-3" aria-hidden="true" />
                                            Default
                                        </span>
                                    {/if}
                                </div>
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
