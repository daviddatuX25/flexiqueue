<script lang="ts">
	/**
	 * FlexiQueue toast UI: softer visuals, header inset, truncated messages.
	 * Supports action: { label, onClick } for clickable CTAs (e.g. "Go to triage").
	 * Per docs/TOAST-MIGRATION-MAP.md.
	 */
	import { normalizeProps, useMachine } from '@zag-js/svelte';
	import * as toast from '@zag-js/toast';
	import FlexiQueueToast from './FlexiQueueToast.svelte';
	import { toaster } from '../lib/toaster.js';

	const service = useMachine(toast.group.machine, () => ({
		id: 'flexiqueue-toaster',
		store: toaster
	}));
	const api = $derived(toast.group.connect(service, normalizeProps));
</script>

<div {...api.getGroupProps()} data-testid="toaster-root">
	{#each api.getToasts() as t, index (t.id)}
		<FlexiQueueToast
			toast={t}
			{index}
			parent={service}
			base="relative flex items-start gap-3"
			width="min-w-[280px] max-w-md"
			padding="p-4"
			titleBase="text-sm font-semibold"
			titleClasses="text-surface-950 line-clamp-2 break-words"
			descriptionBase="text-xs mt-0.5"
			descriptionClasses="text-surface-600 line-clamp-3 break-words"
		/>
	{/each}
</div>
