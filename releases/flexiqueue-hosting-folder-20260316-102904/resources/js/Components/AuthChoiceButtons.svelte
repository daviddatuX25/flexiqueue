<script lang="ts">
	type Mode = 'pin' | 'qr' | 'request';

	let {
		disabled = false,
		includeRequest = false,
		mode = $bindable<Mode>('pin'),
	} = $props<{
		disabled?: boolean;
		includeRequest?: boolean;
		mode?: Mode;
	}>();

	$effect(() => {
		if (!includeRequest && mode === 'request') mode = 'pin';
	});
</script>

<div class="join join-horizontal w-full" role="group" aria-label="Authorization method">
	<button
		type="button"
		class="btn btn-sm flex-1 touch-target-h {mode === 'pin' ? 'preset-filled-primary-500' : 'preset-tonal'}"
		disabled={disabled}
		onclick={() => (mode = 'pin')}
		title="Enter 6-digit code"
	>
		PIN
	</button>
	<button
		type="button"
		class="btn btn-sm flex-1 touch-target-h {mode === 'qr' ? 'preset-filled-primary-500' : 'preset-tonal'}"
		disabled={disabled}
		onclick={() => (mode = 'qr')}
		title="Scan QR"
	>
		QR
	</button>
	{#if includeRequest}
		<button
			type="button"
			class="btn btn-sm flex-1 touch-target-h {mode === 'request' ? 'preset-filled-primary-500' : 'preset-tonal'}"
			disabled={disabled}
			onclick={() => (mode = 'request')}
			title="Request supervisor approval"
		>
			Request
		</button>
	{/if}
</div>

