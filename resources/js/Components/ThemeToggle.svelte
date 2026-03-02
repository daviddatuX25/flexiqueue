<script>
	/**
	 * ThemeToggle — switches between light and dark mode. Persists to localStorage.
	 * Per Skeleton docs: data-mode="light" | "dark" on <html>.
	 */
	import { Moon, Sun } from "lucide-svelte";

	const STORAGE_KEY = "flexiqueue-theme";

	let mode = $state(
		typeof document !== "undefined"
			? (document.documentElement.getAttribute("data-mode") ?? "light")
			: "light",
	);

	function doToggle() {
		const next = mode === "dark" ? "light" : "dark";
		document.documentElement.setAttribute("data-mode", next);
		try {
			localStorage.setItem(STORAGE_KEY, next);
		} catch {}
		mode = next;
	}

	$effect(() => {
		if (typeof document === "undefined") return;
		const stored = localStorage.getItem(STORAGE_KEY);
		if (stored && stored !== mode) {
			document.documentElement.setAttribute("data-mode", stored);
			mode = stored;
		}
	});
</script>

<button
	type="button"
	onclick={doToggle}
	class="p-2 rounded-lg text-surface-600 hover:text-surface-950 hover:bg-surface-200 dark:text-surface-400 dark:hover:text-surface-100 dark:hover:bg-surface-800 transition-colors focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:focus:ring-offset-surface-900 min-h-[2.5rem] min-w-[2.5rem] flex items-center justify-center"
	aria-label={mode === "dark" ? "Switch to light mode" : "Switch to dark mode"}
	title={mode === "dark" ? "Light mode" : "Dark mode"}
>
	{#if mode === "dark"}
		<Sun class="w-5 h-5" aria-hidden="true" />
	{:else}
		<Moon class="w-5 h-5" aria-hidden="true" />
	{/if}
</button>
