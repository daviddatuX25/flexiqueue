<script>
	/**
	 * MobileLayout — full-width for Triage + Station. Per 09-UI-ROUTES-PHASE1.md Section 2.3.
	 * Navbar (top) + scrollable content + dock (bottom bar). Large touch targets.
	 */
	import { Link } from '@inertiajs/svelte';
	import { usePage } from '@inertiajs/svelte';
	import { router } from '@inertiajs/svelte';
	import StatusFooter from './StatusFooter.svelte';
	import Toast from '../Components/Toast.svelte';
	import OfflineBanner from '../Components/OfflineBanner.svelte';

	let { children, headerTitle = 'FlexiQueue', queueCount = 0, processedToday = 0 } = $props();

	const pageStore = usePage();
	const user = $derived($pageStore.props?.auth?.user ?? null);
	const roleLabel = $derived(user?.role ?? 'staff');
	const currentPath = $derived($pageStore.url ?? '');
	const isStation = $derived(currentPath === '/station' || currentPath.startsWith('/station/'));
	const isTriage = $derived(currentPath === '/triage');
</script>

<div class="flex flex-col min-h-screen bg-base-200">
	<OfflineBanner />

	<header class="navbar bg-base-100 border-b border-base-300 px-3 min-h-0 h-14 shrink-0">
		<div class="navbar-start">
			<span class="text-base font-semibold text-base-content">{headerTitle}</span>
		</div>
		<div class="navbar-end gap-1.5">
			<span class="badge badge-accent badge-xs">{roleLabel}</span>
			<div class="dropdown dropdown-end">
				<div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-circle">
					<div class="w-8 h-8 rounded-full bg-primary text-primary-content flex items-center justify-center text-xs font-bold">
						{user?.name?.charAt(0) ?? '?'}
					</div>
				</div>
				<ul role="menu" tabindex="0" class="dropdown-content menu bg-base-100 rounded-box z-50 w-40 p-2 shadow border border-base-300">
					<li><span class="text-xs text-base-content/60">{user?.name ?? '—'}</span></li>
					<li><Link href="/station">Station</Link></li>
					<li><Link href="/triage">Triage</Link></li>
					<li><button type="button" onclick={() => router.post('/logout')}>Log out</button></li>
				</ul>
			</div>
		</div>
	</header>

	<main class="flex-1 overflow-y-auto p-4">
		{#if children}
			{@render children()}
		{/if}
	</main>

	<div class="bg-base-100 border-t border-base-300 flex justify-around py-2 shrink-0">
		<Link
			href="/station"
			class="flex flex-col items-center gap-0.5 min-w-[44px] min-h-[44px] justify-center {isStation ? 'text-primary font-semibold' : 'text-base-content/70'}"
		>
			<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
			<span class="text-[0.6rem]">Station</span>
		</Link>
		<Link
			href="/triage"
			class="flex flex-col items-center gap-0.5 min-w-[44px] min-h-[44px] justify-center {isTriage ? 'text-primary font-semibold' : 'text-base-content/70'}"
		>
			<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
			<span class="text-[0.6rem]">Triage</span>
		</Link>
	</div>

	<StatusFooter {queueCount} {processedToday} />

	<Toast />
</div>
