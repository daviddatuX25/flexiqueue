<script>
	/**
	 * Display/Status.svelte — client QR status result. Per 09-UI-ROUTES-PHASE1 §3.5.
	 * Data from server (check-status logic). Dismiss returns to /display. Auto-dismiss 30s.
	 */
	import { onDestroy } from 'svelte';
	import { Link, router } from '@inertiajs/svelte';
	import DisplayLayout from '../../Layouts/DisplayLayout.svelte';

	let {
		error = null,
		alias = null,
		status = null,
		client_category = null,
		progress = null,
		current_station = null,
		estimated_wait_minutes = null,
		started_at = null,
		message = null,
	} = $props();

	// Per spec §3.5: AutoDismissTimer (30 seconds → auto-navigate back)
	let countdown = $state(30);
	let timerId;

	timerId = setInterval(() => {
		countdown -= 1;
		if (countdown <= 0) {
			clearInterval(timerId);
			router.visit('/display');
		}
	}, 1000);

	onDestroy(() => {
		if (timerId) clearInterval(timerId);
	});
</script>

<svelte:head>
	<title>Your Status — FlexiQueue</title>
</svelte:head>

<DisplayLayout programName={null} date="">
	<div class="max-w-md mx-auto flex flex-col gap-6">
		<h1 class="text-2xl font-bold text-surface-950">YOUR STATUS</h1>

		{#if error}
			<div class="bg-error-100 text-error-900 border border-error-300 rounded-container p-4">
				<span>{error}</span>
			</div>
		{:else if status === 'available' && message}
			<div class="card bg-surface-50 border border-surface-200 rounded-container p-4">
				<div class="text-4xl font-bold text-surface-950">{alias ?? '—'}</div>
				<p class="text-surface-950/80 mt-2">{message}</p>
			</div>
		{:else}
			<div class="card bg-surface-50 border border-surface-200 rounded-container p-4">
				<div class="text-5xl font-bold text-primary-500">{alias ?? '—'}</div>
				{#if client_category}
					<span class="text-xs px-2 py-0.5 rounded preset-filled-warning-500 mt-2 inline-block">{client_category}</span>
				{/if}
				{#if status}
					<span class="text-xs px-2 py-0.5 rounded preset-filled-primary-500 mt-2 inline-block">{status}</span>
				{/if}
				{#if current_station}
					<p class="mt-2 text-surface-950/80">Currently at: <strong>{current_station}</strong></p>
				{/if}
				{#if estimated_wait_minutes != null}
					<p class="text-sm text-surface-950/70">Estimated wait: ~{estimated_wait_minutes} minutes</p>
				{/if}
				{#if started_at}
					<p class="text-xs text-surface-950/50 mt-1">Started {new Date(started_at).toLocaleString()}</p>
				{/if}
			</div>

			{#if progress?.steps?.length}
				<div class="card bg-surface-50 border border-surface-200 rounded-container p-4">
					<h2 class="font-semibold text-surface-950 mb-2">Progress</h2>
					<ul class="space-y-2 w-full">
						{#each progress.steps as step}
							<li class="flex items-center gap-2 {step.status === 'complete' || step.status === 'in_progress' ? 'text-primary-500' : 'text-surface-950/70'}">
								<span class="w-2 h-2 rounded-full {step.status === 'complete' ? 'bg-success-500' : step.status === 'in_progress' ? 'bg-primary-500' : 'bg-surface-300'}"></span>
								{step.station_name} — {step.status === 'complete' ? 'Complete' : step.status === 'in_progress' ? 'In progress' : 'Waiting'}
							</li>
						{/each}
					</ul>
				</div>
			{/if}
		{/if}

		<p class="text-sm text-surface-950/60">Returning to board in {countdown}s</p>
		<Link href="/display" class="btn preset-filled-primary-500 w-full">OK, GOT IT</Link>
	</div>
</DisplayLayout>
