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

<main class="min-h-screen bg-base-200 flex flex-col items-center justify-center p-6">
	<div class="card bg-base-100 shadow-xl max-w-md w-full">
		<div class="card-body">
			<h1 class="card-title text-2xl text-primary justify-center">FlexiQueue</h1>
			<p class="text-center text-base-content/80 text-sm">Sign in with your email and password.</p>

			{#if error}
				<div class="alert alert-error" role="alert">
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
				<div class="form-control w-full">
					<label for="email" class="label">
						<span class="label-text">Email</span>
					</label>
					<input
						id="email"
						type="email"
						name="email"
						autocomplete="email"
						required
						class="input input-bordered w-full {$form.errors?.email ? 'input-error' : ''}"
						bind:value={$form.email}
						aria-invalid={!!$form.errors?.email}
						aria-describedby={$form.errors?.email ? 'email-error' : undefined}
					/>
					{#if $form.errors?.email}
						<label id="email-error" class="label text-error" for="email">
							<span class="label-text-alt">{$form.errors.email}</span>
						</label>
					{/if}
				</div>

				<div class="form-control w-full">
					<label for="password" class="label">
						<span class="label-text">Password</span>
					</label>
					<input
						id="password"
						type="password"
						name="password"
						autocomplete="current-password"
						required
						class="input input-bordered w-full {$form.errors?.password ? 'input-error' : ''}"
						bind:value={$form.password}
						aria-invalid={!!$form.errors?.password}
						aria-describedby={$form.errors?.password ? 'password-error' : undefined}
					/>
					{#if $form.errors?.password}
						<label id="password-error" class="label text-error" for="password">
							<span class="label-text-alt">{$form.errors.password}</span>
						</label>
					{/if}
				</div>

				<button
					type="submit"
					class="btn btn-primary w-full mt-2"
					disabled={$form.processing}
				>
					{$form.processing ? 'Signing in…' : 'Sign in'}
				</button>
			</form>
		</div>
	</div>
</main>
