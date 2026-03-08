<script>
	import { onMount } from 'svelte';
	import AuthLayout from '../Layouts/AuthLayout.svelte';
	import { toaster } from '../lib/toaster.js';

	let lastEvent = $state(null);
	let status = $state('idle'); // idle | listening | received | error

	onMount(() => {
		if (typeof window === 'undefined' || !window.Echo) {
			status = 'error';
			toaster.error({ title: 'Echo not available (check VITE_REVERB_APP_KEY and Reverb server).' });
			return;
		}

		status = 'listening';
		window.Echo.channel('test-channel').listen('.TestBroadcast', (e) => {
			lastEvent = e;
			status = 'received';
		});
	});
</script>

<svelte:head>
	<title>Broadcast test — FlexiQueue</title>
</svelte:head>

<AuthLayout>
<main class="min-h-screen bg-surface-100 flex flex-col items-center justify-center p-6">
	<div class="card bg-surface-50 shadow-xl max-w-md w-full">
		<div class="p-6">
			<h1 class="font-bold text-2xl text-primary-500">Reverb broadcast test</h1>
			<p class="text-surface-950/80 text-sm mt-1">
				BD-002: Verify Laravel Reverb and Echo. Ensure <code class="bg-surface-100 px-1 rounded">php artisan reverb:start</code> is running on port 6001.
			</p>

			{#if status === 'error'}
				<p role="alert" class="text-sm text-error-600 mt-4">Echo not available. See toast for details.</p>
			{:else}
				<div class="mt-4 flex flex-col gap-3">
					<button
						type="button"
						class="btn preset-filled-primary-500 touch-target-h"
						onclick={async () => {
							status = 'listening';
							lastEvent = null;
							try {
								const r = await window.axios.post('/broadcast-test');
								if (!r.data?.ok) {
									status = 'error';
									toaster.error({ title: 'Broadcast test failed.' });
								}
							} catch (e) {
								status = 'error';
								toaster.error({ title: e?.message ?? 'Request failed' });
							}
						}}
					>
						Fire test broadcast
					</button>

					{#if status === 'listening' && !lastEvent}
						<p class="text-sm text-surface-950/70">Listening for event…</p>
					{/if}
					{#if lastEvent}
						<div class="bg-success-100 text-success-900 border border-success-300 rounded-container p-4">
							<p class="font-semibold">Received</p>
							<pre class="text-left text-sm mt-1 whitespace-pre-wrap">{JSON.stringify(lastEvent, null, 2)}</pre>
						</div>
					{/if}
				</div>
			{/if}

			<div class="mt-4 pt-4 border-t border-surface-200">
				<a href="/" class="link link-primary text-sm touch-target-h inline-flex items-center">← Back to welcome</a>
			</div>
		</div>
	</div>
</main>
</AuthLayout>
