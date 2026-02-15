<script>
	/**
	 * StatusFooter — connection indicator, queue count, clock.
	 * Per 09-UI-ROUTES-PHASE1.md Section 2.1. DaisyUI base-300 bar.
	 */
	let {
		queueCount = 0,
		processedToday = 0
	} = $props();

	let online = $state(typeof navigator !== 'undefined' ? navigator.onLine : true);
	let time = $state('');
	$effect(() => {
		const update = () => {
			time = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false });
		};
		update();
		const id = setInterval(update, 1000);
		return () => clearInterval(id);
	});
	$effect(() => {
		if (typeof window === 'undefined') return;
		const handler = () => { online = navigator.onLine; };
		window.addEventListener('online', handler);
		window.addEventListener('offline', handler);
		return () => {
			window.removeEventListener('online', handler);
			window.removeEventListener('offline', handler);
		};
	});
</script>

<div class="bg-base-300 px-4 py-2 flex items-center justify-between text-sm">
	<div class="flex items-center gap-2">
		{#if online}
			<span class="w-2.5 h-2.5 bg-success rounded-full" aria-hidden="true"></span>
			<span class="text-base-content/70">Online</span>
		{:else}
			<span class="w-2.5 h-2.5 bg-warning rounded-full animate-pulse" aria-hidden="true"></span>
			<span class="text-warning font-medium">Offline</span>
		{/if}
	</div>
	<span class="text-base-content/60">Queue: {queueCount}</span>
	<span class="text-base-content/60">Processed: {processedToday}</span>
	<span class="text-base-content/60 font-mono" aria-label="Current time">{time}</span>
</div>
