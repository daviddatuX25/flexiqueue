<script lang="ts">
	/**
	 * Profile: password, preset PIN, preset QR. Preset is per-user; any staff can set it.
	 * Preset authorization only works when the user is a supervisor for the program where it's used.
	 */
	import AppShell from '../../Layouts/AppShell.svelte';
	import { usePage } from '@inertiajs/svelte';

	const page = usePage();
	const user = $derived($page.props?.auth?.user ?? null);

	// Password form
	let passwordCurrent = $state('');
	let passwordNew = $state('');
	let passwordConfirm = $state('');
	let passwordSubmitting = $state(false);
	let passwordMessage = $state<{ type: 'success' | 'error'; text: string } | null>(null);

	// Override PIN form
	let currentPassword = $state('');
	let newPin = $state('');
	let pinSubmitting = $state(false);
	let pinMessage = $state<{ type: 'success' | 'error'; text: string } | null>(null);

	// Preset QR
	let hasPresetQr = $state(false);
	let qrLoading = $state(false);
	let qrRegenerating = $state(false);
	let qrDataUri = $state<string | null>(null);
	let qrMessage = $state<{ type: 'success' | 'error'; text: string } | null>(null);

	async function fetchHasPresetQr() {
		qrLoading = true;
		try {
			const r = await fetch('/api/profile/override-qr', { credentials: 'include' });
			const data = await r.json();
			hasPresetQr = !!data.has_preset_qr;
		} finally {
			qrLoading = false;
		}
	}

	$effect(() => {
		if (user) fetchHasPresetQr();
	});

	async function submitPassword(e: Event) {
		e.preventDefault();
		passwordMessage = null;
		passwordSubmitting = true;
		try {
			const r = await fetch('/api/profile/password', {
				method: 'PUT',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
				credentials: 'include',
				body: JSON.stringify({
					current_password: passwordCurrent,
					password: passwordNew,
					password_confirmation: passwordConfirm,
				}),
			});
			const data = await r.json().catch(() => ({}));
			if (r.ok) {
				passwordMessage = { type: 'success', text: data.message ?? 'Password updated.' };
				passwordCurrent = '';
				passwordNew = '';
				passwordConfirm = '';
			} else {
				passwordMessage = {
					type: 'error',
					text: data.message ?? (data.errors ? Object.values(data.errors).flat().join(' ') : 'Failed to update password.'),
				};
			}
		} finally {
			passwordSubmitting = false;
		}
	}

	async function submitPin(e: Event) {
		e.preventDefault();
		pinMessage = null;
		pinSubmitting = true;
		try {
			const r = await fetch('/api/profile/override-pin', {
				method: 'PUT',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
				credentials: 'include',
				body: JSON.stringify({ current_password: currentPassword, new_pin: newPin }),
			});
			const data = await r.json().catch(() => ({}));
			if (r.ok) {
				pinMessage = { type: 'success', text: data.message ?? 'Override PIN updated.' };
				currentPassword = '';
				newPin = '';
			} else {
				pinMessage = { type: 'error', text: data.message ?? (data.errors ? Object.values(data.errors).flat().join(' ') : 'Failed to update PIN.') };
			}
		} finally {
			pinSubmitting = false;
		}
	}

	async function regenerateQr() {
		qrMessage = null;
		qrDataUri = null;
		qrRegenerating = true;
		try {
			const r = await fetch('/api/profile/override-qr/regenerate', {
				method: 'POST',
				headers: { Accept: 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
				credentials: 'include',
			});
			const data = await r.json().catch(() => ({}));
			if (r.ok) {
				qrDataUri = data.qr_data_uri ?? null;
				hasPresetQr = true;
				qrMessage = { type: 'success', text: data.message ?? 'Preset QR regenerated. Save or print it; it will not be shown again.' };
			} else {
				qrMessage = { type: 'error', text: data.message ?? 'Failed to regenerate QR.' };
			}
		} finally {
			qrRegenerating = false;
		}
	}

	function getCsrfToken(): string {
		const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
		return match ? decodeURIComponent(match[1]) : '';
	}

	function printPresetQr() {
		if (!qrDataUri || !user) return;
		const name = user.name ?? 'User';
		const w = window.open('', '_blank', 'width=400,height=500');
		if (!w) return;
		w.document.write(`
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<title>Preset authorization QR — FlexiQueue</title>
	<style>
		body { font-family: system-ui, sans-serif; padding: 24px; text-align: center; }
		h1 { font-size: 1rem; margin: 0 0 8px; }
		p { font-size: 0.875rem; color: #666; margin: 0 0 16px; }
		img { max-width: 240px; height: auto; border: 1px solid #ddd; border-radius: 8px; }
		@media print { body { padding: 16px; } }
	</style>
</head>
<body>
	<h1>Preset authorization QR</h1>
	<p>${escapeHtml(name)} — FlexiQueue</p>
	<img src="${escapeHtml(qrDataUri)}" alt="Preset QR code" />
</body>
</html>`);
		w.document.close();
		w.focus();
		w.print();
	}

	function escapeHtml(s: string): string {
		const div = document.createElement('div');
		div.textContent = s;
		return div.innerHTML;
	}
</script>

<svelte:head>
	<title>Profile — FlexiQueue</title>
</svelte:head>

<AppShell>
	<div class="profile-page-content p-4 max-w-lg mx-auto">
		<h1 class="text-xl font-semibold mb-4">Profile</h1>
		{#if user}
			<span class="text-sm text-surface-950/70">{user.name}</span>
			<span class="text-xs px-2 py-0.5 rounded preset-filled-primary-500 badge-sm ml-2">{user.role}</span>
		{/if}

		<!-- Password -->
		<section class="card bg-surface-50 shadow-sm mt-4 mb-6">
			<div class="card-body">
				<h2 class="card-title text-base">Change password</h2>
				<form onsubmit={submitPassword} class="space-y-3">
					<div>
						<label for="current_password" class="label label-text">Current password</label>
						<input
							id="current_password"
							type="password"
							class="input rounded-container border border-surface-200 px-3 py-2 w-full"
							bind:value={passwordCurrent}
							required
							autocomplete="current-password"
						/>
					</div>
					<div>
						<label for="password_new" class="label label-text">New password</label>
						<input
							id="password_new"
							type="password"
							class="input rounded-container border border-surface-200 px-3 py-2 w-full"
							bind:value={passwordNew}
							required
							autocomplete="new-password"
						/>
					</div>
					<div>
						<label for="password_confirm" class="label label-text">Confirm new password</label>
						<input
							id="password_confirm"
							type="password"
							class="input rounded-container border border-surface-200 px-3 py-2 w-full"
							bind:value={passwordConfirm}
							required
							autocomplete="new-password"
						/>
					</div>
					{#if passwordMessage}
						<p class="text-sm {passwordMessage.type === 'error' ? 'text-error-500' : 'text-success-500'}">{passwordMessage.text}</p>
					{/if}
					<button type="submit" class="btn preset-filled-primary-500 btn-sm" disabled={passwordSubmitting}>
						{passwordSubmitting ? 'Updating…' : 'Update password'}
					</button>
				</form>
			</div>
		</section>

		<!-- Preset PIN & QR (any user; only works when you're a supervisor for that program) -->
		<section class="card bg-surface-50 shadow-sm mb-6">
			<div class="card-body">
				<h2 class="card-title text-base">Override PIN</h2>
				<p class="text-sm text-surface-950/70">Set or change your 6-digit PIN for authorizing overrides and force-complete. Not visible to admins. <strong>Only works when you are a supervisor for the program</strong> where it’s used; otherwise staff will see: “You are not a supervisor for this program.”</p>
				<form onsubmit={submitPin} class="space-y-3">
					<div>
						<label for="pin_current_password" class="label label-text">Current password</label>
						<input
							id="pin_current_password"
							type="password"
							class="input rounded-container border border-surface-200 px-3 py-2 w-full"
							bind:value={currentPassword}
							required
							autocomplete="current-password"
						/>
					</div>
					<div>
						<label for="new_pin" class="label label-text">New 6-digit PIN</label>
						<input
							id="new_pin"
							type="password"
							inputmode="numeric"
							pattern="[0-9]{6}"
							maxlength="6"
							class="input rounded-container border border-surface-200 px-3 py-2 w-full"
							bind:value={newPin}
							required
							placeholder="000000"
							autocomplete="off"
						/>
					</div>
					{#if pinMessage}
						<p class="text-sm {pinMessage.type === 'error' ? 'text-error-500' : 'text-success-500'}">{pinMessage.text}</p>
					{/if}
					<button type="submit" class="btn preset-filled-primary-500 btn-sm" disabled={pinSubmitting}>
						{pinSubmitting ? 'Saving…' : 'Update PIN'}
					</button>
				</form>
			</div>
		</section>

		<section class="card bg-surface-50 shadow-sm mb-6">
			<div class="card-body">
				<h2 class="card-title text-base">Preset QR</h2>
				<p class="text-sm text-surface-950/70">Staff can scan your preset QR to authorize overrides. Preset is per-user; <strong>it only works when you are a supervisor for that program</strong>. Regenerating invalidates the previous QR. The QR is shown only once after regeneration.</p>
				{#if qrLoading}
					<p class="text-sm text-surface-950/60">Loading…</p>
				{:else}
					{#if hasPresetQr && !qrDataUri}
						<p class="text-sm text-surface-950/70">You have a preset QR set. Regenerate to get a new one (current one will stop working).</p>
					{/if}
					{#if qrDataUri}
						<div class="flex flex-col items-center gap-2 my-2">
							<img src={qrDataUri} alt="Preset QR code" class="w-48 h-48 object-contain border border-surface-200 rounded-lg" />
							<p class="text-xs text-warning-500">Save or print this; it will not be shown again.</p>
							<button type="button" class="btn preset-outlined btn-sm" onclick={printPresetQr}>
								Print
							</button>
						</div>
					{/if}
					{#if qrMessage}
						<p class="text-sm {qrMessage.type === 'error' ? 'text-error-500' : 'text-success-500'}">{qrMessage.text}</p>
					{/if}
					<button
						type="button"
						class="btn preset-outlined btn-sm"
						disabled={qrRegenerating}
						onclick={regenerateQr}
					>
						{qrRegenerating ? 'Regenerating…' : (hasPresetQr ? 'Regenerate preset QR' : 'Generate preset QR')}
					</button>
				{/if}
			</div>
		</section>

		<!-- Profile photo placeholder -->
		<section class="card bg-surface-50 shadow-sm">
			<div class="card-body">
				<h2 class="card-title text-base">Profile photo</h2>
				<p class="text-sm text-surface-950/70">Photo upload will be available in a future update.</p>
			</div>
		</section>
	</div>
</AppShell>
