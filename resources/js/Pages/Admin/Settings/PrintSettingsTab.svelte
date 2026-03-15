<script lang="ts">
	/**
	 * Print settings form — embedded in Configuration tab.
	 * Migrated from Tokens/Index.svelte print modal.
	 */
	import { get } from "svelte/store";
	import { usePage } from "@inertiajs/svelte";
	import { onMount } from "svelte";
	import { toaster } from "../../../lib/toaster.js";
	import { compressImage, HERO_BANNER_PRESET } from "../../../lib/imageUtils.js";
	import { Printer, ChevronDown } from "lucide-svelte";

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

	async function api<T = unknown>(method: string, url: string, body?: object): Promise<{ ok: boolean; data?: T; message?: string }> {
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
				return { ok: false };
			}
			const data = await res.json().catch(() => ({}));
			return { ok: res.ok, data: data as T, message: (data as { message?: string })?.message };
		} catch (e) {
			toaster.error({ title: MSG_NETWORK_ERROR });
			return { ok: false };
		}
	}

	let printSettings = $state({
		cards_per_page: 6,
		paper: "a4",
		orientation: "portrait",
		show_hint: true,
		show_cut_lines: true,
		logo_url: "" as string,
		footer_text: "" as string,
		bg_image_url: "" as string,
	});
	let loading = $state(true);
	let submitting = $state(false);
	let printSettingsSaved = $state(false);
	let logoUploading = $state(false);
	let bgUploading = $state(false);
	let logoFileInput = $state<HTMLInputElement | null>(null);
	let bgFileInput = $state<HTMLInputElement | null>(null);

	onMount(() => { fetchPrintSettings(); });

	async function fetchPrintSettings() {
		loading = true;
		const { ok, data } = await api<{ print_settings?: Record<string, unknown> }>("GET", "/api/admin/print-settings");
		loading = false;
		if (ok && data?.print_settings) {
			const s = data.print_settings;
			printSettings = {
				cards_per_page: (s.cards_per_page as number) ?? 6,
				paper: (s.paper as string) ?? "a4",
				orientation: (s.orientation as string) ?? "portrait",
				show_hint: (s.show_hint as boolean) !== false,
				show_cut_lines: (s.show_cut_lines as boolean) !== false,
				logo_url: (s.logo_url as string) ?? "",
				footer_text: (s.footer_text as string) ?? "",
				bg_image_url: (s.bg_image_url as string) ?? "",
			};
		}
	}

	async function savePrintSettings() {
		submitting = true;
		printSettingsSaved = false;
		const { ok, data, message } = await api<{ print_settings?: Record<string, unknown> }>("PUT", "/api/admin/print-settings", {
			cards_per_page: printSettings.cards_per_page,
			paper: printSettings.paper,
			orientation: printSettings.orientation,
			show_hint: printSettings.show_hint,
			show_cut_lines: printSettings.show_cut_lines,
			logo_url: printSettings.logo_url.trim() || null,
			footer_text: printSettings.footer_text.trim() || null,
			bg_image_url: printSettings.bg_image_url.trim() || null,
		});
		submitting = false;
		if (ok && data?.print_settings) {
			const s = data.print_settings;
			printSettings = {
				cards_per_page: (s.cards_per_page as number) ?? 6,
				paper: (s.paper as string) ?? "a4",
				orientation: (s.orientation as string) ?? "portrait",
				show_hint: (s.show_hint as boolean) !== false,
				show_cut_lines: (s.show_cut_lines as boolean) !== false,
				logo_url: (s.logo_url as string) ?? "",
				footer_text: (s.footer_text as string) ?? "",
				bg_image_url: (s.bg_image_url as string) ?? "",
			};
			printSettingsSaved = true;
		} else {
			toaster.error({ title: message ?? "Failed to save settings." });
		}
	}

	async function uploadPrintImage(type: "logo" | "background", file: File) {
		if (!file) return;
		const compressed = await compressImage(file, HERO_BANNER_PRESET);
		const fd = new FormData();
		fd.append("image", compressed);
		fd.append("type", type);
		if (type === "logo") logoUploading = true; else bgUploading = true;
		try {
			const res = await fetch("/api/admin/print-settings/image", {
				method: "POST",
				headers: { "X-CSRF-TOKEN": getCsrfToken(), "X-Requested-With": "XMLHttpRequest" },
				body: fd,
				credentials: "same-origin",
			});
			if (res.status === 419) {
				toaster.error({ title: MSG_SESSION_EXPIRED });
				return;
			}
			const data = await res.json().catch(() => ({}));
			if (!res.ok) {
				toaster.error({ title: (data && "message" in data && (data as { message?: string }).message) || "Failed to upload image." });
				return;
			}
			const url = (data && (data as { url?: string }).url) as string | undefined;
			if (url) {
				if (type === "logo") printSettings.logo_url = url;
				else printSettings.bg_image_url = url;
				printSettingsSaved = false;
			}
		} catch (e) {
			toaster.error({ title: MSG_NETWORK_ERROR });
		} finally {
			if (type === "logo") { logoUploading = false; if (logoFileInput) logoFileInput.value = ""; }
			else { bgUploading = false; if (bgFileInput) bgFileInput.value = ""; }
		}
	}
</script>

<p class="text-sm text-surface-600 mb-4">
	Cards per page, paper size, logo, footer text, and background image for token card printing. These apply when you print from the Tokens page.
</p>

{#if loading}
	<p class="text-surface-500">Loading…</p>
{:else}
	<div class="rounded-container bg-surface-50 border border-surface-200 shadow-sm p-6 flex flex-col gap-5">
		<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
			<div class="form-control">
				<label for="cfg-print-cards" class="label"><span class="label-text font-medium">Cards per page</span></label>
				<select id="cfg-print-cards" class="select rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm" bind:value={printSettings.cards_per_page}>
					{#each [4, 5, 6, 7, 8] as n}
						<option value={n}>{n}</option>
					{/each}
				</select>
			</div>
			<div class="form-control">
				<label for="cfg-print-paper" class="label"><span class="label-text font-medium">Paper</span></label>
				<select id="cfg-print-paper" class="select rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm" bind:value={printSettings.paper}>
					<option value="a4">A4</option>
					<option value="letter">Letter</option>
				</select>
			</div>
			<div class="form-control">
				<label for="cfg-print-orientation" class="label"><span class="label-text font-medium">Orientation</span></label>
				<select id="cfg-print-orientation" class="select rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm" bind:value={printSettings.orientation}>
					<option value="portrait">Portrait</option>
					<option value="landscape">Landscape</option>
				</select>
			</div>
		</div>

		<div class="bg-surface-50 p-4 rounded-container border border-surface-200">
			<h4 class="text-xs font-semibold uppercase tracking-wider text-surface-500 mb-3">Display Options</h4>
			<div class="flex flex-col sm:flex-row gap-4 sm:gap-8">
				<label class="label cursor-pointer justify-start gap-3">
					<input type="checkbox" class="checkbox" bind:checked={printSettings.show_hint} />
					<span class="label-text font-medium">Show &quot;Scan for status&quot; hint</span>
				</label>
				<label class="label cursor-pointer justify-start gap-3">
					<input type="checkbox" class="checkbox" bind:checked={printSettings.show_cut_lines} />
					<span class="label-text font-medium">Show cut lines</span>
				</label>
			</div>
		</div>

		<div class="grid grid-cols-1 gap-4">
			<div class="form-control">
				<label for="cfg-print-logo" class="label"><span class="label-text font-medium">Logo URL <span class="text-surface-500 font-normal">(optional)</span></span></label>
				<div class="flex flex-col gap-2">
					<input id="cfg-print-logo" type="url" class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm" placeholder="https://example.com/logo.png" bind:value={printSettings.logo_url} />
					<div class="flex flex-wrap items-center gap-2">
						<button type="button" class="btn btn-sm preset-outlined bg-surface-50 text-surface-700 shadow-sm disabled:opacity-50" onclick={() => logoFileInput?.click()} disabled={logoUploading || submitting}>
							{logoUploading ? "Uploading…" : "Upload image"}
						</button>
						{#if printSettings.logo_url}
							<img src={printSettings.logo_url} alt="Logo preview" class="h-8 w-auto rounded border border-surface-200 bg-surface-50" />
						{/if}
						<input type="file" accept="image/*" class="hidden" bind:this={logoFileInput} onchange={(e) => { const f = (e.currentTarget as HTMLInputElement).files?.[0]; if (f) uploadPrintImage("logo", f); }} />
					</div>
				</div>
			</div>
			<div class="form-control">
				<label for="cfg-print-footer" class="label"><span class="label-text font-medium">Footer text <span class="text-surface-500 font-normal">(optional)</span></span></label>
				<textarea id="cfg-print-footer" class="textarea rounded-container border border-surface-200 w-full bg-surface-50 shadow-sm" placeholder="Shown on each card, centered." rows="2" bind:value={printSettings.footer_text}></textarea>
			</div>
			<div class="form-control">
				<label for="cfg-print-bg" class="label"><span class="label-text font-medium">Background image URL <span class="text-surface-500 font-normal">(optional)</span></span></label>
				<div class="flex flex-col gap-2">
					<input id="cfg-print-bg" type="text" class="input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 shadow-sm" placeholder="https://example.com/bg.png" bind:value={printSettings.bg_image_url} />
					<div class="flex flex-wrap items-center gap-2">
						<button type="button" class="btn btn-sm preset-outlined bg-surface-50 text-surface-700 shadow-sm disabled:opacity-50" onclick={() => bgFileInput?.click()} disabled={bgUploading || submitting}>
							{bgUploading ? "Uploading…" : "Upload image"}
						</button>
						{#if printSettings.bg_image_url}
							<div class="h-10 w-14 rounded border border-surface-200 bg-surface-100 bg-center bg-cover" style="background-image: url({printSettings.bg_image_url});"></div>
						{/if}
						<input type="file" accept="image/*" class="hidden" bind:this={bgFileInput} onchange={(e) => { const f = (e.currentTarget as HTMLInputElement).files?.[0]; if (f) uploadPrintImage("background", f); }} />
					</div>
					<p class="label-text-alt mt-1 flex items-center gap-1.5"><ChevronDown class="w-3 h-3 text-surface-400 rotate-[-90deg]" /> Use 6:5 aspect ratio for best fit per card.</p>
				</div>
			</div>
		</div>

		<div class="flex justify-end gap-3 pt-4 border-t border-surface-100">
			<button type="button" class="btn preset-filled-primary-500 shadow-sm" onclick={savePrintSettings} disabled={submitting}>
				{printSettingsSaved ? "Saved" : submitting ? "Saving…" : "Save as default"}
			</button>
		</div>
	</div>
{/if}
