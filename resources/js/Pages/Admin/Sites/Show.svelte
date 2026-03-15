<script lang="ts">
    /**
     * Per central-edge B.5: Site show — masked API key, regenerate, edge settings form.
     */
    import AdminLayout from "../../../Layouts/AdminLayout.svelte";
    import { Link, router } from "@inertiajs/svelte";
    import { get } from "svelte/store";
    import { usePage } from "@inertiajs/svelte";
    import Modal from "../../../Components/Modal.svelte";
    import ConfirmModal from "../../../Components/ConfirmModal.svelte";
    import { toaster } from "../../../lib/toaster.js";
    import { compressImage, HERO_BANNER_PRESET, getUploadHint } from "../../../lib/imageUtils.js";
    import QrDisplay from "../../../Components/QrDisplay.svelte";
    import {
        Building2,
        Key,
        RefreshCw,
        Copy,
        ArrowLeft,
        Save,
        Share2,
        ChevronUp,
        ChevronDown,
        Pencil,
        Trash2,
        Plus,
    } from "lucide-svelte";

    interface EdgeSettings {
        sync_clients: boolean;
        sync_client_scope: "program_history" | "all";
        sync_tokens: boolean;
        sync_tts: boolean;
        bridge_enabled: boolean;
        offline_binding_mode_override: "optional" | "required";
        scheduled_sync_time: string;
        offline_allow_client_creation: boolean;
    }

    const DEFAULT_EDGE: EdgeSettings = {
        sync_clients: true,
        sync_client_scope: "program_history",
        sync_tokens: true,
        sync_tts: true,
        bridge_enabled: false,
        offline_binding_mode_override: "optional",
        scheduled_sync_time: "17:00",
        offline_allow_client_creation: true,
    };

    interface SiteOption {
        id: number;
        name: string;
        slug: string;
        is_default?: boolean;
    }

    let {
        site,
        site_landing_url = "",
        site_entry_short_url = null,
        api_key_masked = "sk_live_...****",
        default_site_id = null,
        auth_is_super_admin = false,
        sites = [],
    }: {
        site: {
            id: number;
            name: string;
            slug: string;
            is_default?: boolean;
            settings: Record<string, unknown>;
            edge_settings: Partial<EdgeSettings>;
            created_at: string | null;
            updated_at: string | null;
            landing_hero_image_url?: string | null;
        };
        site_landing_url?: string;
        site_entry_short_url?: string | null;
        api_key_masked?: string;
        default_site_id?: number | null;
        auth_is_super_admin?: boolean;
        sites?: SiteOption[];
    } = $props();

    let edge = $state<EdgeSettings>({
        ...DEFAULT_EDGE,
        ...(site?.edge_settings ?? {}),
    });
    let submitting = $state(false);
    let showRegenerateConfirm = $state(false);
    let showNewKeyModal = $state(false);
    let newKeyOnce = $state<string | null>(null);
    let edgeErrors = $state<Record<string, string>>({});

    /** Per public-site plan: public access key and landing settings (from site.settings). */
    let publicAccessKey = $state<string>((site?.settings?.public_access_key as string) ?? "");
    let landingHeroTitle = $state<string>((site?.settings?.landing_hero_title as string) ?? "");
    let landingHeroDescription = $state<string>((site?.settings?.landing_hero_description as string) ?? "");
    let landingShowStats = $state<boolean>(!!site?.settings?.landing_show_stats);

    let heroUploading = $state(false);
    let heroInputEl = $state<HTMLInputElement | null>(null);
    let siteEntryQrGenerating = $state(false);

    type LandingSection = { type: string; title: string; body?: string };
    function getInitialLandingSections(): LandingSection[] {
        const raw = site?.settings?.landing_sections;
        if (!Array.isArray(raw)) return [];
        return raw.map((s: unknown) => {
            const o = s as Record<string, unknown>;
            return {
                type: typeof o?.type === "string" ? o.type : "text",
                title: typeof o?.title === "string" ? o.title : "",
                body: typeof o?.body === "string" ? o.body : "",
            };
        });
    }
    let landingSections = $state<LandingSection[]>(getInitialLandingSections());
    let editingSectionIndex = $state<number | null>(null);

    const page = usePage();

    const isDefaultSite = $derived(default_site_id !== null && site.id === default_site_id);

    async function handleSetAsDefault(): Promise<void> {
        if (submitting) return;
        submitting = true;
        const result = await api("PATCH", `/api/admin/sites/${site.id}/default`);
        submitting = false;
        if (result.ok) {
            toaster.success({ title: "Default site updated." });
            router.reload();
        } else {
            toaster.error({
                title: (result as { message?: string })?.message ?? "Failed to set default site.",
            });
        }
    }

    function getCsrfToken(): string {
        const p = get(page);
        const fromProps = (p?.props as { csrf_token?: string } | undefined)
            ?.csrf_token;
        if (fromProps) return fromProps;
        const meta =
            typeof document !== "undefined"
                ? (
                      document.querySelector(
                          'meta[name="csrf-token"]',
                      ) as HTMLMetaElement
                  )?.content
                : "";
        return meta ?? "";
    }

    async function handleHeroUpload(file: File): Promise<void> {
        if (heroUploading) return;
        heroUploading = true;
        try {
            const compressed = await compressImage(file, HERO_BANNER_PRESET);
            const formData = new FormData();
            formData.append("image", compressed);
            const res = await fetch(`/api/admin/sites/${site.id}/hero-image`, {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
                body: formData,
            });
            if (res.ok) {
                toaster.success({ title: "Hero image uploaded." });
                router.reload();
            } else {
                const data = await res.json().catch(() => ({}));
                toaster.error({
                    title: (data as { message?: string })?.message ?? "Upload failed.",
                });
            }
        } catch {
            toaster.error({ title: "Upload failed." });
        } finally {
            heroUploading = false;
        }
    }

    async function handleHeroRemove(): Promise<void> {
        if (heroUploading) return;
        if (!confirm("Remove hero image?")) return;
        heroUploading = true;
        try {
            const res = await fetch(`/api/admin/sites/${site.id}/hero-image`, {
                method: "DELETE",
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            });
            if (res.ok) {
                toaster.success({ title: "Hero image removed." });
                router.reload();
            } else {
                toaster.error({ title: "Failed to remove image." });
            }
        } catch {
            toaster.error({ title: "Failed to remove image." });
        } finally {
            heroUploading = false;
        }
    }

    function moveSectionUp(i: number): void {
        if (i <= 0) return;
        landingSections = [
            ...landingSections.slice(0, i - 1),
            landingSections[i],
            landingSections[i - 1],
            ...landingSections.slice(i + 1),
        ];
        if (editingSectionIndex === i) editingSectionIndex = i - 1;
        else if (editingSectionIndex !== null && editingSectionIndex === i - 1) editingSectionIndex = i;
    }

    function moveSectionDown(i: number): void {
        if (i >= landingSections.length - 1) return;
        landingSections = [
            ...landingSections.slice(0, i),
            landingSections[i + 1],
            landingSections[i],
            ...landingSections.slice(i + 2),
        ];
        if (editingSectionIndex === i) editingSectionIndex = i + 1;
        else if (editingSectionIndex !== null && editingSectionIndex === i + 1) editingSectionIndex = i;
    }

    function removeSection(i: number): void {
        if (!confirm("Remove this section?")) return;
        landingSections = landingSections.filter((_, idx) => idx !== i);
        if (editingSectionIndex === i) editingSectionIndex = null;
        else if (editingSectionIndex !== null && editingSectionIndex > i) editingSectionIndex--;
    }

    function addSection(): void {
        landingSections = [...landingSections, { type: "text", title: "", body: "" }];
        editingSectionIndex = landingSections.length - 1;
    }

    async function api(
        method: string,
        url: string,
        body?: object,
    ): Promise<{
        ok: boolean;
        data?: object;
        message?: string;
        errors?: Record<string, string>;
    }> {
        try {
            const res = await fetch(url, {
                method,
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
                ...(body ? { body: JSON.stringify(body) } : {}),
            });
            if (res.status === 419) {
                toaster.error({
                    title: "Session expired. Please refresh and try again.",
                });
                return { ok: false };
            }
            const data = await res.json().catch(() => ({}));
            const errMsg = (data as { message?: string })?.message;
            const errors = (data as { errors?: Record<string, string[]> })
                ?.errors;
            return {
                ok: res.ok,
                data,
                message: errMsg,
                errors: errors
                    ? Object.fromEntries(
                          Object.entries(errors).map(([k, v]) => [
                              k,
                              Array.isArray(v) ? v[0] : String(v),
                          ]),
                      )
                    : undefined,
            };
        } catch {
            toaster.error({ title: "Network error. Please try again." });
            return { ok: false };
        }
    }

    async function handleRegenerate(): Promise<void> {
        showRegenerateConfirm = false;
        submitting = true;
        const { ok, data } = await api(
            "POST",
            `/api/admin/sites/${site.id}/regenerate-key`,
        );
        submitting = false;
        if (ok && data) {
            const key = (data as { api_key?: string })?.api_key;
            if (typeof key === "string") {
                newKeyOnce = key;
                showNewKeyModal = true;
            }
            toaster.success({ title: "New API key generated." });
        } else {
            toaster.error({
                title: (data as { message?: string })?.message ?? "Failed to regenerate key.",
            });
        }
    }

    function copyNewKey(): void {
        if (!newKeyOnce) return;
        navigator.clipboard
            .writeText(newKeyOnce)
            .then(() => toaster.success({ title: "API key copied." }))
            .catch(() => toaster.error({ title: "Could not copy." }));
    }

    function closeNewKeyModal(): void {
        showNewKeyModal = false;
        newKeyOnce = null;
        router.reload();
    }

    function validateTime(value: string): boolean {
        return /^\d{2}:\d{2}$/.test(value);
    }

    async function handleEdgeSubmit(e: SubmitEvent): Promise<void> {
        e.preventDefault();
        edgeErrors = {};
        if (!validateTime(edge.scheduled_sync_time)) {
            edgeErrors["edge_settings.scheduled_sync_time"] =
                "Use HH:MM 24-hour format.";
        }
        const [h, m] = edge.scheduled_sync_time.split(":").map(Number);
        if (
            Object.keys(edgeErrors).length === 0 &&
            (h < 0 || h > 23 || m < 0 || m > 59)
        ) {
            edgeErrors["edge_settings.scheduled_sync_time"] =
                "Valid 00:00–23:59.";
        }
        if (Object.keys(edgeErrors).length > 0) {
            toaster.error({ title: "Fix the errors below." });
            return;
        }
        submitting = true;
        const result = await api(
            "PUT",
            `/api/admin/sites/${site.id}`,
            { edge_settings: edge },
        );
        submitting = false;
        const errs = result.errors;
        if (result.ok) {
            toaster.success({ title: "Edge settings saved." });
            if (errs) edgeErrors = {};
            router.reload();
        } else {
            if (errs) edgeErrors = errs;
            toaster.error({
                title: (result as { message?: string })?.message ?? "Failed to save.",
            });
        }
    }

</script>

<svelte:head>
    <title>{site.name} — Sites — FlexiQueue</title>
</svelte:head>

<AdminLayout>
    <div class="flex flex-col gap-8 max-w-4xl">
        <div class="flex items-center gap-4">
            <Link
                href="/admin/sites"
                class="btn btn-ghost btn-square btn-sm"
                aria-label="Back to sites"
            >
                <ArrowLeft class="w-5 h-5" />
            </Link>
            <div class="flex-1 min-w-0">
                <h1 class="text-2xl font-bold text-surface-950 flex items-center gap-2">
                    <Building2 class="w-6 h-6 text-primary-500 shrink-0" />
                    {site.name}
                </h1>
                <p class="text-surface-500 mt-0.5 font-mono text-sm">{site.slug}</p>
            </div>
        </div>

        {#if auth_is_super_admin && sites && sites.length > 1}
            <section
                class="rounded-container bg-surface-50 border border-surface-200 p-4 flex flex-wrap items-center gap-3"
                aria-label="Default site for display and public triage"
            >
                {#if isDefaultSite}
                    <p class="text-sm text-surface-600">
                        This site is the default for display and public triage.
                    </p>
                {:else}
                    <p class="text-sm text-surface-600 shrink-0">
                        Use this site as the default for display and public triage.
                    </p>
                    <button
                        type="button"
                        class="btn preset-tonal flex items-center gap-2 touch-target-h"
                        onclick={handleSetAsDefault}
                        disabled={submitting}
                    >
                        Use as default site
                    </button>
                {/if}
            </section>
        {/if}

        <!-- Public site URL (share); future: blog-like content here -->
        <section
            class="rounded-container bg-surface-50 border border-surface-200 p-4 flex flex-wrap items-center gap-3"
            aria-label="Public site URL"
        >
            <p class="text-sm text-surface-600 shrink-0">
                Public site landing: clients open this URL to see active programs and choose display or triage.
            </p>
            {#if site_landing_url}
                <div class="flex flex-wrap items-center gap-2 min-w-0 flex-1">
                    <code class="flex-1 min-w-0 text-sm bg-surface-100 dark:bg-surface-800 px-2 py-1.5 rounded truncate" title={site_landing_url}>{site_landing_url}</code>
                    <button
                        type="button"
                        class="btn preset-tonal flex items-center gap-2 touch-target-h"
                        onclick={() => {
                            navigator.clipboard.writeText(site_landing_url).then(
                                () => toaster.success({ title: "Copied public site URL." }),
                                () => toaster.error({ title: "Could not copy." })
                            );
                        }}
                        aria-label="Copy public site URL"
                    >
                        <Share2 class="w-4 h-4" />
                        Share site URL
                    </button>
                </div>
            {/if}
        </section>

        <!-- Public access & landing (per public-site plan) -->
        <section
            class="rounded-container bg-surface-50 border border-surface-200 p-6"
            aria-labelledby="public-access-heading"
        >
            <h2 id="public-access-heading" class="text-lg font-semibold text-surface-950 flex items-center gap-2 mb-4">
                Public access & landing
            </h2>
            <p class="text-surface-600 text-sm mb-4">
                Site key lets public devices discover this site from the homepage. Landing fields customize the public site page.
            </p>
            <form
                class="space-y-4"
                onsubmit={async (e) => {
                    e.preventDefault();
                    submitting = true;
                    const result = await api("PUT", `/api/admin/sites/${site.id}`, {
                        settings: {
                            public_access_key: publicAccessKey.trim() || null,
                            landing_hero_title: landingHeroTitle.trim() || null,
                            landing_hero_description: landingHeroDescription.trim() || null,
                            landing_show_stats: landingShowStats,
                            landing_sections: landingSections,
                        },
                    });
                    submitting = false;
                    if (result.ok) {
                        toaster.success({ title: "Saved." });
                        router.reload();
                    } else {
                        toaster.error({ title: (result as { message?: string })?.message ?? "Failed to save." });
                    }
                }}
            >
                <div>
                    <label for="public-access-key" class="block text-sm font-medium text-surface-700 dark:text-slate-300 mb-1">Site key</label>
                    <input
                        id="public-access-key"
                        type="text"
                        class="input w-full max-w-xs"
                        placeholder="e.g. TAGUDIN8"
                        bind:value={publicAccessKey}
                        maxlength={20}
                    />
                    {#if !publicAccessKey.trim()}
                        <p class="text-amber-600 dark:text-amber-400 text-sm mt-1">No public access key set — public devices cannot discover this site.</p>
                    {/if}
                </div>
                <div>
                    <span class="block text-sm font-medium text-surface-700 dark:text-slate-300 mb-1">Site entry QR</span>
                    <p class="text-sm text-surface-500 dark:text-slate-400 mb-2">Short link for QR codes: devices scan → land on homepage with site key hint.</p>
                    {#if site_entry_short_url}
                        <div class="mt-2">
                            <QrDisplay url={site_entry_short_url} label="Site entry" />
                        </div>
                    {:else}
                        <button
                            type="button"
                            class="btn variant-outline text-sm"
                            disabled={siteEntryQrGenerating}
                            onclick={async () => {
                                siteEntryQrGenerating = true;
                                const res = await api("POST", `/api/admin/sites/${site.id}/generate-qr`);
                                siteEntryQrGenerating = false;
                                if (res.ok) router.reload();
                                else toaster.error({ title: (res as { message?: string }).message ?? "Failed to generate." });
                            }}
                        >
                            {siteEntryQrGenerating ? "Generating…" : "Generate site entry QR"}
                        </button>
                    {/if}
                </div>
                <div>
                    <label for="landing-hero-title" class="block text-sm font-medium text-surface-700 dark:text-slate-300 mb-1">Landing page title</label>
                    <input
                        id="landing-hero-title"
                        type="text"
                        class="input w-full max-w-md"
                        placeholder={site.name}
                        bind:value={landingHeroTitle}
                        maxlength={120}
                    />
                </div>
                <div>
                    <label for="landing-hero-desc" class="block text-sm font-medium text-surface-700 dark:text-slate-300 mb-1">Landing description</label>
                    <textarea
                        id="landing-hero-desc"
                        class="input w-full max-w-md min-h-[80px]"
                        placeholder="Short description for the public site page"
                        bind:value={landingHeroDescription}
                        maxlength={500}
                    />
                </div>
                <div>
                    <span class="block text-sm font-medium text-surface-700 dark:text-slate-300 mb-1">Hero image</span>
                    <input
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        class="hidden"
                        bind:this={heroInputEl}
                        onchange={(e) => {
                            const file = (e.target as HTMLInputElement)?.files?.[0];
                            if (file) handleHeroUpload(file);
                            (e.target as HTMLInputElement).value = "";
                        }}
                    />
                    {#if site.landing_hero_image_url}
                        <div class="flex flex-wrap items-center gap-3 mt-2">
                            <img
                                src={site.landing_hero_image_url}
                                alt=""
                                class="max-h-20 rounded-lg border border-surface-200 dark:border-slate-600 object-cover"
                            />
                            <div class="flex gap-2">
                                <button
                                    type="button"
                                    class="btn variant-outline text-sm"
                                    disabled={heroUploading}
                                    onclick={() => heroInputEl?.click()}
                                >
                                    Replace
                                </button>
                                <button
                                    type="button"
                                    class="btn variant-outline text-sm"
                                    disabled={heroUploading}
                                    onclick={() => handleHeroRemove()}
                                >
                                    Remove
                                </button>
                            </div>
                        </div>
                    {:else}
                        <button
                            type="button"
                            class="btn variant-outline text-sm mt-2"
                            disabled={heroUploading}
                            onclick={() => heroInputEl?.click()}
                        >
                            {heroUploading ? "Uploading…" : "Upload image"}
                        </button>
                    {/if}
                    <p class="text-xs text-surface-500 mt-1">{getUploadHint('hero')}</p>
                </div>
                <div class="flex items-center gap-2">
                    <input
                        id="landing-show-stats"
                        type="checkbox"
                        class="checkbox"
                        bind:checked={landingShowStats}
                    />
                    <label for="landing-show-stats" class="text-sm text-surface-700 dark:text-slate-300">Show served stats on public landing</label>
                </div>
                <div>
                    <h3 class="text-sm font-medium text-surface-700 dark:text-slate-300 mb-2">Content sections</h3>
                    <ul class="space-y-2">
                        {#each landingSections as section, i (i)}
                            <li class="rounded-lg border border-surface-200 dark:border-slate-600 bg-surface-100 dark:bg-slate-800/50 p-3">
                                {#if editingSectionIndex === i}
                                    <div class="space-y-2">
                                        <input
                                            type="text"
                                            class="input w-full text-sm"
                                            placeholder="Section title"
                                            bind:value={landingSections[i].title}
                                        />
                                        <textarea
                                            class="input w-full text-sm min-h-[60px]"
                                            placeholder="Body (optional)"
                                            bind:value={landingSections[i].body}
                                        ></textarea>
                                        <button
                                            type="button"
                                            class="btn variant-outline text-sm"
                                            onclick={() => (editingSectionIndex = null)}
                                        >
                                            Done
                                        </button>
                                    </div>
                                {:else}
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium text-surface-900 dark:text-white">
                                            {section.title || "Untitled"}
                                        </span>
                                        <span class="text-surface-500 dark:text-slate-400 text-sm truncate max-w-[200px]">
                                            {section.body ? (section.body.slice(0, 60) + (section.body.length > 60 ? "…" : "")) : ""}
                                        </span>
                                        <div class="flex gap-1 ml-auto">
                                            <button
                                                type="button"
                                                class="btn variant-ghost size-sm p-1"
                                                title="Move up"
                                                disabled={i === 0}
                                                onclick={() => moveSectionUp(i)}
                                            >
                                                <ChevronUp class="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                class="btn variant-ghost size-sm p-1"
                                                title="Move down"
                                                disabled={i === landingSections.length - 1}
                                                onclick={() => moveSectionDown(i)}
                                            >
                                                <ChevronDown class="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                class="btn variant-ghost size-sm p-1"
                                                title="Edit"
                                                onclick={() => (editingSectionIndex = i)}
                                            >
                                                <Pencil class="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                class="btn variant-ghost size-sm p-1 text-red-600"
                                                title="Remove"
                                                onclick={() => removeSection(i)}
                                            >
                                                <Trash2 class="h-4 w-4" />
                                            </button>
                                        </div>
                                    </div>
                                {/if}
                            </li>
                        {/each}
                    </ul>
                    <button
                        type="button"
                        class="btn variant-outline text-sm mt-2 flex items-center gap-1"
                        onclick={addSection}
                    >
                        <Plus class="h-4 w-4" />
                        Add section
                    </button>
                </div>
                <button type="submit" class="btn preset-filled-primary-500" disabled={submitting}>
                    {submitting ? "Saving…" : "Save"}
                </button>
            </form>
        </section>

        <!-- API key (masked) + Regenerate -->
        <section
            class="rounded-container bg-surface-50 border border-surface-200 p-6"
            aria-labelledby="api-key-heading"
        >
            <h2 id="api-key-heading" class="text-lg font-semibold text-surface-950 flex items-center gap-2 mb-4">
                <Key class="w-5 h-5 text-primary-500" />
                API key
            </h2>
            <p class="text-surface-600 text-sm mb-3">
                The key is never shown again after create or regenerate. Store it in Pi <code class="bg-surface-200 dark:bg-surface-700 px-1 rounded text-xs">.env</code> as <code class="bg-surface-200 dark:bg-surface-700 px-1 rounded text-xs">CENTRAL_API_KEY</code>.
            </p>
            <div class="flex flex-wrap items-center gap-3">
                <code class="px-3 py-2 rounded-lg bg-surface-200 dark:bg-surface-700 font-mono text-sm">
                    {api_key_masked}
                </code>
                <button
                    type="button"
                    class="btn preset-tonal flex items-center gap-2 touch-target-h"
                    onclick={() => (showRegenerateConfirm = true)}
                    disabled={submitting}
                >
                    <RefreshCw class="w-4 h-4" />
                    Regenerate key
                </button>
            </div>
        </section>

        <!-- Edge settings form -->
        <section
            class="rounded-container bg-surface-50 border border-surface-200 p-6"
            aria-labelledby="edge-settings-heading"
        >
            <h2 id="edge-settings-heading" class="text-lg font-semibold text-surface-950 mb-4">
                Edge settings
            </h2>
            <form onsubmit={handleEdgeSubmit} class="space-y-6">
                <div class="grid gap-6 sm:grid-cols-2">
                    <label class="flex items-center gap-3 cursor-pointer touch-target-h min-h-[48px]">
                        <input
                            type="checkbox"
                            class="checkbox checkbox-primary"
                            bind:checked={edge.sync_clients}
                        />
                        <span>Sync clients</span>
                    </label>
                    <label class="flex items-center gap-3 cursor-pointer touch-target-h min-h-[48px]">
                        <input
                            type="checkbox"
                            class="checkbox checkbox-primary"
                            bind:checked={edge.sync_tokens}
                        />
                        <span>Sync tokens</span>
                    </label>
                    <label class="flex items-center gap-3 cursor-pointer touch-target-h min-h-[48px]">
                        <input
                            type="checkbox"
                            class="checkbox checkbox-primary"
                            bind:checked={edge.sync_tts}
                        />
                        <span>Sync TTS</span>
                    </label>
                    <label class="flex items-center gap-3 cursor-pointer touch-target-h min-h-[48px]">
                        <input
                            type="checkbox"
                            class="checkbox checkbox-primary"
                            bind:checked={edge.bridge_enabled}
                        />
                        <span>Bridge enabled</span>
                    </label>
                    <label class="flex items-center gap-3 cursor-pointer touch-target-h min-h-[48px]">
                        <input
                            type="checkbox"
                            class="checkbox checkbox-primary"
                            bind:checked={edge.offline_allow_client_creation}
                        />
                        <span>Offline allow client creation</span>
                    </label>
                </div>

                <div>
                    <label for="sync_client_scope" class="label-text block mb-1">
                        Sync client scope
                    </label>
                    <select
                        id="sync_client_scope"
                        class="select select-bordered w-full max-w-xs select-theme"
                        bind:value={edge.sync_client_scope}
                    >
                        <option value="program_history">program_history</option>
                        <option value="all">all</option>
                    </select>
                </div>

                <div>
                    <label for="offline_binding_mode" class="label-text block mb-1">
                        Offline binding mode override
                    </label>
                    <select
                        id="offline_binding_mode"
                        class="select select-bordered w-full max-w-xs select-theme"
                        bind:value={edge.offline_binding_mode_override}
                    >
                        <option value="optional">optional</option>
                        <option value="required">required</option>
                    </select>
                </div>

                <div>
                    <label for="scheduled_sync_time" class="label-text block mb-1">
                        Scheduled sync time (HH:MM 24h)
                    </label>
                    <input
                        id="scheduled_sync_time"
                        type="text"
                        class="input input-bordered w-full max-w-[8rem] font-mono"
                        placeholder="17:00"
                        bind:value={edge.scheduled_sync_time}
                        maxlength="5"
                    />
                    {#if edgeErrors["edge_settings.scheduled_sync_time"]}
                        <p class="text-sm text-error-600 mt-1">
                            {edgeErrors["edge_settings.scheduled_sync_time"]}
                        </p>
                    {/if}
                </div>

                <div class="flex gap-3 pt-2">
                    <button
                        type="submit"
                        class="btn preset-filled-primary-500 touch-target-h"
                        disabled={submitting}
                    >
                        <Save class="w-4 h-4" />
                        Save edge settings
                    </button>
                </div>
            </form>
        </section>
    </div>
</AdminLayout>

<ConfirmModal
    open={showRegenerateConfirm}
    title="Regenerate API key?"
    message="The current key will stop working immediately. Update the Pi .env with the new key. The new key will be shown only once."
    confirmLabel="Regenerate"
    onConfirm={handleRegenerate}
    onCancel={() => (showRegenerateConfirm = false)}
/>

<Modal
    open={showNewKeyModal}
    title="New API key — copy now"
    onClose={closeNewKeyModal}
>
    <p class="text-surface-600 mb-4">
        This key is shown only once. Copy it and update your Pi
        <code class="text-sm bg-surface-200 dark:bg-surface-700 px-1 rounded">.env</code>.
    </p>
    <div class="flex flex-wrap items-center gap-2 mb-4 p-3 rounded-lg bg-surface-100 dark:bg-surface-800 font-mono text-sm break-all">
        {newKeyOnce ?? ""}
    </div>
    <div class="flex flex-wrap gap-3">
        <button
            type="button"
            class="btn preset-tonal flex items-center gap-2 touch-target-h"
            onclick={copyNewKey}
        >
            <Copy class="w-4 h-4" />
            Copy key
        </button>
        <button
            type="button"
            class="btn preset-filled-primary-500 touch-target-h"
            onclick={closeNewKeyModal}
        >
            Done
        </button>
    </div>
</Modal>
