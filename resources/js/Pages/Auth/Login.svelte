<script lang="ts">
	import { useForm, usePage } from '@inertiajs/svelte';
	import AuthLayout from '../../Layouts/AuthLayout.svelte';
	import AppBackground from '../../Components/AppBackground.svelte';
	import Modal from '../../Components/Modal.svelte';

	interface DemoAccount {
		label: string;
		username: string;
		email?: string;
	}

	let {
		status = null,
		error = null,
		demo = false,
		demoAccounts = [] as DemoAccount[],
		googleOAuthEnabled = false
	} = $props();

	/** Same program photo as Home / app shell so login feels part of the same experience */
	const heroImageUrl = '/images/mswdo_tagudin.jpg';

	const form = useForm({
		username: '',
		password: ''
	});

	const page = usePage();
	const edgeMode = $derived(
		($page?.props as { edge_mode?: { is_edge?: boolean } } | undefined)
			?.edge_mode ?? null
	);

	let showDemoAccountsModal = $state(false);

	function applyDemoAccount(account: DemoAccount) {
		$form.username = account.username;
		$form.password = 'password';
		$form.errors = {};
		showDemoAccountsModal = false;
	}
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
			<div class="mt-1 flex items-center justify-center gap-2">
				<p class="text-center text-surface-950/80 text-sm">Sign in with your username and password.</p>
				{#if demo && demoAccounts.length > 0}
					<button
						type="button"
						class="inline-flex items-center justify-center h-7 w-7 rounded-full border border-surface-300 text-surface-600 hover:bg-surface-100 hover:text-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-500"
						title="View demo accounts"
						aria-label="View demo accounts"
						onclick={() => {
							showDemoAccountsModal = true;
						}}
					>
						<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
						</svg>
					</button>
				{/if}
			</div>

			{#if error}
				<div
					class="mt-4 rounded-container border border-error-500/40 bg-error-50 px-3 py-2 text-sm text-error-800 dark:bg-error-950/30 dark:text-error-200"
					role="alert"
				>
					{error}
				</div>
			{/if}
			{#if status}
				<div
					class="mt-4 rounded-container border border-success-500/40 bg-success-50 px-3 py-2 text-sm text-success-900 dark:bg-success-950/30 dark:text-success-100"
					role="status"
				>
					{status}
				</div>
			{/if}

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
						aria-describedby={$form.errors?.username ? 'username-error' : undefined}
					/>
					{#if $form.errors?.username}
						<span id="username-error" class="text-error-600 text-sm">{$form.errors.username}</span>
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

				<div class="text-right">
					{#if !edgeMode?.is_edge}
						<a
							href="/forgot-password"
							class="text-sm font-medium text-primary-600 hover:text-primary-700 hover:underline"
						>
							Forgot password?
						</a>
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

			{#if googleOAuthEnabled && !edgeMode?.is_edge}
				<div class="relative mt-6">
					<div class="absolute inset-0 flex items-center" aria-hidden="true">
						<div class="w-full border-t border-surface-200"></div>
					</div>
					<div class="relative flex justify-center text-xs">
						<span class="bg-surface-50 px-2 text-surface-500">or</span>
					</div>
				</div>
				<a
					href="/auth/google"
					class="btn preset-outlined-surface-200 w-full mt-4 touch-target-h inline-flex items-center justify-center gap-2 no-underline"
				>
					<svg class="h-5 w-5" viewBox="0 0 24 24" aria-hidden="true">
						<path
							fill="#4285F4"
							d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
						/>
						<path
							fill="#34A853"
							d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
						/>
						<path
							fill="#FBBC05"
							d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
						/>
						<path
							fill="#EA4335"
							d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
						/>
					</svg>
					Sign in with Google
				</a>
			{/if}

		</div>
	</main>

	{#if demo && demoAccounts.length > 0}
		<Modal
			open={showDemoAccountsModal}
			title="Demo accounts"
			onClose={() => {
				showDemoAccountsModal = false;
			}}
		>
			<div class="space-y-4">
				<p class="text-sm text-surface-600">
					Choose an account to auto-fill the login form.
				</p>
				<p class="text-xs text-surface-600">
					Password: <kbd class="px-1 rounded bg-surface-200 font-mono text-xs">password</kbd>
					· Override PIN: <kbd class="px-1 rounded bg-surface-200 font-mono text-xs">123456</kbd>
				</p>
				<ul class="space-y-2 max-h-72 overflow-y-auto">
					{#each demoAccounts as account (account.username)}
						<li>
							<button
								type="button"
								class="text-left w-full text-sm py-2 px-3 rounded border border-surface-200 hover:bg-primary-500/10 focus:bg-primary-500/10 focus:outline-none focus:ring-2 focus:ring-primary-500"
								onclick={() => applyDemoAccount(account)}
							>
								<span class="font-medium text-surface-800">{account.label}</span>
								<span class="block text-xs text-surface-500 font-mono truncate" title={account.username}>{account.username}</span>
								{#if account.email}
									<span class="block text-xs text-surface-400 truncate" title={account.email}>{account.email}</span>
								{/if}
							</button>
						</li>
					{/each}
				</ul>
			</div>
		</Modal>
	{/if}
</AuthLayout>
