<script lang="ts">
	/**
	 * Per addition-to-public-site-plan Part 7: public program info page.
	 * Banner, name, announcement, description, status badge, CTAs to view and device setup.
	 */
	import { Link } from '@inertiajs/svelte';
	import DisplayLayout from '../../Layouts/DisplayLayout.svelte';
	import { Monitor, Smartphone } from 'lucide-svelte';

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
</script>

<svelte:head>
	<title>{program.name} — {site.name} — FlexiQueue</title>
</svelte:head>

<DisplayLayout programName={program.name} date="">
	<div class="flex flex-1 flex-col px-6 py-8 max-w-2xl mx-auto">
		{#if page?.banner_image_url}
			<img
				src={page.banner_image_url}
				alt=""
				class="w-full max-h-48 object-cover rounded-2xl mb-4"
			/>
		{/if}
		<header class="text-center mb-6">
			<h1 class="text-2xl font-bold text-surface-950 dark:text-white">
				{program.name}
			</h1>
			{#if page?.announcement}
				<div class="mt-3 rounded-xl border border-warning-300 dark:border-warning-600 bg-warning-50 dark:bg-warning-900/30 p-3 text-warning-800 dark:text-warning-200 text-sm font-medium">
					{page.announcement}
				</div>
			{/if}
			<div class="mt-2 flex justify-center gap-2">
				{#if program.is_active && !program.is_paused}
					<span class="badge badge-success">Active</span>
				{:else if program.is_paused}
					<span class="badge badge-warning">Paused</span>
				{:else}
					<span class="badge badge-surface">Inactive</span>
				{/if}
			</div>
		</header>

		{#if page?.description}
			<section class="mb-8 rounded-xl border border-surface-200 dark:border-slate-700 bg-surface-50/80 dark:bg-slate-800/50 p-4">
				<p class="text-sm text-surface-600 dark:text-slate-400 whitespace-pre-wrap">{page.description}</p>
			</section>
		{/if}

		<div class="flex flex-col sm:flex-row gap-3">
			<Link
				href="/site/{site.slug}/program/{program.slug}/view"
				class="btn preset-filled-primary-500 flex items-center justify-center gap-2 flex-1 touch-target-h"
			>
				<Monitor class="h-5 w-5 shrink-0" />
				Monitor your queue
			</Link>
			<Link
				href="/site/{site.slug}/program/{program.slug}"
				class="btn flex items-center justify-center gap-2 flex-1 touch-target-h rounded-lg border font-medium text-surface-900 dark:text-white border-surface-300 dark:border-slate-500 bg-surface-50 dark:bg-slate-700/50 hover:bg-surface-200 dark:hover:bg-slate-600/70 hover:border-surface-400 dark:hover:border-slate-400 focus:outline-none focus:ring-2 focus:ring-primary-500/50"
			>
				<Smartphone class="h-5 w-5 shrink-0" />
				Use this device
			</Link>
		</div>
	</div>
</DisplayLayout>
