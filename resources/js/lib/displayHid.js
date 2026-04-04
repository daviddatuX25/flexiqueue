/**
 * Per plan: device-local HID barcode settings for display board, kiosk, and staff binder.
 * Used on /display, /site/.../kiosk/..., and staff triage lookup-by-ID. Not persisted to DB; localStorage per device.
 */

const STORAGE_KEYS = {
	display: 'flexiqueue_display_hid_allow_on_this_device',
	/** @deprecated legacy key; migrated to `kiosk` on read */
	triage: 'flexiqueue_triage_hid_allow_on_this_device',
	kiosk: 'flexiqueue_kiosk_hid_allow_on_this_device',
	staff_binder: 'flexiqueue_staff_binder_hid_allow_on_this_device',
};

const PERSISTENT_HID_KEYS = {
	display: 'flexiqueue_display_hid_persistent_on_this_device',
	triage: 'flexiqueue_triage_hid_persistent_on_this_device',
	kiosk: 'flexiqueue_kiosk_hid_persistent_on_this_device',
	staff_binder: 'flexiqueue_staff_binder_hid_persistent_on_this_device',
};

/** One-time copy flexiqueue_triage_* → flexiqueue_kiosk_* when kiosk key missing. */
function migrateLegacyTriageHidToKiosk() {
	if (typeof window === 'undefined' || !window.localStorage) return;
	try {
		if (window.localStorage.getItem(STORAGE_KEYS.kiosk) === null && window.localStorage.getItem(STORAGE_KEYS.triage) !== null) {
			window.localStorage.setItem(STORAGE_KEYS.kiosk, window.localStorage.getItem(STORAGE_KEYS.triage));
		}
		if (
			window.localStorage.getItem(PERSISTENT_HID_KEYS.kiosk) === null &&
			window.localStorage.getItem(PERSISTENT_HID_KEYS.triage) !== null
		) {
			window.localStorage.setItem(PERSISTENT_HID_KEYS.kiosk, window.localStorage.getItem(PERSISTENT_HID_KEYS.triage));
		}
	} catch {
		// ignore
	}
}

/**
 * @param {'display' | 'triage' | 'kiosk' | 'staff_binder'} context
 * @returns {boolean|null} true/false when set, null when not set
 */
export function getLocalAllowHidOnThisDevice(context) {
	if (context === 'kiosk') migrateLegacyTriageHidToKiosk();
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
 * Device-local allow HID for settings UI: explicit override wins; otherwise mirrors program.
 *
 * @param {'display' | 'triage' | 'kiosk' | 'staff_binder'} context
 * @param {boolean} programHidEnabled
 * @returns {boolean}
 */
export function getEffectiveLocalAllowHid(context, programHidEnabled) {
	const local = getLocalAllowHidOnThisDevice(context);
	if (local !== null) return local;
	return programHidEnabled;
}

/**
 * Persistent HID refocus: localStorage override wins; otherwise program default (kiosk: program setting).
 *
 * @param {'display' | 'triage' | 'kiosk' | 'staff_binder'} context
 * @param {boolean} programDefaultPersistent
 * @returns {boolean}
 */
export function getEffectivePersistentHid(context, programDefaultPersistent) {
	if (context === 'kiosk') migrateLegacyTriageHidToKiosk();
	if (typeof window === 'undefined' || !window.localStorage) return programDefaultPersistent;
	try {
		const raw = window.localStorage.getItem(PERSISTENT_HID_KEYS[context]);
		if (raw === null) return programDefaultPersistent;
		if (raw === 'true') return true;
		if (raw === 'false') return false;
		return programDefaultPersistent;
	} catch {
		return programDefaultPersistent;
	}
}

/**
 * @param {'display' | 'triage' | 'kiosk' | 'staff_binder'} context
 * @returns {boolean} true if user has saved a per-device persistent-HID preference
 */
export function hasLocalPersistentHidOverride(context) {
	if (context === 'kiosk') migrateLegacyTriageHidToKiosk();
	if (typeof window === 'undefined' || !window.localStorage) return false;
	try {
		return window.localStorage.getItem(PERSISTENT_HID_KEYS[context]) !== null;
	} catch {
		return false;
	}
}

/**
 * @param {'display' | 'triage' | 'kiosk' | 'staff_binder'} context
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
 * Whether we should focus the HID barcode input: program must allow; device-local may override.
 * When local is unset, mirror the program HID flag (kiosk/display/staff program toggles).
 *
 * @param {boolean} programHidEnabled
 * @param {'display' | 'triage' | 'kiosk' | 'staff_binder'} context
 * @returns {boolean}
 */
export function shouldFocusHidInput(programHidEnabled, context) {
	if (!programHidEnabled) return false;
	const local = getLocalAllowHidOnThisDevice(context);
	if (local !== null) return local;
	// No device override: mirror program (kiosk/display/staff program toggles).
	return programHidEnabled;
}

/**
 * When true, set hidden barcode input to inputmode="none" to suppress keyboard (mobile HID mode).
 *
 * @param {boolean} programHidEnabled
 * @param {'display' | 'triage' | 'kiosk' | 'staff_binder'} context
 * @returns {boolean}
 */
export function shouldUseInputModeNone(programHidEnabled, context) {
	if (!isMobileTouch() || !programHidEnabled) return false;
	const local = getLocalAllowHidOnThisDevice(context);
	if (local === false) return false;
	// local null or true: suppress soft keyboard when program allows HID
	return true;
}

/**
 * Persistent HID: when true, HID input is refocused periodically when the scan modal is closed
 * (Display Board–like). When false, HID only works when the scan modal is open (kiosk-like).
 *
 * @param {'display' | 'triage' | 'kiosk' | 'staff_binder'} context
 * @returns {boolean} default true for display, false for kiosk/triage/staff_binder
 */
export function getLocalPersistentHidOnThisDevice(context) {
	if (context === 'kiosk') migrateLegacyTriageHidToKiosk();
	if (typeof window === 'undefined' || !window.localStorage) return context === 'display';
	try {
		const raw = window.localStorage.getItem(PERSISTENT_HID_KEYS[context]);
		if (raw === null) return context === 'display';
		if (raw === 'true') return true;
		if (raw === 'false') return false;
		return context === 'display';
	} catch {
		return context === 'display';
	}
}

/**
 * @param {'display' | 'triage' | 'kiosk' | 'staff_binder'} context
 * @param {boolean} value
 */
export function setLocalPersistentHidOnThisDevice(context, value) {
	if (typeof window === 'undefined' || !window.localStorage) return;
	try {
		window.localStorage.setItem(PERSISTENT_HID_KEYS[context], value ? 'true' : 'false');
	} catch {
		// ignore
	}
}

/** Clear kiosk device-local HID preferences (and legacy triage keys). */
export function clearKioskDeviceLocalHidSettings() {
	if (typeof window === 'undefined' || !window.localStorage) return;
	try {
		window.localStorage.removeItem(STORAGE_KEYS.kiosk);
		window.localStorage.removeItem(STORAGE_KEYS.triage);
		window.localStorage.removeItem(PERSISTENT_HID_KEYS.kiosk);
		window.localStorage.removeItem(PERSISTENT_HID_KEYS.triage);
	} catch {
		// ignore
	}
}

/** @deprecated Use clearKioskDeviceLocalHidSettings */
export function clearTriageDeviceLocalHidSettings() {
	clearKioskDeviceLocalHidSettings();
}
