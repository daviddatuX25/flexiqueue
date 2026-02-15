<script>
	/**
	 * DisplayLayout — client-facing informant (no auth). Per 09-UI-ROUTES-PHASE1 §2.4.
	 * Minimal chrome: header with program name + date, full-screen content, no nav (kiosk mode).
	 */
	let { children, programName = null, date = '' } = $props();
</script>

<div class="flex flex-col min-h-screen bg-base-200">
	<header class="navbar bg-primary text-primary-content px-4 py-2 shrink-0">
		<div class="navbar-start">
			<span class="text-lg font-bold">FlexiQueue</span>
		</div>
		<div class="navbar-center">
			{#if programName}
				<span class="text-base font-semibold">{programName}</span>
			{:else}
				<span class="text-base-content/70">No active program</span>
			{/if}
		</div>
		<div class="navbar-end">
			<span class="text-sm opacity-90">{date}</span>
		</div>
	</header>

	<main class="flex-1 overflow-auto p-4">
		{#if children}
			{@render children()}
		{/if}
	</main>
</div>
