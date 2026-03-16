/**
 * Centralized toast: Skeleton Toaster (Zag.js) singleton.
 * Per docs/TOAST-MIGRATION-MAP.md and centralized toast plan (Option B).
 */
import { createToaster } from '@skeletonlabs/skeleton-svelte';

export const toaster = createToaster({
	placement: 'top-end',
	max: 5,
	// Inset below header so toasts don't overlap nav/controls
	offsets: { top: '4.5rem', right: '1rem', bottom: '1rem', left: '1rem' },
});

/**
 * Backward-compatible wrapper: toast(message, type) → toaster[type]({ title }).
 *
 * Message structure (FlexiQueueToaster enforces):
 * - title: short heading (line-clamp-2). Long text truncated with "..."
 * - description: optional detail (line-clamp-3). Use for actionable hints.
 * Callers: keep title brief; put long copy in description.
 *
 * @param {string} message
 * @param {'success' | 'error' | 'info' | 'warning'} [type='info']
 */
export function toast(message, type = 'info') {
	if (type === 'warning') {
		toaster.warning({ title: message });
	} else {
		toaster[type]({ title: message });
	}
}
