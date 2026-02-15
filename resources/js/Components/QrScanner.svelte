<script lang="ts">
	/**
	 * QR scanner using html5-qrcode. When active, shows camera and calls onScan(decodedText) on success.
	 */
	import { onMount, onDestroy } from 'svelte';

	let { active = false, onScan = () => {} } = $props();
	let containerId = 'qr-reader-' + Math.random().toString(36).slice(2, 9);
	let html5Qrcode: import('html5-qrcode').Html5Qrcode | null = null;

	async function startScanner() {
		await stopScanner();
		if (!document.getElementById(containerId)) return;
		const { Html5Qrcode } = await import('html5-qrcode');
		html5Qrcode = new Html5Qrcode(containerId);
		await html5Qrcode.start(
			{ facingMode: 'environment' },
			{ fps: 5, qrbox: { width: 250, height: 250 } },
			(decodedText) => onScan(decodedText),
			() => {}
		);
	}

	async function stopScanner() {
		if (html5Qrcode?.isScanning()) {
			await html5Qrcode.stop();
		}
		html5Qrcode = null;
	}

	$effect(() => {
		if (active) {
			startScanner();
		} else {
			stopScanner();
		}
		return () => {
			stopScanner();
		};
	});

	onDestroy(() => {
		stopScanner();
	});
</script>

{#if active}
	<div id={containerId} class="overflow-hidden rounded-lg border border-base-300 bg-base-100 min-h-[250px] w-full max-w-[300px] mx-auto"></div>
{/if}
