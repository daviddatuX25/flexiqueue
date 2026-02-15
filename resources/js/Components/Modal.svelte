<script>
	/**
	 * Modal — generic dialog wrapper using DaisyUI modal + modal-box.
	 * Per 07-UI-UX-SPECS.md Section 6.3, 9.2. Use element.showModal() / close().
	 */
	let {
		open = false,
		title = '',
		onClose = () => {},
		children
	} = $props();

	let dialogEl = $state(/** @type {HTMLDialogElement | null} */ (null));

	$effect(() => {
		if (!dialogEl) return;
		if (open) {
			dialogEl.showModal();
		} else {
			dialogEl.close();
		}
	});

	function handleClose() {
		if (dialogEl) dialogEl.close();
		onClose();
	}
</script>

<dialog
	bind:this={dialogEl}
	class="modal"
	onclose={handleClose}
	onclick={(e) => e.target === dialogEl && handleClose()}
>
	<div class="modal-box" onclick={(e) => e.stopPropagation()}>
		{#if title}
			<h3 class="font-bold text-lg">{title}</h3>
		{/if}
		<div class="py-4">
			{#if children}
				{@render children()}
			{/if}
		</div>
		<button type="button" class="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" aria-label="Close" onclick={handleClose}>✕</button>
	</div>
	<form method="dialog" class="modal-backdrop"><button type="submit">close</button></form>
</dialog>
