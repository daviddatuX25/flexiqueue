<script lang="ts">
    import AdminLayout from "../../../Layouts/AdminLayout.svelte";
    import Modal from "../../../Components/Modal.svelte";
    import {
        ArrowLeft,
        IdCard,
        Info,
        Lock,
        Eye,
        Trash2,
        ArrowRightLeft,
        Search,
    } from "lucide-svelte";
    import { Link, router, usePage } from "@inertiajs/svelte";
    import { get } from "svelte/store";
    import { toaster } from "../../../lib/toaster.js";

    interface ClientItem {
        id: number;
        name: string;
        birth_year: number | null;
        created_at: string | null;
    }

    interface IdDocumentItem {
        id: number;
        id_type: string;
        id_last4: string;
        created_at: string | null;
    }

    let {
        client,
        id_documents = [],
    }: {
        client: ClientItem;
        id_documents: IdDocumentItem[];
    } = $props();

    const page = usePage();
    const userRole = $derived(
        (get(page).props as { auth?: { user?: { role?: string } } } | undefined)
            ?.auth?.user?.role ?? null,
    );
    const isAdmin = $derived(userRole === "admin");
    const isSupervisor = $derived(userRole === "supervisor");

    let showDeleteClientModal = $state(false);
    let deletingClient = $state(false);
    let deleteClientError = $state<string | null>(null);

    let showDeleteIdModal = $state(false);
    let deletingId = $state(false);
    let deleteIdError = $state<string | null>(null);
    let deleteIdTarget = $state<IdDocumentItem | null>(null);

    let showReassignModal = $state(false);
    let reassigning = $state(false);
    let reassignError = $state<string | null>(null);
    let reassignDocTarget = $state<IdDocumentItem | null>(null);
    let targetSearchName = $state("");
    let targetSearchBirthYear = $state("");
    let searchingTargets = $state(false);
    let targetResults = $state<
        { id: number; name: string; birth_year: number | null }[]
    >([]);
    let chosenTargetClientId = $state<number | null>(null);

    let showRevealModal = $state(false);
    let activeDocument = $state<IdDocumentItem | null>(null);
    let reasonPreset = $state<
        | ""
        | "Duplicate investigation"
        | "Identity verification request"
        | "Staff error correction"
        | "Other"
    >("");
    let otherReason = $state("");
    let submitting = $state(false);
    let revealedIdNumber = $state<string | null>(null);
    let errorMessage = $state<string | null>(null);

    function formatDate(value: string | null): string {
        if (!value) return "—";
        try {
            const d = new Date(value);
            return new Intl.DateTimeFormat(undefined, {
                year: "numeric",
                month: "short",
                day: "2-digit",
                hour: "2-digit",
                minute: "2-digit",
            }).format(d);
        } catch {
            return value;
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

    function openDeleteClient() {
        if (!isAdmin) return;
        showDeleteClientModal = true;
        deletingClient = false;
        deleteClientError = null;
    }

    function closeDeleteClientModal() {
        showDeleteClientModal = false;
        deletingClient = false;
        deleteClientError = null;
    }

    async function confirmDeleteClient() {
        if (!isAdmin || deletingClient) return;
        deletingClient = true;
        deleteClientError = null;
        try {
            const res = await fetch(`/api/admin/clients/${client.id}`, {
                method: "DELETE",
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            });
            const data = (await res.json().catch(() => ({}))) as {
                message?: string;
            };
            if (res.ok) {
                toaster.success({ title: "Client deleted." });
                router.visit("/admin/clients");
                return;
            }
            const msg =
                data.message ??
                (res.status === 409
                    ? "Cannot delete client due to audit log."
                    : "Failed to delete client.");
            deleteClientError = msg;
            toaster.error({ title: msg });
        } catch {
            const msg = "Network error. Please try again.";
            deleteClientError = msg;
            toaster.error({ title: msg });
        } finally {
            deletingClient = false;
        }
    }

    function openDeleteId(doc: IdDocumentItem) {
        if (!isAdmin) return;
        deleteIdTarget = doc;
        showDeleteIdModal = true;
        deletingId = false;
        deleteIdError = null;
    }

    function closeDeleteIdModal() {
        showDeleteIdModal = false;
        deletingId = false;
        deleteIdError = null;
        deleteIdTarget = null;
    }

    async function confirmDeleteId() {
        if (!isAdmin || !deleteIdTarget || deletingId) return;
        deletingId = true;
        deleteIdError = null;
        try {
            const res = await fetch(
                `/api/admin/client-id-documents/${deleteIdTarget.id}`,
                {
                    method: "DELETE",
                    headers: {
                        Accept: "application/json",
                        "X-CSRF-TOKEN": getCsrfToken(),
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    credentials: "same-origin",
                },
            );
            const data = (await res.json().catch(() => ({}))) as {
                message?: string;
            };
            if (res.ok) {
                toaster.success({ title: "ID document deleted." });
                closeDeleteIdModal();
                router.reload();
                return;
            }
            const msg =
                data.message ??
                (res.status === 409
                    ? "Cannot delete ID document due to audit log."
                    : "Failed to delete ID document.");
            deleteIdError = msg;
            toaster.error({ title: msg });
        } catch {
            const msg = "Network error. Please try again.";
            deleteIdError = msg;
            toaster.error({ title: msg });
        } finally {
            deletingId = false;
        }
    }

    function openReassign(doc: IdDocumentItem) {
        if (!isAdmin) return;
        reassignDocTarget = doc;
        showReassignModal = true;
        reassigning = false;
        reassignError = null;
        targetSearchName = "";
        targetSearchBirthYear = "";
        targetResults = [];
        chosenTargetClientId = null;
    }

    function closeReassignModal() {
        showReassignModal = false;
        reassigning = false;
        reassignError = null;
        reassignDocTarget = null;
        targetResults = [];
        chosenTargetClientId = null;
    }

    const BIRTH_YEAR_MIN = 1900;
    const BIRTH_YEAR_MAX = 2100;

    async function runTargetSearch(e?: SubmitEvent) {
        e?.preventDefault();
        const name = targetSearchName.trim();
        if (!name) return;
        searchingTargets = true;
        reassignError = null;
        try {
            const birthYearVal = targetSearchBirthYear.trim();
            const birthYearNum = birthYearVal ? Number(birthYearVal) : NaN;
            const birthYear =
                Number.isInteger(birthYearNum) &&
                birthYearNum >= BIRTH_YEAR_MIN &&
                birthYearNum <= BIRTH_YEAR_MAX
                    ? birthYearNum
                    : null;
            const params = new URLSearchParams({
                name,
                per_page: "10",
                page: "1",
            });
            if (birthYear != null) {
                params.set("birth_year", String(birthYear));
            }
            const res = await fetch(`/api/clients/search?${params.toString()}`, {
                headers: { Accept: "application/json" },
                credentials: "same-origin",
            });
            const data = (await res.json().catch(() => ({}))) as {
                data?: { id: number; name: string; birth_year: number | null }[];
            };
            if (res.ok) {
                targetResults = data.data ?? [];
            } else {
                targetResults = [];
            }
        } finally {
            searchingTargets = false;
        }
    }

    async function confirmReassign() {
        if (!isAdmin || !reassignDocTarget || chosenTargetClientId == null)
            return;
        if (reassigning) return;
        reassigning = true;
        reassignError = null;
        try {
            const res = await fetch(
                `/api/admin/client-id-documents/${reassignDocTarget.id}/reassign`,
                {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        Accept: "application/json",
                        "X-CSRF-TOKEN": getCsrfToken(),
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    credentials: "same-origin",
                    body: JSON.stringify({
                        target_client_id: chosenTargetClientId,
                    }),
                },
            );
            const data = (await res.json().catch(() => ({}))) as {
                message?: string;
            };
            if (res.ok) {
                toaster.success({ title: "ID document reassigned." });
                closeReassignModal();
                router.reload();
                return;
            }
            const msg = data.message ?? "Failed to reassign ID document.";
            reassignError = msg;
            toaster.error({ title: msg });
        } catch {
            const msg = "Network error. Please try again.";
            reassignError = msg;
            toaster.error({ title: msg });
        } finally {
            reassigning = false;
        }
    }

    function openReveal(doc: IdDocumentItem) {
        if (!isAdmin) return;
        activeDocument = doc;
        reasonPreset = "";
        otherReason = "";
        revealedIdNumber = null;
        errorMessage = null;
        showRevealModal = true;
    }

    function closeRevealModal() {
        showRevealModal = false;
        activeDocument = null;
        reasonPreset = "";
        otherReason = "";
        revealedIdNumber = null;
        errorMessage = null;
        submitting = false;
    }

    async function handleRevealSubmit() {
        if (!activeDocument || !isAdmin) return;

        if (!reasonPreset) {
            errorMessage = "Please select a reason for reveal.";
            return;
        }

        let reason: string | null = null;
        if (reasonPreset !== "Other") {
            reason = reasonPreset;
        } else if (otherReason.trim() !== "") {
            reason = `Other: ${otherReason.trim()}`;
        } else {
            reason = "Other";
        }

        submitting = true;
        errorMessage = null;
        try {
            const res = await fetch(
                `/api/admin/client-id-documents/${activeDocument.id}/reveal`,
                {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        Accept: "application/json",
                        "X-CSRF-TOKEN": getCsrfToken(),
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    credentials: "same-origin",
                    body: JSON.stringify({
                        confirm: true,
                        reason,
                    }),
                },
            );
            if (res.status === 419) {
                submitting = false;
                const msg = "Session expired. Please refresh and try again.";
                errorMessage = msg;
                toaster.error({ title: msg });
                return;
            }
            const data = (await res.json().catch(() => ({}))) as {
                message?: string;
                id_document?: { id_number?: string };
            };
            if (res.ok && data.id_document?.id_number) {
                revealedIdNumber = data.id_document.id_number;
                errorMessage = null;
            } else if (res.status === 403) {
                const msg = "Admin access required to reveal ID numbers.";
                errorMessage = msg;
                toaster.error({ title: msg });
            } else if (res.status === 429) {
                const msg =
                    data.message ??
                    "Too many reveal attempts. Please wait and try again.";
                errorMessage = msg;
                toaster.error({ title: msg });
            } else {
                const msg =
                    data.message ?? "Failed to reveal ID. Please try again.";
                errorMessage = msg;
                toaster.error({ title: msg });
            }
        } catch (e) {
            const msg = "Network error. Please try again.";
            errorMessage = msg;
            toaster.error({ title: msg });
        } finally {
            submitting = false;
        }
    }
</script>

<svelte:head>
    <title>{client.name} — Client — FlexiQueue</title>
</svelte:head>

<AdminLayout>
    <div class="flex flex-col gap-3 mb-4">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <div class="flex items-center gap-2 text-sm">
                <Link
                    href="/admin/clients"
                    class="inline-flex items-center gap-1 text-surface-600 hover:text-surface-900 text-xs font-medium"
                >
                    <ArrowLeft class="w-3 h-3" />
                    Back to clients
                </Link>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <Link
                    href={`/triage?from_client_id=${client.id}`}
                    class="btn btn-sm preset-filled-primary-500 flex items-center gap-1.5 shadow-sm"
                >
                    Create registration
                </Link>
                {#if isAdmin}
                    <button
                        type="button"
                        class="btn btn-sm preset-outlined bg-surface-50 text-error-600 hover:bg-error-50 border-error-200 flex items-center gap-1.5 shadow-sm"
                        onclick={openDeleteClient}
                        data-testid="admin-client-delete-button"
                    >
                        <Trash2 class="w-3.5 h-3.5" />
                        Delete client
                    </button>
                {/if}
            </div>
        </div>

        <div
            class="flex flex-col md:flex-row md:items-center md:justify-between gap-3"
        >
            <div>
                <h1
                    class="text-2xl font-bold text-surface-950 flex items-center gap-2"
                >
                    <IdCard class="w-6 h-6 text-primary-500" />
                    {client.name}
                </h1>
                <p class="mt-1 text-surface-600 text-sm">
                    Birth year:
                    <span class="font-medium text-surface-950">
                        {client.birth_year ?? "—"}
                    </span>
                    <span class="mx-2 text-surface-300">•</span>
                    Created {formatDate(client.created_at)}
                </p>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <section
            class="rounded-container bg-surface-50 border border-surface-200 p-5 shadow-sm lg:col-span-1"
        >
            <h2 class="text-sm font-semibold text-surface-900 mb-3">
                Client details
            </h2>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-surface-500">Name</dt>
                    <dd class="font-medium text-surface-950 text-right">
                        {client.name}
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-surface-500">Birth year</dt>
                    <dd class="font-medium text-surface-950 text-right">
                        {client.birth_year ?? "—"}
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-surface-500">Created</dt>
                    <dd class="font-medium text-surface-950 text-right">
                        {formatDate(client.created_at)}
                    </dd>
                </div>
            </dl>
        </section>

        <section
            class="rounded-container bg-surface-50 border border-surface-200 p-5 shadow-sm lg:col-span-2"
        >
            <div class="flex items-start justify-between gap-3 mb-3">
                <div>
                    <h2 class="text-sm font-semibold text-surface-900">
                        ID documents
                    </h2>
                    <p class="text-xs text-surface-500 mt-1">
                        Only masked ID numbers are shown here (last four
                        characters). Full ID values are available only through
                        the admin-only reveal flow and are never stored in page
                        props.
                    </p>
                </div>
                <div class="hidden md:flex flex-col items-end gap-1">
                    <div
                        class="flex items-center gap-1 text-[11px] text-surface-500"
                    >
                        <Info class="w-3.5 h-3.5" />
                        <span>
                            Reveals are audited and visible only to admins.
                        </span>
                    </div>
                    {#if isSupervisor && !isAdmin}
                        <div
                            class="flex items-center gap-1 text-[11px] text-surface-500"
                        >
                            <Lock class="w-3.5 h-3.5" />
                            <span>Admin access required to reveal IDs.</span>
                        </div>
                    {/if}
                </div>
            </div>

            {#if id_documents.length === 0}
                <div
                    class="rounded-container border border-dashed border-surface-200 bg-surface-50/60 p-6 text-center text-sm text-surface-600"
                >
                    No ID documents are attached to this client yet.
                </div>
            {:else}
                <!-- Desktop table -->
                <div class="hidden md:block">
                    <table class="table table-zebra w-full">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Masked ID</th>
                                <th>Created</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {#each id_documents as doc (doc.id)}
                                <tr>
                                    <td class="text-surface-900 font-medium">
                                        {doc.id_type}
                                    </td>
                                    <td class="text-surface-800">
                                        •••• {doc.id_last4}
                                    </td>
                                    <td class="text-surface-700">
                                        {formatDate(doc.created_at)}
                                    </td>
                                    <td class="text-right">
                                        {#if isAdmin}
                                            <div class="flex justify-end gap-2">
                                                <button
                                                    type="button"
                                                    class="btn btn-xs preset-outlined bg-surface-50 text-surface-700 hover:bg-surface-50 flex items-center gap-1.5 shadow-sm px-3 py-1.5 transition-colors"
                                                    onclick={() => openReveal(doc)}
                                                    disabled={submitting}
                                                >
                                                    <Eye class="w-3.5 h-3.5" />
                                                    Reveal
                                                </button>
                                                <button
                                                    type="button"
                                                    class="btn btn-xs preset-outlined bg-surface-50 text-surface-700 hover:bg-surface-50 flex items-center gap-1.5 shadow-sm px-3 py-1.5 transition-colors"
                                                    onclick={() => openReassign(doc)}
                                                    disabled={submitting}
                                                    data-testid="admin-id-doc-reassign-{doc.id}"
                                                >
                                                    <ArrowRightLeft class="w-3.5 h-3.5" />
                                                    Migrate
                                                </button>
                                                <button
                                                    type="button"
                                                    class="btn btn-xs preset-outlined bg-surface-50 text-error-600 hover:bg-error-50 border-error-200 flex items-center gap-1.5 shadow-sm px-3 py-1.5 transition-colors"
                                                    onclick={() => openDeleteId(doc)}
                                                    disabled={submitting}
                                                    data-testid="admin-id-doc-delete-{doc.id}"
                                                >
                                                    <Trash2 class="w-3.5 h-3.5" />
                                                    Delete
                                                </button>
                                            </div>
                                        {:else if isSupervisor}
                                            <button
                                                type="button"
                                                class="btn btn-xs preset-outlined bg-surface-50 text-surface-400 border-surface-200 flex items-center gap-1.5 shadow-sm px-3 py-1.5"
                                                disabled
                                            >
                                                <Lock class="w-3.5 h-3.5" />
                                                Reveal (Admin only)
                                            </button>
                                        {/if}
                                    </td>
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>

                <!-- Mobile cards -->
                <div class="grid grid-cols-1 gap-3 md:hidden">
                    {#each id_documents as doc (doc.id)}
                        <div
                            class="card bg-surface-50 border border-surface-200 shadow-sm p-4 flex flex-col gap-3"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <span
                                    class="text-sm font-semibold text-surface-950"
                                >
                                    {doc.id_type}
                                </span>
                                <span
                                    class="text-xs text-surface-500 uppercase tracking-wide"
                                >
                                    Added {formatDate(doc.created_at)}
                                </span>
                            </div>
                            <div class="text-sm text-surface-800">
                                Masked ID:
                                <span class="font-mono">
                                    •••• {doc.id_last4}
                                </span>
                            </div>
                            <div class="pt-1 border-t border-surface-200">
                                {#if isAdmin}
                                    <div class="grid grid-cols-1 gap-2">
                                        <button
                                            type="button"
                                            class="btn btn-xs preset-outlined bg-surface-50 text-surface-700 flex items-center justify-center gap-1.5 shadow-sm w-full"
                                            onclick={() => openReveal(doc)}
                                            disabled={submitting}
                                        >
                                            <Eye class="w-3.5 h-3.5" />
                                            Reveal ID
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-xs preset-outlined bg-surface-50 text-surface-700 flex items-center justify-center gap-1.5 shadow-sm w-full"
                                            onclick={() => openReassign(doc)}
                                            disabled={submitting}
                                        >
                                            <ArrowRightLeft class="w-3.5 h-3.5" />
                                            Migrate ID to another client
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-xs preset-outlined bg-surface-50 text-error-600 hover:bg-error-50 border-error-200 flex items-center justify-center gap-1.5 shadow-sm w-full"
                                            onclick={() => openDeleteId(doc)}
                                            disabled={submitting}
                                        >
                                            <Trash2 class="w-3.5 h-3.5" />
                                            Delete ID document
                                        </button>
                                    </div>
                                {:else if isSupervisor}
                                    <button
                                        type="button"
                                        class="btn btn-xs preset-outlined bg-surface-50 text-surface-400 border-surface-200 flex items-center justify-center gap-1.5 shadow-sm w-full"
                                        disabled
                                    >
                                        <Lock class="w-3.5 h-3.5" />
                                        Reveal (Admin only)
                                    </button>
                                    <p
                                        class="mt-1 text-[11px] text-surface-500 text-center"
                                    >
                                        Admin access required to reveal ID
                                        numbers.
                                    </p>
                                {/if}
                            </div>
                        </div>
                    {/each}
                </div>
            {/if}
        </section>
    </div>

    <Modal
        open={showRevealModal}
        title={activeDocument
            ? `Reveal ID — ${activeDocument.id_type}`
            : "Reveal ID"}
        onClose={closeRevealModal}
    >
        {#if activeDocument}
            <div class="space-y-4">
                <p class="text-sm text-surface-700">
                    Revealing this ID will decrypt and show the full ID number
                    for a limited time. This action is logged with your user
                    account and reason.
                </p>
                <div class="form-control">
                    <label class="label" for="reveal-reason"
                        ><span class="label-text text-sm font-medium"
                            >Reason for reveal</span
                        ></label
                    >
                    <select
                        id="reveal-reason"
                        class="select rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 text-sm shadow-sm"
                        bind:value={reasonPreset}
                    >
                        <option value="">Select a reason…</option>
                        <option value="Duplicate investigation">
                            Duplicate investigation
                        </option>
                        <option value="Identity verification request">
                            Identity verification request
                        </option>
                        <option value="Staff error correction">
                            Staff error correction
                        </option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                {#if reasonPreset === "Other"}
                    <div class="form-control">
                        <label class="label" for="reveal-other-reason"
                            ><span class="label-text text-sm font-medium"
                                >Additional details (optional)</span
                            ></label
                        >
                        <textarea
                            id="reveal-other-reason"
                            class="textarea rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 text-sm shadow-sm min-h-[72px]"
                            bind:value={otherReason}
                            placeholder="Briefly describe why you need to reveal this ID."
                        ></textarea>
                    </div>
                {/if}

                {#if errorMessage}
                    <div
                        class="rounded-container border border-error-200 bg-error-50 text-error-800 text-xs px-3 py-2"
                    >
                        {errorMessage}
                    </div>
                {/if}

                {#if revealedIdNumber}
                    <div
                        class="rounded-container border border-warning-200 bg-warning-50 text-warning-900 px-3 py-3 space-y-2"
                    >
                        <div class="text-xs font-semibold uppercase tracking-wide">
                            Decrypted ID (handle with care)
                        </div>
                        <div class="font-mono text-sm break-all">
                            {revealedIdNumber}
                        </div>
                        <p class="text-[11px] text-warning-900/90">
                            Do not capture, print, or store this ID outside
                            approved systems. Closing this dialog will clear the
                            value.
                        </p>
                    </div>
                {/if}

                <div
                    class="flex justify-end gap-3 mt-4 pt-3 border-t border-surface-100"
                >
                    <button
                        type="button"
                        class="btn preset-tonal"
                        onclick={closeRevealModal}
                        disabled={submitting}
                    >
                        Close
                    </button>
                    <button
                        type="button"
                        class="btn preset-filled-primary-500 shadow-sm"
                        onclick={handleRevealSubmit}
                        disabled={submitting}
                    >
                        {submitting ? "Revealing…" : "Reveal now"}
                    </button>
                </div>
            </div>
        {/if}
    </Modal>

    <Modal open={showDeleteClientModal} title="Delete client" onClose={closeDeleteClientModal}>
        {#snippet children()}
            <div class="space-y-3">
                <p class="text-sm text-surface-700">
                    Delete client <span class="font-semibold">{client.name}</span>? This cannot be undone.
                </p>
                {#if deleteClientError}
                    <p class="text-sm text-error-700">{deleteClientError}</p>
                {/if}
                <div class="flex gap-2 pt-1">
                    <button type="button" class="btn preset-tonal flex-1" onclick={closeDeleteClientModal} disabled={deletingClient}>Cancel</button>
                    <button type="button" class="btn preset-filled-primary-500 flex-1 bg-error-600 hover:bg-error-700" onclick={confirmDeleteClient} disabled={deletingClient}>
                        {deletingClient ? "Deleting…" : "Delete"}
                    </button>
                </div>
            </div>
        {/snippet}
    </Modal>

    <Modal open={showDeleteIdModal} title="Delete ID document" onClose={closeDeleteIdModal}>
        {#snippet children()}
            {#if deleteIdTarget}
                <div class="space-y-3">
                    <p class="text-sm text-surface-700">
                        Delete <span class="font-semibold">{deleteIdTarget.id_type}</span> (•••• {deleteIdTarget.id_last4}) from this client?
                    </p>
                    {#if deleteIdError}
                        <p class="text-sm text-error-700">{deleteIdError}</p>
                    {/if}
                    <div class="flex gap-2 pt-1">
                        <button type="button" class="btn preset-tonal flex-1" onclick={closeDeleteIdModal} disabled={deletingId}>Cancel</button>
                        <button type="button" class="btn preset-filled-primary-500 flex-1 bg-error-600 hover:bg-error-700" onclick={confirmDeleteId} disabled={deletingId}>
                            {deletingId ? "Deleting…" : "Delete"}
                        </button>
                    </div>
                </div>
            {/if}
        {/snippet}
    </Modal>

    <Modal open={showReassignModal} title="Migrate ID document" onClose={closeReassignModal} wide={true}>
        {#snippet children()}
            {#if reassignDocTarget}
                <div class="space-y-4">
                    <p class="text-sm text-surface-700">
                        Move <span class="font-semibold">{reassignDocTarget.id_type}</span> (•••• {reassignDocTarget.id_last4}) to another client.
                    </p>
                    <form class="space-y-2" onsubmit={runTargetSearch}>
                        <label for="admin-reassign-search-name" class="label-text text-xs font-semibold uppercase tracking-wide text-surface-500 block">Search target client</label>
                        <div class="join w-full">
                            <div class="join-item flex items-center gap-2 px-3 py-1 border border-surface-300 rounded-l-container bg-surface-50 w-full">
                                <Search class="w-4 h-4 my-2 text-surface-400 shrink-0" />
                                <input
                                    type="text"
                                    id="admin-reassign-search-name"
                                    class="input input-ghost !bg-transparent px-0 py-0 h-auto text-sm w-full focus:!outline-none focus:!ring-0 focus:!border-transparent"
                                    bind:value={targetSearchName}
                                    placeholder="e.g. Maria Santos"
                                    data-testid="admin-reassign-search-name"
                                />
                            </div>
                            <button type="submit" class="join-item btn preset-filled-primary-500 px-4 text-sm shadow-sm !rounded-none !rounded-tr-lg !rounded-br-lg" disabled={searchingTargets || !targetSearchName.trim()}>
                                {searchingTargets ? "Searching…" : "Search"}
                            </button>
                        </div>
                        <label class="flex flex-col gap-1 text-xs">
                            <span class="text-surface-700">Birth year (optional)</span>
                            <input type="number" class="input rounded-container border border-surface-200 px-3 py-2 text-sm w-full" bind:value={targetSearchBirthYear} placeholder="e.g. 1985" min={BIRTH_YEAR_MIN} max={BIRTH_YEAR_MAX} />
                        </label>
                    </form>

                    {#if targetResults.length > 0}
                        <p class="text-xs text-surface-600">Select a client</p>
                        <ul class="space-y-2">
                            {#each targetResults as c (c.id)}
                                <li>
                                    <button
                                        type="button"
                                        class="btn preset-tonal w-full justify-start text-left text-sm touch-target-h {chosenTargetClientId === c.id ? 'ring-2 ring-primary-500' : ''}"
                                        onclick={() => (chosenTargetClientId = c.id)}
                                    >
                                        {c.name}{#if c.birth_year != null}<span class="text-surface-600"> ({c.birth_year})</span>{/if}
                                    </button>
                                </li>
                            {/each}
                        </ul>
                    {:else if targetSearchName.trim() !== "" && !searchingTargets}
                        <p class="text-xs text-surface-600">No matches found.</p>
                    {/if}

                    {#if reassignError}
                        <p class="text-sm text-error-700">{reassignError}</p>
                    {/if}

                    <div class="flex gap-2 pt-1">
                        <button type="button" class="btn preset-tonal flex-1" onclick={closeReassignModal} disabled={reassigning}>Cancel</button>
                        <button type="button" class="btn preset-filled-primary-500 flex-1" onclick={confirmReassign} disabled={reassigning || chosenTargetClientId == null}>
                            {reassigning ? "Migrating…" : "Migrate"}
                        </button>
                    </div>
                </div>
            {/if}
        {/snippet}
    </Modal>
</AdminLayout>

