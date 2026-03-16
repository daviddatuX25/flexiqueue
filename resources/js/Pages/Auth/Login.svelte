<script lang="ts">
	import { useForm } from '@inertiajs/svelte';
	import AuthLayout from '../../Layouts/AuthLayout.svelte';
	import AppBackground from '../../Components/AppBackground.svelte';

	interface DemoAccount {
		label: string;
		email: string;
	}

	let {
		status = null,
		error = null,
		demo = false,
		demoAccounts = [] as DemoAccount[]
	} = $props();

	/** Same program photo as Home / app shell so login feels part of the same experience */
	const heroImageUrl = '/images/mswdo_tagudin.jpg';

	const form = useForm({
		email: '',
		password: ''
	});
</script>

<svelte:head>
	<title>Login — FlexiQueue</title>
</svelte:head>

<AuthLayout>
	<!-- Same program photo background as Home so login is visibly on-brand -->
	<AppBackground heroImageUrl={heroImageUrl} />
	<main class="min-h-screen flex flex-col items-center justify-center p-6 relative">
		<!-- Per ui-ux-tasks-checklist: back button to return to public/home -->
		<a
			href="/"
			class="absolute top-4 left-4 sm:top-6 sm:left-6 inline-flex items-center gap-2 text-sm font-medium text-surface-600 hover:text-surface-950 dark:text-slate-400 dark:hover:text-slate-100 transition-colors no-underline"
			aria-label="Back to home"
		>
			<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
			</svg>
			Back to home
		</a>
		<div class="card bg-surface-50 rounded-container shadow-xl max-w-md w-full p-6">
			<h1 class="text-2xl font-bold text-primary-500 text-center">FlexiQueue</h1>
			<p class="text-center text-surface-950/80 text-sm mt-1">Sign in with your email and password.</p>

			<form
				class="flex flex-col gap-4 mt-4"
				onsubmit={(e) => {
					e.preventDefault();
					$form.post('/login');
				}}
				method="post"
				action="/login"
			>
				<div class="w-full">
					<label for="email" class="block text-sm font-medium text-surface-950 mb-1">Email</label>
					<input
						id="email"
						type="email"
						name="email"
						autocomplete="email"
						required
						class="input w-full rounded-container border border-surface-200 px-3 py-2 {$form.errors?.email ? 'border-error-500 bg-error-50' : ''}"
						bind:value={$form.email}
						aria-invalid={!!$form.errors?.email}
						aria-describedby={$form.errors?.email ? 'email-error' : undefined}
					/>
					{#if $form.errors?.email}
						<span id="email-error" class="text-error-600 text-sm">{$form.errors.email}</span>
					{/if}
				</div>

				<div class="w-full">
					<label for="password" class="block text-sm font-medium text-surface-950 mb-1">Password</label>
					<input
						id="password"
						type="password"
						name="password"
						autocomplete="current-password"
						required
						class="input w-full rounded-container border border-surface-200 px-3 py-2 {$form.errors?.password ? 'border-error-500 bg-error-50' : ''}"
						bind:value={$form.password}
						aria-invalid={!!$form.errors?.password}
						aria-describedby={$form.errors?.password ? 'password-error' : undefined}
					/>
					{#if $form.errors?.password}
						<span id="password-error" class="text-error-600 text-sm">{$form.errors.password}</span>
					{/if}
				</div>

				<button
					type="submit"
					class="btn preset-filled-primary-500 w-full mt-2 touch-target-h"
					disabled={$form.processing}
				>
					{$form.processing ? 'Signing in…' : 'Sign in'}
				</button>
			</form>

			{#if demo && demoAccounts.length > 0}
				<section
					class="mt-6 p-4 rounded-container border border-surface-200 bg-surface-100/80"
					aria-labelledby="demo-accounts-heading"
				>
					<h2 id="demo-accounts-heading" class="text-sm font-semibold text-surface-700 mb-2">
						Demo accounts
					</h2>
					<p class="text-xs text-surface-600 mb-3">
						Password: <kbd class="px-1 rounded bg-surface-200 font-mono text-xs">password</kbd>
						· Override PIN: <kbd class="px-1 rounded bg-surface-200 font-mono text-xs">123456</kbd>
					</p>
					<ul class="space-y-1.5 max-h-48 overflow-y-auto">
						{#each demoAccounts as account (account.email)}
							<li>
								<button
									type="button"
									class="text-left w-full text-sm py-1.5 px-2 rounded hover:bg-primary-500/10 focus:bg-primary-500/10 focus:outline-none focus:ring-2 focus:ring-primary-500"
									onclick={() => {
										$form.setData({ email: account.email, password: 'password' });
									}}
								>
									<span class="font-medium text-surface-800">{account.label}</span>
									<span class="block text-xs text-surface-500 truncate" title={account.email}>{account.email}</span>
								</button>
							</li>
						{/each}
					</ul>
				</section>
			{/if}
		</div>
	</main>
</AuthLayout>
