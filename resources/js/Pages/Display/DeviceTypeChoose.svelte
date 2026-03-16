<script lang="ts">
	/**
	 * Per plan: after device auth, user chooses Main display / Public triage / Station display.
	 * Uses form POST so server returns 302 + Set-Cookie and browser follows with cookie (avoids fetch Set-Cookie not sent on next request).
	 * "Change program" clears lock and goes to program list (/site/{site_slug}/display).
	 */
	import { usePage } from '@inertiajs/svelte';
	import { get } from 'svelte/store';
	import MobileLayout from '../../Layouts/MobileLayout.svelte';
	import { toaster } from '../../lib/toaster.js';
	import { Monitor, ClipboardList, LayoutGrid, ArrowLeft, Loader2 } from 'lucide-svelte';

	let {
		site_slug,
		program,
		stations,
		queueCount = 0,
		processedToday = 0,
		activeProgram = null,
		currentProgram = null,
		canSwitchProgram = false,
		programs = [],
	}: {
		site_slug: string;
		program: { id: number; name: string; slug: string };
		stations: { id: number; name: string }[];
		queueCount?: number;
		processedToday?: number;
		activeProgram?: { id: number; name: string; is_active: boolean; is_paused: boolean } | null;
		currentProgram?: { id: number; name: string; is_active: boolean; is_paused: boolean } | null;
		canSwitchProgram?: boolean;
		programs?: { id: number; name: string }[];
	} = $props();

	const page = usePage();

	$effect(() => {
		const flash = (get(page)?.props as { flash?: { error?: string } })?.flash;
		if (flash?.error) {
			toaster.warning({ title: flash.error });
		}
	});

	let loading = $state(false);
	let showStationPicker = $state(false);
	let stationError = $state<string | null>(null);
	let lockForm: HTMLFormElement | null = $state(null);

	function getCsrfToken(): string {
		const p = get(page)?.props as { csrf_token?: string } | undefined;
		const fromProps = p?.csrf_token;
		if (fromProps && typeof fromProps === 'string') return fromProps;
		return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
	}

	function setLockAndGo(deviceType: 'display' | 'triage' | 'station', stationId?: number) {
		if (loading) return;
		const csrf = getCsrfToken();
		if (!csrf) {
			toaster.error({
				title: 'Session invalid. Please refresh the page and try again.',
			});
			return;
		}
		if (!lockForm) {
			toaster.error({ title: 'Could not set device type. Try again.' });
			return;
		}
		loading = true;
		stationError = null;
		const deviceTypeEl = lockForm.querySelector<HTMLInputElement>('[name="device_type"]');
		const stationIdEl = lockForm.querySelector<HTMLInputElement>('[name="station_id"]');
		if (deviceTypeEl) deviceTypeEl.value = deviceType;
		if (stationIdEl) stationIdEl.value = deviceType === 'station' && stationId != null ? String(stationId) : '';
		// Set CSRF right before submit (form may have been rendered with stale token)
		const tokenEl = lockForm.querySelector<HTMLInputElement>('[name="_token"]');
		if (tokenEl) tokenEl.value = csrf;
		lockForm.submit();
	}

	function chooseMainDisplay() {
		setLockAndGo('display');
	}

	function choosePublicTriage() {
		setLockAndGo('triage');
	}

	function chooseStation(stationId: number) {
		setLockAndGo('station', stationId);
	}

	async function changeProgram() {
		if (loading) return;
		const csrf = getCsrfToken();
		if (!csrf) {
			toaster.error({
				title: 'Session invalid. Please refresh the page and try again.',
			});
			return;
		}
		loading = true;
		try {
			const res = await fetch('/api/public/device-lock/clear', {
				method: 'POST',
				credentials: 'include',
				headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
			});
			if (res.status === 419) {
				toaster.error({
					title: 'Session expired. Please refresh the page and try again.',
				});
				return;
			}
			window.location.href = `/site/${site_slug}/display`;
		} catch (e) {
			console.error('[DeviceTypeChoose] changeProgram failed', e);
			toaster.error({ title: 'Network error. Try again.' });
		} finally {
			loading = false;
		}
	}
</script>

<MobileLayout
	headerTitle="This device"
	{queueCount}
	{processedToday}
>
	<!-- Form POST so server returns 302 + Set-Cookie; browser then follows with cookie. No Accept: application/json. -->
	<form
		bind:this={lockForm}
		action="/api/public/device-lock"
		method="post"
		target="_top"
		class="hidden"
		aria-hidden="true"
	>
		<input type="hidden" name="_token" value="" />
		<input type="hidden" name="site_slug" value={site_slug} />
		<input type="hidden" name="program_slug" value={program.slug} />
		<input type="hidden" name="device_type" value="display" />
		<input type="hidden" name="station_id" value="" />
	</form>
	<div
		class="flex w-full flex-col justify-start px-4 py-6 sm:flex-1 sm:items-center sm:justify-center sm:px-6 sm:py-12"
	>
		<div
			class="flex max-w-md w-full flex-col items-center gap-6 rounded-2xl border border-surface-200 bg-surface-50/90 p-6 shadow-lg dark:border-slate-700 dark:bg-slate-800/90 sm:p-8"
		>
			<h1 class="text-xl font-semibold text-surface-950 dark:text-white text-center">
				Choose device type
			</h1>
			{#if loading}
				<p class="text-sm text-primary-600 dark:text-primary-400 flex items-center justify-center gap-2">
					<Loader2 class="h-4 w-4 animate-spin shrink-0" aria-hidden="true" />
					Setting up…
				</p>
			{:else}
				<p class="text-sm text-surface-600 dark:text-slate-400 text-center">
					This device will be locked to the chosen interface until a supervisor approves a change.
				</p>
			{/if}

			{#if showStationPicker}
				<div class="w-full space-y-3">
					<p class="text-sm font-medium text-surface-700 dark:text-slate-300">Select a station</p>
					{#if stationError}
						<p class="text-sm text-warning-600 dark:text-warning-400">{stationError}</p>
					{/if}
					{#if stations.length === 0}
						<p class="text-sm text-surface-600 dark:text-slate-400">No stations available.</p>
					{:else}
						<ul class="space-y-2">
							{#each stations as station (station.id)}
								<li>
									<button
										type="button"
										class="btn preset-tonal w-full touch-target-h justify-start"
										disabled={loading}
										onclick={() => chooseStation(station.id)}
									>
										{station.name}
									</button>
								</li>
							{/each}
						</ul>
					{/if}
					<button
						type="button"
						class="btn preset-tonal btn-sm w-full"
						disabled={loading}
						onclick={() => (showStationPicker = false)}
					>
						Back
					</button>
				</div>
			{:else}
				<div class="grid gap-3 w-full">
					<button
						type="button"
						class="btn preset-tonal flex items-center gap-3 p-4 touch-target-h justify-start text-left"
						disabled={loading}
						onclick={chooseMainDisplay}
					>
						<Monitor class="h-6 w-6 shrink-0 text-primary-600 dark:text-primary-400" />
						<div>
							<span class="font-medium block">Main display</span>
							<span class="text-sm text-surface-600 dark:text-slate-400">Now serving board</span>
						</div>
					</button>
					<button
						type="button"
						class="btn preset-tonal flex items-center gap-3 p-4 touch-target-h justify-start text-left"
						disabled={loading}
						onclick={choosePublicTriage}
					>
						<ClipboardList class="h-6 w-6 shrink-0 text-primary-600 dark:text-primary-400" />
						<div>
							<span class="font-medium block">Public triage</span>
							<span class="text-sm text-surface-600 dark:text-slate-400">Self-serve check-in</span>
						</div>
					</button>
					<button
						type="button"
						class="btn preset-tonal flex items-center gap-3 p-4 touch-target-h justify-start text-left"
						disabled={loading}
						onclick={() => (showStationPicker = true)}
					>
						<LayoutGrid class="h-6 w-6 shrink-0 text-primary-600 dark:text-primary-400" />
						<div>
							<span class="font-medium block">Station display</span>
							<span class="text-sm text-surface-600 dark:text-slate-400">Calling / queue for one station</span>
						</div>
					</button>
				</div>
			{/if}
			<div class="w-full pt-4 border-t border-surface-200 dark:border-slate-600 mt-4">
				<button
					type="button"
					class="btn preset-tonal btn-sm w-full flex items-center justify-center gap-2"
					disabled={loading}
					onclick={changeProgram}
				>
					<ArrowLeft class="h-4 w-4" />
					Change program
				</button>
				<p class="text-xs text-surface-500 dark:text-slate-400 mt-2 text-center">
					Returns to program list to pick another program.
				</p>
			</div>
		</div>
	</div>
</MobileLayout>
