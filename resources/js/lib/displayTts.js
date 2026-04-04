/**
 * Shared TTS for display call announcements.
 * Token call = pre-phrase + token (pre-generated MP3 → browser TTS).
 * Station directions = connecting phrase + station wording/name (pre-generated or browser TTS).
 * Export names still use playSegmentA / playSegmentB for historical reasons.
 * Used by Board (full call + directions), StationBoard (token call only).
 *
 * Fallback chain: pre-generated → browser TTS. ElevenLabs is used only for generation (jobs);
 * playback never calls server TTS. Each intermediate fallback invokes onFallback. Only when
 * all options are exhausted and browser TTS fails do we invoke onCompleteFailure.
 *
 * @typedef {Object} DisplayTtsOptions
 * @property {boolean} [muted=false]
 * @property {number} [volume=1] 0-1
 * @property {string|null} [preferredVoiceName=null]
 * @property {(reason: string, text: string) => void} [onFallback] Called on each intermediate fallback (e.g. 'token_failed')
 * @property {(reason: string, text: string) => void} [onCompleteFailure] Called only when all TTS options exhausted and playback failed
 */

import { getVoiceForTts, ensureVoicesLoaded, TTS_DEFAULT_RATE } from './speechUtils.js';

export const DEFAULT_OPTIONS = {
	muted: false,
	volume: 1,
	preferredVoiceName: null,
	/** When set, replaces alias phonetics in "Calling …" fallback (server/browser) for custom token pronunciation. */
	tokenSpokenPart: null,
	/** From token_tts_settings.playback: when false, announcements use browser TTS only (no token MP3 fetch). */
	preferGeneratedAudio: true,
	/** From token_tts_settings.playback: when false, no station directions; optional closing line after token call. */
	segment2Enabled: true,
	/** Active-language default pre_phrase from site settings (may be empty). */
	defaultPrePhrase: '',
	/** Active-language token_bridge_tail from site settings. */
	tokenBridgeTail: '',
	/** Active-language optional line after token call when station directions are off (trimmed empty = skip). */
	closingWithoutSegment2: '',
	ttsLanguage: 'en',
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
let audioContext = null;
let currentBufferSource = null;
let activeStitchedKey = null;
let announcementGeneration = 0;
const playbackTelemetry = {
	stitchedCacheHit: 0,
	stitchedCacheMiss: 0,
	stitchedBuildSuccess: 0,
	stitchedBuildFailure: 0,
	stitchedEvictions: 0,
	stitchedAborts: 0,
	fallbackTokenFailed: 0,
};

const STITCH_PRELOAD_MAX_INFLIGHT = 3;
const STITCH_PRELOAD_MAX_CACHE = 20;
const stitchedCache = new Map();
const stitchedLruOrder = [];
const stitchedPreloadQueue = [];
let stitchedInflightCount = 0;

function normalizeTtsLanguage(lang) {
	return ['en', 'fil', 'ilo'].includes(lang) ? lang : 'en';
}

function createStitchedKey(tokenId, stationId, lang) {
	if (tokenId == null || stationId == null) return null;
	return `${tokenId}:${stationId}:${normalizeTtsLanguage(lang)}`;
}

function getAudioContext() {
	if (typeof window === 'undefined') return null;
	const Ctx = window.AudioContext || window.webkitAudioContext;
	if (!Ctx) return null;
	if (!audioContext) audioContext = new Ctx();
	return audioContext;
}

function removeLruKey(key) {
	const idx = stitchedLruOrder.indexOf(key);
	if (idx >= 0) stitchedLruOrder.splice(idx, 1);
}

function touchLruKey(key) {
	removeLruKey(key);
	stitchedLruOrder.push(key);
}

function evictStitchedCacheIfNeeded() {
	while (stitchedLruOrder.length > STITCH_PRELOAD_MAX_CACHE) {
		const staleKey = stitchedLruOrder.shift();
		if (!staleKey) return;
		const entry = stitchedCache.get(staleKey);
		if (entry?.inflightAbortController) {
			try {
				entry.inflightAbortController.abort();
			} catch (_) {}
		}
		stitchedCache.delete(staleKey);
		playbackTelemetry.stitchedEvictions += 1;
	}
}

function fetchArrayBuffer(url, signal) {
	return fetch(url, {
		credentials: 'same-origin',
		signal,
	}).then((res) => {
		if (!res.ok) {
			throw new Error(`Audio fetch failed: ${res.status}`);
		}
		return res.arrayBuffer();
	});
}

function decodeMp3ArrayBuffer(arrayBuffer) {
	const ctx = getAudioContext();
	if (!ctx) throw new Error('WebAudio unavailable');
	return ctx.decodeAudioData(arrayBuffer.slice(0));
}

/**
 * Return the sample index where audio goes silent at the end of the buffer.
 * Silence threshold: peak amplitude < 0.005 across all channels for at least 50 ms.
 */
function findTrailingSilenceStart(buffer, silenceThreshold = 0.005) {
	const minSilentSamples = Math.floor(buffer.sampleRate * 0.05); // 50 ms
	let silentRun = 0;
	for (let i = buffer.length - 1; i >= 0; i -= 1) {
		let peak = 0;
		for (let ch = 0; ch < buffer.numberOfChannels; ch += 1) {
			peak = Math.max(peak, Math.abs(buffer.getChannelData(ch)[i]));
		}
		if (peak < silenceThreshold) {
			silentRun += 1;
		} else {
			if (silentRun >= minSilentSamples) return i + 1;
			silentRun = 0;
		}
	}
	return silentRun >= minSilentSamples ? 0 : buffer.length;
}

function stitchAudioBuffers(first, second) {
	const ctx = getAudioContext();
	if (!ctx) throw new Error('WebAudio unavailable');
	const trimEnd = findTrailingSilenceStart(first);
	const firstLen = Math.min(trimEnd, first.length);
	const channels = Math.max(first.numberOfChannels, second.numberOfChannels);
	const sampleRate = first.sampleRate;
	const totalLength = firstLen + second.length;
	const stitched = ctx.createBuffer(channels, totalLength, sampleRate);
	for (let ch = 0; ch < channels; ch += 1) {
		const channelData = stitched.getChannelData(ch);
		const firstData = first.getChannelData(Math.min(ch, first.numberOfChannels - 1));
		const secondData = second.getChannelData(Math.min(ch, second.numberOfChannels - 1));
		channelData.set(firstData.subarray(0, firstLen), 0);
		channelData.set(secondData, firstLen);
	}
	return stitched;
}

function getStationDirectionsUrl(stationId, lang) {
	return `/api/public/tts/station/${stationId}/${normalizeTtsLanguage(lang)}`;
}

async function buildStitchedAudioBuffer(tokenId, stationId, lang, signal) {
	const [tokenRaw, stationRaw] = await Promise.all([
		fetchArrayBuffer(`/api/public/tts/token/${tokenId}`, signal),
		fetchArrayBuffer(getStationDirectionsUrl(stationId, lang), signal),
	]);
	const [tokenBuf, stationBuf] = await Promise.all([
		decodeMp3ArrayBuffer(tokenRaw),
		decodeMp3ArrayBuffer(stationRaw),
	]);
	return stitchAudioBuffers(tokenBuf, stationBuf);
}

function queueStitchedPreloadWork(preloadTask) {
	stitchedPreloadQueue.push(preloadTask);
	processStitchedPreloadQueue();
}

function processStitchedPreloadQueue() {
	while (stitchedInflightCount < STITCH_PRELOAD_MAX_INFLIGHT && stitchedPreloadQueue.length > 0) {
		const task = stitchedPreloadQueue.shift();
		if (!task) return;
		stitchedInflightCount += 1;
		task().finally(() => {
			stitchedInflightCount = Math.max(0, stitchedInflightCount - 1);
			processStitchedPreloadQueue();
		});
	}
}

function ensureStitchedBufferForTarget(target) {
	const key = createStitchedKey(target?.tokenId, target?.stationId, target?.lang);
	if (!key) return Promise.resolve(null);
	const existing = stitchedCache.get(key);
	if (existing?.buffer) {
		touchLruKey(key);
		playbackTelemetry.stitchedCacheHit += 1;
		return Promise.resolve(existing.buffer);
	}
	playbackTelemetry.stitchedCacheMiss += 1;
	if (existing?.inflightPromise) return existing.inflightPromise;

	const abortController = new AbortController();
	const inflightPromise = new Promise((resolve) => {
		queueStitchedPreloadWork(async () => {
			const liveEntry = stitchedCache.get(key);
			if (!liveEntry || liveEntry.inflightAbortController !== abortController) {
				resolve(null);
				return;
			}
			try {
				const stitched = await buildStitchedAudioBuffer(
					target.tokenId,
					target.stationId,
					target.lang,
					abortController.signal
				);
				const afterBuild = stitchedCache.get(key);
				if (!afterBuild || afterBuild.inflightAbortController !== abortController) {
					resolve(null);
					return;
				}
				stitchedCache.set(key, { key, buffer: stitched, target });
				touchLruKey(key);
				evictStitchedCacheIfNeeded();
				playbackTelemetry.stitchedBuildSuccess += 1;
				resolve(stitched);
			} catch (_) {
				const afterError = stitchedCache.get(key);
				if (afterError?.inflightAbortController === abortController) {
					stitchedCache.delete(key);
				}
				removeLruKey(key);
				playbackTelemetry.stitchedBuildFailure += 1;
				resolve(null);
			}
		});
	});

	stitchedCache.set(key, {
		key,
		target,
		inflightPromise,
		inflightAbortController: abortController,
	});
	return inflightPromise;
}

function playAudioBuffer(buffer, opts) {
	const ctx = getAudioContext();
	const o = resolveOptions(opts);
	if (!ctx || !buffer || o.muted) return Promise.reject(new Error('No WebAudio playback'));
	return new Promise((resolve, reject) => {
		try {
			const gainNode = ctx.createGain();
			gainNode.gain.value = o.volume;
			const source = ctx.createBufferSource();
			source.buffer = buffer;
			source.connect(gainNode).connect(ctx.destination);
			currentBufferSource = source;
			const playGen = announcementGeneration;
			source.onended = () => {
				if (currentBufferSource === source) currentBufferSource = null;
				if (announcementGeneration !== playGen) return;
				resolve();
			};
			source.start(0);
		} catch (error) {
			reject(error);
		}
	});
}

export function syncStitchedPreloadTargets(targets, options = {}) {
	const enabled = options?.enabled !== false;
	const uniqueTargets = new Map();
	(targets || []).forEach((target) => {
		const key = createStitchedKey(target?.tokenId, target?.stationId, target?.lang);
		if (!key) return;
		uniqueTargets.set(key, {
			tokenId: target.tokenId,
			stationId: target.stationId,
			lang: normalizeTtsLanguage(target.lang),
		});
	});

	for (const [key, entry] of stitchedCache.entries()) {
		if (enabled && uniqueTargets.has(key)) continue;
		if (key === activeStitchedKey) continue;
		if (entry?.inflightAbortController) {
			try {
				entry.inflightAbortController.abort();
			} catch (_) {}
			playbackTelemetry.stitchedAborts += 1;
		}
		removeLruKey(key);
		stitchedCache.delete(key);
	}

	if (!enabled) return;
	for (const target of uniqueTargets.values()) {
		ensureStitchedBufferForTarget(target);
	}
}

export function clearStitchedPreloads() {
	for (const entry of stitchedCache.values()) {
		if (entry?.inflightAbortController) {
			try {
				entry.inflightAbortController.abort();
			} catch (_) {}
			playbackTelemetry.stitchedAborts += 1;
		}
	}
	stitchedCache.clear();
	stitchedLruOrder.length = 0;
	stitchedPreloadQueue.length = 0;
}

export function getDisplayTtsTelemetry() {
	return { ...playbackTelemetry };
}

export function resetDisplayTtsTelemetry() {
	for (const key of Object.keys(playbackTelemetry)) {
		playbackTelemetry[key] = 0;
	}
}

function resolveOptions(options) {
	const o = { ...DEFAULT_OPTIONS, ...options };
	o.volume = Math.max(0, Math.min(1, Number(o.volume ?? 1)));
	return o;
}

export function cancelCurrentAnnouncement() {
	announcementGeneration += 1;
	activeStitchedKey = null;
	if (currentBufferSource) {
		try {
			currentBufferSource.stop(0);
		} catch (_) {}
		currentBufferSource = null;
	}
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

/** Word mode: letter runs as one chunk each, then digit runs (e.g. AAB3 → "AAB 3"). Matches TtsPhrase::aliasWordLetterRunsAndDigits. */
function aliasWordLetterRunsAndDigits(alias) {
	const raw = (alias ?? 'client').toString().trim() || 'client';
	const parts = [];
	let i = 0;
	while (i < raw.length) {
		if (/[a-zA-Z]/.test(raw[i])) {
			let run = '';
			while (i < raw.length && /[a-zA-Z]/.test(raw[i])) run += raw[i++];
			parts.push(run);
		} else if (/\d/.test(raw[i])) {
			let run = '';
			while (i < raw.length && /\d/.test(raw[i])) run += raw[i++];
			parts.push(run);
		} else {
			i++;
		}
	}
	return parts.length ? parts.join(' ') : raw;
}

function aliasForSpeech(alias, pronounceAs) {
	const raw = (alias ?? 'client').toString().trim() || 'client';
	if (pronounceAs === 'word' || pronounceAs === 'custom') {
		return aliasWordLetterRunsAndDigits(raw);
	}
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

/**
 * Match App\Services\Tts\AnnouncementBuilder::buildSegment1 (display/browser path).
 */
export function buildDisplaySegment1Text(
	alias,
	pronounceAs,
	tokenSpokenPart,
	defaultPrePhrase,
	tokenBridgeTail,
	segment2Enabled = true
) {
	// offline fallback only, do not use when server is reachable.
	// When server TTS is reachable, runtime should fetch builder-backed segment text instead of rebuilding it here.
	const part =
		tokenSpokenPart != null && String(tokenSpokenPart).trim() !== ''
			? String(tokenSpokenPart).trim()
			: aliasForSpeech(alias, pronounceAs);
	const pre = (defaultPrePhrase ?? '').trim();
	// Match AnnouncementBuilder: when segment 2 is enabled, segment 1 must not include bridge-tail/closing.
	const tail = segment2Enabled !== false ? '' : (tokenBridgeTail ?? '').trim();
	if (pre === '' && tail === '') {
		if (segment2Enabled !== false) {
			return `Calling ${part}`.trim();
		}
		return `Calling ${part}, please proceed to your station`;
	}
	const lead = pre === '' ? 'Calling' : pre;
	return [lead, part, tail].filter(Boolean).join(' ').replace(/\s+/g, ' ').trim();
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
	const res = await fetch(url, {
		credentials: 'same-origin',
		signal: AbortSignal.timeout(5000),
	});
	if (!res.ok) {
		o.onFallback?.('token_failed', `token ${tokenId}`);
		playbackTelemetry.fallbackTokenFailed += 1;
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
			playbackTelemetry.fallbackTokenFailed += 1;
			clearCurrentAudio();
			reject(new Error('Playback failed'));
		};
		currentAudio.play().catch((e) => {
			o.onFallback?.('token_failed', `token ${tokenId}`);
			playbackTelemetry.fallbackTokenFailed += 1;
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
	const u = new SpeechSynthesisUtterance(text);
	u.rate = TTS_DEFAULT_RATE;
	u.volume = o.volume;
	const voice = getVoiceForTts(o.preferredVoiceName);
	if (voice) u.voice = voice;
	return new Promise((resolve) => {
		let finished = false;
		const finish = () => {
			if (finished) return;
			finished = true;
			clearInterval(keepAlive);
			clearTimeout(stallNudge);
			resolve();
		};

		// Chrome bug #1: speechSynthesis silently sets paused=true mid-utterance.
		// Poll every 250 ms and call resume() to keep playback going.
		const keepAlive = setInterval(() => {
			if (finished) { clearInterval(keepAlive); return; }
			if (window.speechSynthesis.paused) window.speechSynthesis.resume();
		}, 250);

		// Chrome bug #2: speaking=true, paused=false, but audio output silently stops.
		// The paused check above won't catch this variant. Use a one-shot timeout: if the
		// utterance hasn't ended naturally within 2 s, nudge the engine once via pause+resume.
		// Cleared immediately in finish() so it never fires during normal short phrases.
		const stallNudge = setTimeout(() => {
			if (!finished && window.speechSynthesis.speaking && !window.speechSynthesis.paused) {
				window.speechSynthesis.pause();
				window.speechSynthesis.resume();
			}
		}, 2000);

		u.onend = () => {
			// Chrome bug #3: onend fires early while audio tail is still draining.
			// Wait until speaking is actually false before continuing to the next segment.
			if (window.speechSynthesis.speaking) {
				let guard = 0;
				const poll = setInterval(() => {
					guard += 50;
					if (!window.speechSynthesis.speaking || guard > 2000) {
						clearInterval(poll);
						finish();
					}
				}, 50);
			} else {
				finish();
			}
		};
		u.onerror = () => {
			o.onCompleteFailure?.('browser_tts_failed', text);
			finish();
		};
		window.speechSynthesis.speak(u);
	});
}

export function playSegmentA(alias, pronounceAs, tokenId, options) {
	const opts = resolveOptions(options);
	const fullText = buildDisplaySegment1Text(
		alias,
		pronounceAs,
		opts.tokenSpokenPart,
		opts.defaultPrePhrase,
		opts.tokenBridgeTail,
		opts.segment2Enabled
	);
	const prefer = opts.preferGeneratedAudio !== false;

	if (!prefer) {
		return speakBrowser(fullText, opts);
	}

	if (tokenId != null) {
		return playTokenTts(tokenId, opts).catch(() => speakBrowser(fullText, opts));
	}
	return speakBrowser(fullText, opts);
}

function playSegmentAWithOptionalClosing(alias, pronounceAs, tokenId, opts) {
	return playSegmentA(alias, pronounceAs, tokenId, opts).then(async () => {
		if (opts.segment2Enabled !== false) {
			return;
		}
		const closing = (opts.closingWithoutSegment2 ?? '').trim();
		if (closing !== '') {
			// Avoid double-speaking when closing phrase equals the default ending
			// already included in segment 1 (e.g. "please proceed to your station").
			const seg1Text = buildDisplaySegment1Text(
				alias,
				pronounceAs,
				opts.tokenSpokenPart,
				opts.defaultPrePhrase,
				opts.tokenBridgeTail,
				opts.segment2Enabled
			);
			const norm = (s) =>
				(s ?? '')
					.toString()
					.trim()
					.toLowerCase()
					.replace(/[.,!?]+$/g, '');
			const c1 = norm(closing);
			const s1 = norm(seg1Text);
			if (!s1.endsWith(c1)) {
				await speakBrowser(closing, opts);
			}
		}
	});
}

/**
 * Queueable segment A (prephrase + token). Use on StationBoard so multiple rapid calls play in order instead of canceling.
 * When segment2Enabled is false (site setting), appends closing_without_segment2 after segment 1.
 */
export function playSegmentAQueued(alias, pronounceAs, tokenId, options) {
	const opts = resolveOptions(options);
	if (currentJobPromise != null) {
		jobQueue.push({ type: 'segmentA', alias, pronounceAs, tokenId, opts });
		return currentJobPromise;
	}
	currentJobPromise = playSegmentAWithOptionalClosing(alias, pronounceAs, tokenId, opts).then(() =>
		processNextJob()
	);
	return currentJobPromise;
}

export function playSegmentB(connectorPhrase, stationPhraseOrName, options) {
	const opts = resolveOptions(options);
	const secondText = connectorPhrase
		? connectorPhrase + ' ' + stationPhraseOrName
		: stationPhraseOrName;
	return speakBrowser(secondText, opts);
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
	const segment2On = opts.segment2Enabled !== false;
	const prefer = opts.preferGeneratedAudio !== false;
	const stitchedKey = createStitchedKey(params.tokenId, params.stationId, opts.ttsLanguage);
	const shouldTryStitched =
		segment2On &&
		prefer &&
		stitchedKey != null &&
		getAudioContext() != null;
	const gen = announcementGeneration;

	const playOnce = () => {
		if (shouldTryStitched) activeStitchedKey = stitchedKey;
		return (shouldTryStitched
			? ensureStitchedBufferForTarget({
					tokenId: params.tokenId,
					stationId: params.stationId,
					lang: opts.ttsLanguage,
				}).then((buffer) => {
					activeStitchedKey = null;
					if (!buffer) throw new Error('No stitched buffer');
					return playAudioBuffer(buffer, opts);
				})
			: Promise.reject(new Error('Stitched path disabled'))
		).catch(() => {
			activeStitchedKey = null;
			return playSegmentA(params.alias, params.pronounceAs, params.tokenId, opts).then(async () => {
				if (!segment2On) {
					const closing = (opts.closingWithoutSegment2 ?? '').trim();
					if (closing !== '') {
						const seg1Text = buildDisplaySegment1Text(
							params.alias,
							params.pronounceAs,
							opts.tokenSpokenPart,
							opts.defaultPrePhrase,
							opts.tokenBridgeTail,
							opts.segment2Enabled
						);
						const norm = (s) =>
							(s ?? '')
								.toString()
								.trim()
								.toLowerCase()
								.replace(/[.,!?]+$/g, '');
						if (!norm(seg1Text).endsWith(norm(closing))) {
							return speakBrowser(closing, opts);
						}
					}
					return Promise.resolve();
				}
				return playSegmentB(params.connectorPhrase, stationPhrase, opts);
			});
		});
	};

	const onePlay = repeatCount <= 1
		? playOnce()
		: playOnce().then(
				() =>
					new Promise((resolve) => {
						pendingRepeatTimeoutId = setTimeout(() => {
							pendingRepeatTimeoutId = null;
							if (announcementGeneration !== gen) { resolve(); return; }
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
		currentJobPromise = playSegmentAWithOptionalClosing(job.alias, job.pronounceAs, job.tokenId, job.opts).then(
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
	const pronounceAs =
		event?.pronounce_as === 'word' || event?.pronounce_as === 'custom' ? 'word' : 'letters';
	const stationName = options?.stationName ?? event?.station_name ?? 'your station';
	const lang = options?.ttsActiveLanguage ?? 'en';
	const byLang = event?.token_spoken_by_lang;
	const tokenSpokenPart =
		byLang && typeof byLang === 'object' && typeof byLang[lang] === 'string' && byLang[lang].trim() !== ''
			? byLang[lang].trim()
			: null;
	return {
		alias: event?.alias ?? '—',
		stationName,
		stationId: event?.station_id ?? event?.stationId ?? options?.stationId ?? null,
		pronounceAs,
		tokenId: event?.token_id ?? null,
		connectorPhrase: options?.connectorPhrase ?? null,
		stationTtsByName: options?.stationTtsByName ?? {},
		options: {
			muted: options?.muted ?? false,
			volume: options?.volume ?? 1,
			tokenSpokenPart: tokenSpokenPart || undefined,
			preferGeneratedAudio: options?.preferGeneratedAudio !== false,
			segment2Enabled: options?.segment2Enabled !== false,
			defaultPrePhrase: options?.defaultPrePhrase ?? '',
			tokenBridgeTail: options?.tokenBridgeTail ?? '',
			closingWithoutSegment2: options?.closingWithoutSegment2 ?? '',
			ttsLanguage: options?.ttsActiveLanguage ?? 'en',
			onFallback: options?.onFallback,
			onCompleteFailure: options?.onCompleteFailure,
		},
		repeatCount: Math.max(1, Math.floor(Number(options?.repeatCount) ?? 1)),
		repeatDelayMs: Math.max(0, Math.floor(Number(options?.repeatDelayMs) ?? 2000)),
	};
}
