<script lang="ts">
	import { Link } from '@inertiajs/svelte';
	import { Settings, Users, FileText, Zap } from 'lucide-svelte';
	import type { DashboardStats } from '../../types/dashboard';

	let { stats }: { stats: DashboardStats | null } = $props();
</script>

<div class="space-y-4 h-full flex flex-col">
	<div class="card bg-surface-50 rounded-container elevation-card flex-1">
		<div class="card-body p-5 space-y-6">
			<h2 class="card-title text-base flex items-center gap-2 border-b border-surface-100 pb-3">
				<Zap class="h-4 w-4 text-warning-500" />
				Quick Actions
			</h2>

			<div>
				<p class="text-xs font-semibold uppercase tracking-wider text-surface-500 mb-3 ml-1">
					Program & Setup
				</p>
				<Link
					href="/admin/programs"
					class="btn preset-tonal-surface w-full justify-start gap-3 hover:preset-tonal-primary transition-colors py-3"
				>
					<Settings class="h-4 w-4 shrink-0 text-surface-600" />
					<span class="font-medium">Manage Program</span>
				</Link>
			</div>

			<div>
				<p class="text-xs font-semibold uppercase tracking-wider text-surface-500 mb-3 ml-1">
					People & Reports
				</p>
				<div class="flex flex-col gap-3">
					<Link
						href="/admin/users"
						class="btn preset-tonal-surface w-full justify-start gap-3 hover:preset-tonal-primary transition-colors py-3"
					>
						<Users class="h-4 w-4 shrink-0 text-surface-600" />
						<span class="font-medium">Manage Staff</span>
					</Link>
					<Link
						href="/admin/reports"
						class="btn preset-tonal-surface w-full justify-start gap-3 hover:preset-tonal-primary transition-colors py-3"
					>
						<FileText class="h-4 w-4 shrink-0 text-surface-600" />
						<span class="font-medium">View Reports</span>
					</Link>
				</div>
			</div>
		</div>
	</div>

	<!-- Staff Online -->
	{#if stats}
		<div class="stats bg-surface-50 rounded-container elevation-card w-full">
			<div class="stat py-4 px-5">
				<div class="stat-figure text-secondary-500 bg-secondary-100 p-2 rounded-lg">
					<Users class="h-5 w-5" />
				</div>
				<div class="stat-title text-xs font-medium uppercase tracking-wider">Staff Assigned</div>
				<div class="stat-value text-2xl text-secondary-600">{stats.staff_online}</div>
				<div class="stat-desc text-xs mt-1">Staff currently at stations</div>
			</div>
		</div>
	{/if}
</div>
