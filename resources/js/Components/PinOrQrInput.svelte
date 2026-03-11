<script lang="ts">
	import Modal from './Modal.svelte';
	import QrScanner from './QrScanner.svelte';

	type Mode = 'pin' | 'qr' | 'request';

	let {
		disabled = false,
		mode = 'pin',
		pin = $bindable(''),
		qrScanToken = $bindable(''),
	} = $props<{
		disabled?: boolean;
		mode?: Mode;
		pin?: string;
		qrScanToken?: string;
	}>();

	let showScanner = $state(false);
	let scanHandled = $state(false);
	let lastScannedAt = $state<number | null>(null);

	$effect(() => {
		if (mode === 'pin') {
			qrScanToken = '';
			lastScannedAt = null;
		}
		if (mode === 'qr') {
			pin = '';
		}
	});

	function openScanner() {
		if (disabled) return;
		showScanner = true;
		scanHandled = false;
	}

	function closeScanner() {
		showScanner = false;
	}

	function clearScanned() {
		qrScanToken = '';
		lastScannedAt = null;
	}

	function handleQrScan(decodedText: string) {
		if (scanHandled) return;
		scanHandled = true;
		qrScanToken = decodedText.trim();
		lastScannedAt = Date.now();
		showScanner = false;
	}

	export function buildPinOrQrPayload(): { pin: string } | { qr_scan_token: string } | null {
		if (mode === 'pin') {
			const v = pin.trim();
			if (!/^\d{6}$/.test(v)) return null;
			return { pin: v };
		}
		if (mode === 'qr') {
			const t = String(qrScanToken ?? '').trim();
			if (!t) return null;
			return { qr_scan_token: t };
		}
		return null;
	}
</script>

{#if mode === 'pin'}
	<label class="flex flex-col gap-1">
		<span class="text-sm font-medium text-surface-950">PIN (6 digits)</span>
		<input
			type="text"
			inputmode="numeric"
			autocomplete="off"
			maxlength="6"
			class="input input-sm bg-surface-50 border border-surface-300 rounded-container font-mono w-full max-w-[8rem]"
			placeholder="000000"
			bind:value={pin}
			oninput={(e) => { pin = (e.currentTarget.value || '').replace(/\D/g, '').slice(0, 6); }}
			disabled={disabled}
		/>
	</label>
{:else if mode === 'qr'}
	<div class="flex flex-col gap-2">
		<span class="text-sm font-medium text-surface-950">Scan QR token</span>
		{#if qrScanToken}
			<div class="rounded-container border border-surface-200 bg-surface-50 p-3 flex items-center justify-between gap-2">
				<div class="flex flex-col">
					<span class="text-sm text-surface-950/90">Scanned</span>
					{#if lastScannedAt}
						<span class="text-xs text-surface-950/60">{new Date(lastScannedAt).toLocaleString()}</span>
					{/if}
				</div>
				<div class="flex gap-2">
					<button type="button" class="btn preset-tonal text-sm" onclick={clearScanned} disabled={disabled}>Clear</button>
					<button type="button" class="btn preset-filled-primary-500 text-sm" onclick={openScanner} disabled={disabled}>Rescan</button>
				</div>
			</div>
		{:else}
			<button type="button" class="btn preset-filled-primary-500 touch-target-h" onclick={openScanner} disabled={disabled}>
				Scan QR token
			</button>
		{/if}
	</div>
{/if}

<Modal open={showScanner} title="Scan authorization QR" onClose={closeScanner} wide={true}>
	{#snippet children()}
		<div class="flex flex-col gap-3 w-full min-w-[20rem] mx-auto">
			<QrScanner active={showScanner} cameraOnly={true} onScan={handleQrScan} />
			<button
				type="button"
				class="w-full py-3 text-base font-semibold rounded-container border-2 border-surface-300 bg-surface-50 text-surface-950 shadow-md hover:bg-surface-200 focus:ring-2 focus:ring-offset-2 focus:ring-surface-400"
				onclick={closeScanner}
			>
				Cancel
			</button>
		</div>
	{/snippet}
</Modal>

