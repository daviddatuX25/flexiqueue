/**
 * Per-station public display (/display/station/{id}): device-only overrides stored in localStorage.
 * Staff /station sets server defaults; this blob overrides appearance/audio on this browser until Reset.
 */

/**
 * @param {number|string} stationId
 */
export function stationDisplayDeviceStorageKey(stationId) {
	return `flexiqueue-station-display-device-${stationId}`;
}

/**
 * @typedef {{ theme?: string; zoom?: number; muted?: boolean; volume?: number }} StationDisplayDeviceLocal
 */

/**
 * @param {number|string} stationId
 * @returns {StationDisplayDeviceLocal | null}
 */
export function readStationDisplayDeviceLocal(stationId) {
	if (typeof window === 'undefined' || !window.localStorage) return null;
	try {
		const raw = localStorage.getItem(stationDisplayDeviceStorageKey(stationId));
		if (raw == null || raw === '') return null;
		const o = JSON.parse(raw);
		if (!o || typeof o !== 'object') return null;
		return o;
	} catch {
		return null;
	}
}

/**
 * @param {number|string} stationId
 * @param {StationDisplayDeviceLocal} data
 */
export function writeStationDisplayDeviceLocal(stationId, data) {
	if (typeof window === 'undefined' || !window.localStorage) return;
	try {
		localStorage.setItem(stationDisplayDeviceStorageKey(stationId), JSON.stringify(data));
	} catch {
		// ignore
	}
}

/**
 * @param {number|string} stationId
 */
export function clearStationDisplayDeviceLocal(stationId) {
	if (typeof window === 'undefined' || !window.localStorage) return;
	try {
		localStorage.removeItem(stationDisplayDeviceStorageKey(stationId));
	} catch {
		// ignore
	}
}
