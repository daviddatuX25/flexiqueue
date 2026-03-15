<script lang="ts">
	/**
	 * Reusable ID number input: optional mask toggle (hide like password) and optional "Scan ID" button.
	 * Use wherever we collect or display ID numbers for consistent UX and to avoid duplication.
	 */
	import { Camera, Eye, EyeOff } from 'lucide-svelte';

	let {
		value = $bindable(''),
		placeholder = 'Optional or scan below',
		disabled = false,
		showMaskToggle = true,
		showScanButton = false,
		onScanClick,
		class: className = '',
		inputClass = 'input rounded-container border border-surface-200 px-3 py-2 text-xs w-full',
		testId = 'id-number-input',
		/** When true, scan button uses full width below the input (e.g. in narrow forms). */
		scanButtonFullWidth = false,
		onKeydown,
	}: {
		value?: string;
		placeholder?: string;
		disabled?: boolean;
		showMaskToggle?: boolean;
		showScanButton?: boolean;
		onScanClick?: () => void;
		class?: string;
		inputClass?: string;
		testId?: string;
		scanButtonFullWidth?: boolean;
		onKeydown?: (e: KeyboardEvent) => void;
	} = $props();

	let masked = $state(true);
</script>

<div class="flex flex-col gap-1 {className}">
	<div class="flex items-center gap-1 min-w-0">
		<input
			type={masked ? 'password' : 'text'}
			class="{inputClass} flex-1 min-w-0"
			placeholder={placeholder}
			bind:value
			{disabled}
			data-testid={testId}
			autocomplete="off"
			onkeydown={onKeydown}
		/>
		{#if showMaskToggle}
			<button
				type="button"
				class="btn btn-icon preset-tonal shrink-0 touch-target"
				aria-label={masked ? 'Show ID number' : 'Hide ID number'}
				title={masked ? 'Show' : 'Hide'}
				onclick={() => (masked = !masked)}
				{disabled}
			>
				{#if masked}
					<Eye class="w-4 h-4" />
				{:else}
					<EyeOff class="w-4 h-4" />
				{/if}
			</button>
		{/if}
	</div>
	{#if showScanButton && onScanClick}
		<button
			type="button"
			class="btn preset-filled-primary-500 text-sm touch-target-h flex items-center justify-center gap-2 {scanButtonFullWidth ? 'w-full' : 'w-fit'}"
			onclick={onScanClick}
			{disabled}
			data-testid="{testId}-scan"
		>
			<Camera class="w-4 h-4 shrink-0" />
			Scan ID to capture number
		</button>
	{/if}
</div>
