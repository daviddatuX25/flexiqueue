<script lang="ts">
    import AdminLayout from "../../../Layouts/AdminLayout.svelte";
    import Modal from "../../../Components/Modal.svelte";
    import AdminTable from "../../../Components/AdminTable.svelte";
    import { Link, router, usePage } from "@inertiajs/svelte";
    import { get } from "svelte/store";
    import { toaster } from "../../../lib/toaster.js";
    import { Users as UsersIcon, Search as SearchIcon, Eye, Trash2 } from "lucide-svelte";

    interface ClientListItem {
        id: number;
        name: string;
        birth_year: number | null;
        id_documents_count: number;
        created_at: string | null;
    }

    let {
        clients = [],
        search: initialSearch = "",
    }: {
        clients: ClientListItem[];
        search?: string | null;
    } = $props();

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
                    Search and inspect client records and attached ID documents. ID
                    numbers are always redacted here; full values require an
                    explicit admin-only reveal flow.
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
        <div class="w-full sm:w-auto sm:min-w-[200px]"></div>
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
        <!-- Desktop table -->
        <AdminTable class="mt-6 hidden md:block" compact={true}>
            {#snippet head()}
                <tr>
                    <th>Name</th>
                    <th>Birth year</th>
                    <th>ID documents</th>
                    <th>Created</th>
                    <th class="text-center">Actions</th>
                </tr>
            {/snippet}
            {#snippet body()}
                {#each clients as client (client.id)}
                    <tr>
                        <td class="font-medium text-surface-900">
                            {client.name}
                        </td>
                        <td class="text-surface-700">
                            {client.birth_year ?? "—"}
                        </td>
                        <td class="text-surface-700">
                            {client.id_documents_count}
                        </td>
                        <td class="text-surface-700">
                            {formatDate(client.created_at)}
                        </td>
                        <td class="text-center">
                            <div
                                class="flex justify-center items-center gap-2 flex-wrap"
                            >
                                <Link
                                    href={`/admin/clients/${client.id}`}
                                    class="btn btn-sm preset-outlined bg-surface-50 text-surface-700 flex items-center gap-1.5 shadow-sm px-3 py-1.5 whitespace-nowrap"
                                >
                                    <Eye class="w-3.5 h-3.5" />
                                    View
                                </Link>
                                {#if isAdmin}
                                    <button
                                        type="button"
                                        class="btn btn-sm preset-filled-error-500 hover:preset-filled-error-600 flex items-center justify-center w-10 shrink-0 shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
                                        onclick={() => openDelete(client)}
                                        data-testid={"admin-client-delete-" + client.id}
                                        aria-label="Delete client"
                                        title="Delete client"
                                    >
                                        <Trash2 class="w-4 h-4" />
                                    </button>
                                {/if}
                            </div>
                        </td>
                    </tr>
                {/each}
            {/snippet}
        </AdminTable>

        <!-- Mobile cards -->
        <div class="grid grid-cols-1 gap-4 mt-4 md:hidden">
            {#each clients as client (client.id)}
                <div
                    class="card bg-surface-50 border border-surface-200 shadow-sm p-4 flex flex-col gap-3"
                >
                    <div
                        class="flex items-start justify-between gap-3 flex-wrap"
                    >
                        <div>
                            <span
                                class="font-semibold text-surface-950 block text-base"
                                >{client.name}</span
                            >
                            <span class="text-xs text-surface-500 block mt-0.5">
                                Created {formatDate(client.created_at)}
                            </span>
                        </div>
                        <div class="flex flex-col items-end gap-1 text-sm">
                            <span class="text-surface-600">
                                Birth year:
                                <span class="font-medium text-surface-950">
                                    {client.birth_year ?? "—"}
                                </span>
                            </span>
                            <span class="text-surface-600">
                                ID documents:
                                <span class="font-medium text-surface-950">
                                    {client.id_documents_count}
                                </span>
                            </span>
                        </div>
                    </div>
                    <div
                        class="pt-2 border-t border-surface-200 flex justify-end"
                    >
                        <div
                            class="flex items-center gap-2 w-full xs:w-auto"
                        >
                            <Link
                                href={`/admin/clients/${client.id}`}
                                class="btn btn-sm preset-outlined bg-surface-50 text-surface-700 flex items-center justify-center gap-1.5 shadow-sm flex-1 xs:flex-none"
                            >
                                <Eye class="w-3.5 h-3.5" />
                                View details
                            </Link>
                            {#if isAdmin}
                                <button
                                    type="button"
                                    class="btn btn-sm preset-filled-error-500 hover:preset-filled-error-600 flex items-center justify-center touch-target-h w-10 shrink-0 shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
                                    onclick={() => openDelete(client)}
                                    data-testid={"admin-client-delete-mobile-" + client.id}
                                    aria-label="Delete client"
                                >
                                    <Trash2 class="w-4 h-4" />
                                </button>
                            {/if}
                        </div>
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
                        Delete client <span class="font-semibold">{deleteTarget.name}</span>? This cannot be undone.
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

