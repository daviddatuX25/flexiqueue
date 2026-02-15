<script lang="ts">
	import AdminLayout from '../../../Layouts/AdminLayout.svelte';
	import Modal from '../../../Components/Modal.svelte';
	import { get } from 'svelte/store';
	import { usePage } from '@inertiajs/svelte';

	interface TokenItem {
		id: number;
		physical_id: string;
		qr_code_hash: string;
		status: string;
	}

	let tokens = $state<TokenItem[]>([]);
	let loading = $state(true);
	let submitting = $state(false);
	let error = $state('');
	let showBatchModal = $state(false);
	let filterStatus = $state('');
	let searchQuery = $state('');
	// Batch form
	let batchPrefix = $state('A');
	let batchStart = $state(1);
	let batchCount = $state(50);
	let openDropdownId = $state<number | null>(null);
	// Selection for bulk actions
	let selectedIds = $state<Set<number>>(new Set());
	let selectAllCheckbox = $state<HTMLInputElement | null>(null);
	// Print modal (uniform flow: select template then print)
	let showPrintModal = $state(false);
	let printSettings = $state({
		cards_per_page: 6,
		paper: 'a4',
		orientation: 'portrait',
		show_hint: true,
		show_cut_lines: true,
		logo_url: '' as string,
		footer_text: '' as string,
		bg_image_url: '' as string
	});

	const page = usePage();
	function getCsrfToken(): string {
		const p = get(page);
		const fromProps = (p?.props as { csrf_token?: string } | undefined)?.csrf_token;
		if (fromProps) return fromProps;
		const meta = typeof document !== 'undefined' ? (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content : '';
		return meta ?? '';
	}

	async function api(
		method: string,
		url: string,
		body?: object
	): Promise<{ ok: boolean; data?: { tokens?: TokenItem[]; token?: TokenItem; created?: number }; message?: string }> {
		const res = await fetch(url, {
			method,
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
				'X-CSRF-TOKEN': getCsrfToken(),
				'X-Requested-With': 'XMLHttpRequest'
			},
			credentials: 'same-origin',
			...(body ? { body: JSON.stringify(body) } : {})
		});
		const data = await res.json().catch(() => ({}));
		return { ok: res.ok, data, message: data?.message };
	}

	function buildTokensUrl(): string {
		const params = new URLSearchParams();
		if (filterStatus) params.set('status', filterStatus);
		if (searchQuery.trim()) params.set('search', searchQuery.trim());
		const q = params.toString();
		return q ? `/api/admin/tokens?${q}` : '/api/admin/tokens';
	}

	async function fetchTokens() {
		loading = true;
		const { ok, data } = await api('GET', buildTokensUrl());
		loading = false;
		if (ok && data?.tokens) {
			tokens = data.tokens;
		} else {
			tokens = [];
		}
	}

	async function onFilterApply() {
		await fetchTokens();
	}

	function openBatchModal() {
		batchPrefix = 'A';
		batchStart = 1;
		batchCount = 50;
		error = '';
		showBatchModal = true;
	}

	function closeBatchModal() {
		showBatchModal = false;
		error = '';
	}

	async function handleBatchCreate() {
		if (batchCount < 1 || batchCount > 500) return;
		submitting = true;
		error = '';
		const { ok, data, message } = await api('POST', '/api/admin/tokens/batch', {
			prefix: batchPrefix.trim(),
			count: batchCount,
			start_number: batchStart
		});
		submitting = false;
		if (ok) {
			closeBatchModal();
			await fetchTokens();
		} else {
			error = message ?? (data && 'errors' in data ? 'Validation failed.' : 'Failed to create tokens.');
		}
	}

	async function setTokenStatus(token: TokenItem, status: string) {
		openDropdownId = null;
		submitting = true;
		error = '';
		const { ok, data, message } = await api('PUT', `/api/admin/tokens/${token.id}`, { status });
		submitting = false;
		if (ok && data?.token) {
			tokens = tokens.map((t) => (t.id === token.id ? { ...t, status: data.token.status } : t));
		} else {
			error = message ?? 'Failed to update status.';
		}
	}

	function toggleDropdown(id: number) {
		openDropdownId = openDropdownId === id ? null : id;
	}

	// Selection
	const selectableForDelete = $derived(tokens.filter((t) => t.status === 'available'));
	const allSelected = $derived(
		selectableForDelete.length > 0 &&
			selectableForDelete.every((t) => selectedIds.has(t.id))
	);
	const someSelected = $derived(selectedIds.size > 0);
	const selectedForPrint = $derived(tokens.filter((t) => selectedIds.has(t.id)));

	function toggleSelectAll() {
		if (allSelected) {
			selectedIds = new Set();
		} else {
			selectedIds = new Set(selectableForDelete.map((t) => t.id));
		}
	}

	function toggleSelect(id: number) {
		const next = new Set(selectedIds);
		if (next.has(id)) next.delete(id);
		else next.add(id);
		selectedIds = next;
	}

	function getPrintUrl(ids: number[], opts?: typeof printSettings): string {
		if (ids.length === 0) return '/admin/tokens/print';
		const s = opts ?? printSettings;
		const params = new URLSearchParams();
		params.set('ids', ids.join(','));
		params.set('cards_per_page', String(s.cards_per_page));
		params.set('paper', s.paper);
		params.set('orientation', s.orientation);
		params.set('hint', s.show_hint ? '1' : '0');
		params.set('cutlines', s.show_cut_lines ? '1' : '0');
		if (s.logo_url?.trim()) params.set('logo_url', s.logo_url.trim());
		if (s.footer_text?.trim()) params.set('footer_text', s.footer_text.trim());
		if (s.bg_image_url?.trim()) params.set('bg_image_url', s.bg_image_url.trim());
		return `/admin/tokens/print?${params.toString()}`;
	}

	let printTargetIds = $state<number[]>([]);

	function openPrintModal(ids: number[]) {
		printTargetIds = ids;
		printSettingsSaved = false;
		showPrintModal = true;
		fetchPrintSettings();
	}

	function closePrintModal() {
		showPrintModal = false;
	}

	async function fetchPrintSettings() {
		const { ok, data } = await api('GET', '/api/admin/print-settings');
		if (ok && data?.print_settings) {
			const s = data.print_settings;
			printSettings = {
				cards_per_page: s.cards_per_page ?? 6,
				paper: s.paper ?? 'a4',
				orientation: s.orientation ?? 'portrait',
				show_hint: s.show_hint !== false,
				show_cut_lines: s.show_cut_lines !== false,
				logo_url: s.logo_url ?? '',
				footer_text: s.footer_text ?? '',
				bg_image_url: s.bg_image_url ?? ''
			};
		}
	}

	function doPrint() {
		const url = getPrintUrl(printTargetIds);
		window.open(url, '_blank', 'noopener,noreferrer');
		closePrintModal();
	}

	let printSettingsSaved = $state(false);

	async function savePrintSettings() {
		submitting = true;
		error = '';
		printSettingsSaved = false;
		const { ok, data, message } = await api('PUT', '/api/admin/print-settings', {
			cards_per_page: printSettings.cards_per_page,
			paper: printSettings.paper,
			orientation: printSettings.orientation,
			show_hint: printSettings.show_hint,
			show_cut_lines: printSettings.show_cut_lines,
			logo_url: printSettings.logo_url.trim() || null,
			footer_text: printSettings.footer_text.trim() || null,
			bg_image_url: printSettings.bg_image_url.trim() || null
		});
		submitting = false;
		if (ok && data?.print_settings) {
			const s = data.print_settings;
			printSettings = {
				cards_per_page: s.cards_per_page ?? 6,
				paper: s.paper ?? 'a4',
				orientation: s.orientation ?? 'portrait',
				show_hint: s.show_hint !== false,
				show_cut_lines: s.show_cut_lines !== false,
				logo_url: s.logo_url ?? '',
				footer_text: s.footer_text ?? '',
				bg_image_url: s.bg_image_url ?? ''
			};
			printSettingsSaved = true;
		} else {
			error = message ?? 'Failed to save settings.';
		}
	}

	async function handleBatchDelete() {
		const ids = [...selectedIds];
		if (ids.length === 0) return;
		if (!confirm(`Delete ${ids.length} token(s)? They will be soft-deleted and can no longer be used.`))
			return;
		submitting = true;
		error = '';
		const { ok, data, message } = await api('POST', '/api/admin/tokens/batch-delete', { ids });
		submitting = false;
		if (ok) {
			selectedIds = new Set();
			await fetchTokens();
		} else {
			error = message ?? 'Failed to delete tokens.';
		}
	}

	async function handleDeleteToken(token: TokenItem) {
		if (token.status === 'in_use') {
			error = 'Cannot delete token in use.';
			return;
		}
		if (!confirm(`Delete token ${token.physical_id}? It will be soft-deleted.`)) return;
		openDropdownId = null;
		submitting = true;
		error = '';
		const { ok, message } = await api('DELETE', `/api/admin/tokens/${token.id}`);
		submitting = false;
		if (ok) {
			tokens = tokens.filter((t) => t.id !== token.id);
		} else {
			error = message ?? 'Failed to delete token.';
		}
	}

	// Load tokens on mount
	$effect(() => {
		fetchTokens();
	});

	// Sync select-all checkbox indeterminate state
	$effect(() => {
		const el = selectAllCheckbox;
		if (el) {
			el.indeterminate = someSelected && !allSelected;
		}
	});

	// Close dropdown when clicking outside (DaisyUI dropdown-open is manual)
	function onDocumentClick(e: MouseEvent) {
		const target = e.target as HTMLElement;
		if (openDropdownId !== null && !target.closest('.dropdown')) {
			openDropdownId = null;
		}
	}
</script>

<svelte:window onclick={onDocumentClick} />

<svelte:head>
	<title>Tokens — FlexiQueue</title>
</svelte:head>

<AdminLayout>
	<div class="flex flex-col gap-4">
		<div class="flex flex-wrap items-center justify-between gap-2">
			<h1 class="text-2xl font-semibold text-base-content">Token Management</h1>
			<div class="flex gap-2">
				<button type="button" class="btn btn-outline btn-sm" onclick={() => openPrintModal([])}>
					Print settings
				</button>
				<button type="button" class="btn btn-primary" onclick={openBatchModal}>Create Batch</button>
			</div>
		</div>

		{#if someSelected}
			<div
				class="flex flex-wrap items-center gap-3 rounded-box border border-primary/30 bg-primary/10 px-4 py-2"
				role="toolbar"
				aria-label="Bulk actions"
			>
				<span class="text-sm font-medium text-base-content">
					{selectedIds.size} selected
				</span>
				<button
					type="button"
					class="btn btn-primary btn-sm"
					onclick={() => openPrintModal([...selectedIds])}
				>
					Print selected
				</button>
				<button
					type="button"
					class="btn btn-error btn-sm"
					onclick={handleBatchDelete}
					disabled={submitting || selectedForPrint.some((t) => t.status === 'in_use')}
					title={selectedForPrint.some((t) => t.status === 'in_use')
						? 'Cannot delete tokens in use. Deselect them first.'
						: ''}
				>
					{submitting ? 'Deleting…' : 'Delete selected'}
				</button>
				<button
					type="button"
					class="btn btn-ghost btn-sm"
					onclick={() => (selectedIds = new Set())}
				>
					Clear selection
				</button>
			</div>
		{/if}

		<!-- Filter bar per 09-UI-ROUTES §3.9 -->
		<div class="flex flex-wrap items-center gap-3">
			<select
				class="select select-bordered select-sm w-40"
				bind:value={filterStatus}
				aria-label="Filter by status"
			>
				<option value="">All statuses</option>
				<option value="available">Available</option>
				<option value="in_use">In use</option>
			</select>
			<input
				type="text"
				class="input input-bordered input-sm w-48"
				placeholder="Search by ID (e.g. A1)"
				bind:value={searchQuery}
				onkeydown={(e) => e.key === 'Enter' && onFilterApply()}
			/>
			<button type="button" class="btn btn-primary btn-sm" onclick={onFilterApply} disabled={loading}>
				{loading ? 'Loading…' : 'Apply'}
			</button>
		</div>

		{#if error}
			<div class="alert alert-error" role="alert">
				<span>{error}</span>
				<button type="button" class="btn btn-ghost btn-sm" onclick={() => (error = '')}>Dismiss</button>
			</div>
		{/if}

		{#if loading && tokens.length === 0}
			<div class="rounded-box bg-base-100 border border-base-300 p-8 text-center text-base-content/70">
				<span class="loading loading-spinner loading-lg"></span>
				<p class="mt-2">Loading tokens…</p>
			</div>
		{:else if tokens.length === 0}
			<div class="rounded-box bg-base-100 border border-base-300 p-8 text-center text-base-content/70">
				<p>No tokens found. Create a batch to get started.</p>
			</div>
		{:else}
			<div class="overflow-x-auto rounded-box border border-base-300 bg-base-100">
				<table class="table table-zebra">
					<thead>
						<tr>
							<th class="w-10">
								<input
									type="checkbox"
									class="checkbox checkbox-sm"
									bind:this={selectAllCheckbox}
									checked={allSelected}
									onchange={toggleSelectAll}
									aria-label="Select all available"
									disabled={selectableForDelete.length === 0}
								/>
							</th>
							<th>Physical ID</th>
							<th>QR Hash</th>
							<th>Status</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						{#each tokens as token (token.id)}
							<tr>
								<td>
									{#if token.status === 'available'}
										<input
											type="checkbox"
											class="checkbox checkbox-sm"
											checked={selectedIds.has(token.id)}
											onchange={() => toggleSelect(token.id)}
											aria-label="Select {token.physical_id}"
										/>
									{:else}
										<span class="text-base-content/40" title="In-use tokens cannot be selected for delete"
											>—</span
										>
									{/if}
								</td>
								<td class="font-mono font-medium">{token.physical_id}</td>
								<td class="font-mono text-sm text-base-content/70" title={token.qr_code_hash}>
									{token.qr_code_hash.slice(0, 12)}…
								</td>
								<td>
									{#if token.status === 'available'}
										<span class="badge badge-success">Available</span>
									{:else if token.status === 'in_use'}
										<span class="badge badge-info">In use</span>
									{:else}
										<span class="badge badge-ghost">{token.status}</span>
									{/if}
								</td>
								<td>
									<div class="dropdown dropdown-end" class:dropdown-open={openDropdownId === token.id}>
											<button
												type="button"
												class="btn btn-ghost btn-sm"
												onclick={() => toggleDropdown(token.id)}
												disabled={submitting}
												aria-haspopup="true"
												aria-expanded={openDropdownId === token.id}
											>
												Actions ▾
											</button>
											<ul class="dropdown-content menu bg-base-100 border border-base-300 rounded-box z-10 mt-1 w-48 p-1 shadow">
												{#if token.status === 'in_use'}
													<li>
														<button
															type="button"
															class="btn btn-ghost btn-sm justify-start"
															onclick={() => setTokenStatus(token, 'available')}
															disabled={submitting}
														>
															Mark Available
														</button>
													</li>
												{/if}
												<li>
													<button
														type="button"
														class="btn btn-ghost btn-sm justify-start text-error"
														onclick={() => handleDeleteToken(token)}
														disabled={submitting || token.status === 'in_use'}
													>
														Delete
													</button>
												</li>
											</ul>
										</div>
								</td>
							</tr>
						{/each}
					</tbody>
				</table>
			</div>
		{/if}
	</div>
</AdminLayout>

<Modal open={showBatchModal} title="Create token batch" onClose={closeBatchModal}>
	{#snippet children()}
		<form
			onsubmit={(e) => {
				e.preventDefault();
				handleBatchCreate();
			}}
			class="flex flex-col gap-4"
		>
			<div class="form-control w-full">
				<label for="batch-prefix" class="label"><span class="label-text">Prefix</span></label>
				<input
					id="batch-prefix"
					type="text"
					class="input input-bordered w-full"
					placeholder="e.g. A"
					maxlength="10"
					bind:value={batchPrefix}
					required
				/>
			</div>
			<div class="form-control w-full">
				<label for="batch-start" class="label"><span class="label-text">Start number</span></label>
				<input
					id="batch-start"
					type="number"
					class="input input-bordered w-full"
					min="0"
					bind:value={batchStart}
					required
				/>
			</div>
			<div class="form-control w-full">
				<label for="batch-count" class="label"><span class="label-text">Count</span></label>
				<input
					id="batch-count"
					type="number"
					class="input input-bordered w-full"
					min="1"
					max="500"
					bind:value={batchCount}
					required
				/>
				<p class="label-text-alt text-base-content/70">Preview: {batchPrefix}{batchStart} … {batchPrefix}{Number(batchStart) + Number(batchCount) - 1}</p>
			</div>
			<div class="flex justify-end gap-2">
				<button type="button" class="btn btn-ghost" onclick={closeBatchModal}>Cancel</button>
				<button
					type="submit"
					class="btn btn-primary"
					disabled={submitting || batchCount < 1 || batchCount > 500 || !batchPrefix.trim()}
				>
					{submitting ? 'Creating…' : 'Create'}
				</button>
			</div>
		</form>
	{/snippet}
</Modal>

<Modal open={showPrintModal} title="Print tokens" onClose={closePrintModal}>
	{#snippet children()}
		<div class="flex flex-col gap-4">
			{#if printTargetIds.length > 0}
				<p class="text-sm text-base-content/80">
					Printing {printTargetIds.length} token(s). Adjust template options below.
				</p>
			{:else}
				<p class="text-sm text-base-content/80">
					Edit print template defaults. Select tokens and click Print selected to print.
				</p>
			{/if}
			<div class="grid grid-cols-2 gap-4">
				<div class="form-control">
					<label for="print-cards" class="label"><span class="label-text">Cards per page</span></label>
					<select id="print-cards" class="select select-bordered w-full" bind:value={printSettings.cards_per_page}>
						{#each [4, 5, 6, 7, 8] as n}
							<option value={n}>{n}</option>
						{/each}
					</select>
				</div>
				<div class="form-control">
					<label for="print-paper" class="label"><span class="label-text">Paper</span></label>
					<select id="print-paper" class="select select-bordered w-full" bind:value={printSettings.paper}>
						<option value="a4">A4</option>
						<option value="letter">Letter</option>
					</select>
				</div>
				<div class="form-control">
					<label for="print-orientation" class="label"><span class="label-text">Orientation</span></label>
					<select id="print-orientation" class="select select-bordered w-full" bind:value={printSettings.orientation}>
						<option value="portrait">Portrait</option>
						<option value="landscape">Landscape</option>
					</select>
				</div>
			</div>
			<div class="flex gap-4">
				<label class="label cursor-pointer gap-2">
					<input type="checkbox" class="checkbox checkbox-sm" bind:checked={printSettings.show_hint} />
					<span class="label-text">Show "Scan for status" hint</span>
				</label>
				<label class="label cursor-pointer gap-2">
					<input type="checkbox" class="checkbox checkbox-sm" bind:checked={printSettings.show_cut_lines} />
					<span class="label-text">Show cut lines</span>
				</label>
			</div>
			<div class="form-control">
				<label for="print-logo" class="label"><span class="label-text">Logo URL (optional)</span></label>
				<input
					id="print-logo"
					type="url"
					class="input input-bordered w-full"
					placeholder="https://example.com/logo.png"
					bind:value={printSettings.logo_url}
				/>
			</div>
			<div class="form-control">
				<label for="print-footer" class="label"><span class="label-text">Footer text (optional)</span></label>
				<textarea
					id="print-footer"
					class="textarea textarea-bordered w-full"
					placeholder="Shown on each card, centered. e.g. Premise rules, office hours"
					rows="2"
					bind:value={printSettings.footer_text}
				></textarea>
			</div>
			<div class="form-control">
				<label for="print-bg" class="label"><span class="label-text">Background image URL (optional)</span></label>
				<input
					id="print-bg"
					type="text"
					class="input input-bordered w-full"
					placeholder="https://example.com/bg.png"
					bind:value={printSettings.bg_image_url}
				/>
				<p class="label-text-alt text-base-content/70 mt-1">
					Use 6:5 aspect ratio (e.g. 60×50mm, 300×250px) for best fit per token card.
				</p>
			</div>
			<div class="flex justify-end gap-2">
				<button type="button" class="btn btn-ghost" onclick={closePrintModal}>Cancel</button>
				<button
					type="button"
					class="btn btn-outline"
					onclick={() => savePrintSettings()}
					disabled={submitting}
				>
					{printSettingsSaved ? 'Saved' : submitting ? 'Saving…' : 'Save as default'}
				</button>
				<button
					type="button"
					class="btn btn-primary"
					onclick={doPrint}
					disabled={printTargetIds.length === 0}
					title={printTargetIds.length === 0 ? 'Select tokens first' : ''}
				>
					Print
				</button>
			</div>
		</div>
	{/snippet}
</Modal>
