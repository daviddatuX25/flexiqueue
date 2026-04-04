<script lang="ts">
	import type { DashboardStation } from '../../types/dashboard';
	import { Monitor, Users, UserRoundCheck } from 'lucide-svelte';
	import UserAvatar from '../UserAvatar.svelte';

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

	function availabilityLabel(status: string | undefined): 'Available' | 'On break' | 'Away' | 'Offline' {
		switch (status) {
			case 'available':
				return 'Available';
			case 'on_break':
				return 'On break';
			case 'away':
				return 'Away';
			default:
				return 'Offline';
		}
	}

	function getAvailableStaff(station: DashboardStation) {
		return station.assigned_staff.filter(
			(staff) => staff.availability_status === 'available'
		);
	}
</script>

<!-- Match Programs index cards: bg-surface-50, border-surface-200/50, text-surface-950/600/500 -->
<div class="bg-surface-50 rounded-container elevation-card h-full flex flex-col border border-surface-200/50">
	<div class="px-6 py-4 border-b border-surface-100 flex items-center gap-2">
		<Monitor class="h-4 w-4 text-surface-500 shrink-0" />
		<h2 class="card-title text-base text-surface-950">Station Status Board</h2>
	</div>
	<div class="p-5 flex-1">
		{#if stations.length === 0}
			<div class="px-6 py-12 text-center text-surface-600 text-sm flex flex-col items-center gap-2">
				<Monitor class="h-8 w-8 text-surface-400" />
				<span>No stations. Add stations to the active program.</span>
			</div>
		{:else}
			<div class="overflow-x-auto pb-1">
				<div class="flex flex-nowrap gap-4 min-w-max">
				{#each stations as s (s.id)}
					{@const availableStaff = getAvailableStaff(s)}
					<article
						class="w-[320px] shrink-0 bg-surface-50 rounded-container elevation-card border p-5 transition-all hover:shadow-[var(--shadow-raised)] flex flex-col {s.is_active
							? 'border-primary-300'
							: 'border-surface-200/50'}"
					>
						<div class="flex items-start justify-between gap-3">
							<div class="min-w-0">
								<p class="text-xs uppercase tracking-wide font-semibold text-surface-500">Station</p>
								<h3 class="text-lg font-bold text-surface-950 truncate">
									{s.name}
								</h3>
								<p class="text-xs mt-0.5 text-surface-600">
									Station {s.is_active ? 'active' : 'inactive'}
								</p>
							</div>
							{#if availableStaff.length > 0}
								<span class="badge preset-filled-success-500 text-[11px] font-semibold">
									Available
								</span>
							{/if}
						</div>

						<div class="mt-4 grid grid-cols-2 gap-3 text-sm">
							<div class="rounded-container border border-surface-200/50 p-3 bg-surface-50/50">
								<p class="text-[11px] uppercase font-semibold tracking-wide text-surface-500">
									Queue
								</p>
								<p class="mt-1 text-base font-semibold text-surface-950">
									{s.queue_count > 0 ? s.queue_count : 'Empty'}
								</p>
							</div>
							<div class="rounded-container border border-surface-200/50 p-3 bg-surface-50/50">
								<p class="text-[11px] uppercase font-semibold tracking-wide text-surface-500">
									Current Client
								</p>
								<p class="mt-1 text-base font-semibold text-surface-950">
									{s.current_client ?? '—'}
								</p>
							</div>
						</div>

						<div class="mt-4 rounded-container border border-surface-200/50 p-3 bg-surface-50/50">
							<div class="flex items-center justify-between gap-2 mb-2">
								<div
									class="inline-flex items-center gap-1.5 text-[11px] uppercase tracking-wide font-semibold text-surface-500"
								>
									<Users class="h-3.5 w-3.5" />
									Personnel
								</div>
								{#if availableStaff.length > 0}
									<div class="inline-flex items-center gap-1 text-success-600 text-xs font-medium">
										<UserRoundCheck class="h-3.5 w-3.5" />
										{availableStaff.length} available
									</div>
								{/if}
							</div>

							{#if s.assigned_staff.length === 0}
								<p class="text-xs text-surface-600 italic">No personnel assigned</p>
							{:else}
								<div class="flex flex-col gap-2">
									{#each s.assigned_staff as staff (staff.id)}
										<div class="flex items-center justify-between gap-2">
											<div class="flex items-center gap-2 min-w-0">
												<UserAvatar user={staff} size="sm" />
												<span class="text-sm text-surface-950 truncate font-medium">{staff.name}</span>
											</div>
											<div class="inline-flex items-center gap-1.5 text-xs shrink-0">
												<span
													class="w-2 h-2 rounded-full {availabilityDotClass(staff.availability_status)}"
													aria-label={availabilityLabel(staff.availability_status)}
												></span>
												<span class="text-surface-600 capitalize">
													{availabilityLabel(staff.availability_status)}
												</span>
											</div>
										</div>
									{/each}
								</div>
							{/if}
						</div>
					</article>
				{/each}
				</div>
			</div>
		{/if}
	</div>
</div>
