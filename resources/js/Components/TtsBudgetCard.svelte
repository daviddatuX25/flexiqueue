<script lang="ts">
	/**
	 * TTS generation budget status for site admin.
	 * Fetches from /api/admin/tts/budget. Shows usage when policy is enabled, or platform-global monitoring when enabled.
	 */
	import { Link } from "@inertiajs/svelte";
	import { usePage } from "@inertiajs/svelte";
	import { onMount } from "svelte";
	import { AlertTriangle, BarChart3, Info } from "lucide-svelte";

	/** When set (e.g. site admin’s `/admin/sites/{id}`), show link to edit policy on Site show. Hidden when platform global budget applies. */
	let { editHref = null as string | null }: { editHref?: string | null } = $props();

	interface BudgetPolicy {
		enabled: boolean;
		mode: string;
		period: string;
		limit: number;
		warning_threshold_pct: number;
		block_on_limit: boolean;
	}

	interface GlobalMonitoring {
		period_key?: string;
		effective_char_limit?: number;
		chars_used?: number;
		remaining?: number;
		at_limit?: boolean;
		platform_char_limit?: number;
		platform_chars_used_total?: number;
		warning_threshold_pct?: number;
		message?: string;
	}

	interface BudgetData {
		policy: BudgetPolicy;
		usage: { chars_used: number; period_key: string } | null;
		remaining: number | null;
		at_limit: boolean;
		period_key: string | null;
		platform_global_budget_enabled?: boolean;
		global_monitoring?: GlobalMonitoring;
	}

	const page = usePage();

	function getCsrfToken(): string {
		return (
			($page.props?.csrf_token as string | undefined) ??
			(typeof document !== "undefined"
				? (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content
				: "") ??
			""
		);
	}

	const isSuperAdmin = $derived($page.props?.auth?.is_super_admin === true);

	let loading = $state(true);
	let data = $state<BudgetData | null>(null);
	let error = $state<string | null>(null);

	const globalMon = $derived(data?.global_monitoring);
	const globalEnforced = $derived(
		globalMon != null &&
			globalMon.effective_char_limit !== undefined &&
			globalMon.effective_char_limit !== null,
	);
	const usagePct = $derived(
		data?.policy?.enabled && data?.usage && data.policy.limit > 0
			? Math.min(100, (data.usage.chars_used / data.policy.limit) * 100)
			: 0,
	);
	const globalUsagePct = $derived.by(() => {
		const g = data?.global_monitoring;
		if (
			!g ||
			g.effective_char_limit === undefined ||
			g.effective_char_limit === null ||
			g.effective_char_limit <= 0
		) {
			return 0;
		}
		return Math.min(100, ((g.chars_used ?? 0) / g.effective_char_limit) * 100);
	});
	const globalIsWarning = $derived(
		globalUsagePct >= (globalMon?.warning_threshold_pct ?? 80),
	);
	const isWarning = $derived(usagePct >= (data?.policy?.warning_threshold_pct ?? 80));

	onMount(() => {
		fetchBudget();
	});

	async function fetchBudget() {
		loading = true;
		error = null;
		try {
			const res = await fetch("/api/admin/tts/budget", {
				headers: { Accept: "application/json", "X-CSRF-TOKEN": getCsrfToken(), "X-Requested-With": "XMLHttpRequest" },
				credentials: "same-origin",
			});
			if (res.status === 404) {
				data = null;
				error = "No site assigned.";
				return;
			}
			const json = await res.json().catch(() => ({}));
			if (res.ok && json && "policy" in json) {
				data = json as BudgetData;
			} else {
				data = null;
				error = (json as { error?: string })?.error ?? "Failed to load budget.";
			}
		} catch {
			error = "Network error.";
		} finally {
			loading = false;
		}
	}
</script>

{#if loading}
	<div class="rounded-container border border-surface-200 bg-surface-50 p-4 animate-pulse">
		<div class="h-6 w-32 bg-surface-200 rounded mb-2"></div>
		<div class="h-4 w-48 bg-surface-200 rounded"></div>
	</div>
{:else if error}
	<div class="rounded-container border border-surface-200 bg-surface-50 p-4 text-surface-600 text-sm">
		{error}
	</div>
{:else if data?.platform_global_budget_enabled && globalEnforced && globalMon}
	<div class="rounded-container border border-surface-200 bg-surface-50 p-4 space-y-3">
		<div class="rounded-lg border border-primary-200 bg-primary-50/70 p-3 flex gap-2 text-xs text-surface-800">
			<Info class="w-4 h-4 text-primary-600 shrink-0 mt-0.5" aria-hidden="true" />
			<p>
				Platform-wide TTS budget is on. This site’s effective limit comes from the shared pool and weights
				(Configuration). Per-site policy below is not used for enforcement.
			</p>
		</div>
		<div class="flex flex-wrap items-center justify-between gap-2">
			<div class="flex items-center gap-2">
				<BarChart3 class="w-5 h-5 text-surface-500 shrink-0" />
				<span class="text-sm font-medium text-surface-700">TTS generation budget</span>
			</div>
			<div class="flex flex-wrap items-center gap-2">
				{#if globalMon.period_key}
					<span class="text-xs text-surface-500 font-mono">{globalMon.period_key}</span>
				{/if}
				{#if isSuperAdmin}
					<Link href="/admin/settings?tab=tts-generation" class="btn btn-sm preset-tonal">Platform settings</Link>
				{/if}
			</div>
		</div>
		<div class="flex items-baseline gap-2">
			<span class="text-lg font-semibold text-surface-900">{(globalMon.chars_used ?? 0).toLocaleString()}</span>
			<span class="text-sm text-surface-600">/ {(globalMon.effective_char_limit ?? 0).toLocaleString()} chars (this site)</span>
		</div>
		<div class="w-full bg-surface-200 rounded-full h-2">
			<div
				class="h-2 rounded-full transition-all {globalMon.at_limit ? 'bg-error' : globalIsWarning ? 'bg-warning' : 'bg-primary'}"
				style="width: {Math.min(100, globalUsagePct)}%"
			></div>
		</div>
		<div class="flex items-center justify-between text-xs text-surface-600">
			<span>{(globalMon.remaining ?? 0).toLocaleString()} remaining</span>
			{#if globalMon.at_limit}
				<span class="flex items-center gap-1 text-error font-medium">
					<AlertTriangle class="w-3.5 h-3.5" />
					Limit reached — generation blocked
				</span>
			{/if}
		</div>
		{#if globalMon.platform_char_limit != null && globalMon.platform_chars_used_total != null}
			<p class="text-xs text-surface-500">
				Platform pool (all sites):
				<span class="font-mono">{(globalMon.platform_chars_used_total ?? 0).toLocaleString()}</span>
				/
				<span class="font-mono">{(globalMon.platform_char_limit ?? 0).toLocaleString()}</span>
				chars
			</p>
		{/if}
	</div>
{:else if data?.platform_global_budget_enabled && globalMon?.message}
	<div class="rounded-container border border-surface-200 bg-surface-50 p-4 space-y-3">
		<div class="flex items-start gap-2">
			<Info class="w-5 h-5 text-primary-500 shrink-0 mt-0.5" aria-hidden="true" />
			<div class="space-y-2 text-sm text-surface-700">
				<p class="font-medium">Platform-wide TTS budget</p>
				<p class="text-xs text-surface-600">{globalMon.message}</p>
				{#if isSuperAdmin}
					<Link href="/admin/settings?tab=tts-generation" class="btn btn-sm preset-tonal w-fit">Open Configuration</Link>
				{/if}
			</div>
		</div>
	</div>
{:else if !data?.policy?.enabled}
	<div class="rounded-container border border-surface-200 bg-surface-50 p-4 flex items-center gap-3">
		<BarChart3 class="w-5 h-5 text-surface-400 shrink-0" />
		<div>
			<p class="text-sm font-medium text-surface-700">TTS generation budget</p>
			<p class="text-xs text-surface-500">Not configured for this site. Contact your administrator to set limits.</p>
		</div>
	</div>
{:else}
	<div class="rounded-container border border-surface-200 bg-surface-50 p-4 space-y-3">
		<div class="flex flex-wrap items-center justify-between gap-2">
			<div class="flex items-center gap-2">
				<BarChart3 class="w-5 h-5 text-surface-500 shrink-0" />
				<span class="text-sm font-medium text-surface-700">TTS generation budget</span>
			</div>
			<div class="flex flex-wrap items-center gap-2">
				{#if data.period_key}
					<span class="text-xs text-surface-500">{data.period_key}</span>
				{/if}
				{#if editHref && !data?.platform_global_budget_enabled}
					<Link href={editHref} class="btn btn-sm preset-tonal">Edit policy</Link>
				{/if}
			</div>
		</div>
		{#if data.usage}
			<div class="flex items-baseline gap-2">
				<span class="text-lg font-semibold text-surface-900">{data.usage.chars_used.toLocaleString()}</span>
				<span class="text-sm text-surface-600">/ {data.policy.limit.toLocaleString()} chars</span>
			</div>
			<div class="w-full bg-surface-200 rounded-full h-2">
				<div
					class="h-2 rounded-full transition-all {data.at_limit ? 'bg-error' : isWarning ? 'bg-warning' : 'bg-primary'}"
					style="width: {Math.min(100, usagePct)}%"
				></div>
			</div>
			<div class="flex items-center justify-between text-xs text-surface-600">
				<span>{data.remaining?.toLocaleString() ?? 0} remaining</span>
				{#if data.at_limit}
					<span class="flex items-center gap-1 text-error font-medium">
						<AlertTriangle class="w-3.5 h-3.5" />
						Limit reached — generation blocked
					</span>
				{/if}
			</div>
		{/if}
	</div>
{/if}
