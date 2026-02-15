<script>
	/**
	 * Display/Board.svelte — client-facing "Now Serving" informant. Per 09-UI-ROUTES-PHASE1 §3.4.
	 * Public, no auth. Shows program name, date, Scan section, Now Serving grid, waiting by station, station activity.
	 */
	import { onMount } from 'svelte';
	import { router } from '@inertiajs/svelte';
	import DisplayLayout from '../../Layouts/DisplayLayout.svelte';
	import QrScanner from '../../Components/QrScanner.svelte';

	let {
		program_name = null,
		date = '',
		now_serving = [],
		waiting_by_station = [],
		total_in_queue = 0,
		station_activity = [],
	} = $props();

	let showScanner = $state(false);
	/** Latch: ignore repeated onScan callbacks after first successful scan (per gotchas — stops flicker / unresponsive OK GOT IT). */
	let scanHandled = $state(false);
	/** Activity feed: initial from props, prepended by real-time events */
	let activityFeed = $state([...(station_activity ?? [])]);

	$effect(() => {
		activityFeed = [...(station_activity ?? [])];
	});

	onMount(() => {
		if (typeof window === 'undefined' || !window.Echo) return;
		const channel = window.Echo.channel('display.activity');
		channel.listen('.station_activity', (e) => {
			const item = {
				station_name: e.station_name ?? '—',
				message: e.message ?? '',
				alias: e.alias ?? '—',
				action_type: e.action_type ?? '',
				created_at: e.created_at ?? new Date().toISOString(),
			};
			activityFeed = [item, ...activityFeed].slice(0, 20);
		});
		return () => {
			window.Echo?.leave('display.activity');
		};
	});

	function formatActivityTime(iso) {
		try {
			const d = new Date(iso);
			const now = new Date();
			const secs = Math.floor((now.getTime() - d.getTime()) / 1000);
			if (secs < 60) return 'just now';
			if (secs < 3600) return `${Math.floor(secs / 60)}m ago`;
			return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
		} catch {
			return '';
		}
	}

	function handleQrScan(decodedText) {
		if (scanHandled) return;
		scanHandled = true;
		const raw = decodedText.trim();
		// If QR contains a URL path (e.g. .../display/status/HASH), use the last segment as qr_hash
		const qrHash = raw.includes('/') ? raw.split('/').pop() ?? raw : raw;
		if (qrHash) {
			showScanner = false;
			router.visit(`/display/status/${encodeURIComponent(qrHash)}`);
		}
	}
</script>

<svelte:head>
	<title>Now Serving — FlexiQueue</title>
</svelte:head>

<DisplayLayout programName={program_name} {date}>
	<div class="flex flex-col gap-6 max-w-4xl mx-auto">
		<!-- Scan section: tap to activate camera, on scan navigate to status. Per 09-UI-ROUTES §3.4 -->
		<section>
			<h2 class="text-xl font-bold text-base-content mb-3">CHECK YOUR STATUS</h2>
			{#if showScanner}
				<div class="card bg-base-100 border border-base-300">
					<div class="card-body">
						<QrScanner active={true} onScan={handleQrScan} />
						<button
							type="button"
							class="btn btn-ghost btn-sm mt-2"
							onclick={() => (showScanner = false)}
						>
							Cancel
						</button>
					</div>
				</div>
			{:else}
				<button
					type="button"
					class="btn btn-primary btn-block text-lg py-4"
					onclick={() => {
						showScanner = true;
						scanHandled = false;
					}}
				>
					TAP TO SCAN QR CODE
				</button>
			{/if}
		</section>

		<!-- Now Serving -->
		<section>
			<h2 class="text-xl font-bold text-base-content mb-3">NOW SERVING</h2>
			{#if now_serving.length > 0}
				<div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
					{#each now_serving as entry}
						<div class="card bg-base-100 border border-base-300 shadow-sm">
							<div class="card-body p-4 text-center">
								<div class="text-4xl font-bold text-primary">{entry.alias}</div>
								<div class="text-sm text-base-content/80">{entry.station_name}</div>
								<div class="flex justify-center gap-1 mt-1">
									{#if entry.status === 'called'}
										<span class="badge badge-warning badge-sm">Calling</span>
									{/if}
									<span class="badge badge-ghost badge-sm">{entry.track}</span>
								</div>
							</div>
						</div>
					{/each}
				</div>
			{:else}
				<div class="card bg-base-100 border border-base-300">
					<div class="card-body py-8 text-center text-base-content/70">
						No one is being served right now.
					</div>
				</div>
			{/if}
		</section>

		<!-- Currently Waiting -->
		<section>
			<h2 class="text-xl font-bold text-base-content mb-3">CURRENTLY WAITING</h2>
			{#if waiting_by_station.length > 0}
				<div class="space-y-2">
					{#each waiting_by_station as row}
						<div class="card bg-base-100 border border-base-300 shadow-sm">
							<div class="card-body py-3 px-4">
								<div class="flex flex-wrap items-center gap-2">
									<span class="font-semibold text-base-content">{row.station_name}:</span>
									{#if row.serving_count != null && row.client_capacity != null}
										<span class="badge badge-sm badge-primary"
											>{row.serving_count}/{row.client_capacity} serving</span
										>
									{/if}
									<span class="text-base-content/80">
										{row.aliases.length > 0 ? row.aliases.join(', ') + ' — ' : ''}{row.count}
										{row.count === 1 ? 'client' : 'clients'} waiting
									</span>
								</div>
							</div>
						</div>
					{/each}
				</div>
				<p class="mt-2 text-sm text-base-content/70">
					Total in queue: <strong>{total_in_queue}</strong>
				</p>
			{:else}
				<div class="card bg-base-100 border border-base-300">
					<div class="card-body py-6 text-center text-base-content/70">
						No one is currently waiting.
					</div>
				</div>
			{/if}
		</section>

		<!-- Station activity (real-time via Reverb) -->
		<section>
			<h2 class="text-xl font-bold text-base-content mb-3">RECENT ACTIVITY</h2>
			{#if activityFeed.length > 0}
				<div class="card bg-base-100 border border-base-300">
					<ul class="divide-y divide-base-200">
						{#each activityFeed as item, i (String(i) + (item.created_at ?? '') + (item.alias ?? '') + (item.station_name ?? ''))}
							<li class="px-4 py-2 flex justify-between items-center gap-2">
								<span class="text-base-content/90">
									<span class="font-semibold text-base-content">{item.station_name}:</span> {item.message}
								</span>
								<span class="text-xs text-base-content/60 shrink-0">{formatActivityTime(item.created_at)}</span>
							</li>
						{/each}
					</ul>
				</div>
			{:else}
				<div class="card bg-base-100 border border-base-300">
					<div class="card-body py-6 text-center text-base-content/70">
						No recent activity.
					</div>
				</div>
			{/if}
		</section>
	</div>
</DisplayLayout>
