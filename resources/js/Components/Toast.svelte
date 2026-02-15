<script>
	/**
	 * Toast container — stacks alerts in top-right. Per 07-UI-UX-SPECS.md Section 9.3.
	 */
	import { toasts, dismiss } from '../stores/toastStore.js';

	const alertClass = (type) => {
		switch (type) {
			case 'success':
				return 'alert-success';
			case 'error':
				return 'alert-error';
			case 'info':
			default:
				return 'alert-info';
		}
	};
</script>

<div class="toast toast-top toast-end z-50 gap-2">
	{#each $toasts as t (t.id)}
		<div
			class="alert {alertClass(t.type)} shadow-lg text-sm py-2 px-4 flex items-center gap-2"
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
					class="btn btn-ghost btn-xs btn-square ml-auto"
					aria-label="Dismiss"
					onclick={() => dismiss(t.id)}
				>
					<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
				</button>
			{/if}
		</div>
	{/each}
</div>
