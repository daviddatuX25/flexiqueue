<script>
	/**
	 * Consumes Laravel flash from Inertia page props and shows them as toasts.
	 * Include in every layout that has Toaster. Per docs/TOAST-MIGRATION-MAP.md.
	 */
	import { usePage } from '@inertiajs/svelte';
	import { toaster } from '../lib/toaster.js';

	const pageStore = usePage();
	let lastShown = $state('');

	$effect(() => {
		const p = $pageStore;
		const flash = (typeof p === 'object' && p?.props) ? p.props.flash : undefined;
		if (!flash || typeof flash !== 'object') return;
		const key = JSON.stringify(flash);
		if (key === lastShown) return;
		lastShown = key;
		if (flash.error) toaster.error({ title: flash.error });
		if (flash.success) toaster.success({ title: flash.success });
		if (flash.status) toaster.info({ title: flash.status });
	});
</script>
