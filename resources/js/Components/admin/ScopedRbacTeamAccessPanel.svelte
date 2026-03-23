<script lang="ts">
    /**
     * Phase 6E: site/program scoped direct permissions (Spatie team_id = RbacTeam).
     * Loads catalog + site users, GET/PUT scoped grants per user.
     */
    import { onMount } from "svelte";
    import { get } from "svelte/store";
    import { Link, usePage } from "@inertiajs/svelte";
    import UserDirectPermissionsEditor from "./UserDirectPermissionsEditor.svelte";
    import { toaster } from "../../lib/toaster.js";

    let {
        rbacTeam,
    }: {
        rbacTeam: {
            id: number;
            type: string;
            site_id: number;
            scope_label: string;
        };
    } = $props();

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

    const isSuperAdmin = $derived(
        Boolean(
            (get(page)?.props as { auth?: { is_super_admin?: boolean } } | undefined)
                ?.auth?.is_super_admin,
        ),
    );

    let catalog = $state<string[]>([]);
    let users = $state<{ id: number; name: string; email: string }[]>([]);
    let loadError = $state<string | null>(null);
    let listLoading = $state(true);

    let selectedUserId = $state<number | null>(null);
    let selectedPerms = $state<string[]>([]);
    let initialScopedPerms = $state<string[]>([]);
    let userLoadPending = $state(false);
    let saving = $state(false);
    let saveErrors = $state<Record<string, string[] | string | undefined>>({});

    const scopeTitle =
        rbacTeam.type === "program" ? "Program" : "Site";

    const scopedDescription = $derived(
        rbacTeam.type === "program"
            ? `Extra access for this program only (“${rbacTeam.scope_label}”). Their normal role still applies everywhere else.`
            : `Extra access for this site only (“${rbacTeam.scope_label}”). Their normal role still applies everywhere else.`,
    );

    const isDirty = $derived(
        JSON.stringify([...selectedPerms].sort()) !==
            JSON.stringify([...initialScopedPerms].sort()),
    );

    async function loadLists(): Promise<void> {
        listLoading = true;
        loadError = null;
        try {
            const [permRes, userRes] = await Promise.all([
                fetch("/api/admin/permissions", {
                    headers: { Accept: "application/json" },
                    credentials: "same-origin",
                }),
                fetch(
                    `/api/admin/users?site_id=${encodeURIComponent(String(rbacTeam.site_id))}`,
                    {
                        headers: { Accept: "application/json" },
                        credentials: "same-origin",
                    },
                ),
            ]);
            if (!permRes.ok) {
                loadError = "Could not load permission catalog.";
                return;
            }
            if (!userRes.ok) {
                loadError = "Could not load users for this site.";
                return;
            }
            const permData = (await permRes.json()) as { permissions?: string[] };
            const userData = (await userRes.json()) as {
                users?: { id: number; name: string; email: string }[];
            };
            catalog = permData.permissions ?? [];
            users = (userData.users ?? []).map((u) => ({
                id: u.id,
                name: u.name,
                email: u.email,
            }));
        } catch {
            loadError = "Network error loading access panel.";
        } finally {
            listLoading = false;
        }
    }

    async function loadScopedForUser(uid: number): Promise<void> {
        userLoadPending = true;
        saveErrors = {};
        try {
            const res = await fetch(
                `/api/admin/rbac-teams/${rbacTeam.id}/users/${uid}`,
                {
                    headers: { Accept: "application/json" },
                    credentials: "same-origin",
                },
            );
            if (res.status === 403 || res.status === 404) {
                selectedPerms = [];
                initialScopedPerms = [];
                toaster.error(
                    res.status === 404
                        ? "User not in this site."
                        : "You cannot view permissions for this user.",
                );
                return;
            }
            if (!res.ok) {
                toaster.error("Could not load scoped permissions.");
                return;
            }
            const data = (await res.json()) as {
                direct_permissions?: string[];
            };
            const next = [...(data.direct_permissions ?? [])].sort((a, b) =>
                a.localeCompare(b),
            );
            selectedPerms = next;
            initialScopedPerms = [...next];
        } catch {
            toaster.error("Network error loading scoped permissions.");
        } finally {
            userLoadPending = false;
        }
    }

    function onUserChange(e: Event): void {
        const v = (e.target as HTMLSelectElement).value;
        if (!v) {
            selectedUserId = null;
            selectedPerms = [];
            initialScopedPerms = [];
            return;
        }
        const uid = Number(v);
        selectedUserId = uid;
        void loadScopedForUser(uid);
    }

    async function save(): Promise<void> {
        if (selectedUserId == null || saving || !isDirty) return;
        saving = true;
        saveErrors = {};
        try {
            const res = await fetch(
                `/api/admin/rbac-teams/${rbacTeam.id}/users/${selectedUserId}`,
                {
                    method: "PUT",
                    headers: {
                        Accept: "application/json",
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": getCsrfToken(),
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    credentials: "same-origin",
                    body: JSON.stringify({
                        direct_permissions: selectedPerms,
                    }),
                },
            );
            const data = (await res.json().catch(() => ({}))) as {
                message?: string;
                errors?: Record<string, string[]>;
                direct_permissions?: string[];
            };
            if (res.status === 422 && data.errors) {
                saveErrors = data.errors;
                return;
            }
            if (!res.ok) {
                toaster.error(data.message ?? "Could not save scoped permissions.");
                return;
            }
            const next = [...(data.direct_permissions ?? selectedPerms)].sort(
                (a, b) => a.localeCompare(b),
            );
            selectedPerms = next;
            initialScopedPerms = [...next];
            toaster.success("Saved.");
        } catch {
            toaster.error("Network error saving permissions.");
        } finally {
            saving = false;
        }
    }

    onMount(() => {
        void loadLists();
    });
</script>

<section
    class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-6"
    aria-labelledby="scoped-rbac-heading"
>
    <h2
        id="scoped-rbac-heading"
        class="text-base font-semibold text-surface-950 mb-1"
    >
        Extra access — {rbacTeam.scope_label}
    </h2>
    <p class="text-xs text-surface-500 mb-4">
        {scopeTitle}-only; choose a user, then tick the extras they need here.
    </p>

    {#if listLoading}
        <p class="text-sm text-surface-600">Loading…</p>
    {:else if loadError}
        <p class="text-sm text-error-700" role="alert">{loadError}</p>
    {:else}
        <div class="flex flex-wrap items-end gap-3 mb-4">
            <div class="form-control min-w-[min(100%,20rem)] flex-1">
                <label class="label pb-1" for="scoped-rbac-user"
                    ><span class="label-text font-medium text-surface-950"
                        >User</span
                    ></label
                >
                <select
                    id="scoped-rbac-user"
                    class="select select-bordered w-full select-theme"
                    value={selectedUserId != null ? String(selectedUserId) : ""}
                    onchange={onUserChange}
                >
                    <option value="">Select a user…</option>
                    {#each users as u (u.id)}
                        <option value={u.id}>{u.name} ({u.email})</option>
                    {/each}
                </select>
            </div>
            <Link
                href="/admin/users"
                class="btn preset-tonal btn-sm touch-target-h mb-0.5"
                >Staff → Users</Link
            >
        </div>

        {#if selectedUserId != null}
            {#if userLoadPending}
                <p class="text-sm text-surface-600 mb-3">Loading user…</p>
            {:else}
                <UserDirectPermissionsEditor
                    bind:selected={selectedPerms}
                    assignablePermissions={catalog}
                    effectivePermissions={[]}
                    supervisorProgramCount={0}
                    canAssignPlatformManage={isSuperAdmin}
                    disabled={saving}
                    errors={saveErrors}
                    description={scopedDescription}
                    showEffectiveReadout={false}
                />
                <div class="mt-4 flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="btn preset-filled-primary touch-target-h"
                        onclick={() => void save()}
                        disabled={saving || !isDirty}
                    >
                        {saving ? "Saving…" : "Save"}
                    </button>
                </div>
            {/if}
        {/if}
    {/if}
</section>
