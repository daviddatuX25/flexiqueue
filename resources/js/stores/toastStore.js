/**
 * Toast notification store. Per 07-UI-UX-SPECS.md Section 9.3.
 * Success/Info: auto-dismiss 3–5s. Error: persist until dismissed.
 */
import { writable, derived } from 'svelte/store';

/** @typedef {{ id: number; type: 'success' | 'error' | 'info'; message: string; duration?: number }} Toast */

const AUTO_DISMISS = { success: 3000, info: 5000, error: 0 };

/** @type {import('svelte/store').Writable<Toast[]>} */
const toastsStore = writable([]);
let nextId = 1;

/**
 * @param {string} message
 * @param {'success' | 'error' | 'info'} [type='info']
 */
export function toast(message, type = 'info') {
	const id = nextId++;
	const duration = AUTO_DISMISS[type];
	toastsStore.update((list) => [...list, { id, type, message, duration }]);
	if (duration > 0) {
		setTimeout(() => dismiss(id), duration);
	}
	return id;
}

/** @param {number} id */
export function dismiss(id) {
	toastsStore.update((list) => list.filter((t) => t.id !== id));
}

export const toasts = derived(toastsStore, ($t) => $t);
