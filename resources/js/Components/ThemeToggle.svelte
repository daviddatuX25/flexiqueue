<script>
	/**
	 * ThemeToggle — cycles Light → FlexiQueue theme → Dark.
	 * When persistToStorage is true (default), updates <html data-mode> and localStorage.
	 * When persistToStorage is false, only updates bindable mode (parent applies after save).
	 */
	import { Moon, Sun, Leaf } from "lucide-svelte";

	const STORAGE_KEY = "flexiqueue-theme";
	const MODES = ["light", "flexiqueue", "dark"];

	function readDomMode() {
		if (typeof document === "undefined") return "light";
		return document.documentElement.getAttribute("data-mode") ?? "light";
	}

	let {
		persistToStorage = true,
		disabled = false,
		mode = $bindable(readDomMode()),
	} = $props();

	function doToggle() {
		if (disabled) return;
		const i = MODES.indexOf(mode);
		const idx = i >= 0 ? i : 0;
		const next = MODES[(idx + 1) % MODES.length];
		if (persistToStorage) {
			document.documentElement.setAttribute("data-mode", next);
			try {
				localStorage.setItem(STORAGE_KEY, next);
			} catch {}
		}
		mode = next;
	}

	$effect(() => {
		if (!persistToStorage || typeof document === "undefined") return;
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
	const modeIdx = $derived(MODES.indexOf(mode) >= 0 ? MODES.indexOf(mode) : 0);
	const nextLabel = $derived(labels[MODES[(modeIdx + 1) % MODES.length]]);
	const iconWidthPx = 20;
	const stripOffset = $derived(-modeIdx * iconWidthPx);
</script>

<!-- Cycle: Light → FlexiQueue → Dark → Light -->
<button
	type="button"
	onclick={doToggle}
	disabled={disabled}
	class="p-2 rounded-lg text-surface-600 hover:text-surface-950 dark:text-surface-400 dark:hover:text-primary-400 bg-transparent hover:bg-transparent active:bg-transparent focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-surface-900 touch-target flex items-center justify-center border-0 min-w-[2.5rem] min-h-[2.5rem] disabled:opacity-50 disabled:pointer-events-none"
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
