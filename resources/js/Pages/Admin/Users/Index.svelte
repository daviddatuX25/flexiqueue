<script lang="ts">
    import AdminLayout from "../../../Layouts/AdminLayout.svelte";
    import Modal from "../../../Components/Modal.svelte";
    import ConfirmModal from "../../../Components/ConfirmModal.svelte";
    import { get } from "svelte/store";
    import { router, usePage } from "@inertiajs/svelte";
    import {
        Users as UsersIcon,
        UserPlus,
        Edit2,
        Key,
        Ban,
        CheckCircle2,
        XCircle,
    } from "lucide-svelte";
    import UserAvatar from "../../../Components/UserAvatar.svelte";

    interface UserItem {
        id: number;
        name: string;
        email: string;
        avatar_url?: string | null;
        role: string;
        is_active: boolean;
        availability_status?: string;
        assigned_station_id: number | null;
        assigned_station: { id: number; name: string } | null;
    }

    let { users = [] }: { users: UserItem[] } = $props();

    let error = $state("");
    let submitting = $state(false);
    let showCreateModal = $state(false);
    let showEditModal = $state(false);
    let showResetModal = $state(false);
    let deactivateConfirmUser = $state<UserItem | null>(null);
    let editUser = $state<UserItem | null>(null);
    let resetUser = $state<UserItem | null>(null);
    let createName = $state("");
    let createEmail = $state("");
    let createPassword = $state("");
    let createRole = $state<"admin" | "staff">("staff");
    let createOverridePin = $state("");
    let editName = $state("");
    let editEmail = $state("");
    let editRole = $state<"admin" | "staff">("staff");
    let editIsActive = $state(true);
    let editPassword = $state("");
    let editOverridePin = $state("");
    let resetPassword = $state("");

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

    async function api(
        method: string,
        url: string,
        body?: object,
    ): Promise<{
        ok: boolean;
        data?: { user?: UserItem } | object;
        message?: string;
    }> {
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
        const data = await res.json().catch(() => ({}));
        return {
            ok: res.ok,
            data,
            message: (data as { message?: string })?.message,
        };
    }

    function openCreate() {
        createName = "";
        createEmail = "";
        createPassword = "";
        createRole = "staff";
        createOverridePin = "";
        error = "";
        showCreateModal = true;
    }

    async function handleCreate() {
        if (
            !createName.trim() ||
            !createEmail.trim() ||
            !createPassword.trim()
        ) {
            error = "Name, email, and password are required.";
            return;
        }
        submitting = true;
        error = "";
        const body: {
            name: string;
            email: string;
            password: string;
            role: string;
            override_pin?: string;
        } = {
            name: createName.trim(),
            email: createEmail.trim(),
            password: createPassword,
            role: createRole,
        };
        if (createOverridePin.trim())
            body.override_pin = createOverridePin.trim();
        const { ok, message: msg } = await api(
            "POST",
            "/api/admin/users",
            body,
        );
        submitting = false;
        if (ok) {
            showCreateModal = false;
            router.reload();
        } else error = msg ?? "Failed to create user.";
    }

    function openEdit(u: UserItem) {
        editUser = u;
        editName = u.name;
        editEmail = u.email;
        editRole = u.role as "admin" | "staff";
        editIsActive = u.is_active;
        editPassword = "";
        editOverridePin = "";
        error = "";
        showEditModal = true;
    }

    async function handleEdit() {
        if (!editUser || !editName.trim() || !editEmail.trim()) return;
        submitting = true;
        error = "";
        const body: {
            name: string;
            email: string;
            role: string;
            is_active: boolean;
            password?: string;
            override_pin?: string | null;
        } = {
            name: editName.trim(),
            email: editEmail.trim(),
            role: editRole,
            is_active: editIsActive,
        };
        if (editPassword.trim()) body.password = editPassword.trim();
        if (editOverridePin.trim()) body.override_pin = editOverridePin.trim();
        else body.override_pin = null;
        const {
            ok,
            data,
            message: msg,
        } = await api("PUT", `/api/admin/users/${editUser.id}`, body);
        submitting = false;
        if (ok && data?.user) {
            showEditModal = false;
            editUser = null;
            router.reload();
        } else error = msg ?? "Failed to update user.";
    }

    function openReset(u: UserItem) {
        resetUser = u;
        resetPassword = "";
        error = "";
        showResetModal = true;
    }

    async function handleReset() {
        if (!resetUser || !resetPassword.trim() || resetPassword.length < 8) {
            error = "Password must be at least 8 characters.";
            return;
        }
        submitting = true;
        error = "";
        const { ok, message: msg } = await api(
            "POST",
            `/api/admin/users/${resetUser.id}/reset-password`,
            {
                password: resetPassword,
            },
        );
        submitting = false;
        if (ok) {
            showResetModal = false;
            resetUser = null;
            error = "";
        } else error = msg ?? "Failed to reset password.";
    }

    function openDeactivateConfirm(u: UserItem) {
        deactivateConfirmUser = u;
        error = "";
    }

    async function handleDeactivateConfirm() {
        if (!deactivateConfirmUser) return;
        const u = deactivateConfirmUser;
        submitting = true;
        error = "";
        const { ok, message: msg } = await api(
            "DELETE",
            `/api/admin/users/${u.id}`,
        );
        submitting = false;
        if (ok) {
            deactivateConfirmUser = null;
            router.reload();
        } else error = msg ?? "Failed to deactivate user.";
    }

    function closeDeactivateConfirm() {
        deactivateConfirmUser = null;
    }

    function closeModals() {
        showCreateModal = false;
        showEditModal = false;
        showResetModal = false;
        deactivateConfirmUser = null;
        editUser = null;
        resetUser = null;
        error = "";
    }
</script>

<svelte:head>
    <title>Staff — FlexiQueue</title>
</svelte:head>

<AdminLayout>
    <div
        class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4"
    >
        <div>
            <h1
                class="text-2xl font-bold text-surface-950 flex items-center gap-2"
            >
                <UsersIcon class="w-6 h-6 text-primary-500" />
                Staff
            </h1>
            <p class="mt-2 text-surface-600 max-w-3xl leading-relaxed">
                Manage staff accounts. Assign stations per program in Program →
                Staff tab.
            </p>
        </div>
        <button
            type="button"
            class="btn preset-filled-primary-500 flex items-center gap-2 shadow-sm"
            onclick={openCreate}
        >
            <UserPlus class="w-4 h-4" /> Add Staff
        </button>
    </div>

    {#if error}
        <div
            class="bg-error-100 text-error-900 border border-error-300 rounded-container p-4 mt-4"
        >
            {error}
        </div>
    {/if}

    <div class="table-container mt-6">
        <table class="table table-zebra relative w-full">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Availability</th>
                    <th>Assigned to</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                {#each users as user (user.id)}
                    <tr>
                        <td>
                            <div class="flex items-center gap-2">
                                <UserAvatar user={user} size="sm" />
                                <span class="font-medium text-surface-900">{user.name}</span>
                            </div>
                        </td>
                        <td class="text-surface-700">{user.email}</td>
                        <td>
                            <span
                                class="badge {user.role === 'admin'
                                    ? 'preset-filled-primary-500'
                                    : 'preset-tonal'} shadow-sm font-semibold uppercase tracking-wide text-[10px] px-2 py-0.5 rounded-full"
                                >{user.role}</span
                            >
                        </td>
                        <td>
                            {#if user.is_active}
                                <span
                                    class="badge preset-filled-success-500 shadow-sm font-semibold uppercase tracking-wide text-[10px] px-2 py-0.5 rounded-full flex items-center gap-1 w-max"
                                    ><CheckCircle2 class="w-3 h-3" /> Active</span
                                >
                            {:else}
                                <span
                                    class="badge preset-tonal shadow-sm font-semibold uppercase tracking-wide text-[10px] px-2 py-0.5 rounded-full flex items-center gap-1 w-max"
                                    ><XCircle class="w-3 h-3" /> Inactive</span
                                >
                            {/if}
                        </td>
                        <td>
                            {#if user.availability_status === "available"}
                                <span
                                    class="badge preset-filled-success-500 shadow-sm font-semibold uppercase tracking-wide text-[10px] px-2 py-0.5 rounded-full"
                                    >Available</span
                                >
                            {:else if user.availability_status === "on_break"}
                                <span
                                    class="badge preset-filled-warning-500 shadow-sm font-semibold uppercase tracking-wide text-[10px] px-2 py-0.5 rounded-full"
                                    >On break</span
                                >
                            {:else if user.availability_status === "away"}
                                <span
                                    class="badge preset-tonal shadow-sm font-semibold uppercase tracking-wide text-[10px] px-2 py-0.5 rounded-full"
                                    >Away</span
                                >
                            {:else}
                                <span
                                    class="badge preset-tonal text-surface-500 shadow-sm font-semibold uppercase tracking-wide text-[10px] px-2 py-0.5 rounded-full border border-surface-200"
                                    >Offline</span
                                >
                            {/if}
                        </td>
                        <td class="text-surface-700">
                            <span class="text-surface-950/70"
                                >{user.assigned_station?.name ?? "—"}</span
                            >
                        </td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-2">
                                {#if user.is_active}
                                    <button
                                        type="button"
                                        class="btn btn-sm preset-outlined bg-white text-surface-700 hover:bg-surface-50 flex items-center gap-1 shadow-sm px-3 py-1.5 transition-colors"
                                        onclick={() => openEdit(user)}
                                        disabled={submitting}
                                    >
                                        <Edit2 class="w-3.5 h-3.5" /> Edit
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-sm preset-outlined bg-white text-surface-700 hover:bg-surface-50 flex items-center gap-1 shadow-sm px-3 py-1.5 transition-colors"
                                        onclick={() => openReset(user)}
                                        disabled={submitting}
                                    >
                                        <Key class="w-3.5 h-3.5" /> PW
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-sm preset-outlined bg-white text-error-600 hover:bg-error-50 border-error-200 flex items-center gap-1 shadow-sm px-3 py-1.5 transition-colors"
                                        onclick={() =>
                                            openDeactivateConfirm(user)}
                                        disabled={submitting}
                                    >
                                        <Ban class="w-3.5 h-3.5" /> Disable
                                    </button>
                                {:else}
                                    <button
                                        type="button"
                                        class="btn btn-sm preset-outlined bg-white text-surface-700 hover:bg-surface-50 flex items-center gap-1 shadow-sm px-3 py-1.5 transition-colors"
                                        onclick={() => openEdit(user)}
                                        disabled={submitting}
                                    >
                                        <Edit2 class="w-3.5 h-3.5" /> Edit
                                    </button>
                                {/if}
                            </div>
                        </td>
                    </tr>
                {/each}
            </tbody>
        </table>
    </div>
</AdminLayout>

<Modal open={showCreateModal} title="Add staff" onClose={closeModals}>
    <div class="space-y-4">
        <div class="form-control">
            <label class="label"
                ><span class="label-text font-medium">Name</span></label
            >
            <input
                type="text"
                class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-white shadow-sm"
                bind:value={createName}
                placeholder="Juan Cruz"
            />
        </div>
        <div class="form-control">
            <label class="label"
                ><span class="label-text font-medium">Email</span></label
            >
            <input
                type="email"
                class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-white shadow-sm"
                bind:value={createEmail}
                placeholder="juan@example.com"
            />
        </div>
        <div class="form-control">
            <label class="label"
                ><span class="label-text font-medium">Password</span></label
            >
            <input
                type="password"
                class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-white shadow-sm"
                bind:value={createPassword}
                placeholder="Min 8 characters"
            />
        </div>
        <div class="form-control">
            <label class="label"
                ><span class="label-text font-medium">Role</span></label
            >
            <select
                class="select rounded-container border border-surface-200 px-3 py-2 w-full bg-white shadow-sm"
                bind:value={createRole}
            >
                <option value="staff">Staff</option>
                <option value="admin">Admin</option>
            </select>
        </div>
        <div class="form-control">
            <label class="label"
                ><span class="label-text font-medium"
                    >Override PIN (6 digits)</span
                ></label
            >
            <input
                type="text"
                class="input rounded-container border border-surface-200 px-3 py-2 w-full max-w-xs bg-white shadow-sm text-center"
                bind:value={createOverridePin}
                placeholder="e.g. 123456"
                maxlength="6"
                inputmode="numeric"
                pattern="[0-9]*"
            />
            <span class="label-text-alt mt-1"
                >Required when assigning as program supervisor. Set now so the
                user can be added as supervisor later.</span
            >
        </div>
        <div
            class="flex justify-end gap-3 mt-6 pt-2 border-t border-surface-100"
        >
            <button type="button" class="btn preset-tonal" onclick={closeModals}
                >Cancel</button
            >
            <button
                type="button"
                class="btn preset-filled-primary-500 shadow-sm"
                disabled={submitting}
                onclick={handleCreate}
            >
                {submitting ? "Creating…" : "Create"}
            </button>
        </div>
    </div>
</Modal>

<Modal
    open={showEditModal}
    title={editUser ? `Edit ${editUser.name}` : "Edit user"}
    onClose={closeModals}
>
    {#if editUser}
        <div class="space-y-4">
            <div class="form-control">
                <label class="label"
                    ><span class="label-text font-medium">Name</span></label
                >
                <input
                    type="text"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-white shadow-sm"
                    bind:value={editName}
                />
            </div>
            <div class="form-control">
                <label class="label"
                    ><span class="label-text font-medium">Email</span></label
                >
                <input
                    type="email"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-white shadow-sm"
                    bind:value={editEmail}
                />
            </div>
            <div class="form-control">
                <label class="label"
                    ><span class="label-text font-medium">Role</span></label
                >
                <select
                    class="select rounded-container border border-surface-200 px-3 py-2 w-full bg-white shadow-sm"
                    bind:value={editRole}
                >
                    <option value="staff">Staff</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div
                class="form-control mt-2 mb-2 p-3 border border-surface-100 rounded-container bg-surface-50/50"
            >
                <label class="label cursor-pointer justify-start gap-3 w-full">
                    <input
                        type="checkbox"
                        class="checkbox"
                        bind:checked={editIsActive}
                    />
                    <span class="label-text font-medium"
                        >Active (can log in)</span
                    >
                </label>
            </div>
            <div class="form-control">
                <label class="label"
                    ><span class="label-text font-medium"
                        >New password (optional)</span
                    ></label
                >
                <input
                    type="password"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-white shadow-sm"
                    bind:value={editPassword}
                    placeholder="Leave blank to keep current"
                />
            </div>
            <div class="form-control">
                <label class="label"
                    ><span class="label-text font-medium"
                        >Override PIN (6 digits)</span
                    ></label
                >
                <input
                    type="text"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full max-w-xs bg-white shadow-sm text-center"
                    bind:value={editOverridePin}
                    placeholder="Leave blank to keep or clear"
                    maxlength="6"
                    inputmode="numeric"
                    pattern="[0-9]*"
                />
                <span class="label-text-alt mt-1"
                    >Required for program supervisors. Set so the user can be
                    added as supervisor in Program → Staff.</span
                >
            </div>
            <div
                class="flex justify-end gap-3 mt-6 pt-2 border-t border-surface-100"
            >
                <button
                    type="button"
                    class="btn preset-tonal"
                    onclick={closeModals}>Cancel</button
                >
                <button
                    type="button"
                    class="btn preset-filled-primary-500 shadow-sm"
                    disabled={submitting}
                    onclick={handleEdit}
                >
                    {submitting ? "Saving…" : "Save"}
                </button>
            </div>
        </div>
    {/if}
</Modal>

<Modal
    open={showResetModal}
    title={resetUser
        ? `Reset password for ${resetUser.name}`
        : "Reset password"}
    onClose={closeModals}
>
    {#if resetUser}
        <div class="space-y-4">
            <p class="text-sm text-surface-950/70">
                Set a new password. The user will need to use this to log in.
            </p>
            <div class="form-control">
                <label class="label"
                    ><span class="label-text font-medium">New password</span
                    ></label
                >
                <input
                    type="password"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-white shadow-sm"
                    bind:value={resetPassword}
                    placeholder="Min 8 characters"
                />
            </div>
            <div
                class="flex justify-end gap-3 mt-6 pt-2 border-t border-surface-100"
            >
                <button
                    type="button"
                    class="btn preset-tonal"
                    onclick={closeModals}>Cancel</button
                >
                <button
                    type="button"
                    class="btn preset-filled-primary-500 shadow-sm"
                    disabled={submitting || resetPassword.length < 8}
                    onclick={handleReset}
                >
                    {submitting ? "Resetting…" : "Reset"}
                </button>
            </div>
        </div>
    {/if}
</Modal>

<ConfirmModal
    open={!!deactivateConfirmUser}
    title="Deactivate user?"
    message={deactivateConfirmUser
        ? `Deactivate ${deactivateConfirmUser.name}? They will no longer be able to log in.`
        : ""}
    confirmLabel="Deactivate"
    cancelLabel="Cancel"
    variant="danger"
    loading={submitting}
    onConfirm={handleDeactivateConfirm}
    onCancel={closeDeactivateConfirm}
/>
