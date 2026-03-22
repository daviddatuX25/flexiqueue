/**
 * Staff footer QR: classify scan payloads and resolve token → status vs triage (device refactor Phase 1).
 * Approve-style QR prefixes are handled by qrApproveHandler before this module runs.
 */

const DEVICE_UNLOCK_REQUEST_QR_PREFIX = 'flexiqueue:device_unlock_request:';
const DEVICE_AUTH_REQUEST_QR_PREFIX = 'flexiqueue:device_auth_request:';
const DISPLAY_SETTINGS_REQUEST_QR_PREFIX = 'flexiqueue:display_settings_request:';
const PERMISSION_REQUEST_QR_PREFIX = 'flexiqueue:permission_request:';

/** @param {string} raw */
export function isApprovePayload(raw) {
	const s = raw.trim();
	return (
		s.startsWith(DEVICE_UNLOCK_REQUEST_QR_PREFIX) ||
		s.startsWith(DEVICE_AUTH_REQUEST_QR_PREFIX) ||
		s.startsWith(DISPLAY_SETTINGS_REQUEST_QR_PREFIX) ||
		s.startsWith(PERMISSION_REQUEST_QR_PREFIX)
	);
}

/**
 * Parse physical id, raw qr hash, or status URL → token lookup query.
 * @param {string} raw
 * @returns {{ type: 'physical_id', value: string } | { type: 'qr_hash', value: string } | null}
 */
export function parseTokenScanPayload(raw) {
	const trimmed = raw.trim();
	const branchA = trimmed.length <= 10 && /^[A-Za-z0-9]+$/.test(trimmed);
	const branchB = trimmed.length === 64 && /^[a-f0-9]+$/.test(trimmed);
	const lastSegment = trimmed.includes('/') ? (trimmed.split('/').pop() ?? '').split('?')[0].trim() : '';
	const branchUrl = lastSegment.length === 64 && /^[a-f0-9]+$/.test(lastSegment);

	if (branchA) {
		return { type: 'physical_id', value: trimmed };
	}
	if (branchB) {
		return { type: 'qr_hash', value: trimmed };
	}
	if (branchUrl) {
		return { type: 'qr_hash', value: lastSegment };
	}
	if (trimmed.length > 10) {
		return { type: 'physical_id', value: trimmed.slice(0, 10) };
	}
	return null;
}

/**
 * @param {string} raw
 * @param {{ csrfToken: string }} ctx
 * @returns {Promise<
 *   | { kind: 'not_token' }
 *   | { kind: 'lookup_error'; message: string }
 *   | { kind: 'token_deactivated' }
 *   | { kind: 'status'; qr_hash: string; physical_id: string }
 *   | { kind: 'triage'; token: { physical_id: string; qr_hash: string; status: string } }
 * >}
 */
export async function resolveStaffTokenScan(raw, { csrfToken }) {
	const parsed = parseTokenScanPayload(raw);
	if (!parsed) {
		return { kind: 'not_token' };
	}
	const params =
		parsed.type === 'physical_id'
			? `physical_id=${encodeURIComponent(parsed.value)}`
			: `qr_hash=${encodeURIComponent(parsed.value)}`;
	const res = await fetch(`/api/sessions/token-lookup?${params}`, {
		credentials: 'same-origin',
		headers: {
			Accept: 'application/json',
			'X-CSRF-TOKEN': csrfToken,
			'X-Requested-With': 'XMLHttpRequest',
		},
	});
	const data = await res.json().catch(() => ({}));
	if (res.status === 404) {
		return { kind: 'lookup_error', message: typeof data.message === 'string' ? data.message : 'Token not found.' };
	}
	if (!res.ok) {
		return {
			kind: 'lookup_error',
			message: typeof data.message === 'string' ? data.message : 'Token lookup failed.',
		};
	}
	const physical_id = data.physical_id;
	const qr_hash = data.qr_hash;
	const status = data.status;
	if (typeof physical_id !== 'string' || typeof qr_hash !== 'string' || typeof status !== 'string') {
		return { kind: 'lookup_error', message: 'Invalid token response.' };
	}
	if (status === 'deactivated') {
		return { kind: 'token_deactivated' };
	}
	if (status === 'in_use') {
		return { kind: 'status', qr_hash, physical_id };
	}
	if (status === 'available') {
		return { kind: 'triage', token: { physical_id, qr_hash, status } };
	}
	return { kind: 'lookup_error', message: `Token is marked as ${status}.` };
}
