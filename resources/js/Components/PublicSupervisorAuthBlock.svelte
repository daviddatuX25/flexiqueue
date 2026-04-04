<script>
	/**
	 * Shared "Apply changes" block for public display board, station display, and kiosk:
	 * PIN / QR supervisor approval before saving, with logged-in staff/admin bypass (no PIN/QR).
	 */
	import { onDestroy } from 'svelte';
	import AuthChoiceButtons from './AuthChoiceButtons.svelte';
	import PinOrQrInput from './PinOrQrInput.svelte';
	import { toaster } from '../lib/toaster.js';

	export const DISPLAY_SETTINGS_REQUEST_QR_PREFIX = 'flexiqueue:display_settings_request:';

	let {
		programId = null,
		canBypassAuth = false,
		getCsrfToken = () => '',
		/** Body fields for POST /api/public/display-settings-requests (program_id added automatically). */
		getRequestBody = () => ({}),
		disabled = false,
		saving = false,
		authMode = $bindable('pin'),
		/** Synced for parent to disable fields while QR approval is pending. */
		authWaiting = $bindable(false),
		onQrApproved = async () => {},
		onFlowError = (/** @type {string} */ _msg) => {},
	} = $props();

	let pinOrQrRef = $state(null);
	let requestId = $state(/** @type {number | null} */ (null));
	let requestToken = $state(/** @type {string | null} */ (null));
	let requestState = $state(/** @type {'idle' | 'waiting'} */ ('idle'));
	let pollIntervalId = $state(/** @type {ReturnType<typeof setInterval> | null} */ (null));
	let qrCreating = $state(false);

	const waiting = $derived(requestState === 'waiting');
	const panelBusy = $derived(disabled || saving || qrCreating);

	/** @returns {Record<string, string> | null} null = invalid / wrong mode; {} = staff bypass */
	export function buildPinOrQrPayload() {
		if (canBypassAuth) {
			return {};
		}
		if (authMode === 'request') {
			return null;
		}
		return pinOrQrRef?.buildPinOrQrPayload?.() ?? null;
	}

	export async function cancelOngoingRequest() {
		requestState = 'idle';
		const id = requestId;
		const token = requestToken;
		requestId = null;
		requestToken = null;
		if (pollIntervalId != null) {
			clearInterval(pollIntervalId);
			pollIntervalId = null;
		}
		if (id != null && token) {
			try {
				await fetch(`/api/public/display-settings-requests/${id}/cancel`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-CSRF-TOKEN': getCsrfToken(),
						Accept: 'application/json',
					},
					credentials: 'include',
					body: JSON.stringify({ request_token: token }),
				});
			} catch {
				// ignore
			}
		}
	}

	onDestroy(() => {
		cancelOngoingRequest();
	});

	async function createDisplaySettingsRequest() {
		if (programId == null || qrCreating) return;
		qrCreating = true;
		try {
			const body = { program_id: programId, ...getRequestBody() };
			const res = await fetch('/api/public/display-settings-requests', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-CSRF-TOKEN': getCsrfToken(),
					'X-Requested-With': 'XMLHttpRequest',
				},
				credentials: 'same-origin',
				body: JSON.stringify(body),
			});
			const data = await res.json().catch(() => ({}));
			if (!res.ok) {
				const msg = data.message || 'Failed to create request.';
				onFlowError(msg);
				toaster.error({ title: msg });
				return;
			}
			requestId = data.id;
			requestToken = data.request_token;
			requestState = 'waiting';
			const id = data.id;
			const token = data.request_token;
			pollIntervalId = setInterval(async () => {
				try {
					const r = await fetch(
						`/api/public/display-settings-requests/${id}?token=${encodeURIComponent(token)}`,
						{ credentials: 'same-origin' }
					);
					const d = await r.json().catch(() => ({}));
					if (d.status === 'approved') {
						if (pollIntervalId != null) clearInterval(pollIntervalId);
						pollIntervalId = null;
						requestId = null;
						requestToken = null;
						requestState = 'idle';
						await onQrApproved();
					} else if (d.status === 'rejected' || d.status === 'cancelled') {
						if (pollIntervalId != null) clearInterval(pollIntervalId);
						pollIntervalId = null;
						requestState = 'idle';
						requestId = null;
						requestToken = null;
						toaster.warning({
							title: d.status === 'rejected' ? 'Request was rejected.' : 'Request was cancelled.',
						});
					}
				} catch {
					// ignore poll errors
				}
			}, 2000);
		} finally {
			qrCreating = false;
		}
	}
</script>

<div class="border-t border-surface-200 pt-4 flex flex-col gap-2">
	<h3 class="text-sm font-semibold text-surface-950">Apply changes</h3>
	{#if canBypassAuth}
		<p class="text-xs text-surface-950/60">
			Signed in as staff — you can save without PIN or QR. Settings above are applied only when you save.
		</p>
	{:else}
		<p class="text-xs text-surface-950/60">
			Authorize with PIN or show QR for supervisor to scan. Settings above are applied only when you save.
		</p>
		<AuthChoiceButtons includeRequest={true} disabled={panelBusy || waiting} bind:mode={authMode} />
		{#if waiting && requestId != null && requestToken != null}
			<div class="flex flex-col items-center gap-3 py-4">
				<p class="text-sm font-medium text-surface-950">Waiting for approval…</p>
				<p class="text-xs text-surface-950/60 text-center">
					Ask the program supervisor or admin to scan this QR on the Track overrides page.
				</p>
				<img
					class="rounded-container border border-surface-200 bg-white p-2"
					alt="QR for supervisor to scan"
					width="200"
					height="200"
					src={`https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(DISPLAY_SETTINGS_REQUEST_QR_PREFIX + requestId + ':' + requestToken)}`}
				/>
				<button type="button" class="btn preset-tonal btn-sm touch-target-h" onclick={cancelOngoingRequest}>
					Cancel request
				</button>
			</div>
		{:else if authMode === 'request'}
			<button
				type="button"
				class="btn preset-filled-primary-500"
				onclick={createDisplaySettingsRequest}
				disabled={panelBusy || programId == null}
			>
				{qrCreating ? 'Creating…' : 'Show QR for supervisor to scan'}
			</button>
		{:else}
			<PinOrQrInput bind:this={pinOrQrRef} disabled={panelBusy} mode={authMode} />
		{/if}
	{/if}
</div>
