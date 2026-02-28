<script>
	/**
	 * Display/StationBoard.svelte — station-specific informant display (no auth).
	 * Per plan: one station's calling, queue, activity; TTS on call; mute/volume in localStorage.
	 */
	import { onMount } from 'svelte';
	import { router } from '@inertiajs/svelte';
	import DisplayLayout from '../../Layouts/DisplayLayout.svelte';
	import { Volume2, VolumeX } from 'lucide-svelte';

	let {
		program_name = null,
		date = '',
		station_name = '',
		station_id = 0,
		now_serving = [],
		waiting = [],
		station_activity = [],
	} = $props();

	let muted = $state(false);
	let volume = $state(1);

	function getStorageKey(suffix) {
		return 'display.station.' + station_id + '.' + suffix;
	}

	function speakCall(alias) {
		if (typeof window === 'undefined' || muted || !window.speechSynthesis) return;
		const u = new SpeechSynthesisUtterance('Calling ' + (alias || 'client'));
		u.volume = Math.max(0, Math.min(1, volume));
		window.speechSynthesis.speak(u);
	}

	function refreshStationData() {
		router.reload({ only: ['now_serving', 'waiting', 'station_activity'] });
	}

	function handleStationActivity(e) {
		// Coerce to number so backend int and JSON number/string both match (plan: only speak for this station)
		if (e?.action_type === 'call' && Number(e?.station_id) === Number(station_id)) {
			speakCall(e.alias);
		}
		refreshStationData();
	}

	onMount(() => {
		if (typeof window !== 'undefined') {
			try {
				const storedMuted = localStorage.getItem(getStorageKey('muted'));
				if (storedMuted !== null) muted = storedMuted === 'true';
				const v = localStorage.getItem(getStorageKey('volume'));
				if (v !== null) volume = Math.max(0, Math.min(1, Number(v)));
			} catch (_) {}
		}
		if (typeof window === 'undefined' || !window.Echo || !station_id) return;
		const echo = window.Echo;
		const channelName = 'display.station.' + station_id;
		const ch = echo.channel(channelName);
		ch.listen('.station_activity', handleStationActivity);
		ch.listen('.now_serving', refreshStationData);
		ch.listen('.queue_length', refreshStationData);
		return () => {
			echo.leave(channelName);
		};
	});

	$effect(() => {
		if (typeof window === 'undefined') return;
		try {
			localStorage.setItem(getStorageKey('muted'), String(muted));
			localStorage.setItem(getStorageKey('volume'), String(volume));
		} catch (_) {}
	});
</script>

<DisplayLayout programName={program_name} {date}>
	<div class="space-y-6">
		<div class="flex flex-wrap items-center justify-between gap-4">
			<h1 class="text-xl font-bold text-surface-900">{station_name}</h1>
			<div class="flex items-center gap-4" role="group" aria-label="Audio controls">
				<button
					type="button"
					class="btn btn-ghost btn-sm gap-1"
					aria-pressed={muted}
					aria-label={muted ? 'Unmute' : 'Mute'}
					onclick={() => (muted = !muted)}
				>
					{#if muted}
						<VolumeX class="w-5 h-5" />
						<span>Unmute</span>
					{:else}
						<Volume2 class="w-5 h-5" />
						<span>Mute</span>
					{/if}
				</button>
				<label class="flex items-center gap-2">
					<span class="text-sm text-surface-600">Volume</span>
					<input
						type="range"
						min="0"
						max="1"
						step="0.1"
						class="range range-sm w-24"
						bind:value={volume}
						disabled={muted}
						aria-label="Volume"
					/>
				</label>
			</div>
		</div>

		{#if now_serving?.length > 0}
			<section>
				<h2 class="text-sm font-semibold text-surface-600 uppercase tracking-wide mb-2">Now serving / Calling</h2>
				<ul class="space-y-2">
					{#each now_serving as item}
						<li class="flex items-center gap-2 p-2 rounded bg-surface-200">
							<span class="font-mono font-semibold">{item.alias}</span>
							<span class="text-sm text-surface-600">{item.status === 'called' ? 'Calling' : 'Serving'}</span>
							{#if item.track}<span class="text-xs text-surface-500">{item.track}</span>{/if}
						</li>
					{/each}
				</ul>
			</section>
		{/if}

		{#if waiting?.length > 0}
			<section>
				<h2 class="text-sm font-semibold text-surface-600 uppercase tracking-wide mb-2">Waiting</h2>
				<ul class="space-y-1">
					{#each waiting as item}
						<li class="flex items-center gap-2 p-2 rounded bg-surface-100">
							<span class="font-mono font-medium">{item.alias}</span>
							{#if item.process_name}<span class="text-xs text-surface-500">{item.process_name}</span>{/if}
						</li>
					{/each}
				</ul>
			</section>
		{/if}

		{#if station_activity?.length > 0}
			<section>
				<h2 class="text-sm font-semibold text-surface-600 uppercase tracking-wide mb-2">Recent activity</h2>
				<ul class="space-y-1 text-sm">
					{#each station_activity as item}
						<li class="text-surface-700">{item.message}</li>
					{/each}
				</ul>
			</section>
		{/if}

		{#if (!now_serving?.length && !waiting?.length && !station_activity?.length)}
			<p class="text-surface-500">No activity at this station.</p>
		{/if}
	</div>
</DisplayLayout>
