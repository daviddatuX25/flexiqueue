<script lang="ts">
    import AdminLayout from "../../../Layouts/AdminLayout.svelte";
    import Modal from "../../../Components/Modal.svelte";
    import {
        ArrowLeft,
        IdCard,
        Lock,
        Eye,
        Trash2,
        Pencil,
    } from "lucide-svelte";
    import { Link, router, usePage } from "@inertiajs/svelte";
    import { get } from "svelte/store";
    import { toaster } from "../../../lib/toaster.js";
    import { clientDisplayName as getClientDisplayName } from "../../../lib/clientDisplayName.js";

    interface ClientItem {
        id: number;
        first_name: string;
        middle_name: string | null;
        last_name: string;
        birth_date: string | null;
        address_line_1?: string | null;
        address_line_2?: string | null;
        city?: string | null;
        state?: string | null;
        postal_code?: string | null;
        country?: string | null;
        mobile_masked: string | null;
        created_at: string | null;
    }

    let {
        client,
    }: {
        client: ClientItem;
    } = $props();

    const clientDisplayName = $derived(getClientDisplayName(client));

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

    let showRevealModal = $state(false);
    let reasonPreset = $state<
        | ""
        | "Duplicate investigation"
        | "Identity verification request"
        | "Staff error correction"
        | "Other"
    >("");
    let otherReason = $state("");
    let submitting = $state(false);
    let revealedMobile = $state<string | null>(null);
    let revealError = $state<string | null>(null);

    let showUpdateModal = $state(false);
    let updateMobile = $state("");
    let updateReason = $state("");
    let updateError = $state<string | null>(null);
    let updating = $state(false);

    let showEditModal = $state(false);
    let editFirst = $state("");
    let editMiddle = $state("");
    let editLast = $state("");
    let editBirthDate = $state("");
    let editAddress1 = $state("");
    let editAddress2 = $state("");
    let editCity = $state("");
    let editState = $state("");
    let editPostalCode = $state("");
    let editCountry = $state("");
    let editError = $state<string | null>(null);
    let savingDetails = $state(false);

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

    function openReveal() {
        if (!isAdmin || !client.mobile_masked) return;
        showRevealModal = true;
        submitting = false;
        revealedMobile = null;
        revealError = null;
        reasonPreset = "";
        otherReason = "";
    }

    function closeRevealModal() {
        showRevealModal = false;
        revealedMobile = null;
    }

    async function confirmRevealPhone() {
        if (!isAdmin || submitting) return;
        const reason = reasonPreset === "Other" ? otherReason.trim() : reasonPreset;
        if (!reason) {
            revealError = "Please select or provide a reason.";
            return;
        }
        submitting = true;
        revealError = null;
        try {
            const res = await fetch(
                `/api/clients/${client.id}/reveal-phone`,
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
                        reason,
                        confirm: true,
                    }),
                },
            );
            const data = (await res.json().catch(() => ({}))) as {
                message?: string;
                mobile?: string;
            };
            if (res.ok && data.mobile) {
                revealedMobile = data.mobile;
            } else if (res.status === 403) {
                revealError = "Admin access required to reveal phone.";
                toaster.error({ title: revealError });
            } else if (res.status === 422) {
                revealError = data.message ?? "Client has no stored phone.";
            } else {
                revealError = data.message ?? "Failed to reveal. Please try again.";
                toaster.error({ title: revealError });
            }
        } catch {
            revealError = "Network error. Please try again.";
            toaster.error({ title: revealError });
        } finally {
            submitting = false;
        }
    }

    function openUpdate() {
        if (!isAdmin && !isSupervisor) return;
        showUpdateModal = true;
        updateMobile = "";
        updateReason = "";
        updateError = null;
        updating = false;
    }

    function closeUpdateModal() {
        showUpdateModal = false;
    }

    async function confirmUpdatePhone() {
        if ((!isAdmin && !isSupervisor) || updating) return;
        const mobile = updateMobile.trim();
        const reason = updateReason.trim();
        if (!mobile || !reason) {
            updateError = "Phone and reason are required.";
            return;
        }
        updating = true;
        updateError = null;
        try {
            const res = await fetch(`/api/clients/${client.id}/mobile`, {
                method: "PUT",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
                body: JSON.stringify({ mobile, reason }),
            });
            const data = (await res.json().catch(() => ({}))) as {
                message?: string;
                mobile_masked?: string;
            };
            if (res.ok) {
                toaster.success({ title: "Phone updated." });
                router.reload();
            } else if (res.status === 409) {
                updateError = data.message ?? "Another client already has this number.";
            } else {
                updateError = data.message ?? "Failed to update. Please try again.";
                toaster.error({ title: updateError });
            }
        } catch {
            updateError = "Network error. Please try again.";
            toaster.error({ title: updateError });
        } finally {
            updating = false;
        }
    }

    function openEditDetails() {
        showEditModal = true;
        editFirst = client.first_name ?? "";
        editMiddle = client.middle_name ?? "";
        editLast = client.last_name ?? "";
        editBirthDate = client.birth_date ?? "";
        editAddress1 = client.address_line_1 ?? "";
        editAddress2 = client.address_line_2 ?? "";
        editCity = client.city ?? "";
        editState = client.state ?? "";
        editPostalCode = client.postal_code ?? "";
        editCountry = client.country ?? "";
        editError = null;
        savingDetails = false;
    }

    function closeEditModal() {
        showEditModal = false;
    }

    async function confirmSaveDetails() {
        if (savingDetails) return;
        const first = editFirst.trim();
        const last = editLast.trim();
        if (!first || !last) {
            editError = "First name and last name are required.";
            return;
        }
        savingDetails = true;
        editError = null;
        try {
            const res = await fetch(`/api/admin/clients/${client.id}`, {
                method: "PUT",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
                body: JSON.stringify({
                    first_name: first,
                    middle_name: editMiddle.trim() || null,
                    last_name: last,
                    birth_date: editBirthDate.trim() || null,
                    address_line_1: editAddress1.trim() || null,
                    address_line_2: editAddress2.trim() || null,
                    city: editCity.trim() || null,
                    state: editState.trim() || null,
                    postal_code: editPostalCode.trim() || null,
                    country: editCountry.trim() || null,
                }),
            });
            const data = (await res.json().catch(() => ({}))) as { client?: unknown; message?: string };
            if (res.ok && data.client) {
                toaster.success({ title: "Client details updated." });
                router.reload();
                return;
            }
            editError = data.message ?? "Failed to update client details.";
            toaster.error({ title: editError });
        } catch {
            editError = "Network error. Please try again.";
            toaster.error({ title: editError });
        } finally {
            savingDetails = false;
        }
    }
</script>

<svelte:head>
    <title>{clientDisplayName} — Client — FlexiQueue</title>
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
                {#if isAdmin}
                    <button
                        type="button"
                        class="btn btn-sm preset-filled-error-500 flex items-center gap-1.5 shadow-sm"
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
                    {clientDisplayName}
                </h1>
                <div class="mt-1 flex flex-col gap-0.5 text-sm sm:flex-row sm:items-baseline sm:gap-2">
                    <span class="text-surface-600">
                        Birth date:
                        <span class="font-medium text-surface-950">
                            {client.birth_date ?? "—"}
                        </span>
                    </span>
                    <span class="hidden text-surface-300 sm:inline">•</span>
                    <span class="text-surface-600">
                        Created {formatDate(client.created_at)}
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="max-w-2xl">
        <section
            class="rounded-container bg-surface-50/90 border border-surface-200 p-4 shadow-sm"
        >
            <div class="flex items-center justify-between gap-2 mb-3">
                <h2 class="text-sm font-semibold text-surface-900">
                    Client details
                </h2>
                {#if isAdmin || isSupervisor}
                    <button
                        type="button"
                        class="btn btn-sm preset-outlined bg-surface-50 text-surface-700 flex items-center gap-1.5"
                        onclick={openEditDetails}
                        data-testid="admin-client-edit-details"
                    >
                        <Pencil class="w-3.5 h-3.5" />
                        Edit details
                    </button>
                {/if}
            </div>
            <dl class="space-y-3 text-sm">
                <div class="flex flex-col gap-0.5">
                    <dt class="text-surface-500">First name</dt>
                    <dd class="font-medium text-surface-950">{client.first_name}</dd>
                </div>
                {#if client.middle_name}
                    <div class="flex flex-col gap-0.5">
                        <dt class="text-surface-500">Middle name</dt>
                        <dd class="font-medium text-surface-950">{client.middle_name}</dd>
                    </div>
                {/if}
                <div class="flex flex-col gap-0.5">
                    <dt class="text-surface-500">Last name</dt>
                    <dd class="font-medium text-surface-950">{client.last_name}</dd>
                </div>
                <div class="flex flex-col gap-0.5">
                    <dt class="text-surface-500">Birth date</dt>
                    <dd class="font-medium text-surface-950">
                        {client.birth_date ?? "—"}
                    </dd>
                </div>
                {#if client.address_line_1 || client.city || client.postal_code || client.country}
                    <div class="flex flex-col gap-0.5">
                        <dt class="text-surface-500">Address</dt>
                        <dd class="font-medium text-surface-950">
                            {[client.address_line_1, client.address_line_2, [client.city, client.state].filter(Boolean).join(", ").trim(), client.postal_code, client.country].filter(Boolean).join(", ").trim() || "—"}
                        </dd>
                    </div>
                {/if}
                <div class="flex flex-col gap-0.5">
                    <dt class="text-surface-500">Created</dt>
                    <dd class="font-medium text-surface-950">
                        {formatDate(client.created_at)}
                    </dd>
                </div>
                <div class="flex flex-col gap-0.5 pt-2 border-t border-surface-200">
                    <dt class="text-surface-500">Phone</dt>
                    <dd class="flex flex-wrap items-center gap-2">
                        {#if client.mobile_masked}
                            <span class="font-mono text-sm text-surface-900">{client.mobile_masked}</span>
                        {:else}
                            <span class="text-surface-500 text-sm">No phone stored</span>
                        {/if}
                        {#if client.mobile_masked && isAdmin}
                            <button
                                type="button"
                                class="btn btn-xs preset-outlined bg-surface-50 text-surface-600 flex items-center gap-1"
                                onclick={openReveal}
                                disabled={submitting}
                                data-testid="admin-client-reveal-phone"
                            >
                                <Eye class="w-3 h-3" />
                                Reveal
                            </button>
                        {/if}
                        {#if isAdmin || isSupervisor}
                            <button
                                type="button"
                                class="btn btn-xs preset-tonal text-surface-700 flex items-center gap-1"
                                onclick={openUpdate}
                                disabled={updating}
                                data-testid="admin-client-update-phone"
                            >
                                Update phone
                            </button>
                        {/if}
                    </dd>
                    <p class="text-[11px] text-surface-500 mt-0.5">
                        Masked for privacy. Reveal is admin-only and audited.
                        {#if isSupervisor && !isAdmin}
                            <span class="inline-flex items-center gap-0.5"><Lock class="w-3 h-3" /> Reveal requires admin.</span>
                        {/if}
                    </p>
                </div>
            </dl>
        </section>
    </div>

    <Modal
        open={showRevealModal}
        title="Reveal phone"
        onClose={closeRevealModal}
    >
        {#snippet children()}
            <div class="space-y-4">
                <p class="text-sm text-surface-700">
                    Revealing will decrypt and show the full phone number. This
                    action is audited.
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
                                >Details</span
                            ></label
                        >
                        <textarea
                            id="reveal-other-reason"
                            class="textarea rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 text-sm shadow-sm min-h-[72px]"
                            bind:value={otherReason}
                            placeholder="Brief reason for reveal."
                        ></textarea>
                    </div>
                {/if}

                {#if revealError}
                    <div
                        class="rounded-container border border-error-200 bg-error-50 text-error-800 text-xs px-3 py-2"
                    >
                        {revealError}
                    </div>
                {/if}

                {#if revealedMobile}
                    <div
                        class="rounded-container border border-warning-200 bg-warning-50 text-warning-900 px-3 py-3"
                    >
                        <div class="text-xs font-semibold uppercase tracking-wide mb-1">
                            Phone (handle with care)
                        </div>
                        <div class="font-mono text-sm break-all">
                            {revealedMobile}
                        </div>
                    </div>
                    <button
                        type="button"
                        class="btn preset-tonal w-full"
                        onclick={closeRevealModal}
                    >
                        Close
                    </button>
                {:else}
                    <div class="flex gap-2">
                        <button
                            type="button"
                            class="btn preset-tonal flex-1"
                            onclick={closeRevealModal}
                            disabled={submitting}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            class="btn preset-filled-primary-500 flex-1"
                            onclick={confirmRevealPhone}
                            disabled={submitting || !reasonPreset}
                        >
                            {submitting ? "Revealing…" : "Reveal"}
                        </button>
                    </div>
                {/if}
            </div>
        {/snippet}
    </Modal>

    <Modal open={showUpdateModal} title="Update phone" onClose={closeUpdateModal}>
        {#snippet children()}
            <div class="space-y-4">
                <p class="text-sm text-surface-700">
                    Replace the stored phone number. This action is audited.
                </p>
                <div class="form-control">
                    <label class="label" for="update-mobile"
                        ><span class="label-text text-sm font-medium"
                            >New phone number</span
                        ></label
                    >
                    <input
                        id="update-mobile"
                        type="tel"
                        inputmode="numeric"
                        class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 text-sm shadow-sm"
                        bind:value={updateMobile}
                        placeholder="e.g. 09171234567"
                    />
                </div>
                <div class="form-control">
                    <label class="label" for="update-reason"
                        ><span class="label-text text-sm font-medium"
                            >Reason (required)</span
                        ></label
                    >
                    <input
                        id="update-reason"
                        type="text"
                        class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 text-sm shadow-sm"
                        bind:value={updateReason}
                        placeholder="e.g. Client provided new number"
                    />
                </div>
                {#if updateError}
                    <div
                        class="rounded-container border border-error-200 bg-error-50 text-error-800 text-xs px-3 py-2"
                    >
                        {updateError}
                    </div>
                {/if}
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="btn preset-tonal flex-1"
                        onclick={closeUpdateModal}
                        disabled={updating}
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="btn preset-filled-primary-500 flex-1"
                        onclick={confirmUpdatePhone}
                        disabled={updating || !updateMobile.trim() || !updateReason.trim()}
                    >
                        {updating ? "Updating…" : "Update"}
                    </button>
                </div>
            </div>
        {/snippet}
    </Modal>

    <Modal open={showEditModal} title="Edit client details" onClose={closeEditModal}>
        {#snippet children()}
            <div class="space-y-4 max-h-[70vh] overflow-y-auto">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="form-control sm:col-span-2 sm:grid sm:grid-cols-3 gap-2">
                        <div>
                            <label class="label py-0" for="edit-first"><span class="label-text text-xs font-medium">First name *</span></label>
                            <input id="edit-first" type="text" class="input input-sm rounded-container border border-surface-200 w-full bg-surface-50" bind:value={editFirst} placeholder="First name" />
                        </div>
                        <div>
                            <label class="label py-0" for="edit-middle"><span class="label-text text-xs font-medium">Middle name</span></label>
                            <input id="edit-middle" type="text" class="input input-sm rounded-container border border-surface-200 w-full bg-surface-50" bind:value={editMiddle} placeholder="Middle" />
                        </div>
                        <div>
                            <label class="label py-0" for="edit-last"><span class="label-text text-xs font-medium">Last name *</span></label>
                            <input id="edit-last" type="text" class="input input-sm rounded-container border border-surface-200 w-full bg-surface-50" bind:value={editLast} placeholder="Last name" />
                        </div>
                    </div>
                    <div class="form-control">
                        <label class="label py-0" for="edit-birth"><span class="label-text text-xs font-medium">Birth date</span></label>
                        <input id="edit-birth" type="date" class="input input-sm rounded-container border border-surface-200 w-full bg-surface-50" bind:value={editBirthDate} />
                    </div>
                </div>
                <div class="form-control">
                    <label class="label py-0" for="edit-address1"><span class="label-text text-xs font-medium">Address line 1</span></label>
                    <input id="edit-address1" type="text" class="input input-sm rounded-container border border-surface-200 w-full bg-surface-50" bind:value={editAddress1} placeholder="Street, number" />
                </div>
                <div class="form-control">
                    <label class="label py-0" for="edit-address2"><span class="label-text text-xs font-medium">Address line 2</span></label>
                    <input id="edit-address2" type="text" class="input input-sm rounded-container border border-surface-200 w-full bg-surface-50" bind:value={editAddress2} placeholder="Apt, building" />
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div class="form-control">
                        <label class="label py-0" for="edit-city"><span class="label-text text-xs font-medium">City</span></label>
                        <input id="edit-city" type="text" class="input input-sm rounded-container border border-surface-200 w-full bg-surface-50" bind:value={editCity} placeholder="City" />
                    </div>
                    <div class="form-control">
                        <label class="label py-0" for="edit-state"><span class="label-text text-xs font-medium">State / Province</span></label>
                        <input id="edit-state" type="text" class="input input-sm rounded-container border border-surface-200 w-full bg-surface-50" bind:value={editState} placeholder="State" />
                    </div>
                    <div class="form-control">
                        <label class="label py-0" for="edit-postal"><span class="label-text text-xs font-medium">Postal code</span></label>
                        <input id="edit-postal" type="text" class="input input-sm rounded-container border border-surface-200 w-full bg-surface-50" bind:value={editPostalCode} placeholder="Postal" />
                    </div>
                    <div class="form-control">
                        <label class="label py-0" for="edit-country"><span class="label-text text-xs font-medium">Country</span></label>
                        <input id="edit-country" type="text" class="input input-sm rounded-container border border-surface-200 w-full bg-surface-50" bind:value={editCountry} placeholder="Country" />
                    </div>
                </div>
                {#if editError}
                    <div class="rounded-container border border-error-200 bg-error-50 text-error-800 text-xs px-3 py-2">{editError}</div>
                {/if}
                <div class="flex gap-2 pt-1">
                    <button type="button" class="btn preset-tonal flex-1" onclick={closeEditModal} disabled={savingDetails}>
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="btn preset-filled-primary-500 flex-1"
                        onclick={confirmSaveDetails}
                        disabled={savingDetails || !editFirst.trim() || !editLast.trim()}
                    >
                        {savingDetails ? "Saving…" : "Save"}
                    </button>
                </div>
            </div>
        {/snippet}
    </Modal>

    <Modal
        open={showDeleteClientModal}
        title="Delete client"
        onClose={closeDeleteClientModal}
    >
        {#snippet children()}
            <div class="space-y-3">
                <p class="text-sm text-surface-700">
                    Delete client <span class="font-semibold">{clientDisplayName}</span>?
                    This cannot be undone.
                </p>
                {#if deleteClientError}
                    <p class="text-sm text-error-700">{deleteClientError}</p>
                {/if}
                <div class="flex gap-2 pt-1">
                    <button
                        type="button"
                        class="btn preset-tonal flex-1"
                        onclick={closeDeleteClientModal}
                        disabled={deletingClient}
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="btn preset-filled-primary-500 flex-1 bg-error-600 hover:bg-error-700"
                        onclick={confirmDeleteClient}
                        disabled={deletingClient}
                        data-testid="admin-client-delete-confirm"
                    >
                        {deletingClient ? "Deleting…" : "Delete"}
                    </button>
                </div>
            </div>
        {/snippet}
    </Modal>
</AdminLayout>
