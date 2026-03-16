<script>
	/**
	 * Minimal layout for auth pages (Login). Provides Toaster + FlashToToast so
	 * Laravel flash (e.g. rate-limit error) shows as toast. Per docs/TOAST-MIGRATION-MAP.md.
	 */
	import FlexiQueueToaster from '../Components/FlexiQueueToaster.svelte';
	import FlashToToast from '../Components/FlashToToast.svelte';

	let { children } = $props();
</script>

<FlexiQueueToaster />
<FlashToToast />
{#if typeof children === 'function'}
	{@render children()}
{/if}
