<script lang="ts">
    /**
     * Reusable program status chip: program name (marquee) + connection status + optional program switch dropdown.
     * Same look everywhere (mobile-style) — used in StatusFooter for both MobileLayout (compact) and AdminLayout.
     */
    import { ChevronUp } from "lucide-svelte";
    import { CheckCircle2 } from "lucide-svelte";
    import Marquee from "./Marquee.svelte";

    interface ProgramOption {
        id: number;
        name: string;
    }

    let {
        programName = "All programs",
        programMode = "standby",
        connectionLabel = "Connected",
        networkConnected = true,
        isProgramClickable = false,
        showProgramSwitch = false,
        programs = [],
        currentProgramId = null,
        programSwitchOpen = false,
        onProgramClick = () => {},
        onChevronClick = () => {},
        onSwitchProgram = (_id: number) => {},
        fixed = false,
        menuPosition = { left: 0, bottom: 0 },
    }: {
        programName?: string;
        programMode?: "ongoing" | "standby";
        connectionLabel?: string;
        networkConnected?: boolean;
        isProgramClickable?: boolean;
        showProgramSwitch?: boolean;
        programs?: ProgramOption[];
        currentProgramId?: number | null;
        programSwitchOpen?: boolean;
        onProgramClick?: () => void;
        onChevronClick?: () => void;
        onSwitchProgram?: (id: number) => void;
        fixed?: boolean;
        menuPosition?: { left: number; bottom: number };
    } = $props();
</script>

<div
    class="relative shrink-0 max-w-[9rem] sm:max-w-[12rem] md:max-w-none"
    data-program-chip
>
    <div
        class="px-1.5 sm:px-2 flex items-stretch rounded-full border overflow-hidden
            {programMode === 'ongoing'
                ? 'bg-success-50 dark:bg-success-900/30 text-success-800 dark:text-success-200 border-success-200 dark:border-success-700'
                : 'bg-surface-100 dark:bg-slate-800 text-surface-800 dark:text-slate-200 border-surface-200 dark:border-slate-600'}"
    >
        <button
            type="button"
            class="flex flex-col items-start justify-center gap-0 sm:gap-0.5 px-1.5 sm:px-2 py-1.5 sm:py-2 min-h-[36px] sm:min-h-[40px] text-left transition-all duration-200 shrink-0 min-w-0
                {isProgramClickable ? 'cursor-pointer hover:bg-success-100 dark:hover:bg-success-900/50' : 'cursor-default'}"
            onclick={onProgramClick}
            disabled={!isProgramClickable}
            aria-label="{programName} — {connectionLabel}"
        >
            <div class="min-w-0 w-full max-w-[5.5rem] sm:max-w-[7rem] md:max-w-[10rem] overflow-hidden">
                <Marquee overflowOnly={true} duration={14} gapEm={1.5} class="text-[0.55rem] sm:text-[0.62rem] md:text-[0.68rem] font-semibold uppercase tracking-wide leading-tight text-inherit">
                    {#snippet children()}
                        <span class="whitespace-nowrap">{programName}</span>
                    {/snippet}
                </Marquee>
            </div>
            <span class="inline-flex items-center gap-0.5 sm:gap-1 text-[0.6rem] sm:text-[0.72rem] whitespace-nowrap shrink-0 {programMode === 'ongoing' ? 'text-success-800 dark:text-success-300' : 'text-surface-600 dark:text-slate-400'}">
                <span
                    class="w-1 sm:w-1.5 h-1 sm:h-1.5 rounded-full shrink-0 animate-pulse {networkConnected
                        ? 'bg-success-500'
                        : 'bg-error-500'}"
                    aria-hidden="true"
                ></span>
                <span>{connectionLabel}</span>
            </span>
        </button>
        {#if showProgramSwitch}
            <button
                type="button"
                class="flex items-center justify-center min-w-[28px] sm:min-w-[32px] px-1.5 sm:px-2 border-l border-current/20 text-surface-500 dark:text-slate-400 hover:bg-black/5 dark:hover:bg-white/5 transition-colors touch-target"
                onclick={(e) => {
                    e.stopPropagation();
                    onChevronClick();
                }}
                aria-haspopup="listbox"
                aria-expanded={programSwitchOpen}
                aria-label="Change program"
            >
                <ChevronUp
                    class="w-3 h-3 sm:w-3.5 sm:h-3.5 transition-transform {programSwitchOpen ? 'rotate-180' : ''}"
                    aria-hidden="true"
                />
            </button>
        {/if}
    </div>

    <!-- Inline dropdown when footer is not fixed -->
    {#if showProgramSwitch && programSwitchOpen && !fixed}
        <ul
            role="listbox"
            class="absolute bottom-full left-0 mb-1 min-w-[11rem] max-h-[12rem] overflow-y-auto py-1 rounded-lg border border-surface-200 dark:border-slate-600 bg-surface-50 dark:bg-slate-800 shadow-lg z-50"
            aria-label="Select program"
        >
            {#each programs as p (p.id)}
                <li role="option" aria-selected={currentProgramId === p.id}>
                    <button
                        type="button"
                        class="w-full text-left flex items-center gap-2 px-3 py-2 text-sm rounded-none text-surface-800 dark:text-slate-200 {currentProgramId === p.id ? 'text-primary-700 dark:text-primary-400 font-medium' : 'hover:bg-surface-100 dark:hover:bg-slate-700'}"
                        onclick={() => onSwitchProgram(p.id)}
                    >
                        {#if currentProgramId === p.id}
                            <CheckCircle2 class="w-4 h-4 text-primary-500 shrink-0" aria-hidden="true" />
                        {/if}
                        <span>{p.name}</span>
                    </button>
                </li>
            {/each}
        </ul>
    {/if}
</div>

<!-- Fixed-position dropdown when footer is fixed (e.g. admin layout) -->
{#if showProgramSwitch && programSwitchOpen && fixed}
    <ul
        role="listbox"
        class="fixed z-[90] mb-1 min-w-[11rem] max-h-[12rem] overflow-y-auto py-1 rounded-lg border border-surface-200 dark:border-slate-600 bg-surface-50 dark:bg-slate-800 shadow-lg"
        style="left: {menuPosition.left}px; bottom: {menuPosition.bottom}px;"
        aria-label="Select program"
    >
        {#each programs as p (p.id)}
            <li role="option" aria-selected={currentProgramId === p.id}>
                <button
                    type="button"
                    class="w-full text-left flex items-center gap-2 px-3 py-2 text-sm rounded-none text-surface-800 dark:text-slate-200 {currentProgramId === p.id ? 'text-primary-700 dark:text-primary-400 font-medium' : 'hover:bg-surface-100 dark:hover:bg-slate-700'}"
                    onclick={() => onSwitchProgram(p.id)}
                >
                    {#if currentProgramId === p.id}
                        <CheckCircle2 class="w-4 h-4 text-primary-500 shrink-0" aria-hidden="true" />
                    {/if}
                    <span>{p.name}</span>
                </button>
            </li>
        {/each}
    </ul>
{/if}
