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

<div class="flex flex-col min-h-screen bg-base-200">
	<OfflineBanner />

	<header class="navbar bg-base-100 border-b border-base-300 px-4">
		<div class="navbar-start">
			<a href="/" class="text-lg font-semibold text-base-content">FlexiQueue</a>
		</div>
		<div class="navbar-end gap-2">
			{#if user}
				<span class="text-sm text-base-content/60 hidden sm:inline">{user.name}</span>
				<span class="badge badge-primary badge-sm">{roleLabel}</span>
				<a href="/profile" class="btn btn-ghost btn-sm">Profile</a>
				<button type="button" class="btn btn-ghost btn-sm" onclick={() => router.post('/logout')}>Log out</button>
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
