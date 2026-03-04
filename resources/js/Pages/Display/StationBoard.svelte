<script>
	/**
	 * Display/StationBoard.svelte — station-specific informant display (no auth).
	 * Mute/volume from server and /station/*; real-time via .display_station_settings.
	 */
	import { onMount } from 'svelte';
	import { router } from '@inertiajs/svelte';
	import DisplayLayout from '../../Layouts/DisplayLayout.svelte';
	import { getVoiceForTts, ensureVoicesLoaded, TTS_DEFAULT_RATE } from '../../lib/speechUtils.js';

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
		tts_source = 'browser',
		display_tts_voice = null,
	} = $props();

	let muted = $state(false);
	let volume = $state(1);
	let ttsSource = $state('browser');
	let ttsVoice = $state(null);
	/** Pending second-speak timeout; cleared when new call or unmount. */
	let repeatTimeoutId = $state(null);
	/** Recent activity: from props + real-time .station_activity; max 20, newest first. */
	let activityFeed = $state([]);

	$effect(() => {
		muted = !!display_audio_muted;
		volume = Math.max(0, Math.min(1, Number(display_audio_volume ?? 1)));
		ttsSource = tts_source === 'server' ? 'server' : 'browser';
		ttsVoice = display_tts_voice ?? null;
	});

	/** Effective TTS source: server (use API) or browser (speechSynthesis). */
	const effectiveTtsSource = $derived(ttsSource ?? tts_source ?? 'browser');
	/** Effective TTS voice: prefer synced state, fall back to prop so refresh always applies. */
	const effectiveTtsVoice = $derived(ttsVoice ?? display_tts_voice ?? null);
	$effect(() => {
		activityFeed = [...(station_activity ?? [])];
	});

	/** Phonetic words for letters so TTS says "ay" not "uh". */
	const LETTER_PHONETIC = {
		a: 'ay', b: 'bee', c: 'see', d: 'dee', e: 'ee', f: 'eff', g: 'jee', h: 'aych',
		i: 'eye', j: 'jay', k: 'kay', l: 'ell', m: 'em', n: 'en', o: 'oh', p: 'pee',
		q: 'cue', r: 'ar', s: 'ess', t: 'tee', u: 'you', v: 'vee', w: 'double you',
		x: 'ex', y: 'why', z: 'zee',
	};

	/** Build alias text for TTS: letters = phonetic + digit runs; word = as-is. */
	function aliasForSpeech(alias, pronounceAs) {
		const raw = (alias || 'client').toString().trim() || 'client';
		if (pronounceAs === 'word') return raw;
		const segments = [];
		let i = 0;
		while (i < raw.length) {
			if (/[a-zA-Z]/.test(raw[i])) {
				let run = '';
				while (i < raw.length && /[a-zA-Z]/.test(raw[i])) {
					run += raw[i++];
				}
				for (const c of run) {
					const ph = LETTER_PHONETIC[c.toLowerCase()];
					if (ph) segments.push(ph);
				}
			} else if (/\d/.test(raw[i])) {
				let run = '';
				while (i < raw.length && /\d/.test(raw[i])) {
					run += raw[i++];
				}
				segments.push(run);
			} else {
				i++;
			}
		}
		return segments.length ? segments.join(' ') : raw;
	}

	/** Play pre-generated token TTS; returns Promise that resolves when playback ends or rejects on 404/error. */
	async function playTokenTts(tokenId) {
		const url = `/api/public/tts/token/${tokenId}`;
		const res = await fetch(url, { credentials: 'same-origin' });
		if (!res.ok) throw new Error(res.status === 404 ? 'No token TTS' : `TTS ${res.status}`);
		const blob = await res.blob();
		const objectUrl = URL.createObjectURL(blob);
		await new Promise((resolve, reject) => {
			const a = new Audio(objectUrl);
			a.volume = Math.max(0, Math.min(1, volume));
			a.onended = () => { URL.revokeObjectURL(objectUrl); resolve(); };
			a.onerror = () => { URL.revokeObjectURL(objectUrl); reject(new Error('Playback failed')); };
			a.play().catch(reject);
		});
	}

	/** Play TTS audio from server; returns Promise that resolves when playback ends or rejects on error/503. */
	async function playServerTts(text, voiceId) {
		const params = new URLSearchParams({ text });
		if (voiceId) params.set('voice', voiceId);
		params.set('rate', String(TTS_DEFAULT_RATE));
		const url = `/api/public/tts?${params.toString()}`;
		const cacheName = 'flexiqueue-tts';
		if (typeof caches !== 'undefined') {
			try {
				const cache = await caches.open(cacheName);
				const cached = await cache.match(url);
				if (cached && cached.ok) {
					const blob = await cached.blob();
					const objectUrl = URL.createObjectURL(blob);
					await new Promise((resolve, reject) => {
						const a = new Audio(objectUrl);
						a.volume = Math.max(0, Math.min(1, volume));
						a.onended = () => { URL.revokeObjectURL(objectUrl); resolve(); };
						a.onerror = () => { URL.revokeObjectURL(objectUrl); reject(new Error('Playback failed')); };
						a.play().catch(reject);
					});
					return;
				}
			} catch {
				// Fall through to fetch
			}
		}
		const res = await fetch(url, { credentials: 'same-origin' });
		if (!res.ok) throw new Error(res.status === 503 ? 'TTS unavailable' : `TTS ${res.status}`);
		const blob = await res.blob();
		if (typeof caches !== 'undefined') {
			try {
				const cache = await caches.open(cacheName);
				await cache.put(url, new Response(blob, { headers: { 'Content-Type': 'audio/mpeg' } }));
			} catch {
				// Ignore cache write errors
			}
		}
		const objectUrl = URL.createObjectURL(blob);
		await new Promise((resolve, reject) => {
			const a = new Audio(objectUrl);
			a.volume = Math.max(0, Math.min(1, volume));
			a.onended = () => { URL.revokeObjectURL(objectUrl); resolve(); };
			a.onerror = () => { URL.revokeObjectURL(objectUrl); reject(new Error('Playback failed')); };
			a.play().catch(reject);
		});
	}

	function speakCall(alias, pronounceAs = 'letters', tokenId = null) {
		if (typeof window === 'undefined' || muted) return;
		if (repeatTimeoutId != null) {
			clearTimeout(repeatTimeoutId);
			repeatTimeoutId = null;
		}
		const text = 'Calling ' + aliasForSpeech(alias, pronounceAs);
		const doBrowserSpeak = () => {
			if (muted || !window.speechSynthesis) return;
			const u = new SpeechSynthesisUtterance(text);
			u.rate = TTS_DEFAULT_RATE;
			u.volume = Math.max(0, Math.min(1, volume));
			const voice = getVoiceForTts(effectiveTtsVoice ?? null);
			if (voice) u.voice = voice;
			window.speechSynthesis.speak(u);
		};
		const doServerTtsFallback = () => {
			playServerTts(text, effectiveTtsVoice ?? undefined).catch(() => doBrowserSpeak());
		};
		if (effectiveTtsSource === 'server') {
			if (tokenId != null) {
				playTokenTts(tokenId).catch(doServerTtsFallback);
			} else {
				doServerTtsFallback();
			}
			repeatTimeoutId = setTimeout(() => {
				if (tokenId != null) {
					playTokenTts(tokenId).catch(doServerTtsFallback);
				} else {
					doServerTtsFallback();
				}
				repeatTimeoutId = null;
			}, 2000);
		} else {
			doBrowserSpeak();
			repeatTimeoutId = setTimeout(() => {
				doBrowserSpeak();
				repeatTimeoutId = null;
			}, 2000);
		}
	}

	function refreshStationData() {
		router.reload({
			only: ['now_serving', 'waiting', 'station_activity', 'display_audio_muted', 'display_audio_volume', 'tts_source', 'display_tts_voice'],
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
			const pronounceAs = e.pronounce_as === 'word' ? 'word' : 'letters';
			speakCall(e.alias, pronounceAs, e.token_id ?? null);
		}
		refreshStationData();
	}

	onMount(() => {
		ensureVoicesLoaded();
		if (typeof window === 'undefined' || !window.Echo || !station_id) return;
		const echo = window.Echo;
		const channelName = 'display.station.' + station_id;
		const ch = echo.channel(channelName);
		ch.listen('.station_activity', handleStationActivity);
		ch.listen('.now_serving', refreshStationData);
		ch.listen('.queue_length', refreshStationData);
		ch.listen('.display_station_settings', (e) => {
			muted = !!e.display_audio_muted;
			volume = Math.max(0, Math.min(1, Number(e.display_audio_volume ?? 1)));
			if (e.tts_source === 'server') ttsSource = 'server';
			else ttsSource = 'browser';
			ttsVoice = e.display_tts_voice ?? null;
		});
		return () => {
			if (repeatTimeoutId != null) clearTimeout(repeatTimeoutId);
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
