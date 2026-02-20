<script>
	/**
	 * AdminLayout — extends AppShell with drawer sidebar (240px). Per 09-UI-ROUTES-PHASE1.md Section 2.2.
	 * Sidebar: Dashboard, Programs, Tokens, Users, Reports. DaisyUI drawer + menu.
	 */
	import { Link } from '@inertiajs/svelte';
	import { router } from '@inertiajs/svelte';
	import { usePage } from '@inertiajs/svelte';
	import StatusFooter from './StatusFooter.svelte';
	import Toast from '../Components/Toast.svelte';
	import OfflineBanner from '../Components/OfflineBanner.svelte';

	let { children } = $props();

	const pageStore = usePage();
	const user = $derived($pageStore.props?.auth?.user ?? null);
	const roleLabel = $derived(user?.role ?? '');
	const currentPath = $derived($pageStore.url ?? '');
</script>

<div class="flex flex-col min-h-screen bg-surface-100">
	<OfflineBanner />

	<div class="flex flex-col min-h-screen lg:flex-row">
		<input id="admin-drawer" type="checkbox" class="peer sr-only" aria-hidden="true" />

		<div class="flex flex-col min-h-screen flex-1 min-w-0 lg:order-1">
			<header class="flex items-center justify-between bg-surface-50 border-b border-surface-200 px-4 h-14 lg:hidden">
				<label for="admin-drawer" class="btn preset-tonal btn-icon" aria-label="Open menu">
					<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
				</label>
				<span class="font-semibold text-surface-950">FlexiQueue</span>
				<div class="flex items-center gap-1.5">
					<span class="text-xs px-2 py-0.5 rounded preset-filled-primary-500">{roleLabel}</span>
					<button type="button" class="btn preset-tonal btn-sm" onclick={() => router.post('/logout')}>Log out</button>
				</div>
			</header>

			<main class="flex-1 p-6 max-w-7xl">
				{#if children}
					{@render children()}
				{/if}
			</main>

			<StatusFooter />
		</div>

		<div class="fixed inset-y-0 right-0 z-40 w-60 transform translate-x-full transition-transform duration-200 peer-checked:translate-x-0 lg:translate-x-0 lg:static lg:order-2">
			<label for="admin-drawer" class="fixed inset-0 bg-black/50 z-30 lg:hidden" aria-label="Close sidebar"></label>
			<aside class="relative z-40 bg-surface-800 text-surface-50 w-60 min-h-full flex flex-col">
				<div class="p-5 border-b border-surface-600">
					<span class="text-lg font-bold">FlexiQueue</span>
				</div>
				<nav class="flex-1 p-3">
					<ul class="space-y-0.5">
						<li><Link href="/admin/dashboard" class="block py-2 px-3 rounded {currentPath === '/admin/dashboard' || currentPath === '/admin' ? 'bg-primary-500 text-primary-contrast-500' : 'hover:bg-surface-700'}">Dashboard</Link></li>
						<li><Link href="/admin/programs" class="block py-2 px-3 rounded {currentPath.startsWith('/admin/programs') ? 'bg-primary-500 text-primary-contrast-500' : 'hover:bg-surface-700'}">Programs</Link></li>
						<li><Link href="/admin/tokens" class="block py-2 px-3 rounded {currentPath.startsWith('/admin/tokens') ? 'bg-primary-500 text-primary-contrast-500' : 'hover:bg-surface-700'}">Tokens</Link></li>
						<li><Link href="/admin/users" class="block py-2 px-3 rounded {currentPath.startsWith('/admin/users') ? 'bg-primary-500 text-primary-contrast-500' : 'hover:bg-surface-700'}">Staff</Link></li>
						<li><Link href="/admin/reports" class="block py-2 px-3 rounded {currentPath.startsWith('/admin/reports') ? 'bg-primary-500 text-primary-contrast-500' : 'hover:bg-surface-700'}">Reports</Link></li>
					</ul>
				</nav>
				<div class="p-4 border-t border-surface-600">
					<span class="text-xs text-surface-400">{user?.name ?? '—'}</span>
					<Link href="/profile" class="btn preset-tonal btn-sm w-full mt-2 text-surface-100">Profile</Link>
					<button type="button" class="btn preset-tonal btn-sm w-full text-surface-100" onclick={() => router.post('/logout')}>Log out</button>
				</div>
			</aside>
		</div>
	</div>

	<Toast />
</div>
