<script lang="ts">
	import AdminLayout from '../../Layouts/AdminLayout.svelte';
	import { get } from 'svelte/store';
	import { onMount } from 'svelte';
	import { usePage } from '@inertiajs/svelte';
	import { RefreshCw, LayoutDashboard, AlertCircle } from 'lucide-svelte';

	// Components
	import HealthStats from '../../Components/Dashboard/HealthStats.svelte';
	import ActiveProgramCard from '../../Components/Dashboard/ActiveProgramCard.svelte';
	import StationStatusTable from '../../Components/Dashboard/StationStatusTable.svelte';
	import QuickActions from '../../Components/Dashboard/QuickActions.svelte';

	// Types
	import type { DashboardStats, DashboardStation } from '../../types/dashboard';

	let stats = $state<DashboardStats | null>(null);
	let stations = $state<DashboardStation[]>([]);
	let loading = $state(true);
	let error = $state('');
	let refreshIntervalId = $state<ReturnType<typeof setInterval> | null>(null);

	const page = usePage();
	function getCsrfToken(): string {
		const p = get(page);
		const fromProps = (p?.props as { csrf_token?: string } | undefined)?.csrf_token;
		if (fromProps) return fromProps;
		const metaEl =
			typeof document !== 'undefined'
				? (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content
				: '';
		return metaEl ?? '';
	}

	async function fetchStats(): Promise<DashboardStats | null> {
		const res = await fetch('/api/dashboard/stats', {
			headers: {
				Accept: 'application/json',
				'X-CSRF-TOKEN': getCsrfToken(),
				'X-Requested-With': 'XMLHttpRequest'
			},
			credentials: 'same-origin'
		});
		const data = await res.json().catch(() => ({}));
		return res.ok ? data : null;
	}

	async function fetchStations(): Promise<DashboardStation[]> {
		const res = await fetch('/api/dashboard/stations', {
			headers: {
				Accept: 'application/json',
				'X-CSRF-TOKEN': getCsrfToken(),
				'X-Requested-With': 'XMLHttpRequest'
			},
			credentials: 'same-origin'
		});
		const data = await res.json().catch(() => ({}));
		return res.ok && data.stations ? data.stations : [];
	}

	async function refresh() {
		loading = true;
		error = '';
		const [s, st] = await Promise.all([fetchStats(), fetchStations()]);
		loading = false;
		if (s) stats = s;
		else error = 'Failed to load dashboard stats.';
		stations = st;
	}

	function formatDate(): string {
		return new Date().toLocaleDateString(undefined, {
			weekday: 'short',
			year: 'numeric',
			month: 'short',
			day: 'numeric'
		});
	}

	onMount(() => {
		refresh();
		refreshIntervalId = setInterval(refresh, 10000);
		return () => {
			if (refreshIntervalId) clearInterval(refreshIntervalId);
		};
	});
</script>

<svelte:head>
	<title>Dashboard — FlexiQueue</title>
</svelte:head>

<AdminLayout>
	<div class="flex flex-col gap-6 max-w-[1600px] mx-auto w-full">
		<!-- Header -->
		<div class="flex flex-wrap items-center justify-between gap-4">
			<div>
				<h1 class="text-2xl font-bold text-surface-950 flex items-center gap-2">
					<LayoutDashboard class="h-6 w-6 text-primary-600" />
					Admin Dashboard
				</h1>
				<p class="text-sm text-surface-950/60 mt-1 ml-8">
					Live system status for <span class="font-medium text-surface-900">{formatDate()}</span>
				</p>
			</div>
			<button
				type="button"
				class="btn preset-filled-primary-500 btn-sm gap-2 shadow-sm hover:shadow-md transition-all"
				onclick={refresh}
				disabled={loading}
				aria-label="Refresh dashboard"
			>
				{#if loading}
					<span class="loading-spinner loading-sm"></span>
				{:else}
					<RefreshCw class="h-4 w-4" />
				{/if}
				Refresh
			</button>
		</div>

		<!-- Error Alert -->
		{#if error}
			<div
				class="bg-error-50 text-error-900 border border-error-200 rounded-container p-4 flex items-center gap-3 shadow-sm"
			>
				<AlertCircle class="h-5 w-5 text-error-600" />
				<span class="font-medium">{error}</span>
			</div>
		{/if}

		<!-- Loading State -->
		{#if loading && !stats}
			<div class="flex justify-center py-24">
				<div class="flex flex-col items-center gap-4">
					<span class="loading-spinner loading-lg text-primary-500"></span>
					<p class="text-surface-500 text-sm animate-pulse">Loading dashboard data...</p>
				</div>
			</div>
		{:else if stats}
			<!-- Health Stats Grid -->
			<HealthStats {stats} />

			<!-- Main Content Grid -->
			<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
				<!-- Left Column (Program & Stations) -->
				<div class="lg:col-span-8 xl:col-span-9 space-y-6 flex flex-col">
					<!-- Active Program Card -->
					<div class="shrink-0">
						<ActiveProgramCard {stats} />
					</div>

					<!-- Station Status Table -->
					<div class="grow min-h-[300px]">
						<StationStatusTable {stations} />
					</div>
				</div>

				<!-- Right Column (Quick Actions) -->
				<div class="lg:col-span-4 xl:col-span-3">
					<QuickActions {stats} />
				</div>
			</div>
		{/if}
	</div>
</AdminLayout>
