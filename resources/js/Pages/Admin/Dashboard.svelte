<script lang="ts">
	import AdminLayout from '../../Layouts/AdminLayout.svelte';
	import { Link } from '@inertiajs/svelte';
	import { get } from 'svelte/store';
	import { onMount } from 'svelte';
	import { usePage } from '@inertiajs/svelte';

	interface Stats {
		active_program: { id: number; name: string } | null;
		sessions: {
			active: number;
			waiting: number;
			serving: number;
			completed_today: number;
			cancelled_today: number;
			no_show_today: number;
		};
		stations: { total: number; active: number; with_queue: number };
		staff_online: number;
		by_track: Array<{ track_name: string; count: number }>;
	}

	interface DashboardStation {
		id: number;
		name: string;
		is_active: boolean;
		queue_count: number;
		current_client: string | null;
		assigned_staff: Array<{ id: number; name: string }>;
	}

	let stats = $state<Stats | null>(null);
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

	async function fetchStats(): Promise<Stats | null> {
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

	function staffNames(s: DashboardStation): string {
		return s.assigned_staff.map((u) => u.name).join(', ') || '—';
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
	<div class="flex flex-col gap-6">
		<div class="flex flex-wrap items-center justify-between gap-4">
			<div>
				<h1 class="text-2xl font-bold text-base-content">Admin Dashboard</h1>
				<p class="text-sm text-base-content/60 mt-1">Live system status for {formatDate()}</p>
			</div>
			<button
				type="button"
				class="btn btn-sm btn-primary gap-2"
				onclick={refresh}
				disabled={loading}
				aria-label="Refresh dashboard"
			>
				{#if loading}
					<span class="loading loading-spinner loading-sm"></span>
				{:else}
					<svg
						xmlns="http://www.w3.org/2000/svg"
						class="h-4 w-4"
						fill="none"
						viewBox="0 0 24 24"
						stroke="currentColor"
					>
						<path
							stroke-linecap="round"
							stroke-linejoin="round"
							stroke-width="2"
							d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
						/>
					</svg>
				{/if}
				Refresh
			</button>
		</div>

		{#if error}
			<div class="alert alert-error">{error}</div>
		{/if}

		{#if loading && !stats}
			<div class="flex justify-center py-12">
				<span class="loading loading-spinner loading-lg text-primary"></span>
			</div>
		{:else if stats}
			<!-- Health Cards -->
			<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
				<div class="stats shadow bg-base-100 border border-base-200">
					<div class="stat p-4">
						<div class="stat-figure text-primary bg-primary/10 p-2 rounded-lg">
							<svg
								xmlns="http://www.w3.org/2000/svg"
								class="h-6 w-6"
								fill="none"
								viewBox="0 0 24 24"
								stroke="currentColor"
							>
								<path
									stroke-linecap="round"
									stroke-linejoin="round"
									stroke-width="2"
									d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"
								/>
							</svg>
						</div>
						<div class="stat-title text-xs font-medium uppercase tracking-wider opacity-60">
							Active Sessions
						</div>
						<div class="stat-value text-primary text-3xl">{stats.sessions.active}</div>
						<div class="stat-desc text-xs mt-1">
							{stats.sessions.waiting} waiting &middot; {stats.sessions.serving} serving
						</div>
					</div>
				</div>

				<div class="stats shadow bg-base-100 border border-base-200">
					<div class="stat p-4">
						<div class="stat-figure text-warning bg-warning/10 p-2 rounded-lg">
							<svg
								xmlns="http://www.w3.org/2000/svg"
								class="h-6 w-6"
								fill="none"
								viewBox="0 0 24 24"
								stroke="currentColor"
							>
								<path
									stroke-linecap="round"
									stroke-linejoin="round"
									stroke-width="2"
									d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
								/>
							</svg>
						</div>
						<div class="stat-title text-xs font-medium uppercase tracking-wider opacity-60">
							Queue Waiting
						</div>
						<div class="stat-value text-warning text-3xl">{stats.sessions.waiting}</div>
						<div class="stat-desc text-xs mt-1">Clients in queue</div>
					</div>
				</div>

				<div class="stats shadow bg-base-100 border border-base-200">
					<div class="stat p-4">
						<div class="stat-figure text-success bg-success/10 p-2 rounded-lg">
							<svg
								xmlns="http://www.w3.org/2000/svg"
								class="h-6 w-6"
								fill="none"
								viewBox="0 0 24 24"
								stroke="currentColor"
							>
								<path
									stroke-linecap="round"
									stroke-linejoin="round"
									stroke-width="2"
									d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"
								/>
							</svg>
						</div>
						<div class="stat-title text-xs font-medium uppercase tracking-wider opacity-60">
							Stations Online
						</div>
						<div class="stat-value text-success text-3xl">
							{stats.stations.active}<span class="text-sm text-base-content/40 font-normal">/{stats.stations.total}</span>
						</div>
						<div class="stat-desc text-xs mt-1">
							{stats.stations.with_queue} with queue
						</div>
					</div>
				</div>

				<div class="stats shadow bg-base-100 border border-base-200">
					<div class="stat p-4">
						<div class="stat-figure text-base-content bg-base-200 p-2 rounded-lg">
							<svg
								xmlns="http://www.w3.org/2000/svg"
								class="h-6 w-6"
								fill="none"
								viewBox="0 0 24 24"
								stroke="currentColor"
							>
								<path
									stroke-linecap="round"
									stroke-linejoin="round"
									stroke-width="2"
									d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
								/>
							</svg>
						</div>
						<div class="stat-title text-xs font-medium uppercase tracking-wider opacity-60">
							Completed Today
						</div>
						<div class="stat-value text-3xl">{stats.sessions.completed_today}</div>
						<div class="stat-desc text-xs mt-1">
							{stats.sessions.cancelled_today} cancelled &middot; {stats.sessions.no_show_today} no-show
						</div>
					</div>
				</div>
			</div>

			<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
				<div class="lg:col-span-2 space-y-6">
					<!-- Active Program -->
					<div class="card bg-base-100 shadow-sm border border-base-200">
						<div class="card-body py-5 px-6">
							<div class="flex justify-between items-start mb-4">
								<div>
									<h2 class="card-title text-base">Active Program</h2>
									<p class="text-sm text-base-content/60">
										{stats.active_program?.name ?? 'No active program'}
									</p>
								</div>
								{#if stats.active_program}
									<span class="badge badge-success badge-sm gap-1">
										<div class="w-1.5 h-1.5 bg-white rounded-full"></div>
										Live
									</span>
								{/if}
							</div>

							{#if stats.active_program && stats.by_track.length > 0}
								<div class="space-y-3">
									{#each stats.by_track as track (track.track_name)}
										<div>
											<div class="flex justify-between text-xs mb-1">
												<span>{track.track_name}</span>
												<span class="font-medium">{track.count} client{track.count === 1 ? '' : 's'}</span>
											</div>
											<progress
												class="progress progress-primary w-full"
												value={stats.sessions.active > 0 ? (track.count / stats.sessions.active) * 100 : 0}
												max="100"
											></progress>
										</div>
									{/each}
								</div>
							{:else if stats.active_program}
								<p class="text-sm text-base-content/60">No active sessions.</p>
							{:else}
								<p class="text-sm text-base-content/60">Activate a program from Programs.</p>
							{/if}
						</div>
					</div>

					<!-- Station Status Table -->
					<div class="card bg-base-100 shadow-sm border border-base-200">
						<div class="card-body p-0">
							<div class="px-6 py-4 border-b border-base-200">
								<h2 class="card-title text-base">Station Status</h2>
							</div>
							<div class="overflow-x-auto">
								{#if stations.length === 0}
									<div class="px-6 py-8 text-center text-base-content/60 text-sm">
										No stations. Add stations to the active program.
									</div>
								{:else}
									<table class="table table-zebra table-sm">
										<thead class="bg-base-200/50">
											<tr>
												<th class="pl-6">Station</th>
												<th>Staff</th>
												<th>Queue</th>
												<th>Current Client</th>
												<th class="pr-6">Status</th>
											</tr>
										</thead>
										<tbody>
											{#each stations as s (s.id)}
												<tr class="hover:bg-base-200/30">
													<td class="pl-6 font-medium">{s.name}</td>
													<td class="text-sm">{staffNames(s)}</td>
													<td>
														{#if s.queue_count > 0}
															<span class="font-bold">{s.queue_count}</span>
															<span class="text-xs text-base-content/50"> waiting</span>
														{:else}
															<span class="text-base-content/40 italic text-xs">None</span>
														{/if}
													</td>
													<td>
														{#if s.current_client}
															<span class="badge badge-lg badge-ghost font-bold">{s.current_client}</span>
														{:else}
															<span class="text-base-content/40 italic text-xs">—</span>
														{/if}
													</td>
													<td class="pr-6">
														<span
															class="badge badge-xs {s.is_active ? 'badge-success' : 'badge-ghost'}"
														>
															{s.is_active ? 'active' : 'inactive'}
														</span>
													</td>
												</tr>
											{/each}
										</tbody>
									</table>
								{/if}
							</div>
						</div>
					</div>
				</div>

				<!-- Quick Actions -->
				<div class="space-y-4">
					<div class="card bg-base-100 shadow-sm border border-base-200">
						<div class="card-body">
							<h2 class="card-title text-base">Quick Actions</h2>
							<div class="flex flex-col gap-2">
								<Link href="/admin/programs" class="btn btn-outline btn-sm justify-start">
									<svg
										xmlns="http://www.w3.org/2000/svg"
										class="h-4 w-4"
										fill="none"
										viewBox="0 0 24 24"
										stroke="currentColor"
									>
										<path
											stroke-linecap="round"
											stroke-linejoin="round"
											stroke-width="2"
											d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"
										/>
									</svg>
									Manage Program
								</Link>
								<Link href="/admin/users" class="btn btn-outline btn-sm justify-start">
									<svg
										xmlns="http://www.w3.org/2000/svg"
										class="h-4 w-4"
										fill="none"
										viewBox="0 0 24 24"
										stroke="currentColor"
									>
										<path
											stroke-linecap="round"
											stroke-linejoin="round"
											stroke-width="2"
											d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"
										/>
									</svg>
									Manage Staff
								</Link>
								<Link href="/admin/reports" class="btn btn-outline btn-sm justify-start">
									<svg
										xmlns="http://www.w3.org/2000/svg"
										class="h-4 w-4"
										fill="none"
										viewBox="0 0 24 24"
										stroke="currentColor"
									>
										<path
											stroke-linecap="round"
											stroke-linejoin="round"
											stroke-width="2"
											d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
										/>
									</svg>
									View Reports
								</Link>
							</div>
						</div>
					</div>

					<!-- Staff Online -->
					{#if stats}
						<div class="stats shadow bg-base-100 border border-base-200 w-full">
							<div class="stat py-4 px-4">
								<div class="stat-title text-xs font-medium uppercase tracking-wider opacity-60">
									Staff Assigned
								</div>
								<div class="stat-value text-2xl">{stats.staff_online}</div>
								<div class="stat-desc text-xs">Staff at stations</div>
							</div>
						</div>
					{/if}
				</div>
			</div>
		{/if}
	</div>
</AdminLayout>
