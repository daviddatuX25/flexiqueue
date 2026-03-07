<script>
	/**
	 * ConfirmModal — reusable confirmation dialog (danger/warning/neutral).
	 * Per REPLACE-CONFIRM-ALERT-WITH-MODALS.md and 07-UI-UX-SPECS.md 6.3, 9.2.
	 * Uses Modal.svelte + Skeleton button presets.
	 */
	import Modal from './Modal.svelte';

	let {
		open = false,
		title = '',
		message = '',
		confirmLabel = 'Confirm',
		cancelLabel = 'Cancel',
		variant = 'neutral',
		loading = false,
		onConfirm = () => {},
		onCancel = () => {},
	} = $props();

	const confirmButtonPreset = $derived(
		variant === 'danger' ? 'preset-filled-error-500' : variant === 'warning' ? 'preset-filled-warning-500' : 'preset-filled-primary-500'
	);

	function handleCancel() {
		if (loading) return;
		onCancel();
	}

	async function handleConfirm() {
		if (loading) return;
		await onConfirm();
	}
</script>

<Modal {open} {title} onClose={handleCancel}>
	{#snippet children()}
		<p class="text-surface-950/90">{message}</p>
		<div class="flex justify-end gap-2 mt-4">
			<button
				type="button"
				class="btn preset-tonal min-h-[48px]"
				disabled={loading}
				onclick={handleCancel}
			>
				{cancelLabel}
			</button>
			<button
				type="button"
				class="btn {confirmButtonPreset} min-h-[48px]"
				disabled={loading}
				onclick={handleConfirm}
			>
				{#if loading}
					<span class="inline-block h-4 w-4 border-2 border-current border-t-transparent rounded-full animate-spin" aria-hidden="true"></span>
				{/if}
				{confirmLabel}
			</button>
		</div>
	{/snippet}
</Modal>
