<script lang="ts">
	import { untrack } from 'svelte';

	/**
	 * Self-contained countdown timer. Holds its own state and interval so only this
	 * component re-renders every second — avoids re-rendering large parents (e.g. PublicStart).
	 */
	let {
		active = false,
		initialSeconds = 0,
		onExpire = () => {},
		/** Text before the number, e.g. "Closing in " or "Resetting to the start in " */
		prefix = '',
		/** Text after the number, e.g. "s" or " seconds…" */
		suffix = 's',
	}: {
		active: boolean;
		initialSeconds: number;
		onExpire: () => void;
		prefix?: string;
		suffix?: string;
	} = $props();

	let countdown = $state(0);
	/** Plain ref so the effect doesn't depend on it — avoids effect re-running when interval is set and resetting the timer. */
	let intervalId: ReturnType<typeof setInterval> | null = null;

	function clearTimer() {
		if (intervalId != null) {
			clearInterval(intervalId);
			intervalId = null;
		}
	}

	function startTimer(seconds: number) {
		clearTimer();
		if (seconds <= 0) return;
		countdown = seconds;
		intervalId = setInterval(() => {
			countdown -= 1;
			if (countdown <= 0) {
				clearTimer();
				onExpire();
			}
		}, 1000);
	}

	$effect(() => {
		if (!active) {
			clearTimer();
			countdown = 0;
			return;
		}
		const sec = Math.max(0, Math.floor(Number(initialSeconds) || 0));
		if (sec <= 0) return;
		clearTimer();
		startTimer(sec);
		return () => clearTimer();
	});

	/** Add extra seconds to the current countdown (e.g. "Extend" button). */
	export function extend(extraSeconds: number) {
		if (intervalId == null) return;
		const extra = Math.max(0, Math.floor(Number(extraSeconds) || 0));
		countdown += extra;
	}
</script>

{#if active && countdown > 0}
	<p class="text-sm text-surface-600" data-testid="countdown-display">
		{prefix}{countdown}{suffix}
	</p>
{/if}
