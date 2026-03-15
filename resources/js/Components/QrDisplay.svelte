<script lang="ts">
	/**
	 * Renders a QR code for a given URL and provides Copy link + Download PNG.
	 * Per addition-to-public-site-plan Part 6.3: client-side QR rendering.
	 */
	import { onMount } from 'svelte';
	import { toaster } from '../lib/toaster.js';

	let { url = '', label = 'QR code' }: { url: string; label?: string } = $props();

	let dataUrl = $state<string | null>(null);
	let error = $state(false);

	onMount(async () => {
		if (!url) return;
		try {
			const QRCode = (await import('qrcode')).default;
			const data = await new Promise<string>((resolve, reject) => {
				QRCode.toDataURL(url, { width: 200, margin: 1 }, (err: Error | null, data: string) => {
					if (err) reject(err);
					else resolve(data);
				});
			});
			dataUrl = data;
		} catch (e) {
			error = true;
		}
	});

	function copyLink() {
		if (!url) return;
		navigator.clipboard.writeText(url).then(
			() => toaster.success({ title: 'Link copied.' }),
			() => toaster.error({ title: 'Could not copy.' })
		);
	}

	function downloadPng() {
		if (!dataUrl) return;
		const a = document.createElement('a');
		a.href = dataUrl;
		a.download = `qr-${label.replace(/\s+/g, '-').toLowerCase()}.png`;
		a.click();
		toaster.success({ title: 'Download started.' });
	}
</script>

{#if error}
	<p class="text-sm text-surface-500">Could not generate QR.</p>
{:else if dataUrl}
	<div class="flex flex-col items-start gap-2">
		<img src={dataUrl} alt={label} class="rounded border border-surface-200 dark:border-slate-600" width="200" height="200" />
		<code class="text-xs text-surface-600 dark:text-slate-400 truncate max-w-full" title={url}>{url}</code>
		<div class="flex gap-2">
			<button type="button" class="btn variant-outline btn-sm" onclick={copyLink}>Copy link</button>
			<button type="button" class="btn variant-outline btn-sm" onclick={downloadPng}>Download QR</button>
		</div>
	</div>
{:else}
	<p class="text-sm text-surface-500">Generating…</p>
{/if}
