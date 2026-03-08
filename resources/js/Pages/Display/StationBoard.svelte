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
	station_activity = [],
	display_audio_muted = false,
	display_audio_volume = 1,
	tts_active_language = 'en',
	tts_connector_phrase = null,
	station_tts_phrase = null,
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
			only: ['now_serving', 'waiting', 'station_activity', 'display_audio_muted', 'display_audio_volume'],
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
						</li>
					{/each}
				</ul>
			</section>
		{/if}

		{#if waiting?.length > 0}
			<section>
				<h2 class="text-xl font-bold text-surface-950 mb-3">WAITING</h2>
				<ul class="space-y-2">
					{#each waiting as item}
						<li class="flex items-center gap-3 p-3 rounded-container bg-surface-100 border border-surface-200">
							<span class="font-mono font-semibold text-surface-900">{item.alias}</span>
							{#if item.process_name}
								<span class="text-sm text-surface-600">{item.process_name}</span>
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

		{#if (!now_serving?.length && !waiting?.length && activityFeed.length === 0)}
			<div class="card bg-surface-50 border border-surface-200 rounded-container p-8 text-center">
				<p class="text-surface-600 text-lg">No activity at this station.</p>
			</div>
		{/if}
	</div>
</DisplayLayout>
