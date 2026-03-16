<script lang="ts">
	/**
	 * Per docs/plans/QR-TOKEN-PRINT-SYSTEM.md QR-1: Token print template.
	 * physical_id, QR, cut lines, configurable cards per page, A4/Letter.
	 */
	import { Link } from '@inertiajs/svelte';

	interface Card {
		physical_id: string;
		qr_data_uri: string;
		qr_hash: string;
	}

	interface Props {
		cards: Card[];
		pages: Card[][];
		cardsPerRow: number;
		cardsPerColumn: number;
		paperSize: 'a4' | 'letter';
		orientation?: 'portrait' | 'landscape';
		showHint?: boolean;
		showCutLines?: boolean;
		logoUrl?: string | null;
		footerText?: string | null;
		bgImageUrl?: string | null;
		skipped: number;
	}

	let {
		cards = [],
		pages = [],
		cardsPerRow = 3,
		cardsPerColumn = 2,
		paperSize = 'a4',
		orientation = 'portrait',
		showHint = true,
		showCutLines = true,
		logoUrl = null,
		footerText = null,
		bgImageUrl = null,
		skipped = 0
	}: Props = $props();
	let showInstructions = $state(false);

	// Instruction dimensions (32mm QR, 6:5 background) must stay in sync with template CSS; consider backend-driven values if paper-specific later.
	const paperLabel = $derived(paperSize === 'letter' ? 'US Letter' : 'A4');

	function printPage() {
		window.print();
	}
</script>

<svelte:head>
	<title>FlexiQueue – Token Cards</title>
	{@html `<style>@page { size: ${paperSize === 'letter' ? 'letter' : 'A4'} ${orientation}; margin: 10mm; }</style>`}
</svelte:head>

<style>
	/* Isolate print page in light scheme so dark app theme does not affect preview or print output. */
	.print-page-wrapper {
		color-scheme: light;
		background: #f3f4f6;
		min-height: 100vh;
		padding: 1rem;
	}

	.print-instructions-list {
		display: flex;
		flex-direction: column;
		gap: 0.5rem;
		color: #1f2937;
	}

	.print-sheet {
		display: grid;
		grid-template-columns: repeat(var(--cols, 3), 1fr);
		grid-template-rows: repeat(var(--rows, 2), minmax(80mm, 1fr));
		gap: 4mm;
		min-height: 50vh;
		padding: 5mm;
		background: #ffffff;
	}

	.print-card {
		border: 1px dashed #999;
		padding: 4mm;
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		text-align: center;
		min-height: 45mm;
		page-break-inside: avoid;
		background-color: #ffffff;
		background-size: cover;
		background-position: center;
		background-repeat: no-repeat;
	}

	.print-sheet--no-cutlines .print-card {
		border: none;
	}

	/* Logo height on card; keep proportional to card and QR. */
	.print-logo img {
		height: 18mm;
		width: auto;
		object-fit: contain;
	}

	.print-physical-id {
		font-size: 32pt;
		font-weight: 700;
		margin-bottom: 2mm;
		line-height: 1.2;
		color: #000;
		/* Dark letter shadow for readability over bg image */
		text-shadow:
			0 0 1px rgba(0, 0, 0, 0.9),
			0 1px 2px rgba(0, 0, 0, 0.5),
			0 2px 4px rgba(0, 0, 0, 0.25);
	}

	.print-qr-container {
		margin: 2mm 0;
	}

	.print-qr-container img {
		width: 32mm;
		height: 32mm;
		display: block;
	}

	/* Footer (premise rules etc.): semi-opaque background so text stays readable over card bg image */
	.print-footer {
		font-size: 8pt;
		color: #000;
		margin-top: 2mm;
		text-align: center;
		background: rgba(255, 255, 255, 0.8);
		padding: 1.5mm 3mm;
		border-radius: 1mm;
		display: inline-block;
		max-width: 100%;
	}

	@media print {
		:global(html),
		:global(body) {
			margin: 0;
			padding: 0;
			print-color-adjust: exact;
			-webkit-print-color-adjust: exact;
		}

		.screen-only {
			display: none !important;
		}

		/* Remove screen wrapper styling so print output is clean */
		.print-page-wrapper {
			min-height: unset;
			padding: 0;
			background: transparent;
		}

		/* Force backgrounds and colors to print (match screen preview) */
		.print-sheet,
		.print-card {
			print-color-adjust: exact;
			-webkit-print-color-adjust: exact;
		}

		.print-sheet {
			min-height: 267mm;
			page-break-after: always;
			page-break-inside: avoid;
		}

		/* No trailing break after last sheet to avoid extra blank page */
		.print-sheet:last-child {
			page-break-after: avoid !important;
		}
	}
</style>

<div class="print-page-wrapper">
	<!-- Screen-only toolbar: Back + Print + Instructions -->
	<div class="screen-only mb-4 space-y-2">
		<div class="flex flex-wrap items-center gap-3">
			<Link
				href="/admin/tokens"
				class="btn preset-tonal btn-sm"
			>
				← Back to Tokens
			</Link>
			<button type="button" class="btn preset-filled-primary-500 btn-sm" onclick={printPage}>
				Print
			</button>
			<button
				type="button"
				class="btn preset-tonal btn-sm"
				aria-expanded={showInstructions}
				aria-controls="print-instructions"
				onclick={() => (showInstructions = !showInstructions)}
			>
				{showInstructions ? 'Hide instructions' : 'Print instructions'}
			</button>
		</div>
		{#if showInstructions}
			<div
				id="print-instructions"
				class="print-instructions-box rounded-box border border-gray-200 bg-gray-50 p-4 text-sm"
				role="region"
				aria-label="Print instructions"
			>
				<ul class="print-instructions-list">
					<li>
						<strong>Cut lines:</strong> The dashed borders around each card are cut lines. Cut along them to separate individual token cards.
					</li>
					<li>
						<strong>Paper:</strong> Use {paperLabel}. Ensure your printer's paper size matches.
					</li>
					<li>
						<strong>QR codes:</strong> Sized at 32mm for reliable scanning (matches this template). Test a sample before bulk printing.
					</li>
					<li>
						<strong>Background image:</strong> Use 6:5 aspect ratio (e.g. 60×50mm, 300×250px) for best fit per card.
					</li>
				</ul>
			</div>
		{/if}
	</div>

	{#if cards.length === 0}
		<div
			role="status"
			aria-label="No tokens to print"
			class="print-empty-state rounded-lg bg-white p-8 text-center text-gray-600 flex flex-col items-center gap-4"
		>
			<p class="font-medium">No tokens to print.</p>
			{#if skipped > 0}
				<p class="text-sm">
					{skipped} token(s) skipped (invalid or QR generation failed).
				</p>
			{/if}
			<Link
				href="/admin/tokens"
				class="btn preset-filled-primary-500 flex items-center gap-2 touch-target-h"
			>
				Go to Tokens
			</Link>
		</div>
	{:else}
		{#each pages as pageCards}
			<div
				class="print-sheet rounded-lg shadow-sm"
				class:print-sheet--no-cutlines={!showCutLines}
				style="--cols: {cardsPerRow}; --rows: {cardsPerColumn};"
			>
				{#each pageCards as card}
					<div
						class="print-card"
						class:print-card--has-bg={!!bgImageUrl}
						style={bgImageUrl ? `background-image: url(${bgImageUrl});` : ''}
					>
						{#if logoUrl}
							<div class="print-logo mb-1">
								<img src={logoUrl} alt="" />
							</div>
						{/if}
						<div class="print-physical-id">{card.physical_id}</div>
						<div class="print-qr-container">
							<img
								src={card.qr_data_uri}
								alt="QR code for {card.physical_id}"
								width="120"
								height="120"
							/>
						</div>
						{#if footerText}
							<div class="print-footer">{footerText}</div>
						{/if}
					</div>
				{/each}
			</div>
		{/each}
	{/if}
</div>
