<script lang="ts">
	import { useForm } from '@inertiajs/svelte';
	import AuthLayout from '../../Layouts/AuthLayout.svelte';
	import AppBackground from '../../Components/AppBackground.svelte';

	let { token = '', username = '' } = $props();

	const heroImageUrl = '/images/mswdo_tagudin.jpg';

	const form = useForm({
		token,
		username,
		password: '',
		password_confirmation: ''
	});
</script>

<svelte:head>
	<title>Set new password — FlexiQueue</title>
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
			<h1 class="text-2xl font-bold text-primary-500 text-center">Set new password</h1>
			<p class="mt-2 text-center text-surface-950/80 text-sm">Choose a new password for your account.</p>

			<form
				class="flex flex-col gap-4 mt-4"
				onsubmit={(e) => {
					e.preventDefault();
					$form.post('/reset-password');
				}}
				method="post"
				action="/reset-password"
			>
				<div class="w-full">
					<label for="password" class="block text-sm font-medium text-surface-950 mb-1">New password</label>
					<input
						id="password"
						type="password"
						name="password"
						autocomplete="new-password"
						required
						class="input w-full rounded-container border border-surface-200 px-3 py-2 {$form.errors?.password ? 'border-error-500 bg-error-50' : ''}"
						bind:value={$form.password}
					/>
					{#if $form.errors?.password}
						<span class="text-error-600 text-sm">{$form.errors.password}</span>
					{/if}
				</div>

				<div class="w-full">
					<label for="password_confirmation" class="block text-sm font-medium text-surface-950 mb-1"
						>Confirm password</label
					>
					<input
						id="password_confirmation"
						type="password"
						name="password_confirmation"
						autocomplete="new-password"
						required
						class="input w-full rounded-container border border-surface-200 px-3 py-2"
						bind:value={$form.password_confirmation}
					/>
				</div>

				{#if $form.errors?.username}
					<p class="text-error-600 text-sm" role="alert">{$form.errors.username}</p>
				{/if}

				<button
					type="submit"
					class="btn preset-filled-primary-500 w-full mt-2 touch-target-h"
					disabled={$form.processing}
				>
					{$form.processing ? 'Saving…' : 'Update password'}
				</button>
			</form>
		</div>
	</main>
</AuthLayout>
