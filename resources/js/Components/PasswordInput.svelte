<script lang="ts">
	/**
	 * Shared new-password + confirm block with live match indicator and strength bar.
	 * Used in Profile change-password and Admin "Reset password for user" modal.
	 * Per ui-ux-tasks-phased: confirm field, match indicator (green check / red X), 3-segment strength bar.
	 */
	let {
		password = $bindable(''),
		passwordConfirm = $bindable(''),
		errors = {} as { password?: string; password_confirmation?: string },
		idPrefix = 'pw',
		disabled = false,
		minLength = 8,
		required = true,
	} = $props<{
		password?: string;
		passwordConfirm?: string;
		errors?: { password?: string; password_confirmation?: string };
		idPrefix?: string;
		disabled?: boolean;
		minLength?: number;
		required?: boolean;
	}>();

	const idNew = `${idPrefix}_new`;
	const idConfirm = `${idPrefix}_confirm`;

	function getStrength(pw: string): 'weak' | 'fair' | 'strong' {
		if (!pw || pw.length < minLength) return 'weak';
		const hasLower = /[a-z]/.test(pw);
		const hasUpper = /[A-Z]/.test(pw);
		const hasDigit = /\d/.test(pw);
		const hasSpecial = /[^a-zA-Z0-9]/.test(pw);
		const types = [hasLower || hasUpper, hasDigit, hasSpecial].filter(Boolean).length;
		if (pw.length >= 12 && types >= 2) return 'strong';
		if (pw.length >= 8 && types >= 2) return 'fair';
		if (pw.length >= 8 && (hasLower && hasUpper)) return 'fair';
		return 'weak';
	}

	const strength = $derived(getStrength(password));
	const match = $derived(
		password.length === 0 && passwordConfirm.length === 0
			? null
			: password === passwordConfirm && password.length >= minLength,
	);
	const matchVisible = $derived(passwordConfirm.length > 0);
</script>

<div class="space-y-3">
	<div>
		<label for={idNew} class="label label-text">New password</label>
		<input
			id={idNew}
			type="password"
			class="input rounded-container border px-3 py-2 w-full {errors?.password ? 'border-error-500 bg-error-50' : 'border-surface-200'}"
			bind:value={password}
			disabled={disabled}
			required={required}
			autocomplete="new-password"
			aria-invalid={!!errors?.password}
			aria-describedby={errors?.password ? `${idNew}-error` : undefined}
		/>
		{#if errors?.password}
			<span id="{idNew}-error" class="text-error-600 text-sm" role="alert">{errors.password}</span>
		{/if}
		<!-- Strength bar: 3 segments -->
		{#if password.length > 0}
			<div class="flex gap-0.5 mt-1.5" role="presentation" aria-label="Password strength: {strength}">
				<div
					class="h-1 flex-1 rounded-full transition-colors {strength === 'weak' || strength === 'fair' || strength === 'strong'
						? strength === 'weak'
							? 'bg-error-500'
							: strength === 'fair'
								? 'bg-amber-500'
								: 'bg-success-500'
						: 'bg-surface-200'}"
				></div>
				<div
					class="h-1 flex-1 rounded-full transition-colors {strength === 'fair' || strength === 'strong'
						? strength === 'fair'
							? 'bg-amber-500'
							: 'bg-success-500'
						: 'bg-surface-200'}"
				></div>
				<div
					class="h-1 flex-1 rounded-full transition-colors {strength === 'strong' ? 'bg-success-500' : 'bg-surface-200'}"
				></div>
			</div>
			<p class="text-xs text-surface-500 mt-0.5">
				{strength === 'weak' && `Weak — use at least ${minLength} characters and mix letters, numbers, or symbols`}
				{strength === 'fair' && 'Fair — add more character types or length for stronger'}
				{strength === 'strong' && 'Strong'}
			</p>
		{/if}
	</div>
	<div>
		<label for={idConfirm} class="label label-text">Confirm new password</label>
		<input
			id={idConfirm}
			type="password"
			class="input rounded-container border px-3 py-2 w-full {errors?.password_confirmation ? 'border-error-500 bg-error-50' : 'border-surface-200'}"
			bind:value={passwordConfirm}
			disabled={disabled}
			required={required}
			autocomplete="new-password"
			aria-invalid={!!errors?.password_confirmation}
			aria-describedby={errors?.password_confirmation ? `${idConfirm}-error` : undefined}
		/>
		{#if errors?.password_confirmation}
			<span id="{idConfirm}-error" class="text-error-600 text-sm" role="alert"
				>{errors.password_confirmation}</span
			>
		{/if}
		{#if matchVisible}
			{#if match === true}
				<p class="flex items-center gap-1.5 mt-1.5 text-sm text-success-600" role="status">
					<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
					</svg>
					Passwords match
				</p>
			{:else if match === false}
				<p class="flex items-center gap-1.5 mt-1.5 text-sm text-error-600" role="alert">
					<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
					</svg>
					Passwords don't match
				</p>
			{/if}
		{/if}
	</div>
</div>
