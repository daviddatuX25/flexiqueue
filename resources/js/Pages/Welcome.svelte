<script>
	let { appName = 'FlexiQueue' } = $props();
</script>

<svelte:head>
	<title>{appName}</title>
</svelte:head>

<main class="min-h-screen bg-base-200 flex flex-col items-center justify-center p-6">
	<div class="card bg-base-100 shadow-xl max-w-md w-full">
		<div class="card-body items-center text-center">
			<h1 class="card-title text-4xl text-primary">{appName}</h1>
			<p class="text-base-content/80 mt-2">
				Laravel 12 + Svelte 5 + Inertia.js + TailwindCSS 4 + DaisyUI 5
			</p>
			<div class="flex flex-wrap gap-2 mt-4">
				<button type="button" class="btn btn-primary">Primary</button>
				<button type="button" class="btn btn-success">Success</button>
				<button type="button" class="btn btn-outline">Outline</button>
				<a href="/broadcast-test" class="btn btn-outline btn-sm">Reverb test</a>
			</div>
			<p class="text-sm text-neutral mt-4">
				Test page — FlexiQueue theme applied. If you see styled buttons and primary blue, setup is correct.
			</p>
		</div>
	</div>
</main>
