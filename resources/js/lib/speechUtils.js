/**
 * Shared TTS helpers for display call announcements.
 * Ensures a female voice is used when available; preloads voices so they're ready on first call.
 * When offline, server-provided voice (e.g. "Microsoft Sonia Online") may not be in getVoices();
 * we persist a device-local fallback voice name so TTS still works with no connection.
 */

const TTS_FALLBACK_STORAGE_KEY = 'flexiqueue_tts_fallback_voice';

let cachedFemaleVoice = null;

function getStoredFallbackVoiceName() {
	try {
		if (typeof window !== 'undefined' && window.localStorage) {
			const name = window.localStorage.getItem(TTS_FALLBACK_STORAGE_KEY);
			return name && String(name).trim() ? String(name).trim() : null;
		}
	} catch (_) {}
	return null;
}

function setStoredFallbackVoiceName(name) {
	if (!name || typeof name !== 'string') return;
	try {
		if (typeof window !== 'undefined' && window.localStorage) {
			window.localStorage.setItem(TTS_FALLBACK_STORAGE_KEY, name);
		}
	} catch (_) {}
}

/** Default speech rate: 0.84 = 5% faster than 0.8 (not 100% / 1.0). */
export const TTS_DEFAULT_RATE = 0.84;

/** Default TTS voice name when none is set: Microsoft Sonia Online (included in defaults). */
export const TTS_DEFAULT_VOICE_NAME = 'Microsoft Sonia Online';

/** Known female voice names across platforms (Windows, macOS, Chrome, Linux, Android). */
const FEMALE_VOICE_PATTERN =
	/female|samantha|karen|victoria|moira|fiona|tessa|amelie|zira|aria|sonia|google\s*.*\s*female|english\s*.*\s*female|espeak.*female/i;

function isFemaleVoice(voice) {
	if (!voice?.name) return false;
	const name = voice.name.toLowerCase();
	return FEMALE_VOICE_PATTERN.test(voice.name) || name.includes('female') || name.includes('sonia');
}

/**
 * Prefer Microsoft Sonia Online, then other female voices, then first available.
 * @param {SpeechSynthesisVoice[]} voices
 * @returns {SpeechSynthesisVoice | null}
 */
function selectDefaultVoice(voices) {
	if (!Array.isArray(voices) || voices.length === 0) return null;
	// 1) Prefer Microsoft Sonia Online (default)
	const sonia = voices.find((v) => v.name === TTS_DEFAULT_VOICE_NAME || (v.name && v.name.toLowerCase().includes('sonia')));
	if (sonia) return sonia;
	// 2) Other female voices, prefer local
	const female = voices.filter(isFemaleVoice);
	const localFemale = female.find((v) => v.localService === true);
	if (localFemale) return localFemale;
	if (female.length > 0) return female[0];
	return voices[0] ?? null;
}

/**
 * Return cached female voice or compute from current getVoices() and cache.
 * Call ensureVoicesLoaded() from display pages on mount so voices are ready before first call.
 * @returns {SpeechSynthesisVoice | null}
 */
export function getFemaleVoice() {
	if (typeof window === 'undefined' || !window.speechSynthesis) return null;
	const voices = window.speechSynthesis.getVoices();
	if (voices.length === 0) return cachedFemaleVoice;
	const chosen = selectDefaultVoice(voices);
	if (chosen) cachedFemaleVoice = chosen;
	return chosen ?? cachedFemaleVoice ?? voices[0] ?? null;
}

/**
 * Get voice by exact name (from settings). Returns null if not found.
 * @param {string} name - SpeechSynthesisVoice.name from browser
 * @returns {SpeechSynthesisVoice | null}
 */
export function getVoiceByName(name) {
	if (!name || typeof window === 'undefined' || !window.speechSynthesis) return null;
	const voices = window.speechSynthesis.getVoices();
	return voices.find((v) => v.name === name) ?? null;
}

/**
 * Resolve voice for TTS: preferred (server) → stored fallback (device) → default female.
 * Persists the chosen voice so when offline we can still use the last working voice.
 * @param {string | null} preferredVoiceName - display_tts_voice from server
 * @returns {SpeechSynthesisVoice | null}
 */
export function getVoiceForTts(preferredVoiceName) {
	if (typeof window === 'undefined' || !window.speechSynthesis) return null;
	const voice =
		(preferredVoiceName && getVoiceByName(preferredVoiceName)) ||
		getVoiceByName(getStoredFallbackVoiceName()) ||
		getFemaleVoice();
	if (voice) setStoredFallbackVoiceName(voice.name);
	return voice ?? null;
}

/**
 * Trigger voice loading and cache female voice when ready.
 * Chrome returns empty getVoices() until voiceschanged; call this on display page mount.
 * @param {(voices: SpeechSynthesisVoice[]) => void} [onReady] - called when voices are available (e.g. to populate a dropdown)
 */
export function ensureVoicesLoaded(onReady) {
	if (typeof window === 'undefined' || !window.speechSynthesis) return;
	const syn = window.speechSynthesis;
	syn.getVoices(); // trigger Chrome to start loading
	const onVoicesChanged = () => {
		const voices = syn.getVoices();
		if (voices.length > 0) {
			cachedFemaleVoice = selectDefaultVoice(voices);
			if (cachedFemaleVoice?.name) setStoredFallbackVoiceName(cachedFemaleVoice.name);
			syn.removeEventListener('voiceschanged', onVoicesChanged);
			if (typeof onReady === 'function') onReady(voices);
		}
	};
	syn.addEventListener('voiceschanged', onVoicesChanged);
	// In case voices were already loaded (e.g. Firefox)
	if (syn.getVoices().length > 0) onVoicesChanged();
}

/**
 * Speak a sample phrase with the given voice (or default female) and default rate. For settings preview.
 * @param {string} text - Phrase to speak (e.g. "Calling A 3, please proceed to window 1").
 * @param {string | null} voiceName - SpeechSynthesisVoice.name or null for default.
 * @param {number} [volume=1] - Volume 0–1.
 */
export function speakSample(text, voiceName, volume = 1) {
	if (typeof window === 'undefined' || !window.speechSynthesis || !text) return;
	// Prevent overlapping utterances (reduces stutter in admin previews).
	try {
		window.speechSynthesis.cancel();
	} catch (_) {}
	const u = new SpeechSynthesisUtterance(String(text));
	u.rate = TTS_DEFAULT_RATE;
	u.volume = Math.max(0, Math.min(1, Number(volume)));
	const voice = getVoiceForTts(voiceName || null);
	if (voice) u.voice = voice;
	window.speechSynthesis.speak(u);
}

/**
 * Speak text with browser TTS and return a Promise that resolves when done (or on error).
 * Same voice/rate behaviour as displays so admin preview matches token playback when API is unavailable.
 * @param {string} text - Phrase to speak.
 * @param {string | null} voiceName - SpeechSynthesisVoice.name or null for default.
 * @param {number} [volume=1] - Volume 0–1.
 * @param {number} [rate] - Speech rate (e.g. 0.84). Uses TTS_DEFAULT_RATE if omitted.
 * @returns {Promise<void>}
 */
export function speakSampleAsync(text, voiceName, volume = 1, rate = TTS_DEFAULT_RATE) {
	if (typeof window === 'undefined' || !window.speechSynthesis || !text) return Promise.resolve();
	// Prevent overlapping utterances (reduces stutter in admin previews).
	try {
		window.speechSynthesis.cancel();
	} catch (_) {}
	const u = new SpeechSynthesisUtterance(String(text));
	u.rate = Math.max(0.1, Math.min(10, Number(rate) || TTS_DEFAULT_RATE));
	u.volume = Math.max(0, Math.min(1, Number(volume)));
	const voice = getVoiceForTts(voiceName || null);
	if (voice) u.voice = voice;
	return new Promise((resolve) => {
		u.onend = () => resolve();
		u.onerror = () => resolve();
		window.speechSynthesis.speak(u);
	});
}
