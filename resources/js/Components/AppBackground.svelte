<script lang="ts">
    /**
     * AppBackground: A reusable component that provides the signature FlexiQueue background
     * with frosted glass wash and vibrant gradients. Adapts beautifully between light and dark modes.
     */
    interface Props {
        heroImageUrl?: string;
    }

    let { heroImageUrl = "/images/mswdo_tagudin.jpg" }: Props = $props();
</script>

<!-- 1. The Raw Background Image -->
<div
    class="fixed inset-0 z-[-3] bg-cover bg-center bg-no-repeat transition-all duration-700"
    style={`background-image: url('${heroImageUrl}')`}
></div>

<!-- 2. The Adaptive Wash -->
<!-- Light mode gets a bright frosty wash, Dark mode gets a deep dark-blue wash -->
<div
    class="fixed inset-0 z-[-2] bg-slate-50/85 dark:bg-slate-900/85 backdrop-blur-[2px] transition-colors duration-700"
></div>

<!-- 3. The Subtle Vibrant Gradient Overlay -->
<div
    class="fixed inset-0 z-[-1] bg-gradient-to-br from-emerald-500/10 via-teal-500/5 to-transparent pointer-events-none transition-colors duration-700"
></div>
