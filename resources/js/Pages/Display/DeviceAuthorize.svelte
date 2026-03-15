<script lang="ts">
	/**
	 * Per plan Step 5: Authorize this device to use the program's display/triage.
	 * Supervisor enters PIN or scans QR; or request approval (show QR for supervisor to scan).
	 */
	import { router } from '@inertiajs/svelte';
	import { onMount, onDestroy } from 'svelte';
	import DisplayLayout from '../../Layouts/DisplayLayout.svelte';
	import { toaster } from '../../lib/toaster.js';
	import { ShieldCheck } from 'lucide-svelte';

	let {
		program_id,
		program_name,
		program_slug,
		site_slug,
		redirect_url,
	}: {
		program_id: number;
		program_name: string;
		program_slug: string;
		site_slug: string;
		redirect_url: string;
	} = $props();

	let pin = $state('');
	let allowPersistent = $state(false);
	let loading = $state(false);
	type AuthMode = 'pin' | 'request';
	let authMode = $state<AuthMode>('pin');
	let requestId = $state<number | null>(null);
	let requestToken = $state<string | null>(null);
	let requestState = $state<'idle' | 'waiting' | 'approved' | 'rejected'>('idle');
	let pollIntervalId = $state<ReturnType<typeof setInterval> | null>(null);

	function getCsrfToken(): string {
		return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
	}

	async function createRequest() {
		if (loading) return;
		loading = true;
		try {
			const res = await fetch('/api/public/device-authorization-requests', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'Accept': 'application/json',
					'X-CSRF-TOKEN': getCsrfToken(),
					'X-Requested-With': 'XMLHttpRequest',
				},
				credentials: 'include',
				body: JSON.stringify({
					program_id,
					scope: allowPersistent ? 'persistent' : 'session',
				}),
			});
			const data = await res.json().catch(() => ({}));
			if (!res.ok) {
				toaster.error({ title: (data).message || 'Failed to create request.' });
				loading = false;
				return;
			}
			requestId = data.id;
			requestToken = data.request_token;
			requestState = 'waiting';
			const id = data.id;
			const token = data.request_token;
			pollIntervalId = setInterval(async () => {
				try {
					const r = await fetch(`/api/public/device-authorization-requests/${id}?token=${encodeURIComponent(token)}`, { credentials: 'include' });
					const d = await r.json().catch(() => ({}));
					if (d.status === 'approved') {
						if (pollIntervalId) clearInterval(pollIntervalId);
						pollIntervalId = null;
						requestState = 'idle';
						requestId = null;
						requestToken = null;
						toaster.success({ title: 'Device authorized.' });
						router.visit(redirect_url, { replace: true });
					} else if (d.status === 'rejected' || d.status === 'cancelled') {
						if (pollIntervalId) clearInterval(pollIntervalId);
						pollIntervalId = null;
						requestState = 'idle';
						requestId = null;
						requestToken = null;
						toaster.warning({ title: d.status === 'rejected' ? 'Request was rejected.' : 'Request was cancelled.' });
					}
				} catch {
					// ignore
				}
			}, 2000);
		} finally {
			loading = false;
		}
	}

	function cancelRequest() {
		requestState = 'idle';
		requestId = null;
		requestToken = null;
		if (pollIntervalId) {
			clearInterval(pollIntervalId);
			pollIntervalId = null;
		}
	}

	async function cancelDeviceAuthRequestOnLeave() {
		if (requestId != null && requestToken) {
			try {
				await fetch(`/api/public/device-authorization-requests/${requestId}/cancel`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), Accept: 'application/json' },
					credentials: 'include',
					body: JSON.stringify({ request_token: requestToken }),
				});
			} catch {
				// ignore
			}
		}
	}

	let beforeUnloadHandler: (() => void) | null = null;
	onDestroy(() => {
		cancelDeviceAuthRequestOnLeave();
		if (typeof window !== 'undefined' && beforeUnloadHandler) {
			window.removeEventListener('beforeunload', beforeUnloadHandler);
		}
	});
	onMount(() => {
		beforeUnloadHandler = () => {
			cancelDeviceAuthRequestOnLeave();
		};
		window.addEventListener('beforeunload', beforeUnloadHandler);
	});

	const DEVICE_AUTH_REQUEST_QR_PREFIX = 'flexiqueue:device_auth_request:';

	async function submit() {
		const trimmed = pin.replace(/\D/g, '').slice(0, 6);
		if (trimmed.length !== 6) {
			toaster.warning({ title: 'Enter a 6-digit PIN.' });
			return;
		}
		loading = true;
		try {
			const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
			const res = await fetch('/api/public/device-authorize', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'Accept': 'application/json',
					'X-CSRF-TOKEN': csrf,
					'X-Requested-With': 'XMLHttpRequest',
				},
				body: JSON.stringify({
					program_id,
					pin: trimmed,
					allow_persistent: allowPersistent,
				}),
				credentials: 'include',
			});
			const data = await res.json().catch(() => ({}));
			if (!res.ok) {
				toaster.error({ title: data.message || 'Authorization failed.' });
				loading = false;
				return;
			}
			toaster.success({ title: 'Device authorized.' });
			router.visit(redirect_url, { replace: true });
		} catch {
			toaster.error({ title: 'Network error. Try again.' });
			loading = false;
		}
	}
</script>

<DisplayLayout programName={program_name} date="">
	<div class="flex flex-1 flex-col items-center justify-center px-6 py-12">
		<div
			class="flex flex-col gap-6 items-center rounded-2xl border border-surface-200 bg-surface-50/90 dark:bg-slate-800/90 dark:border-slate-700 p-8 shadow-lg max-w-md w-full"
		>
			<div
				class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-primary-100 dark:bg-primary-900/50 text-primary-600 dark:text-primary-400"
				aria-hidden="true"
			>
				<ShieldCheck class="h-8 w-8" />
			</div>
			<div class="space-y-2 text-center">
				<h1 class="text-xl font-semibold text-surface-950 dark:text-white">
					Authorize this device
				</h1>
				<p class="text-sm text-surface-600 dark:text-slate-400">
					This device must be authorized to use <strong>{program_name}</strong>. Ask the program supervisor or admin to enter their PIN, or show the QR for them to scan.
				</p>
			</div>
			{#if requestState === 'waiting' && requestId != null && requestToken != null}
				<div class="flex w-full flex-col items-center gap-3">
					<p class="text-sm font-medium text-surface-950">Waiting for approval…</p>
					<p class="text-xs text-surface-950/60 text-center">Ask the program supervisor or admin to scan this QR on the Track overrides page.</p>
					<img
						class="rounded-container border border-surface-200 bg-white p-2"
						alt="QR for supervisor to scan"
						width="200"
						height="200"
						src={`https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(DEVICE_AUTH_REQUEST_QR_PREFIX + requestId + ':' + requestToken)}`}
					/>
					<button type="button" class="btn preset-tonal btn-sm touch-target-h" onclick={cancelRequest}>
						Cancel request
					</button>
				</div>
			{:else}
				<div class="flex w-full gap-2 mb-2">
					<button
						type="button"
						class="btn btn-sm flex-1 touch-target-h {authMode === 'pin' ? 'preset-filled-primary-500' : 'preset-tonal'}"
						onclick={() => (authMode = 'pin')}
					>
						PIN
					</button>
					<button
						type="button"
						class="btn btn-sm flex-1 touch-target-h {authMode === 'request' ? 'preset-filled-primary-500' : 'preset-tonal'}"
						onclick={() => (authMode = 'request')}
					>
						QR
					</button>
				</div>
				{#if authMode === 'pin'}
					<form
						class="flex w-full flex-col gap-4"
						onsubmit={(e) => {
							e.preventDefault();
							submit();
						}}
					>
						<div>
							<label for="device-auth-pin" class="block text-sm font-medium text-surface-700 dark:text-slate-300 mb-1">
								Supervisor PIN
							</label>
							<input
								id="device-auth-pin"
								type="password"
								inputmode="numeric"
								pattern="[0-9]*"
								maxlength="6"
								autocomplete="one-time-code"
								class="input w-full"
								placeholder="6-digit PIN"
								bind:value={pin}
								disabled={loading}
							/>
						</div>
						<label class="flex items-center gap-2 cursor-pointer">
							<input
								type="checkbox"
								class="checkbox"
								bind:checked={allowPersistent}
								disabled={loading}
							/>
							<span class="text-sm text-surface-600 dark:text-slate-400">
								Allow this device every time (until revoked)
							</span>
						</label>
						<button
							type="submit"
							class="btn preset-filled-primary-500"
							disabled={loading}
						>
							{loading ? 'Authorizing…' : 'Authorize'}
						</button>
					</form>
				{:else}
					<label class="flex items-center gap-2 cursor-pointer mb-2">
						<input type="checkbox" class="checkbox" bind:checked={allowPersistent} disabled={loading} />
						<span class="text-sm text-surface-600 dark:text-slate-400">Allow this device every time (until revoked)</span>
					</label>
					<button
						type="button"
						class="btn preset-filled-primary-500 w-full"
						disabled={loading}
						onclick={createRequest}
					>
						{loading ? 'Creating…' : 'Show QR for supervisor to scan'}
					</button>
				{/if}
			{/if}
		</div>
	</div>
</DisplayLayout>
