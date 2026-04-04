import { speakSampleAsync } from "./speechUtils.js";

/**
 * Fetch builder-backed segment 1 text (GET /api/admin/tts/preview-text). Caller supplies CSRF for JSON routes.
 *
 * @param {{ lang: string; alias?: string; pronounce_as?: string; pre_phrase?: string; token_phrase?: string; token_bridge_tail?: string; getCsrfToken: () => string }} p
 */
export async function previewSegment1Text(p) {
	const q = new URLSearchParams({ segment: '1', lang: p.lang });
	if (p.alias) q.set('alias', p.alias);
	if (p.pronounce_as) q.set('pronounce_as', p.pronounce_as);
	if (p.pre_phrase != null) q.set('pre_phrase', p.pre_phrase);
	if (p.token_phrase) q.set('token_phrase', p.token_phrase);
	if (p.token_bridge_tail != null && p.token_bridge_tail !== '') q.set('token_bridge_tail', p.token_bridge_tail);
	const res = await fetch(`/api/admin/tts/preview-text?${q.toString()}`, {
		method: 'GET',
		headers: { Accept: 'application/json', 'X-CSRF-TOKEN': p.getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
		credentials: 'same-origin',
	});
	const data = await res.json().catch(() => ({}));
	return { ok: res.ok, status: res.status, text: typeof data?.text === 'string' ? data.text : null };
}

/**
 * Fetch builder-backed segment 2 text (connector + station phrase + name).
 *
 * @param {{ lang: string; connector_phrase?: string; station_name?: string; station_phrase?: string; getCsrfToken: () => string }} p
 */
export async function previewSegment2Text(p) {
	const q = new URLSearchParams({ segment: '2', lang: p.lang });
	if (p.connector_phrase != null) q.set('connector_phrase', p.connector_phrase);
	if (p.station_name) q.set('station_name', p.station_name);
	if (p.station_phrase) q.set('station_phrase', p.station_phrase);
	const res = await fetch(`/api/admin/tts/preview-text?${q.toString()}`, {
		method: 'GET',
		headers: { Accept: 'application/json', 'X-CSRF-TOKEN': p.getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
		credentials: 'same-origin',
	});
	const data = await res.json().catch(() => ({}));
	return { ok: res.ok, status: res.status, text: typeof data?.text === 'string' ? data.text : null };
}

/**
 * Fetch builder-backed token pronunciation body only (no pre_phrase / tail / station part).
 * GET /api/admin/tts/preview-token-spoken-part
 *
 * @param {{ lang: string; alias: string; pronounce_as: string; token_phrase?: string | null; getCsrfToken: () => string }} p
 */
export async function previewTokenSpokenPartText(p) {
	const q = new URLSearchParams({ lang: p.lang, alias: p.alias, pronounce_as: p.pronounce_as });
	if (p.token_phrase != null && String(p.token_phrase).trim() !== '') q.set('token_phrase', p.token_phrase);
	const res = await fetch(`/api/admin/tts/preview-token-spoken-part?${q.toString()}`, {
		method: 'GET',
		headers: { Accept: 'application/json', 'X-CSRF-TOKEN': p.getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
		credentials: 'same-origin',
	});
	const data = await res.json().catch(() => ({}));
	return { ok: res.ok, status: res.status, text: typeof data?.text === 'string' ? data.text : null };
}

/**
 * Shared admin UI: play a TTS preview via ElevenLabs (GET /api/public/tts) or browser speech fallback.
 *
 * @param {{ text: string; rate: number; voiceId?: string }} params
 * @returns {Promise<{ ok: boolean; code?: number; fallback?: boolean }>} code 419 = session expired (caller shows toast)
 */
export async function playAdminTtsPreview({ text, rate, voiceId = '' }) {
	const ttsParams = new URLSearchParams({ text, rate: String(rate) });
	if (voiceId) ttsParams.set('voice', voiceId);
	const ttsRes = await fetch(`/api/public/tts?${ttsParams.toString()}`, {
		method: 'GET',
		headers: { Accept: 'audio/mpeg', 'X-Requested-With': 'XMLHttpRequest' },
		credentials: 'same-origin',
	});
	if (ttsRes.status === 419) {
		return { ok: false, code: 419 };
	}
	if (!ttsRes.ok) {
		const canSpeak =
			typeof window !== 'undefined' &&
			typeof window.speechSynthesis !== 'undefined' &&
			typeof SpeechSynthesisUtterance !== 'undefined' &&
			typeof speakSampleAsync === 'function';

		if (canSpeak) {
			// voiceId is ElevenLabs voice id; browser voices are different. Use default browser voice.
			await speakSampleAsync(text, null, 1, rate);
			return { ok: true, fallback: true };
		}

		return { ok: false };
	}
	const blob = await ttsRes.blob();
	const objectUrl = URL.createObjectURL(blob);
	try {
		await new Promise((resolve, reject) => {
			const audio = new Audio(objectUrl);
			audio.onended = () => {
				URL.revokeObjectURL(objectUrl);
				resolve();
			};
			audio.onerror = () => {
				URL.revokeObjectURL(objectUrl);
				reject(new Error('Playback failed'));
			};
			audio.volume = 1;
			audio.play().catch(reject);
		});
	} catch (e) {
		return { ok: false };
	}
	return { ok: true };
}

/**
 * Admin “full call” preview: same order as informant displays — AnnouncementBuilder segment 1, then optional
 * closing (when station directions are off) or segment 2 (when on). Uses preview-text + sequential
 * playAdminTtsPreview (no pre-generated token blob).
 *
 * @param {object} p
 * @param {() => string} p.getCsrfToken
 * @param {string} p.lang
 * @param {number} p.rate
 * @param {string} [p.voiceId]
 * @param {boolean} p.segment2Enabled
 * @param {{ alias?: string; pronounce_as?: string; pre_phrase?: string; token_phrase?: string; token_bridge_tail?: string }} [p.segment1]
 * @param {string} [p.connectorPhrase] when segment2Enabled
 * @param {string} [p.stationName] when segment2Enabled
 * @param {string} [p.stationPhrase] when segment2Enabled (custom station wording)
 * @param {string} [p.closingWithoutSegment2] when !segment2Enabled
 * @returns {Promise<{ ok: boolean; code?: number; step?: string }>}
 */
export async function playAdminFullAnnouncementPreview(p) {
	const rate = p.rate;
	const voiceId = p.voiceId ?? '';
	const getCsrf = p.getCsrfToken;
	const s1 = p.segment1 ?? {};
	const s1Res = await previewSegment1Text({
		lang: p.lang,
		alias: s1.alias ?? 'A1',
		pronounce_as: s1.pronounce_as ?? 'letters',
		pre_phrase: s1.pre_phrase,
		token_phrase: s1.token_phrase,
		token_bridge_tail: s1.token_bridge_tail,
		getCsrfToken: getCsrf,
	});
	if (s1Res.status === 419) return { ok: false, code: 419 };
	if (!s1Res.ok || !s1Res.text) return { ok: false, step: 'segment1' };
	let r = await playAdminTtsPreview({ text: s1Res.text, rate, voiceId });
	if (r.code === 419) return { ok: false, code: 419 };
	if (!r.ok) return { ok: false, step: 'play1' };
	if (!p.segment2Enabled) {
		const closing = (p.closingWithoutSegment2 ?? '').trim();
		if (closing) {
			const s1 = s1Res.text.trim().toLowerCase().replace(/[.,!?]+$/g, '');
			const c1 = closing.toLowerCase().replace(/[.,!?]+$/g, '');
			// Avoid duplicate closing when segment 1 already ends with same phrase.
			if (!s1.endsWith(c1)) {
				r = await playAdminTtsPreview({ text: closing, rate, voiceId });
				if (r.code === 419) return { ok: false, code: 419 };
				if (!r.ok) return { ok: false, step: 'closing' };
			}
		}
		return { ok: true };
	}
	const s2Res = await previewSegment2Text({
		lang: p.lang,
		connector_phrase: p.connectorPhrase ?? '',
		station_name: p.stationName ?? 'Window 1',
		station_phrase: p.stationPhrase,
		getCsrfToken: getCsrf,
	});
	if (s2Res.status === 419) return { ok: false, code: 419 };
	if (!s2Res.ok || !s2Res.text) return { ok: false, step: 'segment2' };
	r = await playAdminTtsPreview({ text: s2Res.text, rate, voiceId });
	if (r.code === 419) return { ok: false, code: 419 };
	if (!r.ok) return { ok: false, step: 'play2' };
	return { ok: true };
}
