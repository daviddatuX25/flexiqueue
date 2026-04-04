<script lang="ts">
    import { onMount } from "svelte";
    import {
        MonitorSmartphone,
        Plus,
        RefreshCw,
        MoreHorizontal,
        Circle,
    } from "lucide-svelte";

    interface Device {
        id: number;
        name: string;
        status: "online" | "waiting" | "idle" | "stale" | "offline";
        sync_mode: "auto" | "end_of_event";
        supervisor_admin_access: boolean;
        assigned_program_id: number | null;
        assigned_program_name: string | null;
        session_active: boolean;
        last_seen_at: string | null;
    }

    interface Program {
        id: number;
        name: string;
        edge_locked_by_device_id: number | null;
    }

    let {
        siteId,
        slotsTotal,
        programs = [],
    }: {
        siteId: number;
        slotsTotal: number;
        programs: Program[];
    } = $props();

    let devices = $state<Device[]>([]);
    let slotsUsed = $state(0);
    let loading = $state(true);
    let errorMsg = $state<string | null>(null);

    // Add device modal
    let showAddModal = $state(false);
    let addDeviceName = $state("");
    let addSubmitting = $state(false);
    let generatedCode = $state<string | null>(null);

    // Assign modal
    let assigningDevice = $state<Device | null>(null);
    let assignProgramId = $state<number | null>(null);
    let assignSyncMode = $state<"auto" | "end_of_event">("auto");
    let assignSupervisor = $state(false);
    let assignSubmitting = $state(false);

    // Revoke confirm
    let revokingDevice = $state<Device | null>(null);
    let revokeSubmitting = $state(false);

    // Open dropdown
    let openDropdownId = $state<number | null>(null);

    function getCsrfToken(): string {
        return (
            (
                document.querySelector(
                    'meta[name="csrf-token"]',
                ) as HTMLMetaElement | null
            )?.content ?? ""
        );
    }

    async function loadDevices(): Promise<void> {
        loading = true;
        errorMsg = null;
        try {
            const res = await fetch(
                `/api/admin/sites/${siteId}/edge-devices`,
                { headers: { Accept: "application/json" } },
            );
            if (!res.ok) throw new Error("Failed to load devices.");
            const data = await res.json();
            devices = data.devices ?? [];
            slotsUsed = data.slots_used ?? 0;
        } catch (e) {
            errorMsg = e instanceof Error ? e.message : "Unknown error.";
        } finally {
            loading = false;
        }
    }

    onMount(loadDevices);

    async function handleGenerateCode(): Promise<void> {
        if (!addDeviceName.trim()) return;
        addSubmitting = true;
        errorMsg = null;
        try {
            const res = await fetch(
                `/api/admin/sites/${siteId}/edge-devices/pairing-code`,
                {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        Accept: "application/json",
                        "X-CSRF-TOKEN": getCsrfToken(),
                    },
                    body: JSON.stringify({ name: addDeviceName.trim() }),
                },
            );
            const data = await res.json();
            if (!res.ok) {
                errorMsg = data.message ?? "Failed to generate code.";
                return;
            }
            generatedCode = data.code;
        } finally {
            addSubmitting = false;
        }
    }

    function closeAddModal(): void {
        showAddModal = false;
        addDeviceName = "";
        generatedCode = null;
        loadDevices();
    }

    function openAssignModal(device: Device): void {
        assigningDevice = device;
        assignProgramId = device.assigned_program_id;
        assignSyncMode = device.sync_mode;
        assignSupervisor = device.supervisor_admin_access;
        openDropdownId = null;
    }

    async function handleAssignSubmit(): Promise<void> {
        if (!assigningDevice) return;
        assignSubmitting = true;
        errorMsg = null;
        try {
            const res = await fetch(
                `/api/admin/edge-devices/${assigningDevice.id}`,
                {
                    method: "PUT",
                    headers: {
                        "Content-Type": "application/json",
                        Accept: "application/json",
                        "X-CSRF-TOKEN": getCsrfToken(),
                    },
                    body: JSON.stringify({
                        assigned_program_id: assignProgramId,
                        sync_mode: assignSyncMode,
                        supervisor_admin_access: assignSupervisor,
                    }),
                },
            );
            const data = await res.json();
            if (!res.ok) {
                errorMsg = data.message ?? "Failed to update device.";
                return;
            }
            assigningDevice = null;
            await loadDevices();
        } finally {
            assignSubmitting = false;
        }
    }

    async function handleRevoke(): Promise<void> {
        if (!revokingDevice) return;
        revokeSubmitting = true;
        errorMsg = null;
        try {
            const res = await fetch(
                `/api/admin/edge-devices/${revokingDevice.id}`,
                {
                    method: "DELETE",
                    headers: {
                        Accept: "application/json",
                        "X-CSRF-TOKEN": getCsrfToken(),
                    },
                },
            );
            if (!res.ok) {
                const data = await res.json();
                errorMsg = data.message ?? "Failed to revoke device.";
                return;
            }
            revokingDevice = null;
            await loadDevices();
        } finally {
            revokeSubmitting = false;
        }
    }

    const STATUS_COLORS: Record<string, string> = {
        online: "text-success-500",
        waiting: "text-warning-500",
        idle: "text-primary-500",
        stale: "text-error-400",
        offline: "text-surface-400",
    };

    const STATUS_LABELS: Record<string, string> = {
        online: "Online",
        waiting: "Waiting",
        idle: "Idle",
        stale: "Stale",
        offline: "Offline",
    };

    const availablePrograms = $derived(
        programs.filter(
            (p) =>
                p.edge_locked_by_device_id === null ||
                p.edge_locked_by_device_id === assigningDevice?.id,
        ),
    );
</script>

<!-- Header -->
<div class="flex items-center justify-between mb-4">
    <div>
        <h2
            class="text-base font-semibold text-surface-950 dark:text-white flex items-center gap-2"
        >
            <MonitorSmartphone class="w-4 h-4" />
            Edge Devices
        </h2>
        {#if !loading}
            <p class="text-xs text-surface-500 mt-0.5">
                {slotsUsed} of {slotsTotal} slot{slotsTotal !== 1 ? "s" : ""} used
            </p>
        {/if}
    </div>
    <div class="flex items-center gap-2">
        <button
            type="button"
            class="btn preset-outlined btn-sm"
            onclick={loadDevices}
            disabled={loading}
            aria-label="Refresh device list"
        >
            <RefreshCw class="w-3.5 h-3.5 {loading ? 'animate-spin' : ''}" />
        </button>
        <button
            type="button"
            class="btn preset-filled-primary-500 btn-sm flex items-center gap-1.5"
            onclick={() => {
                showAddModal = true;
            }}
            disabled={slotsUsed >= slotsTotal}
            title={slotsUsed >= slotsTotal
                ? "Slot limit reached. Revoke a device or increase the limit."
                : "Generate a pairing code for a new device"}
        >
            <Plus class="w-3.5 h-3.5" /> Add Device
        </button>
    </div>
</div>

{#if errorMsg}
    <div class="alert preset-tonal-error mb-3 text-sm" role="alert">
        {errorMsg}
    </div>
{/if}

<!-- Device list -->
{#if loading}
    <p class="text-sm text-surface-500">Loading…</p>
{:else if devices.length === 0}
    <p class="text-sm text-surface-500">
        No edge devices paired yet. Click "Add Device" to generate a pairing
        code.
    </p>
{:else}
    <div class="overflow-x-auto">
        <table class="table text-sm w-full">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Program</th>
                    <th>Mode</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                {#each devices as device (device.id)}
                    <tr>
                        <td class="font-medium text-surface-950 dark:text-white"
                            >{device.name}</td
                        >
                        <td>
                            <span
                                class="flex items-center gap-1.5 {STATUS_COLORS[
                                    device.status
                                ]}"
                            >
                                <Circle class="w-2 h-2 fill-current" />
                                {STATUS_LABELS[device.status]}
                            </span>
                        </td>
                        <td class="text-surface-600 dark:text-surface-300">
                            {device.assigned_program_name ?? "—"}
                        </td>
                        <td class="text-surface-600 dark:text-surface-300">
                            {device.sync_mode === "end_of_event"
                                ? "End of Event"
                                : "Auto"}
                        </td>
                        <td class="text-right">
                            <div class="relative inline-block">
                                <button
                                    type="button"
                                    class="btn preset-ghost btn-sm"
                                    onclick={() => {
                                        openDropdownId =
                                            openDropdownId === device.id
                                                ? null
                                                : device.id;
                                    }}
                                    aria-label="Device actions for {device.name}"
                                >
                                    <MoreHorizontal class="w-4 h-4" />
                                </button>
                                {#if openDropdownId === device.id}
                                    <div
                                        class="absolute right-0 z-20 mt-1 w-48 bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-container shadow-lg py-1"
                                        role="menu"
                                    >
                                        <button
                                            type="button"
                                            class="w-full text-left px-4 py-2 text-sm hover:bg-surface-100 dark:hover:bg-surface-700"
                                            role="menuitem"
                                            onclick={() =>
                                                openAssignModal(device)}
                                        >
                                            Assign / Configure
                                        </button>
                                        <button
                                            type="button"
                                            class="w-full text-left px-4 py-2 text-sm text-error-600 hover:bg-surface-100 dark:hover:bg-surface-700"
                                            role="menuitem"
                                            onclick={() => {
                                                revokingDevice = device;
                                                openDropdownId = null;
                                            }}
                                        >
                                            Revoke Device
                                        </button>
                                    </div>
                                {/if}
                            </div>
                        </td>
                    </tr>
                {/each}
            </tbody>
        </table>
    </div>
{/if}

<!-- Add Device Modal -->
{#if showAddModal}
    <div
        class="modal-backdrop"
        role="presentation"
        onclick={closeAddModal}
    ></div>
    <div
        class="modal-container"
        role="dialog"
        aria-modal="true"
        aria-labelledby="add-device-title"
    >
        <div class="modal-content max-w-sm">
            <h3
                id="add-device-title"
                class="text-base font-semibold mb-4 text-surface-950 dark:text-white"
            >
                Add Edge Device
            </h3>

            {#if generatedCode}
                <p class="text-sm text-surface-600 dark:text-surface-300 mb-2">
                    Pairing code (valid 10 minutes, single-use):
                </p>
                <div class="text-center my-4">
                    <span
                        class="text-3xl font-mono font-bold tracking-widest text-primary-600"
                    >
                        {generatedCode}
                    </span>
                </div>
                <p class="text-xs text-surface-500 text-center mb-4">
                    Enter this code in the edge device setup wizard.
                </p>
                <button
                    type="button"
                    class="btn preset-filled w-full"
                    onclick={closeAddModal}
                >
                    Done
                </button>
            {:else}
                <label class="label mb-1" for="add-device-name">
                    <span class="label-text">Device name</span>
                </label>
                <input
                    id="add-device-name"
                    type="text"
                    class="input w-full mb-4"
                    placeholder="e.g. Field Pi 1"
                    bind:value={addDeviceName}
                />
                <div class="flex gap-2 justify-end">
                    <button
                        type="button"
                        class="btn preset-outlined"
                        onclick={closeAddModal}
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="btn preset-filled-primary-500"
                        onclick={handleGenerateCode}
                        disabled={addSubmitting || !addDeviceName.trim()}
                    >
                        {addSubmitting ? "Generating…" : "Generate Code"}
                    </button>
                </div>
            {/if}
        </div>
    </div>
{/if}

<!-- Assign / Configure Modal -->
{#if assigningDevice}
    <div
        class="modal-backdrop"
        role="presentation"
        onclick={() => {
            assigningDevice = null;
        }}
    ></div>
    <div class="modal-container" role="dialog" aria-modal="true">
        <div class="modal-content max-w-sm">
            <h3
                class="text-base font-semibold mb-4 text-surface-950 dark:text-white"
            >
                Configure — {assigningDevice.name}
            </h3>

            <div class="flex flex-col gap-4">
                <div>
                    <label class="label mb-1" for="assign-program">
                        <span class="label-text">Assigned program</span>
                    </label>
                    <select
                        id="assign-program"
                        class="select w-full"
                        bind:value={assignProgramId}
                    >
                        <option value={null}>— None —</option>
                        {#each availablePrograms as program (program.id)}
                            <option value={program.id}>{program.name}</option>
                        {/each}
                    </select>
                </div>

                <div>
                    <p class="label-text mb-2">Sync mode</p>
                    <div class="flex gap-4">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input
                                type="radio"
                                class="radio"
                                bind:group={assignSyncMode}
                                value="auto"
                            />
                            <span class="text-sm">Auto</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input
                                type="radio"
                                class="radio"
                                bind:group={assignSyncMode}
                                value="end_of_event"
                            />
                            <span class="text-sm">End of Event</span>
                        </label>
                    </div>
                </div>

                <label class="flex items-center gap-2 cursor-pointer">
                    <input
                        type="checkbox"
                        class="checkbox"
                        bind:checked={assignSupervisor}
                    />
                    <span class="text-sm"
                        >Allow supervisor admin access on edge</span
                    >
                </label>
            </div>

            <div class="flex gap-2 justify-end mt-6">
                <button
                    type="button"
                    class="btn preset-outlined"
                    onclick={() => {
                        assigningDevice = null;
                    }}
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="btn preset-filled-primary-500"
                    onclick={handleAssignSubmit}
                    disabled={assignSubmitting}
                >
                    {assignSubmitting ? "Saving…" : "Save"}
                </button>
            </div>
        </div>
    </div>
{/if}

<!-- Revoke Confirm Modal -->
{#if revokingDevice}
    <div
        class="modal-backdrop"
        role="presentation"
        onclick={() => {
            revokingDevice = null;
        }}
    ></div>
    <div class="modal-container" role="dialog" aria-modal="true">
        <div class="modal-content max-w-sm">
            <h3 class="text-base font-semibold mb-2 text-error-600">
                Revoke Device
            </h3>
            <p class="text-sm text-surface-600 dark:text-surface-300 mb-4">
                Revoke <strong>{revokingDevice.name}</strong>? This permanently
                removes the device and releases any program lock. The device
                will be blocked on its next heartbeat.
            </p>
            <div class="flex gap-2 justify-end">
                <button
                    type="button"
                    class="btn preset-outlined"
                    onclick={() => {
                        revokingDevice = null;
                    }}
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="btn preset-filled-error-500"
                    onclick={handleRevoke}
                    disabled={revokeSubmitting}
                >
                    {revokeSubmitting ? "Revoking…" : "Revoke"}
                </button>
            </div>
        </div>
    </div>
{/if}

<style>
    .modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.4);
        z-index: 40;
    }
    .modal-container {
        position: fixed;
        inset: 0;
        z-index: 50;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }
    .modal-content {
        background: var(--color-surface-50, #fff);
        border-radius: var(--radius-container, 0.75rem);
        padding: 1.5rem;
        width: 100%;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
    }
</style>
