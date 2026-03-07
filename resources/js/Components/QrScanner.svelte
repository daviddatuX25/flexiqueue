<script lang="ts">
	/**
	 * QR and barcode scanner. When active, calls onScan(decodedText) on success.
	 * Scanning includes: (a) camera/file decoding via html5-qrcode (QR + barcode), and (b) keyboard-wedge (HID)
	 * barcode scanner input — a dedicated input is focused when active so hardware scanners that "type" + Enter work.
	 * Supports: QR + barcode (explicit formatsToSupport), camera selection (multi-camera), last camera persisted in
	 * localStorage (flexiqueue_last_camera_id), file-scan fallback (no camera), optional sound on scan.
	 */
	import { onDestroy, tick } from 'svelte';

	interface CameraDevice {
		id: string;
		label: string;
	}

	let {
		active = false,
		onScan = () => {},
		soundOnScan = false,
		showFileFallback = true,
		/** When true (e.g. display modal): no barcode input/label; only camera dropdown, video, file fallback, errors. */
		cameraOnly = false,
	} = $props();

	let containerId = 'qr-reader-' + Math.random().toString(36).slice(2, 9);
	let fileInputId = 'qr-file-' + Math.random().toString(36).slice(2, 9);
	let html5Qrcode: import('html5-qrcode').Html5Qrcode | null = null;

	let cameras = $state<CameraDevice[]>([]);
	let selectedCameraId = $state<string | null>(null);
	let errorMessage = $state('');
	let isLoading = $state(false);
	let mode = $state<'camera' | 'file' | 'error'>('camera');
	let barcodeInputValue = $state('');
	let barcodeInputEl = $state<HTMLInputElement | null>(null);

	// html5-qrcode requires qrbox dimensions >= 50px; function avoids error on small viewports (mobile).
	const SCAN_CONFIG = {
		fps: 5,
		qrbox: (viewfinderWidth: number, viewfinderHeight: number) => ({
			width: Math.max(50, Math.min(250, viewfinderWidth || 250)),
			height: Math.max(50, Math.min(250, viewfinderHeight || 250)),
		}),
	} as const;
	const STORAGE_KEY = 'flexiqueue_last_camera_id';

	/** Prefer back camera on mobile (Android/iOS). */
	const FACING_ENVIRONMENT = { facingMode: 'environment' } as const;
	const FACING_USER = { facingMode: 'user' } as const;

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

	function onBarcodeInputKeydown(e: KeyboardEvent) {
		if (e.key !== 'Enter') return;
		const raw = barcodeInputValue.trim();
		if (raw) {
			e.preventDefault();
			handleScanSuccess(raw);
			barcodeInputValue = '';
			barcodeInputEl?.focus();
		}
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

	async function startCamera(
		cameraIdOrConfig: string | { facingMode: string },
		configOverride?: { videoConstraints?: MediaTrackConstraints }
	) {
		await stopScanner();
		if (!document.getElementById(containerId)) return;
		const { Html5Qrcode, Html5QrcodeSupportedFormats } = await import('html5-qrcode');
		const scannerConfig = {
			formatsToSupport: [
				Html5QrcodeSupportedFormats.QR_CODE,
				Html5QrcodeSupportedFormats.CODE_128,
				Html5QrcodeSupportedFormats.EAN_13,
				Html5QrcodeSupportedFormats.EAN_8,
				Html5QrcodeSupportedFormats.UPC_A,
				Html5QrcodeSupportedFormats.CODE_39,
			],
		};
		html5Qrcode = new Html5Qrcode(containerId, scannerConfig);
		const scanConfig = configOverride
			? { ...SCAN_CONFIG, ...configOverride }
			: SCAN_CONFIG;
		await html5Qrcode.start(
			cameraIdOrConfig,
			scanConfig,
			(decodedText) => handleScanSuccess(decodedText),
			() => {}
		);
	}

	async function stopScanner() {
		if (!html5Qrcode) return;
		try {
			if (html5Qrcode.isScanning) {
				await html5Qrcode.stop();
			}
		} catch {
			// html5-qrcode can throw "Cannot transition to a new state, already under transition"
			// when stop() is called while start() is still in progress (e.g. user closes modal during init).
			// Swallow so teardown (effect cleanup, onDestroy) doesn't leave unhandled rejections.
		} finally {
			html5Qrcode = null;
		}
	}

	/** Fallback: no constraints — maximal compatibility when facingMode fails (per html5-qrcode#164). */
	const VIDEO_ANY = { videoConstraints: {} } as const;

	/**
	 * On Android (and some mobile browsers), getCameras() returns [] until camera permission is granted.
	 * Try starting with facingMode first to trigger the permission prompt; then enumerate to get the list.
	 */
	async function tryStartWithFacingMode(): Promise<boolean> {
		try {
			await startCamera(FACING_ENVIRONMENT);
			mode = 'camera';
			// After permission, enumerate so we can show "Switch camera" and get device IDs.
			const list = await loadCameras();
			if (list.length > 0) {
				cameras = list;
				selectedCameraId = list[0]?.id ?? null;
				if (selectedCameraId && typeof localStorage !== 'undefined') {
					localStorage.setItem(STORAGE_KEY, selectedCameraId);
				}
			}
			return true;
		} catch {
			try {
				await startCamera(FACING_USER);
				mode = 'camera';
				const list = await loadCameras();
				if (list.length > 0) {
					cameras = list;
					selectedCameraId = list[0]?.id ?? null;
				}
				return true;
			} catch {
				try {
					// Maximal compatibility: no facingMode constraint (some devices reject both).
					await startCamera('fallback', VIDEO_ANY);
					mode = 'camera';
					const list = await loadCameras();
					if (list.length > 0) {
						cameras = list;
						selectedCameraId = list[0]?.id ?? null;
					}
					return true;
				} catch {
					return false;
				}
			}
		}
	}

	/** Message when camera is blocked due to non-secure context (HTTP on mobile). */
	function getInsecureContextMessage(): string {
		const base = 'Camera requires a secure connection (HTTPS).';
		try {
			const host = typeof window !== 'undefined' ? window.location.host : '';
			if (host) {
				return `${base} Open this page via https://${host} or use "Scan from file".`;
			}
		} catch {
			// ignore
		}
		return `${base} Use "Scan from file" or open the page via HTTPS.`;
	}

	async function initScanner() {
		if (!active) return;
		errorMessage = '';
		isLoading = true;
		mode = 'camera';

		// Per plan: detect non-secure context (e.g. HTTP on mobile) so we show a clear message instead of generic "No camera available".
		if (typeof window !== 'undefined' && (window.isSecureContext === false || typeof navigator?.mediaDevices === 'undefined')) {
			isLoading = false;
			errorMessage = getInsecureContextMessage();
			mode = showFileFallback ? 'file' : 'error';
			return;
		}

		try {
			let list = await loadCameras();
			// Mobile (e.g. Android): enumeration often empty until permission granted. Try starting with constraint first.
			if (list.length === 0) {
				const started = await tryStartWithFacingMode();
				if (!started) {
					errorMessage = 'No camera found or permission denied.';
					mode = showFileFallback ? 'file' : 'error';
				}
				return;
			}
			cameras = list;
			const savedId =
				typeof localStorage !== 'undefined' ? localStorage.getItem(STORAGE_KEY) ?? '' : '';
			const idInList = savedId && list.some((c) => c.id === savedId);
			selectedCameraId = idInList ? savedId : (pickPreferredCamera(list) || list[0]?.id) ?? null;
			// Allow camera release on mobile (getCameras acquires then releases; slow devices need time).
			await new Promise((r) => setTimeout(r, 250));
			await startCamera(selectedCameraId);
			if (selectedCameraId && typeof localStorage !== 'undefined') {
				localStorage.setItem(STORAGE_KEY, selectedCameraId);
			}
			mode = 'camera';
		} catch (err) {
			const e = err as { name?: string; message?: string };
			if (e?.name === 'NotAllowedError' || e?.message?.toLowerCase().includes('permission')) {
				errorMessage = 'Camera access was denied. Enable it in browser settings or use file scan.';
			} else if (e?.name === 'NotFoundError' || e?.message?.toLowerCase().includes('not found')) {
				errorMessage = 'No camera found.';
			} else if (
				e?.name === 'NotReadableError' ||
				e?.message?.toLowerCase().includes('could not start') ||
				e?.message?.toLowerCase().includes('in use')
			) {
				errorMessage =
					'Camera is in use elsewhere. Close other apps using the camera and try again.';
			} else if (e?.name === 'OverconstrainedError') {
				errorMessage = 'Camera constraints not supported. Try a different camera or file scan.';
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
			if (typeof localStorage !== 'undefined') {
				localStorage.setItem(STORAGE_KEY, newId);
			}
			if (!cameraOnly) {
				await tick();
				barcodeInputEl?.focus();
			}
		} catch (err) {
			const e = err as { name?: string; message?: string };
			if (e?.name === 'NotReadableError' || e?.message?.toLowerCase().includes('in use')) {
				errorMessage =
					'Camera is in use elsewhere. Close other apps and try again.';
			} else if (e?.name === 'OverconstrainedError') {
				errorMessage = 'Camera constraints not supported. Try a different camera.';
			} else {
				errorMessage = e?.message ?? 'Could not switch camera.';
			}
		}
	}

	async function onFileSelected(event: Event) {
		const input = event.target as HTMLInputElement;
		const file = input.files?.[0];
		if (!file) return;
		try {
			const { Html5Qrcode, Html5QrcodeSupportedFormats } = await import('html5-qrcode');
			const scannerConfig = {
				formatsToSupport: [
					Html5QrcodeSupportedFormats.QR_CODE,
					Html5QrcodeSupportedFormats.CODE_128,
					Html5QrcodeSupportedFormats.EAN_13,
					Html5QrcodeSupportedFormats.EAN_8,
					Html5QrcodeSupportedFormats.UPC_A,
					Html5QrcodeSupportedFormats.CODE_39,
				],
			};
			const html5 = new Html5Qrcode(containerId, scannerConfig);
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
			// When used in a modal (cameraOnly), wait one frame so the dialog is visible and the container has dimensions.
			const start = () => initScanner();
			const id = cameraOnly
				? requestAnimationFrame(() => requestAnimationFrame(start))
				: requestAnimationFrame(start);
			return () => {
				cancelAnimationFrame(id);
				stopScanner();
			};
		} else {
			stopScanner();
			cameras = [];
			selectedCameraId = null;
			errorMessage = '';
			barcodeInputValue = '';
		}
		return () => {
			stopScanner();
		};
	});

	$effect(() => {
		if (cameraOnly || !active || !barcodeInputEl) return;
		const el = barcodeInputEl;
		tick().then(() => el?.focus());
	});

	onDestroy(() => {
		stopScanner();
	});
</script>

{#if active}
	<div class="flex flex-col gap-3 w-full mx-auto {cameraOnly ? 'max-w-xl' : 'max-w-[300px]'}">
		{#if !cameraOnly}
			<!-- HID barcode scanner: input is visually hidden so typed stream is not shown; still focusable so hardware scanner works -->
			<div class="flex flex-col gap-1 relative">
				<label for="qr-barcode-input" class="text-xs font-medium text-surface-950/70 cursor-pointer hover:text-surface-950">Scan with barcode scanner</label>
				<input
					id="qr-barcode-input"
					type="text"
					autocomplete="off"
					inputmode="text"
					aria-label="Barcode scanner input; scan with hardware scanner or type and press Enter"
					class="sr-only"
					bind:value={barcodeInputValue}
					bind:this={barcodeInputEl}
					onkeydown={onBarcodeInputKeydown}
				/>
				<span class="text-xs text-surface-950/60">Or use camera below</span>
			</div>
		{/if}
		{#if mode === 'camera' && cameras.length >= 1}
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
			class="overflow-hidden rounded-lg border border-surface-200 bg-surface-50 w-full relative {mode !== 'camera' ? 'hidden' : ''} {cameraOnly ? 'min-h-[320px]' : 'min-h-[250px]'}"
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
