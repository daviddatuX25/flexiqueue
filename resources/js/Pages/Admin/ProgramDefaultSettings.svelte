<script lang="ts">
	/**
	 * Per ISSUES-ELABORATION §2: view/edit global default program settings.
	 * Used when creating new programs or "Apply default settings" on Program Show.
	 */
	import AdminLayout from "../../Layouts/AdminLayout.svelte";
	import { Link } from "@inertiajs/svelte";
	import { get } from "svelte/store";
	import { usePage } from "@inertiajs/svelte";
	import { onMount } from "svelte";
	import { toaster } from "../../lib/toaster.js";
	import { Clock, Users, GitMerge, AlertCircle } from "lucide-svelte";

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

	async function api(method: string, url: string, body?: object): Promise<{ ok: boolean; data?: object; message?: string }> {
		try {
			const res = await fetch(url, {
				method,
				headers: {
					"Content-Type": "application/json",
					Accept: "application/json",
					"X-CSRF-TOKEN": getCsrfToken(),
					"X-Requested-With": "XMLHttpRequest",
				},
				credentials: "same-origin",
				...(body ? { body: JSON.stringify(body) } : {}),
			});
			if (res.status === 419) {
				toaster.error({ title: MSG_SESSION_EXPIRED });
				return { ok: false, message: MSG_SESSION_EXPIRED };
			}
			const data = await res.json().catch(() => ({}));
			return { ok: res.ok, data, message: data?.message };
		} catch (e) {
			toaster.error({ title: MSG_NETWORK_ERROR });
			return { ok: false, message: MSG_NETWORK_ERROR };
		}
	}

	let loading = $state(true);
	let loadFailed = $state(false);
	let submitting = $state(false);
	let noShowTimer = $state(10);
	let maxNoShowAttempts = $state(3);
	let requireOverride = $state(true);
	let priorityFirst = $state(true);
	let balanceMode = $state<"fifo" | "alternate">("fifo");
	let stationSelectionMode = $state("fixed");
	let alternateRatioP = $state(2);
	let alternateRatioR = $state(1);
	let alternatePriorityFirst = $state(true);
	let displayScanTimeoutSeconds = $state(20);
	let displayAudioMuted = $state(false);
	let displayAudioVolume = $state(1);
	let displayTtsRepeatCount = $state(1);
	let displayTtsRepeatDelaySec = $state(2);
	let allowPublicTriage = $state(false);
	let identityBindingMode = $state<"disabled" | "required">("disabled");
	let allowUnverifiedEntry = $state(false);
	let enableDisplayHidBarcode = $state(true);
	let enablePublicTriageHidBarcode = $state(true);
	let enableDisplayCameraScanner = $state(true);
	let enablePublicTriageCameraScanner = $state(true);
	let ttsActiveLanguage = $state<"en" | "fil" | "ilo">("en");

	async function loadSettings() {
		loading = true;
		loadFailed = false;
		const { ok, data, message } = await api("GET", "/api/admin/program-default-settings");
		loading = false;
		if (!ok || !data) {
			loadFailed = true;
			if (message !== MSG_SESSION_EXPIRED && message !== MSG_NETWORK_ERROR) {
				toaster.error({ title: "Unable to load settings. Try again or refresh the page." });
			}
			return;
		}
		const s = (data as { settings?: Record<string, unknown> }).settings ?? {};
		noShowTimer = Number(s.no_show_timer_seconds ?? 10);
		maxNoShowAttempts = Number(s.max_no_show_attempts ?? 3);
		requireOverride = Boolean(s.require_permission_before_override ?? true);
		priorityFirst = Boolean(s.priority_first ?? true);
		balanceMode = ((s.balance_mode as string) ?? "fifo") as "fifo" | "alternate";
		stationSelectionMode = String(s.station_selection_mode ?? "fixed");
		const ar = (s.alternate_ratio as number[] | undefined) ?? [2, 1];
		alternateRatioP = Number(ar[0] ?? 2);
		alternateRatioR = Number(ar[1] ?? 1);
		alternatePriorityFirst = (s.alternate_priority_first as boolean | undefined) !== false;
		displayScanTimeoutSeconds = Math.min(
			300,
			Math.max(
				0,
				Number(
					(s as { display_scan_timeout_seconds?: number }).display_scan_timeout_seconds ??
						20,
				),
			),
		);
		displayAudioMuted = (s as { display_audio_muted?: boolean }).display_audio_muted === true;
		displayAudioVolume = Math.max(
			0,
			Math.min(
				1,
				Number((s as { display_audio_volume?: number }).display_audio_volume ?? 1),
			),
		);
		displayTtsRepeatCount = Math.max(
			1,
			Math.min(
				3,
				Math.floor(
					Number(
						(s as { display_tts_repeat_count?: number }).display_tts_repeat_count ??
							1,
					),
				),
			),
		);
		displayTtsRepeatDelaySec = Math.max(
			0.5,
			Math.min(
				10,
				(Number(
					(s as { display_tts_repeat_delay_ms?: number })
						.display_tts_repeat_delay_ms ?? 2000,
				) /
					1000),
			),
		);
		allowPublicTriage = (s as { allow_public_triage?: boolean }).allow_public_triage === true;
		identityBindingMode = ((s as { identity_binding_mode?: string }).identity_binding_mode === "required"
			? "required"
			: "disabled") as "disabled" | "required";
		allowUnverifiedEntry = (s as { allow_unverified_entry?: boolean }).allow_unverified_entry === true;
		enableDisplayHidBarcode = (s as { enable_display_hid_barcode?: boolean })
			.enable_display_hid_barcode !== false;
		enablePublicTriageHidBarcode = (s as { enable_public_triage_hid_barcode?: boolean })
			.enable_public_triage_hid_barcode !== false;
		enableDisplayCameraScanner = (s as { enable_display_camera_scanner?: boolean })
			.enable_display_camera_scanner !== false;
		enablePublicTriageCameraScanner = (s as { enable_public_triage_camera_scanner?: boolean })
			.enable_public_triage_camera_scanner !== false;
		const tts = (s as { tts?: { active_language?: string } }).tts;
		const lang = (tts?.active_language as string | undefined) ?? "en";
		ttsActiveLanguage = (["en", "fil", "ilo"].includes(lang) ? lang : "en") as
			| "en"
			| "fil"
			| "ilo";
	}

	onMount(() => {
		loadSettings();
	});

	async function handleSave() {
		submitting = true;
		const { ok, message } = await api(
			"PUT",
			"/api/admin/program-default-settings",
			{
				settings: {
					no_show_timer_seconds: noShowTimer,
					max_no_show_attempts: maxNoShowAttempts,
					require_permission_before_override: requireOverride,
					priority_first: priorityFirst,
					balance_mode: balanceMode,
					station_selection_mode: stationSelectionMode,
					alternate_ratio: [alternateRatioP, alternateRatioR],
					alternate_priority_first: alternatePriorityFirst,
					display_scan_timeout_seconds: displayScanTimeoutSeconds,
					display_audio_muted: displayAudioMuted,
					display_audio_volume: displayAudioVolume,
					display_tts_repeat_count: displayTtsRepeatCount,
					display_tts_repeat_delay_ms: Math.round(
						displayTtsRepeatDelaySec * 1000,
					),
					allow_public_triage: allowPublicTriage,
					identity_binding_mode: identityBindingMode,
					allow_unverified_entry: allowUnverifiedEntry,
					enable_display_hid_barcode: enableDisplayHidBarcode,
					enable_public_triage_hid_barcode: enablePublicTriageHidBarcode,
					enable_display_camera_scanner: enableDisplayCameraScanner,
					enable_public_triage_camera_scanner: enablePublicTriageCameraScanner,
					tts: {
						active_language: ttsActiveLanguage,
					},
				},
			},
		);
		submitting = false;
		if (ok) toaster.success({ title: "Default settings updated." });
		else toaster.error({ title: message ?? "Failed to save." });
	}
</script>

<svelte:head>
	<title>Default program settings — Admin</title>
</svelte:head>

<AdminLayout>
	<div class="max-w-3xl mx-auto">
		<div class="flex items-center gap-4 mb-6">
			<Link href="/admin/programs" class="btn preset-tonal btn-sm">← Programs</Link>
			<h1 class="text-xl font-semibold text-surface-950">Default program settings</h1>
		</div>
		<p class="text-sm text-surface-600 mb-6">
			These values are used when you click "Apply default settings" on a program's Settings tab. New programs do not auto-apply; use Apply default settings after creating one.
		</p>

		{#if loading}
			<p class="text-surface-500">Loading…</p>
		{:else if loadFailed}
			<div role="alert" class="rounded-container border border-error-200 bg-error-50 p-4 mb-4">
				<p class="text-error-800 text-sm">Failed to load settings.</p>
				<button type="button" class="btn preset-tonal btn-sm mt-3 touch-target-h" onclick={() => loadSettings()}>Try again</button>
			</div>
		{:else}
			<div class="rounded-container bg-surface-50 border border-surface-200 shadow-sm p-6 space-y-6">
				<div class="flex flex-col sm:flex-row gap-4">
					<div class="sm:w-1/3 shrink-0">
						<h3 class="font-medium text-surface-950 flex items-center gap-2"><Clock class="w-4 h-4 text-surface-500" /> No-show timer</h3>
						<p class="text-xs text-surface-500 mt-1">Seconds before staff can mark no-show.</p>
					</div>
					<div class="sm:w-2/3">
						<input type="number" class="input rounded-container border border-surface-200 px-3 py-2 w-24" min="5" max="120" bind:value={noShowTimer} />
						<span class="text-sm text-surface-600 ml-2">seconds</span>
					</div>
				</div>
				<div class="flex flex-col sm:flex-row gap-4">
					<div class="sm:w-1/3 shrink-0">
						<h3 class="font-medium text-surface-950 flex items-center gap-2"><AlertCircle class="w-4 h-4 text-surface-500" /> Max no-show attempts</h3>
						<p class="text-xs text-surface-500 mt-1">After this many no-shows, staff must choose Extend or Last call (default 3).</p>
					</div>
					<div class="sm:w-2/3">
						<input type="number" class="input rounded-container border border-surface-200 px-3 py-2 w-24" min="1" max="10" bind:value={maxNoShowAttempts} />
					</div>
				</div>
				<div class="flex flex-col sm:flex-row gap-4">
					<div class="sm:w-1/3 shrink-0">
						<h3 class="font-medium text-surface-950 flex items-center gap-2"><Users class="w-4 h-4 text-surface-500" /> Priority first</h3>
						<p class="text-xs text-surface-500 mt-1">Call PWD/Senior before Regular.</p>
					</div>
					<div class="sm:w-2/3 form-control pt-1">
						<label class="label cursor-pointer justify-start gap-3 w-fit">
							<input type="checkbox" class="checkbox" bind:checked={priorityFirst} />
							<span class="label-text">Enable priority first routing</span>
						</label>
					</div>
				</div>
				<div class="flex flex-col sm:flex-row gap-4" class:opacity-60={priorityFirst}>
					<div class="sm:w-1/3 shrink-0">
						<h3 class="font-medium text-surface-950 flex items-center gap-2"><GitMerge class="w-4 h-4 text-surface-500" /> Balance mode</h3>
						<p class="text-xs text-surface-500 mt-1">When priority first is off.</p>
					</div>
					<div class="sm:w-2/3 space-y-3">
						<select class="select rounded-container border border-surface-200 px-3 py-2 w-full" bind:value={balanceMode} disabled={priorityFirst}>
							<option value="fifo">FIFO</option>
							<option value="alternate">Alternate (ratio)</option>
						</select>
						{#if balanceMode === "alternate" && !priorityFirst}
							<div class="space-y-2">
								<div class="flex items-center gap-2">
									<span class="text-sm">Ratio Priority:Regular</span>
									<input type="number" class="input w-16 px-2 py-1 text-center" min="1" max="10" bind:value={alternateRatioP} />
									<span>:</span>
									<input type="number" class="input w-16 px-2 py-1 text-center" min="1" max="10" bind:value={alternateRatioR} />
								</div>
								<div class="form-control pt-1">
									<label class="label cursor-pointer justify-start gap-3 w-fit text-xs">
										<input type="checkbox" class="checkbox checkbox-xs" bind:checked={alternatePriorityFirst} />
										<span class="label-text">Start alternate cycle with priority lane first</span>
									</label>
								</div>
							</div>
						{/if}
					</div>
				</div>
				<div class="flex flex-col sm:flex-row gap-4">
					<div class="sm:w-1/3 shrink-0">
						<h3 class="font-medium text-surface-950 flex items-center gap-2"><GitMerge class="w-4 h-4 text-surface-500" /> Station selection</h3>
						<p class="text-xs text-surface-500 mt-1">How to pick station when multiple serve same process.</p>
					</div>
					<div class="sm:w-2/3">
						<select class="select rounded-container border border-surface-200 px-3 py-2 w-full" bind:value={stationSelectionMode}>
							<option value="fixed">Fixed</option>
							<option value="shortest_queue">Shortest Queue</option>
							<option value="least_busy">Least Busy</option>
							<option value="round_robin">Round Robin</option>
							<option value="least_recently_served">Least Recently Served</option>
						</select>
					</div>
				</div>
				<div class="border-t border-surface-200 pt-6 mt-4 space-y-6">
					<div class="flex flex-col sm:flex-row gap-4">
						<div class="sm:w-1/3 shrink-0">
							<h3 class="font-medium text-surface-950 flex items-center gap-2">
								<Clock class="w-4 h-4 text-surface-500" /> Display auto-close
							</h3>
							<p class="text-xs text-surface-500 mt-1">
								Seconds before the \"Scan\" card auto-closes on Display and Status screens (0 = never).
							</p>
						</div>
						<div class="sm:w-2/3 flex items-center gap-2">
							<input
								type="number"
								min="0"
								max="300"
								class="input rounded-container border border-surface-200 px-3 py-2 w-24"
								bind:value={displayScanTimeoutSeconds}
							/>
							<span class="text-sm text-surface-600">seconds</span>
						</div>
					</div>
					<div class="flex flex-col sm:flex-row gap-4">
						<div class="sm:w-1/3 shrink-0">
							<h3 class="font-medium text-surface-950">Display audio &amp; TTS</h3>
							<p class="text-xs text-surface-500 mt-1">
								Default volume and repeat behavior when announcements play on the Display board.
							</p>
						</div>
						<div class="sm:w-2/3 space-y-3">
							<div class="form-control">
								<label class="label cursor-pointer justify-start gap-3 w-fit">
									<input type="checkbox" class="checkbox" bind:checked={displayAudioMuted} />
									<span class="label-text text-sm">Mute audio by default</span>
								</label>
							</div>
							<div class="flex items-center gap-3">
								<label class="text-sm text-surface-700 w-32" for="default-volume">
									Volume
								</label>
								<input
									id="default-volume"
									type="range"
									min="0"
									max="1"
									step="0.05"
									class="range range-xs flex-1"
									bind:value={displayAudioVolume}
								/>
								<span class="w-10 text-right text-xs text-surface-600">
									{Math.round(displayAudioVolume * 100)}%
								</span>
							</div>
							<div class="flex flex-wrap items-center gap-3">
								<label class="text-sm text-surface-700" for="default-repeat-count">
									Repeat
								</label>
								<select
									id="default-repeat-count"
									class="select rounded-container border border-surface-200 px-3 py-1 h-9"
									bind:value={displayTtsRepeatCount}
								>
									<option value={1}>Once</option>
									<option value={2}>Twice</option>
									<option value={3}>Three times</option>
								</select>
								<span class="text-xs text-surface-500">Delay between repeats</span>
								<input
									type="number"
									min="0.5"
									max="10"
									step="0.5"
									class="input w-20 px-2 py-1 text-right"
									bind:value={displayTtsRepeatDelaySec}
								/>
								<span class="text-xs text-surface-600">seconds</span>
							</div>
							<div class="flex items-center gap-3">
								<label class="text-sm text-surface-700 w-32" for="default-tts-language">
									TTS language
								</label>
								<select
									id="default-tts-language"
									class="select rounded-container border border-surface-200 px-3 py-1 h-9 w-40"
									bind:value={ttsActiveLanguage}
								>
									<option value="en">English</option>
									<option value="fil">Filipino</option>
									<option value="ilo">Ilocano</option>
								</select>
							</div>
						</div>
					</div>
					<div class="flex flex-col sm:flex-row gap-4">
						<div class="sm:w-1/3 shrink-0">
							<h3 class="font-medium text-surface-950">Public triage &amp; identity</h3>
							<p class="text-xs text-surface-500 mt-1">
								Default behavior when clients start at Public triage: whether triage is enabled, ID is required, and unverified entries are allowed.
							</p>
						</div>
						<div class="sm:w-2/3 space-y-3">
							<div class="form-control">
								<label class="label cursor-pointer justify-start gap-3 w-fit">
									<input type="checkbox" class="checkbox" bind:checked={allowPublicTriage} />
									<span class="label-text text-sm">Allow public triage</span>
								</label>
							</div>
							<div class="space-y-2">
								<label
									class="text-xs font-medium text-surface-600"
									for="default-identity-policy"
								>
									Identity policy at triage
								</label>
								<select
									id="default-identity-policy"
									class="select rounded-container border border-surface-200 px-3 py-1 h-9 w-full max-w-xs"
									bind:value={identityBindingMode}
								>
									<option value="disabled">No ID at triage</option>
									<option value="required">ID or registration required</option>
								</select>
								<div class="form-control pt-1">
									<label class="label cursor-pointer justify-start gap-3 w-fit text-xs">
										<input
											type="checkbox"
											class="checkbox checkbox-xs"
											bind:checked={allowUnverifiedEntry}
											disabled={!allowPublicTriage || identityBindingMode !== "required"}
										/>
										<span class="label-text">
											Allow unverified registrations to start visits from public triage
										</span>
									</label>
								</div>
							</div>
						</div>
					</div>
					<div class="flex flex-col sm:flex-row gap-4">
						<div class="sm:w-1/3 shrink-0">
							<h3 class="font-medium text-surface-950">Scanners</h3>
							<p class="text-xs text-surface-500 mt-1">
								Default scanner inputs available on Display and Public triage screens.
							</p>
						</div>
						<div class="sm:w-2/3 space-y-2">
							<div class="form-control">
								<label class="label cursor-pointer justify-start gap-3 w-fit text-sm">
									<input
										type="checkbox"
										class="checkbox"
										bind:checked={enableDisplayHidBarcode}
									/>
									<span class="label-text">Enable HID barcode on Display board</span>
								</label>
							</div>
							<div class="form-control">
								<label class="label cursor-pointer justify-start gap-3 w-fit text-sm">
									<input
										type="checkbox"
										class="checkbox"
										bind:checked={enablePublicTriageHidBarcode}
									/>
									<span class="label-text">Enable HID barcode on Public triage</span>
								</label>
							</div>
							<div class="form-control">
								<label class="label cursor-pointer justify-start gap-3 w-fit text-sm">
									<input
										type="checkbox"
										class="checkbox"
										bind:checked={enableDisplayCameraScanner}
									/>
									<span class="label-text">Enable camera/QR scanner on Display board</span>
								</label>
							</div>
							<div class="form-control">
								<label class="label cursor-pointer justify-start gap-3 w-fit text-sm">
									<input
										type="checkbox"
										class="checkbox"
										bind:checked={enablePublicTriageCameraScanner}
									/>
									<span class="label-text">Enable camera/QR scanner on Public triage</span>
								</label>
							</div>
						</div>
					</div>
				</div>
				<div class="flex flex-col sm:flex-row gap-4">
					<div class="sm:w-1/3 shrink-0">
						<h3 class="font-medium text-surface-950 flex items-center gap-2"><AlertCircle class="w-4 h-4 text-surface-500" /> Require override PIN</h3>
						<p class="text-xs text-surface-500 mt-1">Supervisor PIN to redirect clients.</p>
					</div>
					<div class="sm:w-2/3 form-control pt-1">
						<label class="label cursor-pointer justify-start gap-3 w-fit">
							<input type="checkbox" class="checkbox" bind:checked={requireOverride} />
							<span class="label-text">Require supervisor PIN</span>
						</label>
					</div>
				</div>
				<div class="pt-4 border-t border-surface-200">
					<button type="button" class="btn preset-filled-primary-500" disabled={submitting} onclick={handleSave}>
						{submitting ? "Saving…" : "Save default settings"}
					</button>
				</div>
			</div>
		{/if}
	</div>
</AdminLayout>
