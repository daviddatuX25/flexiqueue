<script>
	/**
	 * Modal — generic dialog wrapper using native <dialog> + Skeleton styling.
	 * Per 07-UI-UX-SPECS.md Section 6.3, 9.2. Use element.showModal() / close().
	 */
	let {
		open = false,
		title = '',
		onClose = () => {},
		children,
		/** When true, use a wider card (e.g. for scanner modal). */
		wide = false,
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

<!-- Per ISSUES-ELABORATION: only explicit Close/Cancel (and optionally Escape) close; no click-outside -->
<dialog
	bind:this={dialogEl}
	class="modal-dialog-center p-0 m-0 rounded-none border-0 shadow-none bg-transparent backdrop:bg-black/50"
	onclose={handleClose}
	oncancel={(e) => e.preventDefault()}
	onkeydown={(e) => e.key === 'Escape' && handleClose()}
>
	<div
		class="card bg-surface-50 rounded-container elevation-modal p-4 sm:p-6 relative max-h-[85dvh] sm:max-h-[90vh] overflow-y-auto w-[calc(100vw-2rem)] max-w-[calc(100vw-2rem)] sm:max-w-2xl mx-auto {wide ? 'min-w-0 sm:min-w-[36rem] sm:w-full' : ''}"
		role="document"
		aria-label={title || 'Dialog'}
		onclick={(e) => e.stopPropagation()}
		onkeydown={(e) => e.key === 'Escape' && handleClose()}
	>
		{#if title}
			<h3 class="font-bold text-lg text-surface-950">{title}</h3>
		{/if}
		<div class="py-4">
			{#if children}
				{@render children()}
			{/if}
		</div>
		<button
			type="button"
			class="btn btn-icon btn-icon-sm preset-tonal absolute right-2 top-2"
			aria-label="Close"
			onclick={handleClose}
		>✕</button>
	</div>
	<!-- Per flexiqueue-ldd: no form/button on backdrop — only explicit Close or Escape close the modal -->
</dialog>
