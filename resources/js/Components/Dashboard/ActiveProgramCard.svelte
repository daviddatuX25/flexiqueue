<script lang="ts">
    import { Link } from "@inertiajs/svelte";
    import { Play, Eye } from "lucide-svelte";
    import type { DashboardStats } from "../../types/dashboard";

    let { stats }: { stats: DashboardStats } = $props();
</script>

<div class="card bg-surface-50 rounded-container elevation-card h-full">
    <div class="card-body py-5 px-6">
        <div class="flex flex-wrap justify-between items-start gap-3 mb-4">
            <div>
                <h2 class="card-title text-base flex items-center gap-2">
                    <Play class="h-4 w-4 text-primary-500" />
                    Active Program
                </h2>
                <p class="text-sm text-surface-950/60 mt-1">
                    {stats.active_program?.name ?? "No active program"}
                </p>
            </div>
            {#if stats.active_program}
                <div class="flex items-center gap-2">
                    <span
                        class="text-xs px-2 py-0.5 rounded preset-filled-success-500 badge-sm gap-1 flex items-center"
                    >
                        <div
                            class="w-1.5 h-1.5 bg-white rounded-full animate-pulse"
                        ></div>
                        Live
                    </span>
                    <Link
                        href="/station"
                        class="btn preset-filled-primary-500 btn-sm gap-1.5"
                    >
                        <Eye class="h-4 w-4" />
                        View Program
                    </Link>
                </div>
            {/if}
        </div>

        {#if stats.active_program && stats.by_track.length > 0}
            <div class="space-y-4 mt-6">
                {#each stats.by_track as track (track.track_name)}
                    <div>
                        <div class="flex justify-between text-xs mb-1.5">
                            <span class="font-medium text-surface-700"
                                >{track.track_name}</span
                            >
                            <span class="font-bold text-surface-900"
                                >{track.count} client{track.count === 1
                                    ? ""
                                    : "s"}</span
                            >
                        </div>
                        <div
                            class="w-full bg-surface-200 rounded-full h-2.5 overflow-hidden"
                        >
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
                <p class="text-sm text-surface-950/60">
                    No active sessions yet.
                </p>
            </div>
        {:else}
            <div class="py-8 text-center">
                <p class="text-sm text-surface-950/60 mb-3">
                    Activate a program to start operations.
                </p>
                <Link href="/admin/programs" class="btn preset-tonal btn-sm"
                    >Go to Programs</Link
                >
            </div>
        {/if}
    </div>
</div>
