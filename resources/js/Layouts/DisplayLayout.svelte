<script>
	/**
	 * DisplayLayout — client-facing informant (no auth). Per 09-UI-ROUTES-PHASE1 §2.4.
	 * Header: FlexiQueue, program name, date, and live time. Main: full-screen content.
	 */
	import { Link } from '@inertiajs/svelte';
	import FlexiQueueToaster from '../Components/FlexiQueueToaster.svelte';
	import FlashToToast from '../Components/FlashToToast.svelte';

	let { children, programName = null, date = '' } = $props();
	let time = $state('');

	$effect(() => {
		const update = () => {
			time = new Date().toLocaleTimeString('en-US', {
				hour: 'numeric',
				minute: '2-digit',
				second: '2-digit',
				hour12: true,
			});
		};
		update();
		const id = setInterval(update, 1000);
		return () => clearInterval(id);
	});
</script>

<div class="flex flex-col min-h-screen bg-surface-100">
	<FlexiQueueToaster />
	<FlashToToast />
	<header class="flex items-center justify-between gap-4 bg-primary-500 text-primary-contrast-500 px-4 py-2.5 shrink-0">
		<div class="shrink-0">
			<Link href="/" class="text-lg font-bold text-inherit no-underline hover:opacity-90 transition-opacity">FlexiQueue</Link>
		</div>
		<div class="flex-1 flex justify-center min-w-0">
			{#if programName}
				<div class="fq-header-marquee min-w-0">
					<div class="fq-header-marquee__inner">
						<span class="text-base font-semibold whitespace-nowrap">{programName}</span>
						<span class="text-base font-semibold whitespace-nowrap fq-header-marquee__dup" aria-hidden="true"
							>{programName}</span
						>
					</div>
				</div>
			{:else}
				<span class="text-primary-contrast-500/70">No active program</span>
			{/if}
		</div>
		<div class="flex flex-col items-end gap-0.5 shrink-0 sm:flex-row sm:items-center sm:gap-4">
			<span class="text-sm opacity-90">{date}</span>
			<span class="text-sm font-mono tabular-nums" aria-label="Current time">{time}</span>
		</div>
	</header>

	<main class="flex-1 overflow-auto p-4">
		{#if typeof children === 'function'}
			{@render children()}
		{/if}
	</main>
</div>

<style>
	/* Mobile-only marquee for long program names; desktop keeps static (truncate via layout). */
	.fq-header-marquee {
		overflow: hidden;
		width: 100%;
	}

	.fq-header-marquee__inner {
		display: inline-flex;
		align-items: center;
		gap: 2rem;
		width: max-content;
		animation: fq-header-marquee 12s linear infinite;
	}

	/* Duplicate text for seamless scroll; add left padding via gap. */
	.fq-header-marquee__dup {
		opacity: 0.9;
	}

	@media (min-width: 640px) {
		.fq-header-marquee {
			overflow: visible;
			display: flex;
			justify-content: center;
		}
		.fq-header-marquee__inner {
			animation: none;
		}
		.fq-header-marquee__dup {
			display: none;
		}
	}

	@media (prefers-reduced-motion: reduce) {
		.fq-header-marquee__inner {
			animation: none;
		}
		.fq-header-marquee__dup {
			display: none;
		}
	}

	@keyframes fq-header-marquee {
		0% {
			transform: translateX(0);
		}
		100% {
			transform: translateX(-50%);
		}
	}
</style>
