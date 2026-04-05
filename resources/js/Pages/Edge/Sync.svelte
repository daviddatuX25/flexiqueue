<script lang="ts">
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import { usePage, router } from '@inertiajs/svelte';
    import { get } from 'svelte/store';
    import { RefreshCw, Loader2 } from 'lucide-svelte';

    interface DeviceState {
        paired_at: string | null;
        central_url: string | null;
        site_name: string | null;
        active_program_id: number | null;
        active_program_name: string | null;
        package_version: string | null;
        package_stale: boolean;
        update_available: boolean;
        session_active: boolean;
        last_synced_at: string | null;
        app_version: string | null;
    }

    interface ImportStatus {
        status: 'never_synced' | 'running' | 'complete' | 'failed' | 'unknown';
        imported_at?: string;
        program_id?: number;
        sync_tokens?: boolean;
        sync_clients?: boolean;
        sync_tts?: boolean;
    }

    interface SyncReceipt {
        id: number;
        batch_id: string | null;
        status: string;
        payload_summary: { sessions?: number; logs?: number } | null;
        started_at: string | null;
        completed_at: string | null;
    }

    let {
        device,
        import: importStatus,
        receipts,
    }: {
        device: DeviceState;
        import: ImportStatus;
        receipts: SyncReceipt[];
    } = $props();

    const page = usePage();
    let syncing = $state(false);
    let sshEnabling = $state(false);
    let sshMessage = $state<string | null>(null);
    let sshError = $state<string | null>(null);
    let pollHandle: ReturnType<typeof setInterval> | null = null;

    function getCsrfToken(): string {
        const p = get(page);
        const fromProps = (p?.props as { csrf_token?: string } | undefined)?.csrf_token;
        if (fromProps) return fromProps;
        const metaEl =
            typeof document !== 'undefined'
                ? (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content
                : '';
        return metaEl ?? '';
    }

    async function triggerSync(): Promise<void> {
        if (syncing || device.active_program_id == null) return;
        syncing = true;
        const res = await fetch('/api/admin/edge/import', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ program_id: device.active_program_id }),
        });
        if (res.ok || res.status === 409) {
            pollHandle = setInterval(async () => {
                const statusRes = await fetch('/api/admin/edge/import/status', {
                    credentials: 'same-origin',
                });
                if (statusRes.ok) {
                    const data = await statusRes.json();
                    if (data.status !== 'running') {
                        if (pollHandle !== null) clearInterval(pollHandle);
                        pollHandle = null;
                        syncing = false;
                        router.reload();
                    }
                }
            }, 3000);
        } else {
            syncing = false;
        }
    }

    function formatDate(iso: string | null | undefined): string {
        if (!iso) return '—';
        try {
            return new Date(iso).toLocaleString(undefined, {
                dateStyle: 'medium',
                timeStyle: 'short',
            });
        } catch {
            return iso;
        }
    }

    function importStatusLabel(status: string): string {
        const map: Record<string, string> = {
            never_synced: 'Never synced',
            running: 'Syncing…',
            complete: 'Up to date',
            failed: 'Failed',
            unknown: 'Unknown',
        };
        return map[status] ?? status;
    }

    function importStatusClass(status: string): string {
        switch (status) {
            case 'complete': return 'badge preset-filled-success-500';
            case 'running':  return 'badge preset-filled-primary-500';
            case 'failed':   return 'badge preset-filled-error-500';
            default:         return 'badge preset-tonal';
        }
    }

    function receiptStatusClass(status: string): string {
        switch (status) {
            case 'complete': return 'badge preset-filled-success-500';
            case 'failed':   return 'badge preset-filled-error-500';
            case 'partial':  return 'badge preset-filled-warning-500';
            case 'running':  return 'badge preset-filled-primary-500';
            default:         return 'badge preset-tonal';
        }
    }

    async function enableSsh(): Promise<void> {
        if (sshEnabling) return;
        sshEnabling = true;
        sshMessage  = null;
        sshError    = null;
        try {
            const res = await fetch('/api/edge/ssh/enable', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            const data: { message?: string; expires_at?: string; error?: string } = await res.json();
            if (res.ok) {
                const expiry = data.expires_at ? formatDate(data.expires_at) : '';
                sshMessage = data.message + (expiry ? ' Expires: ' + expiry + '.' : '');
            } else {
                sshError = data.error ?? 'Failed to enable SSH.';
            }
        } catch {
            sshError = 'Network error. Could not reach the server.';
        } finally {
            sshEnabling = false;
        }
    }
</script>

<svelte:head>
    <title>Sync Status — FlexiQueue</title>
</svelte:head>

<AdminLayout>
    <div class="max-w-3xl mx-auto space-y-6">
        <!-- Page header -->
        <div>
            <h1 class="text-2xl font-semibold text-surface-900 dark:text-surface-100">
                Edge Sync Status
            </h1>
            <p class="mt-1 text-sm text-surface-500 dark:text-surface-400">
                Device pairing, package import, and batch sync history.
            </p>
        </div>

        <!-- Device info card -->
        <div class="card bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-700 p-5 space-y-4">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-surface-500 dark:text-surface-400">
                Device
            </h2>
            <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
                <dt class="text-surface-500 dark:text-surface-400">Site</dt>
                <dd class="text-surface-900 dark:text-surface-100 font-medium">{device.site_name ?? '—'}</dd>
                <dt class="text-surface-500 dark:text-surface-400">Central URL</dt>
                <dd class="text-surface-900 dark:text-surface-100 font-mono text-xs truncate">{device.central_url ?? '—'}</dd>
                <dt class="text-surface-500 dark:text-surface-400">Paired since</dt>
                <dd class="text-surface-900 dark:text-surface-100">{formatDate(device.paired_at)}</dd>
                <dt class="text-surface-500 dark:text-surface-400">Active program</dt>
                <dd class="text-surface-900 dark:text-surface-100">{device.active_program_name ?? '—'}</dd>
                <dt class="text-surface-500 dark:text-surface-400">App version</dt>
                <dd class="text-surface-900 dark:text-surface-100 font-mono text-xs">{device.app_version ?? '—'}</dd>
            </dl>
        </div>

        <!-- Package import card -->
        <div class="card bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-700 p-5 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-surface-500 dark:text-surface-400">
                    Package Import
                </h2>
                <span class={importStatusClass(importStatus.status)}>
                    {importStatusLabel(importStatus.status)}
                </span>
            </div>
            <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
                <dt class="text-surface-500 dark:text-surface-400">Last imported</dt>
                <dd class="text-surface-900 dark:text-surface-100">{formatDate(importStatus.imported_at)}</dd>
                <dt class="text-surface-500 dark:text-surface-400">Package version</dt>
                <dd class="text-surface-900 dark:text-surface-100 font-mono text-xs">{device.package_version ?? '—'}</dd>
                <dt class="text-surface-500 dark:text-surface-400">Includes tokens</dt>
                <dd class="text-surface-900 dark:text-surface-100">
                    {importStatus.sync_tokens == null ? '—' : importStatus.sync_tokens ? 'Yes' : 'No'}
                </dd>
                <dt class="text-surface-500 dark:text-surface-400">Includes clients</dt>
                <dd class="text-surface-900 dark:text-surface-100">
                    {importStatus.sync_clients == null ? '—' : importStatus.sync_clients ? 'Yes' : 'No'}
                </dd>
                <dt class="text-surface-500 dark:text-surface-400">Includes TTS audio</dt>
                <dd class="text-surface-900 dark:text-surface-100">
                    {importStatus.sync_tts == null ? '—' : importStatus.sync_tts ? 'Yes' : 'No'}
                </dd>
            </dl>

            <!-- Stale warning (session NOT active — safe to re-sync now) -->
            {#if device.package_stale && !device.session_active}
                <div class="rounded-container bg-warning-50 dark:bg-warning-900/30 border border-warning-200 dark:border-warning-700 p-3 text-sm text-warning-800 dark:text-warning-300">
                    Package is outdated. Re-sync to apply the latest configuration from central.
                </div>
            {/if}

            <!-- Stale warning (session IS active — must wait) -->
            {#if device.package_stale && device.session_active}
                <div class="rounded-container bg-warning-50 dark:bg-warning-900/30 border border-warning-200 dark:border-warning-700 p-3 text-sm text-warning-800 dark:text-warning-300">
                    Package is outdated. Re-sync is blocked while a session is active. Changes will apply after the session ends.
                </div>
            {/if}

            <!-- App update notice -->
            {#if device.update_available}
                <div class="rounded-container bg-primary-50 dark:bg-primary-900/30 border border-primary-200 dark:border-primary-700 p-3 text-sm text-primary-800 dark:text-primary-300">
                    A newer edge app version is available on central. Update the app on central, then re-sync here to receive it.
                </div>
            {/if}

            <!-- Re-sync button -->
            <button
                type="button"
                class="btn preset-filled-primary-500 flex items-center gap-2"
                onclick={triggerSync}
                disabled={syncing || device.active_program_id == null || device.session_active}
                title={device.session_active
                    ? 'Re-sync is blocked while a session is active.'
                    : device.active_program_id == null
                    ? 'No active program assigned to this device.'
                    : undefined}
            >
                {#if syncing}
                    <Loader2 class="w-4 h-4 animate-spin" aria-hidden="true" />
                    Syncing…
                {:else}
                    <RefreshCw class="w-4 h-4" aria-hidden="true" />
                    Re-sync from central
                {/if}
            </button>
        </div>

        <!-- Batch sync history card -->
        <div class="card bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-700 p-5 space-y-4">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-surface-500 dark:text-surface-400">
                Batch Sync History
                <span class="normal-case font-normal text-surface-400 dark:text-surface-500 ml-1">
                    (last 10)
                </span>
            </h2>
            {#if receipts.length === 0}
                <p class="text-sm text-surface-500 dark:text-surface-400">
                    No batch syncs recorded yet.
                    Batch sync runs automatically at the end of each session to upload data back to central.
                </p>
            {:else}
                <div class="overflow-x-auto -mx-1">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-surface-500 dark:text-surface-400 border-b border-surface-200 dark:border-surface-700">
                                <th class="pb-2 pr-4 font-medium">Batch</th>
                                <th class="pb-2 pr-4 font-medium">Status</th>
                                <th class="pb-2 pr-4 font-medium">Sessions</th>
                                <th class="pb-2 pr-4 font-medium">Logs</th>
                                <th class="pb-2 font-medium">Completed</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-surface-200 dark:divide-surface-700">
                            {#each receipts as receipt (receipt.id)}
                                <tr class="text-surface-700 dark:text-surface-300">
                                    <td class="py-2 pr-4 font-mono text-xs max-w-[120px] truncate" title={receipt.batch_id ?? undefined}>
                                        {receipt.batch_id ?? '—'}
                                    </td>
                                    <td class="py-2 pr-4">
                                        <span class={receiptStatusClass(receipt.status)}>
                                            {receipt.status}
                                        </span>
                                    </td>
                                    <td class="py-2 pr-4 tabular-nums">
                                        {receipt.payload_summary?.sessions ?? '—'}
                                    </td>
                                    <td class="py-2 pr-4 tabular-nums">
                                        {receipt.payload_summary?.logs ?? '—'}
                                    </td>
                                    <td class="py-2 text-surface-500 dark:text-surface-400">
                                        {formatDate(receipt.completed_at)}
                                    </td>
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>
            {/if}
        </div>

        <!-- Maintenance Access — SSH toggle (§18.3.3) -->
        <div class="card bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-700 p-5 space-y-4">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-surface-500 dark:text-surface-400">
                Maintenance Access
            </h2>
            <p class="text-sm text-surface-600 dark:text-surface-400">
                SSH is disabled by default on this device. Enable it temporarily for remote diagnostics.
                It will auto-disable after 30 minutes.
            </p>
            {#if sshMessage}
                <div class="rounded-container bg-success-50 dark:bg-success-900/30 border border-success-200 dark:border-success-700 p-3 text-sm text-success-800 dark:text-success-300">
                    {sshMessage}
                </div>
            {/if}
            {#if sshError}
                <div class="rounded-container bg-error-50 dark:bg-error-900/30 border border-error-200 dark:border-error-700 p-3 text-sm text-error-800 dark:text-error-300">
                    {sshError}
                </div>
            {/if}
            <button
                type="button"
                class="btn preset-tonal flex items-center gap-2"
                onclick={enableSsh}
                disabled={sshEnabling}
                aria-busy={sshEnabling}
            >
                {#if sshEnabling}
                    <Loader2 class="w-4 h-4 animate-spin" aria-hidden="true" />
                    Enabling SSH…
                {:else}
                    Enable SSH for 30 minutes
                {/if}
            </button>
        </div>
    </div>
</AdminLayout>
