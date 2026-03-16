<script lang="ts">
    /**
     * Edge mode banner for admin panel. Per docs/final-edge-mode-rush-plann.md [DF-16].
     * Shows offline (amber) or online (green) state; "Sync Now" triggers edge import.
     */
    import { usePage, router } from "@inertiajs/svelte";
    import { onMount, onDestroy } from "svelte";
    import { Wifi, WifiOff, RefreshCw } from "lucide-svelte";

    const page = usePage();
    const edgeMode = $derived($page.props?.edge_mode ?? null);

    let importStatus = $state<{ status?: string; imported_at?: string } | null>(null);
    let importing = $state(false);
    let pollInterval = $state<ReturnType<typeof setInterval> | null>(null);

    function getCsrfToken(): string {
        return (
            ($page.props?.csrf_token as string | undefined) ??
            document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ??
            ""
        );
    }

    async function fetchStatus() {
        const res = await fetch("/api/admin/edge/import/status", { credentials: "same-origin" });
        if (res.ok) importStatus = await res.json();
    }

    onMount(() => {
        fetchStatus();
    });

    onDestroy(() => {
        if (pollInterval != null) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
    });

    async function triggerSync() {
        if (importing) return;
        const programId = ($page.props?.currentProgram as { id?: number } | undefined)?.id;
        if (programId == null) {
            alert("No active program selected. Go to a program page to sync.");
            return;
        }
        importing = true;
        const res = await fetch("/api/admin/edge/import", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": getCsrfToken(),
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
            body: JSON.stringify({ program_id: programId }),
        });
        if (res.ok || res.status === 409) {
            pollInterval = setInterval(async () => {
                await fetchStatus();
                if (importStatus?.status !== "running") {
                    if (pollInterval != null) {
                        clearInterval(pollInterval);
                        pollInterval = null;
                    }
                    importing = false;
                    router.reload();
                }
            }, 3000);
        } else {
            importing = false;
        }
    }

    function formatLastSynced(iso: string | undefined): string {
        if (!iso) return "";
        try {
            const d = new Date(iso);
            return d.toLocaleString(undefined, { dateStyle: "short", timeStyle: "short" });
        } catch {
            return iso;
        }
    }
</script>

{#if edgeMode?.is_edge}
    {#if edgeMode.is_online && edgeMode.bridge_mode_enabled}
        <!-- Online state (green): Phase E bridge active — only when EDGE_BRIDGE_MODE=true -->
        <div
            class="py-2 px-4 flex items-center justify-between bg-success-50 dark:bg-success-900/30 border-b border-success-200 dark:border-success-700"
            role="status"
            aria-live="polite"
        >
            <div class="flex items-center gap-2">
                <Wifi class="w-5 h-5 text-success-600 dark:text-success-400" aria-hidden="true" />
                <span class="font-semibold text-success-700 dark:text-success-300">Edge mode — bridge active</span>
            </div>
        </div>
    {:else}
        <!-- Offline state (amber) — theme-aware for dark mode -->
        <div
            class="py-2 px-4 flex items-center justify-between bg-warning-50 dark:bg-warning-900/30 border-b border-warning-200 dark:border-warning-700"
            role="status"
            aria-live="polite"
        >
            <div class="flex items-center gap-2">
                <WifiOff class="w-5 h-5 text-warning-600 dark:text-warning-400" aria-hidden="true" />
                <div>
                    <p class="font-semibold text-warning-700 dark:text-warning-300">Edge mode — offline</p>
                    <p class="text-sm text-warning-600 dark:text-warning-400">Admin is read-only</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                {#if importStatus?.imported_at}
                    <span class="text-xs text-warning-600 dark:text-warning-400">
                        Last synced: {formatLastSynced(importStatus.imported_at)}
                    </span>
                {/if}
                <button
                    type="button"
                    class="btn preset-tonal btn-sm flex items-center gap-1.5"
                    onclick={triggerSync}
                    disabled={importing}
                    aria-busy={importing}
                >
                    {#if importing}
                        <span class="loading-spinner loading-sm" aria-hidden="true"></span>
                        Syncing…
                    {:else}
                        <RefreshCw class="w-4 h-4" aria-hidden="true" />
                        Sync Now
                    {/if}
                </button>
            </div>
        </div>
    {/if}
{/if}
