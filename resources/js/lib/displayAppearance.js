/**
 * Device-local display appearance: theme (shared with ThemeToggle) and zoom (display board).
 * Used by Display/Board settings modal — apply to document only after PIN/QR save (except initial zoom from storage on load).
 */

export const THEME_STORAGE_KEY = 'flexiqueue-theme';

/** @type {readonly ['light', 'flexiqueue', 'dark']} */
export const THEME_MODES = ['light', 'flexiqueue', 'dark'];

/**
 * @param {string} mode
 */
/**
 * @param {string} mode
 * @param {{ persistToGlobalStorage?: boolean }} [options]
 */
export function applyThemeModeToDocument(mode, options = {}) {
	const { persistToGlobalStorage = true } = options;
	if (typeof document === 'undefined') return;
	if (!THEME_MODES.includes(mode)) return;
	document.documentElement.setAttribute('data-mode', mode);
	if (!persistToGlobalStorage) return;
	try {
		localStorage.setItem(THEME_STORAGE_KEY, mode);
	} catch {
		// ignore
	}
}

export const DISPLAY_ZOOM_STORAGE_KEY = 'flexiqueue-display-zoom';

/** Preset zoom levels for the display board (device-local). Uses CSS `zoom` on &lt;html&gt; (best in Chromium). */
export const DISPLAY_ZOOM_LEVELS = [
	{ label: '75%', value: 0.75 },
	{ label: '85%', value: 0.85 },
	{ label: '100%', value: 1 },
	{ label: '110%', value: 1.1 },
	{ label: '125%', value: 1.25 },
];

/**
 * @param {unknown} value
 * @returns {number}
 */
export function normalizeDisplayZoom(value) {
	const n = Number(value);
	if (!Number.isFinite(n) || n < 0.5 || n > 2) return 1;
	const allowed = DISPLAY_ZOOM_LEVELS.map((x) => x.value);
	return allowed.includes(n) ? n : 1;
}

/**
 * @returns {number}
 */
export function readDisplayZoomFromStorage() {
	if (typeof window === 'undefined' || !window.localStorage) return 1;
	try {
		const raw = localStorage.getItem(DISPLAY_ZOOM_STORAGE_KEY);
		if (raw == null) return 1;
		return normalizeDisplayZoom(parseFloat(raw));
	} catch {
		return 1;
	}
}

/**
 * @param {unknown} scale
 * @param {{ persistToGlobalStorage?: boolean }} [options]
 */
export function applyDisplayZoomToDocument(scale, options = {}) {
	const { persistToGlobalStorage = true } = options;
	if (typeof document === 'undefined') return;
	const z = normalizeDisplayZoom(scale);
	if (z === 1) {
		document.documentElement.style.removeProperty('zoom');
	} else {
		document.documentElement.style.zoom = String(z);
	}
	if (!persistToGlobalStorage) return;
	try {
		localStorage.setItem(DISPLAY_ZOOM_STORAGE_KEY, String(z));
	} catch {
		// ignore
	}
}
