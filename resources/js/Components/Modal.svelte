<script>
	/**
	 * Modal — generic dialog wrapper using native <dialog> + Skeleton styling.
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
			// #region agent log
			requestAnimationFrame(() => {
				const cs = getComputedStyle(dialogEl);
				const rect = dialogEl.getBoundingClientRect();
				fetch('http://127.0.0.1:7245/ingest/315b3bef-aa43-40ce-b2fe-4cd06d9bf0f1',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'b03ec3'},body:JSON.stringify({sessionId:'b03ec3',location:'Modal.svelte:effect',message:'dialog open (showModal)',data:{display:cs.display,alignItems:cs.alignItems,justifyContent:cs.justifyContent,position:cs.position,width:cs.width,height:cs.height,margin:cs.margin,rect:{top:rect.top,left:rect.left,width:rect.width,height:rect.height},viewport:{w:window.innerWidth,h:window.innerHeight},childCount:dialogEl.children.length},timestamp:Date.now(),hypothesisId:'H1-H5'})}).catch(()=>{});
			});
			// #endregion
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
	class="modal-dialog-center p-0 m-0 rounded-none border-0 shadow-none bg-transparent backdrop:bg-black/50"
	onclose={handleClose}
	onclick={(e) => e.target === dialogEl && handleClose()}
>
	<div
		class="card bg-surface-50 rounded-container elevation-modal p-6 relative max-h-[90vh] overflow-y-auto"
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
	<form method="dialog" class="absolute inset-0 -z-10"><button type="submit">close</button></form>
</dialog>
