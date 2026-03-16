<script lang="ts">
	import MobileLayout from '../../Layouts/MobileLayout.svelte';

	interface StationSummary {
		name: string | null;
		queue_count: number;
		message: string | null;
	}

	interface Metrics {
		sessions_served_today: number;
		average_time_per_client_minutes: number | null;
		station: StationSummary | null;
		activity_counts_today: Record<string, number>;
	}

	let {
		metrics,
		queueCount = 0,
		processedToday = 0,
	}: {
		metrics: Metrics;
		queueCount?: number;
		processedToday?: number;
	} = $props();

	const activityLabels: Record<string, string> = {
		bind: 'Binds (triage)',
		call: 'Calls',
		check_in: 'Check-ins (served)',
		transfer: 'Transfers',
		complete: 'Completions',
		cancel: 'Cancellations',
		no_show: 'No-shows',
		force_complete: 'Force complete',
		override: 'Overrides',
	};
	function labelForAction(action: string): string {
		return activityLabels[action] ?? action;
	}
</script>

<svelte:head>
	<title>Dashboard — FlexiQueue</title>
</svelte:head>

<MobileLayout headerTitle="Dashboard" {queueCount} {processedToday}>
	<div class="flex flex-col gap-4 md:gap-6 text-surface-950 w-full max-w-2xl mx-auto px-4 md:px-6 py-4 md:py-6">
		<h1 class="text-xl md:text-2xl font-semibold text-surface-950">My Dashboard</h1>
		<p class="text-sm text-surface-600">Your activity and station metrics for today.</p>

		<!-- Summary cards -->
		<div class="grid gap-4 sm:grid-cols-2">
			<div
				class="rounded-container border border-surface-200 bg-surface-50 elevation-card p-4 md:p-5"
			>
				<p class="text-sm font-medium text-surface-600">Sessions served today</p>
				<p class="text-2xl md:text-3xl font-bold text-primary-600 mt-1">
					{metrics.sessions_served_today}
				</p>
			</div>
			<div
				class="rounded-container border border-surface-200 bg-surface-50 elevation-card p-4 md:p-5"
			>
				<p class="text-sm font-medium text-surface-600">Avg. time per client (today)</p>
				<p class="text-2xl md:text-3xl font-bold text-surface-950 mt-1">
					{metrics.average_time_per_client_minutes != null
						? `${metrics.average_time_per_client_minutes} min`
						: '—'}
				</p>
			</div>
		</div>

		<!-- Your station -->
		{#if metrics.station}
			<div
				class="rounded-container border border-surface-200 bg-surface-50 elevation-card p-4 md:p-5"
			>
				<h2 class="text-base font-semibold text-surface-950">Your station</h2>
				{#if metrics.station.name}
					<p class="text-surface-950 font-medium mt-1">{metrics.station.name}</p>
					<p class="text-sm text-surface-600 mt-0.5">
						{metrics.station.queue_count} {metrics.station.queue_count === 1 ? 'client' : 'clients'} waiting
					</p>
				{:else}
					<p class="text-surface-600 mt-1">{metrics.station.message ?? 'No station assigned'}</p>
				{/if}
			</div>
		{/if}

		<!-- Recent activity today -->
		<div
			class="rounded-container border border-surface-200 bg-surface-50 elevation-card p-4 md:p-5"
		>
			<h2 class="text-base font-semibold text-surface-950">Activity today</h2>
			{#if Object.keys(metrics.activity_counts_today).length > 0}
				<ul class="mt-2 space-y-1.5 text-sm">
					{#each Object.entries(metrics.activity_counts_today).sort((a, b) => b[1] - a[1]) as [action, count]}
						<li class="flex justify-between text-surface-950">
							<span>{labelForAction(action)}</span>
							<span class="font-medium">{count}</span>
						</li>
					{/each}
				</ul>
			{:else}
				<p class="text-surface-600 mt-2 text-sm">No activity recorded yet today.</p>
			{/if}
		</div>
	</div>
</MobileLayout>
