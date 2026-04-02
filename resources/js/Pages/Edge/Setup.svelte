<script lang="ts">
	import { useForm } from '@inertiajs/svelte';
	import AuthLayout from '../../Layouts/AuthLayout.svelte';

	let step = $state(1);
	let centralUrl = $state('https://flexiqueue.click');
	let pairingCode = $state('');
	let syncMode = $state<'auto' | 'end_of_event'>('auto');
	let pingStatus = $state<'idle' | 'checking' | 'ok' | 'fail'>('idle');
	let pingError = $state('');

	const form = useForm({
		central_url: '',
		pairing_code: '',
		sync_mode: 'auto' as 'auto' | 'end_of_event',
	});

	async function testConnection() {
		pingStatus = 'checking';
		pingError = '';
		try {
			const res = await fetch(
				`/edge/setup/ping-check?url=${encodeURIComponent(centralUrl)}`
			);
			const data = await res.json();
			pingStatus = data.reachable ? 'ok' : 'fail';
			if (!data.reachable) {
				pingError = 'Could not reach the central server. Check the URL and your network connection.';
			}
		} catch {
			pingStatus = 'fail';
			pingError = 'Request failed. Check your network connection.';
		}
	}

	function goToStep2() {
		if (pingStatus !== 'ok') {
			pingError = 'Please verify the connection before continuing.';
			return;
		}
		step = 2;
	}

	function submit() {
		$form.central_url = centralUrl;
		$form.pairing_code = pairingCode.toUpperCase();
		$form.sync_mode = syncMode;
		$form.post('/edge/setup');
	}
</script>

<svelte:head>
	<title>Edge Setup — FlexiQueue</title>
</svelte:head>

<AuthLayout>
	<main class="min-h-screen flex flex-col items-center justify-center p-6">
		<div class="card bg-surface-50 rounded-container shadow-xl max-w-lg w-full p-8">
			<h1 class="text-2xl font-bold text-primary-500 text-center mb-1">FlexiQueue Edge Setup</h1>
			<p class="text-sm text-center text-surface-500 mb-6">Step {step} of 3</p>

			<div class="flex gap-2 mb-8">
				{#each [1, 2, 3] as s}
					<div class="flex-1 h-1.5 rounded-full {s <= step ? 'bg-primary-500' : 'bg-surface-200'}"></div>
				{/each}
			</div>

			{#if $form.errors?.pairing_code}
				<div class="mb-4 rounded-container border border-error-500/40 bg-error-50 px-3 py-2 text-sm text-error-800 dark:bg-error-950/30 dark:text-error-200" role="alert">
					{$form.errors.pairing_code}
				</div>
			{/if}

			{#if step === 1}
				<h2 class="text-lg font-semibold mb-2">Central Server URL</h2>
				<p class="text-sm text-surface-600 mb-4">Enter the address of your FlexiQueue central server.</p>
				<label for="central_url" class="block text-sm font-medium text-surface-950 mb-1">Central URL</label>
				<input
					id="central_url"
					type="url"
					class="input w-full rounded-container border border-surface-200 px-3 py-2 mb-3"
					bind:value={centralUrl}
					placeholder="https://flexiqueue.click"
				/>
				{#if pingError}
					<p class="text-sm text-error-600 mb-3">{pingError}</p>
				{/if}
				{#if pingStatus === 'ok'}
					<p class="text-sm text-success-600 mb-3">Connection verified.</p>
				{/if}
				<div class="flex gap-3 mt-2">
					<button
						type="button"
						class="btn preset-outlined-surface-200 flex-1"
						onclick={testConnection}
						disabled={pingStatus === 'checking'}
					>
						{pingStatus === 'checking' ? 'Checking…' : 'Test Connection'}
					</button>
					<button
						type="button"
						class="btn preset-filled-primary-500 flex-1"
						onclick={goToStep2}
						disabled={pingStatus !== 'ok'}
					>
						Next
					</button>
				</div>
			{/if}

			{#if step === 2}
				<h2 class="text-lg font-semibold mb-2">Pairing Code</h2>
				<p class="text-sm text-surface-600 mb-4">
					Go to <strong>Site Settings → Edge Devices → Add Device</strong> on your central server and enter the 8-character code shown there.
				</p>
				<label for="pairing_code" class="block text-sm font-medium text-surface-950 mb-1">Pairing Code</label>
				<input
					id="pairing_code"
					type="text"
					maxlength="8"
					class="input w-full rounded-container border border-surface-200 px-3 py-2 mb-3 uppercase font-mono tracking-widest text-center text-xl"
					bind:value={pairingCode}
					placeholder="ABCD1234"
					autocomplete="off"
					spellcheck={false}
				/>
				<div class="flex gap-3 mt-2">
					<button type="button" class="btn preset-outlined-surface-200 flex-1" onclick={() => (step = 1)}>Back</button>
					<button
						type="button"
						class="btn preset-filled-primary-500 flex-1"
						onclick={() => { if (pairingCode.length === 8) step = 3; }}
						disabled={pairingCode.length !== 8}
					>
						Next
					</button>
				</div>
			{/if}

			{#if step === 3}
				<h2 class="text-lg font-semibold mb-2">Sync Mode</h2>
				<p class="text-sm text-surface-600 mb-4">How should this device sync data with the central server?</p>
				<fieldset class="flex flex-col gap-3 mb-6">
					<label class="flex items-start gap-3 p-3 rounded-container border cursor-pointer {syncMode === 'auto' ? 'border-primary-500 bg-primary-50 dark:bg-primary-950/20' : 'border-surface-200'}">
						<input type="radio" bind:group={syncMode} value="auto" class="mt-0.5" />
						<div>
							<span class="font-medium text-sm">Auto (recommended)</span>
							<p class="text-xs text-surface-500 mt-0.5">Data is saved locally and pushed to the central server in real time. Best when internet is reliable.</p>
						</div>
					</label>
					<label class="flex items-start gap-3 p-3 rounded-container border cursor-pointer {syncMode === 'end_of_event' ? 'border-primary-500 bg-primary-50 dark:bg-primary-950/20' : 'border-surface-200'}">
						<input type="radio" bind:group={syncMode} value="end_of_event" class="mt-0.5" />
						<div>
							<span class="font-medium text-sm">End of Event</span>
							<p class="text-xs text-surface-500 mt-0.5">Data is saved locally only. Everything syncs to the central server after the session ends. Best for unreliable or no internet.</p>
						</div>
					</label>
				</fieldset>
				<div class="flex gap-3">
					<button type="button" class="btn preset-outlined-surface-200 flex-1" onclick={() => (step = 2)}>Back</button>
					<button
						type="button"
						class="btn preset-filled-primary-500 flex-1"
						onclick={submit}
						disabled={$form.processing}
					>
						{$form.processing ? 'Connecting…' : 'Connect Device'}
					</button>
				</div>
			{/if}
		</div>
	</main>
</AuthLayout>
