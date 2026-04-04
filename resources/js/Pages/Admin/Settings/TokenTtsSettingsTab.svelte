<script lang="ts">
	/**
	 * Token TTS settings form — embedded in Configuration tab.
	 * Token bridge tail (after the spoken token in segment 1) is edited here only when station directions are off.
	 * Default pre-phrase is edited on the Tokens page.
	 */
	import TtsBudgetCard from "../../../Components/TtsBudgetCard.svelte";
	import { get } from "svelte/store";
	import { usePage } from "@inertiajs/svelte";
	import { onMount } from "svelte";
	import { toaster } from "../../../lib/toaster.js";
	import { playAdminFullAnnouncementPreview, playAdminTtsPreview, previewSegment1Text, previewSegment2Text } from "../../../lib/ttsPreview.js";

	type TtsLangKey = "en" | "fil" | "ilo";
	interface LangConfig {
		voice_id: string;
		rate: number;
		token_phrase: string;
		token_bridge_tail: string;
		closing_without_segment2: string;
	}

	const pageStore = usePage();
	const budgetEditHref = $derived.by(() => {
		const id = ($pageStore.props as { auth?: { user?: { site_id?: number | null } } })?.auth?.user?.site_id;
		return id != null ? `/admin/sites/${id}` : null;
	});
	const page = pageStore;
	function getCsrfToken(): string {
		const p = get(page);
		const fromProps = (p?.props as { csrf_token?: string } | undefined)?.csrf_token;
		if (fromProps) return fromProps;
		const meta = typeof document !== "undefined" ? (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content : "";
		return meta ?? "";
	}

	const MSG_SESSION_EXPIRED = "Session expired. Please refresh and try again.";
	const MSG_NETWORK_ERROR = "Network error. Please try again.";

	function emptyLang(): LangConfig {
		return { voice_id: "", rate: 0.84, token_phrase: "", token_bridge_tail: "", closing_without_segment2: "" };
	}

	let tokenTtsVoiceId = $state<string | null>(null);
	let tokenTtsRate = $state(0.84);
	let tokenTtsLanguages = $state<Record<TtsLangKey, LangConfig>>({
		en: emptyLang(),
		fil: emptyLang(),
		ilo: emptyLang(),
	});
	let playbackPreferGenerated = $state(true);
	let playbackAllowCustom = $state(true);
	let playbackSegment2 = $state(true);
	/** Sample connecting phrase for “Play station directions” preview (real phrases are per program). */
	let segment2SampleConnector = $state("please go to");
	let tokenTtsVoices = $state<{ id: string; name: string; lang?: string }[]>([]);
	let tokenTtsLoading = $state(true);
	let tokenTtsSaving = $state(false);
	/** e.g. `sample:en` (segment-only) or `full:en` (token call + directions or closing) */
	let audioPreviewKey = $state<string | null>(null);

	function voiceForLang(config: LangConfig): string {
		return (config.voice_id?.trim() || tokenTtsVoiceId) ?? "";
	}

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
				const s = settingsData.token_tts_settings as {
					voice_id?: string | null;
					rate?: number;
					playback?: { prefer_generated_audio?: boolean; allow_custom_pronunciation?: boolean; segment_2_enabled?: boolean };
					languages?: Record<TtsLangKey, Record<string, unknown>>;
				};
				tokenTtsVoiceId = (s.voice_id ?? null) as string | null;
				tokenTtsRate = typeof s.rate === "number" ? s.rate : 0.84;
				const pb = s.playback;
				if (pb) {
					playbackPreferGenerated = pb.prefer_generated_audio !== false;
					playbackAllowCustom = pb.allow_custom_pronunciation !== false;
					playbackSegment2 = pb.segment_2_enabled !== false;
				}
				const langs = (s.languages ?? {}) as Record<TtsLangKey, Record<string, unknown>>;
				const row = (k: TtsLangKey) => {
					const L = langs[k] ?? {};
					return {
						voice_id: (L.voice_id as string | undefined) ?? "",
						rate: typeof L.rate === "number" ? L.rate : 0.84,
						token_phrase: (L.token_phrase as string | undefined) ?? "",
						token_bridge_tail: (L.token_bridge_tail as string | undefined) ?? "",
						closing_without_segment2: (L.closing_without_segment2 as string | undefined) ?? "",
					};
				};
				tokenTtsLanguages = { en: row("en"), fil: row("fil"), ilo: row("ilo") };
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

	/** Station directions only (segment 2), for “Play sample” when directions are on. */
	async function playSegment2SampleOnly(lang: TtsLangKey, config: LangConfig) {
		const id = `sample:${lang}`;
		if (audioPreviewKey) return;
		audioPreviewKey = id;
		try {
			const pr = await previewSegment2Text({
				lang,
				connector_phrase: segment2SampleConnector.trim(),
				station_name: "Window 1",
				getCsrfToken,
			});
			if (pr.status === 419) { toaster.error({ title: MSG_SESSION_EXPIRED }); return; }
			if (!pr.ok || !pr.text) { toaster.error({ title: "Could not get station directions text." }); return; }
			const preview = await playAdminTtsPreview({ text: pr.text, rate: config.rate, voiceId: voiceForLang(config) });
			if (preview.code === 419) { toaster.error({ title: MSG_SESSION_EXPIRED }); return; }
			if (!preview.ok) { toaster.error({ title: "Failed to play TTS sample." }); return; }
		} catch (e) {
			toaster.error({ title: e instanceof TypeError && (e as Error).message === "Failed to fetch" ? MSG_NETWORK_ERROR : "Failed to play TTS sample." });
		} finally {
			audioPreviewKey = null;
		}
	}

	/** Token call only (segment 1), for “Play sample” when directions are off — uses bridge tail from the form. */
	async function playSegment1SampleOnly(lang: TtsLangKey, config: LangConfig) {
		const id = `sample:${lang}`;
		if (audioPreviewKey) return;
		audioPreviewKey = id;
		try {
			const tail = (config.token_bridge_tail ?? "").trim();
			const pr = await previewSegment1Text({
				lang,
				alias: "A1",
				pronounce_as: "letters",
				token_bridge_tail: tail || undefined,
				getCsrfToken,
			});
			if (pr.status === 419) { toaster.error({ title: MSG_SESSION_EXPIRED }); return; }
			if (!pr.ok || !pr.text) { toaster.error({ title: "Could not get token call text." }); return; }
			const preview = await playAdminTtsPreview({ text: pr.text, rate: config.rate, voiceId: voiceForLang(config) });
			if (preview.code === 419) { toaster.error({ title: MSG_SESSION_EXPIRED }); return; }
			if (!preview.ok) { toaster.error({ title: "Failed to play TTS sample." }); return; }
		} catch (e) {
			toaster.error({ title: e instanceof TypeError && (e as Error).message === "Failed to fetch" ? MSG_NETWORK_ERROR : "Failed to play TTS sample." });
		} finally {
			audioPreviewKey = null;
		}
	}

	/** Full call: segment 1 (site defaults) + segment 2 or optional closing — matches display order. */
	async function playFullAnnouncementSample(lang: TtsLangKey, config: LangConfig) {
		const id = `full:${lang}`;
		if (audioPreviewKey) return;
		audioPreviewKey = id;
		try {
			const seg1: { alias: string; pronounce_as: string; token_bridge_tail?: string } = {
				alias: "A1",
				pronounce_as: "letters",
			};
			if (!playbackSegment2) {
				const t = (config.token_bridge_tail ?? "").trim();
				if (t) seg1.token_bridge_tail = t;
			}
			const res = await playAdminFullAnnouncementPreview({
				getCsrfToken,
				lang,
				rate: config.rate,
				voiceId: voiceForLang(config),
				segment2Enabled: playbackSegment2,
				segment1: seg1,
				connectorPhrase: segment2SampleConnector.trim(),
				stationName: "Window 1",
				closingWithoutSegment2: playbackSegment2 ? undefined : config.closing_without_segment2,
			});
			if (res.code === 419) { toaster.error({ title: MSG_SESSION_EXPIRED }); return; }
			if (!res.ok) {
				const msg =
					res.step === "segment1"
						? "Could not build token call."
						: res.step === "segment2"
							? "Could not build station directions."
							: "Failed to play full preview.";
				toaster.error({ title: msg });
			}
		} catch (e) {
			toaster.error({ title: e instanceof TypeError && (e as Error).message === "Failed to fetch" ? MSG_NETWORK_ERROR : "Failed to play full preview." });
		} finally {
			audioPreviewKey = null;
		}
	}

	async function saveTtsSettings() {
		tokenTtsSaving = true;
		try {
			const L = tokenTtsLanguages;
			const res = await fetch("/api/admin/token-tts-settings", {
				method: "PUT",
				headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": getCsrfToken(), "X-Requested-With": "XMLHttpRequest" },
				credentials: "same-origin",
				body: JSON.stringify({
					voice_id: tokenTtsVoiceId || null,
					rate: tokenTtsRate,
					playback: {
						prefer_generated_audio: playbackPreferGenerated,
						allow_custom_pronunciation: playbackAllowCustom,
						segment_2_enabled: playbackSegment2,
					},
					languages: {
						en: {
							voice_id: L.en.voice_id || null,
							rate: L.en.rate,
							token_phrase: L.en.token_phrase.trim() || null,
							token_bridge_tail: L.en.token_bridge_tail.trim() || null,
							closing_without_segment2: L.en.closing_without_segment2.trim() || null,
						},
						fil: {
							voice_id: L.fil.voice_id || null,
							rate: L.fil.rate,
							token_phrase: L.fil.token_phrase.trim() || null,
							token_bridge_tail: L.fil.token_bridge_tail.trim() || null,
							closing_without_segment2: L.fil.closing_without_segment2.trim() || null,
						},
						ilo: {
							voice_id: L.ilo.voice_id || null,
							rate: L.ilo.rate,
							token_phrase: L.ilo.token_phrase.trim() || null,
							token_bridge_tail: L.ilo.token_bridge_tail.trim() || null,
							closing_without_segment2: L.ilo.closing_without_segment2.trim() || null,
						},
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
	<strong>Voice, speed, and playback.</strong> Site-wide <strong>pre-phrase</strong> (spoken before the token ID) is set on the <strong>Tokens</strong> page under <strong>Token prephrase (site-wide)</strong>. When <strong>station directions</strong> are off, optional wording after the spoken token (same utterance as the call) is set below per language. Program-wide <strong>connecting phrases</strong> and per-station wording are under <strong>Program → Stations</strong> when station directions are enabled. Toggles below are stored in the database (not <code class="text-xs">.env</code>).
</p>
<p class="text-xs text-surface-600 mb-4 -mt-2">
	<strong>Short token call:</strong> On the Tokens page, set pre-phrase to <strong>Calling</strong> and leave <strong>token bridge tail</strong> empty (here, when directions are off) for “Calling” plus the spoken token only—without the long default “please proceed to your station” segment.
</p>

{#if tokenTtsLoading}
	<p class="text-surface-500">Loading…</p>
{:else}
	<div class="flex flex-col gap-4">
		<TtsBudgetCard editHref={budgetEditHref} />
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

		<div class="rounded-container border border-surface-200 bg-surface-100/40 p-4 space-y-3">
			<p class="text-sm font-medium text-surface-950">Playback (informant displays)</p>
			<div class="form-control">
				<label
					for="cfg-playback-prefer-generated"
					class="label cursor-pointer justify-start gap-3 w-full sm:w-fit hover:bg-surface-100/90 p-2 -ml-2 rounded-lg transition-colors items-start sm:items-center min-h-0 py-2"
				>
					<div class="relative inline-block w-11 h-5 shrink-0 mt-0.5 sm:mt-0">
						<input
							id="cfg-playback-prefer-generated"
							type="checkbox"
							class="peer appearance-none w-11 h-5 bg-surface-200 rounded-full checked:bg-surface-800 cursor-pointer transition-colors duration-300"
							bind:checked={playbackPreferGenerated}
						/>
						<span
							class="absolute top-0 left-0 w-5 h-5 bg-surface-950 rounded-full border border-surface-300 shadow-sm transition-transform duration-300 peer-checked:translate-x-6 peer-checked:border-surface-800 pointer-events-none"
							aria-hidden="true"
						></span>
					</div>
					<span class="label-text text-sm text-surface-950 font-medium text-left leading-snug">
						Prefer generated token audio when available
					</span>
				</label>
			</div>
			<div class="form-control">
				<label
					for="cfg-playback-segment-2"
					class="label cursor-pointer justify-start gap-3 w-full sm:w-fit hover:bg-surface-100/90 p-2 -ml-2 rounded-lg transition-colors items-start sm:items-center min-h-0 py-2"
				>
					<div class="relative inline-block w-11 h-5 shrink-0 mt-0.5 sm:mt-0">
						<input
							id="cfg-playback-segment-2"
							type="checkbox"
							class="peer appearance-none w-11 h-5 bg-surface-200 rounded-full checked:bg-surface-800 cursor-pointer transition-colors duration-300"
							bind:checked={playbackSegment2}
						/>
						<span
							class="absolute top-0 left-0 w-5 h-5 bg-surface-950 rounded-full border border-surface-300 shadow-sm transition-transform duration-300 peer-checked:translate-x-6 peer-checked:border-surface-800 pointer-events-none"
							aria-hidden="true"
						></span>
					</div>
					<span class="flex flex-col gap-0.5 text-left">
						<span class="label-text text-sm text-surface-950 font-medium leading-snug">Announce station directions after the token call</span>
						<span class="text-xs text-surface-600 max-w-prose">Connecting phrase plus window or station name (or custom station wording when allowed).</span>
					</span>
				</label>
			</div>
			<div class="form-control">
				<label
					for="cfg-playback-allow-custom"
					class="label cursor-pointer justify-start gap-3 w-full sm:w-fit hover:bg-surface-100/90 p-2 -ml-2 rounded-lg transition-colors items-start sm:items-center min-h-0 py-2"
				>
					<div class="relative inline-block w-11 h-5 shrink-0 mt-0.5 sm:mt-0">
						<input
							id="cfg-playback-allow-custom"
							type="checkbox"
							class="peer appearance-none w-11 h-5 bg-surface-200 rounded-full checked:bg-surface-800 cursor-pointer transition-colors duration-300"
							bind:checked={playbackAllowCustom}
						/>
						<span
							class="absolute top-0 left-0 w-5 h-5 bg-surface-950 rounded-full border border-surface-300 shadow-sm transition-transform duration-300 peer-checked:translate-x-6 peer-checked:border-surface-800 pointer-events-none"
							aria-hidden="true"
						></span>
					</div>
					<span class="label-text text-sm text-surface-950 font-medium text-left leading-snug">
						Allow custom token and station wording
					</span>
				</label>
			</div>
			<p class="text-xs text-surface-700">
				When “Prefer generated…” is off, displays use browser speech and do not fetch public token audio. When station directions are off, you can add optional text after the token ID in the call (see per-language fields below).
			</p>
			{#if playbackSegment2}
				<div class="form-control max-w-md">
					<label class="label" for="cfg-seg2-connector"><span class="label-text text-xs font-medium">Sample connecting phrase (preview only)</span></label>
					<input id="cfg-seg2-connector" type="text" class="input input-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm" bind:value={segment2SampleConnector} />
					<span class="label-text-alt text-surface-500">Programs use their own connecting phrases under Program → Stations. This is only for the preview below (placeholder station: Window 1).</span>
				</div>
			{/if}
		</div>

		<p class="text-sm font-medium text-surface-950 mt-2">Per language (English, Filipino, Ilocano)</p>
		<p class="text-xs text-surface-600 mb-2">
			Voice override and speed for previews.
			{#if playbackSegment2}
				<strong>Play sample</strong> = station directions only. <strong>Play full</strong> = token call (site defaults) then directions, using the sample connector above.
			{:else}
				Optional <strong>token bridge tail</strong> is edited here.
				<strong>Play sample</strong> = token call only. <strong>Play full</strong> adds the optional stored closing line after segment 1 (if any).
			{/if}
		</p>

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
					{#if !playbackSegment2}
					<div class="form-control mt-2">
						<label class="label" for="cfg-tts-{key}-bridge"><span class="label-text text-xs font-medium">Token bridge tail</span></label>
						<input
							id="cfg-tts-{key}-bridge"
							type="text"
							class="input input-sm rounded-container border border-surface-200 bg-surface-50 shadow-sm"
							bind:value={cfg.token_bridge_tail}
							disabled={!playbackAllowCustom}
						/>
						<span class="label-text-alt text-surface-500">Only when station directions are off. Spoken in the same turn as pre-phrase + token (see Tokens → pre-phrase).</span>
					</div>
					{/if}
					<div class="mt-2 flex flex-wrap gap-2 items-center">
						{#if playbackSegment2}
							<button type="button" class="btn btn-sm preset-tonal text-surface-700 border border-surface-200 bg-surface-50 hover:bg-surface-100 disabled:opacity-50" onclick={() => playSegment2SampleOnly(key, cfg)} disabled={audioPreviewKey !== null}>
								{audioPreviewKey === `sample:${key}` ? "Playing…" : "Play sample"}
							</button>
						{:else}
							<button type="button" class="btn btn-sm preset-tonal text-surface-700 border border-surface-200 bg-surface-50 hover:bg-surface-100 disabled:opacity-50" onclick={() => playSegment1SampleOnly(key, cfg)} disabled={audioPreviewKey !== null}>
								{audioPreviewKey === `sample:${key}` ? "Playing…" : "Play sample"}
							</button>
						{/if}
						<button type="button" class="btn btn-sm preset-filled-primary-500 shadow-sm disabled:opacity-50" onclick={() => playFullAnnouncementSample(key, cfg)} disabled={audioPreviewKey !== null}>
							{audioPreviewKey === `full:${key}` ? "Playing…" : "Play full"}
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
	</div>
{/if}
