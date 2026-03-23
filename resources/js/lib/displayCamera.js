import {
	clearKioskDeviceLocalHidSettings,
	getLocalAllowHidOnThisDevice,
	hasLocalPersistentHidOverride,
} from './displayHid.js';

/**
 * Per plan: device-local camera/QR scanner settings for display board, kiosk, and staff binder.
 * Not persisted to DB; localStorage per device.
 *
 * Default behavior: allowed (true) when not explicitly set.
 */
const STORAGE_KEYS = {
	display: 'flexiqueue_display_camera_allow_on_this_device',
	/** @deprecated legacy key; migrated to `kiosk` on read */
	triage: 'flexiqueue_triage_camera_allow_on_this_device',
	kiosk: 'flexiqueue_kiosk_camera_allow_on_this_device',
	staff_binder: 'flexiqueue_staff_binder_camera_allow_on_this_device',
};

function migrateLegacyTriageCameraToKiosk() {
	if (typeof window === 'undefined' || !window.localStorage) return;
	try {
		if (window.localStorage.getItem(STORAGE_KEYS.kiosk) === null && window.localStorage.getItem(STORAGE_KEYS.triage) !== null) {
			window.localStorage.setItem(STORAGE_KEYS.kiosk, window.localStorage.getItem(STORAGE_KEYS.triage));
		}
	} catch {
		// ignore
	}
}

/**
 * @param {'display' | 'triage' | 'kiosk' | 'staff_binder'} context
 * @returns {boolean|null} true/false when set, null when not set
 */
export function getLocalAllowCameraOnThisDevice(context) {
	if (context === 'kiosk') migrateLegacyTriageCameraToKiosk();
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
 * @param {'display' | 'triage' | 'kiosk' | 'staff_binder'} context
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
 * When local is unset, use programDefault (mirror program “apply to all” on this device).
 *
 * @param {'display' | 'triage' | 'kiosk' | 'staff_binder'} context
 * @param {boolean} [programDefault=true] Program allows camera when local unset
 * @returns {boolean}
 */
export function shouldAllowCameraScanner(context, programDefault = true) {
	const local = getLocalAllowCameraOnThisDevice(context);
	if (local !== null) return local === true;
	return programDefault === true;
}

/** Clear kiosk device-local camera preference (and legacy triage key). */
export function clearKioskDeviceLocalCameraSettings() {
	if (typeof window === 'undefined' || !window.localStorage) return;
	try {
		window.localStorage.removeItem(STORAGE_KEYS.kiosk);
		window.localStorage.removeItem(STORAGE_KEYS.triage);
	} catch {
		// ignore
	}
}

/** @deprecated Use clearKioskDeviceLocalCameraSettings */
export function clearTriageDeviceLocalCameraSettings() {
	clearKioskDeviceLocalCameraSettings();
}

/** Clear all kiosk “this device” overrides (HID + persistent HID + camera). */
export function clearKioskDeviceLocalSettings() {
	clearKioskDeviceLocalHidSettings();
	clearKioskDeviceLocalCameraSettings();
}

/** @deprecated Use clearKioskDeviceLocalSettings */
export function clearTriageDeviceLocalSettings() {
	clearKioskDeviceLocalSettings();
}

/** True if any kiosk per-device localStorage override is set (includes legacy triage keys). */
export function hasKioskDeviceLocalOverrides() {
	if (getLocalAllowHidOnThisDevice('kiosk') !== null) return true;
	if (getLocalAllowCameraOnThisDevice('kiosk') !== null) return true;
	if (hasLocalPersistentHidOverride('kiosk')) return true;
	return false;
}

/** @deprecated Use hasKioskDeviceLocalOverrides */
export function hasTriageDeviceLocalOverrides() {
	return hasKioskDeviceLocalOverrides();
}
