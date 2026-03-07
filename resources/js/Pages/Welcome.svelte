<script>
	let { appName = 'FlexiQueue' } = $props();
</script>

<svelte:head>
	<title>{appName}</title>
</svelte:head>

<main class="min-h-screen bg-surface-100 flex flex-col items-center justify-center p-6">
	<div class="card bg-surface-50 shadow-xl max-w-md w-full rounded-container p-6 flex flex-col items-center text-center">
		<h1 class="font-bold text-4xl text-primary-500">{appName}</h1>
		<p class="text-surface-950/80 mt-2">
			Laravel 12 + Svelte 5 + Inertia.js + TailwindCSS 4 + Skeleton UI
		</p>
		<div class="flex flex-wrap gap-2 mt-4">
			<button type="button" class="btn preset-filled-primary-500 min-h-[48px]">Primary</button>
			<button type="button" class="btn preset-filled-success-500 min-h-[48px]">Success</button>
			<button type="button" class="btn preset-outlined min-h-[48px]">Outline</button>
			<a href="/broadcast-test" class="btn btn-sm preset-outlined min-h-[48px] inline-flex items-center">Reverb test</a>
		</div>
		<p class="text-sm text-surface-600 mt-4">
			Test page — FlexiQueue theme applied. If you see styled buttons and primary blue, setup is correct.
		</p>
	</div>
</main>
