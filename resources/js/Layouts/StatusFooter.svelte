<script>
	/**
	 * StatusFooter — dual indicator (network + user availability), queue count, clock.
	 * Per staff-availability-status plan: [Network] [Availability] | Queue | Processed | time.
	 * Network: browser connectivity (Connected/Offline).
	 * Availability: user-chosen status (Available | On break | Away). Tap to cycle.
	 */
	import { usePage } from '@inertiajs/svelte';

	let {
		queueCount = 0,
		processedToday = 0
	} = $props();

	const page = usePage();
	const user = $derived($page.props?.auth?.user ?? null);
	const csrfToken = $derived($page.props?.csrf_token ?? '');

	let networkConnected = $state(typeof navigator !== 'undefined' ? navigator.onLine : true);
	/** Local availability state; synced from auth.user in $effect below */
	let availabilityStatus = $state('offline');
	let isUpdating = $state(false);
	let time = $state('');

	$effect(() => {
		const u = $page.props?.auth?.user;
		const status = u?.availability_status ?? 'offline';
		availabilityStatus = status;
	});

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
		const handler = () => { networkConnected = navigator.onLine; };
		window.addEventListener('online', handler);
		window.addEventListener('offline', handler);
		return () => {
			window.removeEventListener('online', handler);
			window.removeEventListener('offline', handler);
		};
	});

	const cycle = ['available', 'on_break', 'away'];
	const labels = { available: 'Available', on_break: 'On break', away: 'Away', offline: 'Offline' };

	async function cycleAvailability() {
		if (!user || isUpdating) return;
		const idx = cycle.indexOf(availabilityStatus);
		const next = cycle[(idx + 1) % cycle.length];
		availabilityStatus = next;
		isUpdating = true;
		try {
			const res = await fetch('/api/users/me/availability', {
				method: 'PATCH',
				headers: {
					'Content-Type': 'application/json',
					'Accept': 'application/json',
					'X-CSRF-TOKEN': csrfToken,
					'X-Requested-With': 'XMLHttpRequest',
				},
				body: JSON.stringify({ status: next }),
			});
			if (!res.ok) {
				availabilityStatus = user?.availability_status ?? 'offline';
			}
		} catch {
			availabilityStatus = user?.availability_status ?? 'offline';
		} finally {
			isUpdating = false;
		}
	}
</script>

<div class="bg-surface-200 px-4 py-2 flex items-center justify-between text-sm">
	<div class="flex items-center gap-4">
		<div class="flex items-center gap-2" aria-label="Network status">
			{#if networkConnected}
				<span class="w-2.5 h-2.5 bg-success-500 rounded-full" aria-hidden="true"></span>
				<span class="text-surface-950/70">Connected</span>
			{:else}
				<span class="w-2.5 h-2.5 bg-warning-500 rounded-full animate-pulse" aria-hidden="true"></span>
				<span class="text-warning-600 font-medium">Offline</span>
			{/if}
		</div>
		{#if user}
			<button
				type="button"
				class="flex items-center gap-2 rounded px-2 py-1 -mx-2 -my-1 min-h-[44px] min-w-[44px] touch-manipulation
					{availabilityStatus === 'available' ? 'text-success-600' : ''}
					{availabilityStatus === 'on_break' ? 'text-warning-600' : ''}
					{availabilityStatus === 'away' ? 'text-surface-950/70' : ''}
					{availabilityStatus === 'offline' ? 'text-surface-950/50' : ''}
					hover:bg-surface-300/50 disabled:opacity-60"
				onclick={cycleAvailability}
				disabled={isUpdating}
				aria-label="Availability: {labels[availabilityStatus] ?? availabilityStatus}. Tap to change"
			>
				{#if availabilityStatus === 'available'}
					<span class="w-2.5 h-2.5 bg-success-500 rounded-full" aria-hidden="true"></span>
				{:else if availabilityStatus === 'on_break'}
					<span class="w-2.5 h-2.5 bg-warning-500 rounded-full" aria-hidden="true"></span>
				{:else}
					<span class="w-2.5 h-2.5 bg-surface-400 rounded-full" aria-hidden="true"></span>
				{/if}
				<span class="text-sm">{labels[availabilityStatus] ?? availabilityStatus}</span>
			</button>
		{/if}
	</div>
	<span class="text-surface-950/60">Queue: {queueCount}</span>
	<span class="text-surface-950/60">Processed: {processedToday}</span>
	<span class="text-surface-950/60 font-mono" aria-label="Current time">{time}</span>
</div>
