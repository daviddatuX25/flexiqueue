<script lang="ts">
    /**
     * Per central-edge B.5: Create site — name, slug; on success show API key once.
     */
    import AdminLayout from "../../../Layouts/AdminLayout.svelte";
    import { Link, router } from "@inertiajs/svelte";
    import { get } from "svelte/store";
    import { usePage } from "@inertiajs/svelte";
    import Modal from "../../../Components/Modal.svelte";
    import { toaster } from "../../../lib/toaster.js";
    import { Building2, Copy, ArrowLeft } from "lucide-svelte";

    let name = $state("");
    let slug = $state("");
    let submitting = $state(false);
    let createdSiteId = $state<number | null>(null);
    let createdApiKey = $state<string | null>(null);
    let showKeyModal = $state(false);

    const page = usePage();

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

    type ApiData = { message?: string; errors?: Record<string, string[]>; site?: { id: number }; api_key?: string };

    async function api(
        method: string,
        url: string,
        body?: object,
    ): Promise<{ ok: boolean; data?: ApiData; message?: string }> {
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
            const data = (await res.json().catch(() => ({}))) as ApiData;
            const errMsg =
                data?.message ??
                (data?.errors ? Object.values(data.errors).flat().join(", ") : undefined);
            return {
                ok: res.ok,
                data,
                message: errMsg,
            };
        } catch {
            toaster.error({ title: "Network error. Please try again." });
            return { ok: false };
        }
    }

    function slugFromName(): void {
        if (!slug && name) {
            slug = name
                .toLowerCase()
                .replace(/\s+/g, "-")
                .replace(/[^a-z0-9-]/g, "");
        }
    }

    async function handleSubmit(e: SubmitEvent): Promise<void> {
        e.preventDefault();
        const n = name.trim();
        const s = slug.trim().toLowerCase().replace(/[^a-z0-9-]/g, "");
        if (!n || !s) return;
        submitting = true;
        const { ok, data } = await api("POST", "/api/admin/sites", {
            name: n,
            slug: s,
        });
        submitting = false;
        if (ok && data) {
            const created = data.site;
            const key = data.api_key;
            if (created?.id && typeof key === "string") {
                createdSiteId = created.id;
                createdApiKey = key;
                showKeyModal = true;
            } else {
                toaster.success({ title: "Site created." });
                router.visit("/admin/sites");
            }
        } else {
            toaster.error({
                title: data?.message ?? "Failed to create site.",
            });
            if (data?.errors?.slug) {
                toaster.error({
                    title: "Slug already in use. Choose another.",
                });
            }
        }
    }

    function copyKey(): void {
        if (!createdApiKey) return;
        navigator.clipboard
            .writeText(createdApiKey)
            .then(() => toaster.success({ title: "API key copied." }))
            .catch(() => toaster.error({ title: "Could not copy." }));
    }

    function closeKeyModal(): void {
        showKeyModal = false;
        createdApiKey = null;
        createdSiteId = null;
        router.visit("/admin/sites");
    }
</script>

<svelte:head>
    <title>Add site — FlexiQueue</title>
</svelte:head>

<AdminLayout>
    <div class="flex flex-col gap-6 max-w-2xl">
        <div class="flex items-center gap-4">
            <Link
                href="/admin/sites"
                class="btn btn-ghost btn-square btn-sm"
                aria-label="Back to sites"
            >
                <ArrowLeft class="w-5 h-5" />
            </Link>
            <div>
                <h1 class="text-2xl font-bold text-surface-950 flex items-center gap-2">
                    <Building2 class="w-6 h-6 text-primary-500" />
                    Add site
                </h1>
                <p class="mt-1 text-surface-600">
                    Create a site and get an API key for edge/sync. The key is shown only once.
                </p>
            </div>
        </div>

        <form
            onsubmit={handleSubmit}
            class="rounded-container bg-surface-50 border border-surface-200 p-6 space-y-5"
        >
            <div>
                <label for="site-name" class="label-text block mb-1">
                    Name
                </label>
                <input
                    id="site-name"
                    type="text"
                    class="input input-bordered w-full"
                    placeholder="e.g. Dagupan CSWDO"
                    bind:value={name}
                    onblur={slugFromName}
                    required
                    maxlength="255"
                />
            </div>
            <div>
                <label for="site-slug" class="label-text block mb-1">
                    Slug
                </label>
                <input
                    id="site-slug"
                    type="text"
                    class="input input-bordered w-full font-mono text-sm"
                    placeholder="e.g. mswdo-dagupan"
                    bind:value={slug}
                    required
                    maxlength="100"
                    pattern="[a-z0-9\-]+"
                    title="Lowercase letters, numbers, and hyphens only"
                />
                <p class="text-xs text-surface-500 mt-1">
                    Used as SITE_ID in Pi .env. Lowercase, numbers, hyphens only.
                </p>
            </div>
            <div class="flex gap-3 pt-2">
                <button
                    type="submit"
                    class="btn preset-filled-primary-500 touch-target-h"
                    disabled={submitting || !name.trim() || !slug.trim()}
                >
                    {submitting ? "Creating…" : "Create site"}
                </button>
                <Link href="/admin/sites" class="btn preset-tonal touch-target-h">
                    Cancel
                </Link>
            </div>
        </form>
    </div>
</AdminLayout>

<Modal
    open={showKeyModal}
    title="API key — copy now"
    onClose={closeKeyModal}
>
    <p class="text-surface-600 mb-4">
        This key is shown only once. Copy it and store it in your Pi <code class="text-sm bg-surface-200 dark:bg-surface-700 px-1 rounded">.env</code> as <code class="text-sm bg-surface-200 dark:bg-surface-700 px-1 rounded">CENTRAL_API_KEY</code>.
    </p>
    <div class="flex flex-wrap items-center gap-2 mb-4 p-3 rounded-lg bg-surface-100 dark:bg-surface-800 font-mono text-sm break-all">
        {createdApiKey ?? ""}
    </div>
    <div class="flex flex-wrap gap-3">
        <button
            type="button"
            class="btn preset-tonal flex items-center gap-2 touch-target-h"
            onclick={copyKey}
        >
            <Copy class="w-4 h-4" />
            Copy key
        </button>
        {#if createdSiteId}
            <Link
                href="/admin/sites/{createdSiteId}"
                class="btn preset-filled-primary-500 touch-target-h"
                onclick={() => {
                    showKeyModal = false;
                    createdApiKey = null;
                    createdSiteId = null;
                }}
            >
                Go to site
            </Link>
        {/if}
        <button
            type="button"
            class="btn btn-ghost touch-target-h"
            onclick={closeKeyModal}
        >
            Done
        </button>
    </div>
</Modal>
