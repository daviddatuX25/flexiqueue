<script>
	/**
	 * DisplayLayout — client-facing informant (no auth). Per 09-UI-ROUTES-PHASE1 §2.4.
	 * Header: FlexiQueue, program name, date, and live time. Main: full-screen content.
	 */
	let { children, programName = null, date = '' } = $props();
	let time = $state('');

	$effect(() => {
		const update = () => {
			time = new Date().toLocaleTimeString('en-US', {
				hour: 'numeric',
				minute: '2-digit',
				second: '2-digit',
				hour12: true,
			});
		};
		update();
		const id = setInterval(update, 1000);
		return () => clearInterval(id);
	});
</script>

<div class="flex flex-col min-h-screen bg-surface-100">
	<header class="flex items-center justify-between gap-4 bg-primary-500 text-primary-contrast-500 px-4 py-2.5 shrink-0">
		<div class="shrink-0">
			<span class="text-lg font-bold">FlexiQueue</span>
		</div>
		<div class="flex-1 flex justify-center min-w-0">
			{#if programName}
				<span class="text-base font-semibold truncate">{programName}</span>
			{:else}
				<span class="text-primary-contrast-500/70">No active program</span>
			{/if}
		</div>
		<div class="flex items-center gap-4 shrink-0">
			<span class="text-sm opacity-90">{date}</span>
			<span class="text-sm font-mono tabular-nums" aria-label="Current time">{time}</span>
		</div>
	</header>

	<main class="flex-1 overflow-auto p-4">
		{#if children}
			{@render children()}
		{/if}
	</main>
</div>
