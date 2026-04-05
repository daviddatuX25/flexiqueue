<script lang="ts">
    import AdminLayout from "../../../Layouts/AdminLayout.svelte";
    import Modal from "../../../Components/Modal.svelte";
    import AdminTable from "../../../Components/AdminTable.svelte";
    import ConfirmModal from "../../../Components/ConfirmModal.svelte";
    import { get } from "svelte/store";
    import { router, usePage } from "@inertiajs/svelte";
    import { toaster } from "../../../lib/toaster.js";
    import {
        Users as UsersIcon,
        UserPlus,
        Edit2,
        Ban,
        CheckCircle,
        CheckCircle2,
        XCircle,
        Clock,
    } from "lucide-svelte";
    import UserAvatar from "../../../Components/UserAvatar.svelte";
    import PasswordInput from "../../../Components/PasswordInput.svelte";
    import UserDirectPermissionsEditor from "../../../Components/admin/UserDirectPermissionsEditor.svelte";

    interface SiteOption {
        id: number;
        name: string;
        slug: string;
    }

    interface UserItem {
        id: number;
        name: string;
        username: string;
        email: string;
        recovery_gmail?: string | null;
        avatar_url?: string | null;
        role: string;
        is_active: boolean;
        availability_status?: string;
        assigned_station_id: number | null;
        assigned_station: { id: number; name: string } | null;
        site?: SiteOption | null;
        pending_assignment?: boolean;
    }

    let {
        users = [],
        sites = [],
        auth_is_super_admin = false,
        auth_user_id,
        allowed_roles_for_create = ["staff"],
        allowed_roles_for_edit = ["staff"],
        assignable_permissions = [],
    }: {
        users: UserItem[];
        sites?: SiteOption[];
        auth_is_super_admin?: boolean;
        auth_user_id?: number;
        allowed_roles_for_create?: ("admin" | "staff")[];
        allowed_roles_for_edit?: ("admin" | "staff")[];
        /** Assignable direct permission names (same as GET /api/admin/permissions). */
        assignable_permissions?: string[];
    } = $props();

    let submitting = $state(false);
    let showCreateModal = $state(false);
    let showEditModal = $state(false);
    let deactivateConfirmUser = $state<UserItem | null>(null);
    let editUser = $state<UserItem | null>(null);
    let createName = $state("");
    let createUsername = $state("");
    let createEmail = $state("");
    let createRecoveryGmail = $state("");
    let createPassword = $state("");
    let createPasswordConfirm = $state("");
    let createRole = $state<"admin" | "staff">("staff");
    let createPendingAssignment = $state(false);
    let createOverridePin = $state("");
    let createSiteId = $state<string | number>("");
    let editName = $state("");
    let editUsername = $state("");
    let editEmail = $state("");
    let editRecoveryGmail = $state("");
    let editRole = $state<"admin" | "staff">("staff");
    let editIsActive = $state(true);
    let editPassword = $state("");
    let editTempPassword = $state("");
    let editTempPasswordConfirm = $state("");
    let editOverridePin = $state("");
    let editPendingAssignment = $state(false);
    let editSiteId = $state<string | number>("");
    let editDirectPermissions = $state<string[]>([]);
    let editInitialDirectPermissions = $state<string[]>([]);
    let editEffectivePermissions = $state<string[]>([]);
    let editSupervisorProgramCount = $state(0);
    let editValidationErrors = $state<
        Record<string, string[] | string | undefined>
    >({});

    const page = usePage();
    const edgeMode = $derived(
        ($page?.props as { edge_mode?: { is_edge?: boolean; admin_read_only?: boolean } } | undefined)
            ?.edge_mode ?? null
    );
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

    const MSG_SESSION_EXPIRED = "Session expired. Please refresh and try again.";
    const MSG_NETWORK_ERROR = "Network error. Please try again.";

    async function api(
        method: string,
        url: string,
        body?: object,
    ): Promise<{
        ok: boolean;
        data?: { user?: UserItem } | object;
        message?: string;
        errors?: Record<string, string[]>;
        status?: number;
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
                toaster.error({ title: MSG_SESSION_EXPIRED });
                return { ok: false, message: MSG_SESSION_EXPIRED };
            }
            const data = (await res.json().catch(() => ({}))) as {
                message?: string;
                errors?: Record<string, string[]>;
            };
            return {
                ok: res.ok,
                data,
                message: data?.message,
                errors: data?.errors,
                status: res.status,
            };
        } catch (e) {
            toaster.error({ title: MSG_NETWORK_ERROR });
            return { ok: false, message: MSG_NETWORK_ERROR };
        }
    }

    /** Keep override PIN to digits only, max 6 (avoids browser "Please match the requested format"). */
    function sanitizeOverridePin(value: string): string {
        return value.replace(/\D/g, "").slice(0, 6);
    }

    function openCreate() {
        createName = "";
        createUsername = "";
        createEmail = "";
        createRecoveryGmail = "";
        createPassword = "";
        createPasswordConfirm = "";
        createRole = (allowed_roles_for_create?.[0] ?? "staff") as "admin" | "staff";
        createPendingAssignment = false;
        createOverridePin = "";
        createSiteId = sites?.length ? sites[0].id : "";
        showCreateModal = true;
    }

    async function handleCreate() {
        if (
            !createName.trim() ||
            !createUsername.trim() ||
            !createEmail.trim() ||
            !createRecoveryGmail.trim() ||
            !createPassword.trim() ||
            !createPasswordConfirm.trim()
        ) {
            toaster.error({
                title: "Name, username, email, recovery Gmail, password, and confirm password are required.",
            });
            return;
        }
        if (createPassword !== createPasswordConfirm) {
            toaster.error({ title: "Password and confirm password must match." });
            return;
        }
        if (createPassword.length < 8) {
            toaster.error({ title: "Password must be at least 8 characters." });
            return;
        }
        submitting = true;
        const body: {
            name: string;
            username: string;
            email: string;
            recovery_gmail: string;
            password: string;
            password_confirmation: string;
            role: string;
            override_pin?: string;
            site_id?: number | null;
            pending_assignment?: boolean;
        } = {
            name: createName.trim(),
            username: createUsername.trim(),
            email: createEmail.trim(),
            recovery_gmail: createRecoveryGmail.trim(),
            password: createPassword,
            password_confirmation: createPasswordConfirm,
            role: createRole,
        };
        if (createOverridePin.trim())
            body.override_pin = createOverridePin.trim();
        if (auth_is_super_admin && sites?.length)
            body.site_id = (createSiteId === "" || createSiteId == null) ? null : Number(createSiteId);
        if (createRole === "staff" && createPendingAssignment) {
            body.pending_assignment = true;
        }
        const { ok, message: msg } = await api(
            "POST",
            "/api/admin/users",
            body,
        );
        submitting = false;
        if (ok) {
            toaster.success({ title: "User created." });
            showCreateModal = false;
            router.reload();
        } else toaster.error({ title: msg ?? "Failed to create user." });
    }

    function openEdit(u: UserItem) {
        editUser = u;
        editName = u.name;
        editUsername = u.username ?? "";
        editEmail = u.email;
        editRecoveryGmail = u.recovery_gmail ?? "";
        const allowed = allowed_roles_for_edit ?? ["staff"];
        editRole = (allowed.includes(u.role as "admin" | "staff") ? u.role : allowed[0]) as "admin" | "staff";
        editIsActive = u.is_active;
        editPassword = "";
        editTempPassword = "";
        editTempPasswordConfirm = "";
        editOverridePin = "";
        editSiteId = u.site?.id ?? "";
        editDirectPermissions = [...(u.direct_permissions ?? [])];
        editInitialDirectPermissions = [...(u.direct_permissions ?? [])];
        editEffectivePermissions = [...(u.effective_permissions ?? [])];
        editSupervisorProgramCount = u.supervisor_program_count ?? 0;
        editPendingAssignment = u.pending_assignment ?? false;
        editValidationErrors = {};
        showEditModal = true;
    }

    async function handleEdit() {
        if (!editUser || !editName.trim() || !editUsername.trim() || !editEmail.trim()) return;
        submitting = true;
        editValidationErrors = {};
        const body: {
            name: string;
            username: string;
            email: string;
            recovery_gmail?: string | null;
            role?: string;
            is_active?: boolean;
            password?: string;
            override_pin?: string | null;
            site_id?: number | null;
            direct_permissions?: string[];
            pending_assignment?: boolean;
        } = {
            name: editName.trim(),
            username: editUsername.trim(),
            email: editEmail.trim(),
            recovery_gmail: editRecoveryGmail.trim() || null,
        };
        // API rejects role / is_active on self-update — omit so profile save works.
        if (editUser.id !== auth_user_id) {
            body.role = editRole;
            body.is_active = editIsActive;
        }
        if (editUser.id === auth_user_id && editPassword.trim()) {
            body.password = editPassword.trim();
        }
        if (editOverridePin.trim()) body.override_pin = editOverridePin.trim();
        else body.override_pin = null;
        if (auth_is_super_admin)
            body.site_id = (editSiteId === "" || editSiteId == null) ? null : Number(editSiteId);
        body.direct_permissions = [...editDirectPermissions].sort((a, b) =>
            a.localeCompare(b),
        );
        if (editUser.id !== auth_user_id && editRole === "staff") {
            body.pending_assignment = editPendingAssignment;
        }
        const {
            ok,
            data,
            message: msg,
            errors,
        } = await api("PUT", `/api/admin/users/${editUser.id}`, body);
        submitting = false;
        if (ok && (data as { user?: UserItem })?.user) {
            toaster.success({ title: "User updated." });
            showEditModal = false;
            editUser = null;
            router.reload();
            return;
        }
        if (errors) {
            editValidationErrors = errors;
            const flat = Object.values(errors).flat();
            const first = flat[0];
            if (first) toaster.error({ title: String(first) });
        } else {
            toaster.error({ title: msg ?? "Failed to update user." });
        }
    }

    /** PWD-5: fail-safe temporary password for another user (POST reset-password), not self. */
    async function handleSetTemporaryPassword() {
        if (!editUser || editUser.id === auth_user_id) return;
        if (!editTempPassword.trim() || !editTempPasswordConfirm.trim()) {
            toaster.error({
                title: "Enter and confirm the temporary password.",
            });
            return;
        }
        if (editTempPassword !== editTempPasswordConfirm) {
            toaster.error({ title: "Password and confirm password must match." });
            return;
        }
        if (editTempPassword.length < 8) {
            toaster.error({ title: "Password must be at least 8 characters." });
            return;
        }
        submitting = true;
        const { ok, message: msg, errors } = await api(
            "POST",
            `/api/admin/users/${editUser.id}/reset-password`,
            {
                password: editTempPassword.trim(),
                password_confirmation: editTempPasswordConfirm.trim(),
            },
        );
        submitting = false;
        if (ok) {
            toaster.success({ title: "Temporary password set." });
            editTempPassword = "";
            editTempPasswordConfirm = "";
            return;
        }
        if (errors) {
            const flat = Object.values(errors).flat();
            const first = flat[0];
            if (first) toaster.error({ title: String(first) });
        } else {
            toaster.error({ title: msg ?? "Failed to set temporary password." });
        }
    }

    function openDeactivateConfirm(u: UserItem) {
        deactivateConfirmUser = u;
    }

    async function handleDeactivateConfirm() {
        if (!deactivateConfirmUser) return;
        const u = deactivateConfirmUser;
        submitting = true;
        const { ok, message: msg } = await api(
            "DELETE",
            `/api/admin/users/${u.id}`,
        );
        submitting = false;
        if (ok) {
            toaster.success({ title: "User deactivated." });
            deactivateConfirmUser = null;
            router.reload();
        } else toaster.error({ title: msg ?? "Failed to deactivate user." });
    }

    function closeDeactivateConfirm() {
        deactivateConfirmUser = null;
    }

    function closeModals() {
        showCreateModal = false;
        showEditModal = false;
        deactivateConfirmUser = null;
        editUser = null;
        editValidationErrors = {};
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
        <div
            class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full sm:w-auto mt-2 sm:mt-0"
        >
            <button
                type="button"
                class="btn preset-filled-primary-500 flex justify-center items-center gap-2 w-full sm:w-auto shadow-sm transition-transform active:scale-95 md:flex hidden"
                onclick={openCreate}
                aria-label="Add Staff"
                disabled={!!edgeMode?.admin_read_only}
                title={edgeMode?.admin_read_only ? 'Changes must be made on the central server and re-synced.' : undefined}
            >
                <UserPlus class="w-4 h-4" /> Add Staff
            </button>
        </div>
        <!-- Mobile FAB: circular icon-only bottom-right, above footer (per Phase 3 Configuration) -->
            <button
                type="button"
                class="fixed bottom-[87px] right-[23px] z-50 flex md:hidden items-center justify-center w-14 h-14 rounded-full bg-primary-500 text-primary-contrast-500 shadow-lg hover:bg-primary-600 active:scale-95 transition-transform touch-manipulation"
                onclick={openCreate}
                aria-label="Add Staff"
                disabled={!!edgeMode?.admin_read_only}
                title={edgeMode?.admin_read_only ? 'Changes must be made on the central server and re-synced.' : undefined}
            >
                <UserPlus class="w-6 h-6" aria-hidden="true" />
            </button>
    </div>

    {#if users.length === 0}
        <div
            role="status"
            aria-label="No staff yet"
            class="rounded-container bg-surface-50 border border-surface-200 p-12 flex flex-col items-center justify-center text-center shadow-sm mt-6"
        >
            <div
                class="bg-surface-100 p-4 rounded-full text-surface-400 mb-4"
            >
                <UsersIcon class="w-8 h-8" />
            </div>
            <h3 class="text-lg font-semibold text-surface-950">
                No staff yet
            </h3>
            <p class="text-surface-600 max-w-sm mt-2 mb-6">
                Add staff accounts to manage stations and serve clients.
            </p>
            <button
                type="button"
                class="btn preset-filled-primary-500 flex items-center gap-2 touch-target-h"
                onclick={openCreate}
                disabled={!!edgeMode?.admin_read_only}
                title={edgeMode?.admin_read_only ? 'Changes must be made on the central server and re-synced.' : undefined}
            >
                <UserPlus class="w-4 h-4" /> Add Staff
            </button>
        </div>
    {:else}
    <!-- Desktop Table View -->
    <AdminTable class="mt-6 hidden md:block" compact={true}>
        {#snippet head()}
            <tr>
                <th>Name</th>
                <th>Username</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Availability</th>
                <th>Assigned to</th>
                <th class="text-center">Actions</th>
            </tr>
        {/snippet}
        {#snippet body()}
            {#each users as user (user.id)}
                <tr>
                    <td>
                        <div class="flex items-center gap-2">
                            <UserAvatar {user} size="sm" />
                            <span class="font-medium text-surface-900"
                                >{user.name}</span
                            >
                        </div>
                    </td>
                    <td class="text-surface-700 font-mono text-sm">{user.username}</td>
                    <td class="text-surface-700">{user.email}</td>
                    <td>
                        <div class="flex flex-wrap items-center gap-1">
                            <span
                                class="badge {user.role === 'admin'
                                    ? 'preset-filled-primary-500'
                                    : 'preset-tonal'} shadow-sm font-semibold uppercase tracking-wide text-[11px] px-2.5 py-1 rounded-full"
                                >{user.role}</span
                            >
                            {#if user.role === "staff" && user.pending_assignment}
                                <span
                                    class="badge preset-filled-warning-500 shadow-sm font-semibold uppercase tracking-wide text-[11px] px-2.5 py-1 rounded-full"
                                    title="Pending assignment / onboarding hold"
                                    >Pending</span
                                >
                            {/if}
                        </div>
                    </td>
                    <td>
                        {#if user.is_active}
                            <span
                                class="badge preset-filled-success-500 shadow-sm font-semibold uppercase tracking-wide text-[11px] px-2.5 py-1 rounded-full flex items-center gap-1.5 w-max"
                                ><CheckCircle2 class="w-3.5 h-3.5" /> Active</span
                            >
                        {:else}
                            <span
                                class="badge preset-tonal shadow-sm font-semibold uppercase tracking-wide text-[11px] px-2.5 py-1 rounded-full flex items-center gap-1.5 w-max"
                                ><XCircle class="w-3.5 h-3.5" /> Inactive</span
                            >
                        {/if}
                    </td>
                    <td>
                        {#if user.availability_status === "available"}
                            <span
                                class="badge preset-filled-success-500 shadow-sm font-semibold uppercase tracking-wide text-[11px] px-2.5 py-1 rounded-full"
                                >Available</span
                            >
                        {:else if user.availability_status === "on_break"}
                            <span
                                class="badge preset-filled-warning-500 shadow-sm font-semibold uppercase tracking-wide text-[11px] px-2.5 py-1 rounded-full"
                                >On break</span
                            >
                        {:else if user.availability_status === "away"}
                            <span
                                class="badge preset-tonal shadow-sm font-semibold uppercase tracking-wide text-[11px] px-2.5 py-1 rounded-full"
                                >Away</span
                            >
                        {:else}
                            <span
                                class="badge preset-tonal text-surface-500 shadow-sm font-semibold uppercase tracking-wide text-[11px] px-2.5 py-1 rounded-full border border-surface-200"
                                >Offline</span
                            >
                        {/if}
                    </td>
                    <td class="text-surface-700">
                        {#if user.assigned_station?.name}
                            <span class="text-surface-950/70">{user.assigned_station.name}</span>
                        {:else}
                            <span class="text-xs text-surface-950/50">Not assigned</span>
                        {/if}
                    </td>
                    <td class="text-left">
                        <div class="flex items-center justify-start gap-2">
                            {#if user.is_active}
                                <button
                                    type="button"
                                    class="btn btn-sm preset-outlined bg-surface-50 text-surface-700 hover:bg-surface-50 flex items-center gap-1 shadow-sm px-3 py-1.5 transition-colors"
                                    onclick={() => openEdit(user)}
                                    disabled={submitting || !!edgeMode?.admin_read_only}
                                    title={edgeMode?.admin_read_only ? 'Changes must be made on the central server and re-synced.' : undefined}
                                >
                                    <Edit2 class="w-3.5 h-3.5" /> Edit
                                </button>
                                {#if user.id !== auth_user_id}
                                    <button
                                        type="button"
                                        class="btn btn-sm preset-outlined bg-surface-50 text-error-600 hover:bg-error-50 border-error-200 flex items-center gap-1 shadow-sm px-3 py-1.5 transition-colors"
                                        onclick={() =>
                                            openDeactivateConfirm(user)}
                                        disabled={submitting || !!edgeMode?.admin_read_only}
                                        title={edgeMode?.admin_read_only ? 'Changes must be made on the central server and re-synced.' : undefined}
                                    >
                                        <Ban class="w-3.5 h-3.5" /> Disable
                                    </button>
                                {/if}
                            {:else}
                                <button
                                    type="button"
                                    class="btn btn-sm preset-outlined bg-surface-50 text-surface-700 hover:bg-surface-50 flex items-center gap-1 shadow-sm px-3 py-1.5 transition-colors"
                                    onclick={() => openEdit(user)}
                                    disabled={submitting || !!edgeMode?.admin_read_only}
                                    title={edgeMode?.admin_read_only ? 'Changes must be made on the central server and re-synced.' : undefined}
                                >
                                    <Edit2 class="w-3.5 h-3.5" /> Edit
                                </button>
                            {/if}
                        </div>
                    </td>
                </tr>
            {/each}
        {/snippet}
    </AdminTable>

    <!-- Mobile Card View -->
    <div class="grid grid-cols-1 gap-4 mt-4 md:hidden">
        {#each users as user (user.id)}
            <div
                class="card bg-surface-50 border border-surface-200 shadow-sm p-4 flex flex-col gap-4"
            >
                <div
                    class="flex flex-col gap-3 xs:flex-row xs:items-start xs:justify-between xs:gap-2"
                >
                    <div class="flex items-center gap-3">
                        <UserAvatar {user} size="md" />
                        <div>
                            <span class="font-semibold text-surface-950 block"
                                >{user.name}</span
                            >
                            <span class="text-sm text-surface-950/70 font-mono block"
                                >{user.username}</span
                            >
                            <span class="text-xs text-surface-500 block truncate"
                                >{user.email}</span
                            >
                        </div>
                    </div>
                    <div
                        class="flex flex-wrap items-center gap-1.5 xs:flex-col xs:items-end xs:gap-1.5"
                    >
                        {#if user.role === "admin"}
                            <span
                                class="badge preset-filled-primary-500 shadow-sm font-semibold uppercase tracking-wide text-[11px] px-2.5 py-1 rounded-full"
                            >
                                Admin
                            </span>
                        {:else}
                            <span
                                class="badge preset-tonal shadow-sm font-semibold uppercase tracking-wide text-[11px] px-2.5 py-1 rounded-full"
                            >
                                Staff
                            </span>
                        {/if}
                        {#if user.role === "staff" && user.pending_assignment}
                            <span
                                class="badge preset-filled-warning-500 shadow-sm font-semibold uppercase tracking-wide text-[11px] px-2.5 py-1 rounded-full"
                                >Pending</span
                            >
                        {/if}
                        {#if user.is_active}
                            <span
                                class="badge preset-filled-success-500 shadow-sm font-semibold uppercase tracking-wide text-[11px] px-2.5 py-1 rounded-full flex items-center gap-1.5"
                            >
                                <CheckCircle2 class="w-3.5 h-3.5" /> Active
                            </span>
                        {:else}
                            <span
                                class="badge preset-tonal text-surface-600 shadow-sm font-semibold uppercase tracking-wide text-[11px] px-2.5 py-1 rounded-full flex items-center gap-1.5"
                            >
                                <Ban class="w-3.5 h-3.5" /> Disabled
                            </span>
                        {/if}
                    </div>
                </div>

                <div
                    class="grid grid-cols-2 gap-2 text-sm bg-surface-100/50 p-3 rounded-container border border-surface-200"
                >
                    <div>
                        <span
                            class="text-xs text-surface-500 block mb-0.5 uppercase tracking-wider font-semibold"
                            >Availability</span
                        >
                        {#if user.availability_status === "available"}
                            <span
                                class="badge preset-filled-success-500 shadow-sm font-semibold uppercase tracking-wide text-[11px] px-2.5 py-1 rounded-full inline-flex items-center gap-1.5"
                            >
                                <CheckCircle class="w-3.5 h-3.5" /> Available
                            </span>
                        {:else if user.availability_status === "on_break"}
                            <span
                                class="badge preset-filled-warning-500 shadow-sm font-semibold uppercase tracking-wide text-[11px] px-2.5 py-1 rounded-full inline-flex items-center gap-1.5"
                            >
                                <Clock class="w-3.5 h-3.5" /> Away
                            </span>
                        {:else}
                            <span
                                class="badge preset-tonal text-surface-500 shadow-sm font-semibold uppercase tracking-wide text-[11px] px-2.5 py-1 rounded-full border border-surface-200 inline-block"
                            >
                                Offline
                            </span>
                        {/if}
                    </div>
                    <div>
                        <span
                            class="text-xs text-surface-500 block mb-0.5 uppercase tracking-wider font-semibold"
                            >Station</span
                        >
                        {#if user.assigned_station?.name}
                            <span
                                class="text-surface-950 font-medium truncate block"
                                title={user.assigned_station.name}
                            >
                                {user.assigned_station.name}
                            </span>
                        {:else}
                            <span class="text-xs text-surface-950/50">Not assigned</span>
                        {/if}
                    </div>
                </div>

                <div
                    class="pt-1 border-t border-surface-200 flex flex-wrap items-center justify-end gap-2"
                >
                    {#if user.is_active}
                        <button
                            type="button"
                            class="btn btn-sm flex-1 preset-outlined bg-surface-50 text-surface-700 flex items-center justify-center gap-1.5 shadow-sm transition-colors"
                            onclick={() => openEdit(user)}
                            disabled={submitting || !!edgeMode?.admin_read_only}
                            title={edgeMode?.admin_read_only ? 'Changes must be made on the central server and re-synced.' : undefined}
                        >
                            <Edit2 class="w-3.5 h-3.5" /> Edit
                        </button>
                        {#if user.id !== auth_user_id}
                            <button
                                type="button"
                                class="btn btn-sm flex-1 preset-outlined bg-surface-50 text-error-600 hover:bg-error-50 border-error-200 flex items-center justify-center gap-1.5 shadow-sm transition-colors"
                                onclick={() => openDeactivateConfirm(user)}
                                disabled={submitting || !!edgeMode?.admin_read_only}
                                title={edgeMode?.admin_read_only ? 'Changes must be made on the central server and re-synced.' : undefined}
                            >
                                <Ban class="w-3.5 h-3.5" /> Disable
                            </button>
                        {/if}
                    {:else}
                        <button
                            type="button"
                            class="btn btn-sm flex-1 preset-outlined bg-surface-50 text-surface-700 flex items-center justify-center gap-1.5 shadow-sm transition-colors"
                            onclick={() => openEdit(user)}
                            disabled={submitting || !!edgeMode?.admin_read_only}
                            title={edgeMode?.admin_read_only ? 'Changes must be made on the central server and re-synced.' : undefined}
                        >
                            <Edit2 class="w-3.5 h-3.5" /> Edit
                        </button>
                    {/if}
                </div>
            </div>
        {/each}
    </div>
    {/if}
</AdminLayout>

<Modal open={showCreateModal} title="Add staff" onClose={closeModals}>
    <div class="space-y-4">
        <div class="form-control">
            <label class="label"
                ><span class="label-text font-medium">Name</span></label
            >
            <input
                type="text"
                class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                bind:value={createName}
                placeholder="Juan Cruz"
            />
        </div>
        <div class="form-control">
            <label class="label"
                ><span class="label-text font-medium">Username</span></label
            >
            <input
                type="text"
                class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm font-mono"
                bind:value={createUsername}
                placeholder="juan.cruz"
                autocomplete="off"
            />
            <span class="label-text-alt mt-1">Letters, numbers, dots, underscores, hyphens only. Used to sign in.</span>
        </div>
        <div class="form-control">
            <label class="label"
                ><span class="label-text font-medium">Email</span></label
            >
            <input
                type="email"
                class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                bind:value={createEmail}
                placeholder="juan@example.com"
            />
        </div>
        <div class="form-control">
            <label class="label"
                ><span class="label-text font-medium">Recovery Gmail</span></label
            >
            <input
                type="email"
                class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                bind:value={createRecoveryGmail}
                placeholder="user@gmail.com"
            />
            <span class="label-text-alt mt-1">Forgot-password link is sent here (Hestia SMTP). Required for self-service reset.</span>
        </div>
        <div class="form-control">
            <PasswordInput
                bind:password={createPassword}
                bind:passwordConfirm={createPasswordConfirm}
                idPrefix="create_pw"
                disabled={submitting}
                required={true}
            />
        </div>
        <div class="form-control">
            <label class="label"
                ><span class="label-text font-medium">Role</span></label
            >
            <select
                class="select select-theme rounded-container border border-surface-200 px-3 py-2 pr-8 w-full bg-surface-50 shadow-sm"
                bind:value={createRole}
            >
                {#each allowed_roles_for_create ?? ["staff"] as r}
                    <option value={r}>{r === "admin" ? "Admin" : "Staff"}</option>
                {/each}
            </select>
        </div>
        {#if createRole === "staff"}
            <div
                class="form-control rounded-container border border-surface-200 bg-surface-50/80 p-3"
            >
                <label
                    for="create-pending-assignment"
                    class="label cursor-pointer justify-between gap-3 w-full items-start"
                >
                    <span class="label-text font-medium text-sm"
                        >Hold at onboarding (pending assignment)</span
                    >
                    <input
                        id="create-pending-assignment"
                        type="checkbox"
                        class="checkbox"
                        bind:checked={createPendingAssignment}
                        disabled={submitting}
                    />
                </label>
                <span class="label-text-alt mt-1 block"
                    >User can sign in but cannot use station or triage until you
                    assign a station or turn this off.</span
                >
            </div>
        {/if}
        <div class="form-control">
            <label class="label"
                ><span class="label-text font-medium"
                    >Override PIN (6 digits)</span
                ></label
            >
            <input
                type="text"
                class="input rounded-container border border-surface-200 px-3 py-2 w-full max-w-xs bg-surface-50 shadow-sm text-center"
                bind:value={createOverridePin}
                oninput={(e) => { createOverridePin = sanitizeOverridePin(e.currentTarget.value); }}
                placeholder="e.g. 123456"
                maxlength="6"
                inputmode="numeric"
                autocomplete="one-time-code"
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
                disabled={submitting || createPassword.length < 8 || createPassword !== createPasswordConfirm}
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
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                    bind:value={editName}
                />
            </div>
            <div class="form-control">
                <label class="label"
                    ><span class="label-text font-medium">Username</span></label
                >
                <input
                    type="text"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm font-mono"
                    bind:value={editUsername}
                    autocomplete="off"
                />
            </div>
            <div class="form-control">
                <label class="label"
                    ><span class="label-text font-medium">Email</span></label
                >
                <input
                    type="email"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                    bind:value={editEmail}
                />
            </div>
            <div class="form-control">
                <label class="label"
                    ><span class="label-text font-medium">Recovery Gmail</span></label
                >
                <input
                    type="email"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                    bind:value={editRecoveryGmail}
                    placeholder="user@gmail.com"
                />
            </div>
            {#if editUser.id !== auth_user_id}
                <div class="form-control">
                    <label class="label"
                        ><span class="label-text font-medium">Role</span></label
                    >
                    <select
                        class="select select-theme rounded-container border border-surface-200 px-3 py-2 pr-8 w-full bg-surface-50 shadow-sm"
                        bind:value={editRole}
                    >
                        {#each allowed_roles_for_edit ?? ["staff"] as r}
                            <option value={r}>{r === "admin" ? "Admin" : "Staff"}</option>
                        {/each}
                    </select>
                </div>
                {#if editRole === "staff"}
                    <div
                        class="form-control rounded-container border border-surface-200 bg-surface-50/80 p-3"
                    >
                        <label
                            for="edit-pending-assignment"
                            class="label cursor-pointer justify-between gap-3 w-full items-start"
                        >
                            <span class="label-text font-medium text-sm"
                                >Hold at onboarding (pending assignment)</span
                            >
                            <input
                                id="edit-pending-assignment"
                                type="checkbox"
                                class="checkbox"
                                bind:checked={editPendingAssignment}
                                disabled={submitting}
                            />
                        </label>
                        <span class="label-text-alt mt-1 block"
                            >Blocks station and triage until cleared or a station
                            is assigned.</span
                        >
                    </div>
                {/if}
                <div
                    class="form-control mt-2 mb-2 p-3 border border-surface-100 rounded-container bg-surface-50/50"
                >
                    <label
                        for="edit-user-active-switch"
                        class="label cursor-pointer justify-between gap-3 w-full items-center"
                    >
                        <span class="label-text font-medium text-surface-950"
                            >Active (can log in)</span
                        >
                        <div class="relative inline-block w-11 h-5 shrink-0">
                            <input
                                id="edit-user-active-switch"
                                type="checkbox"
                                class="peer appearance-none w-11 h-5 bg-surface-200 rounded-full checked:bg-surface-800 cursor-pointer transition-colors duration-300 disabled:opacity-50 disabled:cursor-not-allowed"
                                bind:checked={editIsActive}
                                disabled={submitting}
                            />
                            <span
                                class="absolute top-0 left-0 w-5 h-5 bg-surface-950 rounded-full border border-surface-300 shadow-sm transition-transform duration-300 peer-checked:translate-x-6 peer-checked:border-surface-800 pointer-events-none"
                                aria-hidden="true"
                            ></span>
                        </div>
                    </label>
                </div>
            {/if}
            {#if editUser.id === auth_user_id}
                <div class="form-control">
                    <label class="label"
                        ><span class="label-text font-medium"
                            >New password (optional)</span
                        ></label
                    >
                    <input
                        type="password"
                        class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                        bind:value={editPassword}
                        placeholder="Leave blank to keep current"
                        autocomplete="new-password"
                    />
                    <span class="label-text-alt mt-1"
                        >For your own account, use this field or your profile
                        page. The admin reset flow below is for other users
                        only.</span
                    >
                </div>
            {:else}
                <div
                    class="form-control rounded-container border border-surface-200 bg-surface-50/80 p-4 space-y-3"
                >
                    <p class="text-sm font-medium text-surface-900">
                        Temporary password (fail-safe)
                    </p>
                    <p class="text-xs text-surface-600 leading-relaxed">
                        Set a new password for this user when they are locked
                        out or email is unavailable. This is logged for audit.
                        You cannot use this on your own account.
                    </p>
                    <div>
                        <label class="label"
                            ><span class="label-text font-medium text-sm"
                                >New password</span
                            ></label
                        >
                        <input
                            type="password"
                            class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                            bind:value={editTempPassword}
                            autocomplete="new-password"
                        />
                    </div>
                    <div>
                        <label class="label"
                            ><span class="label-text font-medium text-sm"
                                >Confirm password</span
                            ></label
                        >
                        <input
                            type="password"
                            class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                            bind:value={editTempPasswordConfirm}
                            autocomplete="new-password"
                        />
                    </div>
                    <button
                        type="button"
                        class="btn preset-filled-primary-500 shadow-sm w-full sm:w-auto"
                        disabled={submitting}
                        onclick={handleSetTemporaryPassword}
                    >
                        {submitting ? "Setting…" : "Set temporary password"}
                    </button>
                </div>
            {/if}
            <div class="form-control">
                <label class="label"
                    ><span class="label-text font-medium"
                        >Override PIN (6 digits)</span
                    ></label
                >
                <input
                    type="text"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full max-w-xs bg-surface-50 shadow-sm text-center"
                    bind:value={editOverridePin}
                    oninput={(e) => { editOverridePin = sanitizeOverridePin(e.currentTarget.value); }}
                    placeholder="Leave blank to keep or clear"
                    maxlength="6"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                />
                <span class="label-text-alt mt-1"
                    >Required for program supervisors. Set so the user can be
                    added as supervisor in Program → Staff.</span
                >
            </div>
            {#if (assignable_permissions?.length ?? 0) > 0}
                <div class="border-t border-surface-200 pt-4 mt-4">
                    <h3 class="text-sm font-semibold text-surface-950 mb-3">Extra access</h3>
                    <UserDirectPermissionsEditor
                        bind:selected={editDirectPermissions}
                        assignablePermissions={assignable_permissions ?? []}
                        effectivePermissions={editEffectivePermissions}
                        supervisorProgramCount={editSupervisorProgramCount}
                        canAssignPlatformManage={auth_is_super_admin}
                        disabled={submitting}
                        errors={editValidationErrors}
                        description="Optional — only if they need more than their role usually allows."
                    />
                </div>
            {/if}
            {#if editValidationErrors.role}
                <div
                    class="rounded-container border border-error-200 bg-error-50 text-error-800 text-sm px-3 py-2"
                    role="alert"
                >
                    {#each (Array.isArray(editValidationErrors.role) ? editValidationErrors.role : [editValidationErrors.role]) as err}
                        <p>{err}</p>
                    {/each}
                </div>
            {/if}
            {#if editValidationErrors.is_active}
                <div
                    class="rounded-container border border-error-200 bg-error-50 text-error-800 text-sm px-3 py-2"
                    role="alert"
                >
                    {#each (Array.isArray(editValidationErrors.is_active) ? editValidationErrors.is_active : [editValidationErrors.is_active]) as err}
                        <p>{err}</p>
                    {/each}
                </div>
            {/if}
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
