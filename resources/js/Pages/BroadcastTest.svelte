<script>
	import { onMount } from 'svelte';

	let lastEvent = $state(null);
	let status = $state('idle'); // idle | listening | received | error
	let errorMessage = $state('');

	onMount(() => {
		if (typeof window === 'undefined' || !window.Echo) {
			status = 'error';
			errorMessage = 'Echo not available (check VITE_REVERB_APP_KEY and Reverb server).';
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

<main class="min-h-screen bg-base-200 flex flex-col items-center justify-center p-6">
	<div class="card bg-base-100 shadow-xl max-w-md w-full">
		<div class="card-body">
			<h1 class="card-title text-2xl text-primary">Reverb broadcast test</h1>
			<p class="text-base-content/80 text-sm mt-1">
				BD-002: Verify Laravel Reverb and Echo. Ensure <code class="bg-base-200 px-1 rounded">php artisan reverb:start</code> is running on port 6001.
			</p>

			{#if status === 'error'}
				<div class="alert alert-error mt-4">
					<span>{errorMessage}</span>
				</div>
			{:else}
				<div class="mt-4 flex flex-col gap-3">
					<button
						type="button"
						class="btn btn-primary"
						onclick={async () => {
							status = 'listening';
							lastEvent = null;
							try {
								const r = await window.axios.post('/broadcast-test');
								if (!r.data?.ok) status = 'error';
							} catch (e) {
								status = 'error';
								errorMessage = e?.message ?? 'Request failed';
							}
						}}
					>
						Fire test broadcast
					</button>

					{#if status === 'listening' && !lastEvent}
						<p class="text-sm text-base-content/70">Listening for event…</p>
					{/if}
					{#if lastEvent}
						<div class="alert alert-success">
							<p class="font-semibold">Received</p>
							<pre class="text-left text-sm mt-1 whitespace-pre-wrap">{JSON.stringify(lastEvent, null, 2)}</pre>
						</div>
					{/if}
				</div>
			{/if}

			<div class="mt-4 pt-4 border-t border-base-300">
				<a href="/" class="link link-primary text-sm">← Back to welcome</a>
			</div>
		</div>
	</div>
</main>
