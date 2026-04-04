<script lang="ts">
    import { Activity, Users, Monitor, CheckCircle } from 'lucide-svelte';
    import type { DashboardStats } from '../../types/dashboard';

    let { stats }: { stats: DashboardStats } = $props();
</script>

<!-- Match Admin/Programs/Index.svelte: theme tokens only (no dark: — Skeleton maps surface-* per data-theme). -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
    <div class="bg-surface-50 rounded-container elevation-card border border-surface-200/50 p-5">
        <div class="flex justify-between items-start gap-3">
            <div class="stat min-w-0">
                <div class="stat-title text-surface-500">Active programs</div>
                <div class="stat-value text-primary-500 tabular-nums">{stats.active_programs_count ?? 0}</div>
                <div class="stat-desc text-surface-600">Activated on this site</div>
            </div>
            <div class="stat-figure text-primary-600 bg-primary-100 p-3 rounded-lg shrink-0">
                <Activity class="h-6 w-6" />
            </div>
        </div>
    </div>

    <div class="bg-surface-50 rounded-container elevation-card border border-surface-200/50 p-5">
        <div class="flex justify-between items-start gap-3">
            <div class="stat min-w-0">
                <div class="stat-title text-surface-500">Queue Waiting</div>
                <div class="stat-value text-warning-500 tabular-nums">{stats.sessions.waiting}</div>
                <div class="stat-desc text-surface-600">Excludes called/serving</div>
            </div>
            <div class="stat-figure text-warning-600 bg-warning-100 p-3 rounded-lg shrink-0">
                <Users class="h-6 w-6" />
            </div>
        </div>
    </div>

    <div class="bg-surface-50 rounded-container elevation-card border border-surface-200/50 p-5">
        <div class="flex justify-between items-start gap-3">
            <div class="stat min-w-0">
                <div class="stat-title text-surface-500">Stations Active</div>
                <div class="stat-value text-success-500 tabular-nums">
                    {stats.stations.active}
                    <span class="text-lg text-surface-500 font-normal">/{stats.stations.total}</span>
                </div>
                <div class="stat-desc text-surface-600">{stats.stations.with_queue} with queue</div>
            </div>
            <div class="stat-figure text-success-600 bg-success-100 p-3 rounded-lg shrink-0">
                <Monitor class="h-6 w-6" />
            </div>
        </div>
    </div>

    <div class="bg-surface-50 rounded-container elevation-card border border-surface-200/50 p-5">
        <div class="flex justify-between items-start gap-3">
            <div class="stat min-w-0">
                <div class="stat-title text-surface-500">Completed Today</div>
                <div class="stat-value text-surface-950 tabular-nums">{stats.sessions.completed_today}</div>
                <div class="stat-desc text-surface-600">
                    <span class="text-error-500">{stats.sessions.cancelled_today} cancelled</span>
                    &middot;
                    <span class="text-warning-600">{stats.sessions.no_show_today} no-show</span>
                </div>
            </div>
            <div class="stat-figure text-surface-700 bg-surface-200 p-3 rounded-lg shrink-0">
                <CheckCircle class="h-6 w-6" />
            </div>
        </div>
    </div>
</div>