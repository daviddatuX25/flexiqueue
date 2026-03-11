<script lang="ts">
    /**
     * FlexiQueue Toast: icon, title, description, optional action.
     * Uses FlexiQueue theme (primary green). Supports action: { label, onClick }.
     */
    import { fly } from "svelte/transition";
    import {
        CheckCircle2,
        AlertTriangle,
        XCircle,
        Info,
        X,
    } from "lucide-svelte";
    import * as toast from "@zag-js/toast";
    import { normalizeProps, useMachine } from "@zag-js/svelte";

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
        base = "relative flex items-start gap-3",
        width = "min-w-[280px] max-w-md",
        padding = "p-4",
        rounded = "rounded-xl",
        classes = "",
        messageBase = "flex-1 min-w-0",
        messageClasses = "",
        titleBase = "text-sm font-semibold",
        titleClasses = "text-surface-950",
        descriptionBase = "mt-0.5 text-xs",
        descriptionClasses = "text-surface-600",
        btnDismissBase = "",
        btnDismissClasses = "text-surface-400 hover:text-surface-600 transition-colors",
        btnDismissTitle = "Close",
        btnDismissAriaLabel = "Close",
        stateInfo = "bg-white dark:bg-slate-800 border-l-4 border-l-blue-500 border border-surface-200 dark:border-slate-700",
        stateSuccess = "bg-white dark:bg-slate-800 border-l-4 border-l-primary-500 border border-surface-200 dark:border-slate-700",
        stateWarning = "bg-white dark:bg-slate-800 border-l-4 border-l-amber-500 border border-surface-200 dark:border-slate-700",
        stateError = "bg-white dark:bg-slate-800 border-l-4 border-l-error-500 border border-surface-200 dark:border-slate-700",
    }: Props = $props();

    const service = useMachine(toast.machine, () => ({
        ...toastOptions,
        parent,
        index,
    }));
    const api = $derived(toast.connect(service, normalizeProps));

    const rxState = $derived(
        api.type === "success"
            ? stateSuccess
            : api.type === "warning"
              ? stateWarning
              : api.type === "error"
                ? stateError
                : stateInfo,
    );

    const iconColor = $derived(
        api.type === "success"
            ? "text-primary-500" // Using primary green for success
            : api.type === "warning"
              ? "text-amber-500"
              : api.type === "error"
                ? "text-error-500"
                : "text-blue-500",
    );

    const IconComponent = $derived(
        api.type === "success"
            ? CheckCircle2
            : api.type === "warning"
              ? AlertTriangle
              : api.type === "error"
                ? XCircle
                : Info,
    );
</script>

<div
    class="{base} {width} {padding} {rounded} {rxState} {classes}"
    {...api.getRootProps()}
    data-testid="toast-root"
    in:fly={{ y: 20, duration: 400 }}
>
    <!-- Left Icon -->
    <div
        class="flex items-center justify-center shrink-0 items-center justify-center pl-4 pr-3 py-4 {iconColor}"
        aria-hidden="true"
    >
        <IconComponent size={24} strokeWidth={2.5} />
    </div>

    <!-- Content -->
    <div
        class="{messageBase} {messageClasses} py-4 pr-2"
        data-testid="toast-message"
    >
        <p
            class="{titleBase} {titleClasses}"
            {...api.getTitleProps()}
            data-testid="toast-title"
        >
            {api.title}
        </p>
        {#if api.description}
            <p
                class="{descriptionBase} {descriptionClasses}"
                {...api.getDescriptionProps()}
                data-testid="toast-description"
            >
                {api.description}
            </p>
        {/if}
        {#if toastOptions?.action}
            <button
                type="button"
                class="btn preset-filled-primary-500 mt-2 rounded-md px-3 py-1.5 text-xs font-bold transition-colors hover:opacity-90 active:scale-95"
                {...api.getActionTriggerProps()}
                data-testid="toast-action"
            >
                {toastOptions.action.label}
            </button>
        {/if}
    </div>

    <!-- Divider & Close -->
    {#if api.closable}
        <div
            class="flex items-stretch border-l border-surface-200 dark:border-slate-700 ml-auto"
        >
            <button
                class="flex items-center justify-center px-4 {btnDismissBase} {btnDismissClasses} hover:bg-surface-50 dark:hover:bg-slate-700/50"
                title={btnDismissTitle}
                aria-label={btnDismissAriaLabel}
                {...api.getCloseTriggerProps()}
                data-testid="toast-dismiss"
            >
                <X size={20} />
            </button>
        </div>
    {/if}
</div>

<style>
    [data-part="root"] {
        translate: var(--x) var(--y);
        scale: var(--scale);
        z-index: var(--z-index);
        height: var(--height);
        opacity: var(--opacity);
        will-change: translate, opacity, scale;
    }
    [data-part="root"] {
        transition:
            translate 400ms,
            scale 400ms,
            opacity 400ms;
        transition-timing-function: cubic-bezier(0.21, 1.02, 0.73, 1);
    }
    [data-part="root"][data-state="closed"] {
        transition:
            translate 400ms,
            scale 400ms,
            opacity 200ms;
        transition-timing-function: cubic-bezier(0.06, 0.71, 0.55, 1);
    }
</style>
