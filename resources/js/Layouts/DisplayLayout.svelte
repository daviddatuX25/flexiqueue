<script>
	/**
	 * DisplayLayout — client-facing informant (no auth). Per 09-UI-ROUTES-PHASE1 §2.4.
	 * Minimal chrome: header with program name + date, full-screen content, no nav (kiosk mode).
	 */
	let { children, programName = null, date = '' } = $props();
</script>

<div class="flex flex-col min-h-screen bg-surface-100">
	<header class="flex items-center justify-between bg-primary-500 text-primary-contrast-500 px-4 py-2 shrink-0">
		<div>
			<span class="text-lg font-bold">FlexiQueue</span>
		</div>
		<div class="flex-1 flex justify-center">
			{#if programName}
				<span class="text-base font-semibold">{programName}</span>
			{:else}
				<span class="text-primary-contrast-500/70">No active program</span>
			{/if}
		</div>
		<div>
			<span class="text-sm opacity-90">{date}</span>
		</div>
	</header>

	<main class="flex-1 overflow-auto p-4">
		{#if children}
			{@render children()}
		{/if}
	</main>
</div>
