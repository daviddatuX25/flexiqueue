<script lang="ts">
	/**
	 * FlexiQueue Toast: icon, title, description, optional action.
	 * Uses FlexiQueue theme (primary green). Supports action: { label, onClick }.
	 */
	import { fly } from 'svelte/transition';
	import { CheckCircle2, AlertTriangle, XCircle, Info, X } from 'lucide-svelte';
	import * as toast from '@zag-js/toast';
	import { normalizeProps, useMachine } from '@zag-js/svelte';

	interface Props {
		toast: toast.Options;
		index: number;
		parent: toast.GroupService;
		base?: string;
		width?: string;
		padding?: string;
		rounded?: string;
		classes?: string;
		messageBase?: string;
		messageClasses?: string;
		titleBase?: string;
		titleClasses?: string;
		descriptionBase?: string;
		descriptionClasses?: string;
		btnDismissBase?: string;
		btnDismissClasses?: string;
		btnDismissTitle?: string;
		btnDismissAriaLabel?: string;
		stateInfo?: string;
		stateSuccess?: string;
		stateWarning?: string;
		stateError?: string;
	}

	let {
		toast: toastOptions,
		index,
		parent,
		base = 'relative flex items-start gap-3',
		width = 'min-w-[280px] max-w-md',
		padding = 'p-4',
		rounded = 'rounded-xl',
		classes = '',
		messageBase = 'flex-1 min-w-0',
		messageClasses = '',
		titleBase = 'text-sm font-semibold',
		titleClasses = 'text-surface-950',
		descriptionBase = 'mt-0.5 text-xs',
		descriptionClasses = 'text-surface-600',
		btnDismissBase = '',
		btnDismissClasses = 'text-surface-400 hover:text-surface-600 transition-colors',
		btnDismissTitle = 'Close',
		btnDismissAriaLabel = 'Close',
		stateInfo = 'bg-surface-50 border border-surface-200 shadow-lg',
		stateSuccess = 'bg-surface-50 border border-primary-200 shadow-lg',
		stateWarning = 'bg-surface-50 border border-warning-200 shadow-lg',
		stateError = 'bg-surface-50 border border-error-200 shadow-lg'
	}: Props = $props();

	const service = useMachine(toast.machine, () => ({
		...toastOptions,
		parent,
		index
	}));
	const api = $derived(toast.connect(service, normalizeProps));
	const rxState = $derived(
		api.type === 'success'
			? stateSuccess
			: api.type === 'warning'
				? stateWarning
				: api.type === 'error'
					? stateError
					: stateInfo
	);
	const iconBg = $derived(
		api.type === 'success'
			? 'bg-primary-50 text-primary-600'
			: api.type === 'warning'
				? 'bg-warning-50 text-warning-600'
				: api.type === 'error'
					? 'bg-error-50 text-error-600'
					: 'bg-primary-50 text-primary-600'
	);
	const IconComponent = $derived(
		api.type === 'success'
			? CheckCircle2
			: api.type === 'warning'
				? AlertTriangle
				: api.type === 'error'
					? XCircle
					: Info
	);
</script>

<div
	class="{base} {width} {padding} {rounded} {rxState} {classes}"
	{...api.getRootProps()}
	data-testid="toast-root"
	in:fly={{ y: 20, duration: 400 }}
>
	<div
		class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {iconBg}"
		aria-hidden="true"
	>
		<IconComponent size={20} />
	</div>

	<div class="{messageBase} {messageClasses}" data-testid="toast-message">
		<p class="{titleBase} {titleClasses} line-clamp-2" {...api.getTitleProps()} data-testid="toast-title">
			{api.title}
		</p>
		{#if api.description}
			<p class="{descriptionBase} {descriptionClasses} line-clamp-3" {...api.getDescriptionProps()} data-testid="toast-description">
				{api.description}
			</p>
		{/if}
		{#if toastOptions?.action}
			<button
				type="button"
				class="btn preset-filled-primary-500 mt-3 rounded-lg px-3 py-1.5 text-xs font-medium transition-colors hover:opacity-90 active:scale-95"
				{...api.getActionTriggerProps()}
				data-testid="toast-action"
			>
				{toastOptions.action.label}
			</button>
		{/if}
	</div>

	{#if api.closable}
		<button
			class="absolute top-3 right-3 {btnDismissBase} {btnDismissClasses}"
			title={btnDismissTitle}
			aria-label={btnDismissAriaLabel}
			{...api.getCloseTriggerProps()}
			data-testid="toast-dismiss"
		>
			<X size={16} />
		</button>
	{/if}
</div>

<style>
	[data-part='root'] {
		translate: var(--x) var(--y);
		scale: var(--scale);
		z-index: var(--z-index);
		height: var(--height);
		opacity: var(--opacity);
		will-change: translate, opacity, scale;
	}
	[data-part='root'] {
		transition:
			translate 400ms,
			scale 400ms,
			opacity 400ms;
		transition-timing-function: cubic-bezier(0.21, 1.02, 0.73, 1);
	}
	[data-part='root'][data-state='closed'] {
		transition:
			translate 400ms,
			scale 400ms,
			opacity 200ms;
		transition-timing-function: cubic-bezier(0.06, 0.71, 0.55, 1);
	}
</style>
