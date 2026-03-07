<script lang="ts">
    import { onDestroy } from "svelte";

    /** Constructor from dynamic import (e.g. ApexCharts). Omit to show placeholder. */
    type ChartConstructor = new (
        el: HTMLElement,
        opts: Record<string, unknown>,
    ) => { render: () => void; updateOptions: (o: Record<string, unknown>) => void; destroy: () => void };

    interface ChartInstance {
        render: () => void;
        updateOptions: (o: Record<string, unknown>) => void;
        destroy: () => void;
    }

    interface Props {
        options: Record<string, unknown>;
        height?: string;
        /** Pass the chart lib from parent (dynamic import). If null, placeholder is shown. */
        chartLib?: ChartConstructor | null;
    }

    let { options, height = "320", chartLib = null }: Props = $props();

    let chartEl = $state<HTMLDivElement | null>(null);
    let chartInstance = $state<ChartInstance | null>(null);
    /** Non-reactive ref so the creation effect does not depend on chartInstance (avoids effect_update_depth_exceeded). */
    let created = false;

    $effect(() => {
        const opts = options;
        if (chartInstance && chartEl && opts) {
            chartInstance.updateOptions(opts);
        }
    });

    $effect(() => {
        if (!chartLib || !chartEl) return;
        if (created) return;
        created = true;
        const opts = options;
        const chartOpts =
            opts.chart && typeof opts.chart === "object" && !Array.isArray(opts.chart)
                ? { ...(opts.chart as Record<string, unknown>), height }
                : { height };
        const inst = new chartLib(chartEl, { ...opts, chart: chartOpts }) as ChartInstance;
        inst.render();
        chartInstance = inst;
    });

    onDestroy(() => {
        if (chartInstance) {
            chartInstance.destroy();
            chartInstance = null;
        }
    });
</script>

<div bind:this={chartEl} class="w-full" style="min-height: {height}px;">
    {#if !chartLib}
        <div
            class="flex items-center justify-center h-full min-h-[200px] rounded-container bg-surface-100 text-surface-500 text-sm"
        >
            Chart library not loaded. Run: npm install apexcharts
        </div>
    {/if}
</div>
