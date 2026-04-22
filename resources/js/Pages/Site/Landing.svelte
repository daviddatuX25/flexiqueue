<script lang="ts">
	/**
	 * Per public-site plan: site landing with hero, sections, two-action program cards (Monitor your queue / Use this device), optional stats.
	 * Program key modal when ?program_key_prompt={slug} (e.g. after scan of private program QR).
	 * Switch-site button removes known_sites entry and redirects to home.
	 */
	import { Link, router, usePage } from '@inertiajs/svelte';
	import DisplayLayout from '../../Layouts/DisplayLayout.svelte';
	import Modal from '../../Components/Modal.svelte';
	import { Monitor, Smartphone, Key, ArrowLeft, LogOut, ExternalLink } from 'lucide-svelte';

	const page = usePage();
	const csrfToken = $derived((page?.props as { csrf_token?: string })?.csrf_token ?? '');

	const KNOWN_PROGRAMS_COOKIE = 'known_programs';
	const KNOWN_PROGRAMS_MAX_AGE_DAYS = 365;
	const KNOWN_SITES_COOKIE = 'known_sites';

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

	function forgetThisSite() {
		if (typeof document === 'undefined') return;
		const raw = document.cookie.split('; ').find((row) => row.startsWith(KNOWN_SITES_COOKIE + '='));
		if (!raw) { router.visit('/'); return; }
		try {
			const value = decodeURIComponent(raw.slice(KNOWN_SITES_COOKIE.length + 1).trim());
			const parsed = JSON.parse(value);
			if (!Array.isArray(parsed)) { router.visit('/'); return; }
			const remaining = parsed.filter((x: { slug: string }) => typeof x?.slug === 'string' && x.slug !== site.slug);
			const encoded = encodeURIComponent(JSON.stringify(remaining));
			document.cookie = `${KNOWN_SITES_COOKIE}=${encoded}; path=/; max-age=${365 * 86400}; SameSite=Lax`;
			router.visit('/');
		} catch {
			router.visit('/');
		}
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

	let showSwitchConfirm = $state(false);

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
				body: JSON.stringify({
				site_slug: site.slug,
				key,
				...(programKeyPromptSlug ? { program_slug: programKeyPromptSlug } : {}),
			}),
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
			<button type="button" class="btn preset-filled-primary-500" onclick={forgetThisSite}>
				Switch site
			</button>
		</div>
	</Modal>

	<div class="flex flex-1 flex-col">
		<!-- Sticky top bar with back + switch site -->
		<div class="sticky top-0 z-30 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-surface-200/60 dark:border-slate-700/60">
			<div class="max-w-3xl mx-auto px-4 py-3 flex items-center justify-between gap-3">
				<Link
					href="/"
					class="inline-flex items-center gap-1.5 text-sm font-medium text-surface-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
				>
					<ArrowLeft class="h-4 w-4" />
					Home
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
			<!-- Hero -->
			<header class="mb-10">
				{#if landing?.hero_image_url}
					<img
						src={landing.hero_image_url}
						alt=""
						class="w-full max-h-64 object-cover rounded-2xl mb-6 shadow-sm"
					/>
				{/if}
				<h1 class="text-3xl md:text-4xl font-extrabold text-surface-950 dark:text-white leading-tight">
					{heroTitle}
				</h1>
				{#if landing?.hero_description}
					<p class="text-lg text-surface-600 dark:text-slate-400 mt-3 leading-relaxed">
						{landing.hero_description}
					</p>
				{/if}
			</header>

			<!-- Optional site stats (article-style stat bar) -->
			{#if landing?.show_stats && siteStats}
				<div class="flex gap-8 mb-10 pb-8 border-b border-surface-200 dark:border-slate-700/60">
					<div>
						<div class="text-3xl font-bold text-surface-900 dark:text-white">{siteStats.served_count}</div>
						<div class="text-xs font-semibold text-surface-500 dark:text-slate-500 uppercase tracking-widest mt-0.5">People served</div>
					</div>
					<div>
						<div class="text-3xl font-bold text-primary-600 dark:text-primary-400">{siteStats.session_hours}</div>
						<div class="text-xs font-semibold text-surface-500 dark:text-slate-500 uppercase tracking-widest mt-0.5">Program hours</div>
					</div>
				</div>
			{/if}

			<!-- Content sections (article paragraphs) -->
			{#if landing?.sections?.length}
				<div class="space-y-8 mb-10">
					{#each landing.sections as section (section.title)}
						{#if section.type === 'text'}
							<section>
								<h2 class="text-xl font-bold text-surface-900 dark:text-white mb-3">{section.title}</h2>
								{#if section.body}
									<div class="prose prose-surface dark:prose-invert max-w-none text-surface-700 dark:text-slate-300 leading-relaxed whitespace-pre-wrap">
										{section.body}
									</div>
								{/if}
							</section>
						{/if}
					{/each}
				</div>
			{/if}

			<!-- Programs section -->
			{#if programs.length > 0}
				<section class="border-t border-surface-200 dark:border-slate-700/60 pt-8">
					<h2 class="text-xl font-bold text-surface-900 dark:text-white mb-2">
						Programs
					</h2>
					<p class="text-sm text-surface-500 dark:text-slate-400 mb-6">
						Choose a program to monitor or use this device as a client.
					</p>
					<ul class="space-y-4">
						{#each programs as program (program.id)}
							<li class="group rounded-xl border border-surface-200 dark:border-slate-700 bg-white dark:bg-slate-800/70 p-5 hover:shadow-md hover:border-primary-300 dark:hover:border-primary-600/50 transition-all">
								<div class="flex items-start justify-between gap-3 mb-4">
									<h3 class="font-semibold text-surface-900 dark:text-white text-lg">{program.name}</h3>
									<ExternalLink class="h-4 w-4 text-surface-400 dark:text-slate-500 shrink-0 mt-1 group-hover:text-primary-500 transition-colors" />
								</div>
								<div class="flex flex-col sm:flex-row gap-2">
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
							</li>
						{/each}
					</ul>
				</section>
			{:else}
				<section class="border-t border-surface-200 dark:border-slate-700/60 pt-8 text-center">
					<p class="text-surface-500 dark:text-slate-400">
						No active programs at the moment.
					</p>
				</section>
			{/if}

			<!-- Article footer -->
			<footer class="mt-12 pt-6 border-t border-surface-200 dark:border-slate-700/60 text-center">
				<p class="text-xs text-surface-400 dark:text-slate-500">
					{site.name} on FlexiQueue
				</p>
			</footer>
		</article>
	</div>
</DisplayLayout>