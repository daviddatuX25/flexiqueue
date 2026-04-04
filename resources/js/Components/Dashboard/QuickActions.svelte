<script lang="ts">
    import { Link } from "@inertiajs/svelte";
    import { Settings, Users, FileText, Zap } from "lucide-svelte";
    import type { DashboardStats } from "../../types/dashboard";

    let { stats }: { stats: DashboardStats | null } = $props();
</script>

<!-- Match Admin/Programs/Index — bg-surface-50 + border-surface-200/50; theme handles dark. -->
<div class="space-y-4 h-full flex flex-col">
    <div class="card bg-surface-50 rounded-container elevation-card flex-1 border border-surface-200/50">
        <div class="card-body p-5 space-y-6">
            <h2
                class="card-title text-base text-surface-950 flex items-center gap-2 border-b border-surface-100 pb-3"
            >
                <Zap class="h-4 w-4 text-warning-500 shrink-0" />
                Quick Actions
            </h2>

            <div>
                <p
                    class="text-xs font-semibold uppercase tracking-wider text-surface-500 mb-3 ml-1"
                >
                    Program & Setup
                </p>
                <Link
                    href="/admin/programs"
                    class="btn preset-tonal-surface w-full justify-start gap-3 hover:preset-tonal-primary transition-colors py-3 touch-target-h"
                >
                    <Settings class="h-4 w-4 shrink-0 text-surface-600" />
                    <span class="font-medium">Manage Program</span>
                </Link>
            </div>

            <div>
                <p
                    class="text-xs font-semibold uppercase tracking-wider text-surface-500 mb-3 ml-1"
                >
                    People & Audit log
                </p>
                <div class="flex flex-col gap-3">
                    <Link
                        href="/admin/users"
                        class="btn preset-tonal-surface w-full justify-start gap-3 hover:preset-tonal-primary transition-colors py-3 touch-target-h"
                    >
                        <Users class="h-4 w-4 shrink-0 text-surface-600" />
                        <span class="font-medium">Manage Staff</span>
                    </Link>
                    <Link
                        href="/admin/logs"
                        class="btn preset-tonal-surface w-full justify-start gap-3 hover:preset-tonal-primary transition-colors py-3 touch-target-h"
                    >
                        <FileText class="h-4 w-4 shrink-0 text-surface-600" />
                        <span class="font-medium">View audit log</span>
                    </Link>
                </div>
            </div>
        </div>
    </div>

    <!-- Staff Online — per ui-ux-tasks-checklist: fix unreadable gray text and consistent layout -->
    {#if stats}
        <div class="card bg-surface-50 rounded-container elevation-card mt-4 border border-surface-200/50">
            <div class="card-body p-5 flex items-center justify-between gap-3">
                <div class="flex items-center gap-4 min-w-0">
                    <div class="p-3 shrink-0 bg-primary-100 text-primary-600 rounded-xl">
                        <Users class="h-6 w-6" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold uppercase tracking-wider text-surface-950 mb-0.5">
                            Staff Online
                        </p>
                        <p class="text-xs text-surface-600 leading-snug">Available and assigned</p>
                    </div>
                </div>
                <div class="text-3xl font-bold text-surface-950 tabular-nums shrink-0">
                    {stats.staff_online}
                </div>
            </div>
        </div>
    {/if}
</div>
