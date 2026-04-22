<script lang="ts">
	/**
	 * Per addition-to-public-site-plan Part 7: public program info page.
	 * Banner, name, announcement, description, status badge, CTAs to view and device setup.
	 * Switch-site button removes known_sites entry and redirects to home.
	 */
	import { Link, router } from '@inertiajs/svelte';
	import DisplayLayout from '../../Layouts/DisplayLayout.svelte';
	import Modal from '../../Components/Modal.svelte';
	import { Monitor, Smartphone, ArrowLeft, LogOut, ExternalLink } from 'lucide-svelte';

	const KNOWN_SITES_COOKIE = 'known_sites';

	function forgetThisSite(slug: string) {
		if (typeof document === 'undefined') { router.visit('/'); return; }
		const raw = document.cookie.split('; ').find((row) => row.startsWith(KNOWN_SITES_COOKIE + '='));
		if (!raw) { router.visit('/'); return; }
		try {
			const value = decodeURIComponent(raw.slice(KNOWN_SITES_COOKIE.length + 1).trim());
			const parsed = JSON.parse(value);
			if (!Array.isArray(parsed)) { router.visit('/'); return; }
			const remaining = parsed.filter((x: { slug: string }) => typeof x?.slug === 'string' && x.slug !== slug);
			const encoded = encodeURIComponent(JSON.stringify(remaining));
			document.cookie = `${KNOWN_SITES_COOKIE}=${encoded}; path=/; max-age=${365 * 86400}; SameSite=Lax`;
			router.visit('/');
		} catch {
			router.visit('/');
		}
	}

	let {
		site,
		program,
		page,
		is_private,
	}: {
		site: { id: number; name: string; slug: string };
		program: { id: number; name: string; slug: string; description: string | null; is_active: boolean; is_paused?: boolean };
		page: { description: string | null; announcement: string | null; banner_image_url: string | null };
		is_private: boolean;
	} = $props();

	let showSwitchConfirm = $state(false);
</script>

<svelte:head>
	<title>{program.name} — {site.name} — FlexiQueue</title>
</svelte:head>

<DisplayLayout programName={program.name} date="">
	<!-- Switch site confirmation modal -->
	<Modal
		open={showSwitchConfirm}
		onclose={() => (showSwitchConfirm = false)}
		title="Switch to another site?"
	>
		<p class="text-sm text-surface-600 dark:text-slate-400 mb-4">
			This will remove <strong>{site.name}</strong> from your saved sites and take you back to the home page. You'll need to enter a new site key to access a different site.
		</p>
		<div class="flex gap-2 justify-end">
			<button type="button" class="btn variant-outline" onclick={() => (showSwitchConfirm = false)}>Cancel</button>
			<button type="button" class="btn preset-filled-primary-500" onclick={() => forgetThisSite(site.slug)}>
				Switch site
			</button>
		</div>
	</Modal>

	<div class="flex flex-1 flex-col">
		<!-- Sticky top bar -->
		<div class="sticky top-0 z-30 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-surface-200/60 dark:border-slate-700/60">
			<div class="max-w-3xl mx-auto px-4 py-3 flex items-center justify-between gap-3">
				<Link
					href="/site/{site.slug}"
					class="inline-flex items-center gap-1.5 text-sm font-medium text-surface-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
				>
					<ArrowLeft class="h-4 w-4" />
					{site.name}
				</Link>
				<button
					type="button"
					onclick={() => (showSwitchConfirm = true)}
					class="inline-flex items-center gap-1.5 text-sm font-medium text-surface-500 dark:text-slate-400 hover:text-red-500 dark:hover:text-red-400 transition-colors"
					title="Remove this site and switch to another"
				>
					<LogOut class="h-4 w-4" />
					Switch site
				</button>
			</div>
		</div>

		<!-- Article-style content -->
		<article class="max-w-3xl mx-auto px-5 py-10">
			<!-- Banner + header -->
			<header class="mb-10">
				{#if page?.banner_image_url}
					<img
						src={page.banner_image_url}
						alt=""
						class="w-full max-h-64 object-cover rounded-2xl mb-6 shadow-sm"
					/>
				{/if}
				<h1 class="text-3xl md:text-4xl font-extrabold text-surface-950 dark:text-white leading-tight">
					{program.name}
				</h1>
				{#if page?.announcement}
					<div class="mt-4 rounded-xl border border-warning-300 dark:border-warning-600 bg-warning-50 dark:bg-warning-900/30 p-4 text-warning-800 dark:text-warning-200 text-sm font-medium leading-relaxed">
						{page.announcement}
					</div>
				{/if}
				<div class="mt-3 flex gap-2">
					{#if program.is_active && !program.is_paused}
						<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300">Active</span>
					{:else if program.is_paused}
						<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300">Paused</span>
					{:else}
						<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider bg-surface-100 dark:bg-surface-800 text-surface-600 dark:text-surface-400">Inactive</span>
					{/if}
					{#if is_private}
						<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider bg-primary-100 dark:bg-primary-900/40 text-primary-700 dark:text-primary-300">Key required</span>
					{/if}
				</div>
			</header>

			<!-- Description -->
			{#if page?.description}
				<section class="mb-10">
					<div class="prose prose-surface dark:prose-invert max-w-none text-surface-700 dark:text-slate-300 leading-relaxed whitespace-pre-wrap">
						{page.description}
					</div>
				</section>
			{/if}

			<!-- Actions -->
			<section class="border-t border-surface-200 dark:border-slate-700/60 pt-8">
				<h2 class="text-xl font-bold text-surface-900 dark:text-white mb-2">
					Join this program
				</h2>
				<p class="text-sm text-surface-500 dark:text-slate-400 mb-6">
					Choose how you'd like to interact with this queue.
				</p>
				<div class="flex flex-col sm:flex-row gap-3">
					<Link
						href="/site/{site.slug}/program/{program.slug}/view"
						class="btn preset-filled-primary-500 flex items-center justify-center gap-2 flex-1 touch-target-h py-3 text-sm font-semibold rounded-lg"
					>
						<Monitor class="h-4 w-4 shrink-0" />
						Monitor your queue
					</Link>
					<Link
						href="/site/{site.slug}/program/{program.slug}"
						class="inline-flex items-center justify-center gap-1.5 py-3 px-4 text-sm font-medium rounded-lg border touch-target-h text-primary-600 dark:text-primary-300 border-primary-300 dark:border-primary-500/80 bg-primary-500/5 dark:bg-primary-400/10 hover:bg-primary-500/10 dark:hover:bg-primary-400/20 hover:border-primary-400 dark:hover:border-primary-400 transition-colors"
					>
						<Smartphone class="h-4 w-4 shrink-0" />
						Use this device
					</Link>
				</div>
			</section>

			<!-- Article footer -->
			<footer class="mt-12 pt-6 border-t border-surface-200 dark:border-slate-700/60 text-center">
				<p class="text-xs text-surface-400 dark:text-slate-500">
					{program.name} at {site.name} on FlexiQueue
				</p>
			</footer>
		</article>
	</div>
</DisplayLayout>