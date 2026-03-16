<script lang="ts">
    import AdminLayout from "../../../Layouts/AdminLayout.svelte";
    import Modal from "../../../Components/Modal.svelte";
    import AdminTable from "../../../Components/AdminTable.svelte";
    import CreateRegistrationModal from "../../../Components/CreateRegistrationModal.svelte";
    import type { CreateRegistrationPayload } from "../../../Components/CreateRegistrationModal.svelte";
    import { Link, router, usePage } from "@inertiajs/svelte";
    import { get } from "svelte/store";
    import { toaster } from "../../../lib/toaster.js";
    import { clientDisplayName } from "../../../lib/clientDisplayName.js";
    import { Users as UsersIcon, Search as SearchIcon, Eye, Trash2, Plus } from "lucide-svelte";

    interface ClientListItem {
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
        clients = [],
        search: initialSearch = "",
        programs = [],
    }: {
        clients: ClientListItem[];
        search?: string | null;
        programs?: { id: number; name: string }[];
    } = $props();

    let showCreateRegModal = $state(false);

    let searchTerm = $state("");
    $effect(() => {
        searchTerm = initialSearch ?? "";
    });
    const page = usePage();
    const userRole = $derived(
        (get(page).props as { auth?: { user?: { role?: string } } } | undefined)
            ?.auth?.user?.role ?? null,
    );
    const isAdmin = $derived(userRole === "admin");

    let showDeleteModal = $state(false);
    let deleteTarget = $state<ClientListItem | null>(null);
    let deleting = $state(false);
    let deleteErrorMessage = $state<string | null>(null);

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

    function openDelete(client: ClientListItem) {
        if (!isAdmin) return;
        deleteTarget = client;
        deleting = false;
        deleteErrorMessage = null;
        showDeleteModal = true;
    }

    function closeDeleteModal() {
        showDeleteModal = false;
        deleteTarget = null;
        deleting = false;
        deleteErrorMessage = null;
    }

    async function confirmDelete() {
        if (!deleteTarget || deleting) return;
        deleting = true;
        deleteErrorMessage = null;
        try {
            const res = await fetch(`/api/admin/clients/${deleteTarget.id}`, {
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
                closeDeleteModal();
                router.reload();
                return;
            }
            const msg =
                data.message ??
                (res.status === 409
                    ? "Cannot delete client due to audit log."
                    : "Failed to delete client.");
            deleteErrorMessage = msg;
            toaster.error({ title: msg });
        } catch {
            const msg = "Network error. Please try again.";
            deleteErrorMessage = msg;
            toaster.error({ title: msg });
        } finally {
            deleting = false;
        }
    }

    function formatDate(value: string | null): string {
        if (!value) return "—";
        try {
            const d = new Date(value);
            return new Intl.DateTimeFormat(undefined, {
                year: "numeric",
                month: "short",
                day: "2-digit",
            }).format(d);
        } catch {
            return value;
        }
    }

    function handleSearchSubmit(event: SubmitEvent) {
        event.preventDefault();
        router.visit("/admin/clients", {
            method: "get",
            data: {
                search: searchTerm.trim() || undefined,
            },
            preserveState: true,
            preserveScroll: true,
        });
    }

    async function submitCreateRegistration(payload: CreateRegistrationPayload): Promise<{ ok: boolean; message?: string }> {
        const token = getCsrfToken();
        const res = await fetch("/api/identity-registrations/direct", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": token,
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
            body: JSON.stringify(payload),
        });
        const data = (await res.json().catch(() => ({}))) as { message?: string };
        return { ok: res.ok, message: data?.message };
    }
</script>

<svelte:head>
    <title>Clients — FlexiQueue</title>
</svelte:head>

<AdminLayout>
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div class="flex-1 space-y-3">
            <div>
                <h1 class="text-2xl font-bold text-surface-950 flex items-center gap-2">
                    <UsersIcon class="w-6 h-6 text-primary-500" />
                    Clients
                </h1>
                <p class="mt-2 text-surface-600 max-w-3xl leading-relaxed">
                    Search and inspect client records. Phone numbers are masked; full
                    values require an explicit admin-only reveal flow.
                </p>
            </div>
            <form
                class="w-full max-w-lg"
                onsubmit={handleSearchSubmit}
            >
                <label
                    for="client-search"
                    class="label-text text-xs font-semibold uppercase tracking-wide text-surface-500 mb-1 block"
                >
                    Search by name
                </label>
                <div class="join w-full">
                    <div
                        class="join-item flex items-center gap-2 px-3 py-1 border border-surface-300 rounded-l-container bg-surface-50 w-full"
                    >
                        <SearchIcon class="w-4 h-4 my-2 text-surface-400 shrink-0" />
                        <input
                            type="text"
                            id="client-search"
                            class="input input-ghost !bg-transparent px-0 py-0 h-auto text-sm w-full focus:!outline-none focus:!ring-0 focus:!border-transparent"
                            bind:value={searchTerm}
                            placeholder="e.g. Maria Santos"
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
                onclick={() => (showCreateRegModal = true)}
                aria-label="Create registration"
            >
                <Plus class="w-4 h-4" /> Create registration
            </button>
        </div>
        <!-- Mobile FAB: circular icon-only bottom-right, above footer (per staff/tokens pattern) -->
        <button
            type="button"
            class="fixed bottom-[87px] right-[23px] z-50 flex md:hidden items-center justify-center w-14 h-14 rounded-full bg-primary-500 text-primary-contrast-500 shadow-lg hover:bg-primary-600 active:scale-95 transition-transform touch-manipulation"
            onclick={() => (showCreateRegModal = true)}
            aria-label="Create registration"
        >
            <Plus class="w-6 h-6" aria-hidden="true" />
        </button>
        <CreateRegistrationModal
            open={showCreateRegModal}
            onClose={() => (showCreateRegModal = false)}
            onSubmitSuccess={() => {
                toaster.success({ title: "Registration created." });
                router.reload();
            }}
            programs={programs}
            submitRequest={submitCreateRegistration}
            description="Create a client registration. Choose a program, then enter client details. The registration is created immediately."
        />
    </div>

    {#if clients.length === 0}
        <div
            role="status"
            aria-label="No clients found"
            class="rounded-container bg-surface-50 border border-surface-200 p-12 flex flex-col items-center justify-center text-center shadow-sm mt-6"
        >
            <div class="bg-surface-100 p-4 rounded-full text-surface-400 mb-4">
                <UsersIcon class="w-8 h-8" />
            </div>
            <h3 class="text-lg font-semibold text-surface-950">
                {initialSearch ? "No matching clients" : "No clients yet"}
            </h3>
            <p class="text-surface-600 max-w-sm mt-2">
                {#if initialSearch}
                    Try a different name or clear the search filters.
                {:else}
                    Client records will appear here after staff bind sessions to
                    identities.
                {/if}
            </p>
        </div>
        {:else}
        <!-- Table: visible from lg breakpoint -->
        <AdminTable class="mt-6 hidden lg:block" compact={true}>
            {#snippet head()}
                <tr>
                    <th>Name</th>
                    <th>Birth date</th>
                    <th>Phone</th>
                    <th>Created</th>
                    <th class="py-2 px-3 text-center text-surface-600 font-medium whitespace-nowrap">Actions</th>
                </tr>
            {/snippet}
            {#snippet body()}
                {#each clients as client (client.id)}
                    <tr>
                        <td class="font-medium text-surface-900">
                            {clientDisplayName(client)}
                        </td>
                        <td class="text-surface-700">
                            {client.birth_date ?? "—"}
                        </td>
                        <td class="text-surface-700">
                            {client.mobile_masked ?? '—'}
                        </td>
                        <td class="text-surface-700">
                            {formatDate(client.created_at)}
                        </td>
                        <td class="py-2 px-3 text-center align-middle">
                            <div
                                class="flex items-center justify-center gap-1.5 flex-wrap"
                            >
                                <Link
                                    href={`/admin/clients/${client.id}`}
                                    class="btn btn-sm preset-outlined bg-surface-50 text-surface-600 hover:bg-surface-50 flex items-center gap-1 min-h-[2rem] px-2.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    <Eye class="w-3.5 h-3.5" /> View
                                </Link>
                                {#if isAdmin}
                                    <button
                                        type="button"
                                        class="btn btn-sm preset-filled-error-500 hover:preset-filled-error-600 flex items-center gap-1 min-h-[2rem] px-2.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                        onclick={() => openDelete(client)}
                                        data-testid={"admin-client-delete-" + client.id}
                                        aria-label="Delete client"
                                        title="Delete client"
                                    >
                                        <Trash2 class="w-3.5 h-3.5" />
                                    </button>
                                {/if}
                            </div>
                        </td>
                    </tr>
                {/each}
            {/snippet}
        </AdminTable>

        <!-- Card layout: 1 col mobile, 2 col tablet; hidden on lg where table shows -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4 lg:hidden">
            {#each clients as client (client.id)}
                <div
                    class="card bg-surface-50 border border-surface-200 shadow-sm p-4 flex flex-col gap-3"
                >
                    <div class="space-y-2">
                        <span
                            class="font-semibold text-surface-950 block text-base"
                            >{clientDisplayName(client)}</span
                        >
                        <span class="text-xs text-surface-500 block">
                            Created {formatDate(client.created_at)}
                        </span>
                        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm items-baseline">
                            <dt class="text-surface-500">Birth date</dt>
                            <dd class="font-medium text-surface-950">
                                {client.birth_date ?? "—"}
                            </dd>
                            <dt class="text-surface-500">Phone</dt>
                            <dd class="font-medium text-surface-950 truncate min-w-0">
                                {client.mobile_masked ?? "—"}
                            </dd>
                        </dl>
                    </div>
                    <!-- Per UI/UX checklist: on mobile, View Details and Delete buttons equal size -->
                    <div
                        class="pt-2 border-t border-surface-200 grid gap-2 {isAdmin ? 'grid-cols-2' : 'grid-cols-1'}"
                    >
                        <Link
                            href={`/admin/clients/${client.id}`}
                            class="btn btn-sm preset-outlined bg-surface-50 text-surface-700 flex items-center justify-center gap-1.5 shadow-sm w-full min-w-0"
                        >
                            <Eye class="w-3.5 h-3.5 shrink-0" />
                            View details
                        </Link>
                        {#if isAdmin}
                            <button
                                type="button"
                                class="btn btn-sm preset-filled-error-500 hover:preset-filled-error-600 flex items-center justify-center gap-1.5 shadow-sm w-full min-w-0 disabled:opacity-50 disabled:cursor-not-allowed"
                                onclick={() => openDelete(client)}
                                data-testid={"admin-client-delete-mobile-" + client.id}
                                aria-label="Delete client"
                            >
                                <Trash2 class="w-3.5 h-3.5 shrink-0" />
                                Delete
                            </button>
                        {/if}
                    </div>
                </div>
            {/each}
        </div>
    {/if}

    <Modal open={showDeleteModal} title="Delete client" onClose={closeDeleteModal}>
        {#snippet children()}
            {#if deleteTarget}
                <div class="space-y-3">
                    <p class="text-sm text-surface-700">
                        Delete client <span class="font-semibold">{clientDisplayName(deleteTarget)}</span>? This cannot be undone.
                    </p>
                    {#if deleteErrorMessage}
                        <p class="text-sm text-error-700">{deleteErrorMessage}</p>
                    {/if}
                    <div class="flex gap-2 pt-1">
                        <button
                            type="button"
                            class="btn preset-tonal flex-1"
                            onclick={closeDeleteModal}
                            disabled={deleting}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            class="btn preset-filled-primary-500 flex-1 bg-error-600 hover:bg-error-700"
                            onclick={confirmDelete}
                            disabled={deleting}
                            data-testid="admin-client-delete-confirm"
                        >
                            {deleting ? "Deleting…" : "Delete"}
                        </button>
                    </div>
                </div>
            {/if}
        {/snippet}
    </Modal>
</AdminLayout>

