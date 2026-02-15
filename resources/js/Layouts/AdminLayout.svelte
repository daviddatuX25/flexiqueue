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

<div class="flex flex-col min-h-screen bg-base-200">
	<OfflineBanner />

	<div class="drawer lg:drawer-open">
		<input id="admin-drawer" type="checkbox" class="drawer-toggle" />

		<div class="drawer-content flex flex-col min-h-screen">
			<header class="navbar bg-base-100 border-b border-base-300 lg:hidden">
				<div class="navbar-start">
					<label for="admin-drawer" class="btn btn-ghost btn-sm btn-square" aria-label="Open menu">
						<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
					</label>
				</div>
				<div class="navbar-center">
					<span class="font-semibold">FlexiQueue</span>
				</div>
				<div class="navbar-end gap-1.5">
					<span class="badge badge-primary badge-xs">{roleLabel}</span>
					<button type="button" class="btn btn-ghost btn-sm" onclick={() => router.post('/logout')}>Log out</button>
				</div>
			</header>

			<main class="flex-1 p-6 max-w-7xl">
				{#if children}
					{@render children()}
				{/if}
			</main>

			<StatusFooter />
		</div>

		<div class="drawer-side z-40">
			<label for="admin-drawer" class="drawer-overlay" aria-label="Close sidebar"></label>
			<aside class="bg-neutral text-neutral-content w-60 min-h-full flex flex-col">
				<div class="p-5 border-b border-neutral-content/10">
					<span class="text-lg font-bold">FlexiQueue</span>
				</div>
				<nav class="flex-1 p-3">
					<ul class="menu">
						<li><Link href="/admin/dashboard" class={currentPath === '/admin/dashboard' || currentPath === '/admin' ? 'menu-active' : ''}>Dashboard</Link></li>
						<li><Link href="/admin/programs" class={currentPath.startsWith('/admin/programs') ? 'menu-active' : ''}>Programs</Link></li>
						<li><Link href="/admin/tokens" class={currentPath.startsWith('/admin/tokens') ? 'menu-active' : ''}>Tokens</Link></li>
						<li><Link href="/admin/users" class={currentPath.startsWith('/admin/users') ? 'menu-active' : ''}>Staff</Link></li>
						<li><Link href="/admin/reports" class={currentPath.startsWith('/admin/reports') ? 'menu-active' : ''}>Reports</Link></li>
					</ul>
				</nav>
				<div class="p-4 border-t border-neutral-content/10">
					<span class="text-xs text-neutral-content/70">{user?.name ?? '—'}</span>
					<Link href="/profile" class="btn btn-ghost btn-sm btn-block mt-2 text-neutral-content">Profile</Link>
					<button type="button" class="btn btn-ghost btn-sm btn-block text-neutral-content" onclick={() => router.post('/logout')}>Log out</button>
				</div>
			</aside>
		</div>
	</div>

	<Toast />
</div>
