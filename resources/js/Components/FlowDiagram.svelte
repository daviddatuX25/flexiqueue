<script lang="ts">
	/**
	 * Visual track flow: step1 → step2 → step3 per track.
	 * Per 07-UI-UX-SPECS.md §6.6, 09-UI-ROUTES-PHASE1 §3.8.
	 */
	interface StepItem {
		id: number;
		process_id: number;
		process_name: string;
		step_order: number;
		is_required: boolean;
	}

	interface TrackItem {
		id: number;
		name: string;
		color_code: string | null;
		is_default: boolean;
		steps?: StepItem[];
	}

	let { tracks = [] }: { tracks: TrackItem[] } = $props();

	function sortedSteps(track: TrackItem): StepItem[] {
		const steps = track.steps ?? [];
		return [...steps].sort((a, b) => a.step_order - b.step_order);
	}
</script>

<div class="space-y-6">
	{#each tracks as track (track.id)}
		<div class="rounded-container bg-surface-50 border border-surface-200 p-4">
			<div class="flex items-center gap-2 mb-3">
				{#if track.color_code}
					<span
						class="inline-block h-4 w-4 rounded-full border border-surface-200 shrink-0"
						style="background-color: {track.color_code}"
						title="{track.color_code}"
					></span>
				{/if}
				<h3 class="font-semibold text-surface-950">{track.name}</h3>
				{#if track.is_default}
					<span class="text-xs px-2 py-0.5 rounded preset-filled-primary-500">Default</span>
				{/if}
			</div>
			{#if sortedSteps(track).length === 0}
				<p class="text-sm text-surface-950/60">No steps defined.</p>
			{:else}
				<div class="mt-1 -mx-1 overflow-x-auto overflow-y-hidden">
					<div
						class="flex items-stretch gap-2 px-1 pb-1 touch-target-h"
						aria-label="Track flow sequence"
					>
						{#each sortedSteps(track) as step, i (step.id)}
							<div
								class="flex items-center gap-2 rounded-container bg-surface-100 border border-surface-200/80 px-3 py-1.5 text-sm font-medium text-surface-950 shadow-[0_1px_3px_rgba(15,23,42,0.08)]"
							>
								<span class="whitespace-nowrap">
									{step.process_name}
									{#if step.is_required}
										<span class="text-surface-950/50 text-xs ml-1">*</span>
									{/if}
								</span>
								{#if i < sortedSteps(track).length - 1}
									<span
										class="text-surface-400 text-xs font-semibold select-none"
										aria-hidden="true"
									>
										→
									</span>
								{/if}
							</div>
						{/each}
					</div>
				</div>
			{/if}
		</div>
	{/each}
</div>
