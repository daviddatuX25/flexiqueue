<script lang="ts">
	/**
	 * Shown inside scan modals when HID is enabled. Pulsating, centered.
	 * When HID input has focus: "HID scanner turned on, waiting for scan."
	 * When focus lost: "HID scanner turned on, click me to allow scans" — click refocuses the HID input.
	 */
	let {
		focused = true,
		onRequestFocus = () => {},
		class: className = '',
	} = $props<{
		focused?: boolean;
		onRequestFocus?: () => void;
		class?: string;
	}>();
</script>

<p
	role="status"
	aria-live="polite"
	class="text-sm text-surface-600 text-center py-2 px-3 rounded-container border border-surface-200 bg-surface-50 animate-pulse {focused ? '' : 'cursor-pointer hover:bg-surface-100 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2'} {className}"
	tabindex={focused ? -1 : 0}
	onclick={(e) => {
		if (!focused) {
			e.preventDefault();
			onRequestFocus();
		}
	}}
	onkeydown={(e) => {
		if (!focused && (e.key === 'Enter' || e.key === ' ')) {
			e.preventDefault();
			onRequestFocus();
		}
	}}
>
	{focused ? 'HID scanner turned on, waiting for scan.' : 'HID scanner turned on, click me to allow scans.'}
</p>
