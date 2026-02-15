<script>
	/**
	 * ConfirmModal — reusable confirmation dialog (danger/warning/neutral).
	 * Per REPLACE-CONFIRM-ALERT-WITH-MODALS.md and 07-UI-UX-SPECS.md 6.3, 9.2.
	 * Uses Modal.svelte + modal-action for confirm/cancel.
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

	const confirmButtonClass = $derived(
		variant === 'danger' ? 'btn-error' : variant === 'warning' ? 'btn-warning' : 'btn-primary'
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
		<p class="text-base-content/90">{message}</p>
		<div class="modal-action mt-4">
			<button
				type="button"
				class="btn btn-ghost"
				disabled={loading}
				onclick={handleCancel}
			>
				{cancelLabel}
			</button>
			<button
				type="button"
				class="btn {confirmButtonClass}"
				disabled={loading}
				onclick={handleConfirm}
			>
				{#if loading}
					<span class="loading loading-spinner loading-sm"></span>
				{/if}
				{confirmLabel}
			</button>
		</div>
	{/snippet}
</Modal>
