<script lang="ts">
	/**
	 * Token TTS settings form — embedded in Configuration tab.
	 * Migrated from Tokens/Index.svelte TTS modal.
	 */
	import { get } from "svelte/store";
	import { usePage } from "@inertiajs/svelte";
	import { onMount } from "svelte";
	import { toaster } from "../../../lib/toaster.js";
	import { ensureVoicesLoaded, speakSampleAsync } from "../../../lib/speechUtils.js";

	type TtsLangKey = "en" | "fil" | "ilo";
	interface LangConfig { voice_id: string; rate: number; pre_phrase: string; }

	const page = usePage();
	function getCsrfToken(): string {
		const p = get(page);
		const fromProps = (p?.props as { csrf_token?: string } | undefined)?.csrf_token;
		if (fromProps) return fromProps;
		const meta = typeof document !== "undefined" ? (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content : "";
		return meta ?? "";
	}

	const MSG_SESSION_EXPIRED = "Session expired. Please refresh and try again.";
	const MSG_NETWORK_ERROR = "Network error. Please try again.";

	let tokenTtsVoiceId = $state<string | null>(null);
	let tokenTtsRate = $state(0.84);
	let tokenTtsLanguages = $state<Record<TtsLangKey, LangConfig>>({
		en: { voice_id: "", rate: 0.84, pre_phrase: "" },
		fil: { voice_id: "", rate: 0.84, pre_phrase: "" },
		ilo: { voice_id: "", rate: 0.84, pre_phrase: "" },
	});
	let tokenTtsVoices = $state<{ id: string; name: string; lang?: string }[]>([]);
	let tokenTtsLoading = $state(true);
	let tokenTtsSaving = $state(false);
	let samplePlayingLang = $state<TtsLangKey | null>(null);

	onMount(() => { fetchTokenTtsSettings(); });

	async function fetchTokenTtsSettings() {
		tokenTtsLoading = true;
		try {
			const [settingsRes, voicesRes] = await Promise.all([
				fetch("/api/admin/token-tts-settings", {
					method: "GET",
					headers: { Accept: "application/json", "X-CSRF-TOKEN": getCsrfToken(), "X-Requested-With": "XMLHttpRequest" },
					credentials: "same-origin",
				}),
				fetch("/api/public/tts/voices", {
					method: "GET",
					headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
					credentials: "same-origin",
				}),
			]);
			if (settingsRes.status === 419 || voicesRes.status === 419) {
				toaster.error({ title: MSG_SESSION_EXPIRED });
				return;
			}
			const settingsData = await settingsRes.json().catch(() => ({}));
			const voicesData = await voicesRes.json().catch(() => ({}));
			if (settingsRes.ok && settingsData && "token_tts_settings" in settingsData) {
				const s = settingsData.token_tts_settings as { voice_id?: string | null; rate?: number; languages?: Record<TtsLangKey, { voice_id?: string | null; rate?: number; pre_phrase?: string | null }> };
				tokenTtsVoiceId = (s.voice_id ?? null) as string | null;
				tokenTtsRate = typeof s.rate === "number" ? s.rate : 0.84;
				const langs = (s.languages ?? {}) as Record<TtsLangKey, { voice_id?: string | null; rate?: number; pre_phrase?: string | null }>;
				tokenTtsLanguages = {
					en: { voice_id: (langs.en?.voice_id as string | undefined) ?? "", rate: typeof langs.en?.rate === "number" ? langs.en.rate : 0.84, pre_phrase: (langs.en?.pre_phrase as string | undefined) ?? "" },
					fil: { voice_id: (langs.fil?.voice_id as string | undefined) ?? "", rate: typeof langs.fil?.rate === "number" ? langs.fil.rate : 0.84, pre_phrase: (langs.fil?.pre_phrase as string | undefined) ?? "" },
					ilo: { voice_id: (langs.ilo?.voice_id as string | undefined) ?? "", rate: typeof langs.ilo?.rate === "number" ? langs.ilo.rate : 0.84, pre_phrase: (langs.ilo?.pre_phrase as string | undefined) ?? "" },
				};
			}
			if (voicesRes.ok && voicesData && "voices" in voicesData && Array.isArray(voicesData.voices)) {
				tokenTtsVoices = voicesData.voices as { id: string; name: string; lang?: string }[];
			} else {
				tokenTtsVoices = [];
			}
		} catch (e) {
			if (e instanceof TypeError && (e as Error).message === "Failed to fetch") toaster.error({ title: MSG_NETWORK_ERROR });
		} finally {
			tokenTtsLoading = false;
		}
	}

	async function playTtsSampleForLang(lang: TtsLangKey, config: LangConfig) {
		if (samplePlayingLang) return;
		samplePlayingLang = lang;
		try {
			const phraseParams = new URLSearchParams({ lang, pre_phrase: config.pre_phrase ?? "", alias: "A1", pronounce_as: "letters" });
			const phraseRes = await fetch(`/api/admin/tts/sample-phrase?${phraseParams.toString()}`, {
				method: "GET",
				headers: { Accept: "application/json", "X-CSRF-TOKEN": getCsrfToken(), "X-Requested-With": "XMLHttpRequest" },
				credentials: "same-origin",
			});
			if (phraseRes.status === 419) { toaster.error({ title: MSG_SESSION_EXPIRED }); return; }
			const phraseData = await phraseRes.json().catch(() => ({}));
			if (!phraseRes.ok || typeof phraseData?.text !== "string") { toaster.error({ title: "Could not get sample phrase." }); return; }
			const voiceId = (config.voice_id?.trim() || tokenTtsVoiceId);
			const ttsParams = new URLSearchParams({ text: phraseData.text, rate: String(config.rate) });
			if (voiceId) ttsParams.set("voice", voiceId);
			const ttsRes = await fetch(`/api/public/tts?${ttsParams.toString()}`, {
				method: "GET",
				headers: { Accept: "audio/mpeg", "X-Requested-With": "XMLHttpRequest" },
				credentials: "same-origin",
			});
			if (ttsRes.status === 419) { toaster.error({ title: MSG_SESSION_EXPIRED }); return; }
			if (!ttsRes.ok) {
				ensureVoicesLoaded();
				await speakSampleAsync(phraseData.text, null, 1, config.rate);
				return;
			}
			const blob = await ttsRes.blob();
			const objectUrl = URL.createObjectURL(blob);
			await new Promise<void>((resolve, reject) => {
				const audio = new Audio(objectUrl);
				audio.onended = () => { URL.revokeObjectURL(objectUrl); resolve(); };
				audio.onerror = () => { URL.revokeObjectURL(objectUrl); reject(new Error("Playback failed")); };
				audio.volume = 1;
				audio.play().catch(reject);
			});
		} catch (e) {
			toaster.error({ title: e instanceof TypeError && (e as Error).message === "Failed to fetch" ? MSG_NETWORK_ERROR : "Failed to play TTS sample." });
		} finally {
			samplePlayingLang = null;
		}
	}

	async function saveTtsSettings() {
		tokenTtsSaving = true;
		try {
			const res = await fetch("/api/admin/token-tts-settings", {
				method: "PUT",
				headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": getCsrfToken(), "X-Requested-With": "XMLHttpRequest" },
				credentials: "same-origin",
				body: JSON.stringify({
					voice_id: tokenTtsVoiceId || null,
					rate: tokenTtsRate,
					languages: {
						en: { voice_id: tokenTtsLanguages.en.voice_id || null, rate: tokenTtsLanguages.en.rate, pre_phrase: tokenTtsLanguages.en.pre_phrase.trim() || null },
						fil: { voice_id: tokenTtsLanguages.fil.voice_id || null, rate: tokenTtsLanguages.fil.rate, pre_phrase: tokenTtsLanguages.fil.pre_phrase.trim() || null },
						ilo: { voice_id: tokenTtsLanguages.ilo.voice_id || null, rate: tokenTtsLanguages.ilo.rate, pre_phrase: tokenTtsLanguages.ilo.pre_phrase.trim() || null },
					},
				}),
			});
			if (res.status === 419) { toaster.error({ title: MSG_SESSION_EXPIRED }); return; }
			const data = await res.json().catch(() => ({}));
			if (!res.ok) {
				toaster.error({ title: (data && "message" in data && (data as { message?: string }).message) || "Failed to save TTS settings." });
				return;
			}
			toaster.success({ title: "TTS settings saved." });
		} catch (e) {
			toaster.error({ title: e instanceof TypeError && (e as Error).message === "Failed to fetch" ? MSG_NETWORK_ERROR : "Failed to save TTS settings." });
		} finally {
			tokenTtsSaving = false;
		}
	}
</script>

<p class="text-sm text-surface-600 mb-4">
	Server voice and speed for pre-generated token audio. Displays fall back to browser voices if server TTS is unavailable.
</p>

{#if tokenTtsLoading}
	<p class="text-surface-500">Loading…</p>
{:else}
	<div class="rounded-container bg-surface-50 border border-surface-200 shadow-sm p-6 flex flex-col gap-4">
		<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
			<div class="form-control">
				<label class="label" for="cfg-tts-voice"><span class="label-text text-sm font-medium">Server voice</span></label>
				<select id="cfg-tts-voice" class="select select-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm max-w-xs" bind:value={tokenTtsVoiceId}>
					<option value={""}>Default (from TTS config)</option>
					{#each tokenTtsVoices as voice}
						<option value={voice.id}>{voice.name}{voice.lang ? ` (${voice.lang})` : ""}</option>
					{/each}
				</select>
			</div>
			<div class="form-control">
				<label class="label" for="cfg-tts-rate"><span class="label-text text-sm font-medium">TTS speed</span></label>
				<div class="flex items-center gap-3">
					<input id="cfg-tts-rate" type="range" min="0.5" max="2" step="0.05" class="range range-sm max-w-xs" bind:value={tokenTtsRate} />
					<span class="text-xs text-surface-600 w-14">{tokenTtsRate.toFixed(2)}x</span>
				</div>
			</div>
		</div>

		<p class="text-sm font-medium text-surface-800 mt-2">Default per language (English, Filipino, Ilocano)</p>
		<p class="text-xs text-surface-600 mb-2">Voice, speed, and pre-phrase (pronunciation) per language.</p>

		<div class="space-y-3">
			{#each ["en", "fil", "ilo"] as lang}
				{@const key = lang as TtsLangKey}
				{@const cfg = tokenTtsLanguages[key]}
				<div class="p-3 rounded-container border border-surface-200 bg-surface-50">
					<span class="text-xs font-semibold uppercase tracking-wide text-surface-500">{key === "en" ? "English" : key === "fil" ? "Filipino" : "Ilocano"}</span>
					<div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
						<div class="form-control">
							<label class="label" for="cfg-tts-{key}-voice"><span class="label-text text-xs font-medium">Voice</span></label>
							<select id="cfg-tts-{key}-voice" class="select select-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm" bind:value={cfg.voice_id}>
								<option value={""}>Use server voice above</option>
								{#each tokenTtsVoices as voice}
									<option value={voice.id}>{voice.name}{voice.lang ? ` (${voice.lang})` : ""}</option>
								{/each}
							</select>
						</div>
						<div class="form-control">
							<label class="label" for="cfg-tts-{key}-rate"><span class="label-text text-xs font-medium">Speed</span></label>
							<div class="flex items-center gap-3">
								<input id="cfg-tts-{key}-rate" type="range" min="0.5" max="2" step="0.05" class="range range-xs max-w-xs" bind:value={cfg.rate} />
								<span class="text-xs text-surface-600 w-14">{Number(cfg.rate).toFixed(2)}x</span>
							</div>
						</div>
					</div>
					<div class="form-control mt-2">
						<label class="label" for="cfg-tts-{key}-pre"><span class="label-text text-xs font-medium">Pre-phrase (pronunciation)</span></label>
						<input id="cfg-tts-{key}-pre" type="text" class="input input-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm" placeholder='e.g. "Calling"' bind:value={cfg.pre_phrase} />
					</div>
					<div class="mt-2">
						<button type="button" class="btn btn-sm preset-tonal text-surface-700 border border-surface-200 bg-surface-50 hover:bg-surface-100 disabled:opacity-50" onclick={() => playTtsSampleForLang(key, cfg)} disabled={samplePlayingLang !== null}>
							{samplePlayingLang === key ? "Playing…" : "Play sample"}
						</button>
					</div>
				</div>
			{/each}
		</div>

		<div class="flex flex-wrap items-center gap-2 pt-2">
			<button type="button" class="btn btn-sm preset-filled-primary-500 shadow-sm disabled:opacity-50" onclick={saveTtsSettings} disabled={tokenTtsSaving}>
				{tokenTtsSaving ? "Saving…" : "Save TTS settings"}
			</button>
		</div>
	</div>
{/if}
