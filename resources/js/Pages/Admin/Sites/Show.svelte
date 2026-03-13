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
    import {
        Building2,
        Key,
        RefreshCw,
        Copy,
        ArrowLeft,
        Save,
        Users,
        ArrowRightLeft,
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

    interface UserInSite {
        id: number;
        name: string;
        email: string;
        role: string;
    }

    interface SiteOption {
        id: number;
        name: string;
        slug: string;
    }

    let {
        site,
        api_key_masked = "sk_live_...****",
        users_in_site = [],
        auth_is_super_admin = false,
        sites = [],
    }: {
        site: {
            id: number;
            name: string;
            slug: string;
            settings: Record<string, unknown>;
            edge_settings: Partial<EdgeSettings>;
            created_at: string | null;
            updated_at: string | null;
        };
        api_key_masked?: string;
        users_in_site?: UserInSite[];
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
    let moveUser = $state<UserInSite | null>(null);
    let moveTargetSiteId = $state<string | number>("");
    let showMoveModal = $state(false);

    const page = usePage();

    const otherSites = $derived((sites ?? []).filter((s) => s.id !== site.id));

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

    function openMoveModal(u: UserInSite): void {
        moveUser = u;
        moveTargetSiteId = otherSites[0]?.id ?? "";
        showMoveModal = true;
    }

    function closeMoveModal(): void {
        showMoveModal = false;
        moveUser = null;
        moveTargetSiteId = "";
    }

    async function handleMoveToSite(): Promise<void> {
        if (!moveUser || (moveTargetSiteId !== "" && moveTargetSiteId == null)) return;
        const targetId = moveTargetSiteId === "" ? null : Number(moveTargetSiteId);
        if (targetId == null) return;
        submitting = true;
        const result = await api("PUT", `/api/admin/users/${moveUser.id}`, {
            site_id: targetId,
        });
        submitting = false;
        if (result.ok) {
            toaster.success({ title: "User moved to site." });
            closeMoveModal();
            router.reload();
        } else {
            toaster.error({
                title: (result as { message?: string })?.message ?? "Failed to move user.",
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

        <!-- Users in this site -->
        <section
            class="rounded-container bg-surface-50 border border-surface-200 p-6"
            aria-labelledby="users-in-site-heading"
        >
            <h2 id="users-in-site-heading" class="text-lg font-semibold text-surface-950 flex items-center gap-2 mb-4">
                <Users class="w-5 h-5 text-primary-500" />
                Users in this site
            </h2>
            {#if users_in_site.length === 0}
                <p class="text-surface-600 text-sm">No users assigned to this site yet.</p>
            {:else}
                <div class="overflow-x-auto">
                    <table class="table table-zebra table-pin-rows w-full text-sm">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                {#if auth_is_super_admin && otherSites.length > 0}
                                    <th class="w-32 text-right">Actions</th>
                                {/if}
                            </tr>
                        </thead>
                        <tbody>
                            {#each users_in_site as u (u.id)}
                                <tr>
                                    <td class="font-medium text-surface-950">{u.name}</td>
                                    <td class="text-surface-700">{u.email}</td>
                                    <td>
                                        <span class="badge preset-tonal text-xs uppercase">{u.role}</span>
                                    </td>
                                    {#if auth_is_super_admin && otherSites.length > 0}
                                        <td class="text-right">
                                            <button
                                                type="button"
                                                class="btn btn-sm preset-outlined touch-target-h"
                                                onclick={() => openMoveModal(u)}
                                                disabled={submitting}
                                            >
                                                <ArrowRightLeft class="w-3.5 h-3.5" />
                                                Move to site
                                            </button>
                                        </td>
                                    {/if}
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>
            {/if}
        </section>
    </div>
</AdminLayout>

<Modal
    open={showMoveModal}
    title={moveUser ? `Move ${moveUser.name} to another site` : "Move to site"}
    onClose={closeMoveModal}
>
    {#if moveUser && otherSites.length > 0}
        <div class="space-y-4">
            <p class="text-surface-600 text-sm">
                Assign this user to a different site. They will then see only that site's programs and users.
            </p>
            <div class="form-control">
                <label class="label"><span class="label-text font-medium">Site</span></label>
                <select
                    class="select select-theme rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                    bind:value={moveTargetSiteId}
                >
                    {#each otherSites as s (s.id)}
                        <option value={s.id}>{s.name}</option>
                    {/each}
                </select>
            </div>
            <div class="flex justify-end gap-3 pt-2 border-t border-surface-100">
                <button type="button" class="btn preset-tonal" onclick={closeMoveModal}>Cancel</button>
                <button
                    type="button"
                    class="btn preset-filled-primary-500"
                    disabled={submitting}
                    onclick={handleMoveToSite}
                >
                    {submitting ? "Moving…" : "Move"}
                </button>
            </div>
        </div>
    {/if}
</Modal>

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
