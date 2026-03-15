/**
 * Shared QR scan-and-approve handler (dispatch table).
 * Used by Track overrides page and MobileLayout footer QR modal.
 * Per plan: flexiqueue:permission_request: | display_settings_request: | device_auth_request: → approve; anything else → "Unrecognized QR code".
 * 403 response → "Invalid or expired QR" toast.
 */
import { toaster } from './toaster.js';

const PERMISSION_REQUEST_QR_PREFIX = 'flexiqueue:permission_request:';
const DISPLAY_SETTINGS_REQUEST_QR_PREFIX = 'flexiqueue:display_settings_request:';
const DEVICE_AUTH_REQUEST_QR_PREFIX = 'flexiqueue:device_auth_request:';
const DEVICE_UNLOCK_REQUEST_QR_PREFIX = 'flexiqueue:device_unlock_request:';

function getCsrfToken() {
	return (document.querySelector('meta[name="csrf-token"]')?.content ?? '').toString();
}

function parseIdAndToken(rest) {
	const parts = rest.split(':');
	if (parts.length < 2) return null;
	const id = parseInt(parts[0], 10);
	const requestToken = parts[1];
	if (isNaN(id) || !requestToken) return null;
	return { id, requestToken };
}

function getMessage(data, fallback) {
	const msg = data && typeof data === 'object' && data.message;
	return typeof msg === 'string' && msg.length > 0 ? msg : fallback;
}

/**
 * @param {string} raw - Trimmed scanned string
 * @param {{ onClose: () => void; onSuccess?: () => void }} options
 * @returns {Promise<void>}
 */
export async function handleQrApproveScan(raw, { onClose, onSuccess }) {
	const s = raw.trim();

	// Dispatch table: unknown prefix → Unrecognized QR code
	if (s.startsWith(DEVICE_UNLOCK_REQUEST_QR_PREFIX)) {
		const rest = s.slice(DEVICE_UNLOCK_REQUEST_QR_PREFIX.length);
		const parsed = parseIdAndToken(rest);
		if (!parsed) {
			toaster.error({ title: 'Invalid or expired QR.' });
			onClose();
			return;
		}
		try {
			const res = await fetch(`/api/device-unlock-requests/${parsed.id}/approve`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-CSRF-TOKEN': getCsrfToken(),
					'X-Requested-With': 'XMLHttpRequest',
				},
				credentials: 'same-origin',
				body: JSON.stringify({ request_token: parsed.requestToken }),
			});
			const data = await res.json().catch(() => ({}));
			if (res.status === 403) {
				toaster.error({ title: getMessage(data, 'Invalid or expired QR.') });
				onClose();
				return;
			}
			if (res.status === 409) {
				toaster.warning({ title: getMessage(data, 'Request already handled.') });
				onClose();
				return;
			}
			if (res.ok) {
				toaster.success({ title: 'Device unlock approved.' });
				onSuccess?.();
				onClose();
				return;
			}
			toaster.error({ title: getMessage(data, 'Failed to approve.') });
		} catch {
			toaster.error({ title: 'Network error. Try again.' });
		}
		onClose();
		return;
	}

	if (s.startsWith(DEVICE_AUTH_REQUEST_QR_PREFIX)) {
		const rest = s.slice(DEVICE_AUTH_REQUEST_QR_PREFIX.length);
		const parsed = parseIdAndToken(rest);
		if (!parsed) {
			toaster.error({ title: 'Invalid or expired QR.' });
			onClose();
			return;
		}
		try {
			const res = await fetch(`/api/device-authorization-requests/${parsed.id}/approve`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-CSRF-TOKEN': getCsrfToken(),
					'X-Requested-With': 'XMLHttpRequest',
				},
				credentials: 'same-origin',
				body: JSON.stringify({ request_token: parsed.requestToken }),
			});
			const data = await res.json().catch(() => ({}));
			if (res.status === 403) {
				toaster.error({ title: getMessage(data, 'Invalid or expired QR.') });
				onClose();
				return;
			}
			if (res.status === 409) {
				toaster.warning({ title: getMessage(data, 'Request already handled.') });
				onClose();
				return;
			}
			if (res.ok) {
				toaster.success({ title: 'Device authorized.' });
				onSuccess?.();
				onClose();
				return;
			}
			toaster.error({ title: getMessage(data, 'Failed to approve.') });
		} catch {
			toaster.error({ title: 'Network error. Try again.' });
		}
		onClose();
		return;
	}

	if (s.startsWith(DISPLAY_SETTINGS_REQUEST_QR_PREFIX)) {
		const rest = s.slice(DISPLAY_SETTINGS_REQUEST_QR_PREFIX.length);
		const parsed = parseIdAndToken(rest);
		if (!parsed) {
			toaster.error({ title: 'Invalid or expired QR.' });
			onClose();
			return;
		}
		try {
			const res = await fetch(`/api/display-settings-requests/${parsed.id}/approve`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-CSRF-TOKEN': getCsrfToken(),
					'X-Requested-With': 'XMLHttpRequest',
				},
				credentials: 'same-origin',
				body: JSON.stringify({ request_token: parsed.requestToken }),
			});
			const data = await res.json().catch(() => ({}));
			if (res.status === 403) {
				toaster.error({ title: getMessage(data, 'Invalid or expired QR.') });
				onClose();
				return;
			}
			if (res.status === 409) {
				toaster.warning({ title: getMessage(data, 'Request already handled.') });
				onClose();
				return;
			}
			if (res.ok) {
				toaster.success({ title: 'Display settings updated.' });
				onSuccess?.();
				onClose();
				return;
			}
			toaster.error({ title: getMessage(data, 'Failed to approve.') });
		} catch {
			toaster.error({ title: 'Network error. Try again.' });
		}
		onClose();
		return;
	}

	if (s.startsWith(PERMISSION_REQUEST_QR_PREFIX)) {
		const rest = s.slice(PERMISSION_REQUEST_QR_PREFIX.length);
		const parsed = parseIdAndToken(rest);
		if (!parsed) {
			toaster.error({ title: 'Invalid or expired QR.' });
			onClose();
			return;
		}
		try {
			const res = await fetch(`/api/permission-requests/${parsed.id}/approve`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-CSRF-TOKEN': getCsrfToken(),
					'X-Requested-With': 'XMLHttpRequest',
				},
				credentials: 'same-origin',
				body: JSON.stringify({ request_token: parsed.requestToken }),
			});
			const data = await res.json().catch(() => ({}));
			if (res.status === 403) {
				toaster.error({ title: getMessage(data, 'Invalid or expired QR.') });
				onClose();
				return;
			}
			if (res.status === 409) {
				toaster.warning({ title: getMessage(data, 'Request already handled.') });
				onClose();
				return;
			}
			if (res.ok) {
				toaster.success({ title: 'Request approved.' });
				onSuccess?.();
				onClose();
				return;
			}
			toaster.error({ title: getMessage(data, 'Failed to approve.') });
		} catch {
			toaster.error({ title: 'Network error. Try again.' });
		}
		onClose();
		return;
	}

	// Unrecognized prefix
	toaster.warning({ title: 'Unrecognized QR code.' });
	onClose();
}
