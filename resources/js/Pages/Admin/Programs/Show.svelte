<script lang="ts">
    import AdminLayout from "../../../Layouts/AdminLayout.svelte";
    import Modal from "../../../Components/Modal.svelte";
    import ConfirmModal from "../../../Components/ConfirmModal.svelte";
    import FlowDiagram from "../../../Components/FlowDiagram.svelte";
    import { get } from "svelte/store";
    import { Link, router, usePage } from "@inertiajs/svelte";

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
        CheckCircle,
        Clock,
        Settings,
        Users,
        ArrowRight,
        Activity,
        Calendar,
        GitMerge,
        FileText,
        Monitor,
        User,
        Power,
        Rocket,
    } from "lucide-svelte";

    interface ProgramItem {
        id: number;
        name: string;
        description: string | null;
        is_active: boolean;
        is_paused?: boolean;
        created_at: string | null;
        settings?: {
            no_show_timer_seconds: number;
            require_permission_before_override: boolean;
            priority_first: boolean;
            balance_mode: string;
            station_selection_mode?: string;
            alternate_ratio: [number, number];
        };
    }

    interface StepItem {
        id: number;
        track_id: number;
        process_id: number;
        process_name: string;
        step_order: number;
        is_required: boolean;
        estimated_minutes: number | null;
    }

    interface ProcessItem {
        id: number;
        program_id: number;
        name: string;
        description: string | null;
        created_at: string | null;
    }

    interface TrackItem {
        id: number;
        program_id: number;
        name: string;
        description: string | null;
        is_default: boolean;
        color_code: string | null;
        created_at: string | null;
        active_sessions_count?: number;
        steps?: StepItem[];
    }

    interface StationItem {
        id: number;
        program_id: number;
        name: string;
        capacity: number;
        client_capacity?: number;
        priority_first_override?: boolean | null;
        is_active: boolean;
        created_at: string | null;
        process_ids?: number[];
    }

    interface ProgramStats {
        total_sessions: number;
        active_sessions: number;
        completed_sessions: number;
    }

    let {
        program,
        tracks = [],
        processes = [],
        stations = [],
        stats = {
            total_sessions: 0,
            active_sessions: 0,
            completed_sessions: 0,
        },
    }: {
        program: ProgramItem;
        tracks: TrackItem[];
        processes?: ProcessItem[];
        stations: StationItem[];
        stats?: ProgramStats;
    } = $props();

    let activeTab = $state<
        "tracks" | "processes" | "stations" | "overview" | "settings" | "staff"
    >("overview");
    let showCreateModal = $state(false);
    let editTrack = $state<TrackItem | null>(null);
    let createName = $state("");
    let createDescription = $state("");
    let createIsDefault = $state(false);
    let createColorCode = $state("");
    let editName = $state("");
    let editDescription = $state("");
    let editIsDefault = $state(false);
    let editColorCode = $state("");
    let showCreateStationModal = $state(false);
    let editStation = $state<StationItem | null>(null);
    let createStationName = $state("");
    let createStationCapacity = $state(1);
    let createStationClientCapacity = $state(1);
    let createStationProcessIds = $state<number[]>([]);
    let editStationName = $state("");
    let editStationCapacity = $state(1);
    let editStationClientCapacity = $state(1);
    let editStationPriorityFirstOverride = $state<boolean | null>(null);
    let editStationIsActive = $state(true);
    let editStationProcessIds = $state<number[]>([]);
    let showStepModal = $state(false);
    let stepModalTrack = $state<TrackItem | null>(null);
    /** Steps shown in the modal; updated in place on add/delete/reorder so modal stays realtime */
    let modalSteps = $state<StepItem[]>([]);
    let addStepProcessId = $state<number | "">("");
    let addStepEstimatedMinutes = $state<number | "">("");
    let editingStepId = $state<number | null>(null);
    let editStepProcessId = $state<number | "">("");
    let editStepEstimatedMinutes = $state<number | "">("");
    let editStepIsRequired = $state(true);
    let createProcessName = $state("");
    let createProcessDescription = $state("");
    let creatingProcess = $state(false);
    let submitting = $state(false);
    let error = $state("");
    let settingsNoShowTimer = $state(10);
    let settingsRequireOverride = $state(true);
    let settingsPriorityFirst = $state(true);
    let settingsBalanceMode = $state<"fifo" | "alternate">("fifo");
    let settingsStationSelectionMode = $state<string>("fixed");
    let settingsAlternateRatioP = $state(2);
    let settingsAlternateRatioR = $state(1);

    $effect(() => {
        const s = program?.settings;
        if (s) {
            settingsNoShowTimer = s.no_show_timer_seconds ?? 10;
            settingsRequireOverride =
                s.require_permission_before_override ?? true;
            settingsPriorityFirst = s.priority_first ?? true;
            settingsBalanceMode = (
                s.balance_mode === "alternate" ? "alternate" : "fifo"
            ) as "fifo" | "alternate";
            settingsStationSelectionMode =
                s.station_selection_mode ?? "fixed";
            const ar = s.alternate_ratio ?? [2, 1];
            settingsAlternateRatioP = ar[0] ?? 2;
            settingsAlternateRatioR = ar[1] ?? 1;
        }
    });

    $effect(() => {
        if (activeTab !== "staff" || !program?.id) return;
        let cancelled = false;
        staffLoading = true;
        Promise.all([
            fetch(`/api/admin/programs/${program.id}/staff-assignments`, {
                headers: {
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            }).then((r) => r.json()),
            fetch(`/api/admin/programs/${program.id}/supervisors`, {
                headers: {
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            }).then((r) => r.json()),
        ])
            .then(([assignRes, superRes]) => {
                if (cancelled) return;
                staffAssignments = assignRes.assignments ?? [];
                staffStations = assignRes.stations ?? [];
                staffSupervisors = superRes.supervisors ?? [];
                staffWithPin = superRes.staff_with_pin ?? [];
            })
            .catch(() => {
                if (!cancelled) staffAssignments = [];
            })
            .finally(() => {
                if (!cancelled) staffLoading = false;
            });
        return () => {
            cancelled = true;
        };
    });
    let showReorderConfirm = $state(false);
    let pendingReorderStepIds = $state<number[]>([]);
    let reorderScope = $state<"new_only" | "migrate">("new_only");
    let confirmStopProgram = $state(false);
    let confirmDeleteTrack = $state<TrackItem | null>(null);
    let confirmDeleteStation = $state<StationItem | null>(null);
    let confirmRemoveStep = $state<{ step: StepItem; track: TrackItem } | null>(
        null,
    );
    // Staff tab state
    let staffAssignments = $state<
        Array<{
            user_id: number;
            user: { id: number; name: string; email: string };
            station_id: number | null;
            station: { id: number; name: string } | null;
        }>
    >([]);
    let staffStations = $state<Array<{ id: number; name: string }>>([]);
    let staffSupervisors = $state<
        Array<{ id: number; name: string; email: string }>
    >([]);
    let staffWithPin = $state<
        Array<{
            id: number;
            name: string;
            email: string;
            is_supervisor: boolean;
        }>
    >([]);
    let staffLoading = $state(false);
    let staffAssigningUserId = $state<number | null>(null);
    let staffAssigningStationId = $state<number | null>(null);

    /** Staff currently assigned to a station (first if multiple; null if none). */
    function getAssignedUserIdForStation(stationId: number): number | null {
        const a = staffAssignments.find((x) => x.station_id === stationId);
        return a?.user_id ?? null;
    }
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

    async function handlePause() {
        submitting = true;
        error = "";
        const { ok, message } = await api(
            "POST",
            `/api/admin/programs/${program.id}/pause`,
        );
        submitting = false;
        if (ok) router.reload();
        else error = message ?? "Failed to pause.";
    }

    async function handleResume() {
        submitting = true;
        error = "";
        const { ok, message } = await api(
            "POST",
            `/api/admin/programs/${program.id}/resume`,
        );
        submitting = false;
        if (ok) router.reload();
        else error = message ?? "Failed to resume.";
    }

    async function handleActivate() {
        submitting = true;
        error = "";
        const { ok, message } = await api(
            "POST",
            `/api/admin/programs/${program.id}/activate`,
        );
        submitting = false;
        if (ok) router.reload();
        else error = message ?? "Failed to start session.";
    }

    function openStopConfirm() {
        confirmStopProgram = true;
        error = "";
    }

    async function handleStopConfirm() {
        submitting = true;
        error = "";
        const { ok, message } = await api(
            "POST",
            `/api/admin/programs/${program.id}/deactivate`,
        );
        submitting = false;
        if (ok) {
            confirmStopProgram = false;
            router.reload();
        } else {
            error =
                message ??
                "You can only stop the session when no clients are in the queue.";
        }
    }

    function closeStopConfirm() {
        confirmStopProgram = false;
    }

    function openCreate() {
        createName = "";
        createDescription = "";
        createIsDefault = false;
        createColorCode = "";
        error = "";
        showCreateModal = true;
    }

    function openEdit(t: TrackItem) {
        editTrack = t;
        editName = t.name;
        editDescription = t.description ?? "";
        editIsDefault = t.is_default;
        editColorCode = t.color_code ?? "";
        error = "";
    }

    function openStepModal(t: TrackItem) {
        stepModalTrack = t;
        modalSteps = stepList(t);
        addStepProcessId = "";
        addStepEstimatedMinutes = "";
        editingStepId = null;
        error = "";
        showStepModal = true;
    }

    function openEditStep(step: StepItem) {
        editingStepId = step.id;
        editStepProcessId = step.process_id;
        editStepEstimatedMinutes = step.estimated_minutes ?? "";
        editStepIsRequired = step.is_required;
        error = "";
    }

    function cancelEditStep() {
        editingStepId = null;
    }

    async function handleUpdateStep() {
        if (editingStepId == null) return;
        submitting = true;
        error = "";
        const body: {
            process_id?: number;
            estimated_minutes?: number | null;
            is_required?: boolean;
        } = {};
        if (editStepProcessId !== "")
            body.process_id = editStepProcessId as number;
        body.estimated_minutes =
            editStepEstimatedMinutes === ""
                ? null
                : Number(editStepEstimatedMinutes);
        body.is_required = editStepIsRequired;
        const { ok, data, message } = await api(
            "PUT",
            `/api/admin/steps/${editingStepId}`,
            body,
        );
        submitting = false;
        if (ok && data?.step) {
            const updated = data.step as StepItem;
            modalSteps = modalSteps.map((s) =>
                s.id === updated.id ? updated : s,
            );
            editingStepId = null;
        } else {
            error = message ?? "Failed to update step.";
        }
    }

    function closeStepModal() {
        showStepModal = false;
        stepModalTrack = null;
        modalSteps = [];
        error = "";
        router.reload();
    }

    function closeModals() {
        showCreateModal = false;
        editTrack = null;
        showCreateStationModal = false;
        editStation = null;
        error = "";
    }

    async function handleCreate() {
        if (!createName.trim()) return;
        submitting = true;
        error = "";
        const { ok, data, message } = await api(
            "POST",
            `/api/admin/programs/${program.id}/tracks`,
            {
                name: createName.trim(),
                description: createDescription.trim() || null,
                is_default: createIsDefault,
                color_code: createColorCode.trim() || null,
            },
        );
        submitting = false;
        if (ok) {
            closeModals();
            router.reload();
        } else {
            error =
                message ??
                (data && "errors" in data
                    ? JSON.stringify(
                          (data as { errors?: Record<string, string[]> })
                              .errors,
                      )
                    : "Failed to create track.");
        }
    }

    async function handleUpdate() {
        if (!editTrack || !editName.trim()) return;
        submitting = true;
        error = "";
        const { ok, message } = await api(
            "PUT",
            `/api/admin/tracks/${editTrack.id}`,
            {
                name: editName.trim(),
                description: editDescription.trim() || null,
                is_default: editIsDefault,
                color_code: editColorCode.trim() || null,
            },
        );
        submitting = false;
        if (ok) {
            closeModals();
            router.reload();
        } else {
            error = message ?? "Failed to update track.";
        }
    }

    function openDeleteTrackConfirm(t: TrackItem) {
        confirmDeleteTrack = t;
        error = "";
    }

    async function handleDeleteTrackConfirm() {
        if (!confirmDeleteTrack) return;
        const t = confirmDeleteTrack;
        submitting = true;
        error = "";
        const { ok, message } = await api(
            "DELETE",
            `/api/admin/tracks/${t.id}`,
        );
        submitting = false;
        if (ok) {
            confirmDeleteTrack = null;
            router.reload();
        } else {
            error = message ?? "Cannot delete: active sessions use this track.";
        }
    }

    function closeDeleteTrackConfirm() {
        confirmDeleteTrack = null;
    }

    function openCreateStation() {
        createStationName = "";
        createStationCapacity = 1;
        createStationClientCapacity = 1;
        createStationProcessIds = [];
        error = "";
        showCreateStationModal = true;
    }

    function openEditStation(s: StationItem) {
        editStation = s;
        editStationName = s.name;
        editStationCapacity = s.capacity;
        editStationClientCapacity = s.client_capacity ?? 1;
        editStationPriorityFirstOverride = s.priority_first_override ?? null;
        editStationIsActive = s.is_active;
        editStationProcessIds = [...(s.process_ids ?? [])];
        error = "";
    }

    function toggleCreateProcess(id: number) {
        if (createStationProcessIds.includes(id)) {
            createStationProcessIds = createStationProcessIds.filter((x) => x !== id);
        } else {
            createStationProcessIds = [...createStationProcessIds, id];
        }
    }

    function toggleEditProcess(id: number) {
        if (editStationProcessIds.includes(id)) {
            editStationProcessIds = editStationProcessIds.filter((x) => x !== id);
        } else {
            editStationProcessIds = [...editStationProcessIds, id];
        }
    }

    async function handleCreateStation() {
        if (!createStationName.trim()) return;
        if (createStationProcessIds.length === 0) {
            error = "Select at least one process.";
            return;
        }
        submitting = true;
        error = "";
        const { ok, data, message } = await api(
            "POST",
            `/api/admin/programs/${program.id}/stations`,
            {
                name: createStationName.trim(),
                capacity: createStationCapacity,
                client_capacity: createStationClientCapacity,
                process_ids: createStationProcessIds,
            },
        );
        submitting = false;
        if (ok) {
            closeModals();
            router.reload();
        } else {
            const d = data as { message?: string; errors?: Record<string, string[]> };
            const firstErr = d?.errors ? Object.values(d.errors).flat()[0] : null;
            error = firstErr ?? message ?? "Failed to create station.";
        }
    }

    async function handleCreateProcess() {
        if (!createProcessName.trim()) return;
        creatingProcess = true;
        submitting = true;
        error = "";
        const { ok, data, message } = await api(
            "POST",
            `/api/admin/programs/${program.id}/processes`,
            {
                name: createProcessName.trim(),
                description: createProcessDescription.trim() || null,
            },
        );
        creatingProcess = false;
        submitting = false;
        if (ok) {
            createProcessName = "";
            createProcessDescription = "";
            router.reload();
        } else {
            const d = data as { message?: string; errors?: Record<string, string[]> };
            const firstErr = d?.errors ? Object.values(d.errors).flat()[0] : null;
            error = firstErr ?? d?.message ?? message ?? "Failed to create process.";
        }
    }

    async function handleUpdateStation() {
        if (!editStation || !editStationName.trim()) return;
        if (editStationProcessIds.length === 0) {
            error = "Select at least one process.";
            return;
        }
        submitting = true;
        error = "";
        const { ok, data, message } = await api(
            "PUT",
            `/api/admin/stations/${editStation.id}`,
            {
                name: editStationName.trim(),
                capacity: editStationCapacity,
                client_capacity: editStationClientCapacity,
                priority_first_override: editStationPriorityFirstOverride,
                is_active: editStationIsActive,
                process_ids: editStationProcessIds,
            },
        );
        submitting = false;
        if (ok) {
            closeModals();
            router.reload();
        } else {
            const d = data as { message?: string; errors?: Record<string, string[]> };
            const firstErr = d?.errors ? Object.values(d.errors).flat()[0] : null;
            error = firstErr ?? message ?? "Failed to update station.";
        }
    }

    function openDeleteStationConfirm(s: StationItem) {
        confirmDeleteStation = s;
        error = "";
    }

    async function handleDeleteStationConfirm() {
        if (!confirmDeleteStation) return;
        const s = confirmDeleteStation;
        submitting = true;
        error = "";
        const { ok, message } = await api(
            "DELETE",
            `/api/admin/stations/${s.id}`,
        );
        submitting = false;
        if (ok) {
            confirmDeleteStation = null;
            router.reload();
        } else {
            error = message ?? "Cannot delete: station is used in track steps.";
        }
    }

    function closeDeleteStationConfirm() {
        confirmDeleteStation = null;
    }

    async function handleToggleStationActive(s: StationItem) {
        submitting = true;
        error = "";
        const { ok, message } = await api(
            "PUT",
            `/api/admin/stations/${s.id}`,
            {
                name: s.name,
                capacity: s.capacity,
                client_capacity: s.client_capacity ?? 1,
                is_active: !s.is_active,
            },
        );
        submitting = false;
        if (ok) {
            router.reload();
        } else {
            error = message ?? "Failed to update station.";
        }
    }

    async function handleAddStep() {
        if (!stepModalTrack || addStepProcessId === "") return;
        submitting = true;
        error = "";
        const body: { process_id: number; estimated_minutes?: number } = {
            process_id: addStepProcessId as number,
        };
        if (addStepEstimatedMinutes !== "")
            body.estimated_minutes = addStepEstimatedMinutes as number;
        const { ok, data, message } = await api(
            "POST",
            `/api/admin/tracks/${stepModalTrack.id}/steps`,
            body,
        );
        submitting = false;
        if (ok && data?.step) {
            const s = data.step as StepItem;
            modalSteps = [...modalSteps, s].sort(
                (a, b) => a.step_order - b.step_order,
            );
            addStepProcessId = "";
            addStepEstimatedMinutes = "";
        } else {
            error = message ?? "Failed to add step.";
        }
    }

    function openRemoveStepConfirm(step: StepItem) {
        if (!stepModalTrack) return;
        confirmRemoveStep = { step, track: stepModalTrack };
        error = "";
    }

    async function handleRemoveStepConfirm() {
        if (!confirmRemoveStep) return;
        const { step } = confirmRemoveStep;
        submitting = true;
        error = "";
        const { ok, message } = await api(
            "DELETE",
            `/api/admin/steps/${step.id}`,
        );
        submitting = false;
        if (ok) {
            modalSteps = modalSteps.filter((s) => s.id !== step.id);
            confirmRemoveStep = null;
        } else {
            error = message ?? "Failed to delete step.";
        }
    }

    function closeRemoveStepConfirm() {
        confirmRemoveStep = null;
    }

    function stepList(t: TrackItem): StepItem[] {
        const s = (t.steps ?? [])
            .slice()
            .sort((a, b) => a.step_order - b.step_order);
        return s;
    }

    async function handleMoveStepUp(step: StepItem) {
        if (!stepModalTrack) return;
        const list = [...modalSteps];
        const i = list.findIndex((s) => s.id === step.id);
        if (i <= 0) return;
        [list[i - 1], list[i]] = [list[i], list[i - 1]];
        const stepIds = list.map((s) => s.id);
        const activeCount = stepModalTrack.active_sessions_count ?? 0;
        if (activeCount > 0) {
            pendingReorderStepIds = stepIds;
            reorderScope = "new_only";
            showReorderConfirm = true;
        } else {
            await submitReorder(stepModalTrack, stepIds, false);
        }
    }

    async function handleMoveStepDown(step: StepItem) {
        if (!stepModalTrack) return;
        const list = [...modalSteps];
        const i = list.findIndex((s) => s.id === step.id);
        if (i < 0 || i >= list.length - 1) return;
        [list[i], list[i + 1]] = [list[i + 1], list[i]];
        const stepIds = list.map((s) => s.id);
        const activeCount = stepModalTrack.active_sessions_count ?? 0;
        if (activeCount > 0) {
            pendingReorderStepIds = stepIds;
            reorderScope = "new_only";
            showReorderConfirm = true;
        } else {
            await submitReorder(stepModalTrack, stepIds, false);
        }
    }

    function closeReorderConfirm() {
        showReorderConfirm = false;
        pendingReorderStepIds = [];
        reorderScope = "new_only";
    }

    async function confirmReorderApply() {
        if (!stepModalTrack) return;
        await submitReorder(
            stepModalTrack,
            pendingReorderStepIds,
            reorderScope === "migrate",
        );
        closeReorderConfirm();
    }

    async function submitReorder(
        t: TrackItem,
        stepIds: number[],
        migrateSessions: boolean = false,
    ) {
        submitting = true;
        error = "";
        const { ok, data, message } = await api(
            "POST",
            `/api/admin/tracks/${t.id}/steps/reorder`,
            {
                step_ids: stepIds,
                migrate_sessions: migrateSessions,
            },
        );
        submitting = false;
        if (ok && data?.steps) {
            modalSteps = data.steps as StepItem[];
        } else {
            error = message ?? "Failed to reorder steps.";
        }
    }

    async function handleSaveSettings() {
        submitting = true;
        error = "";
        const { ok, message } = await api(
            "PUT",
            `/api/admin/programs/${program.id}`,
            {
                name: program.name,
                description: program.description,
                settings: {
                    no_show_timer_seconds: settingsNoShowTimer,
                    require_permission_before_override: settingsRequireOverride,
                    priority_first: settingsPriorityFirst,
                    balance_mode: settingsBalanceMode,
                    station_selection_mode: settingsStationSelectionMode,
                    alternate_ratio: [
                        settingsAlternateRatioP,
                        settingsAlternateRatioR,
                    ],
                },
            },
        );
        submitting = false;
        if (ok) router.reload();
        else error = message ?? "Failed to save settings.";
    }

    async function handleAssignStaff(userId: number, stationId: number | null) {
        staffAssigningUserId = userId;
        error = "";
        if (stationId === null) {
            const { ok, message: msg } = await api(
                "DELETE",
                `/api/admin/programs/${program.id}/staff-assignments/${userId}`,
            );
            if (ok) {
                staffAssignments = staffAssignments.map((a) =>
                    a.user_id === userId
                        ? { ...a, station_id: null, station: null }
                        : a,
                );
            } else error = msg ?? "Failed to unassign.";
        } else {
            const { ok, message: msg } = await api(
                "POST",
                `/api/admin/programs/${program.id}/staff-assignments`,
                {
                    user_id: userId,
                    station_id: stationId,
                },
            );
            if (ok) {
                const station = staffStations.find((s) => s.id === stationId);
                staffAssignments = staffAssignments.map((a) =>
                    a.user_id === userId
                        ? {
                              ...a,
                              station_id: stationId,
                              station: station
                                  ? { id: station.id, name: station.name }
                                  : null,
                          }
                        : a,
                );
            } else error = msg ?? "Failed to assign.";
        }
        staffAssigningUserId = null;
    }

    /** Station-centric: assign or unassign staff for a station (replaces any existing). */
    async function handleAssignStaffForStation(
        stationId: number,
        userId: number | null,
    ) {
        staffAssigningStationId = stationId;
        error = "";
        const current = getAssignedUserIdForStation(stationId);
        if (userId === null) {
            if (current != null) {
                await handleAssignStaff(current, null);
            }
        } else {
            if (current != null && current !== userId) {
                await handleAssignStaff(current, null);
            }
            await handleAssignStaff(userId, stationId);
        }
        staffAssigningStationId = null;
    }

    async function handleAddSupervisor(userId: number) {
        error = "";
        const { ok, message: msg } = await api(
            "POST",
            `/api/admin/programs/${program.id}/supervisors`,
            { user_id: userId },
        );
        if (ok) {
            const u = staffWithPin.find((u) => u.id === userId);
            if (u) {
                staffSupervisors = [
                    ...staffSupervisors,
                    { id: u.id, name: u.name, email: u.email },
                ];
                staffWithPin = staffWithPin.map((x) =>
                    x.id === userId ? { ...x, is_supervisor: true } : x,
                );
            }
        } else error = msg ?? "Failed to add supervisor.";
    }

    async function handleRemoveSupervisor(userId: number) {
        error = "";
        const removed = staffSupervisors.find((s) => s.id === userId);
        const { ok, message: msg } = await api(
            "DELETE",
            `/api/admin/programs/${program.id}/supervisors/${userId}`,
        );
        if (ok) {
            staffSupervisors = staffSupervisors.filter((s) => s.id !== userId);
            const inList = staffWithPin.some((u) => u.id === userId);
            if (inList) {
                staffWithPin = staffWithPin.map((x) =>
                    x.id === userId ? { ...x, is_supervisor: false } : x,
                );
            } else if (removed) {
                // Ensure removed supervisor can be re-added from the "Add supervisor" list
                staffWithPin = [
                    ...staffWithPin,
                    {
                        id: removed.id,
                        name: removed.name,
                        email: removed.email,
                        is_supervisor: false,
                    },
                ];
            }
        } else error = msg ?? "Failed to remove supervisor.";
    }

    function formatDate(iso: string | null): string {
        if (!iso) return "";
        try {
            return new Date(iso).toLocaleDateString(undefined, {
                dateStyle: "medium",
            });
        } catch {
            return iso;
        }
    }
</script>

<svelte:head>
    <title>{program.name} — Programs — FlexiQueue</title>
</svelte:head>

<AdminLayout>
    <div class="flex flex-col gap-6">
        <!-- Back link -->
        <div>
            <Link
                href="/admin/programs"
                class="btn preset-tonal btn-sm flex items-center gap-1.5 text-surface-950 hover:bg-surface-200 transition-colors w-fit"
            >
                <ArrowRight class="w-4 h-4 rotate-180" />
                Programs
            </Link>
        </div>

        <!-- Program header -->
        <div
            class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4"
        >
            <div>
                <h1
                    class="text-2xl font-bold text-surface-950 flex items-center gap-2"
                >
                    {program.name}
                </h1>
                <div
                    class="text-sm text-surface-600 mt-2 flex items-center gap-3 flex-wrap"
                >
                    {#if program.is_active}
                        <span
                            class="text-xs uppercase tracking-wider font-bold px-2.5 py-1 rounded preset-filled-success-500 flex items-center gap-1 shadow-sm"
                        >
                            <Activity class="w-3.5 h-3.5" /> Live
                        </span>
                    {:else}
                        <span
                            class="text-xs uppercase tracking-wider font-bold px-2.5 py-1 rounded preset-tonal text-surface-600 flex items-center gap-1"
                        >
                            <Square class="w-3.5 h-3.5" /> Inactive
                        </span>
                    {/if}
                    <span
                        class="flex items-center gap-1.5 bg-surface-100 px-2.5 py-1 rounded text-surface-700"
                    >
                        <Calendar class="w-3.5 h-3.5" />
                        Created {formatDate(program.created_at)}
                    </span>
                </div>
                {#if program.description}
                    <p class="mt-3 text-surface-600 max-w-3xl leading-relaxed">
                        {program.description}
                    </p>
                {/if}
            </div>
        </div>

        <!-- Status banner + primary actions -->
        {#if program.is_active && !program.is_paused}
            <div
                class="rounded-container border-l-4 border-l-success-500 border border-success-200 bg-success-50 shadow-sm p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-5 transition-all"
            >
                <div class="flex gap-3">
                    <div class="mt-0.5">
                        <CheckCircle class="w-5 h-5 text-success-600" />
                    </div>
                    <div>
                        <h3 class="font-semibold text-success-900">
                            Program is Live
                        </h3>
                        <p class="text-sm text-success-800/80 mt-0.5">
                            Queue times are being actively recorded and tracked.
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <Link
                        href="/triage"
                        class="btn preset-filled-primary-500 flex items-center gap-2 shadow-sm hover:shadow-md transition-shadow"
                        title="Open triage to scan tokens and assign tracks"
                    >
                        <Activity class="w-4 h-4" />
                        Open Triage
                    </Link>
                    <div class="h-6 w-px bg-success-200 hidden sm:block"></div>
                    <button
                        type="button"
                        class="btn preset-tonal flex items-center gap-2 text-warning-700 bg-warning-100 hover:bg-warning-200 transition-colors"
                        disabled={submitting}
                        onclick={handlePause}
                    >
                        <Pause class="w-4 h-4" /> Pause
                    </button>
                    <button
                        type="button"
                        class="btn preset-tonal flex items-center gap-2 text-error-700 bg-error-100 hover:bg-error-200 transition-colors"
                        disabled={submitting}
                        onclick={openStopConfirm}
                        aria-label="Stop session"
                    >
                        <Square class="w-4 h-4" /> Stop Session
                    </button>
                </div>
            </div>
        {:else if program.is_active && program.is_paused}
            <div
                class="rounded-container border-l-4 border-l-warning-500 border border-warning-200 bg-warning-50 shadow-sm p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-5 transition-all"
            >
                <div class="flex gap-3">
                    <div class="mt-0.5">
                        <AlertCircle class="w-5 h-5 text-warning-600" />
                    </div>
                    <div>
                        <h3 class="font-semibold text-warning-900">
                            Program is Paused
                        </h3>
                        <p class="text-sm text-warning-800/80 mt-0.5">
                            Queue operations are temporarily halted.
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    class="btn preset-filled-primary-500 flex items-center gap-2 shrink-0 shadow-sm hover:shadow-md transition-shadow"
                    disabled={submitting}
                    onclick={handleResume}
                >
                    <Play class="w-4 h-4" /> Resume Operations
                </button>
            </div>
        {:else}
            <div
                class="rounded-container border border-surface-200 bg-surface-50 shadow-sm p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-5 transition-all"
            >
                <div class="flex gap-3">
                    <div
                        class="mt-0.5 bg-primary-100 w-9 h-9 flex items-center justify-center rounded-full shrink-0"
                    >
                        <Rocket class="w-5 h-5 text-primary-600" />
                    </div>
                    <div>
                        <h3 class="font-semibold text-surface-900">
                            Ready to Start
                        </h3>
                        <p class="text-sm text-surface-600 mt-0.5">
                            Start a session to begin routing clients into the
                            queue.
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    class="btn preset-filled-primary-500 flex items-center gap-2 shrink-0 shadow-sm hover:shadow-md transition-shadow"
                    disabled={submitting}
                    onclick={handleActivate}
                >
                    <Play class="w-4 h-4" /> Start Session
                </button>
            </div>
        {/if}

        <!-- Tab navigation (BD-009, BD-010) — Skeleton-compatible tabs, Overview first -->
        <div role="tablist" class="tabs">
            <button
                type="button"
                role="tab"
                class="tab"
                class:tab-active={activeTab === "overview"}
                onclick={() => (activeTab = "overview")}
            >
                Overview
            </button>
            <button
                type="button"
                role="tab"
                class="tab"
                class:tab-active={activeTab === "tracks"}
                onclick={() => (activeTab = "tracks")}
            >
                Tracks
            </button>
            <button
                type="button"
                role="tab"
                class="tab"
                class:tab-active={activeTab === "processes"}
                onclick={() => (activeTab = "processes")}
            >
                Processes
            </button>
            <button
                type="button"
                role="tab"
                class="tab"
                class:tab-active={activeTab === "stations"}
                onclick={() => (activeTab = "stations")}
            >
                Stations
            </button>
            <button
                type="button"
                role="tab"
                class="tab"
                class:tab-active={activeTab === "staff"}
                onclick={() => (activeTab = "staff")}
            >
                Staff
            </button>
            <button
                type="button"
                role="tab"
                class="tab"
                class:tab-active={activeTab === "settings"}
                onclick={() => (activeTab = "settings")}
            >
                Settings
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

        {#if activeTab === "overview"}
            <div class="space-y-8">
                <section>
                    <h2 class="text-lg font-semibold text-surface-950 mb-4">
                        Program stats
                    </h2>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div
                            class="rounded-container bg-surface-50 border border-surface-200 p-5 shadow-sm flex items-center justify-between"
                        >
                            <div>
                                <p
                                    class="text-sm font-medium text-surface-600 mb-1"
                                >
                                    Total sessions
                                </p>
                                <p class="text-3xl font-bold text-surface-950">
                                    {stats.total_sessions}
                                </p>
                            </div>
                            <div class="bg-surface-100 p-3 rounded-full">
                                <FileText class="w-6 h-6 text-surface-500" />
                            </div>
                        </div>
                        <div
                            class="rounded-container bg-surface-50 border border-primary-200 p-5 shadow-sm flex items-center justify-between"
                        >
                            <div>
                                <p
                                    class="text-sm font-medium text-surface-600 mb-1"
                                >
                                    Active in queue
                                </p>
                                <p class="text-3xl font-bold text-primary-600">
                                    {stats.active_sessions}
                                </p>
                            </div>
                            <div class="bg-primary-50 p-3 rounded-full">
                                <Activity class="w-6 h-6 text-primary-500" />
                            </div>
                        </div>
                        <div
                            class="rounded-container bg-surface-50 border border-surface-200 p-5 shadow-sm flex items-center justify-between"
                        >
                            <div>
                                <p
                                    class="text-sm font-medium text-surface-600 mb-1"
                                >
                                    Completed / No-show
                                </p>
                                <p class="text-3xl font-bold text-surface-950">
                                    {stats.completed_sessions}
                                </p>
                            </div>
                            <div class="bg-surface-100 p-3 rounded-full">
                                <CheckCircle class="w-6 h-6 text-surface-500" />
                            </div>
                        </div>
                    </div>
                </section>
                <section>
                    <h2 class="text-lg font-semibold text-surface-950 mb-4">
                        Track flow
                    </h2>
                    <FlowDiagram {tracks} />
                </section>
            </div>
        {:else if activeTab === "tracks"}
            <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
                <div>
                    <h2 class="text-lg font-semibold text-surface-950">
                        Tracks
                    </h2>
                    <p class="text-sm text-surface-600 mt-0.5">
                        Define service lanes and their step sequences.
                    </p>
                </div>
                <button
                    type="button"
                    class="btn preset-filled-primary-500 flex items-center gap-2 shadow-sm"
                    onclick={openCreate}
                >
                    <Plus class="w-4 h-4" /> Add Track
                </button>
            </div>
            {#if tracks.length === 0}
                <div
                    class="rounded-container bg-surface-50 border border-surface-200 p-12 flex flex-col items-center justify-center text-center shadow-sm"
                >
                    <div
                        class="bg-surface-100 p-4 rounded-full text-surface-400 mb-4"
                    >
                        <GitMerge class="w-8 h-8" />
                    </div>
                    <h3 class="text-lg font-semibold text-surface-950">
                        No tracks defined
                    </h3>
                    <p class="text-surface-600 max-w-sm mt-2 mb-6">
                        Create a track to define a service lane (e.g., Regular,
                        Priority) and its sequence of stations.
                    </p>
                    <button
                        type="button"
                        class="btn preset-filled-primary-500 flex items-center gap-2"
                        onclick={openCreate}
                    >
                        <Plus class="w-4 h-4" /> Create First Track
                    </button>
                </div>
            {:else}
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {#each tracks as track (track.id)}
                        <div
                            class="bg-surface-50 rounded-container elevation-card transition-all hover:shadow-[var(--shadow-raised)] flex flex-col h-full border border-surface-200/50"
                        >
                            <div class="p-5 flex-grow flex flex-col gap-3">
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <div class="flex items-center gap-2">
                                        {#if track.color_code}
                                            <span
                                                class="inline-block h-3.5 w-3.5 rounded-full border border-surface-200 shrink-0 shadow-sm"
                                                style="background-color: {track.color_code}"
                                                title={track.color_code}
                                            ></span>
                                        {/if}
                                        <h3
                                            class="text-lg font-bold text-surface-950 line-clamp-1"
                                        >
                                            {track.name}
                                        </h3>
                                    </div>
                                    <div class="shrink-0 mt-1">
                                        {#if track.is_default}
                                            <span
                                                class="text-[10px] uppercase tracking-wider font-bold px-2 py-1 rounded preset-filled-primary-500 shadow-sm"
                                                >Default</span
                                            >
                                        {/if}
                                    </div>
                                </div>
                                {#if track.description}
                                    <p
                                        class="text-sm text-surface-600 line-clamp-2"
                                    >
                                        {track.description}
                                    </p>
                                {:else}
                                    <p class="text-sm text-surface-400 italic">
                                        No description provided.
                                    </p>
                                {/if}
                                {#if (track.steps ?? []).length > 0}
                                    <div
                                        class="mt-2 bg-surface-100/50 rounded p-2 border border-surface-200/50"
                                    >
                                        <p
                                            class="text-xs font-medium text-surface-500 mb-1 uppercase tracking-wider"
                                        >
                                            Flow Sequence
                                        </p>
                                        <p
                                            class="text-sm text-surface-700 flex items-center flex-wrap gap-1.5"
                                        >
                                            {#each track.steps ?? [] as step, i}
                                                <span
                                                    class="inline-block px-2 py-0.5 bg-white border border-surface-200 rounded text-xs shadow-[0_1px_2px_rgba(0,0,0,0.02)]"
                                                    >{step.process_name}</span
                                                >
                                                {#if i < (track.steps ?? []).length - 1}
                                                    <ArrowRight
                                                        class="w-3 h-3 text-surface-400"
                                                    />
                                                {/if}
                                            {/each}
                                        </p>
                                    </div>
                                {/if}
                            </div>
                            <div
                                class="px-5 py-3 border-t border-surface-100 flex items-center justify-between bg-surface-50/50 rounded-b-container"
                            >
                                <button
                                    type="button"
                                    class="btn preset-tonal btn-sm flex items-center gap-1.5 bg-white border border-surface-200 shadow-sm hover:bg-surface-50"
                                    onclick={() => openStepModal(track)}
                                    disabled={submitting}
                                >
                                    <GitMerge class="w-3.5 h-3.5" /> Steps
                                </button>
                                <div class="flex items-center gap-1">
                                    <button
                                        type="button"
                                        class="btn preset-tonal btn-sm p-2"
                                        onclick={() => openEdit(track)}
                                        disabled={submitting}
                                        title="Edit Track"
                                    >
                                        <Edit2
                                            class="w-4 h-4 text-surface-600"
                                        />
                                    </button>
                                    <button
                                        type="button"
                                        class="btn preset-tonal btn-sm p-2 hover:bg-error-50"
                                        onclick={() =>
                                            openDeleteTrackConfirm(track)}
                                        disabled={submitting}
                                        title="Delete Track"
                                    >
                                        <Trash2
                                            class="w-4 h-4 text-error-500"
                                        />
                                    </button>
                                </div>
                            </div>
                        </div>
                    {/each}
                </div>
            {/if}
        {:else if activeTab === "settings"}
            <div class="max-w-3xl">
                <div
                    class="flex flex-wrap items-center justify-between gap-4 mb-4"
                >
                    <div>
                        <h2 class="text-lg font-semibold text-surface-950">
                            Program Settings
                        </h2>
                        <p class="text-sm text-surface-600 mt-0.5">
                            Configure operational rules and routing behavior for
                            this program.
                        </p>
                    </div>
                </div>
                <div
                    class="rounded-container bg-surface-50 border border-surface-200 shadow-sm flex flex-col overflow-hidden"
                >
                    <div class="p-5 sm:p-6 space-y-6">
                        <!-- Setting 1 -->
                        <div
                            class="flex flex-col sm:flex-row gap-4 pb-6 border-b border-surface-200"
                        >
                            <div class="sm:w-1/3 shrink-0">
                                <h3
                                    class="font-medium text-surface-950 flex items-center gap-2"
                                >
                                    <Clock class="w-4 h-4 text-surface-500" /> No-show
                                    Timer
                                </h3>
                                <p class="text-xs text-surface-500 mt-1">
                                    Wait time before staff can mark a client as
                                    no-show.
                                </p>
                            </div>
                            <div class="sm:w-2/3 form-control">
                                <div class="flex items-center gap-3">
                                    <input
                                        id="no-show-timer"
                                        type="number"
                                        class="input rounded-container border border-surface-200 px-3 py-2 w-24 text-surface-950 bg-white shadow-sm"
                                        min="5"
                                        max="120"
                                        bind:value={settingsNoShowTimer}
                                    />
                                    <span class="text-sm text-surface-600"
                                        >seconds (default: 10)</span
                                    >
                                </div>
                            </div>
                        </div>

                        <!-- Setting 2 -->
                        <div
                            class="flex flex-col sm:flex-row gap-4 pb-6 border-b border-surface-200"
                        >
                            <div class="sm:w-1/3 shrink-0">
                                <h3
                                    class="font-medium text-surface-950 flex items-center gap-2"
                                >
                                    <Users class="w-4 h-4 text-surface-500" /> Priority
                                    First
                                </h3>
                                <p class="text-xs text-surface-500 mt-1">
                                    Call PWD, Senior, and Pregnant clients
                                    before Regular ones.
                                </p>
                            </div>
                            <div class="sm:w-2/3 form-control pt-1">
                                <label
                                    class="label cursor-pointer justify-start gap-3 w-fit hover:bg-surface-100 p-2 -ml-2 rounded-lg transition-colors"
                                >
                                    <input
                                        type="checkbox"
                                        class="checkbox"
                                        bind:checked={settingsPriorityFirst}
                                    />
                                    <span
                                        class="label-text text-surface-950 font-medium"
                                        >Enable priority first routing</span
                                    >
                                </label>
                            </div>
                        </div>

                        <!-- Setting 3 -->
                        <div
                            class="flex flex-col sm:flex-row gap-4 pb-6 border-b border-surface-200"
                            class:opacity-60={settingsPriorityFirst}
                        >
                            <div class="sm:w-1/3 shrink-0">
                                <h3
                                    class="font-medium text-surface-950 flex items-center gap-2"
                                >
                                    <GitMerge
                                        class="w-4 h-4 text-surface-500"
                                    /> Balance Mode
                                </h3>
                                <p class="text-xs text-surface-500 mt-1">
                                    How to balance queue when 'Priority First'
                                    is disabled.
                                </p>
                            </div>
                            <div class="sm:w-2/3 form-control space-y-3">
                                <select
                                    id="balance-mode"
                                    class="select rounded-container border border-surface-200 px-3 py-2 w-full text-surface-950 bg-white shadow-sm"
                                    bind:value={settingsBalanceMode}
                                    disabled={settingsPriorityFirst}
                                >
                                    <option value="fifo"
                                        >FIFO — Strict arrival order</option
                                    >
                                    <option value="alternate"
                                        >Alternate — Ratio of priority to
                                        regular</option
                                    >
                                </select>

                                {#if settingsBalanceMode === "alternate" && !settingsPriorityFirst}
                                    <div
                                        class="flex items-center gap-3 bg-surface-100 p-3 rounded-container border border-surface-200 text-sm"
                                    >
                                        <span
                                            class="font-medium text-surface-700"
                                            >Ratio Priority:Regular</span
                                        >
                                        <div
                                            class="flex flex-row items-center gap-2"
                                        >
                                            <input
                                                type="number"
                                                class="input rounded border border-surface-300 px-2 py-1.5 w-16 text-center text-surface-950 bg-white"
                                                min="1"
                                                max="10"
                                                bind:value={
                                                    settingsAlternateRatioP
                                                }
                                            />
                                            <span
                                                class="font-bold text-surface-400"
                                                >:</span
                                            >
                                            <input
                                                type="number"
                                                class="input rounded border border-surface-300 px-2 py-1.5 w-16 text-center text-surface-950 bg-white"
                                                min="1"
                                                max="10"
                                                bind:value={
                                                    settingsAlternateRatioR
                                                }
                                            />
                                        </div>
                                    </div>
                                {/if}
                            </div>
                        </div>

                        <!-- Setting 4: Station Selection Mode -->
                        <div
                            class="flex flex-col sm:flex-row gap-4 pb-6 border-b border-surface-200"
                        >
                            <div class="sm:w-1/3 shrink-0">
                                <h3
                                    class="font-medium text-surface-950 flex items-center gap-2"
                                >
                                    <GitMerge
                                        class="w-4 h-4 text-surface-500"
                                    /> Station Selection
                                </h3>
                                <p class="text-xs text-surface-500 mt-1">
                                    When multiple stations serve the same process, how to pick the station.
                                </p>
                            </div>
                            <div class="sm:w-2/3 form-control">
                                <select
                                    id="station-selection-mode"
                                    class="select rounded-container border border-surface-200 px-3 py-2 w-full text-surface-950 bg-white shadow-sm"
                                    bind:value={settingsStationSelectionMode}
                                >
                                    <option value="fixed"
                                        >Fixed — First configured station</option
                                    >
                                    <option value="shortest_queue"
                                        >Shortest Queue — Fewest waiting</option
                                    >
                                    <option value="least_busy"
                                        >Least Busy — Lowest active load</option
                                    >
                                    <option value="round_robin"
                                        >Round Robin — Rotate fairly</option
                                    >
                                    <option value="least_recently_served"
                                        >Least Recently Served</option
                                    >
                                </select>
                            </div>
                        </div>

                        <!-- Setting 5 -->
                        <div class="flex flex-col sm:flex-row gap-4">
                            <div class="sm:w-1/3 shrink-0">
                                <h3
                                    class="font-medium text-surface-950 flex items-center gap-2"
                                >
                                    <AlertCircle
                                        class="w-4 h-4 text-surface-500"
                                    /> Require Override PIN
                                </h3>
                                <p class="text-xs text-surface-500 mt-1">
                                    Require supervisor PIN to redirect clients
                                    to a different flow.
                                </p>
                            </div>
                            <div class="sm:w-2/3 form-control pt-1">
                                <label
                                    class="label cursor-pointer justify-start gap-3 w-fit hover:bg-surface-100 p-2 -ml-2 rounded-lg transition-colors"
                                >
                                    <input
                                        type="checkbox"
                                        class="checkbox"
                                        bind:checked={settingsRequireOverride}
                                    />
                                    <span
                                        class="label-text text-surface-950 font-medium"
                                        >Require supervisor PIN</span
                                    >
                                </label>
                            </div>
                        </div>
                    </div>

                    <div
                        class="bg-surface-100/50 px-5 py-4 border-t border-surface-200 flex justify-end"
                    >
                        <button
                            type="button"
                            class="btn preset-filled-primary-500 flex items-center gap-2 shadow-sm"
                            disabled={submitting}
                            onclick={handleSaveSettings}
                        >
                            {#if submitting}
                                <span class="loading-spinner loading-sm"></span>
                                Saving...
                            {:else}
                                Save settings
                            {/if}
                        </button>
                    </div>
                </div>
            </div>
        {:else if activeTab === "processes"}
            <div class="space-y-6">
                <section>
                    <h2 class="text-lg font-semibold text-surface-950 mb-2">
                        Processes
                    </h2>
                    <p class="text-sm text-surface-600 mb-4">
                        Define logical work types (e.g. Verification, Cash Release). Each track step references a process. Create processes before adding steps to tracks.
                    </p>
                    <div class="flex flex-wrap items-end gap-3 mb-6">
                        <div class="form-control min-w-[200px]">
                            <label for="create-process-name" class="label py-0"
                                ><span class="label-text text-xs">Name</span
                                ></label
                            >
                            <input
                                id="create-process-name"
                                type="text"
                                class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                                placeholder="e.g. Verification"
                                bind:value={createProcessName}
                                maxlength="50"
                            />
                        </div>
                        <div class="form-control min-w-[200px]">
                            <label for="create-process-desc" class="label py-0"
                                ><span class="label-text text-xs">Description (optional)</span
                                ></label
                            >
                            <input
                                id="create-process-desc"
                                type="text"
                                class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                                placeholder="Optional"
                                bind:value={createProcessDescription}
                            />
                        </div>
                        <button
                            type="button"
                            class="btn preset-filled-primary-500 flex items-center gap-2"
                            disabled={submitting || !createProcessName.trim()}
                            onclick={handleCreateProcess}
                        >
                            {#if submitting && creatingProcess}
                                <span class="loading-spinner loading-sm"></span>
                                Adding...
                            {:else}
                                <Plus class="w-4 h-4" /> Add Process
                            {/if}
                        </button>
                    </div>
                    {#if processes.length === 0}
                        <div
                            class="rounded-container bg-surface-50 border border-surface-200 p-8 text-center text-surface-600"
                        >
                            No processes yet. Add one above to use in track steps.
                        </div>
                    {:else}
                        <div class="flex flex-wrap gap-2">
                            {#each processes as proc (proc.id)}
                                <span
                                    class="inline-flex items-center px-3 py-1.5 rounded-lg bg-surface-100 border border-surface-200 text-sm font-medium text-surface-950"
                                >
                                    {proc.name}
                                    {#if proc.description}
                                        <span class="text-surface-500 text-xs ml-2"
                                            >— {proc.description}</span
                                        >
                                    {/if}
                                </span>
                            {/each}
                        </div>
                    {/if}
                </section>
            </div>
        {:else if activeTab === "staff"}
            <!-- Staff tab: station assignments + supervisors -->
            <div class="space-y-8">
                <section>
                    <h2 class="text-lg font-semibold text-surface-950 mb-4">
                        Station assignments
                    </h2>
                    <p class="text-sm text-surface-950/70 mb-4">
                        For each station, select which staff is assigned. You
                        see all stations as rows so role coverage stays clear
                        even with few staff.
                    </p>
                    {#if staffLoading}
                        <div
                            class="rounded-container bg-surface-50 border border-surface-200 p-12 flex flex-col items-center justify-center text-center shadow-sm"
                        >
                            <span
                                class="loading-spinner loading-lg text-primary-500 mb-4"
                            ></span>
                            <p
                                class="text-surface-600 font-medium animate-pulse"
                            >
                                Loading staff data...
                            </p>
                        </div>
                    {:else if staffAssignments.length === 0}
                        <div
                            class="rounded-container bg-surface-50 border border-surface-200 p-12 flex flex-col items-center justify-center text-center shadow-sm"
                        >
                            <div
                                class="bg-surface-100 p-4 rounded-full text-surface-400 mb-4"
                            >
                                <Users class="w-8 h-8" />
                            </div>
                            <h3 class="text-lg font-semibold text-surface-950">
                                No staff assigned
                            </h3>
                            <p class="text-surface-600 max-w-sm mt-2">
                                Add staff accounts from the main Staff
                                management page first.
                            </p>
                        </div>
                    {:else if staffStations.length === 0}
                        <div
                            class="bg-warning-100 text-warning-900 border border-warning-300 rounded-container p-4"
                        >
                            No stations in this program. Add stations first,
                            then assign staff.
                        </div>
                    {:else}
                        <div class="table-container">
                            <table class="table table-zebra">
                                <thead>
                                    <tr>
                                        <th>Station</th>
                                        <th>Assigned staff</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {#each staffStations as station (station.id)}
                                        {@const assignedUserId = getAssignedUserIdForStation(station.id)}
                                        <tr>
                                            <td>
                                                <span class="font-medium"
                                                    >{station.name}</span
                                                >
                                            </td>
                                            <td>
                                                <select
                                                    class="select rounded-container border border-surface-200 px-3 py-2 select-sm max-w-xs"
                                                    value={assignedUserId ?? ""}
                                                    disabled={staffAssigningStationId ===
                                                        station.id}
                                                    onchange={(e) => {
                                                        const val = (
                                                            e.target as HTMLSelectElement
                                                        ).value;
                                                        const uid =
                                                            val === ""
                                                                ? null
                                                                : Number(val);
                                                        handleAssignStaffForStation(
                                                            station.id,
                                                            uid,
                                                        );
                                                    }}
                                                >
                                                    <option value=""
                                                        >— Unassigned —</option
                                                    >
                                                    {#each staffAssignments as a (a.user_id)}
                                                        <option value={a.user_id}
                                                            >{a.user.name}
                                                            {#if staffSupervisors.some((s) => s.id === a.user_id)}
                                                                (Supervisor)
                                                            {/if}</option
                                                        >
                                                    {/each}
                                                </select>
                                            </td>
                                        </tr>
                                    {/each}
                                </tbody>
                            </table>
                        </div>
                    {/if}
                </section>

                <section>
                    <h2 class="text-lg font-semibold text-surface-950 mb-4">
                        Supervisors
                    </h2>
                    <p class="text-sm text-surface-950/70 mb-4">
                        Supervisors can approve flow overrides.
                    </p>
                    {#if staffLoading}
                        <div
                            class="rounded-container bg-surface-50 border border-surface-200 p-12 flex flex-col items-center justify-center text-center shadow-sm"
                        >
                            <span
                                class="loading-spinner loading-lg text-primary-500 mb-4"
                            ></span>
                            <p
                                class="text-surface-600 font-medium animate-pulse"
                            >
                                Loading supervisor data...
                            </p>
                        </div>
                    {:else}
                        <div class="space-y-4">
                            <div
                                class="rounded-container bg-surface-50 border border-surface-200 p-5 shadow-sm"
                            >
                                <h3 class="font-medium text-surface-950 mb-2">
                                    Current supervisors
                                </h3>
                                {#if staffSupervisors.length === 0}
                                    <p class="text-sm text-surface-950/70">
                                        None. Add staff with override PINs
                                        below.
                                    </p>
                                {:else}
                                    <ul class="flex flex-wrap gap-2">
                                        {#each staffSupervisors as s (s.id)}
                                            <li
                                                class="text-xs px-2 py-0.5 rounded preset-filled-primary-500 badge-lg gap-1"
                                            >
                                                {s.name}
                                                <button
                                                    type="button"
                                                    class="btn preset-tonal btn-xs"
                                                    aria-label="Remove {s.name}"
                                                    onclick={() =>
                                                        handleRemoveSupervisor(
                                                            s.id,
                                                        )}
                                                    disabled={submitting}
                                                >
                                                    ×
                                                </button>
                                            </li>
                                        {/each}
                                    </ul>
                                {/if}
                            </div>
                            <div
                                class="rounded-container bg-surface-50 border border-surface-200 p-5 shadow-sm"
                            >
                                <h3 class="font-medium text-surface-950 mb-2">
                                    Add supervisor (staff with PIN)
                                </h3>
                                {#if staffWithPin.filter((u) => !u.is_supervisor).length === 0}
                                    <p class="text-sm text-surface-950/70">
                                        No staff with override PIN left to add.
                                    </p>
                                {:else}
                                    <ul class="flex flex-wrap gap-2">
                                        {#each staffWithPin.filter((u) => !u.is_supervisor) as u (u.id)}
                                            <li>
                                                <button
                                                    type="button"
                                                    class="btn preset-outlined btn-sm"
                                                    onclick={() =>
                                                        handleAddSupervisor(
                                                            u.id,
                                                        )}
                                                    disabled={submitting}
                                                >
                                                    + {u.name}
                                                </button>
                                            </li>
                                        {/each}
                                    </ul>
                                {/if}
                            </div>
                        </div>
                    {/if}
                </section>
            </div>
        {:else}
            <!-- Stations tab (BD-010) -->
            <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
                <div>
                    <h2 class="text-lg font-semibold text-surface-950">
                        Stations
                    </h2>
                    <p class="text-sm text-surface-600 mt-0.5">
                        Manage service points where clients receive attention.
                    </p>
                </div>
                <button
                    type="button"
                    class="btn preset-filled-primary-500 flex items-center gap-2 shadow-sm"
                    onclick={openCreateStation}
                >
                    <Plus class="w-4 h-4" /> Add Station
                </button>
            </div>
            {#if stations.length === 0}
                <div
                    class="rounded-container bg-surface-50 border border-surface-200 p-12 flex flex-col items-center justify-center text-center shadow-sm"
                >
                    <div
                        class="bg-surface-100 p-4 rounded-full text-surface-400 mb-4"
                    >
                        <Monitor class="w-8 h-8" />
                    </div>
                    <h3 class="text-lg font-semibold text-surface-950">
                        No stations defined
                    </h3>
                    <p class="text-surface-600 max-w-sm mt-2 mb-6">
                        Create a station (e.g., Verification Desk, Cashier) for
                        staff to serve clients.
                    </p>
                    <button
                        type="button"
                        class="btn preset-filled-primary-500 flex items-center gap-2"
                        onclick={openCreateStation}
                    >
                        <Plus class="w-4 h-4" /> Create First Station
                    </button>
                </div>
            {:else}
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {#each stations as station (station.id)}
                        <div
                            class="bg-surface-50 rounded-container elevation-card transition-all hover:shadow-[var(--shadow-raised)] flex flex-col h-full border border-surface-200/50"
                        >
                            <div class="p-5 flex-grow flex flex-col gap-3">
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <div class="flex items-center gap-2.5">
                                        <div
                                            class="bg-surface-100 p-2 rounded-lg text-surface-600"
                                        >
                                            <Monitor class="w-5 h-5" />
                                        </div>
                                        <h3
                                            class="text-lg font-bold text-surface-950 line-clamp-1"
                                        >
                                            {station.name}
                                        </h3>
                                    </div>
                                    <div class="shrink-0 mt-1">
                                        {#if station.is_active}
                                            <span
                                                class="text-[10px] uppercase tracking-wider font-bold px-2 py-1 rounded preset-filled-success-500 shadow-sm flex items-center gap-1"
                                            >
                                                <span
                                                    class="w-1.5 h-1.5 rounded-full bg-white/80 shrink-0 animate-pulse"
                                                ></span> Active
                                            </span>
                                        {:else}
                                            <span
                                                class="text-[10px] uppercase tracking-wider font-bold px-2 py-1 rounded preset-tonal text-surface-600 shadow-sm"
                                            >
                                                Inactive
                                            </span>
                                        {/if}
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-2 mt-2">
                                    <div
                                        class="bg-surface-100/50 rounded p-2 border border-surface-200/50 flex flex-col"
                                    >
                                        <span
                                            class="text-xs font-medium text-surface-500 uppercase tracking-wider mb-1 flex items-center gap-1"
                                            ><Users class="w-3 h-3" /> Staff</span
                                        >
                                        <span
                                            class="text-sm font-semibold text-surface-900"
                                            >{station.capacity} desk{station.capacity !==
                                            1
                                                ? "s"
                                                : ""}</span
                                        >
                                    </div>
                                    <div
                                        class="bg-surface-100/50 rounded p-2 border border-surface-200/50 flex flex-col"
                                    >
                                        <span
                                            class="text-xs font-medium text-surface-500 uppercase tracking-wider mb-1 flex items-center gap-1"
                                            ><User class="w-3 h-3" /> Clients</span
                                        >
                                        <span
                                            class="text-sm font-semibold text-surface-900"
                                            >{station.client_capacity ?? 1} / turn</span
                                        >
                                    </div>
                                </div>
                            </div>
                            <div
                                class="px-5 py-3 border-t border-surface-100 flex items-center justify-between bg-surface-50/50 rounded-b-container"
                            >
                                <button
                                    type="button"
                                    class="text-xs font-medium transition-colors hover:text-surface-900 flex items-center gap-1.5 {station.is_active
                                        ? 'text-error-600 hover:text-error-700'
                                        : 'text-success-600 hover:text-success-700'}"
                                    onclick={() =>
                                        handleToggleStationActive(station)}
                                    disabled={submitting}
                                >
                                    {#if station.is_active}
                                        <Power class="w-3.5 h-3.5" /> Deactivate
                                    {:else}
                                        <Power class="w-3.5 h-3.5" /> Activate
                                    {/if}
                                </button>
                                <div class="flex items-center gap-1">
                                    <button
                                        type="button"
                                        class="btn preset-tonal btn-sm p-2"
                                        onclick={() => openEditStation(station)}
                                        disabled={submitting}
                                        title="Edit Station"
                                    >
                                        <Edit2
                                            class="w-4 h-4 text-surface-600"
                                        />
                                    </button>
                                    <button
                                        type="button"
                                        class="btn preset-tonal btn-sm p-2 hover:bg-error-50"
                                        onclick={() =>
                                            openDeleteStationConfirm(station)}
                                        disabled={submitting}
                                        title="Delete Station"
                                    >
                                        <Trash2
                                            class="w-4 h-4 text-error-500"
                                        />
                                    </button>
                                </div>
                            </div>
                        </div>
                    {/each}
                </div>
            {/if}
        {/if}
    </div>
</AdminLayout>

<Modal open={showCreateModal} title="Add Track" onClose={closeModals}>
    {#snippet children()}
        <form
            onsubmit={(e) => {
                e.preventDefault();
                handleCreate();
            }}
            class="flex flex-col gap-4"
        >
            <div class="form-control w-full">
                <label for="create-track-name" class="label"
                    ><span class="label-text">Name</span></label
                >
                <input
                    id="create-track-name"
                    type="text"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                    placeholder="e.g. Priority Lane"
                    maxlength="50"
                    bind:value={createName}
                    required
                />
            </div>
            <div class="form-control w-full">
                <label for="create-track-desc" class="label"
                    ><span class="label-text">Description (optional)</span
                    ></label
                >
                <textarea
                    id="create-track-desc"
                    class="textarea rounded-container border border-surface-200 w-full"
                    rows="2"
                    placeholder="Brief description"
                    bind:value={createDescription}
                ></textarea>
            </div>
            <div class="form-control w-full">
                <label for="create-track-color" class="label"
                    ><span class="label-text">Color (optional, hex)</span
                    ></label
                >
                <input
                    id="create-track-color"
                    type="text"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                    placeholder="#F59E0B"
                    maxlength="7"
                    bind:value={createColorCode}
                />
            </div>
            <div class="form-control">
                <label class="label cursor-pointer justify-start gap-2">
                    <input
                        type="checkbox"
                        class="checkbox checkbox-sm"
                        bind:checked={createIsDefault}
                    />
                    <span class="label-text"
                        >Default track (exactly one per program)</span
                    >
                </label>
            </div>
            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    class="btn preset-tonal"
                    onclick={closeModals}>Cancel</button
                >
                <button
                    type="submit"
                    class="btn preset-filled-primary-500"
                    disabled={submitting || !createName.trim()}
                >
                    {submitting ? "Creating…" : "Create"}
                </button>
            </div>
        </form>
    {/snippet}
</Modal>

{#if editTrack}
    <Modal open={!!editTrack} title="Edit Track" onClose={closeModals}>
        {#snippet children()}
            <form
                onsubmit={(e) => {
                    e.preventDefault();
                    handleUpdate();
                }}
                class="flex flex-col gap-4"
            >
                <div class="form-control w-full">
                    <label for="edit-track-name" class="label"
                        ><span class="label-text">Name</span></label
                    >
                    <input
                        id="edit-track-name"
                        type="text"
                        class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                        maxlength="50"
                        bind:value={editName}
                        required
                    />
                </div>
                <div class="form-control w-full">
                    <label for="edit-track-desc" class="label"
                        ><span class="label-text">Description (optional)</span
                        ></label
                    >
                    <textarea
                        id="edit-track-desc"
                        class="textarea rounded-container border border-surface-200 w-full"
                        rows="2"
                        bind:value={editDescription}
                    ></textarea>
                </div>
                <div class="form-control w-full">
                    <label for="edit-track-color" class="label"
                        ><span class="label-text">Color (optional, hex)</span
                        ></label
                    >
                    <input
                        id="edit-track-color"
                        type="text"
                        class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                        placeholder="#F59E0B"
                        maxlength="7"
                        bind:value={editColorCode}
                    />
                </div>
                <div class="form-control">
                    <label class="label cursor-pointer justify-start gap-2">
                        <input
                            type="checkbox"
                            class="checkbox checkbox-sm"
                            bind:checked={editIsDefault}
                        />
                        <span class="label-text">Default track</span>
                    </label>
                </div>
                <div class="flex justify-end gap-2">
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
                        {submitting ? "Saving…" : "Save"}
                    </button>
                </div>
            </form>
        {/snippet}
    </Modal>
{/if}

<Modal open={showCreateStationModal} title="Add Station" onClose={closeModals}>
    {#snippet children()}
        <form
            onsubmit={(e) => {
                e.preventDefault();
                handleCreateStation();
            }}
            class="flex flex-col gap-4"
        >
            <div class="form-control w-full">
                <label for="create-station-name" class="label"
                    ><span class="label-text">Name</span></label
                >
                <input
                    id="create-station-name"
                    type="text"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                    placeholder="e.g. Verification Desk"
                    maxlength="50"
                    bind:value={createStationName}
                    required
                />
            </div>
            <div class="form-control w-full">
                <label for="create-station-capacity" class="label"
                    ><span class="label-text">Staff capacity</span></label
                >
                <input
                    id="create-station-capacity"
                    type="number"
                    min="1"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                    bind:value={createStationCapacity}
                    required
                />
            </div>
            <div class="form-control w-full">
                <label for="create-station-client-capacity" class="label"
                    ><span class="label-text">Client capacity</span></label
                >
                <input
                    id="create-station-client-capacity"
                    type="number"
                    min="1"
                    class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                    placeholder="Chairs/seats at station"
                    bind:value={createStationClientCapacity}
                    required
                />
            </div>
            <div class="form-control w-full">
                <label class="label"><span class="label-text">Processes</span></label>
                <p class="text-xs text-surface-500 mb-2">Select which work types this station handles. At least one required.</p>
                {#if processes.length === 0}
                    <p class="text-sm text-warning-600">Create processes in the Processes tab first.</p>
                {:else}
                    <div class="flex flex-wrap gap-3">
                        {#each processes as proc (proc.id)}
                            <label
                                class="label cursor-pointer justify-start gap-2 w-fit p-2 rounded-lg border border-surface-200 hover:bg-surface-50"
                            >
                                <input
                                    type="checkbox"
                                    class="checkbox checkbox-sm"
                                    checked={createStationProcessIds.includes(proc.id)}
                                    onchange={() => toggleCreateProcess(proc.id)}
                                />
                                <span class="label-text">{proc.name}</span>
                            </label>
                        {/each}
                    </div>
                {/if}
            </div>
            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    class="btn preset-tonal"
                    onclick={closeModals}>Cancel</button
                >
                <button
                    type="submit"
                    class="btn preset-filled-primary-500"
                    disabled={submitting || !createStationName.trim() || createStationProcessIds.length === 0 || processes.length === 0}
                >
                    {submitting ? "Creating…" : "Create"}
                </button>
            </div>
        </form>
    {/snippet}
</Modal>

{#if editStation}
    <Modal open={!!editStation} title="Edit Station" onClose={closeModals}>
        {#snippet children()}
            <form
                onsubmit={(e) => {
                    e.preventDefault();
                    handleUpdateStation();
                }}
                class="flex flex-col gap-4"
            >
                <div class="form-control w-full">
                    <label for="edit-station-name" class="label"
                        ><span class="label-text">Name</span></label
                    >
                    <input
                        id="edit-station-name"
                        type="text"
                        class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                        maxlength="50"
                        bind:value={editStationName}
                        required
                    />
                </div>
                <div class="form-control w-full">
                    <label for="edit-station-capacity" class="label"
                        ><span class="label-text">Staff capacity</span></label
                    >
                    <input
                        id="edit-station-capacity"
                        type="number"
                        min="1"
                        class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                        bind:value={editStationCapacity}
                        required
                    />
                </div>
                <div class="form-control w-full">
                    <label for="edit-station-client-capacity" class="label"
                        ><span class="label-text">Client capacity</span></label
                    >
                    <input
                        id="edit-station-client-capacity"
                        type="number"
                        min="1"
                        class="input rounded-container border border-surface-200 px-3 py-2 w-full"
                        bind:value={editStationClientCapacity}
                        required
                    />
                </div>
                <div class="form-control w-full">
                    <label class="label"><span class="label-text">Processes</span></label>
                    <p class="text-xs text-surface-500 mb-2">Select which work types this station handles. At least one required.</p>
                    {#if processes.length === 0}
                        <p class="text-sm text-warning-600">Create processes in the Processes tab first.</p>
                    {:else}
                        <div class="flex flex-wrap gap-3">
                            {#each processes as proc (proc.id)}
                                <label
                                    class="label cursor-pointer justify-start gap-2 w-fit p-2 rounded-lg border border-surface-200 hover:bg-surface-50"
                                >
                                    <input
                                        type="checkbox"
                                        class="checkbox checkbox-sm"
                                        checked={editStationProcessIds.includes(proc.id)}
                                        onchange={() => toggleEditProcess(proc.id)}
                                    />
                                    <span class="label-text">{proc.name}</span>
                                </label>
                            {/each}
                        </div>
                    {/if}
                </div>
                <div class="form-control">
                    <label for="edit-priority-override" class="label"
                        ><span class="label-text">Priority first override</span
                        ></label
                    >
                    <select
                        id="edit-priority-override"
                        class="select rounded-container border border-surface-200 px-3 py-2 w-full"
                        value={editStationPriorityFirstOverride === null
                            ? "default"
                            : editStationPriorityFirstOverride
                              ? "true"
                              : "false"}
                        onchange={(e) => {
                            const v = (e.target as HTMLSelectElement).value;
                            editStationPriorityFirstOverride =
                                v === "default" ? null : v === "true";
                        }}
                    >
                        <option value="default">Use program default</option>
                        <option value="true">Yes (priority lane first)</option>
                        <option value="false">No (FIFO/alternate)</option>
                    </select>
                    <span class="label-text-alt"
                        >Override program's priority-first setting for this
                        station</span
                    >
                </div>
                <div class="form-control">
                    <label class="label cursor-pointer justify-start gap-2">
                        <input
                            type="checkbox"
                            class="checkbox checkbox-sm"
                            bind:checked={editStationIsActive}
                        />
                        <span class="label-text">Active</span>
                    </label>
                </div>
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="btn preset-tonal"
                        onclick={closeModals}>Cancel</button
                    >
                    <button
                        type="submit"
                        class="btn preset-filled-primary-500"
                        disabled={submitting || !editStationName.trim() || editStationProcessIds.length === 0 || processes.length === 0}
                    >
                        {submitting ? "Saving…" : "Save"}
                    </button>
                </div>
            </form>
        {/snippet}
    </Modal>
{/if}

{#if stepModalTrack}
    <Modal
        open={!!stepModalTrack}
        title="Steps: {stepModalTrack.name}"
        onClose={closeStepModal}
    >
        {#snippet children()}
            <div class="flex flex-col gap-4">
                {#if error}
                    <div
                        class="bg-error-100 text-error-900 border border-error-300 rounded-container p-4 text-sm"
                        role="alert"
                    >
                        <span>{error}</span>
                        <button
                            type="button"
                            class="btn preset-tonal btn-xs"
                            onclick={() => (error = "")}>Dismiss</button
                        >
                    </div>
                {/if}
                <div class="text-sm text-surface-950/70">
                    Define the station sequence for this track. Clients will
                    follow this order.
                </div>
                <ul class="flex flex-col gap-2">
                    {#each modalSteps as step, i (step.id)}
                        <li
                            class="rounded-lg border border-surface-200 bg-surface-100 p-2"
                        >
                            {#if editingStepId === step.id}
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="font-medium text-surface-950/80"
                                            >{step.step_order}.</span
                                        >
                                        <span
                                            class="text-sm text-surface-950/70"
                                            >Edit step</span
                                        >
                                    </div>
                                    <div
                                        class="grid gap-2 sm:grid-cols-[1fr_auto_auto_auto]"
                                    >
                                        <div class="form-control min-w-0">
                                            <label
                                                for="edit-step-process-{step.id}"
                                                class="label py-0"
                                                ><span
                                                    class="label-text text-xs"
                                                    >Process</span
                                                ></label
                                            >
                                            <select
                                                id="edit-step-process-{step.id}"
                                                class="select rounded-container border border-surface-200 px-3 py-2 select-sm w-full"
                                                bind:value={editStepProcessId}
                                                aria-label="Process"
                                            >
                                                {#each processes as proc}
                                                    <option value={proc.id}
                                                        >{proc.name}</option
                                                    >
                                                {/each}
                                            </select>
                                        </div>
                                        <div class="form-control w-28">
                                            <label
                                                for="edit-step-min-{step.id}"
                                                class="label py-0"
                                                ><span
                                                    class="label-text text-xs"
                                                    >Est. time (min)</span
                                                ></label
                                            >
                                            <input
                                                id="edit-step-min-{step.id}"
                                                type="number"
                                                min="0"
                                                max="120"
                                                class="input rounded-container border border-surface-200 px-3 py-2 input-sm w-full"
                                                placeholder="Optional"
                                                bind:value={
                                                    editStepEstimatedMinutes
                                                }
                                                aria-label="Estimated time in minutes"
                                            />
                                        </div>
                                        <div class="form-control justify-end">
                                            <label
                                                class="label cursor-pointer justify-start gap-2 py-0"
                                                for="edit-step-required-{step.id}"
                                            >
                                                <input
                                                    id="edit-step-required-{step.id}"
                                                    type="checkbox"
                                                    class="checkbox checkbox-sm"
                                                    bind:checked={
                                                        editStepIsRequired
                                                    }
                                                    aria-label="Required step"
                                                />
                                                <span class="label-text text-xs"
                                                    >Required</span
                                                >
                                            </label>
                                        </div>
                                        <div class="flex items-end gap-1">
                                            <button
                                                type="button"
                                                class="btn preset-filled-primary-500 btn-sm"
                                                onclick={handleUpdateStep}
                                                disabled={submitting ||
                                                    editStepProcessId === ""}
                                                >Save</button
                                            >
                                            <button
                                                type="button"
                                                class="btn preset-tonal btn-sm"
                                                onclick={cancelEditStep}
                                                disabled={submitting}
                                                >Cancel</button
                                            >
                                        </div>
                                    </div>
                                </div>
                            {:else}
                                <div class="flex items-center gap-2">
                                    <span
                                        class="font-medium text-surface-950/80"
                                        >{step.step_order}.</span
                                    >
                                    <span class="flex-1"
                                        >{step.process_name}</span
                                    >
                                    {#if step.estimated_minutes != null}
                                        <span
                                            class="text-xs text-surface-950/60"
                                            title="Estimated time"
                                            >~{step.estimated_minutes} min</span
                                        >
                                    {:else}
                                        <span
                                            class="text-xs text-surface-950/50"
                                            >—</span
                                        >
                                    {/if}
                                    {#if step.is_required}
                                        <span
                                            class="text-xs px-2 py-0.5 rounded preset-tonal badge-xs"
                                            >Required</span
                                        >
                                    {/if}
                                    <div class="flex gap-1">
                                        <button
                                            type="button"
                                            class="btn preset-tonal btn-xs"
                                            onclick={() => openEditStep(step)}
                                            disabled={submitting ||
                                                editingStepId != null}
                                            title="Edit step">Edit</button
                                        >
                                        <button
                                            type="button"
                                            class="btn preset-tonal btn-xs"
                                            onclick={() =>
                                                handleMoveStepUp(step)}
                                            disabled={submitting || i === 0}
                                            title="Move up">↑</button
                                        >
                                        <button
                                            type="button"
                                            class="btn preset-tonal btn-xs"
                                            onclick={() =>
                                                handleMoveStepDown(step)}
                                            disabled={submitting ||
                                                i === modalSteps.length - 1}
                                            title="Move down">↓</button
                                        >
                                        <button
                                            type="button"
                                            class="btn preset-tonal btn-xs text-error"
                                            onclick={() =>
                                                openRemoveStepConfirm(step)}
                                            disabled={submitting}
                                            title="Remove step">Delete</button
                                        >
                                    </div>
                                </div>
                            {/if}
                        </li>
                    {/each}
                </ul>
                <div class="border-t border-surface-200 pt-3">
                    <p class="label-text mb-2">Add step</p>
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="form-control min-w-[180px]">
                            <label for="add-step-process" class="label py-0"
                                ><span class="label-text text-xs">Process</span
                                ></label
                            >
                            <select
                                id="add-step-process"
                                class="select rounded-container border border-surface-200 px-3 py-2 select-sm w-full"
                                bind:value={addStepProcessId}
                                aria-label="Process"
                            >
                                <option value="">Select process</option>
                                {#each processes as proc}
                                    <option value={proc.id}>{proc.name}</option>
                                {/each}
                            </select>
                        </div>
                        <div class="form-control w-28">
                            <label for="add-step-min" class="label py-0"
                                ><span class="label-text text-xs"
                                    >Est. time (min)</span
                                ></label
                            >
                            <input
                                id="add-step-min"
                                type="number"
                                min="0"
                                max="120"
                                class="input rounded-container border border-surface-200 px-3 py-2 input-sm w-full"
                                placeholder="Optional"
                                bind:value={addStepEstimatedMinutes}
                                aria-label="Estimated time in minutes for queue estimates"
                            />
                        </div>
                        <button
                            type="button"
                            class="btn preset-filled-primary-500 btn-sm"
                            onclick={handleAddStep}
                            disabled={submitting || addStepProcessId === ""}
                            >Add</button
                        >
                    </div>
                    <p class="text-xs text-surface-950/50 mt-1">
                        Estimated time is optional and used for queue time
                        estimates.
                    </p>
                </div>
                <div class="flex justify-end">
                    <button
                        type="button"
                        class="btn preset-tonal"
                        onclick={closeStepModal}>Close</button
                    >
                </div>
            </div>
        {/snippet}
    </Modal>
{/if}

{#if showReorderConfirm && stepModalTrack}
    <Modal
        open={showReorderConfirm}
        title="Reorder steps"
        onClose={closeReorderConfirm}
    >
        {#snippet children()}
            <div class="flex flex-col gap-4">
                <p class="text-sm text-surface-950/80">
                    This track has <strong
                        >{stepModalTrack.active_sessions_count ?? 0} active session(s)</strong
                    >. How should the new order apply?
                </p>
                <div class="form-control gap-2">
                    <label
                        class="label cursor-pointer justify-start gap-3 rounded-lg border border-surface-200 bg-surface-100 p-3"
                    >
                        <input
                            type="radio"
                            name="reorder_scope"
                            class="radio radio-primary radio-sm"
                            bind:group={reorderScope}
                            value="new_only"
                        />
                        <div>
                            <span class="font-medium">New sessions only</span>
                            <p class="text-xs text-surface-950/60">
                                Future check-ins follow the new order. Existing
                                sessions keep their current position.
                            </p>
                        </div>
                    </label>
                    <label
                        class="label cursor-pointer justify-start gap-3 rounded-lg border border-surface-200 bg-surface-100 p-3"
                    >
                        <input
                            type="radio"
                            name="reorder_scope"
                            class="radio radio-primary radio-sm"
                            bind:group={reorderScope}
                            value="migrate"
                        />
                        <div>
                            <span class="font-medium"
                                >Migrate existing sessions</span
                            >
                            <p class="text-xs text-surface-950/60">
                                Update active sessions to match the new step
                                order (remap their position).
                            </p>
                        </div>
                    </label>
                </div>
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="btn preset-tonal"
                        onclick={closeReorderConfirm}>Cancel</button
                    >
                    <button
                        type="button"
                        class="btn preset-filled-primary-500"
                        onclick={confirmReorderApply}
                        disabled={submitting}>Apply</button
                    >
                </div>
            </div>
        {/snippet}
    </Modal>
{/if}

<ConfirmModal
    open={confirmStopProgram}
    title="Stop session?"
    message={`Stop session for "${program.name}"? You can only stop when no clients are in the queue.`}
    confirmLabel="Stop session"
    cancelLabel="Cancel"
    variant="danger"
    loading={submitting}
    onConfirm={handleStopConfirm}
    onCancel={closeStopConfirm}
/>

<ConfirmModal
    open={!!confirmDeleteTrack}
    title="Delete track?"
    message={confirmDeleteTrack
        ? `Delete track "${confirmDeleteTrack.name}"? This is only allowed if no active sessions use it.`
        : ""}
    confirmLabel="Delete"
    cancelLabel="Cancel"
    variant="danger"
    loading={submitting}
    onConfirm={handleDeleteTrackConfirm}
    onCancel={closeDeleteTrackConfirm}
/>

<ConfirmModal
    open={!!confirmDeleteStation}
    title="Delete station?"
    message={confirmDeleteStation
        ? `Delete station "${confirmDeleteStation.name}"? This is only allowed if it is not used in any track steps.`
        : ""}
    confirmLabel="Delete"
    cancelLabel="Cancel"
    variant="danger"
    loading={submitting}
    onConfirm={handleDeleteStationConfirm}
    onCancel={closeDeleteStationConfirm}
/>

<ConfirmModal
    open={!!confirmRemoveStep}
    title="Remove step?"
    message={confirmRemoveStep
        ? `Remove step "${confirmRemoveStep.step.process_name}" from this track?`
        : ""}
    confirmLabel="Remove"
    cancelLabel="Cancel"
    variant="warning"
    loading={submitting}
    onConfirm={handleRemoveStepConfirm}
    onCancel={closeRemoveStepConfirm}
/>
