<script>
	/**
	 * OfflineBanner — listens to navigator.onLine, shows warning banner when offline.
	 * Per BD-007, 07-UI-UX-SPECS.md Section 6.3.
	 * Per UI-UX-QA-FULL-SYSTEM-FINDINGS.md 3.5 #9: Dismiss and Try again.
	 */
	let online = $state(typeof navigator !== 'undefined' ? navigator.onLine : true);
	let bannerDismissed = $state(false);

	$effect(() => {
		if (typeof window === 'undefined') return;
		const handler = () => {
			online = navigator.onLine;
			// Reset dismiss so banner can show again on next offline cycle
			if (!navigator.onLine) bannerDismissed = false;
		};
		window.addEventListener('online', handler);
		window.addEventListener('offline', handler);
		return () => {
			window.removeEventListener('online', handler);
			window.removeEventListener('offline', handler);
		};
	});
</script>

{#if !online && !bannerDismissed}
	<div
		class="bg-warning-100 text-warning-900 border-b border-warning-300 fixed top-0 left-0 right-0 z-50 shadow-lg py-2 px-4 flex items-center gap-3"
		role="alert"
	>
		<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
		<span>Offline: connection lost. Reconnecting…</span>
		<button
			type="button"
			class="btn preset-tonal btn-sm touch-target-h ml-auto"
			aria-label="Try to reconnect"
			onclick={() => window.location.reload()}
		>
			Try again
		</button>
		<button
			type="button"
			class="btn preset-tonal btn-sm touch-target-h"
			aria-label="Dismiss offline banner"
			onclick={() => (bannerDismissed = true)}
		>
			Dismiss
		</button>
	</div>
{/if}
