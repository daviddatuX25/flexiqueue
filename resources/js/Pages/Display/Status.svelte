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
		<h1 class="text-2xl font-bold text-base-content">YOUR STATUS</h1>

		{#if error}
			<div class="alert alert-error">
				<span>{error}</span>
			</div>
		{:else if status === 'available' && message}
			<div class="card bg-base-100 border border-base-300">
				<div class="card-body">
					<div class="text-4xl font-bold text-base-content">{alias ?? '—'}</div>
					<p class="text-base-content/80 mt-2">{message}</p>
				</div>
			</div>
		{:else}
			<div class="card bg-base-100 border border-base-300">
				<div class="card-body">
					<div class="text-5xl font-bold text-primary">{alias ?? '—'}</div>
					{#if client_category}
						<span class="badge badge-warning mt-2">{client_category}</span>
					{/if}
					{#if status}
						<span class="badge badge-info mt-2">{status}</span>
					{/if}
					{#if current_station}
						<p class="mt-2 text-base-content/80">Currently at: <strong>{current_station}</strong></p>
					{/if}
					{#if estimated_wait_minutes != null}
						<p class="text-sm text-base-content/70">Estimated wait: ~{estimated_wait_minutes} minutes</p>
					{/if}
					{#if started_at}
						<p class="text-xs text-base-content/50 mt-1">Started {new Date(started_at).toLocaleString()}</p>
					{/if}
				</div>
			</div>

			{#if progress?.steps?.length}
				<div class="card bg-base-100 border border-base-300">
					<div class="card-body">
						<h2 class="font-semibold text-base-content mb-2">Progress</h2>
						<ul class="steps steps-vertical w-full">
							{#each progress.steps as step}
								<li class="step {step.status === 'complete' ? 'step-primary' : ''} {step.status === 'in_progress' ? 'step-primary' : ''}">
									{step.station_name} — {step.status === 'complete' ? 'Complete' : step.status === 'in_progress' ? 'In progress' : 'Waiting'}
								</li>
							{/each}
						</ul>
					</div>
				</div>
			{/if}
		{/if}

		<p class="text-sm text-base-content/60">Returning to board in {countdown}s</p>
		<Link href="/display" class="btn btn-primary btn-block">OK, GOT IT</Link>
	</div>
</DisplayLayout>
