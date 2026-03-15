<script>
	/**
	 * ThemeToggle — cycles Light → FlexiQueue theme → Dark. Persists to localStorage.
	 * data-mode on <html>: "light" | "flexiqueue" | "dark".
	 */
	import { Moon, Sun, Leaf } from "lucide-svelte";

	const STORAGE_KEY = "flexiqueue-theme";
	const MODES = ["light", "flexiqueue", "dark"];

	let mode = $state(
		typeof document !== "undefined"
			? (document.documentElement.getAttribute("data-mode") ?? "light")
			: "light",
	);

	function doToggle() {
		const i = MODES.indexOf(mode);
		const next = MODES[(i + 1) % MODES.length];
		document.documentElement.setAttribute("data-mode", next);
		try {
			localStorage.setItem(STORAGE_KEY, next);
		} catch {}
		mode = next;
	}

	$effect(() => {
		if (typeof document === "undefined") return;
		const stored = localStorage.getItem(STORAGE_KEY);
		if (stored && MODES.includes(stored) && stored !== mode) {
			document.documentElement.setAttribute("data-mode", stored);
			mode = stored;
		}
	});

	const labels = {
		light: "Light theme",
		flexiqueue: "FlexiQueue theme",
		dark: "Dark theme",
	};
	const nextLabel = $derived(
		labels[MODES[(MODES.indexOf(mode) + 1) % MODES.length]],
	);
	const iconWidthPx = 20;
	const stripOffset = $derived(-MODES.indexOf(mode) * iconWidthPx);
</script>

<!-- Cycle: Light → FlexiQueue → Dark → Light -->
<button
	type="button"
	onclick={doToggle}
	class="p-2 rounded-lg text-surface-600 hover:text-surface-950 dark:text-surface-400 dark:hover:text-primary-400 bg-transparent hover:bg-transparent active:bg-transparent focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-surface-900 touch-target flex items-center justify-center border-0 min-w-[2.5rem] min-h-[2.5rem]"
	aria-label="Switch to {nextLabel}"
	title="{labels[mode]} (next: {nextLabel})"
>
	<span class="theme-toggle-track relative w-5 h-5 overflow-hidden block" aria-hidden="true">
		<span
			class="theme-toggle-strip flex flex-nowrap transition-transform duration-500 ease-out"
			style="transform: translateX({stripOffset}px);"
		>
			<Sun class="w-5 h-5 shrink-0" />
			<Leaf class="w-5 h-5 shrink-0" />
			<Moon class="w-5 h-5 shrink-0" />
		</span>
	</span>
</button>
