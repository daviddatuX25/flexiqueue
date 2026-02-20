<script>
	/**
	 * AppShell — wraps all authenticated pages. Per 09-UI-ROUTES-PHASE1.md Section 2.1.
	 * Provides: navbar (header), OfflineBanner, Toast area, StatusFooter.
	 */
	import { router } from '@inertiajs/svelte';
	import { usePage } from '@inertiajs/svelte';
	import StatusFooter from './StatusFooter.svelte';
	import Toast from '../Components/Toast.svelte';
	import OfflineBanner from '../Components/OfflineBanner.svelte';

	let { children, showFooter = true, queueCount = 0, processedToday = 0 } = $props();

	const page = usePage();
	const user = $derived($page.props?.auth?.user ?? null);
	const activeProgram = $derived($page.props?.activeProgram ?? null);
	const roleLabel = $derived(user?.role ?? '');
</script>

<div class="flex flex-col min-h-screen bg-surface-100">
	<OfflineBanner />

	<header class="flex items-center justify-between bg-surface-50 border-b border-surface-200 px-4 h-14">
		<div>
			<a href="/" class="text-lg font-semibold text-surface-950">FlexiQueue</a>
		</div>
		<div class="flex items-center gap-2">
			{#if user}
				<span class="text-sm text-surface-950/60 hidden sm:inline">{user.name}</span>
				<span class="text-xs px-2 py-0.5 rounded preset-filled-primary-500">{roleLabel}</span>
				<a href="/profile" class="btn preset-tonal btn-sm">Profile</a>
				<button type="button" class="btn preset-tonal btn-sm" onclick={() => router.post('/logout')}>Log out</button>
			{/if}
		</div>
	</header>

	<main class="flex-1">
		{#if children}
			{@render children()}
		{/if}
	</main>

	{#if showFooter}
		<StatusFooter {queueCount} {processedToday} />
	{/if}

	<Toast />
</div>
