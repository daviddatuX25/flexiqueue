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

	function printPage() {
		window.print();
	}
</script>

<svelte:head>
	<title>FlexiQueue – Token Cards</title>
	{@html `<style>@page { size: ${paperSize === 'letter' ? 'letter' : 'A4'} ${orientation}; margin: 10mm; }</style>`}
</svelte:head>

<style>
	.print-sheet {
		display: grid;
		grid-template-columns: repeat(var(--cols, 3), 1fr);
		grid-template-rows: repeat(var(--rows, 2), minmax(80mm, 1fr));
		gap: 4mm;
		min-height: 50vh;
		padding: 5mm;
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
		background-size: cover;
		background-position: center;
		background-repeat: no-repeat;
	}

	.print-sheet--no-cutlines .print-card {
		border: none;
	}

	.print-physical-id {
		font-size: 18pt;
		font-weight: 700;
		margin-bottom: 2mm;
		line-height: 1.2;
	}

	.print-qr-container {
		margin: 2mm 0;
	}

	.print-qr-container img {
		width: 32mm;
		height: 32mm;
		display: block;
	}

	.print-hint,
	.print-footer {
		font-size: 8pt;
		color: #666;
		margin-top: 2mm;
		text-align: center;
	}

	@media print {
		html,
		body {
			margin: 0;
			padding: 0;
			print-color-adjust: exact;
			-webkit-print-color-adjust: exact;
		}

		.screen-only {
			display: none !important;
		}

		/* Remove screen padding that can push content past page bounds */
		.min-h-screen {
			min-height: unset;
			padding: 0;
			background: transparent;
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

<div class="min-h-screen bg-surface-100 p-4">
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
				class="rounded-box border border-surface-200 bg-surface-50 p-4 text-sm"
				role="region"
				aria-label="Print instructions"
			>
				<ul class="space-y-2 text-surface-950/90">
					<li>
						<strong>Cut lines:</strong> The dashed borders around each card are cut lines. Cut along them to separate individual token cards.
					</li>
					<li>
						<strong>Paper:</strong> Use A4 or US Letter. Ensure your printer's paper size matches.
					</li>
					<li>
						<strong>QR codes:</strong> Sized at 32mm for reliable scanning. Test a sample before bulk printing.
					</li>
					<li>
						<strong>Background image:</strong> Use 6:5 aspect ratio (e.g. 60×50mm, 300×250px) for best fit per card.
					</li>
				</ul>
			</div>
		{/if}
	</div>

	{#if cards.length === 0}
		<div class="rounded-lg bg-surface-50 p-8 text-center text-surface-950/70">
			<p class="font-medium">No tokens to print.</p>
			{#if skipped > 0}
				<p class="mt-2 text-sm">
					{skipped} token(s) skipped (invalid or QR generation failed).
				</p>
			{/if}
		</div>
	{:else}
		{#each pages as pageCards}
			<div
				class="print-sheet rounded-lg bg-surface-50 shadow-sm"
				class:print-sheet--no-cutlines={!showCutLines}
				style="--cols: {cardsPerRow}; --rows: {cardsPerColumn};"
			>
				{#each pageCards as card}
					<div
						class="print-card"
						style={bgImageUrl ? `background-image: url(${bgImageUrl});` : ''}
					>
						{#if logoUrl}
							<div class="print-logo mb-1">
								<img src={logoUrl} alt="" class="h-6 w-auto object-contain" />
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
						{#if showHint}
							<div class="print-hint">Scan for status</div>
						{/if}
						{#if footerText}
							<div class="print-footer">{footerText}</div>
						{/if}
					</div>
				{/each}
			</div>
		{/each}
	{/if}
</div>
