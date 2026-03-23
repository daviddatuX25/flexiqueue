<script lang="ts">
	import { useForm } from '@inertiajs/svelte';
	import AuthLayout from '../../Layouts/AuthLayout.svelte';
	import AppBackground from '../../Components/AppBackground.svelte';

	let {
		status = null,
		error = null
	} = $props();

	const heroImageUrl = '/images/mswdo_tagudin.jpg';

	const form = useForm({
		username: ''
	});
</script>

<svelte:head>
	<title>Forgot password — FlexiQueue</title>
</svelte:head>

<AuthLayout>
	<AppBackground heroImageUrl={heroImageUrl} />
	<main class="min-h-screen flex flex-col items-center justify-center p-6 relative">
		<a
			href="/login"
			class="absolute top-4 left-4 sm:top-6 sm:left-6 inline-flex items-center gap-2 text-sm font-medium text-surface-600 hover:text-surface-950 dark:text-slate-400 dark:hover:text-slate-100 transition-colors no-underline"
			aria-label="Back to sign in"
		>
			<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
			</svg>
			Back to sign in
		</a>
		<div class="card bg-surface-50 rounded-container shadow-xl max-w-md w-full p-6">
			<h1 class="text-2xl font-bold text-primary-500 text-center">Forgot password</h1>
			<p class="mt-2 text-center text-surface-950/80 text-sm">
				Enter your username. If a recovery Gmail is on file, we will email a reset link there.
			</p>

			{#if status}
				<div
					class="mt-4 rounded-container border border-success-500/40 bg-success-50 px-3 py-2 text-sm text-success-900 dark:bg-success-950/30 dark:text-success-100"
					role="status"
				>
					{status}
				</div>
			{/if}
			{#if error}
				<div
					class="mt-4 rounded-container border border-error-500/40 bg-error-50 px-3 py-2 text-sm text-error-800 dark:bg-error-950/30 dark:text-error-200"
					role="alert"
				>
					{error}
				</div>
			{/if}

			<form
				class="flex flex-col gap-4 mt-4"
				onsubmit={(e) => {
					e.preventDefault();
					$form.post('/forgot-password');
				}}
				method="post"
				action="/forgot-password"
			>
				<div class="w-full">
					<label for="username" class="block text-sm font-medium text-surface-950 mb-1">Username</label>
					<input
						id="username"
						type="text"
						name="username"
						autocomplete="username"
						required
						class="input w-full rounded-container border border-surface-200 px-3 py-2 {$form.errors?.username ? 'border-error-500 bg-error-50' : ''}"
						bind:value={$form.username}
						aria-invalid={!!$form.errors?.username}
					/>
					{#if $form.errors?.username}
						<span class="text-error-600 text-sm">{$form.errors.username}</span>
					{/if}
				</div>

				<button
					type="submit"
					class="btn preset-filled-primary-500 w-full mt-2 touch-target-h"
					disabled={$form.processing}
				>
					{$form.processing ? 'Sending…' : 'Send reset link'}
				</button>
			</form>
		</div>
	</main>
</AuthLayout>
