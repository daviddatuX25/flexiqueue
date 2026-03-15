<script lang="ts">
	/**
	 * Per plan: legacy /display and /public-triage with no site slug show only this message.
	 * No site list, no picker. User must scan the QR provided by their administrator.
	 */
	import DisplayLayout from '../../Layouts/DisplayLayout.svelte';
	import { QrCode } from 'lucide-svelte';
</script>

<DisplayLayout programName={null} date="">
	<div class="flex flex-1 flex-col items-center justify-center px-6 py-12 text-center">
		<div
			class="flex flex-col items-center gap-6 rounded-2xl border border-surface-200 bg-surface-50/90 dark:bg-slate-800/90 dark:border-slate-700 p-8 shadow-lg max-w-md"
		>
			<div
				class="flex h-16 w-16 items-center justify-center rounded-full bg-primary-100 dark:bg-primary-900/50 text-primary-600 dark:text-primary-400"
				aria-hidden="true"
			>
				<QrCode class="h-8 w-8" />
			</div>
			<div class="space-y-2">
				<h1 class="text-xl font-semibold text-surface-950 dark:text-white">
					Please scan the QR provided by your administrator.
				</h1>
				<p class="text-sm text-surface-600 dark:text-slate-400">
					Use the QR code at the display or triage area to open the queue board or start your visit.
				</p>
			</div>
		</div>
	</div>
</DisplayLayout>
