<script lang="ts">
    import { Link } from "@inertiajs/svelte";
    import { Play, Eye, Repeat2 } from "lucide-svelte";
    import Marquee from "../Marquee.svelte";
    import type { DashboardStats } from "../../types/dashboard";

    interface ActiveProgramSummary {
        id: number;
        name: string;
    }

    let {
        stats,
        activePrograms = [],
        selectedProgramId = null,
        onCycleActiveProgram = () => {},
    }: {
        stats: DashboardStats;
        activePrograms?: ActiveProgramSummary[];
        selectedProgramId?: number | null;
        onCycleActiveProgram?: () => void;
    } = $props();

    const showProgramSwitcher = $derived(activePrograms.length > 1);

    const displayProgramName = $derived(
        activePrograms.find((p) => p.id === selectedProgramId)?.name ??
            stats.active_program?.name ??
            "No active program"
    );
</script>

<div class="card bg-surface-50 rounded-container elevation-card h-full border border-surface-200/50">
    <div class="card-body py-4 px-4 sm:py-5 sm:px-6">
        <!-- Mobile: title + Live top-right → name+switch → Manage full-width. sm+: title+name left; Live + Manage right. -->
        <div
            class="flex flex-col gap-3 mb-4 sm:flex-row sm:flex-wrap sm:justify-between sm:items-start sm:gap-4"
        >
            <div class="min-w-0 w-full sm:flex-1 sm:w-auto">
                <div class="flex items-start justify-between gap-2 min-w-0 sm:justify-start sm:block">
                    <h2 class="card-title text-base text-surface-950 flex items-center gap-2 min-w-0">
                        <Play class="h-4 w-4 text-primary-500 shrink-0" />
                        Active Program
                    </h2>
                    {#if stats.active_program}
                        <span
                            class="text-xs px-2 py-0.5 rounded preset-filled-success-500 badge-sm gap-1 flex items-center shrink-0 sm:hidden"
                            aria-label="Program is live"
                        >
                            <div
                                class="w-1.5 h-1.5 bg-surface-950 rounded-full animate-pulse shrink-0"
                            ></div>
                            Live
                        </span>
                    {/if}
                </div>
                <!-- Fixed cycle control on the left; name fills remainder. Mobile: Marquee.svelte overflowOnly (same as ProgramChip / Tokens). -->
                <div class="mt-1.5 flex items-center gap-2 w-full min-w-0">
                    {#if showProgramSwitcher}
                        <button
                            type="button"
                            class="btn preset-tonal btn-sm !p-1.5 shrink-0 touch-target-h"
                            onclick={() => onCycleActiveProgram()}
                            title="Switch to another activated program"
                            aria-label="Switch to another activated program"
                        >
                            <Repeat2 class="h-4 w-4 text-surface-600" aria-hidden="true" />
                        </button>
                    {/if}
                    <div class="min-w-0 flex-1 overflow-hidden">
                        <div class="sm:hidden min-w-0 w-full">
                            <Marquee
                                overflowOnly={true}
                                duration={14}
                                gapEm={1.5}
                                class="text-sm text-surface-600 block w-full"
                            >
                                {#snippet children()}
                                    <span class="whitespace-nowrap">{displayProgramName}</span>
                                {/snippet}
                            </Marquee>
                        </div>
                        <p class="hidden sm:block text-sm text-surface-600 truncate min-w-0">
                            {displayProgramName}
                        </p>
                    </div>
                </div>
            </div>
            {#if stats.active_program}
                <div
                    class="hidden sm:flex flex-col gap-2 sm:w-auto sm:flex-row sm:flex-wrap sm:items-center sm:justify-end sm:shrink-0"
                >
                    <span
                        class="text-xs px-2 py-0.5 rounded preset-filled-success-500 badge-sm gap-1 flex items-center w-fit"
                        aria-label="Program is live"
                    >
                        <div
                            class="w-1.5 h-1.5 bg-surface-950 rounded-full animate-pulse shrink-0"
                        ></div>
                        Live
                    </span>
                    <Link
                        href="/station"
                        class="btn preset-filled-primary-500 btn-sm gap-1.5 touch-target-h sm:w-auto justify-center"
                    >
                        <Eye class="h-4 w-4 shrink-0" />
                        Manage program
                    </Link>
                </div>
                <Link
                    href="/station"
                    class="btn preset-filled-primary-500 btn-sm gap-1.5 touch-target-h w-full justify-center sm:hidden"
                >
                    <Eye class="h-4 w-4 shrink-0" />
                    Manage program
                </Link>
            {/if}
        </div>

        {#if stats.active_program && stats.by_track.length > 0}
            <div class="space-y-4 mt-6">
                {#each stats.by_track as track (track.track_name)}
                    <div>
                        <div
                            class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:items-baseline text-xs mb-1.5"
                        >
                            <span class="font-medium text-surface-700 break-words">{track.track_name}</span>
                            <span class="font-bold text-surface-950 tabular-nums shrink-0">
                                {track.count} client{track.count === 1 ? '' : 's'}
                            </span>
                        </div>
                        <div class="w-full bg-surface-200 rounded-full h-2.5 overflow-hidden">
                            <div
                                class="bg-primary-500 h-2.5 rounded-full transition-all duration-500 ease-out"
                                style="width: {stats.sessions.active > 0
                                    ? (track.count / stats.sessions.active) *
                                      100
                                    : 0}%"
                            ></div>
                        </div>
                    </div>
                {/each}
            </div>
        {:else if stats.active_program}
            <div class="py-8 text-center">
                <p class="text-sm text-surface-600">No active sessions yet.</p>
            </div>
        {:else}
            <div class="py-8 text-center">
                <p class="text-sm text-surface-600 mb-3">Activate a program to start operations.</p>
                <Link href="/admin/programs" class="btn preset-tonal btn-sm touch-target-h"
                    >Go to Programs</Link
                >
            </div>
        {/if}
    </div>
</div>
