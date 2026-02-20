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
	const isTrackOverrides = $derived(currentPath === '/track-overrides');
	const isAdmin = $derived(user?.role === 'admin');
	const backHref = $derived(isAdmin ? '/admin/dashboard' : '/dashboard');
	const backLabel = $derived(isAdmin ? 'Admin panel' : 'Dashboard');
</script>

<div class="flex flex-col min-h-screen bg-surface-100">
	<OfflineBanner />

	<header class="flex items-center justify-between gap-2 bg-surface-50 border-b border-surface-200 px-3 min-h-0 h-14 shrink-0">
		<div class="min-w-0 flex-1 flex items-center gap-2">
			<Link
				href={backHref}
				class="btn preset-tonal btn-sm min-h-[44px] min-w-[44px] p-2 gap-1 shrink-0 text-surface-950/80 hover:text-surface-950"
				aria-label="Back to {backLabel}"
			>
				<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
				</svg>
				<span class="text-sm font-medium truncate">{backLabel}</span>
			</Link>
			<span class="text-base font-semibold text-surface-950 truncate">{headerTitle}</span>
		</div>
		<div class="flex items-center gap-1.5 shrink-0">
			<span class="text-xs px-2 py-0.5 rounded preset-filled-tertiary-500">{roleLabel}</span>
			<div class="relative group">
				<div tabindex="0" role="button" class="btn preset-tonal btn-icon min-h-[44px] min-w-[44px] rounded-full">
					<div class="w-8 h-8 rounded-full bg-primary-500 text-primary-contrast-500 flex items-center justify-center text-xs font-bold">
						{user?.name?.charAt(0) ?? '?'}
					</div>
				</div>
				<ul role="menu" tabindex="0" class="absolute right-0 top-full mt-1 z-50 w-48 p-2 rounded-container shadow-xl border border-surface-200 bg-surface-50 hidden group-focus-within:block">
					<li><span class="text-xs text-surface-950/60 block py-1">{user?.name ?? '—'}</span></li>
					<li><Link href={backHref} class="block py-2 px-2 rounded hover:bg-surface-100 text-surface-950">{backLabel}</Link></li>
					<li><Link href="/profile" class="block py-2 px-2 rounded hover:bg-surface-100 text-surface-950">Profile</Link></li>
					<li class="pt-2 pb-0.5"><span class="text-xs text-surface-950/50">Live Session</span></li>
					<li><Link href="/station" class="block py-2 px-2 rounded hover:bg-surface-100 text-surface-950">Station</Link></li>
					<li><Link href="/triage" class="block py-2 px-2 rounded hover:bg-surface-100 text-surface-950">Triage</Link></li>
					<li><Link href="/track-overrides" class="block py-2 px-2 rounded hover:bg-surface-100 text-surface-950">Track Overrides</Link></li>
					<li class="pt-2 border-t border-surface-200 mt-1"><button type="button" class="w-full text-left py-2 px-2 rounded hover:bg-surface-100 text-surface-950" onclick={() => router.post('/logout')}>Log out</button></li>
				</ul>
			</div>
		</div>
	</header>

	<main class="flex-1 overflow-y-auto p-4">
		{#if children}
			{@render children()}
		{/if}
	</main>

	<div class="bg-surface-50 border-t border-surface-200 shrink-0">
		<p class="text-[0.65rem] text-surface-950/50 text-center py-1 font-medium uppercase tracking-wide">Live Session</p>
		<div class="flex justify-around py-2">
		<Link
			href="/station"
			class="flex flex-col items-center gap-0.5 min-w-[44px] min-h-[44px] justify-center {isStation ? 'text-primary-500 font-semibold' : 'text-surface-950/70'}"
		>
			<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
			<span class="text-[0.6rem]">Station</span>
		</Link>
		<Link
			href="/triage"
			class="flex flex-col items-center gap-0.5 min-w-[44px] min-h-[44px] justify-center {isTriage ? 'text-primary-500 font-semibold' : 'text-surface-950/70'}"
		>
			<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
			<span class="text-[0.6rem]">Triage</span>
		</Link>
		<Link
			href="/track-overrides"
			class="flex flex-col items-center gap-0.5 min-w-[44px] min-h-[44px] justify-center {isTrackOverrides ? 'text-primary-500 font-semibold' : 'text-surface-950/70'}"
		>
			<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
			<span class="text-[0.6rem]">Track Overrides</span>
		</Link>
		</div>
	</div>

	<StatusFooter {queueCount} {processedToday} />

	<Toast />
</div>
