<script lang="ts">
    import AdminLayout from "../../../Layouts/AdminLayout.svelte";
    import Modal from "../../../Components/Modal.svelte";
    import { get } from "svelte/store";
    import { usePage } from "@inertiajs/svelte";
    import {
        Ticket,
        Plus,
        Printer,
        Search,
        Filter,
        Trash2,
        Ban,
        CheckCircle2,
        PlayCircle,
        XCircle,
        ChevronDown,
        Pencil,
    } from "lucide-svelte";

    interface TokenItem {
        id: number;
        physical_id: string;
        qr_code_hash: string;
        status: string;
        pronounce_as?: string;
    }

    interface PrintSettingsApi {
        cards_per_page?: number;
        paper?: string;
        orientation?: string;
        show_hint?: boolean;
        show_cut_lines?: boolean;
        logo_url?: string;
        footer_text?: string;
        bg_image_url?: string;
    }

    let tokens = $state<TokenItem[]>([]);
    let loading = $state(true);
    let submitting = $state(false);
    let error = $state("");
    let showBatchModal = $state(false);
    let filterStatus = $state("");
    let searchQuery = $state("");
    // Batch form
    let batchPrefix = $state("A");
    let batchStart = $state(1);
    let batchCount = $state(50);
    /** Plan: pronounce alias as letters (e.g. A 3) or word (e.g. A3) for TTS. */
    let batchPronounceAs = $state<"letters" | "word">("letters");
    /** Generate TTS audio for offline playback (server generates in background after create). */
    let batchGenerateTts = $state(false);
    // Selection for bulk actions
    let selectedIds = $state<Set<number>>(new Set());
    let selectAllCheckbox = $state<HTMLInputElement | null>(null);
    let selectAllCheckboxMobile = $state<HTMLInputElement | null>(null);
    // Edit single token modal
    let editToken = $state<TokenItem | null>(null);
    let editPronounceAs = $state<"letters" | "word">("letters");

    // Print modal (uniform flow: select template then print)
    let showPrintModal = $state(false);
    let printSettings = $state({
        cards_per_page: 6,
        paper: "a4",
        orientation: "portrait",
        show_hint: true,
        show_cut_lines: true,
        logo_url: "" as string,
        footer_text: "" as string,
        bg_image_url: "" as string,
    });

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
        data?: {
            tokens?: TokenItem[];
            token?: TokenItem;
            created?: number;
            print_settings?: PrintSettingsApi;
        };
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
        return { ok: res.ok, data, message: data?.message };
    }

    function buildTokensUrl(): string {
        const params = new URLSearchParams();
        if (filterStatus) params.set("status", filterStatus);
        if (searchQuery.trim()) params.set("search", searchQuery.trim());
        const q = params.toString();
        return q ? `/api/admin/tokens?${q}` : "/api/admin/tokens";
    }

    async function fetchTokens() {
        loading = true;
        const { ok, data } = await api("GET", buildTokensUrl());
        loading = false;
        if (ok && data?.tokens) {
            tokens = data.tokens;
        } else {
            tokens = [];
        }
    }

    async function onFilterApply() {
        await fetchTokens();
    }

    function openBatchModal() {
        batchPrefix = "A";
        batchStart = 1;
        batchCount = 50;
        batchPronounceAs = "letters";
        batchGenerateTts = false;
        error = "";
        showBatchModal = true;
    }

    function closeBatchModal() {
        showBatchModal = false;
        error = "";
    }

    async function handleBatchCreate() {
        if (batchCount < 1 || batchCount > 500) return;
        submitting = true;
        error = "";
        const { ok, data, message } = await api(
            "POST",
            "/api/admin/tokens/batch",
            {
                prefix: batchPrefix.trim(),
                count: batchCount,
                start_number: batchStart,
                pronounce_as: batchPronounceAs,
                generate_tts: batchGenerateTts,
            },
        );
        submitting = false;
        if (ok) {
            closeBatchModal();
            await fetchTokens();
        } else {
            error =
                message ??
                (data && "errors" in data
                    ? "Validation failed."
                    : "Failed to create tokens.");
        }
    }

    async function setTokenStatus(token: TokenItem, status: string) {
        submitting = true;
        error = "";
        const { ok, data, message } = await api(
            "PUT",
            `/api/admin/tokens/${token.id}`,
            { status },
        );
        submitting = false;
        if (ok && data?.token) {
            tokens = tokens.map((t) =>
                t.id === token.id ? { ...t, status: data.token.status } : t,
            );
        } else {
            error = message ?? "Failed to update status.";
        }
    }

    function openEditModal(token: TokenItem) {
        editToken = token;
        editPronounceAs = (token.pronounce_as === "word" ? "word" : "letters") as "letters" | "word";
        error = "";
    }

    function closeEditModal() {
        editToken = null;
    }

    async function saveEdit() {
        if (!editToken) return;
        submitting = true;
        error = "";
        const { ok, data, message } = await api(
            "PUT",
            `/api/admin/tokens/${editToken.id}`,
            { pronounce_as: editPronounceAs },
        );
        submitting = false;
        if (ok && data?.token) {
            tokens = tokens.map((t) =>
                t.id === editToken!.id ? { ...t, pronounce_as: data.token.pronounce_as } : t,
            );
            closeEditModal();
        } else {
            error = message ?? "Failed to update token.";
        }
    }

    // Selection (available and deactivated can be bulk deleted/printed; in_use cannot)
    const selectableForDelete = $derived(
        tokens.filter(
            (t) => t.status === "available" || t.status === "deactivated",
        ),
    );
    const allSelected = $derived(
        selectableForDelete.length > 0 &&
            selectableForDelete.every((t) => selectedIds.has(t.id)),
    );
    const someSelected = $derived(selectedIds.size > 0);
    const selectedForPrint = $derived(
        tokens.filter((t) => selectedIds.has(t.id)),
    );

    function toggleSelectAll() {
        if (allSelected) {
            selectedIds = new Set();
        } else {
            selectedIds = new Set(selectableForDelete.map((t) => t.id));
        }
    }

    function toggleSelect(id: number) {
        const next = new Set(selectedIds);
        if (next.has(id)) next.delete(id);
        else next.add(id);
        selectedIds = next;
    }

    function getPrintUrl(ids: number[], opts?: typeof printSettings): string {
        if (ids.length === 0) return "/admin/tokens/print";
        const s = opts ?? printSettings;
        const params = new URLSearchParams();
        params.set("ids", ids.join(","));
        params.set("cards_per_page", String(s.cards_per_page));
        params.set("paper", s.paper);
        params.set("orientation", s.orientation);
        params.set("hint", s.show_hint ? "1" : "0");
        params.set("cutlines", s.show_cut_lines ? "1" : "0");
        if (s.logo_url?.trim()) params.set("logo_url", s.logo_url.trim());
        if (s.footer_text?.trim())
            params.set("footer_text", s.footer_text.trim());
        if (s.bg_image_url?.trim())
            params.set("bg_image_url", s.bg_image_url.trim());
        return `/admin/tokens/print?${params.toString()}`;
    }

    let printTargetIds = $state<number[]>([]);

    function openPrintModal(ids: number[]) {
        printTargetIds = ids;
        printSettingsSaved = false;
        showPrintModal = true;
        fetchPrintSettings();
    }

    function closePrintModal() {
        showPrintModal = false;
    }

    async function fetchPrintSettings() {
        const { ok, data } = await api("GET", "/api/admin/print-settings");
        if (ok && data?.print_settings) {
            const s = data.print_settings;
            printSettings = {
                cards_per_page: s.cards_per_page ?? 6,
                paper: s.paper ?? "a4",
                orientation: s.orientation ?? "portrait",
                show_hint: s.show_hint !== false,
                show_cut_lines: s.show_cut_lines !== false,
                logo_url: s.logo_url ?? "",
                footer_text: s.footer_text ?? "",
                bg_image_url: s.bg_image_url ?? "",
            };
        }
    }

    function doPrint() {
        const url = getPrintUrl(printTargetIds);
        window.open(url, "_blank", "noopener,noreferrer");
        closePrintModal();
    }

    let printSettingsSaved = $state(false);

    async function savePrintSettings() {
        submitting = true;
        error = "";
        printSettingsSaved = false;
        const { ok, data, message } = await api(
            "PUT",
            "/api/admin/print-settings",
            {
                cards_per_page: printSettings.cards_per_page,
                paper: printSettings.paper,
                orientation: printSettings.orientation,
                show_hint: printSettings.show_hint,
                show_cut_lines: printSettings.show_cut_lines,
                logo_url: printSettings.logo_url.trim() || null,
                footer_text: printSettings.footer_text.trim() || null,
                bg_image_url: printSettings.bg_image_url.trim() || null,
            },
        );
        submitting = false;
        if (ok && data?.print_settings) {
            const s = data.print_settings;
            printSettings = {
                cards_per_page: s.cards_per_page ?? 6,
                paper: s.paper ?? "a4",
                orientation: s.orientation ?? "portrait",
                show_hint: s.show_hint !== false,
                show_cut_lines: s.show_cut_lines !== false,
                logo_url: s.logo_url ?? "",
                footer_text: s.footer_text ?? "",
                bg_image_url: s.bg_image_url ?? "",
            };
            printSettingsSaved = true;
        } else {
            error = message ?? "Failed to save settings.";
        }
    }

    const selectedAvailableCount = $derived(
        tokens.filter((t) => selectedIds.has(t.id) && t.status === "available")
            .length,
    );

    async function handleBatchDeactivate() {
        const toDeactivate = tokens.filter(
            (t) => selectedIds.has(t.id) && t.status === "available",
        );
        if (toDeactivate.length === 0) return;
        if (
            !confirm(
                `Deactivate ${toDeactivate.length} token(s)? They will no longer be available for binding until reactivated.`,
            )
        )
            return;
        submitting = true;
        error = "";
        let failed = 0;
        for (const token of toDeactivate) {
            const { ok, message: msg } = await api(
                "PUT",
                `/api/admin/tokens/${token.id}`,
                { status: "deactivated" },
            );
            if (!ok) {
                failed++;
                error = msg ?? "Failed to deactivate some tokens.";
            }
        }
        submitting = false;
        if (failed === 0) {
            selectedIds = new Set();
            await fetchTokens();
        }
    }

    async function handleBatchDelete() {
        const ids = [...selectedIds];
        if (ids.length === 0) return;
        if (
            !confirm(
                `Delete ${ids.length} token(s)? They will be soft-deleted and can no longer be used.`,
            )
        )
            return;
        submitting = true;
        error = "";
        const { ok, data, message } = await api(
            "POST",
            "/api/admin/tokens/batch-delete",
            { ids },
        );
        submitting = false;
        if (ok) {
            selectedIds = new Set();
            await fetchTokens();
        } else {
            error = message ?? "Failed to delete tokens.";
        }
    }

    async function handleDeleteToken(token: TokenItem) {
        if (token.status === "in_use") {
            error = "Cannot delete token in use.";
            return;
        }
        if (
            !confirm(
                `Delete token ${token.physical_id}? It will be soft-deleted.`,
            )
        )
            return;
        submitting = true;
        error = "";
        const { ok, message } = await api(
            "DELETE",
            `/api/admin/tokens/${token.id}`,
        );
        submitting = false;
        if (ok) {
            tokens = tokens.filter((t) => t.id !== token.id);
        } else {
            error = message ?? "Failed to delete token.";
        }
    }

    // Load tokens on mount
    $effect(() => {
        fetchTokens();
    });

    // Sync select-all checkbox indeterminate state
    $effect(() => {
        const ind = someSelected && !allSelected;
        if (selectAllCheckbox) selectAllCheckbox.indeterminate = ind;
        if (selectAllCheckboxMobile)
            selectAllCheckboxMobile.indeterminate = ind;
    });
</script>

<svelte:head>
    <title>Tokens — FlexiQueue</title>
</svelte:head>

<AdminLayout>
    <div class="flex flex-col gap-6">
        <div
            class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4"
        >
            <div>
                <h1
                    class="text-2xl font-bold text-surface-950 flex items-center gap-2"
                >
                    <Ticket class="w-6 h-6 text-primary-500" />
                    Token Management
                </h1>
                <p class="mt-2 text-surface-600 max-w-3xl leading-relaxed">
                    Manage and print physical tokens with QR codes. Combine with
                    stations to direct users.
                </p>
            </div>
            <div
                class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full sm:w-auto mt-2 sm:mt-0"
            >
                <button
                    type="button"
                    class="btn preset-outlined bg-surface-50 text-surface-700 hover:bg-surface-50 flex justify-center items-center gap-2 w-full sm:w-auto shadow-sm transition-colors"
                    onclick={() => openPrintModal([])}
                >
                    <Printer class="w-4 h-4" /> Print settings
                </button>
                <button
                    type="button"
                    class="btn preset-filled-primary-500 flex justify-center items-center gap-2 w-full sm:w-auto shadow-sm transition-transform active:scale-95"
                    onclick={openBatchModal}
                >
                    <Plus class="w-4 h-4" /> Create Batch
                </button>
            </div>
        </div>

        <!-- Bulk actions: always visible; buttons disabled when no selection -->
        <div
            class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 rounded-container border border-surface-200 bg-surface-50 p-3 sm:p-4 shadow-sm {someSelected ? 'border-primary-200 bg-primary-50/50' : ''}"
            role="toolbar"
            aria-label="Bulk actions"
        >
                <div class="flex items-center gap-3">
                    {#if someSelected}
                        <span
                            class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-primary-500 text-white text-xs font-bold shadow-sm"
                        >
                            {selectedIds.size}
                        </span>
                        <span class="text-sm font-semibold text-surface-900">
                            token{selectedIds.size > 1 ? "s" : ""} selected
                        </span>
                    {:else}
                        <span class="text-sm text-surface-600">
                            Select tokens below for bulk print, deactivate, or delete.
                        </span>
                    {/if}
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-medium text-surface-600">
                        {someSelected ? `${selectedIds.size} selected` : "Select tokens for bulk actions"}
                    </span>
                    <button
                        type="button"
                        class="btn btn-sm preset-filled-primary-500 flex items-center gap-1.5 shadow-sm min-h-[2.25rem] disabled:opacity-50 disabled:cursor-not-allowed"
                        onclick={() => openPrintModal([...selectedIds])}
                        disabled={!someSelected || submitting}
                        title={!someSelected ? "Select tokens to print" : "Print selected"}
                    >
                        <Printer class="w-3.5 h-3.5" /> Print
                    </button>
                    <button
                        type="button"
                        class="btn btn-sm preset-tonal text-surface-700 hover:text-surface-950 flex items-center gap-1.5 shadow-sm transition-colors min-h-[2.25rem] disabled:opacity-50 disabled:cursor-not-allowed"
                        onclick={handleBatchDeactivate}
                        disabled={submitting || selectedAvailableCount === 0}
                        title={selectedAvailableCount === 0
                            ? "Select available tokens to deactivate"
                            : `Deactivate ${selectedAvailableCount} token(s)`}
                    >
                        <Ban class="w-3.5 h-3.5" />
                        {submitting ? "Deactivating…" : "Deactivate"}
                    </button>
                    <button
                        type="button"
                        class="btn btn-sm preset-outlined bg-surface-50 text-error-600 hover:bg-error-50 border-error-200 flex items-center gap-1.5 shadow-sm transition-colors min-h-[2.25rem] disabled:opacity-50 disabled:cursor-not-allowed"
                        onclick={handleBatchDelete}
                        disabled={submitting ||
                            selectedForPrint.some((t) => t.status === "in_use") ||
                            !someSelected}
                        title={selectedForPrint.some((t) => t.status === "in_use")
                            ? "Cannot delete tokens in use. Deselect them first."
                            : !someSelected ? "Select tokens to delete" : "Delete selected"}
                    >
                        <Trash2 class="w-3.5 h-3.5" />
                        {submitting ? "Deleting…" : "Delete"}
                    </button>
                    {#if someSelected}
                        <button
                            type="button"
                            class="btn btn-sm preset-tonal text-surface-600 hover:text-surface-900 flex items-center gap-1 min-h-[2.25rem]"
                            onclick={() => (selectedIds = new Set())}
                        >
                            <XCircle class="w-3.5 h-3.5" /> Clear
                        </button>
                    {/if}
                </div>
            </div>

        <!-- Filter bar per 09-UI-ROUTES §3.9 -->
        <div
            class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 bg-surface-50 p-4 rounded-container border border-surface-200 shadow-sm mt-2"
        >
            <div class="relative w-full sm:w-48">
                <Filter
                    class="w-4 h-4 text-surface-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"
                />
                <select
                    class="select rounded-container border border-surface-200 pl-9 pr-8 py-2 w-full text-sm bg-surface-50 shadow-sm appearance-none"
                    bind:value={filterStatus}
                    aria-label="Filter by status"
                >
                    <option value="">All statuses</option>
                    <option value="available">Available</option>
                    <option value="in_use">In use</option>
                    <option value="deactivated">Deactivated</option>
                </select>
                <ChevronDown
                    class="w-4 h-4 text-surface-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none"
                />
            </div>

            <div class="relative w-full sm:w-64">
                <Search
                    class="w-4 h-4 text-surface-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"
                />
                <input
                    type="text"
                    class="input rounded-container border border-surface-200 pl-9 pr-3 py-2 w-full text-sm bg-surface-50 shadow-sm"
                    placeholder="Search by ID (e.g. A1)"
                    bind:value={searchQuery}
                    onkeydown={(e) => e.key === "Enter" && onFilterApply()}
                />
            </div>

            <button
                type="button"
                class="btn preset-filled-primary-500 shadow-sm sm:ml-auto flex items-center justify-center gap-2"
                onclick={onFilterApply}
                disabled={loading}
            >
                {#if loading}<span class="loading-spinner loading-sm"
                    ></span>{/if}
                {loading ? "Applying…" : "Apply filters"}
            </button>
        </div>

        {#if error}
            <div
                class="bg-error-100 text-error-900 border border-error-300 rounded-container p-4"
                role="alert"
            >
                <span>{error}</span>
                <button
                    type="button"
                    class="btn preset-tonal btn-sm"
                    onclick={() => (error = "")}>Dismiss</button
                >
            </div>
        {/if}

        {#if loading && tokens.length === 0}
            <div
                class="rounded-container border border-surface-200 bg-surface-50 p-12 flex flex-col items-center justify-center text-center shadow-sm mt-4"
            >
                <span class="loading-spinner loading-lg text-primary-500 mb-4"
                ></span>
                <p class="text-surface-600 font-medium animate-pulse">
                    Loading tokens...
                </p>
            </div>
        {:else if tokens.length === 0}
            <div
                class="rounded-container border border-surface-200 bg-surface-50 p-12 flex flex-col items-center justify-center text-center shadow-sm mt-4"
            >
                <div
                    class="bg-surface-100 p-4 rounded-full text-surface-400 mb-4"
                >
                    <Ticket class="w-8 h-8" />
                </div>
                <h3 class="text-lg font-semibold text-surface-950">
                    No tokens found
                </h3>
                <p class="text-surface-600 max-w-sm mt-2">
                    {searchQuery || filterStatus
                        ? "Try adjusting your search criteria or clear the filters."
                        : "Create a batch of tokens to get started."}
                </p>
            </div>
        {:else}
            <!-- Desktop Table View: compact, touch-friendly; row actions always visible, disabled when selection active -->
            <div class="table-container mt-2 hidden md:block overflow-x-auto rounded-container border border-surface-200 shadow-sm w-max max-w-full">
                <table class="table table-zebra relative w-max text-sm">
                    <thead>
                        <tr class="border-b border-surface-200">
                            <th class="w-10 py-2 px-2 text-center text-surface-600 font-medium">Select</th>
                            <th class="w-36 py-2 px-3 text-left text-surface-600 font-medium">Physical ID</th>
                            <th class="w-28 py-2 px-3 text-surface-600 font-medium">Status</th>
                            <th class="py-2 px-3 text-right text-surface-600 font-medium whitespace-nowrap">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {#each tokens as token (token.id)}
                            <tr class="border-b border-surface-100 hover:bg-surface-50/80 transition-colors">
                                <td class="py-2 px-2 text-center align-middle">
                                    {#if token.status === "available" || token.status === "deactivated"}
                                        <input
                                            type="checkbox"
                                            class="checkbox checkbox-sm"
                                            checked={selectedIds.has(token.id)}
                                            onchange={() =>
                                                toggleSelect(token.id)}
                                            aria-label="Select {token.physical_id}"
                                        />
                                    {:else}
                                        <div
                                            class="flex justify-center"
                                            aria-label="In-use tokens cannot be selected for bulk actions"
                                            title="In-use tokens cannot be selected for bulk actions"
                                        >
                                            <Ban
                                                class="w-4 h-4 text-surface-300"
                                            />
                                        </div>
                                    {/if}
                                </td>
                                <td class="py-2 px-3 align-middle">
                                    <span
                                        class="font-mono font-semibold text-surface-900"
                                    >
                                        {token.physical_id}
                                    </span>
                                </td>
                                <td class="py-2 px-3 align-middle">
                                    {#if token.status === "available"}
                                        <span
                                            class="badge preset-filled-success-500 text-[10px] px-2 py-0.5 rounded-full inline-flex items-center gap-1 w-max font-medium"
                                        >
                                            <CheckCircle2 class="w-3 h-3" />
                                            Available
                                        </span>
                                    {:else if token.status === "in_use"}
                                        <span
                                            class="badge preset-filled-primary-500 text-[10px] px-2 py-0.5 rounded-full inline-flex items-center gap-1 w-max font-medium"
                                        >
                                            <PlayCircle class="w-3 h-3" /> In use
                                        </span>
                                    {:else if token.status === "deactivated"}
                                        <span
                                            class="badge preset-tonal text-[10px] px-2 py-0.5 rounded-full inline-flex items-center gap-1 w-max font-medium text-surface-600"
                                        >
                                            <Ban class="w-3 h-3" /> Deactivated
                                        </span>
                                    {:else}
                                        <span
                                            class="badge preset-tonal text-[10px] px-2 py-0.5 rounded-full font-medium"
                                        >
                                            {token.status}
                                        </span>
                                    {/if}
                                </td>
                                <td class="py-2 px-3 text-right align-middle">
                                    <div class="flex items-center justify-end gap-1.5 flex-wrap {someSelected ? 'opacity-60 pointer-events-none' : ''}">
                                        <button
                                            type="button"
                                            class="btn btn-sm preset-outlined bg-surface-50 text-surface-600 hover:bg-surface-50 flex items-center gap-1 min-h-[2rem] px-2.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                            onclick={() => openEditModal(token)}
                                            disabled={someSelected || submitting}
                                            title="Edit token"
                                        >
                                            <Pencil class="w-3.5 h-3.5" /> Edit
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-sm preset-outlined bg-surface-50 text-surface-600 hover:bg-surface-50 flex items-center gap-1 min-h-[2rem] px-2.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                            onclick={() => openPrintModal([token.id])}
                                            disabled={someSelected || submitting}
                                            title="Print token"
                                        >
                                            <Printer class="w-3.5 h-3.5" />
                                        </button>
                                        {#if token.status === "in_use"}
                                            <button
                                                type="button"
                                                class="btn btn-sm preset-outlined bg-surface-50 text-surface-600 hover:bg-surface-50 flex items-center gap-1 min-h-[2rem] px-2.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                                onclick={() => setTokenStatus(token, "available")}
                                                disabled={someSelected || submitting}
                                                title="Mark available"
                                            >
                                                <CheckCircle2 class="w-3.5 h-3.5" />
                                            </button>
                                        {:else if token.status === "available"}
                                            <button
                                                type="button"
                                                class="btn btn-sm preset-outlined bg-surface-50 text-surface-600 hover:bg-surface-50 flex items-center gap-1 min-h-[2rem] px-2.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                                onclick={() => setTokenStatus(token, "deactivated")}
                                                disabled={someSelected || submitting}
                                                title="Deactivate"
                                            >
                                                <Ban class="w-3.5 h-3.5" />
                                            </button>
                                        {:else if token.status === "deactivated"}
                                            <button
                                                type="button"
                                                class="btn btn-sm preset-outlined bg-surface-50 text-surface-600 hover:bg-surface-50 flex items-center gap-1 min-h-[2rem] px-2.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                                onclick={() => setTokenStatus(token, "available")}
                                                disabled={someSelected || submitting}
                                                title="Activate"
                                            >
                                                <CheckCircle2 class="w-3.5 h-3.5" />
                                            </button>
                                        {/if}
                                        <button
                                            type="button"
                                            class="btn btn-sm preset-filled-error-500 hover:preset-filled-error-600 flex items-center gap-1 min-h-[2rem] px-2.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                            onclick={() => handleDeleteToken(token)}
                                            disabled={someSelected || submitting || token.status === "in_use"}
                                            title="Delete token"
                                        >
                                            <Trash2 class="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>

            <!-- Mobile Card View: compact, actions always visible, disabled when selection active -->
            <div
                class="md:hidden mt-4 mb-2 flex items-center justify-between px-1"
            >
                <label
                    class="flex items-center gap-2 text-sm font-medium text-surface-700 cursor-pointer"
                >
                    <input
                        type="checkbox"
                        class="checkbox checkbox-sm"
                        bind:this={selectAllCheckboxMobile}
                        checked={allSelected}
                        onchange={toggleSelectAll}
                        disabled={selectableForDelete.length === 0}
                    />
                    Select All
                </label>
            </div>
            <div class="grid grid-cols-1 gap-3 md:hidden">
                {#each tokens as token (token.id)}
                    <div
                        class="card bg-surface-50 border border-surface-200 shadow-sm p-3 flex flex-col gap-2 rounded-lg"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2 min-w-0">
                                {#if token.status === "available" || token.status === "deactivated"}
                                    <input
                                        type="checkbox"
                                        class="checkbox checkbox-sm shrink-0"
                                        checked={selectedIds.has(token.id)}
                                        onchange={() => toggleSelect(token.id)}
                                        aria-label="Select {token.physical_id}"
                                    />
                                {:else}
                                    <span title="In use" aria-label="In use"><Ban
                                        class="w-4 h-4 text-surface-300 shrink-0"
                                    /></span>
                                {/if}
                                <span
                                    class="font-mono font-semibold text-surface-900 truncate"
                                >
                                    {token.physical_id}
                                </span>
                            </div>
                            <div class="shrink-0">
                                {#if token.status === "available"}
                                    <span
                                        class="badge preset-filled-success-500 text-[10px] px-2 py-0.5 rounded-full inline-flex items-center gap-1 font-medium"
                                    >
                                        <CheckCircle2 class="w-3 h-3" /> Available
                                    </span>
                                {:else if token.status === "in_use"}
                                    <span
                                        class="badge preset-filled-primary-500 text-[10px] px-2 py-0.5 rounded-full inline-flex items-center gap-1 font-medium"
                                    >
                                        <PlayCircle class="w-3 h-3" /> In use
                                    </span>
                                {:else if token.status === "deactivated"}
                                    <span
                                        class="badge preset-tonal text-[10px] px-2 py-0.5 rounded-full inline-flex items-center gap-1 font-medium text-surface-600"
                                    >
                                        <Ban class="w-3 h-3" /> Deactivated
                                    </span>
                                {:else}
                                    <span
                                        class="badge preset-tonal text-[10px] px-2 py-0.5 rounded-full font-medium"
                                    >
                                        {token.status}
                                    </span>
                                {/if}
                            </div>
                        </div>

                        <div class="pt-2 border-t border-surface-200 flex flex-wrap items-center gap-2 min-h-[2.75rem] {someSelected ? 'opacity-60 pointer-events-none' : ''}">
                            <button
                                type="button"
                                class="btn btn-sm preset-outlined bg-surface-50 text-surface-600 flex items-center justify-center gap-1 min-h-[2.5rem] px-2.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                onclick={() => openEditModal(token)}
                                disabled={someSelected || submitting}
                                aria-label="Edit token"
                            >
                                <Pencil class="w-3.5 h-3.5" /> Edit
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm preset-outlined bg-surface-50 text-surface-600 flex items-center justify-center gap-1 min-h-[2.5rem] px-2.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                onclick={() => openPrintModal([token.id])}
                                disabled={someSelected || submitting}
                                aria-label="Print token"
                            >
                                <Printer class="w-3.5 h-3.5" />
                            </button>
                            {#if token.status === "in_use"}
                                <button
                                    type="button"
                                    class="btn btn-sm preset-outlined bg-surface-50 text-surface-600 flex items-center justify-center gap-1 min-h-[2.5rem] px-2.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                    onclick={() => setTokenStatus(token, "available")}
                                    disabled={someSelected || submitting}
                                    aria-label="Mark available"
                                >
                                    <CheckCircle2 class="w-3.5 h-3.5" />
                                </button>
                            {:else if token.status === "available"}
                                <button
                                    type="button"
                                    class="btn btn-sm preset-outlined bg-surface-50 text-surface-600 flex items-center justify-center gap-1 min-h-[2.5rem] px-2.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                    onclick={() => setTokenStatus(token, "deactivated")}
                                    disabled={someSelected || submitting}
                                    aria-label="Deactivate"
                                >
                                    <Ban class="w-3.5 h-3.5" />
                                </button>
                            {:else if token.status === "deactivated"}
                                <button
                                    type="button"
                                    class="btn btn-sm preset-outlined bg-surface-50 text-surface-600 flex items-center justify-center gap-1 min-h-[2.5rem] px-2.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                    onclick={() => setTokenStatus(token, "available")}
                                    disabled={someSelected || submitting}
                                    aria-label="Activate"
                                >
                                    <CheckCircle2 class="w-3.5 h-3.5" />
                                </button>
                            {/if}
                            <button
                                type="button"
                                class="btn btn-sm preset-filled-error-500 hover:preset-filled-error-600 flex items-center justify-center min-h-[2.5rem] w-10 shrink-0 disabled:opacity-50 disabled:cursor-not-allowed"
                                onclick={() => handleDeleteToken(token)}
                                disabled={someSelected || submitting || token.status === "in_use"}
                                aria-label="Delete token"
                            >
                                <Trash2 class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                {/each}
            </div>
        {/if}
    </div>
</AdminLayout>

<Modal
    open={showBatchModal}
    title="Create token batch"
    onClose={closeBatchModal}
>
    {#snippet children()}
        <form
            onsubmit={(e) => {
                e.preventDefault();
                handleBatchCreate();
            }}
            class="flex flex-col gap-4"
        >
            <div class="form-control w-full">
                <label for="batch-prefix" class="label"
                    ><span class="label-text">Prefix</span></label
                >
                <input
                    id="batch-prefix"
                    type="text"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                    placeholder="e.g. A"
                    maxlength="10"
                    bind:value={batchPrefix}
                    required
                />
            </div>
            <div class="form-control w-full">
                <label for="batch-start" class="label"
                    ><span class="label-text font-medium">Start number</span
                    ></label
                >
                <input
                    id="batch-start"
                    type="number"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                    min="0"
                    bind:value={batchStart}
                    required
                />
            </div>
            <div class="form-control w-full">
                <label for="batch-count" class="label"
                    ><span class="label-text font-medium">Count</span></label
                >
                <input
                    id="batch-count"
                    type="number"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                    min="1"
                    max="500"
                    bind:value={batchCount}
                    required
                />
                <div
                    class="mt-2 p-3 bg-surface-100 rounded-container border border-surface-200 flex items-center justify-between"
                >
                    <span
                        class="text-xs font-semibold uppercase text-surface-500 tracking-wider"
                        >Preview Sequence</span
                    >
                    <span class="font-mono text-sm font-bold text-surface-900"
                        >{batchPrefix}{batchStart}
                        <span
                            class="text-surface-400 font-sans font-normal mx-1"
                            >to</span
                        >
                        {batchPrefix}{Number(batchStart) +
                            Number(batchCount) -
                            1}</span
                    >
                </div>
            </div>
            <div class="form-control w-full">
                <span class="label-text font-medium mb-1 block">Pronounce alias as</span>
                <p class="text-sm text-surface-600 mb-2">How display TTS will speak the token ID (e.g. on call).</p>
                <div class="flex gap-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input
                            type="radio"
                            name="batch-pronounce"
                            value="letters"
                            checked={batchPronounceAs === "letters"}
                            onchange={() => (batchPronounceAs = "letters")}
                            class="radio radio-sm"
                        />
                        <span>Letters (e.g. A 3)</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input
                            type="radio"
                            name="batch-pronounce"
                            value="word"
                            checked={batchPronounceAs === "word"}
                            onchange={() => (batchPronounceAs = "word")}
                            class="radio radio-sm"
                        />
                        <span>Word (e.g. A3)</span>
                    </label>
                </div>
            </div>
            <div class="mb-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input
                        type="checkbox"
                        class="checkbox checkbox-sm"
                        bind:checked={batchGenerateTts}
                    />
                    <span class="font-medium">Generate TTS audio for offline playback</span>
                </label>
                <p class="text-sm text-surface-600 mt-1 ml-6">
                    Requires internet. Audio is generated in the background after tokens are created.
                </p>
            </div>
            <div
                class="flex justify-end gap-3 mt-4 pt-4 border-t border-surface-100"
            >
                <button
                    type="button"
                    class="btn preset-tonal"
                    onclick={closeBatchModal}>Cancel</button
                >
                <button
                    type="submit"
                    class="btn preset-filled-primary-500 shadow-sm"
                    disabled={submitting ||
                        batchCount < 1 ||
                        batchCount > 500 ||
                        !batchPrefix.toString().trim()}
                >
                    {submitting ? "Creating…" : "Create"}
                </button>
            </div>
        </form>
    {/snippet}
</Modal>

<Modal
    open={editToken !== null}
    title="Edit token"
    onClose={closeEditModal}
>
    {#snippet children()}
        {#if editToken}
            <form
                onsubmit={(e) => {
                    e.preventDefault();
                    saveEdit();
                }}
                class="flex flex-col gap-4"
            >
                <div class="form-control w-full">
                    <div class="label"><span class="label-text font-medium">Physical ID</span></div>
                    <p class="font-mono font-semibold text-surface-900 px-3 py-2 bg-surface-100 rounded-container border border-surface-200">
                        {editToken.physical_id}
                    </p>
                    <p class="label-text-alt mt-1">Token ID cannot be changed.</p>
                </div>
                <div class="form-control w-full">
                    <span class="label-text font-medium mb-2 block">Pronounce as (TTS)</span>
                    <p class="text-sm text-surface-600 mb-2">How display/TTS will speak this token (e.g. on call).</p>
                    <div class="flex gap-4">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input
                                type="radio"
                                name="edit-pronounce"
                                value="letters"
                                checked={editPronounceAs === "letters"}
                                onchange={() => (editPronounceAs = "letters")}
                                class="radio radio-sm"
                            />
                            <span>Letters (e.g. A 3)</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input
                                type="radio"
                                name="edit-pronounce"
                                value="word"
                                checked={editPronounceAs === "word"}
                                onchange={() => (editPronounceAs = "word")}
                                class="radio radio-sm"
                            />
                            <span>Word (e.g. A3)</span>
                        </label>
                    </div>
                </div>
                <div class="flex justify-end gap-3 mt-4 pt-4 border-t border-surface-200">
                    <button
                        type="button"
                        class="btn preset-tonal"
                        onclick={closeEditModal}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="btn preset-filled-primary-500 shadow-sm"
                        disabled={submitting}
                    >
                        {submitting ? "Saving…" : "Save"}
                    </button>
                </div>
            </form>
        {/if}
    {/snippet}
</Modal>

<Modal open={showPrintModal} title="Print tokens" onClose={closePrintModal}>
    {#snippet children()}
        <div class="flex flex-col gap-5">
            {#if printTargetIds.length > 0}
                <div
                    class="bg-primary-50 border border-primary-200 rounded-container p-3 flex items-start gap-3"
                >
                    <Printer class="w-5 h-5 text-primary-500 shrink-0 mt-0.5" />
                    <div>
                        <p class="text-sm font-semibold text-surface-900">
                            Ready to Print
                        </p>
                        <p class="text-sm text-surface-700 mt-0.5">
                            Printing {printTargetIds.length} token(s). Adjust template
                            options below if needed.
                        </p>
                    </div>
                </div>
            {:else}
                <div
                    class="bg-surface-50 border border-surface-200 rounded-container p-3 flex items-start gap-3"
                >
                    <Printer class="w-5 h-5 text-surface-400 shrink-0 mt-0.5" />
                    <div>
                        <p class="text-sm font-semibold text-surface-900">
                            Edit Print Defaults
                        </p>
                        <p class="text-sm text-surface-600 mt-0.5">
                            Set the default printing template. Select tokens and
                            click "Print selected" to print.
                        </p>
                    </div>
                </div>
            {/if}

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="form-control">
                    <label for="print-cards" class="label"
                        ><span class="label-text font-medium"
                            >Cards per page</span
                        ></label
                    >
                    <select
                        id="print-cards"
                        class="select rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                        bind:value={printSettings.cards_per_page}
                    >
                        {#each [4, 5, 6, 7, 8] as n}
                            <option value={n}>{n}</option>
                        {/each}
                    </select>
                </div>
                <div class="form-control">
                    <label for="print-paper" class="label"
                        ><span class="label-text font-medium">Paper</span
                        ></label
                    >
                    <select
                        id="print-paper"
                        class="select rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                        bind:value={printSettings.paper}
                    >
                        <option value="a4">A4</option>
                        <option value="letter">Letter</option>
                    </select>
                </div>
                <div class="form-control">
                    <label for="print-orientation" class="label"
                        ><span class="label-text font-medium">Orientation</span
                        ></label
                    >
                    <select
                        id="print-orientation"
                        class="select rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                        bind:value={printSettings.orientation}
                    >
                        <option value="portrait">Portrait</option>
                        <option value="landscape">Landscape</option>
                    </select>
                </div>
            </div>

            <div
                class="bg-surface-50 p-4 rounded-container border border-surface-200"
            >
                <h4
                    class="text-xs font-semibold uppercase tracking-wider text-surface-500 mb-3"
                >
                    Display Options
                </h4>
                <div class="flex flex-col sm:flex-row gap-4 sm:gap-8">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input
                            type="checkbox"
                            class="checkbox"
                            bind:checked={printSettings.show_hint}
                        />
                        <span class="label-text font-medium"
                            >Show "Scan for status" hint</span
                        >
                    </label>
                    <label class="label cursor-pointer justify-start gap-3">
                        <input
                            type="checkbox"
                            class="checkbox"
                            bind:checked={printSettings.show_cut_lines}
                        />
                        <span class="label-text font-medium"
                            >Show cut lines</span
                        >
                    </label>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4">
                <div class="form-control">
                    <label for="print-logo" class="label"
                        ><span class="label-text font-medium"
                            >Logo URL <span class="text-surface-500 font-normal"
                                >(optional)</span
                            ></span
                        ></label
                    >
                    <input
                        id="print-logo"
                        type="url"
                        class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                        placeholder="https://example.com/logo.png"
                        bind:value={printSettings.logo_url}
                    />
                </div>
                <div class="form-control">
                    <label for="print-footer" class="label"
                        ><span class="label-text font-medium"
                            >Footer text <span
                                class="text-surface-500 font-normal"
                                >(optional)</span
                            ></span
                        ></label
                    >
                    <textarea
                        id="print-footer"
                        class="textarea rounded-container border border-surface-200 w-full bg-surface-50 shadow-sm"
                        placeholder="Shown on each card, centered. e.g. Premise rules, office hours"
                        rows="2"
                        bind:value={printSettings.footer_text}
                    ></textarea>
                </div>
                <div class="form-control">
                    <label for="print-bg" class="label"
                        ><span class="label-text font-medium"
                            >Background image URL <span
                                class="text-surface-500 font-normal"
                                >(optional)</span
                            ></span
                        ></label
                    >
                    <input
                        id="print-bg"
                        type="text"
                        class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm"
                        placeholder="https://example.com/bg.png"
                        bind:value={printSettings.bg_image_url}
                    />
                    <p class="label-text-alt mt-1 flex items-center gap-1.5">
                        <ChevronDown
                            class="w-3 h-3 text-surface-400 rotate-[-90deg]"
                        /> Use 6:5 aspect ratio (e.g. 60×50mm) for best fit per card.
                    </p>
                </div>
            </div>
            <div
                class="flex justify-end gap-3 mt-6 pt-4 border-t border-surface-100"
            >
                <button
                    type="button"
                    class="btn preset-tonal"
                    onclick={closePrintModal}>Cancel</button
                >
                <button
                    type="button"
                    class="btn preset-outlined bg-surface-50 text-surface-700 hover:bg-surface-50 shadow-sm transition-colors"
                    onclick={() => savePrintSettings()}
                    disabled={submitting}
                >
                    {printSettingsSaved
                        ? "Saved"
                        : submitting
                          ? "Saving…"
                          : "Save as default"}
                </button>
                <button
                    type="button"
                    class="btn preset-filled-primary-500 shadow-sm flex items-center gap-2"
                    onclick={doPrint}
                    disabled={printTargetIds.length === 0}
                    title={printTargetIds.length === 0
                        ? "Select tokens first"
                        : ""}
                >
                    <Printer class="w-4 h-4" /> Print
                </button>
            </div>
        </div>
    {/snippet}
</Modal>
