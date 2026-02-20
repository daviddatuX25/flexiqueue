<script lang="ts">
	/**
	 * QR scanner using html5-qrcode. When active, shows camera and calls onScan(decodedText) on success.
	 * Supports: camera selection (multi-camera), file-scan fallback (no camera), optional sound on scan.
	 */
	import { onMount, onDestroy } from 'svelte';

	interface CameraDevice {
		id: string;
		label: string;
	}

	let {
		active = false,
		onScan = () => {},
		soundOnScan = false,
		showFileFallback = true,
	} = $props();

	let containerId = 'qr-reader-' + Math.random().toString(36).slice(2, 9);
	let fileInputId = 'qr-file-' + Math.random().toString(36).slice(2, 9);
	let html5Qrcode: import('html5-qrcode').Html5Qrcode | null = null;

	let cameras = $state<CameraDevice[]>([]);
	let selectedCameraId = $state<string | null>(null);
	let errorMessage = $state('');
	let isLoading = $state(false);
	let mode = $state<'camera' | 'file' | 'error'>('camera');

	const SCAN_CONFIG = { fps: 5, qrbox: { width: 250, height: 250 } } as const;

	function playBeep() {
		try {
			const ctx = new (window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext)();
			const osc = ctx.createOscillator();
			const gain = ctx.createGain();
			osc.frequency.value = 800;
			osc.connect(gain);
			gain.connect(ctx.destination);
			gain.gain.setValueAtTime(0.2, ctx.currentTime);
			gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.1);
			osc.start(ctx.currentTime);
			osc.stop(ctx.currentTime + 0.1);
		} catch {
			// Ignore audio failures
		}
	}

	function handleScanSuccess(decodedText: string) {
		if (soundOnScan) playBeep();
		onScan(decodedText);
	}

	function pickPreferredCamera(cams: CameraDevice[]): string {
		const env = cams.find(
			(c) =>
				/back|rear|environment|external/i.test(c.label) || c.label.toLowerCase().includes('back')
		);
		return env?.id ?? cams[0]?.id ?? '';
	}

	async function loadCameras(): Promise<CameraDevice[]> {
		const { Html5Qrcode } = await import('html5-qrcode');
		const list = await Html5Qrcode.getCameras();
		return list ?? [];
	}

	async function startCamera(cameraIdOrConfig: string | { facingMode: string }) {
		await stopScanner();
		if (!document.getElementById(containerId)) return;
		const { Html5Qrcode } = await import('html5-qrcode');
		html5Qrcode = new Html5Qrcode(containerId);
		await html5Qrcode.start(
			cameraIdOrConfig,
			SCAN_CONFIG,
			(decodedText) => handleScanSuccess(decodedText),
			() => {}
		);
	}

	async function stopScanner() {
		if (html5Qrcode?.isScanning) {
			await html5Qrcode.stop();
		}
		html5Qrcode = null;
	}

	async function initScanner() {
		if (!active) return;
		errorMessage = '';
		isLoading = true;
		mode = 'camera';

		try {
			const list = await loadCameras();
			if (list.length === 0) {
				errorMessage = 'No camera found.';
				mode = showFileFallback ? 'file' : 'error';
				return;
			}
			cameras = list;
			const preferred = pickPreferredCamera(list);
			selectedCameraId = preferred || list[0]!.id;
			await startCamera(selectedCameraId);
			mode = 'camera';
		} catch (err) {
			const e = err as { name?: string; message?: string };
			if (e?.name === 'NotAllowedError' || e?.message?.toLowerCase().includes('permission')) {
				errorMessage = 'Camera access was denied. Enable it in browser settings or use file scan.';
			} else if (e?.name === 'NotFoundError' || e?.message?.toLowerCase().includes('not found')) {
				errorMessage = 'No camera found.';
			} else {
				errorMessage = e?.message ?? 'Could not access camera.';
			}
			mode = showFileFallback ? 'file' : 'error';
		} finally {
			isLoading = false;
		}
	}

	async function onCameraChange(newId: string) {
		selectedCameraId = newId;
		try {
			await startCamera(newId);
			errorMessage = '';
		} catch (err) {
			errorMessage = (err as Error)?.message ?? 'Could not switch camera.';
		}
	}

	async function onFileSelected(event: Event) {
		const input = event.target as HTMLInputElement;
		const file = input.files?.[0];
		if (!file) return;
		try {
			const { Html5Qrcode } = await import('html5-qrcode');
			const html5 = new Html5Qrcode(containerId);
			const result = await html5.scanFileV2(file, false);
			handleScanSuccess(result.decodedText);
		} catch {
			errorMessage = 'No QR code found in image.';
		} finally {
			input.value = '';
		}
	}

	$effect(() => {
		if (active) {
			initScanner();
		} else {
			stopScanner();
			cameras = [];
			selectedCameraId = null;
			errorMessage = '';
		}
		return () => {
			stopScanner();
		};
	});

	onDestroy(() => {
		stopScanner();
	});
</script>

{#if active}
	<div class="flex flex-col gap-3 w-full max-w-[300px] mx-auto">
		{#if mode === 'camera' && cameras.length > 1}
			<div class="flex flex-col gap-1">
				<label for="qr-camera-select" class="text-xs font-medium text-surface-950/70">Camera</label>
				<select
					id="qr-camera-select"
					class="select rounded-container border border-surface-200 px-3 py-2 text-sm w-full bg-surface-50"
					disabled={isLoading}
					value={selectedCameraId ?? ''}
					onchange={(e) => {
						const v = (e.target as HTMLSelectElement).value;
						if (v) onCameraChange(v);
					}}
				>
					{#each cameras as cam (cam.id)}
						<option value={cam.id}>
							{cam.label || `Camera ${cameras.indexOf(cam) + 1}`}
						</option>
					{/each}
				</select>
			</div>
		{/if}

		<!-- Container always in DOM when active; visible only in camera mode -->
		<div
			id={containerId}
			class="overflow-hidden rounded-lg border border-surface-200 bg-surface-50 min-h-[250px] w-full relative {mode !== 'camera' ? 'hidden' : ''}"
		>
			{#if mode === 'camera' && isLoading}
				<div class="absolute inset-0 flex items-center justify-center bg-surface-50 z-10">
					<span class="text-surface-950/60 text-sm">Starting camera…</span>
				</div>
			{/if}
		</div>

		{#if mode === 'file' && showFileFallback}
			<div class="rounded-container border border-surface-200 bg-surface-50 p-4 flex flex-col gap-3">
				<p class="text-sm text-surface-950/80">{errorMessage || 'No camera available.'}</p>
				<label for={fileInputId} class="btn preset-filled-primary-500 cursor-pointer text-center">
					Scan from file
				</label>
			</div>
		{:else if mode === 'error'}
			<div
				class="rounded-container border border-error-300 bg-error-100 text-error-900 p-4 text-sm"
				role="alert"
			>
				{errorMessage}
			</div>
		{/if}

		{#if errorMessage && mode === 'camera' && showFileFallback}
			<div class="flex flex-col gap-2">
				<div
					class="rounded-container border border-warning-300 bg-warning-100 text-warning-900 p-3 text-sm"
					role="alert"
				>
					{errorMessage}
				</div>
				<label for={fileInputId} class="btn preset-tonal cursor-pointer text-center text-sm">
					Scan from file instead
				</label>
			</div>
		{/if}

		<input
			id={fileInputId}
			type="file"
			accept="image/*"
			class="hidden"
			aria-hidden="true"
			onchange={onFileSelected}
		/>
	</div>
{/if}
