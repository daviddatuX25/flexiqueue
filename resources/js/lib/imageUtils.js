/**
 * Client-side image compression for uploads.
 * Uses browser-image-compression; presets for avatar, hero/banner.
 * Per docs/ui-ux-tasks-phased.md Phase 3: modular, apply to Profile, Site, Program.
 */
import imageCompression from 'browser-image-compression';

const DEFAULT_OPTIONS = {
	useWebWorker: true,
	initialQuality: 0.85,
	maxSizeMB: 2,
};

/** Preset: profile avatar — smaller max dimension, ~50KB target */
export const AVATAR_PRESET = {
	...DEFAULT_OPTIONS,
	maxWidthOrHeight: 800,
	maxSizeMB: 0.05,
};

/** Preset: site hero / program banner / mini-blog images — ~1MB target */
export const HERO_BANNER_PRESET = {
	...DEFAULT_OPTIONS,
	maxWidthOrHeight: 1200,
	maxSizeMB: 1,
};

/**
 * Compress an image file for upload.
 * @param {File} file - Image file from input or drag-drop
 * @param {{ maxWidthOrHeight?: number, maxSizeMB?: number, initialQuality?: number, useWebWorker?: boolean }} options - Override defaults (or use AVATAR_PRESET / HERO_BANNER_PRESET)
 * @returns {Promise<File>} Compressed file (same name, typically WebP or original type)
 */
export async function compressImage(file, options = {}) {
	if (!file || !file.type?.startsWith('image/')) {
		return file;
	}
	const opts = { ...DEFAULT_OPTIONS, ...options };
	try {
		return await imageCompression(file, opts);
	} catch (err) {
		console.warn('Image compression failed, using original file:', err);
		return file;
	}
}

/**
 * Returns short helper text for image upload UI by preset.
 * @param {'avatar' | 'hero'} preset
 * @returns {string}
 */
export function getUploadHint(preset) {
	return preset === 'avatar'
		? 'Compressed automatically; recommended under 50 KB.'
		: 'Images are compressed for web.';
}
