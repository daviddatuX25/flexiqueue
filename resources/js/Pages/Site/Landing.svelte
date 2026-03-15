<script lang="ts">
	/**
	 * Per public-site plan: site landing with hero, sections, two-action program cards (Monitor your queue / Use this device), optional stats.
	 * Program key modal when ?program_key_prompt={slug} (e.g. after scan of private program QR).
	 */
	import { Link, router, usePage } from '@inertiajs/svelte';
	import DisplayLayout from '../../Layouts/DisplayLayout.svelte';
	import Modal from '../../Components/Modal.svelte';
	import { Monitor, Smartphone, Key } from 'lucide-svelte';

	const page = usePage();
	const csrfToken = $derived((page?.props as { csrf_token?: string })?.csrf_token ?? '');

	const KNOWN_PROGRAMS_COOKIE = 'known_programs';
	const KNOWN_PROGRAMS_MAX_AGE_DAYS = 365;

	type KnownProgramEntry = { site_slug: string; program_slug: string; program_name: string; token: string; expires_at: string };

	function getKnownPrograms(): KnownProgramEntry[] {
		if (typeof document === 'undefined') return [];
		const raw = document.cookie.split('; ').find((row) => row.startsWith(KNOWN_PROGRAMS_COOKIE + '='));
		if (!raw) return [];
		try {
			const value = decodeURIComponent(raw.slice(KNOWN_PROGRAMS_COOKIE.length + 1).trim());
			const parsed = JSON.parse(value);
			if (!Array.isArray(parsed)) return [];
			const now = new Date().toISOString();
			return parsed.filter((x: KnownProgramEntry) => x && x.expires_at && x.expires_at > now);
		} catch {
			return [];
		}
	}

	function setKnownPrograms(entries: KnownProgramEntry[]) {
		if (typeof document === 'undefined') return;
		const value = encodeURIComponent(JSON.stringify(entries));
		document.cookie = `${KNOWN_PROGRAMS_COOKIE}=${value}; path=/; max-age=${KNOWN_PROGRAMS_MAX_AGE_DAYS * 86400}; SameSite=Lax`;
	}

	function addKnownProgram(entry: KnownProgramEntry) {
		const list = getKnownPrograms();
		const without = list.filter((e) => !(e.site_slug === entry.site_slug && e.program_slug === entry.program_slug));
		setKnownPrograms([...without, entry]);
	}

	type Landing = {
		hero_title: string;
		hero_description: string | null;
		hero_image_url: string | null;
		sections: { type: string; title: string; body?: string }[];
		show_stats: boolean;
	};

	let {
		site,
		programs,
		landing = {
			hero_title: '',
			hero_description: null,
			hero_image_url: null,
			sections: [],
			show_stats: false,
		},
	}: {
		site: { id: number; name: string; slug: string };
		programs: { id: number; name: string; slug: string }[];
		landing?: Landing;
	} = $props();

	const heroTitle = $derived(landing?.hero_title || site.name);

	/** Per public-site plan: optional site-scoped stats when landing.show_stats. */
	let siteStats = $state<{ served_count: number; session_hours: number } | null>(null);

	/** Program key modal when URL has ?program_key_prompt={slug} (private program entry). */
	let showProgramKeyModal = $state(false);
	let programKeyPromptSlug = $state<string | null>(null);
	let programKeyInput = $state('');
	let programKeyError = $state('');
	let programKeySubmitting = $state(false);

	$effect(() => {
		if (typeof window === 'undefined') return;
		const params = new URLSearchParams(window.location.search);
		const slug = params.get('program_key_prompt');
		if (slug && slug.length > 0 && slug.length <= 100) {
			programKeyPromptSlug = slug;
			showProgramKeyModal = true;
		}
	});

	$effect(() => {
		if (!landing?.show_stats || typeof fetch === 'undefined') return;
		fetch(`/api/public/site-stats/${site.slug}`, { credentials: 'same-origin' })
			.then((res) => (res.ok ? res.json() : null))
			.then((data) => {
				if (data && typeof data.served_count === 'number' && typeof data.session_hours === 'number') {
					siteStats = { served_count: data.served_count, session_hours: data.session_hours };
				}
			})
			.catch(() => {});
	});

	async function submitProgramKey() {
		const key = programKeyInput.trim();
		if (!key || !programKeyPromptSlug) return;
		programKeyError = '';
		programKeySubmitting = true;
		try {
			const res = await fetch('/api/public/program-key', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-Requested-With': 'XMLHttpRequest',
					...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
				},
				body: JSON.stringify({ site_slug: site.slug, key }),
				credentials: 'same-origin',
			});
			const data = await res.json().catch(() => ({}));
			if (res.ok && data.program_slug && data.token && data.expires_at) {
				addKnownProgram({
					site_slug: site.slug,
					program_slug: data.program_slug,
					program_name: data.program_name || data.program_slug,
					token: data.token,
					expires_at: data.expires_at,
				});
				showProgramKeyModal = false;
				programKeyInput = '';
				programKeyPromptSlug = null;
				router.visit(`/site/${site.slug}/program/${data.program_slug}/view`);
			} else {
				programKeyError = 'Invalid key. Please try again.';
			}
		} catch {
			programKeyError = 'Something went wrong. Please try again.';
		} finally {
			programKeySubmitting = false;
		}
	}

	function closeProgramKeyModal() {
		showProgramKeyModal = false;
		programKeyInput = '';
		programKeyError = '';
		if (typeof window !== 'undefined') {
			const u = new URL(window.location.href);
			u.searchParams.delete('program_key_prompt');
			window.history.replaceState({}, '', u.pathname + u.search);
		}
		programKeyPromptSlug = null;
	}
</script>

<svelte:head>
	<title>{heroTitle} — FlexiQueue</title>
</svelte:head>

<DisplayLayout programName={heroTitle} date="">
	<!-- Program key entry modal (private program access) -->
	<Modal
		open={showProgramKeyModal}
		onclose={closeProgramKeyModal}
		title="Enter program key"
	>
		<p class="text-sm text-surface-600 dark:text-slate-400 mb-4">
			This program requires a key. Enter the key provided by staff to continue.
		</p>
		{#if programKeyError}
			<p class="text-sm text-error-600 dark:text-error-400 mb-2">{programKeyError}</p>
		{/if}
		<form
			onsubmit={(e) => {
				e.preventDefault();
				submitProgramKey();
			}}
			class="space-y-3"
		>
			<input
				type="text"
				class="input w-full"
				placeholder="Program key"
				bind:value={programKeyInput}
				maxlength={50}
				autocomplete="off"
			/>
			<div class="flex gap-2 justify-end">
				<button type="button" class="btn variant-outline" onclick={closeProgramKeyModal}>Cancel</button>
				<button type="submit" class="btn preset-filled-primary-500" disabled={programKeySubmitting || !programKeyInput.trim()}>
					{programKeySubmitting ? 'Checking…' : 'Continue'}
				</button>
			</div>
		</form>
	</Modal>

	<div class="flex flex-1 flex-col px-6 py-8 max-w-2xl mx-auto">
		<!-- Hero -->
		<header class="text-center mb-8">
			{#if landing?.hero_image_url}
				<img
					src={landing.hero_image_url}
					alt=""
					class="w-full max-h-48 object-cover rounded-2xl mb-4"
				/>
			{/if}
			<h1 class="text-2xl font-bold text-surface-950 dark:text-white">
				{heroTitle}
			</h1>
			{#if landing?.hero_description}
				<p class="text-surface-600 dark:text-slate-400 mt-2">
					{landing.hero_description}
				</p>
			{/if}
		</header>

		<!-- Optional site stats -->
		{#if landing?.show_stats && siteStats}
			<div class="flex gap-6 justify-center mb-8 rounded-xl border border-surface-200 dark:border-slate-700 bg-surface-50 dark:bg-slate-800/80 p-4">
				<div class="text-center">
					<div class="text-2xl font-bold text-surface-900 dark:text-white">{siteStats.served_count}</div>
					<div class="text-xs text-surface-500 uppercase tracking-wider">People served</div>
				</div>
				<div class="text-center">
					<div class="text-2xl font-bold text-primary-600 dark:text-primary-400">{siteStats.session_hours}</div>
					<div class="text-xs text-surface-500 uppercase tracking-wider">Program hours</div>
				</div>
			</div>
		{/if}

		<!-- Content sections -->
		{#if landing?.sections?.length}
			<div class="space-y-4 mb-8">
				{#each landing.sections as section (section.title)}
					{#if section.type === 'text'}
						<section class="rounded-xl border border-surface-200 dark:border-slate-700 bg-surface-50/80 dark:bg-slate-800/50 p-4">
							<h2 class="font-semibold text-surface-900 dark:text-white">{section.title}</h2>
							{#if section.body}
								<p class="text-sm text-surface-600 dark:text-slate-400 mt-1 whitespace-pre-wrap">{section.body}</p>
							{/if}
						</section>
					{/if}
				{/each}
			</div>
		{/if}

		<!-- Programs: two actions per program -->
		<div class="space-y-4">
			<h2 class="text-lg font-semibold text-surface-950 dark:text-white">
				Choose a program
			</h2>
			{#if programs.length === 0}
				<p class="text-sm text-surface-500 dark:text-slate-500">
					No active programs at the moment.
				</p>
			{:else}
				<ul class="space-y-4">
					{#each programs as program (program.id)}
						<li
							class="rounded-2xl border border-surface-200 dark:border-slate-700 bg-surface-50/90 dark:bg-slate-800/90 p-4 flex flex-col gap-3"
						>
							<span class="font-medium text-surface-900 dark:text-white">{program.name}</span>
							<div class="flex flex-col sm:flex-row gap-2">
								<Link
									href="/site/{site.slug}/program/{program.slug}/view"
									class="btn preset-filled-primary-500 flex items-center justify-center gap-2 flex-1 touch-target-h"
								>
									<Monitor class="h-5 w-5 shrink-0" />
									Monitor your queue
								</Link>
								<Link
									href="/site/{site.slug}/program/{program.slug}"
									class="btn variant-outline flex items-center justify-center gap-2 flex-1 touch-target-h"
								>
									<Smartphone class="h-5 w-5 shrink-0" />
									Use this device
								</Link>
							</div>
						</li>
					{/each}
				</ul>
			{/if}
		</div>
	</div>
</DisplayLayout>
