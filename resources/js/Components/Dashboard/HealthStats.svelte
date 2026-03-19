<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import { Activity, Users, Monitor, CheckCircle } from 'lucide-svelte';
    import type { DashboardStats } from '../../types/dashboard';

    let { stats }: { stats: DashboardStats } = $props();
</script>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
    <div class="bg-surface-50 rounded-container elevation-card p-5">
        <div class="flex justify-between items-start">
            <div class="stat">
                <div class="stat-title">Active Session</div>
                <div class="stat-value text-primary-500">{stats.program.is_running ? 'YES' : 'NO'}</div>
                <div class="stat-desc">
                    Program {stats.program.is_running ? 'running' : (stats.program.is_paused ? 'paused' : 'inactive')}
                </div>
            </div>
            <div class="stat-figure text-primary-600 bg-primary-100 p-3">
                <Activity class="h-6 w-6" />
            </div>
        </div>
    </div>

    <div class="bg-surface-50 rounded-container elevation-card p-5">
        <div class="flex justify-between items-start">
            <div class="stat">
                <div class="stat-title">Queue Waiting</div>
                <div class="stat-value text-warning-500">{stats.sessions.waiting}</div>
                <div class="stat-desc">
                    Excludes called/serving
                </div>
            </div>
            <div class="stat-figure text-warning-600 bg-warning-100 p-3">
                <Users class="h-6 w-6" />
            </div>
        </div>
    </div>

    <div class="bg-surface-50 rounded-container elevation-card p-5">
        <div class="flex justify-between items-start">
            <div class="stat">
                <div class="stat-title">Stations Online</div>
                <div class="stat-value text-success-500">
                    {stats.stations_online}
                    <span class="text-lg text-surface-400 font-normal">/{stats.stations.total}</span>
                </div>
                <div class="stat-desc">
                    {stats.stations.with_queue} with queue
                </div>
            </div>
            <div class="stat-figure text-success-600 bg-success-100 p-3">
                <Monitor class="h-6 w-6" />
            </div>
        </div>
    </div>

    <div class="bg-surface-50 rounded-container elevation-card p-5">
        <div class="flex justify-between items-start">
            <div class="stat">
                <div class="stat-title">Completed Today</div>
                <div class="stat-value text-surface-950">{stats.sessions.completed_today}</div>
                <div class="stat-desc">
                    <span class="text-error-500">{stats.sessions.cancelled_today} cancelled</span> 
                    &middot; 
                    <span class="text-warning-600">{stats.sessions.no_show_today} no-show</span>
                </div>
            </div>
            <div class="stat-figure text-surface-700 bg-surface-200 p-3">
                <CheckCircle class="h-6 w-6" />
            </div>
        </div>
    </div>
</div>