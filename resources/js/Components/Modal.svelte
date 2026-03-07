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
			// Focus trap: focus first focusable inside the dialog (per UI-UX-QA)
			requestAnimationFrame(() => {
				const focusable = dialogEl && dialogEl.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
				const first = focusable && focusable[0];
				if (first && typeof first.focus === 'function') first.focus();
			});
		} else {
			dialogEl.close();
		}
	});

	function handleKeydown(e) {
		if (e.key !== 'Tab' || !dialogEl) return;
		const focusable = Array.from(dialogEl.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')).filter((el) => !el.hasAttribute('disabled'));
		if (focusable.length === 0) return;
		const first = focusable[0];
		const last = focusable[focusable.length - 1];
		if (e.shiftKey) {
			if (document.activeElement === first) {
				e.preventDefault();
				last.focus();
			}
		} else {
			if (document.activeElement === last) {
				e.preventDefault();
				first.focus();
			}
		}
	}

	function handleClose() {
		if (dialogEl) dialogEl.close();
		onClose();
	}
</script>

<!-- Per ISSUES-ELABORATION: only explicit Close/Cancel (and optionally Escape) close; no click-outside. Per UI-UX-QA: aria-modal and focus trap. -->
<dialog
	bind:this={dialogEl}
	class="modal-dialog-center p-0 m-0 rounded-none border-0 shadow-none bg-transparent backdrop:bg-black/50"
	aria-modal="true"
	onclose={handleClose}
	oncancel={(e) => e.preventDefault()}
	onkeydown={(e) => e.key === 'Escape' && handleClose()}
>
	<div
		class="card bg-surface-50 rounded-container elevation-modal p-4 sm:p-6 relative max-h-[85dvh] sm:max-h-[90vh] overflow-y-auto w-[calc(100vw-2rem)] max-w-[calc(100vw-2rem)] sm:max-w-2xl mx-auto {wide ? 'min-w-0 sm:min-w-[36rem] sm:w-full' : ''}"
		role="document"
		aria-label={title || 'Dialog'}
		onclick={(e) => e.stopPropagation()}
		onkeydown={handleKeydown}
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
