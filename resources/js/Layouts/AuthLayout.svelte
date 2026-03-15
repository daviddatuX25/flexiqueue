<script>
	/**
	 * Minimal layout for auth pages (Login). Provides Toaster + FlashToToast so
	 * Laravel flash (e.g. rate-limit error) shows as toast. Per docs/TOAST-MIGRATION-MAP.md.
	 * When device is locked (sessionStorage has redirect URL), redirect back to locked page so back-button cannot escape.
	 */
	import { get } from "svelte/store";
	import { router, usePage } from "@inertiajs/svelte";
	import FlexiQueueToaster from '../Components/FlexiQueueToaster.svelte';
	import FlashToToast from '../Components/FlashToToast.svelte';

	let { children } = $props();
	const page = usePage();
	const deviceLocked = $derived((get(page)?.props?.device_locked) === true);
	const deviceLockedRedirectUrl = $derived((get(page)?.props?.device_locked_redirect_url) ?? null);

	const STORAGE_KEY = "device_lock_redirect_url";

	function isPathAllowedWhenLocked(path, redirectUrl) {
		if (!redirectUrl || typeof path !== "string") return false;
		const pathPrefix = redirectUrl.split("?")[0];
		return path === pathPrefix || path.startsWith(pathPrefix + "/") || path.startsWith(pathPrefix + "?");
	}

	function enforceLockClientSide() {
		if (deviceLocked && deviceLockedRedirectUrl && typeof sessionStorage !== "undefined") {
			sessionStorage.setItem(STORAGE_KEY, deviceLockedRedirectUrl);
		}
		// Do not clear sessionStorage when device_locked is false—cached back-nav pages can have stale false; clear only after consume (unlock).
		const redirectUrl = deviceLockedRedirectUrl ?? (typeof sessionStorage !== "undefined" ? sessionStorage.getItem(STORAGE_KEY) : null);
		if (!redirectUrl) return;
		const path = window.location.pathname;
		const allowed = isPathAllowedWhenLocked(path, redirectUrl);
		if (!allowed) {
			router.visit(redirectUrl, { replace: true });
		}
	}

	$effect(() => {
		if (typeof window === "undefined") return;
		enforceLockClientSide();
		const removeListener = router.on("navigate", () => enforceLockClientSide());
		return () => removeListener?.();
	});

	$effect(() => {
		if (typeof window === "undefined") return;
		const handlePopState = () => setTimeout(() => enforceLockClientSide(), 0);
		window.addEventListener("popstate", handlePopState);
		return () => window.removeEventListener("popstate", handlePopState);
	});
</script>

<FlexiQueueToaster />
<FlashToToast />
{#if typeof children === 'function'}
	{@render children()}
{/if}
