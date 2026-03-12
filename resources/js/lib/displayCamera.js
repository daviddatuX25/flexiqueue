/**
 * Per plan: device-local camera/QR scanner settings for public display, public triage, and staff triage binder.
 * Not persisted to DB; localStorage per device.
 *
 * Default behavior: allowed (true) when not explicitly set.
 */
const STORAGE_KEYS = {
	display: 'flexiqueue_display_camera_allow_on_this_device',
	triage: 'flexiqueue_triage_camera_allow_on_this_device',
	staff_binder: 'flexiqueue_staff_binder_camera_allow_on_this_device',
};

/**
 * @param {'display' | 'triage' | 'staff_binder'} context
 * @returns {boolean|null} true/false when set, null when not set
 */
export function getLocalAllowCameraOnThisDevice(context) {
	if (typeof window === 'undefined' || !window.localStorage) return null;
	try {
		const raw = window.localStorage.getItem(STORAGE_KEYS[context]);
		if (raw === null) return null;
		if (raw === 'true') return true;
		if (raw === 'false') return false;
		return null;
	} catch {
		return null;
	}
}

/**
 * @param {'display' | 'triage' | 'staff_binder'} context
 * @param {boolean} value
 */
export function setLocalAllowCameraOnThisDevice(context, value) {
	if (typeof window === 'undefined' || !window.localStorage) return;
	try {
		window.localStorage.setItem(STORAGE_KEYS[context], value ? 'true' : 'false');
	} catch {
		// ignore
	}
}

/**
 * Default ON when not set.
 *
 * @param {'display' | 'triage' | 'staff_binder'} context
 * @returns {boolean}
 */
export function shouldAllowCameraScanner(context) {
	const local = getLocalAllowCameraOnThisDevice(context);
	return local === null ? true : local === true;
}

