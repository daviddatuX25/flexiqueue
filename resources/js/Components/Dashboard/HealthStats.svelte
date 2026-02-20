<script lang="ts">
	import { Link } from '@inertiajs/svelte';
	import { Activity, Users, Monitor, CheckCircle } from 'lucide-svelte';
	import type { DashboardStats } from '../../types/dashboard';

	let { stats }: { stats: DashboardStats } = $props();
</script>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
	<!-- Active Sessions -->
	<div class="stats bg-surface-50 rounded-container elevation-card">
		<div class="stat p-4">
			<div class="stat-figure text-primary-500 bg-primary-100 p-2 rounded-lg">
				<Activity class="h-6 w-6" />
			</div>
			<div class="stat-title text-xs font-medium uppercase tracking-wider">Active Sessions</div>
			<div class="stat-value text-primary-500 text-4xl">{stats.sessions.active}</div>
			<div class="stat-desc text-xs mt-1">
				{stats.sessions.waiting} waiting &middot; {stats.sessions.serving} serving
			</div>
		</div>
	</div>

	<!-- Queue Waiting -->
	<div class="stats bg-surface-50 rounded-container elevation-card">
		<div class="stat p-4">
			<div class="stat-figure text-warning-500 bg-warning-100 p-2 rounded-lg">
				<Users class="h-6 w-6" />
			</div>
			<div class="stat-title text-xs font-medium uppercase tracking-wider">Queue Waiting</div>
			<div class="stat-value text-warning-500 text-3xl">{stats.sessions.waiting}</div>
			<div class="stat-desc text-xs mt-1">Clients in queue</div>
		</div>
	</div>

	<!-- Stations Online -->
	<div class="stats bg-surface-50 rounded-container elevation-card">
		<div class="stat p-4">
			<div class="stat-figure text-success-500 bg-success-100 p-2 rounded-lg">
				<Monitor class="h-6 w-6" />
			</div>
			<div class="stat-title text-xs font-medium uppercase tracking-wider">Stations Online</div>
			<div class="stat-value text-success-500 text-3xl">
				{stats.stations.active}<span class="text-sm text-surface-950/40 font-normal"
					>/{stats.stations.total}</span
				>
			</div>
			<div class="stat-desc text-xs mt-1">
				{stats.stations.with_queue} with queue
			</div>
		</div>
	</div>

	<!-- Completed Today -->
	<div class="stats bg-surface-50 rounded-container elevation-card">
		<div class="stat p-4">
			<div class="stat-figure text-surface-950 bg-surface-100 p-2 rounded-lg">
				<CheckCircle class="h-6 w-6" />
			</div>
			<div class="stat-title text-xs font-medium uppercase tracking-wider">Completed Today</div>
			<div class="stat-value text-3xl">{stats.sessions.completed_today}</div>
			<div class="stat-desc text-xs mt-1">
				{stats.sessions.cancelled_today} cancelled &middot; {stats.sessions.no_show_today} no-show
			</div>
		</div>
	</div>
</div>
