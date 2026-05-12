<script lang="ts">
	import { onMount, onDestroy } from 'svelte';
	import { router } from '@inertiajs/svelte';
	import AuthLayout from '../../Layouts/AuthLayout.svelte';
	import { isNative, isAndroid, getEdgeRuntime } from '../../native/runtime';
	import { initializeEdge, isStackRunning } from '../../native/plugins/termuxBridge';
	import { startPowerGuard } from '../../native/plugins/powerGuard';
	import PhoneEdgeStatus from '../../Components/PhoneEdgeStatus.svelte';

	type BootStage = 'idle' | 'extracting' | 'starting' | 'guarding' | 'health_check' | 'ready' | 'error';

	let { edgeRuntime = 'dev' }: { edgeRuntime?: string } = $props();

	let stage: BootStage = $state('idle');
	let errorMessage = $state('');
	let stageDetails = $state('');
	let bootAttempted = $state(false);
	let pollInterval: ReturnType<typeof setInterval> | null = null;

	const STAGES: Record<BootStage, { label: string; icon: string }> = {
		idle: { label: 'Preparing...', icon: '⏳' },
		extracting: { label: 'Extracting edge bundle...', icon: '📦' },
		starting: { label: 'Starting edge stack...', icon: '🚀' },
		guarding: { label: 'Activating power guard...', icon: '⚡' },
		health_check: { label: 'Checking server health...', icon: '🔍' },
		ready: { label: 'Edge device ready!', icon: '✅' },
		error: { label: 'Boot failed', icon: '❌' },
	};

	async function bootSequence() {
		if (bootAttempted) return;
		bootAttempted = true;
		stage = 'idle';
		errorMessage = '';

		try {
			// Stage 1: Extract edge bundle (if first run)
			stage = 'extracting';
			stageDetails = 'Extracting edge bundle to Termux...';
			const { alreadyExtracted } = await initializeEdge();
			stageDetails = alreadyExtracted
				? 'Bundle already extracted.'
				: 'Bundle extracted successfully.';

			// Stage 2: Start edge stack (handled by initializeEdge)
			stage = 'starting';
			stageDetails = 'Starting Nginx + PHP-FPM...';

			// Stage 3: Start power guard
			stage = 'guarding';
			stageDetails = 'Acquiring wake lock and starting foreground service...';
			const guardStarted = await startPowerGuard();
			if (!guardStarted) {
				stageDetails = 'Power guard failed — stack may be killed by Android Doze.';
			} else {
				stageDetails = 'Power guard active.';
			}

			// Stage 4: Health check
			stage = 'health_check';
			stageDetails = 'Verifying server is responding...';
			const healthy = await isStackRunning();
			if (!healthy) {
				throw new Error('Server health check failed after boot.');
			}

			// Stage 5: Ready
			stage = 'ready';
			stageDetails = 'Edge device is ready. Redirecting...';

			// Redirect based on device state
			await redirectBasedOnState();
		} catch (err) {
			stage = 'error';
			errorMessage = err instanceof Error ? err.message : 'Unknown error during boot.';
			stageDetails = '';
			bootAttempted = false;
		}
	}

	async function redirectBasedOnState() {
		try {
			const res = await fetch('/edge/boot/status');
			const data = await res.json();

			if (data.redirect) {
				router.visit(data.redirect);
			} else {
				router.visit('/');
			}
		} catch {
			// If status check fails, go to root
			router.visit('/');
		}
	}

	async function retryBoot() {
		bootAttempted = false;
		stage = 'idle';
		errorMessage = '';
		stageDetails = '';
		await bootSequence();
	}

	async function requestBatteryOptimization() {
		if (!isNative()) return;
		try {
			const { Capacitor } = await import('@capacitor/core');
			const { App } = await import('@capacitor/app');
			// Open battery optimization settings
			App.openUrl({ url: 'android-settings://battery-optimization' });
		} catch {
			stageDetails = 'Could not open battery settings.';
		}
	}

	onMount(() => {
		const runtime = edgeRuntime || 'dev';
		if (isNative() && isAndroid()) {
			bootSequence();
		} else {
			// Non-phone runtime — redirect immediately
			redirectBasedOnState();
		}
	});

	onDestroy(() => {
		if (pollInterval) clearInterval(pollInterval);
	});
</script>

<svelte:head>
	<title>Edge Bootstrap — FlexiQueue</title>
</svelte:head>

<AuthLayout>
	<main class="min-h-screen flex flex-col items-center justify-center p-6">
		<div class="card bg-surface-50 rounded-container shadow-xl max-w-lg w-full p-8">
			<div class="text-center mb-6">
				<h1 class="text-2xl font-bold text-primary-500 mb-1">FlexiQueue Edge</h1>
				<p class="text-sm text-surface-500">Bootstrapping edge device...</p>
			</div>

			{#if isNative() && isAndroid()}
				<!-- Boot progress -->
				<div class="space-y-4 mb-6">
					{#each ['extracting', 'starting', 'guarding', 'health_check'] as s}
						<div class="flex items-center gap-3">
							<div class="w-8 h-8 flex items-center justify-center rounded-full
								{stage === s ? 'bg-primary-100 dark:bg-primary-900 ring-2 ring-primary-500' :
								STAGES[stage] && Object.keys(STAGES).indexOf(stage) > Object.keys(STAGES).indexOf(s) ? 'bg-success-100 dark:bg-success-900' :
								'bg-surface-100 dark:bg-surface-800'}">
								{#if stage === s}
									<svg class="animate-spin h-4 w-4 text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
										<circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
										<path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
									</svg>
								{:else if Object.keys(STAGES).indexOf(stage) > Object.keys(STAGES).indexOf(s)}
									<span class="text-success-600 text-sm">✓</span>
								{:else}
									<span class="text-surface-400 text-sm">○</span>
								{/if}
							</div>
							<div class="flex-1">
								<p class="text-sm font-medium {stage === s ? 'text-primary-700 dark:text-primary-300' : 'text-surface-600 dark:text-surface-400'}">
									{STAGES[s].label}
								</p>
							</div>
						</div>
					{/each}
				</div>

				{#if stageDetails}
					<p class="text-xs text-surface-500 text-center mb-4">{stageDetails}</p>
				{/if}

				{#if stage === 'ready'}
					<div class="rounded-container bg-success-50 dark:bg-success-950/20 border border-success-200 dark:border-success-800 p-4 text-center">
						<p class="text-success-700 dark:text-success-300 font-medium">Edge device is ready!</p>
						<p class="text-xs text-success-600 dark:text-success-400 mt-1">Redirecting to your queue...</p>
					</div>
				{/if}

				{#if stage === 'error'}
					<div class="rounded-container bg-error-50 dark:bg-error-950/20 border border-error-200 dark:border-error-800 p-4 mb-4">
						<p class="text-error-700 dark:text-error-300 font-medium">Boot failed</p>
						<p class="text-xs text-error-600 dark:text-error-400 mt-1">{errorMessage}</p>
					</div>
					<div class="flex gap-3">
						<button
							type="button"
							class="btn preset-filled-primary-500 flex-1"
							onclick={retryBoot}
						>
							Retry Boot
						</button>
						<button
							type="button"
							class="btn preset-outlined-surface-200 flex-1"
							onclick={requestBatteryOptimization}
						>
							Battery Settings
						</button>
					</div>
				{/if}

				{#if stage !== 'error' && stage !== 'ready'}
					<div class="flex justify-center">
						<div class="w-full bg-surface-200 dark:bg-surface-700 rounded-full h-2 mt-2">
							<div class="bg-primary-500 h-2 rounded-full transition-all duration-500"
								style="width: {stage === 'extracting' ? '25%' : stage === 'starting' ? '50%' : stage === 'guarding' ? '75%' : stage === 'health_check' ? '90%' : '0%'}">
							</div>
						</div>
					</div>
				{/if}

				<!-- Phone status widget -->
				<div class="mt-6">
					<PhoneEdgeStatus />
				</div>
			{:else}
				<!-- Non-phone runtime -->
				<div class="text-center">
					<p class="text-surface-600 dark:text-surface-400">
						This page is for phone edge devices only.
					</p>
					<p class="text-xs text-surface-500 mt-2">
						Runtime: {edgeRuntime || 'dev'} — redirecting to main page...
					</p>
				</div>
			{/if}
		</div>
	</main>
</AuthLayout>