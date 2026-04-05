<script lang="ts">
    import AdminLayout from "../../../Layouts/AdminLayout.svelte";
    import Modal from "../../../Components/Modal.svelte";
    import ConfirmModal from "../../../Components/ConfirmModal.svelte";
    import { get } from "svelte/store";
    import { Link, router, usePage } from "@inertiajs/svelte";

    // Import icons to make the UI look more professional
    import { toaster } from "../../../lib/toaster.js";
    import {
        Plus,
        Play,
        Pause,
        Square,
        Trash2,
        Edit2,
        FolderOpen,
        Search,
    } from "lucide-svelte";

    interface ProgramSettingsSummary {
        allow_public_triage?: boolean;
        allow_unverified_entry?: boolean;
    }

    interface ProgramItem {
        id: number;
        name: string;
        description: string | null;
        is_active: boolean;
        is_paused?: boolean;
        created_at: string | null;
        settings?: ProgramSettingsSummary;
        edge_locked_by_device_id?: number | null;
        edge_locked_by_device_name?: string | null;
    }

    let {
        programs = [],
        search: initialSearch = "",
    }: {
        programs: ProgramItem[];
        search?: string | null;
    } = $props();

    const appliedSearch = $derived(initialSearch ?? "");
    let searchTerm = $state("");
    $effect(() => {
        searchTerm = appliedSearch;
    });

    function handleSearchSubmit(event: SubmitEvent) {
        event.preventDefault();
        router.visit("/admin/programs", {
            method: "get",
            data: {
                search: searchTerm.trim() || undefined,
            },
            preserveState: true,
            preserveScroll: true,
        });
    }

    let showCreateModal = $state(false);
    let editProgram = $state<ProgramItem | null>(null);
    let deleteConfirmProgram = $state<ProgramItem | null>(null);
    let createName = $state("");
    let createDescription = $state("");
    let editName = $state("");
    let editDescription = $state("");
    let editAllowPublicTriage = $state(false);
    let editAllowUnverifiedEntry = $state(false);
    let submitting = $state(false);

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
    ): Promise<{ ok: boolean; data?: object; message?: string }> {
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
            const data = await res.json().catch(() => ({}));
            return { ok: res.ok, data, message: data?.message };
        } catch (e) {
            toaster.error({ title: MSG_NETWORK_ERROR });
            return { ok: false, message: MSG_NETWORK_ERROR };
        }
    }

    function openCreate() {
        createName = "";
        createDescription = "";
        showCreateModal = true;
    }

    function openEdit(p: ProgramItem) {
        editProgram = p;
        editName = p.name;
        editDescription = p.description ?? "";
        editAllowPublicTriage = p.settings?.allow_public_triage ?? false;
        editAllowUnverifiedEntry = p.settings?.allow_unverified_entry ?? false;
    }

    function closeModals() {
        showCreateModal = false;
        editProgram = null;
        deleteConfirmProgram = null;
    }

    async function handleCreate() {
        if (!createName.trim()) return;
        submitting = true;
        const { ok, message } = await api("POST", "/api/admin/programs", {
            name: createName.trim(),
            description: createDescription.trim() || null,
        });
        submitting = false;
        if (ok) {
            toaster.success({ title: "Program created." });
            closeModals();
            router.reload();
        } else {
            toaster.error({ title: message ?? "Failed to create program." });
        }
    }

    async function handleUpdate() {
        if (!editProgram || !editName.trim()) return;
        submitting = true;
        const payload: {
            name: string;
            description: string | null;
            settings: {
                allow_public_triage: boolean;
                allow_unverified_entry: boolean;
            };
        } = {
            name: editName.trim(),
            description: editDescription.trim() || null,
            settings: {
                allow_public_triage: editAllowPublicTriage,
                allow_unverified_entry: editAllowUnverifiedEntry,
            },
        };
        const { ok, message } = await api(
            "PUT",
            `/api/admin/programs/${editProgram.id}`,
            payload,
        );
        submitting = false;
        if (ok) {
            toaster.success({ title: "Program updated." });
            closeModals();
            router.reload();
        } else {
            toaster.error({ title: message ?? "Failed to update program." });
        }
    }

    /** Per ISSUES-ELABORATION §16: 422 shows message + optional missing list. */
    const ACTIVATE_MISSING_LABELS: Record<string, string> = {
        no_stations: "Add at least one station.",
        no_processes_with_stations: "Assign at least one process to a station.",
        no_staff_assigned: "Assign at least one staff member to a station.",
        no_tracks: "Add at least one track.",
    };
    let activateMissing = $state<string[]>([]);

    async function handleActivate(p: ProgramItem) {
        submitting = true;
        activateMissing = [];
        const { ok, message, data } = await api(
            "POST",
            `/api/admin/programs/${p.id}/activate`,
        );
        submitting = false;
        if (ok) {
            toaster.success({ title: "Session started." });
            const warning = (data as { warning?: string } | undefined)?.warning;
            if (warning) {
                toaster.warning({ title: warning });
            }
            activateMissing = [];
            router.reload();
        } else {
            const missing = (data as { missing?: string[] } | undefined)
                ?.missing;
            if (Array.isArray(missing))
                activateMissing = missing.map(
                    (k) => ACTIVATE_MISSING_LABELS[k] ?? k,
                );
            toaster.error({
                title: message ?? "Failed to start session.",
                description: activateMissing.length ? activateMissing.join(", ") : undefined,
            });
        }
    }

    async function handlePause(p: ProgramItem) {
        submitting = true;
        const { ok, message } = await api(
            "POST",
            `/api/admin/programs/${p.id}/pause`,
        );
        submitting = false;
        if (ok) {
            toaster.success({ title: "Program paused." });
            router.reload();
        } else {
            toaster.error({ title: message ?? "Failed to pause." });
        }
    }

    async function handleResume(p: ProgramItem) {
        submitting = true;
        const { ok, message } = await api(
            "POST",
            `/api/admin/programs/${p.id}/resume`,
        );
        submitting = false;
        if (ok) {
            toaster.success({ title: "Program resumed." });
            router.reload();
        } else {
            toaster.error({ title: message ?? "Failed to resume." });
        }
    }

    async function handleDeactivate(p: ProgramItem) {
        submitting = true;
        const { ok, message } = await api(
            "POST",
            `/api/admin/programs/${p.id}/deactivate`,
        );
        submitting = false;
        if (ok) {
            toaster.success({ title: "Session stopped." });
            router.reload();
        } else {
            toaster.error({
                title: message ?? "You can only stop the session when no clients are in the queue.",
            });
        }
    }

    function openDeleteConfirm(p: ProgramItem) {
        deleteConfirmProgram = p;
    }

    async function handleDeleteConfirm() {
        if (!deleteConfirmProgram) return;
        const p = deleteConfirmProgram;
        submitting = true;
        const { ok, message } = await api(
            "DELETE",
            `/api/admin/programs/${p.id}`,
        );
        submitting = false;
        if (ok) {
            toaster.success({ title: "Program deleted." });
            closeModals();
            router.reload();
        } else {
            toaster.error({ title: message ?? "Cannot delete: program has sessions." });
            deleteConfirmProgram = null;
        }
    }

    function closeDeleteConfirm() {
        deleteConfirmProgram = null;
    }
</script>

<svelte:head>
    <title>Programs — FlexiQueue</title>
</svelte:head>

<AdminLayout>
    <div class="flex flex-col gap-6">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
            <div class="flex-1 space-y-3">
                <div>
                    <h1 class="text-2xl font-bold text-surface-950 flex items-center gap-2">
                        <FolderOpen class="w-6 h-6 text-primary-500" />
                        Programs
                    </h1>
                    <p class="mt-2 text-surface-600 max-w-3xl leading-relaxed">
                        Manage your active queue sessions and programs.
                    </p>
                </div>
                <form
                    class="w-full max-w-lg"
                    onsubmit={handleSearchSubmit}
                >
                    <label
                        for="program-search"
                        class="label-text text-xs font-semibold uppercase tracking-wide text-surface-500 mb-1 block"
                    >
                        Search by name or description
                    </label>
                    <div class="join w-full">
                        <div
                            class="join-item flex items-center gap-2 px-3 py-1 border border-surface-300 rounded-l-container bg-surface-50 w-full"
                        >
                            <Search class="w-4 h-4 my-2 text-surface-400 shrink-0" />
                            <input
                                type="text"
                                id="program-search"
                                class="input input-ghost !bg-transparent px-0 py-0 h-auto text-sm w-full focus:!outline-none focus:!ring-0 focus:!border-transparent"
                                placeholder="e.g. Cash Assistance"
                                bind:value={searchTerm}
                                aria-label="Search programs by name or description"
                            />
                        </div>
                        <button
                            type="submit"
                            class="join-item btn preset-filled-primary-500 px-4 text-sm shadow-sm !rounded-none !rounded-tr-lg !rounded-br-lg"
                        >
                            Search
                        </button>
                    </div>
                    {#if initialSearch}
                        <p class="mt-1 text-[11px] text-surface-500">
                            Showing results for
                            <span class="font-semibold">"{initialSearch}"</span>.
                        </p>
                    {/if}
                </form>
            </div>
            <div
                class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full sm:w-auto mt-2 sm:mt-0"
            >
                <button
                    type="button"
                    class="btn preset-filled-primary-500 flex justify-center items-center gap-2 w-full sm:w-auto shadow-sm transition-transform active:scale-95 md:flex hidden"
                    onclick={openCreate}
                    aria-label="Create Program"
                    disabled={!!edgeMode?.admin_read_only}
                    title={edgeMode?.admin_read_only ? 'Changes must be made on the central server and re-synced.' : undefined}
                >
                    <Plus class="w-4 h-4" />
                    Create Program
                </button>
            </div>
            <!-- Mobile FAB: circular icon-only button bottom-right, above footer (per Phase 3 Configuration) -->
            <button
                type="button"
                class="fixed bottom-[87px] right-[23px] z-50 flex md:hidden items-center justify-center w-14 h-14 rounded-full bg-primary-500 text-primary-contrast-500 shadow-lg hover:bg-primary-600 active:scale-95 transition-transform touch-manipulation"
                onclick={openCreate}
                aria-label="Create Program"
                disabled={!!edgeMode?.admin_read_only}
                title={edgeMode?.admin_read_only ? 'Changes must be made on the central server and re-synced.' : undefined}
            >
                <Plus class="w-6 h-6" aria-hidden="true" />
            </button>
        </div>

        {#if programs.length === 0 && !initialSearch}
            <div
                role="status"
                class="rounded-container bg-surface-50 border border-surface-200 p-12 flex flex-col items-center justify-center text-center shadow-sm"
                aria-label="No programs yet"
            >
                <div
                    class="bg-surface-100 p-4 rounded-full text-surface-400 mb-4"
                >
                    <FolderOpen class="w-8 h-8" />
                </div>
                <h3 class="text-lg font-semibold text-surface-950">
                    Create your first program
                </h3>
                <p class="text-surface-600 max-w-sm mt-2 mb-6">
                    Add a program to start managing queues.
                </p>
                <button
                    type="button"
                    class="btn preset-filled-primary-500 flex items-center gap-2 touch-target-h"
                    onclick={openCreate}
                    disabled={!!edgeMode?.admin_read_only}
                    title={edgeMode?.admin_read_only ? 'Changes must be made on the central server and re-synced.' : undefined}
                >
                    <Plus class="w-4 h-4" /> Create First Program
                </button>
            </div>
        {:else if programs.length === 0 && initialSearch}
            <div
                role="status"
                class="rounded-container bg-surface-50 border border-surface-200 p-12 flex flex-col items-center justify-center text-center shadow-sm"
                aria-label="No programs match search"
            >
                <div
                    class="bg-surface-100 p-4 rounded-full text-surface-400 mb-4"
                >
                    <Search class="w-8 h-8" />
                </div>
                <h3 class="text-lg font-semibold text-surface-950">
                    No programs match your search
                </h3>
                <p class="text-surface-600 max-w-sm mt-2 mb-6">
                    No programs match "{initialSearch}". Try a different term or
                    clear the search.
                </p>
                <button
                    type="button"
                    class="btn preset-tonal"
                    onclick={() =>
                        router.visit("/admin/programs", {
                            method: "get",
                            data: {},
                            preserveState: true,
                            preserveScroll: true,
                        })}
                >
                    Clear search
                </button>
            </div>
        {:else}
            <div class="grid gap-5 grid-cols-1 md:grid-cols-2 xl:grid-cols-3">
                {#each programs as program (program.id)}
                    <div
                        class="bg-surface-50 rounded-container elevation-card transition-all hover:shadow-[var(--shadow-raised)] flex flex-col h-full border border-surface-200/50"
                    >
                        <Link
                            href="/admin/programs/{program.id}"
                            class="p-5 flex-grow flex flex-col gap-3 block hover:bg-surface-100/50 rounded-t-container transition-colors"
                            aria-label="Manage {program.name}"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <span
                                    class="text-lg font-bold text-surface-950 transition-colors line-clamp-1"
                                >
                                    {program.name}
                                </span>
                                <div class="shrink-0 mt-1 flex flex-col items-end gap-1">
                                    {#if program.is_active && !program.is_paused}
                                        <span
                                            class="text-[10px] uppercase tracking-wider font-bold px-2 py-1 rounded preset-filled-success-500 animate-pulse"
                                            >Live</span
                                        >
                                    {:else if program.is_active && program.is_paused}
                                        <span
                                            class="text-[10px] uppercase tracking-wider font-bold px-2 py-1 rounded preset-filled-warning-500"
                                            >Paused</span
                                        >
                                    {:else}
                                        <span
                                            class="text-[10px] uppercase tracking-wider font-bold px-2 py-1 rounded preset-tonal text-surface-600"
                                            >Inactive</span
                                        >
                                    {/if}
                                    {#if program.edge_locked_by_device_id}
                                        <span
                                            class="text-[10px] uppercase tracking-wider font-bold px-2 py-1 rounded preset-tonal-primary flex items-center gap-1"
                                            title="Assigned to edge device {program.edge_locked_by_device_name ?? 'unknown'}. Unassign the device to use this program on central."
                                        >🔒 Edge: {program.edge_locked_by_device_name ?? "device"}</span>
                                    {/if}
                                </div>
                            </div>

                            {#if program.description}
                                <p
                                    class="text-sm text-surface-600 line-clamp-2"
                                >
                                    {program.description}
                                </p>
                            {:else}
                                <p class="text-sm text-surface-400 italic">
                                    No description provided.
                                </p>
                            {/if}
                        </Link>

                        <div
                            class="px-5 py-3 border-t border-surface-100 flex flex-wrap items-center justify-between gap-2 bg-surface-50/50 rounded-b-container"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                {#if program.is_active}
                                    {#if program.is_paused}
                                        <button
                                            type="button"
                                            class="btn preset-filled-primary-500 btn-sm flex items-center gap-1.5"
                                            onclick={() =>
                                                handleResume(program)}
                                            disabled={submitting}
                                        >
                                            <Play class="w-3.5 h-3.5" /> Resume
                                        </button>
                                    {:else}
                                        <button
                                            type="button"
                                            class="btn preset-tonal btn-sm flex items-center gap-1.5 text-warning-600"
                                            onclick={() => handlePause(program)}
                                            disabled={submitting}
                                        >
                                            <Pause class="w-3.5 h-3.5" /> Pause
                                        </button>
                                    {/if}
                                    <button
                                        type="button"
                                        class="btn preset-tonal btn-sm flex items-center gap-1.5 text-error-600"
                                        onclick={() =>
                                            handleDeactivate(program)}
                                        disabled={submitting}
                                        title="Stop Session"
                                    >
                                        <Square class="w-3.5 h-3.5" /> Stop
                                    </button>
                                {:else}
                                    <button
                                        type="button"
                                        class="btn preset-filled-primary-500 btn-sm flex items-center gap-1.5"
                                        onclick={() => handleActivate(program)}
                                        disabled={submitting}
                                    >
                                        <Play class="w-3.5 h-3.5" /> Start
                                    </button>
                                {/if}
                            </div>

                            <div class="flex items-center gap-1">
                                <button
                                    type="button"
                                    class="btn preset-tonal btn-sm p-2"
                                    onclick={() => openEdit(program)}
                                    disabled={submitting || !!edgeMode?.admin_read_only}
                                    title={edgeMode?.admin_read_only ? 'Changes must be made on the central server and re-synced.' : 'Edit Program'}
                                >
                                    <Edit2 class="w-4 h-4 text-surface-600" />
                                </button>
                                <button
                                    type="button"
                                    class="btn preset-tonal btn-sm p-2 hover:bg-error-50"
                                    onclick={() => openDeleteConfirm(program)}
                                    disabled={submitting || !!edgeMode?.admin_read_only}
                                    title={edgeMode?.admin_read_only ? 'Changes must be made on the central server and re-synced.' : 'Delete Program'}
                                >
                                    <Trash2 class="w-4 h-4 text-error-500" />
                                </button>
                            </div>
                        </div>
                    </div>
                {/each}
            </div>
        {/if}
    </div>
</AdminLayout>

<Modal open={showCreateModal} title="Create Program" onClose={closeModals}>
    <form
        onsubmit={(e) => {
            e.preventDefault();
            handleCreate();
        }}
        class="flex flex-col gap-4"
    >
        <div class="form-control w-full">
            <label for="create-name" class="label"
                ><span class="label-text font-medium">Name</span></label
            >
            <input
                id="create-name"
                type="text"
                class="input rounded-container border border-surface-200 px-3 py-2 w-full focus:ring-2 focus:ring-primary-500"
                placeholder="e.g. Cash Assistance Q1 2026"
                maxlength="100"
                bind:value={createName}
                required
            />
        </div>
        <div class="form-control w-full">
            <label for="create-desc" class="label"
                ><span class="label-text font-medium"
                    >Description <span class="text-surface-400 font-normal"
                        >(optional)</span
                    ></span
                ></label
            >
            <textarea
                id="create-desc"
                class="textarea rounded-container border border-surface-200 w-full focus:ring-2 focus:ring-primary-500"
                rows="3"
                placeholder="Brief description of the program"
                bind:value={createDescription}
            ></textarea>
        </div>
        <div class="flex justify-end gap-3 pt-2">
            <button type="button" class="btn preset-tonal" onclick={closeModals}
                >Cancel</button
            >
            <button
                type="submit"
                class="btn preset-filled-primary-500"
                disabled={submitting || !createName.trim()}
            >
                {submitting ? "Creating…" : "Create Program"}
            </button>
        </div>
    </form>
</Modal>

{#if editProgram}
    <Modal open={!!editProgram} title="Edit Program" onClose={closeModals}>
        <form
            onsubmit={(e) => {
                e.preventDefault();
                handleUpdate();
            }}
            class="flex flex-col gap-4"
        >
            <div class="form-control w-full">
                <label for="edit-name" class="label"
                    ><span class="label-text font-medium">Name</span></label
                >
                <input
                    id="edit-name"
                    type="text"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full focus:ring-2 focus:ring-primary-500"
                    maxlength="100"
                    bind:value={editName}
                    required
                />
            </div>
            <div class="form-control w-full">
                <label for="edit-desc" class="label"
                    ><span class="label-text font-medium"
                        >Description <span class="text-surface-400 font-normal"
                            >(optional)</span
                        ></span
                    ></label
                >
                <textarea
                    id="edit-desc"
                    class="textarea rounded-container border border-surface-200 w-full focus:ring-2 focus:ring-primary-500"
                    rows="3"
                    bind:value={editDescription}
                ></textarea>
            </div>
            <div class="divider my-1"></div>
            <fieldset class="space-y-3">
                <legend class="text-sm font-semibold text-surface-900">
                    Kiosk (self-service)
                </legend>
                <label class="flex items-start gap-3 cursor-pointer">
                    <input
                        type="checkbox"
                        class="checkbox checkbox-sm mt-1"
                        bind:checked={editAllowPublicTriage}
                    />
                    <span class="text-sm">
                        <span class="font-medium">Allow kiosk self-service</span>
                        <span class="block text-surface-500 text-xs">
                            When enabled, visitors can use this program’s kiosk
                            (site URL) to start a visit or check queue status,
                            per program kiosk settings.
                        </span>
                    </span>
                </label>
                <label class="flex items-start gap-3 cursor-pointer">
                    <input
                        type="checkbox"
                        class="checkbox checkbox-sm mt-1"
                        bind:checked={editAllowUnverifiedEntry}
                        disabled={!editAllowPublicTriage}
                    />
                    <span class="text-sm">
                        <span class="font-medium"
                            >Allow visits to start with unverified ID (kiosk)</span
                        >
                        <span class="block text-surface-500 text-xs">
                            When identity is required, if enabled the kiosk can create a
                            <span class="font-semibold">queue session</span>
                            together with an identity registration before staff verify; the session stays
                            marked unverified until staff accept. If disabled, the kiosk only submits a
                            registration for <span class="font-semibold">client registration</span> (staff)
                            and does <span class="font-semibold">not</span> start a session.
                        </span>
                    </span>
                </label>
            </fieldset>
            <div class="flex justify-end gap-3 pt-2">
                <button
                    type="button"
                    class="btn preset-tonal"
                    onclick={closeModals}>Cancel</button
                >
                <button
                    type="submit"
                    class="btn preset-filled-primary-500"
                    disabled={submitting || !editName.trim() || !!edgeMode?.admin_read_only}
                    title={edgeMode?.admin_read_only ? 'Changes must be made on the central server and re-synced.' : undefined}
                >
                    {submitting ? "Saving…" : "Save Changes"}
                </button>
            </div>
        </form>
    </Modal>
{/if}

<ConfirmModal
    open={!!deleteConfirmProgram}
    title="Delete program?"
    message={deleteConfirmProgram
        ? `Are you sure you want to delete "${deleteConfirmProgram.name}"? This is only allowed if it has no active queue sessions.`
        : ""}
    confirmLabel="Delete Program"
    cancelLabel="Cancel"
    variant="danger"
    loading={submitting}
    onConfirm={handleDeleteConfirm}
    onCancel={closeDeleteConfirm}
/>
