<script>
	/**
	 * Token queue status from public check-status API (device refactor Phase 1 / Slice A).
	 * Matches Display/Status layout (progress + optional flow diagram).
	 *
	 * Auto-dismiss: use **autoDismiss={true}** + **displayScanTimeoutSeconds** on kiosk/public surfaces (matches program display settings).
	 * Authenticated staff flows should use **autoDismiss={false}** (default) so the modal stays open until the user closes it.
	 */
	import Modal from './Modal.svelte';
	import DiagramCanvas from './ProgramDiagram/DiagramCanvas.svelte';

	let {
		open = false,
		onClose,
		qrHash = '',
		siteId = null,
		csrfToken = '',
		/** When true, countdown + auto onClose after displayScanTimeoutSeconds (public/kiosk). Default false (staff). */
		autoDismiss = false,
		displayScanTimeoutSeconds = 20,
	} = $props();

	let loading = $state(false);
	let error = $state(null);
	let payload = $state(null);

	const dismissSeconds = $derived(Math.max(0, Number(displayScanTimeoutSeconds) || 20));
	let countdown = $state(0);
	/** Not $state — assigning would retrigger effects that call clearCountdown() and read this. */
	let countdownIntervalId = null;

	const showDiagram = $derived(
		payload &&
			payload.diagram &&
			payload.diagram_program &&
			Array.isArray(payload.diagram.nodes) &&
			payload.diagram.nodes.length > 0 &&
			Array.isArray(payload.diagram_tracks),
	);

	function clearCountdown() {
		if (countdownIntervalId != null) {
			clearInterval(countdownIntervalId);
			countdownIntervalId = null;
		}
	}

	function startCountdown() {
		if (!autoDismiss || dismissSeconds <= 0) {
			return;
		}
		clearCountdown();
		countdown = dismissSeconds;
		countdownIntervalId = setInterval(() => {
			countdown -= 1;
			if (countdown <= 0) {
				clearCountdown();
				onClose?.();
			}
		}, 1000);
	}

	function extendCountdown() {
		if (!autoDismiss || dismissSeconds <= 0) {
			return;
		}
		countdown += dismissSeconds;
	}

	$effect(() => {
		if (!open) {
			clearCountdown();
			error = null;
			payload = null;
			loading = false;
			return;
		}
		if (!qrHash) {
			return;
		}
		void autoDismiss;

		loading = true;
		error = null;
		payload = null;
		clearCountdown();

		let cancelled = false;
		const path =
			siteId != null
				? `/api/check-status/${siteId}/${encodeURIComponent(qrHash)}`
				: `/api/check-status/${encodeURIComponent(qrHash)}`;

		(async () => {
			try {
				const res = await fetch(path, {
					credentials: 'same-origin',
					headers: {
						Accept: 'application/json',
						...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest' } : {}),
					},
				});
				const data = await res.json().catch(() => ({}));
				if (cancelled) {
					return;
				}
				if (!res.ok) {
					error = typeof data.message === 'string' ? data.message : 'Could not load status.';
				} else {
					payload = data;
				}
			} catch {
				if (!cancelled) {
					error = 'Network error.';
				}
			} finally {
				if (!cancelled) {
					loading = false;
					if (autoDismiss) {
						startCountdown();
					}
				}
			}
		})();

		return () => {
			cancelled = true;
			clearCountdown();
		};
	});

</script>

<Modal {open} title="Token status" wide={showDiagram} onClose={() => onClose?.()}>
	{#snippet children()}
		<div
			class="mx-auto flex flex-col gap-6 w-full {showDiagram ? 'max-w-4xl' : 'max-w-md'}"
			data-testid="status-checker-modal"
		>
			{#if loading}
				<p class="text-surface-600 text-sm">Loading…</p>
			{:else if error}
				<div class="bg-error-100 text-error-900 border border-error-300 rounded-container p-4" role="alert">
					{error}
				</div>
			{:else if payload?.status === 'available' && payload?.message}
				<div class="card bg-surface-50 border border-surface-200 rounded-container p-4">
					<div class="text-4xl font-bold text-surface-950">{payload.alias ?? '—'}</div>
					<p class="text-surface-950/80 mt-2">{payload.message}</p>
				</div>
			{:else if payload}
				<div class="card bg-surface-50 border border-surface-200 rounded-container p-4">
					<div class="text-5xl font-bold text-primary-500">{payload.alias ?? '—'}</div>
					{#if payload.track}
						<p class="text-sm text-surface-950/70 mt-1">Track: <strong>{payload.track}</strong></p>
					{/if}
					{#if payload.client_category}
						<span class="text-xs px-2 py-0.5 rounded preset-filled-warning-500 mt-2 inline-block">{payload.client_category}</span>
					{/if}
					{#if payload.status}
						<span class="text-xs px-2 py-0.5 rounded preset-filled-primary-500 mt-2 inline-block">{payload.status}</span>
					{/if}
					{#if payload.current_station}
						<p class="mt-2 text-surface-950/80">Currently at: <strong>{payload.current_station}</strong></p>
					{/if}
					{#if payload.estimated_wait_minutes != null}
						<p class="text-sm text-surface-950/70">Estimated wait: ~{payload.estimated_wait_minutes} minutes</p>
					{/if}
					{#if payload.started_at}
						<p class="text-xs text-surface-950/50 mt-1">Started {new Date(payload.started_at).toLocaleString()}</p>
					{/if}
				</div>

				{#if payload.progress?.steps?.length}
					<div class="card bg-surface-50 border border-surface-200 rounded-container p-4">
						<h3 class="font-semibold text-surface-950 mb-2">Progress</h3>
						<ul class="space-y-2 w-full">
							{#each payload.progress.steps as step}
								<li
									class="flex items-center gap-2 {step.status === 'complete' || step.status === 'in_progress'
										? 'text-primary-500'
										: 'text-surface-950/70'}"
								>
									<span
										class="w-2 h-2 rounded-full {step.status === 'complete'
											? 'bg-success-500'
											: step.status === 'in_progress'
												? 'bg-primary-500'
												: 'bg-surface-300'}"
									></span>
									{step.station_name} —
									{step.status === 'complete' ? 'Complete' : step.status === 'in_progress' ? 'In progress' : 'Waiting'}
								</li>
							{/each}
						</ul>
					</div>
				{/if}

				{#if showDiagram}
					<div class="w-full">
						<h3 class="font-semibold text-surface-950 mb-3">Your flow</h3>
						<div class="overflow-x-auto -mx-2 sm:mx-0 max-w-full">
							<DiagramCanvas
								program={payload.diagram_program}
								tracks={payload.diagram_tracks}
								stations={payload.diagram_stations}
								processes={payload.diagram_processes}
								readOnly={true}
								initialLayout={payload.diagram}
								initialSelectedTrackId={payload.diagram_track_id}
								initialStaffList={payload.diagram_staff}
							/>
						</div>
					</div>
				{/if}
			{/if}

			{#if autoDismiss && !loading && (payload || error)}
				<div class="flex flex-wrap items-center justify-center gap-2">
					<p class="text-sm text-surface-950/60">Closing in {countdown}s</p>
					<button type="button" class="btn preset-tonal text-sm py-1.5 px-3" onclick={extendCountdown}>
						Extend (+{dismissSeconds}s)
					</button>
				</div>
			{/if}
		</div>
	{/snippet}
</Modal>
