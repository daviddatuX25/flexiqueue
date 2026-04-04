<script lang="ts">
	import { onMount, onDestroy } from 'svelte';
	import { router } from '@inertiajs/svelte';
	import AuthLayout from '../../Layouts/AuthLayout.svelte';

	let { siteName = 'your central server' } = $props<{ siteName?: string }>();

	let dots = $state('');
	let dotsInterval: ReturnType<typeof setInterval>;
	let pollInterval: ReturnType<typeof setInterval>;

	async function pollAssignment() {
		try {
			const res = await fetch('/edge/waiting-status');
			const data = await res.json();
			if (data.assigned) {
				router.visit('/');
			}
		} catch {
			// Silently ignore — retry on next tick
		}
	}

	onMount(() => {
		dotsInterval = setInterval(() => {
			dots = dots.length >= 3 ? '' : dots + '.';
		}, 500);
		pollAssignment();
		pollInterval = setInterval(pollAssignment, 15_000);
	});

	onDestroy(() => {
		clearInterval(dotsInterval);
		clearInterval(pollInterval);
	});
</script>

<svelte:head>
	<title>Edge Waiting — FlexiQueue</title>
</svelte:head>

<AuthLayout>
	<main class="min-h-screen flex flex-col items-center justify-center p-6">
		<div class="card bg-surface-50 rounded-container shadow-xl max-w-md w-full p-8 text-center">
			<div class="flex justify-center mb-6">
				<svg class="animate-spin h-12 w-12 text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
					<circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
					<path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
				</svg>
			</div>
			<h1 class="text-xl font-bold text-primary-500 mb-2">FlexiQueue Edge</h1>
			<p class="text-surface-700 dark:text-slate-300 mb-1">
				Paired to <strong>{siteName}</strong>.
			</p>
			<p class="text-surface-500 text-sm">
				Awaiting program assignment from your administrator{dots}
			</p>
			<div class="mt-6 text-xs text-surface-400">
				Checking for assignment automatically. This page will redirect when a program is assigned.
			</div>
		</div>
	</main>
</AuthLayout>
