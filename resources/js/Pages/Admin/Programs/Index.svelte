<script lang="ts">
    import AdminLayout from "../../../Layouts/AdminLayout.svelte";
    import Modal from "../../../Components/Modal.svelte";
    import ConfirmModal from "../../../Components/ConfirmModal.svelte";
    import { get } from "svelte/store";
    import { Link, router, usePage } from "@inertiajs/svelte";

    // Import icons to make the UI look more professional
    import {
        Plus,
        Play,
        Pause,
        Square,
        Trash2,
        Edit2,
        Eye,
        FolderOpen,
        AlertCircle,
    } from "lucide-svelte";

    interface ProgramItem {
        id: number;
        name: string;
        description: string | null;
        is_active: boolean;
        is_paused?: boolean;
        created_at: string | null;
    }

    let { programs = [] }: { programs: ProgramItem[] } = $props();

    let showCreateModal = $state(false);
    let editProgram = $state<ProgramItem | null>(null);
    let deleteConfirmProgram = $state<ProgramItem | null>(null);
    let createName = $state("");
    let createDescription = $state("");
    let editName = $state("");
    let editDescription = $state("");
    let submitting = $state(false);
    let error = $state("");

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
    ): Promise<{ ok: boolean; data?: object; message?: string }> {
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
        return { ok: res.ok, data, message: data?.message };
    }

    function openCreate() {
        createName = "";
        createDescription = "";
        error = "";
        showCreateModal = true;
    }

    function openEdit(p: ProgramItem) {
        editProgram = p;
        editName = p.name;
        editDescription = p.description ?? "";
        error = "";
    }

    function closeModals() {
        showCreateModal = false;
        editProgram = null;
        deleteConfirmProgram = null;
        error = "";
    }

    async function handleCreate() {
        if (!createName.trim()) return;
        submitting = true;
        error = "";
        const { ok, message } = await api("POST", "/api/admin/programs", {
            name: createName.trim(),
            description: createDescription.trim() || null,
        });
        submitting = false;
        if (ok) {
            closeModals();
            router.reload();
        } else {
            error = message ?? "Failed to create program.";
        }
    }

    async function handleUpdate() {
        if (!editProgram || !editName.trim()) return;
        submitting = true;
        error = "";
        const { ok, message } = await api(
            "PUT",
            `/api/admin/programs/${editProgram.id}`,
            {
                name: editName.trim(),
                description: editDescription.trim() || null,
            },
        );
        submitting = false;
        if (ok) {
            closeModals();
            router.reload();
        } else {
            error = message ?? "Failed to update program.";
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
        error = "";
        activateMissing = [];
        const { ok, message, data } = await api(
            "POST",
            `/api/admin/programs/${p.id}/activate`,
        );
        submitting = false;
        if (ok) {
            error = "";
            activateMissing = [];
            router.reload();
        } else {
            error = message ?? "Failed to start session.";
            const missing = (data as { missing?: string[] } | undefined)?.missing;
            if (Array.isArray(missing))
                activateMissing = missing.map((k) => ACTIVATE_MISSING_LABELS[k] ?? k);
        }
    }

    async function handlePause(p: ProgramItem) {
        submitting = true;
        error = "";
        const { ok, message } = await api(
            "POST",
            `/api/admin/programs/${p.id}/pause`,
        );
        submitting = false;
        if (ok) {
            error = "";
            router.reload();
        } else {
            error = message ?? "Failed to pause.";
        }
    }

    async function handleResume(p: ProgramItem) {
        submitting = true;
        error = "";
        const { ok, message } = await api(
            "POST",
            `/api/admin/programs/${p.id}/resume`,
        );
        submitting = false;
        if (ok) {
            error = "";
            router.reload();
        } else {
            error = message ?? "Failed to resume.";
        }
    }

    async function handleDeactivate(p: ProgramItem) {
        submitting = true;
        error = "";
        const { ok, message } = await api(
            "POST",
            `/api/admin/programs/${p.id}/deactivate`,
        );
        submitting = false;
        if (ok) {
            error = "";
            router.reload();
        } else {
            error =
                message ??
                "You can only stop the session when no clients are in the queue.";
        }
    }

    function openDeleteConfirm(p: ProgramItem) {
        deleteConfirmProgram = p;
        error = "";
    }

    async function handleDeleteConfirm() {
        if (!deleteConfirmProgram) return;
        const p = deleteConfirmProgram;
        submitting = true;
        error = "";
        const { ok, message } = await api(
            "DELETE",
            `/api/admin/programs/${p.id}`,
        );
        submitting = false;
        if (ok) {
            closeModals();
            router.reload();
        } else {
            error = message ?? "Cannot delete: program has sessions.";
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
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-surface-950">Programs</h1>
                <p class="text-sm text-surface-600 mt-1">
                    Manage your active queue sessions and programs.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Link
                    href="/admin/program-default-settings"
                    class="btn preset-tonal flex items-center gap-2"
                >
                    Default program settings
                </Link>
                <button
                    type="button"
                    class="btn preset-filled-primary-500 flex items-center gap-2"
                    onclick={openCreate}
                >
                    <Plus class="w-4 h-4" />
                    Create Program
                </button>
            </div>
        </div>

        {#if error}
            <div
                class="bg-error-50 text-error-900 border border-error-200 rounded-container p-4 flex items-start gap-3 shadow-sm"
                role="alert"
            >
                <AlertCircle class="w-5 h-5 text-error-500 mt-0.5 shrink-0" />
                <div class="flex-grow">
                    <span class="font-medium text-sm">{error}</span>
                    {#if activateMissing.length > 0}
                        <ul class="mt-2 list-disc list-inside text-sm">
                            {#each activateMissing as label}
                                <li>{label}</li>
                            {/each}
                        </ul>
                    {/if}
                </div>
                <button
                    type="button"
                    class="text-error-500 hover:text-error-700 transition-colors"
                    onclick={() => { error = ""; activateMissing = []; }}
                >
                    Dismiss
                </button>
            </div>
        {/if}

        {#if programs.length === 0}
            <div
                class="rounded-container bg-surface-50 border border-surface-200 p-12 flex flex-col items-center justify-center text-center shadow-sm"
            >
                <div
                    class="bg-surface-100 p-4 rounded-full text-surface-400 mb-4"
                >
                    <FolderOpen class="w-8 h-8" />
                </div>
                <h3 class="text-lg font-semibold text-surface-950">
                    No programs found
                </h3>
                <p class="text-surface-600 max-w-sm mt-2 mb-6">
                    You haven't set up any programs or services yet. Create one
                    to start managing queues.
                </p>
                <button
                    type="button"
                    class="btn preset-filled-primary-500 flex items-center gap-2"
                    onclick={openCreate}
                >
                    <Plus class="w-4 h-4" /> Create First Program
                </button>
            </div>
        {:else}
            <div class="grid gap-5 grid-cols-1 md:grid-cols-2 xl:grid-cols-3">
                {#each programs as program (program.id)}
                    <div
                        class="bg-surface-50 rounded-container elevation-card transition-all hover:shadow-[var(--shadow-raised)] flex flex-col h-full border border-surface-200/50"
                    >
                        <div class="p-5 flex-grow flex flex-col gap-3">
                            <div class="flex items-start justify-between gap-3">
                                <Link
                                    href="/admin/programs/{program.id}"
                                    class="text-lg font-bold text-surface-950 hover:text-primary-600 transition-colors line-clamp-1"
                                >
                                    {program.name}
                                </Link>
                                <div class="shrink-0 mt-1">
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
                        </div>

                        <div
                            class="px-5 py-3 border-t border-surface-100 flex flex-wrap items-center justify-between gap-2 bg-surface-50/50 rounded-b-container"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <Link
                                    href="/admin/programs/{program.id}"
                                    class="btn preset-tonal btn-sm flex items-center gap-1.5"
                                    title="Manage Program"
                                >
                                    <Eye class="w-3.5 h-3.5" /> Manage
                                </Link>

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
                                    disabled={submitting}
                                    title="Edit Program"
                                >
                                    <Edit2 class="w-4 h-4 text-surface-600" />
                                </button>
                                <button
                                    type="button"
                                    class="btn preset-tonal btn-sm p-2 hover:bg-error-50"
                                    onclick={() => openDeleteConfirm(program)}
                                    disabled={submitting}
                                    title="Delete Program"
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
            <div class="flex justify-end gap-3 pt-2">
                <button
                    type="button"
                    class="btn preset-tonal"
                    onclick={closeModals}>Cancel</button
                >
                <button
                    type="submit"
                    class="btn preset-filled-primary-500"
                    disabled={submitting || !editName.trim()}
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
