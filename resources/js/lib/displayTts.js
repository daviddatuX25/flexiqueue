/**
 * Shared TTS for display call announcements.
 * Set A = prephrase + token (token TTS → server TTS → browser).
 * Set B = connector + station phrase (server TTS → browser).
 * Used by Board (A+B), StationBoard (A only).
 *
 * Fallback chain: token/server TTS → server TTS → browser TTS. Each intermediate fallback
 * invokes onFallback. Only when all options are exhausted and browser TTS fails do we
 * invoke onCompleteFailure (use this for user-facing error toasts).
 *
 * @typedef {Object} DisplayTtsOptions
 * @property {boolean} [muted=false]
 * @property {number} [volume=1] 0-1
 * @property {string|null} [preferredVoiceName=null]
 * @property {(reason: string, text: string) => void} [onFallback] Called on each intermediate fallback (e.g. 'token_failed', 'server_tts_failed')
 * @property {(reason: string, text: string) => void} [onCompleteFailure] Called only when all TTS options exhausted and playback failed
 */

import { getVoiceForTts, ensureVoicesLoaded, TTS_DEFAULT_RATE } from './speechUtils.js';

export const DEFAULT_OPTIONS = {
	muted: false,
	volume: 1,
	preferredVoiceName: null,
	onFallback: undefined,
	onCompleteFailure: undefined,
};

const LETTER_PHONETIC = {
	a: 'ay', b: 'bee', c: 'see', d: 'dee', e: 'ee', f: 'eff', g: 'jee', h: 'aych',
	i: 'eye', j: 'jay', k: 'kay', l: 'ell', m: 'em', n: 'en', o: 'oh', p: 'pee',
	q: 'cue', r: 'ar', s: 'ess', t: 'tee', u: 'you', v: 'vee', w: 'double you',
	x: 'ex', y: 'why', z: 'zee',
};

let currentAudio = null;
let currentObjectUrl = null;
let pendingRepeatTimeoutId = null;

/** Single typed job queue: { type: 'full', params } | { type: 'segmentA', alias, pronounceAs, tokenId, opts }. */
const jobQueue = [];
let currentJobPromise = null;

function resolveOptions(options) {
	const o = { ...DEFAULT_OPTIONS, ...options };
	o.volume = Math.max(0, Math.min(1, Number(o.volume ?? 1)));
	return o;
}

export function cancelCurrentAnnouncement() {
	if (currentAudio) {
		try {
			currentAudio.pause();
			currentAudio.currentTime = 0;
		} catch (_) {}
		currentAudio = null;
	}
	if (currentObjectUrl) {
		try {
			URL.revokeObjectURL(currentObjectUrl);
		} catch (_) {}
		currentObjectUrl = null;
	}
	if (typeof window !== 'undefined' && window.speechSynthesis) {
		window.speechSynthesis.cancel();
	}
	if (pendingRepeatTimeoutId != null) {
		clearTimeout(pendingRepeatTimeoutId);
		pendingRepeatTimeoutId = null;
	}
	jobQueue.length = 0;
	currentJobPromise = null;
}

export function prepareDisplayTts(onVoicesReady) {
	ensureVoicesLoaded(onVoicesReady);
}

function aliasForSpeech(alias, pronounceAs) {
	const raw = (alias ?? 'client').toString().trim() || 'client';
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

function clearCurrentAudio() {
	currentAudio = null;
	if (currentObjectUrl) {
		try {
			URL.revokeObjectURL(currentObjectUrl);
		} catch (_) {}
		currentObjectUrl = null;
	}
}

async function playTokenTts(tokenId, opts) {
	const o = resolveOptions(opts);
	if (o.muted) return Promise.resolve();
	const url = `/api/public/tts/token/${tokenId}`;
	const res = await fetch(url, { credentials: 'same-origin' });
	if (!res.ok) {
		o.onFallback?.('token_failed', `token ${tokenId}`);
		throw new Error(res.status === 404 ? 'No token TTS' : `TTS ${res.status}`);
	}
	const blob = await res.blob();
	currentObjectUrl = URL.createObjectURL(blob);
	currentAudio = new Audio(currentObjectUrl);
	currentAudio.volume = o.volume;
	return new Promise((resolve, reject) => {
		currentAudio.onended = () => {
			clearCurrentAudio();
			resolve();
		};
		currentAudio.onerror = () => {
			o.onFallback?.('token_failed', `token ${tokenId}`);
			clearCurrentAudio();
			reject(new Error('Playback failed'));
		};
		currentAudio.play().catch((e) => {
			o.onFallback?.('token_failed', `token ${tokenId}`);
			clearCurrentAudio();
			reject(e);
		});
	});
}

/**
 * Fetch server TTS blob only (no play). Used to preload segment B while A is playing.
 * Returns { blob } or throws. Does not touch currentAudio.
 */
async function fetchServerTtsBlob(text, opts) {
	const o = resolveOptions(opts);
	if (o.muted) return Promise.reject(new Error('muted'));
	const params = new URLSearchParams({ text });
	const url = `/api/public/tts?${params.toString()}`;
	const cacheName = 'flexiqueue-tts';
	if (typeof caches !== 'undefined') {
		try {
			const cache = await caches.open(cacheName);
			const cached = await cache.match(url);
			if (cached && cached.ok) {
				const blob = await cached.blob();
				return { blob };
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
			// Ignore
		}
	}
	return { blob };
}

/**
 * Play a preloaded blob (e.g. segment B). Creates Audio from blob, plays, resolves when ended.
 */
function playBlob(blob, opts) {
	const o = resolveOptions(opts);
	if (o.muted) return Promise.resolve();
	const objectUrl = URL.createObjectURL(blob);
	const audio = new Audio(objectUrl);
	audio.volume = o.volume;
	// Temporarily hold our own reference; we'll assign to currentAudio when we're the active play
	const prevAudio = currentAudio;
	currentAudio = audio;
	return new Promise((resolve, reject) => {
		audio.onended = () => {
			currentAudio = null;
			try {
				URL.revokeObjectURL(objectUrl);
			} catch (_) {}
			resolve();
		};
		audio.onerror = () => {
			currentAudio = prevAudio;
			try {
				URL.revokeObjectURL(objectUrl);
			} catch (_) {}
			reject(new Error('Playback failed'));
		};
		audio.play().catch((e) => {
			currentAudio = prevAudio;
			try {
				URL.revokeObjectURL(objectUrl);
			} catch (_) {}
			reject(e);
		});
	});
}

async function playServerTts(text, opts) {
	const o = resolveOptions(opts);
	if (o.muted) return Promise.resolve();
	const { blob } = await fetchServerTtsBlob(text, opts);
	currentObjectUrl = URL.createObjectURL(blob);
	currentAudio = new Audio(currentObjectUrl);
	currentAudio.volume = o.volume;
	return new Promise((resolve, reject) => {
		currentAudio.onended = () => {
			clearCurrentAudio();
			resolve();
		};
		currentAudio.onerror = () => {
			o.onFallback?.('server_tts_failed', text);
			clearCurrentAudio();
			reject(new Error('Playback failed'));
		};
		currentAudio.play().catch((e) => {
			o.onFallback?.('server_tts_failed', text);
			clearCurrentAudio();
			reject(e);
		});
	});
}

/**
 * Returns a Promise that resolves when the utterance ends (or on error).
 * We only resolve on onend/onerror so Segment B never starts while A is still speaking (no early-out; browser TTS timing varies by voice and rate).
 * Calls onCompleteFailure when browser TTS is unavailable or fails (all options exhausted).
 */
function speakBrowser(text, opts) {
	const o = resolveOptions(opts);
	if (o.muted) return Promise.resolve();
	if (typeof window === 'undefined' || !window.speechSynthesis) {
		o.onCompleteFailure?.('browser_unavailable', text);
		return Promise.resolve();
	}
	window.speechSynthesis.cancel();
	const u = new SpeechSynthesisUtterance(text);
	u.rate = TTS_DEFAULT_RATE;
	u.volume = o.volume;
	const voice = getVoiceForTts(o.preferredVoiceName);
	if (voice) u.voice = voice;
	return new Promise((resolve) => {
		u.onend = () => resolve();
		u.onerror = () => {
			o.onCompleteFailure?.('browser_tts_failed', text);
			resolve();
		};
		window.speechSynthesis.speak(u);
	});
}

export function playSegmentA(alias, pronounceAs, tokenId, options) {
	const opts = resolveOptions(options);
	const firstText = 'Calling ' + aliasForSpeech(alias, pronounceAs);
	if (tokenId != null) {
		return playTokenTts(tokenId, opts).catch(() =>
			playServerTts(firstText, opts).catch(() => {
				opts.onFallback?.('server_tts_failed', firstText);
				return speakBrowser(firstText, opts);
			})
		);
	}
	return playServerTts(firstText, opts).catch(() => {
		opts.onFallback?.('server_tts_failed', firstText);
		return speakBrowser(firstText, opts);
	});
}

/**
 * Queueable segment A (prephrase + token). Use on StationBoard so multiple rapid calls play in order instead of canceling.
 */
export function playSegmentAQueued(alias, pronounceAs, tokenId, options) {
	const opts = resolveOptions(options);
	if (currentJobPromise != null) {
		jobQueue.push({ type: 'segmentA', alias, pronounceAs, tokenId, opts });
		return currentJobPromise;
	}
	currentJobPromise = playSegmentA(alias, pronounceAs, tokenId, opts).then(() =>
		processNextJob()
	);
	return currentJobPromise;
}

export function playSegmentB(connectorPhrase, stationPhraseOrName, options) {
	const opts = resolveOptions(options);
	const secondText = connectorPhrase
		? connectorPhrase + ' ' + stationPhraseOrName
		: stationPhraseOrName;
	return playServerTts(secondText, opts).catch(() => {
		opts.onFallback?.('server_tts_failed', secondText);
		return speakBrowser(secondText, opts);
	});
}

/**
 * Run one full announcement (A+B, with optional repeats). Does not check queue; used internally and for repeats.
 * Returns a Promise that resolves when this announcement is fully done (including all repeats).
 */
function runOneAnnouncement(params) {
	const opts = resolveOptions(params.options);
	const stationPhrase = (params.stationTtsByName?.[params.stationName] ?? params.stationName ?? 'your station').toString().trim();
	const repeatCount = Math.max(1, Math.floor(Number(params.repeatCount) || 1));
	const repeatDelayMs = Math.max(0, Math.floor(Number(params.repeatDelayMs) || 2000));
	const secondText = params.connectorPhrase
		? params.connectorPhrase + ' ' + stationPhrase
		: stationPhrase;
	const preloadBPromise = fetchServerTtsBlob(secondText, opts).catch(() => null);

	const playOnce = () =>
		playSegmentA(params.alias, params.pronounceAs, params.tokenId, opts).then(async () => {
			const preloaded = await preloadBPromise;
			if (preloaded?.blob) {
				return playBlob(preloaded.blob, opts).catch(() =>
					playSegmentB(params.connectorPhrase, stationPhrase, opts)
				);
			}
			return playSegmentB(params.connectorPhrase, stationPhrase, opts);
		});

	const onePlay = repeatCount <= 1
		? playOnce()
		: playOnce().then(
				() =>
					new Promise((resolve) => {
						pendingRepeatTimeoutId = setTimeout(() => {
							pendingRepeatTimeoutId = null;
							runOneAnnouncement({ ...params, repeatCount: repeatCount - 1 }).then(resolve);
						}, repeatDelayMs);
					})
			);

	return onePlay;
}

/**
 * Process next job in queue (full or segmentA). Runs after current job completes.
 */
function processNextJob() {
	if (jobQueue.length === 0) {
		currentJobPromise = null;
		return;
	}
	const job = jobQueue.shift();
	if (job.type === 'full') {
		currentJobPromise = runOneAnnouncement(job.params).then(() => processNextJob());
	} else {
		currentJobPromise = playSegmentA(job.alias, job.pronounceAs, job.tokenId, job.opts).then(
			() => processNextJob()
		);
	}
}

/**
 * Play full announcement (segment A + B, optional repeat). If one is already playing, this one is queued and plays after.
 */
export function playFullAnnouncement(params) {
	if (currentJobPromise != null) {
		jobQueue.push({ type: 'full', params });
		return currentJobPromise;
	}
	currentJobPromise = runOneAnnouncement(params).then(() => processNextJob());
	return currentJobPromise;
}

/**
 * Build params for playFullAnnouncement from a station_activity event and display options.
 * Use so Board (and any future consumer) does not need to know the full job shape.
 */
export function createFullAnnouncementParams(event, options) {
	const pronounceAs = event?.pronounce_as === 'word' ? 'word' : 'letters';
	const stationName = options?.stationName ?? event?.station_name ?? 'your station';
	return {
		alias: event?.alias ?? '—',
		stationName,
		pronounceAs,
		tokenId: event?.token_id ?? null,
		connectorPhrase: options?.connectorPhrase ?? null,
		stationTtsByName: options?.stationTtsByName ?? {},
		options: {
			muted: options?.muted ?? false,
			volume: options?.volume ?? 1,
			onFallback: options?.onFallback,
			onCompleteFailure: options?.onCompleteFailure,
		},
		repeatCount: Math.max(1, Math.floor(Number(options?.repeatCount) ?? 1)),
		repeatDelayMs: Math.max(0, Math.floor(Number(options?.repeatDelayMs) ?? 2000)),
	};
}
