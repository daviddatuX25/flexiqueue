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
	let requireOverride = $state(true);
	let priorityFirst = $state(true);
	let balanceMode = $state<"fifo" | "alternate">("fifo");
	let stationSelectionMode = $state("fixed");
	let alternateRatioP = $state(2);
	let alternateRatioR = $state(1);

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
		requireOverride = Boolean(s.require_permission_before_override ?? true);
		priorityFirst = Boolean(s.priority_first ?? true);
		balanceMode = ((s.balance_mode as string) ?? "fifo") as "fifo" | "alternate";
		stationSelectionMode = String(s.station_selection_mode ?? "fixed");
		const ar = (s.alternate_ratio as number[] | undefined) ?? [2, 1];
		alternateRatioP = Number(ar[0] ?? 2);
		alternateRatioR = Number(ar[1] ?? 1);
	}

	onMount(() => {
		loadSettings();
	});

	async function handleSave() {
		submitting = true;
		const { ok, message } = await api("PUT", "/api/admin/program-default-settings", {
			settings: {
				no_show_timer_seconds: noShowTimer,
				require_permission_before_override: requireOverride,
				priority_first: priorityFirst,
				balance_mode: balanceMode,
				station_selection_mode: stationSelectionMode,
				alternate_ratio: [alternateRatioP, alternateRatioR],
			},
		});
		submitting = false;
		if (!ok) toaster.error({ title: message ?? "Failed to save." });
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
							<div class="flex items-center gap-2">
								<span class="text-sm">Ratio Priority:Regular</span>
								<input type="number" class="input w-16 px-2 py-1 text-center" min="1" max="10" bind:value={alternateRatioP} />
								<span>:</span>
								<input type="number" class="input w-16 px-2 py-1 text-center" min="1" max="10" bind:value={alternateRatioR} />
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
