<script>
	/**
	 * Modal — generic dialog wrapper using native <dialog> + Skeleton styling.
	 * Blur backdrop (via .modal-backdrop) and close on click outside; Escape and X also close.
	 * Per 07-UI-UX-SPECS.md Section 6.3, 9.2. Use element.showModal() / close().
	 */
	let {
		open = false,
		title = '',
		onClose = () => {},
		children,
		/** When true, use a wider card (e.g. for scanner modal). */
		wide = false,
		/** When true (default), clicking the backdrop or pressing Enter/Space on it closes the modal. Set false for critical dialogs that must not close on outside click. */
		closeOnBackdropClick = true,
	} = $props();

	let dialogEl = $state(/** @type {HTMLDialogElement | null} */ (null));
	/** Stored before showModal(); restored in handleClose for focus-return a11y. */
	let previousActiveElement = $state(/** @type {HTMLElement | null} */ (null));

	$effect(() => {
		if (!dialogEl) return;
		if (open) {
			previousActiveElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;
			dialogEl.showModal();
			// Focus trap: focus first focusable inside the dialog (per UI-UX-QA)
			requestAnimationFrame(() => {
				const focusable = dialogEl && dialogEl.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
				const first = focusable && focusable[0];
				if (first && typeof first.focus === 'function') first.focus();
			});
		} else {
			const prev = previousActiveElement;
			previousActiveElement = null;
			dialogEl.close();
			if (prev && document.contains(prev) && typeof prev.focus === 'function') prev.focus();
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
		if (!dialogEl || !dialogEl.open) return;
		const previous = previousActiveElement;
		previousActiveElement = null;
		dialogEl.close();
		onClose();
		if (previous && document.contains(previous) && typeof previous.focus === 'function') {
			previous.focus();
		}
	}

	function handleBackdropClick() {
		if (closeOnBackdropClick) handleClose();
	}

	function handleBackdropKeydown(e) {
		if (closeOnBackdropClick && (e.key === 'Enter' || e.key === ' ')) {
			e.preventDefault();
			handleClose();
		}
	}
</script>

<!-- Blur backdrop via .modal-backdrop; close on backdrop click (when closeOnBackdropClick), Escape, or X. Per UI-UX-QA: aria-modal and focus trap. -->
<dialog
	bind:this={dialogEl}
	class="modal-dialog-center p-0 m-0 rounded-none border-0 shadow-none bg-transparent backdrop:bg-transparent"
	aria-modal="true"
	onclose={handleClose}
	oncancel={(e) => e.preventDefault()}
	onkeydown={(e) => {
		if (e.key === 'Escape') handleClose();
		else handleKeydown(e);
	}}
>
	<button
		type="button"
		class="modal-backdrop border-0 p-0 appearance-none font-inherit cursor-pointer"
		aria-hidden="true"
		tabindex="-1"
		onclick={handleBackdropClick}
		onkeydown={handleBackdropKeydown}
	></button>
	<div
		class="card bg-surface-50 rounded-container elevation-modal p-4 sm:p-6 relative max-h-[85dvh] sm:max-h-[90vh] overflow-y-auto w-[calc(100vw-1.5rem)] max-w-[calc(100vw-1.5rem)] sm:w-[calc(100vw-2rem)] sm:max-w-2xl mx-auto {wide ? 'min-w-0 sm:min-w-[36rem] sm:w-full' : ''}"
		role="document"
		aria-label={title || 'Dialog'}
	>
		{#if title}
			<h3 class="font-bold text-lg text-surface-950">{title}</h3>
		{/if}
		<div class="py-4">
			{#if children}
				{@render children()}
			{/if}
		</div>
		<!-- Per ui-ux-tasks-checklist: no background on close (X); ghost style, hover only -->
		<button
			type="button"
			class="btn btn-icon btn-icon-sm bg-transparent border-0 text-surface-600 hover:text-surface-950 hover:bg-surface-200 dark:text-surface-400 dark:hover:text-surface-100 dark:hover:bg-surface-700 absolute right-2 top-2 shadow-none"
			aria-label="Close"
			onclick={handleClose}
		>✕</button>
	</div>
</dialog>
