<script>
	/**
	 * LogoutConfirm — confirmation modal before logging out.
	 * Per ui-ux-tasks-checklist: "Logout modal confirmation — Add confirm step before logging out."
	 */
	import { router } from '@inertiajs/svelte';
	import ConfirmModal from './ConfirmModal.svelte';

	let { open = false, onClose = () => {} } = $props();

	function handleConfirm() {
		onClose();
		router.post('/logout');
	}
</script>

<ConfirmModal
	{open}
	title="Log out"
	message="Are you sure you want to log out?"
	confirmLabel="Log out"
	cancelLabel="Cancel"
	variant="neutral"
	onConfirm={handleConfirm}
	onCancel={onClose}
/>
