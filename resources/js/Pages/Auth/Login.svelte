<script lang="ts">
	import { useForm } from '@inertiajs/svelte';

	// Flashed messages from controller (lockout, etc.)
	let { status = null, error = null } = $props();

	const form = useForm({
		email: '',
		password: ''
	});
</script>

<svelte:head>
	<title>Login — FlexiQueue</title>
</svelte:head>

<main class="min-h-screen bg-surface-100 flex flex-col items-center justify-center p-6">
	<div class="card bg-surface-50 rounded-container shadow-xl max-w-md w-full p-6">
		<h1 class="text-2xl font-bold text-primary-500 text-center">FlexiQueue</h1>
		<p class="text-center text-surface-950/80 text-sm mt-1">Sign in with your email and password.</p>

		{#if error}
			<div class="bg-error-100 text-error-900 border border-error-300 rounded-container p-3 mt-4" role="alert">
				<span>{error}</span>
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
					class="btn preset-filled-primary-500 w-full mt-2"
					disabled={$form.processing}
				>
					{$form.processing ? 'Signing in…' : 'Sign in'}
				</button>
			</form>
	</div>
</main>
