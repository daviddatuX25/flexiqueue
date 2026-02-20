<script>
	/**
	 * Toast container — stacks alerts in top-right. Per 07-UI-UX-SPECS.md Section 9.3.
	 */
	import { toasts, dismiss } from '../stores/toastStore.js';

	function alertClasses(type) {
		switch (type) {
			case 'success':
				return 'bg-success-100 text-success-900 border border-success-300';
			case 'error':
				return 'bg-error-100 text-error-900 border border-error-300';
			case 'info':
			default:
				return 'bg-primary-100 text-primary-900 border border-primary-300';
		}
	}
</script>

<div class="fixed top-4 right-4 z-50 flex flex-col gap-2">
	{#each $toasts as t (t.id)}
		<div
			class="rounded-container shadow-lg text-sm py-2 px-4 flex items-center gap-2 {alertClasses(t.type)}"
			role="alert"
		>
			{#if t.type === 'success'}
				<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"/></svg>
			{:else if t.type === 'error'}
				<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
			{:else}
				<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
			{/if}
			<span>{t.message}</span>
			{#if t.type === 'error'}
				<button
					type="button"
					class="btn btn-icon btn-icon-sm preset-tonal ml-auto"
					aria-label="Dismiss"
					onclick={() => dismiss(t.id)}
				>
					<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
				</button>
			{/if}
		</div>
	{/each}
</div>
