<script lang="ts">
	import type { DashboardStation } from '../../types/dashboard';
	import { Monitor } from 'lucide-svelte';
	import UserAvatar from '../UserAvatar.svelte';
	import AdminTable from '../AdminTable.svelte';

	let { stations }: { stations: DashboardStation[] } = $props();

	function availabilityDotClass(status: string | undefined): string {
		switch (status) {
			case 'available':
				return 'bg-success-500';
			case 'on_break':
				return 'bg-warning-500';
			default:
				return 'bg-surface-400';
		}
	}
</script>

<div class="card bg-surface-50 rounded-container elevation-card h-full flex flex-col">
	<div class="px-6 py-4 border-b border-surface-200 flex items-center gap-2">
		<Monitor class="h-4 w-4 text-surface-500" />
		<h2 class="card-title text-base">Station Status</h2>
	</div>
	<div class="overflow-x-auto flex-1">
		{#if stations.length === 0}
			<div class="px-6 py-12 text-center text-surface-950/60 text-sm flex flex-col items-center gap-2">
				<Monitor class="h-8 w-8 text-surface-300" />
				<span>No stations. Add stations to the active program.</span>
			</div>
		{:else}
			<AdminTable scrollable tableClass="w-full" compact={false}>
				{#snippet head()}
					<tr>
						<th>Station</th>
						<th>Staff</th>
						<th>Queue</th>
						<th>Current Client</th>
						<th>Status</th>
					</tr>
				{/snippet}
				{#snippet body()}
					{#each stations as s (s.id)}
						<tr>
							<td class="font-medium text-surface-900 dark:text-surface-100">{s.name}</td>
							<td>
								<div class="flex flex-wrap items-center gap-2">
									{#if s.assigned_staff.length > 0}
										{#each s.assigned_staff as staff (staff.id)}
											<span class="inline-flex items-center gap-1.5">
												<UserAvatar user={staff} size="sm" />
												<span
													class="w-2 h-2 rounded-full shrink-0 {availabilityDotClass(staff.availability_status)}"
													aria-label="{staff.availability_status ?? 'offline'}"
												></span>
												<span class="text-surface-800 dark:text-surface-200">{staff.name}</span>
											</span>
										{/each}
									{:else}
										<span class="text-surface-500 dark:text-surface-400">—</span>
									{/if}
								</div>
							</td>
							<td>
								{#if s.queue_count > 0}
									<div class="badge preset-tonal-primary font-bold">{s.queue_count}</div>
								{:else}
									<span class="text-surface-500 dark:text-surface-400 text-xs italic">Empty</span>
								{/if}
							</td>
							<td>
								{#if s.current_client}
									<span class="badge preset-filled-primary-500 font-bold text-sm shadow-sm"
										>{s.current_client}</span
									>
								{:else}
									<span class="text-surface-500 dark:text-surface-400 text-xs italic">—</span>
								{/if}
							</td>
							<td>
								<span
									class="badge text-xs font-medium {s.is_active
										? 'preset-filled-success-500'
										: 'preset-tonal-surface'}"
								>
									{s.is_active ? 'Active' : 'Inactive'}
								</span>
							</td>
						</tr>
					{/each}
				{/snippet}
			</AdminTable>
		{/if}
	</div>
</div>
