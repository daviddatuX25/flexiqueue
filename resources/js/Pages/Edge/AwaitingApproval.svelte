<script lang="ts">
	import { onMount, onDestroy } from 'svelte';
	import { router } from '@inertiajs/svelte';
	import AuthLayout from '../../Layouts/AuthLayout.svelte';

	let dots = $state('');
	let dotsInterval: ReturnType<typeof setInterval>;
	let pollInterval: ReturnType<typeof setInterval>;
	let rejected = $state(false);
	let claiming = $state(false);
	let claimError = $state('');

	async function pollApprovalStatus() {
		try {
			const res = await fetch('/edge/approval-status');
			const data = await res.json();

			if (data.status === 'approved') {
				clearInterval(pollInterval);
				await claimToken();
				return;
			}

			if (data.status === 'rejected') {
				rejected = true;
				clearInterval(pollInterval);
			}
		} catch {
			// Silently ignore — retry on next tick
		}
	}

	async function claimToken() {
		claiming = true;
		claimError = '';
		try {
			// POST to claim-token which redirects on success
			const form = document.createElement('form');
			form.method = 'POST';
			form.action = '/edge/claim-token';
			const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';
			const csrfInput = document.createElement('input');
			csrfInput.type = 'hidden';
			csrfInput.name = '_token';
			csrfInput.value = csrfToken;
			form.appendChild(csrfInput);
			document.body.appendChild(form);
			form.submit();
		} catch {
			claimError = 'Network error while claiming token.';
			router.visit('/edge/setup');
		} finally {
			claiming = false;
		}
	}

	function handleTryAgain() {
		router.visit('/edge/setup');
	}

	onMount(() => {
		dotsInterval = setInterval(() => {
			dots = dots.length >= 3 ? '' : dots + '.';
		}, 500);
		pollApprovalStatus();
		pollInterval = setInterval(pollApprovalStatus, 15_000);
	});

	onDestroy(() => {
		clearInterval(dotsInterval);
		clearInterval(pollInterval);
	});
</script>

<svelte:head>
	<title>Awaiting Approval — FlexiQueue</title>
</svelte:head>

<AuthLayout>
	<main class="min-h-screen flex flex-col items-center justify-center p-6">
		<div class="card bg-surface-50 rounded-container shadow-xl max-w-md w-full p-8 text-center">
			{#if rejected}
				<div class="flex justify-center mb-6">
					<svg class="h-12 w-12 text-error-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
						<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15h.007v.007H12V15Z" />
					</svg>
				</div>
				<h1 class="text-xl font-bold text-error-500 mb-2">Pairing Rejected</h1>
				<p class="text-surface-700 dark:text-slate-300 mb-1">
					The site administrator has rejected this device's pairing request.
				</p>
				<p class="text-surface-500 text-sm mb-6">
					You can try again with a new pairing code.
				</p>
				<button
					type="button"
					class="btn preset-filled-primary-500 w-full"
					onclick={handleTryAgain}
				>
					Try Again
				</button>
			{:else}
				<div class="flex justify-center mb-6">
					<svg class="animate-spin h-12 w-12 text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
						<circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
						<path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
					</svg>
				</div>
				<h1 class="text-xl font-bold text-primary-500 mb-2">FlexiQueue Edge</h1>
				<p class="text-surface-700 dark:text-slate-300 mb-1">
					Awaiting admin approval{dots}
				</p>
				<p class="text-surface-500 text-sm">
					Your pairing request has been submitted. This page will redirect automatically when approved.
				</p>
				<div class="mt-6 text-xs text-surface-400">
					Checking for approval status automatically.
				</div>
			{/if}
		</div>
	</main>
</AuthLayout>