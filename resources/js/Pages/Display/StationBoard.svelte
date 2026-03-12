<script>
	/**
	 * Display/StationBoard.svelte — station-specific informant display (no auth).
	 * Mute/volume from server and /station/*; real-time via .display_station_settings.
	 */
	import { onMount } from 'svelte';
	import { router } from '@inertiajs/svelte';
	import DisplayLayout from '../../Layouts/DisplayLayout.svelte';
	import { prepareDisplayTts, cancelCurrentAnnouncement, playSegmentAQueued } from '../../lib/displayTts.js';
	import { toaster } from '../../lib/toaster.js';

let {
	program_name = null,
	date = '',
	station_name = '',
	station_id = 0,
	now_serving = [],
	waiting = [],
	holding = [],
	station_activity = [],
	display_audio_muted = false,
	display_audio_volume = 1,
	tts_active_language = 'en',
	tts_connector_phrase = null,
	station_tts_phrase = null,
	queueing_method_label = null,
	queue_mode_display = null,
	alternate_ratio = null,
	priority_first = null,
	alternate_priority_first = null,
	max_no_show_attempts = 3,
} = $props();

let muted = $state(false);
let volume = $state(1);
let ttsLanguage = $state('en');
let connectorPhrase = $state(null);
	let stationPhrase = $state(null);
	/** Recent activity: from props + real-time .station_activity; max 20, newest first. */
	let activityFeed = $state([]);

	$effect(() => {
		muted = !!display_audio_muted;
		volume = Math.max(0, Math.min(1, Number(display_audio_volume ?? 1)));
		const lang =
			typeof tts_active_language === 'string' && tts_active_language
				? tts_active_language
				: 'en';
		ttsLanguage = ['en', 'fil', 'ilo'].includes(lang) ? lang : 'en';
		connectorPhrase =
			typeof tts_connector_phrase === 'string' && tts_connector_phrase.trim() !== ''
				? tts_connector_phrase.trim()
				: null;
		stationPhrase =
			typeof station_tts_phrase === 'string' && station_tts_phrase.trim() !== ''
				? station_tts_phrase.trim()
				: null;
	});
	$effect(() => {
		activityFeed = [...(station_activity ?? [])];
	});

	function refreshStationData() {
		router.reload({
			only: [
				'now_serving',
				'waiting',
				'station_activity',
				'display_audio_muted',
				'display_audio_volume',
				'queueing_method_label',
				'queue_mode_display',
				'alternate_ratio',
				'priority_first',
				'alternate_priority_first'
			],
		});
	}

	function handleStationActivity(e) {
		if (Number(e?.station_id) !== Number(station_id)) {
			refreshStationData();
			return;
		}
		const item = {
			station_name: e.station_name ?? station_name,
			message: e.message ?? '',
			alias: e.alias ?? '—',
			action_type: e.action_type ?? '',
			created_at: e.created_at ?? new Date().toISOString(),
		};
		activityFeed = [item, ...activityFeed].slice(0, 20);
		if (e?.action_type === 'call') {
			const pronounceAs = (e.pronounce_as === 'word' ? 'word' : 'letters');
			playSegmentAQueued(e.alias, pronounceAs, e.token_id ?? null, {
				muted,
				volume,
				onCompleteFailure: () => {
					toaster.warning({ title: 'Audio unavailable', description: 'Call announcement could not be played.' });
				},
			});
		}
		refreshStationData();
	}

	onMount(() => {
		prepareDisplayTts();
		if (typeof window === 'undefined' || !window.Echo || !station_id) {
			if (typeof window !== 'undefined') {
				toaster.warning({ title: 'Real-time updates unavailable. Display will not receive live updates.' });
			}
			return () => cancelCurrentAnnouncement();
		}
		const echo = window.Echo;
		const channelName = 'display.station.' + station_id;
		const ch = echo.channel(channelName);
		ch.listen('.station_activity', handleStationActivity);
		ch.listen('.now_serving', refreshStationData);
		ch.listen('.queue_length', refreshStationData);
		ch.listen('.display_station_settings', (e) => {
			muted = !!e.display_audio_muted;
			volume = Math.max(0, Math.min(1, Number(e.display_audio_volume ?? 1)));
		});
		return () => {
			cancelCurrentAnnouncement();
			echo.leave(channelName);
		};
	});
</script>

<DisplayLayout programName={program_name} {date}>
	<div class="flex flex-col gap-6 max-w-4xl mx-auto">
		<header>
			<h1 class="text-2xl font-bold text-surface-950">{station_name}</h1>
		</header>

		<!-- Balance mode: how queue is served at this station (per flexiqueue-syam). -->
		{#if queue_mode_display && String(queue_mode_display).trim() !== ''}
			<section
				class="rounded-container border border-surface-200 bg-surface-50 p-4"
				aria-label="How queue is served at this station"
			>
				<h2 class="text-base font-bold text-surface-950 mb-1">Queue mode</h2>
				<p class="text-lg font-semibold text-surface-900">{queue_mode_display}</p>
				{#if alternate_ratio && Array.isArray(alternate_ratio) && alternate_ratio.length >= 2}
					<p class="text-sm text-surface-700 mt-1">
						Priority {alternate_ratio[0]} : Regular {alternate_ratio[1]}
						{#if queue_mode_display && queue_mode_display.startsWith('Alternate')}
							{#if alternate_priority_first === true}
								— Priority clients are called first in each cycle.
							{:else if alternate_priority_first === false}
								— Regular clients are called first in each cycle.
							{/if}
						{:else}
							{#if priority_first === true}
								— Priority clients are called first.
							{:else if priority_first === false}
								— Regular clients are called first.
							{/if}
						{/if}
					</p>
				{/if}
				<p class="text-sm text-surface-600 mt-1">
					{#if queue_mode_display.startsWith('FIFO')}
						Clients are served in the order they arrived (first-come, first-served).
					{:else if alternate_ratio && Array.isArray(alternate_ratio) && alternate_ratio.length >= 2}
						Priority and regular clients alternate. For every {alternate_ratio[0]} priority clients, {alternate_ratio[1]} regular client is served.
						{#if queue_mode_display && queue_mode_display.startsWith('Alternate')}
							{#if alternate_priority_first === true}
								&nbsp;Priority clients are called first in each cycle.
							{:else if alternate_priority_first === false}
								&nbsp;Regular clients are called first in each cycle.
							{/if}
						{:else}
							{#if priority_first === true}
								&nbsp;Priority clients are called first.
							{:else if priority_first === false}
								&nbsp;Regular clients are called first.
							{/if}
						{/if}
					{:else}
						Priority and regular clients alternate.
					{/if}
				</p>
			</section>
		{/if}

		{#if now_serving?.length > 0}
			<section>
				<h2 class="text-xl font-bold text-surface-950 mb-3">NOW SERVING / CALLING</h2>
				{#if now_serving.some((item) => item.status === 'called')}
					<div class="mb-3 p-4 rounded-container bg-primary-500/10 border-2 border-primary-500 text-center" aria-live="polite">
						<p class="text-sm font-semibold text-primary-700 uppercase tracking-wide">Calling now</p>
						<p class="text-3xl font-bold font-mono text-surface-950 mt-1">
							{now_serving.filter((item) => item.status === 'called').map((item) => item.alias).join(', ')}
						</p>
					</div>
				{/if}
				<ul class="space-y-3">
					{#each now_serving as item}
						<li
							class="card rounded-container border p-4 {item.status === 'called'
								? 'bg-primary-500/5 border-primary-500/50 shadow-sm'
								: 'bg-surface-50 border-surface-200'}"
						>
							<div class="flex flex-wrap items-center justify-between gap-2">
								<span class="font-mono font-bold {item.status === 'called' ? 'text-2xl text-primary-700' : 'text-lg text-surface-900'}">{item.alias}</span>
								<span class="text-sm font-medium {item.status === 'called' ? 'preset-filled-warning-500 px-2 py-1 rounded' : 'text-surface-600'}">
									{item.status === 'called' ? 'Calling' : 'Serving'}
								</span>
							</div>
							{#if item.track}
								<p class="text-xs text-surface-500 mt-1">{item.track}</p>
							{/if}
							{#if item.no_show_attempts && item.no_show_attempts > 0}
								<p class="text-xs text-surface-500 mt-2">
									No-shows: {item.no_show_attempts}/{max_no_show_attempts}
								</p>
							{/if}
						</li>
					{/each}
				</ul>
			</section>
		{/if}

		{#if waiting?.length > 0}
			<section>
				<h2 class="text-xl font-bold text-surface-950 mb-3">WAITING</h2>
				<ul class="space-y-3">
					{#each waiting as item}
						<li class="card rounded-container border border-surface-200 bg-surface-50 p-4">
							<div class="flex flex-wrap items-center justify-between gap-2">
								<div class="flex flex-col gap-1">
									<span class="font-mono font-semibold text-lg text-surface-900">{item.alias}</span>
									{#if item.process_name}
										<span class="text-xs text-surface-600">{item.process_name}</span>
									{/if}
									{#if typeof item.position === 'number'}
										<span class="text-xs text-surface-500">#{item.position} in line</span>
									{/if}
								</div>
							</div>
							{#if item.no_show_attempts && item.no_show_attempts > 0}
								<p class="text-xs text-surface-500 mt-2">
									No-shows: {item.no_show_attempts}/{max_no_show_attempts}
								</p>
							{/if}
						</li>
					{/each}
				</ul>
			</section>
		{/if}

		{#if holding?.length > 0}
			<section>
				<h2 class="text-xl font-bold text-surface-950 mb-3">ON HOLD</h2>
				<ul class="space-y-3">
					{#each holding as item}
						<li class="card rounded-container border border-surface-200 bg-surface-50 p-4">
							<div class="flex flex-wrap items-center justify-between gap-2">
								<div class="flex flex-col gap-1">
									<span class="font-mono font-semibold text-lg text-surface-900">{item.alias}</span>
									{#if item.process_name}
										<span class="text-xs text-surface-600">{item.process_name}</span>
									{/if}
									{#if item.track}
										<span class="text-xs text-surface-500">{item.track}</span>
									{/if}
								</div>
								<div class="flex flex-col items-end gap-1">
									{#if item.status === 'awaiting_approval'}
										<span class="text-[11px] px-2 py-1 rounded preset-filled-warning-500/80 text-warning-900 font-semibold uppercase tracking-wide">
											Awaiting approval
										</span>
									{:else}
										<span class="text-xs text-surface-600 capitalize">{item.status}</span>
									{/if}
								</div>
							</div>
							{#if item.held_at}
								<p class="text-xs text-surface-500 mt-2">
									Held since {new Date(item.held_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
								</p>
							{/if}
							{#if item.no_show_attempts && item.no_show_attempts > 0}
								<p class="text-xs text-surface-500 mt-1">
									No-shows: {item.no_show_attempts}/{max_no_show_attempts}
								</p>
							{/if}
						</li>
					{/each}
				</ul>
			</section>
		{/if}

		<!-- Recent activity: max 20 items, fixed-height scroll (like /display). -->
		<section>
			<h2 class="text-xl font-bold text-surface-950 mb-3">RECENT ACTIVITY</h2>
			{#if activityFeed.length > 0}
				<div class="card bg-surface-50 border border-surface-200 rounded-container overflow-hidden">
					<ul class="divide-y divide-surface-200 max-h-[12rem] overflow-y-auto" aria-label="Recent activity">
						{#each activityFeed.slice(0, 20) as item, i (String(i) + (item.created_at ?? '') + (item.message ?? '') + (item.alias ?? ''))}
							<li class="px-4 py-2 text-surface-950/90 text-sm">{item.message}</li>
						{/each}
					</ul>
				</div>
			{:else}
				<div class="card bg-surface-50 border border-surface-200 rounded-container">
					<div class="p-4 py-6 text-center text-surface-950/70">No recent activity.</div>
				</div>
			{/if}
		</section>

		{#if (!now_serving?.length && !waiting?.length && !holding?.length && activityFeed.length === 0)}
			<div class="card bg-surface-50 border border-surface-200 rounded-container p-8 text-center">
				<p class="text-surface-600 text-lg">No activity at this station.</p>
			</div>
		{/if}
	</div>
</DisplayLayout>
