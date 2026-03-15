<script lang="ts">
	import Modal from './Modal.svelte';
	import QrScanner from './QrScanner.svelte';

	type AuthType = 'preset_pin' | 'temp_pin' | 'preset_qr' | 'temp_qr';

	let {
		disabled = false,
		defaultAuthType = 'preset_pin',
		// Feature flags so callers can hide QR options when camera scanning is disabled by program settings.
		allowQr = true,
		// Bindable outputs (callers can `bind:*` to read values)
		authType = $bindable<AuthType>('preset_pin'),
		supervisorPin = $bindable(''),
		tempCode = $bindable(''),
		qrScanToken = $bindable(''),
	} = $props<{
		disabled?: boolean;
		defaultAuthType?: AuthType;
		allowQr?: boolean;
		authType?: AuthType;
		supervisorPin?: string;
		tempCode?: string;
		qrScanToken?: string;
	}>();

	$effect(() => {
		// Keep a sensible default even if caller doesn't bind authType.
		if (!authType) authType = defaultAuthType;
	});

	let showQrScanner = $state(false);
	let qrScanHandled = $state(false);
	let lastScannedAt = $state<number | null>(null);

	$effect(() => {
		// Reset dependent inputs when switching auth type
		if (authType === 'preset_pin') {
			tempCode = '';
			qrScanToken = '';
			lastScannedAt = null;
		} else if (authType === 'temp_pin') {
			supervisorPin = '';
			qrScanToken = '';
			lastScannedAt = null;
		} else {
			supervisorPin = '';
			tempCode = '';
		}
	});

	function openScanner() {
		if (disabled) return;
		showQrScanner = true;
		qrScanHandled = false;
	}

	function closeScanner() {
		showQrScanner = false;
	}

	function clearScanned() {
		qrScanToken = '';
		lastScannedAt = null;
	}

	function handleQrScan(decodedText: string) {
		if (qrScanHandled) return;
		qrScanHandled = true;
		qrScanToken = decodedText.trim();
		lastScannedAt = Date.now();
		showQrScanner = false;
	}

	export function buildAuthPayload(): Record<string, unknown> | null {
		if (authType === 'preset_pin') {
			const pin = supervisorPin.trim();
			if (!/^\d{6}$/.test(pin)) return null;
			return { auth_type: 'preset_pin', supervisor_pin: pin };
		}
		if (authType === 'temp_pin') {
			const code = tempCode.trim();
			if (!/^\d{6}$/.test(code)) return null;
			// Keep legacy alias for server compatibility (`pin` => temp_pin)
			return { auth_type: 'pin', temp_code: code };
		}
		// QR modes are scanner-only: token must be present in state.
		const token = String(qrScanToken ?? '').trim();
		if (!token) return null;
		// Keep legacy alias for server compatibility (`qr` => temp_qr)
		return authType === 'preset_qr'
			? { auth_type: 'preset_qr', qr_scan_token: token }
			: { auth_type: 'qr', qr_scan_token: token };
	}
</script>

<div class="flex flex-col gap-3">
	<label class="flex flex-col gap-1">
		<span class="text-sm font-medium text-surface-950">Authorization method</span>
		<select
			class="select select-sm bg-surface-50 border border-surface-300 rounded-container w-full max-w-xs"
			bind:value={authType}
			disabled={disabled}
		>
			<option value="preset_pin">Supervisor/Admin PIN</option>
			<option value="temp_pin">Temporary code</option>
			{#if allowQr}
				<option value="preset_qr">Preset QR token</option>
				<option value="temp_qr">Temporary QR token</option>
			{/if}
		</select>
	</label>

	{#if authType === 'preset_pin'}
		<label class="flex flex-col gap-1">
			<span class="text-sm font-medium text-surface-950">PIN (6 digits)</span>
			<input
				type="text"
				inputmode="numeric"
				autocomplete="off"
				maxlength="6"
				class="input input-sm bg-surface-50 border border-surface-300 rounded-container font-mono w-full max-w-[8rem]"
				placeholder="000000"
				bind:value={supervisorPin}
				oninput={(e) => { supervisorPin = (e.currentTarget.value || '').replace(/\D/g, '').slice(0, 6); }}
				disabled={disabled}
			/>
		</label>
	{:else if authType === 'temp_pin'}
		<label class="flex flex-col gap-1">
			<span class="text-sm font-medium text-surface-950">Temporary code (6 digits)</span>
			<input
				type="text"
				inputmode="numeric"
				autocomplete="off"
				maxlength="6"
				class="input input-sm bg-surface-50 border border-surface-300 rounded-container font-mono w-full max-w-[8rem]"
				placeholder="000000"
				bind:value={tempCode}
				oninput={(e) => { tempCode = (e.currentTarget.value || '').replace(/\D/g, '').slice(0, 6); }}
				disabled={disabled}
			/>
		</label>
	{:else}
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
						<button type="button" class="btn preset-tonal text-sm" onclick={clearScanned} disabled={disabled}>
							Clear
						</button>
						<button type="button" class="btn preset-filled-primary-500 text-sm" onclick={openScanner} disabled={disabled}>
							Rescan
						</button>
					</div>
				</div>
			{:else}
				<button type="button" class="btn preset-filled-primary-500 touch-target-h" onclick={openScanner} disabled={disabled}>
					Scan QR token
				</button>
			{/if}
		</div>
	{/if}
</div>

<Modal open={showQrScanner} title="Scan authorization QR" onClose={closeScanner} wide={true}>
	{#snippet children()}
		<div class="flex flex-col gap-3 w-full min-w-[20rem] mx-auto">
			<QrScanner active={showQrScanner} cameraOnly={true} onScan={handleQrScan} />
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

