<script>
	/**
	 * Footer QR → triage bind (available token). Loads program context via API (device refactor Phase 1).
	 */
	import Modal from './Modal.svelte';
	import StaffTriageBindPanel from './StaffTriageBindPanel.svelte';
	import { getLocalAllowHidOnThisDevice, isMobileTouch } from '../lib/displayHid.js';
	import { shouldAllowCameraScanner } from '../lib/displayCamera.js';

	let {
		open = false,
		onClose,
		token = null,
		getCsrfToken,
		onBound,
		staffTriageAllowHid = true,
		staffTriageAllowCamera = true,
	} = $props();

	let program = $state(null);
	let loading = $state(false);
	let loadError = $state(null);

	let localAllowHid = $state(true);
	let localAllowCamera = $state(true);
	const effectiveHid = $derived(staffTriageAllowHid !== false && localAllowHid);
	const effectiveCamera = $derived(staffTriageAllowCamera !== false && localAllowCamera);

	$effect(() => {
		if (!open) {
			program = null;
			loadError = null;
			loading = false;
			return;
		}
		const hidLocal = getLocalAllowHidOnThisDevice('staff_binder');
		localAllowHid = hidLocal !== null ? hidLocal : !isMobileTouch();
		localAllowCamera = shouldAllowCameraScanner('staff_binder', staffTriageAllowCamera !== false);
	});

	$effect(() => {
		if (!open || !token) return;
		let cancelled = false;
		loading = true;
		loadError = null;
		program = null;
		fetch('/api/staff/triage-bind-context', {
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'X-CSRF-TOKEN': getCsrfToken?.() ?? '',
				'X-Requested-With': 'XMLHttpRequest',
			},
		})
			.then(async (res) => {
				const data = await res.json().catch(() => ({}));
				if (!res.ok) {
					loadError = typeof data.message === 'string' ? data.message : 'Could not load triage context.';
					return;
				}
				if (!cancelled) program = data;
			})
			.catch(() => {
				if (!cancelled) loadError = 'Network error.';
			})
			.finally(() => {
				if (!cancelled) loading = false;
			});
		return () => {
			cancelled = true;
		};
	});
</script>

<Modal {open} title="Start visit" wide={true} onClose={() => onClose?.()}>
	{#snippet children()}
		<div class="min-h-[8rem]" data-testid="staff-triage-bind-modal">
			{#if loading}
				<p class="text-sm text-surface-600">Loading program…</p>
			{:else if loadError}
				<div class="bg-error-100 text-error-900 border border-error-300 rounded-container p-4" role="alert">{loadError}</div>
			{:else if program && token}
				<StaffTriageBindPanel
					{program}
					{token}
					effectiveHid={effectiveHid}
					effectiveCamera={effectiveCamera}
					{getCsrfToken}
					onCancel={() => onClose?.()}
					onBound={() => onBound?.()}
				/>
			{/if}
		</div>
	{/snippet}
</Modal>
