<script>
	/**
	 * Display/Status.svelte — client QR status result. Per 09-UI-ROUTES-PHASE1 §3.5.
	 * Data from server (check-status logic). Dismiss returns to /display. Auto-dismiss uses display_scan_timeout_seconds from program settings.
	 * Hidden barcode input allows continuous scanning: scan next ticket without reopening camera (per display scanner plan).
	 * When in_use and program has a diagram, shows client's track flow (read-only, no modifying actions).
	 */
	import { onDestroy, tick } from 'svelte';
	import { Link, router } from '@inertiajs/svelte';
	import DisplayLayout from '../../Layouts/DisplayLayout.svelte';
	import DiagramCanvas from '../../Components/ProgramDiagram/DiagramCanvas.svelte';
	import { shouldFocusHidInput, shouldUseInputModeNone } from '../../lib/displayHid.js';

	let {
		error = null,
		alias = null,
		status = null,
		client_category = null,
		progress = null,
		current_station = null,
		estimated_wait_minutes = null,
		started_at = null,
		message = null,
		display_scan_timeout_seconds = 20,
		program_name = null,
		date = '',
		enable_display_hid_barcode = true,
		diagram = null,
		diagram_program = null,
		diagram_tracks = [],
		diagram_stations = [],
		diagram_processes = [],
		diagram_staff = [],
		diagram_track_id = null,
	} = $props();

	const showDiagram = $derived(
		diagram &&
			diagram_program &&
			Array.isArray(diagram.nodes) &&
			diagram.nodes.length > 0 &&
			Array.isArray(diagram_tracks)
	);

	const dismissSeconds = Math.max(0, Number(display_scan_timeout_seconds) || 20);
	let countdown = $state(dismissSeconds);
	let timerId;
	let barcodeInputValue = $state('');
	let barcodeInputEl = $state(null);

	timerId = setInterval(() => {
		countdown -= 1;
		if (countdown <= 0) {
			clearInterval(timerId);
			router.visit('/display');
		}
	}, 1000);

	function extendCountdown() {
		const extra = Math.max(0, Number(display_scan_timeout_seconds) || 20);
		countdown += extra;
	}

	function onBarcodeKeydown(e) {
		if (e.key !== 'Enter') return;
		const raw = barcodeInputValue.trim();
		if (!raw) return;
		e.preventDefault();
		const qrHash = raw.includes('/') ? raw.split('/').pop() ?? raw : raw;
		if (qrHash) {
			router.visit(`/display/status/${encodeURIComponent(qrHash)}`);
		}
		barcodeInputValue = '';
		if (shouldFocusHidInput(enable_display_hid_barcode, 'display')) barcodeInputEl?.focus();
	}

	$effect(() => {
		const el = barcodeInputEl;
		if (!el || !shouldFocusHidInput(enable_display_hid_barcode, 'display')) return;
		tick().then(() => el?.focus());
	});

	/** Refocus hidden barcode input every 2s. Both program and device-local must allow (per plan). */
	$effect(() => {
		if (!shouldFocusHidInput(enable_display_hid_barcode, 'display')) return;
		const id = setInterval(() => {
			if (shouldFocusHidInput(enable_display_hid_barcode, 'display')) barcodeInputEl?.focus();
		}, 2000);
		return () => clearInterval(id);
	});

	onDestroy(() => {
		if (timerId) clearInterval(timerId);
	});
</script>

<svelte:head>
	<title>Your Status — FlexiQueue</title>
</svelte:head>

<DisplayLayout programName={program_name} date={date || ''}>
	<!-- Hidden input for HID barcode scanner: scan next ticket without reopening camera (continuous scanning) -->
	<input
		type="text"
		autocomplete="off"
		inputmode={shouldUseInputModeNone(enable_display_hid_barcode, 'display') ? 'none' : 'text'}
		aria-label="Barcode scanner input for next ticket; scan with hardware scanner or type and press Enter"
		class="sr-only"
		bind:value={barcodeInputValue}
		bind:this={barcodeInputEl}
		onkeydown={onBarcodeKeydown}
	/>
	<div class="max-w-md mx-auto flex flex-col gap-6">
		<h1 class="text-2xl font-bold text-surface-950">YOUR STATUS</h1>

		{#if error}
			<div class="bg-error-100 text-error-900 border border-error-300 rounded-container p-4">
				<span>{error}</span>
			</div>
		{:else if status === 'available' && message}
			<div class="card bg-surface-50 border border-surface-200 rounded-container p-4">
				<div class="text-4xl font-bold text-surface-950">{alias ?? '—'}</div>
				<p class="text-surface-950/80 mt-2">{message}</p>
			</div>
		{:else}
			<div class="card bg-surface-50 border border-surface-200 rounded-container p-4">
				<div class="text-5xl font-bold text-primary-500">{alias ?? '—'}</div>
				{#if client_category}
					<span class="text-xs px-2 py-0.5 rounded preset-filled-warning-500 mt-2 inline-block">{client_category}</span>
				{/if}
				{#if status}
					<span class="text-xs px-2 py-0.5 rounded preset-filled-primary-500 mt-2 inline-block">{status}</span>
				{/if}
				{#if current_station}
					<p class="mt-2 text-surface-950/80">Currently at: <strong>{current_station}</strong></p>
				{/if}
				{#if estimated_wait_minutes != null}
					<p class="text-sm text-surface-950/70">Estimated wait: ~{estimated_wait_minutes} minutes</p>
				{/if}
				{#if started_at}
					<p class="text-xs text-surface-950/50 mt-1">Started {new Date(started_at).toLocaleString()}</p>
				{/if}
			</div>

			{#if progress?.steps?.length}
				<div class="card bg-surface-50 border border-surface-200 rounded-container p-4">
					<h2 class="font-semibold text-surface-950 mb-2">Progress</h2>
					<ul class="space-y-2 w-full">
						{#each progress.steps as step}
							<li class="flex items-center gap-2 {step.status === 'complete' || step.status === 'in_progress' ? 'text-primary-500' : 'text-surface-950/70'}">
								<span class="w-2 h-2 rounded-full {step.status === 'complete' ? 'bg-success-500' : step.status === 'in_progress' ? 'bg-primary-500' : 'bg-surface-300'}"></span>
								{step.station_name} — {step.status === 'complete' ? 'Complete' : step.status === 'in_progress' ? 'In progress' : 'Waiting'}
							</li>
						{/each}
					</ul>
				</div>
			{/if}

			{#if showDiagram}
				<div class="w-full">
					<h2 class="font-semibold text-surface-950 mb-3">Your flow</h2>
					<div class="overflow-x-auto -mx-2 sm:mx-0">
						<DiagramCanvas
							program={diagram_program}
							tracks={diagram_tracks}
							stations={diagram_stations}
							processes={diagram_processes}
							readOnly={true}
							initialLayout={diagram}
							initialSelectedTrackId={diagram_track_id}
							initialStaffList={diagram_staff}
						/>
					</div>
				</div>
			{/if}
		{/if}

		<div class="flex flex-wrap items-center justify-center gap-2">
			<p class="text-sm text-surface-950/60">Returning to board in {countdown}s</p>
			<button
				type="button"
				class="btn preset-tonal text-sm py-1.5 px-3"
				onclick={extendCountdown}
			>
				Extend (+{Math.max(0, Number(display_scan_timeout_seconds) || 20)}s)
			</button>
		</div>
		<div>
			<Link href="/display" class="btn preset-filled-primary-500 w-full">Go back</Link>
		</div>
	</div>
</DisplayLayout>
