/**
 * Per plan: device-local HID barcode settings for public display, public triage, and staff triage binder.
 * Used on /display, /triage/start, and staff triage lookup-by-ID. Not persisted to DB; localStorage per device.
 */

const STORAGE_KEYS = {
	display: 'flexiqueue_display_hid_allow_on_this_device',
	triage: 'flexiqueue_triage_hid_allow_on_this_device',
	staff_binder: 'flexiqueue_staff_binder_hid_allow_on_this_device',
};

/**
 * @param {'display' | 'triage' | 'staff_binder'} context
 * @returns {boolean|null} true/false when set, null when not set
 */
export function getLocalAllowHidOnThisDevice(context) {
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
export function setLocalAllowHidOnThisDevice(context, value) {
	if (typeof window === 'undefined' || !window.localStorage) return;
	try {
		window.localStorage.setItem(STORAGE_KEYS[context], value ? 'true' : 'false');
	} catch {
		// ignore
	}
}

/**
 * Primary input is touch (phones, most tablets). Used to apply device-local default and inputmode.
 */
export function isMobileTouch() {
	if (typeof window === 'undefined' || !window.matchMedia) return false;
	return window.matchMedia('(pointer: coarse)').matches;
}

/**
 * Whether we should focus the HID barcode input: both program and device-local must allow.
 * When local not set: default true on non-mobile, false on mobile.
 *
 * @param {boolean} programHidEnabled
 * @param {'display' | 'triage' | 'staff_binder'} context
 * @returns {boolean}
 */
export function shouldFocusHidInput(programHidEnabled, context) {
	if (!programHidEnabled) return false;
	const local = getLocalAllowHidOnThisDevice(context);
	if (local !== null) return local;
	return !isMobileTouch(); // default true on desktop, false on mobile
}

/**
 * When true, set hidden barcode input to inputmode="none" to suppress keyboard (mobile HID mode).
 *
 * @param {boolean} programHidEnabled
 * @param {'display' | 'triage' | 'staff_binder'} context
 * @returns {boolean}
 */
export function shouldUseInputModeNone(programHidEnabled, context) {
	return isMobileTouch() && programHidEnabled && getLocalAllowHidOnThisDevice(context) === true;
}
