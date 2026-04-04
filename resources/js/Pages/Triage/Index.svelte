<script lang="ts">
	import MobileLayout from '../../Layouts/MobileLayout.svelte';
	import Modal from '../../Components/Modal.svelte';
	import CreateRegistrationModal from '../../Components/CreateRegistrationModal.svelte';
	import { Plus, Search } from 'lucide-svelte';
	import { get } from 'svelte/store';
	import { usePage, router } from '@inertiajs/svelte';
	import { toaster } from '../../lib/toaster.js';
	import { clientDisplayName } from '../../lib/clientDisplayName.js';

	interface Track {
		id: number;
		name: string;
		color_code: string | null;
		is_default: boolean;
	}

	interface ActiveProgram {
		id: number;
		name: string;
		is_active?: boolean;
		is_paused?: boolean;
		tracks: Track[];
		identity_binding_mode?: 'disabled' | 'required';
		allow_unverified_entry?: boolean;
	}

	/** A.4.2: currentProgram from controller; fallback to program then activeProgram for transition. */
	let {
		currentProgram = null,
		program = null,
		activeProgram = null,
		canSwitchProgram = false,
		programs = [],
		queueCount = 0,
		processedToday = 0,
		pending_identity_registrations = [],
		site_slug = null,
		program_slug = null,
	}: {
		currentProgram?: ActiveProgram | null;
		program?: ActiveProgram | null;
		activeProgram?: ActiveProgram | null;
		canSwitchProgram?: boolean;
		programs?: { id: number; name: string }[];
		queueCount?: number;
		processedToday?: number;
		pending_identity_registrations?: { id: number; request_type?: string; first_name: string | null; middle_name: string | null; last_name: string | null; birth_date: string | null; client_category: string | null; mobile_masked: string | null; id_verified: boolean; id_verified_at: string | null; id_verified_by_user_id: number | null; id_verified_by: string | null; requested_at: string; session_id: number | null; session_alias: string | null; token_physical_id?: string; track_name?: string; client_name?: string | null }[];
		site_slug?: string | null;
		program_slug?: string | null;
	} = $props();

	const effectiveProgram = $derived(currentProgram ?? program ?? activeProgram);

	/** Identity registration accept modal */
	let acceptRegModalReg = $state<typeof pending_identity_registrations[0] | null>(null);
	let acceptVerifyFirstName = $state('');
	let acceptVerifyLastName = $state('');
	let acceptVerifyBirthDate = $state('');
	let acceptVerifyCategory = $state('Regular');
	let acceptPossibleMatches = $state<{ id: number; first_name: string; middle_name?: string | null; last_name: string; birth_date: string | null; mobile_masked?: string | null }[]>([]);
	let acceptLinkSearchName = $state('');
	let acceptLinkSearchBirthDate = $state('');
	let acceptLinkSearching = $state(false);
	let acceptLinkSearchPhone = $state('');
	let acceptPhoneSearching = $state(false);
	let acceptChosenClientId = $state<number | null>(null);
	let acceptCreateNew = $state(false);
	let acceptSubmitting = $state(false);
	/** Reveal phone modal (from Accept modal): reason + result. */
	let showAcceptRevealModal = $state(false);
	let acceptRevealReason = $state('');
	let acceptRevealResult = $state<string | null>(null);
	let acceptRevealSubmitting = $state(false);
	/** When possible-matches returns existing_client_by_phone, this is set so we can show verify-existing copy and pre-select. */
	let acceptExistingClientByPhone = $state<{ id: number; first_name: string; middle_name?: string | null; last_name: string; birth_date: string | null } | null>(null);
	let highlightSessionId = $state<number | null>(null);

	/** Staff direct registration request (no token) */
	let showNewRegModal = $state(false);

	const page = usePage();
	function getCsrfToken(): string {
		const p = get(page);
		const fromProps = (p?.props as { csrf_token?: string } | undefined)?.csrf_token;
		if (fromProps) return fromProps;
		const meta = typeof document !== 'undefined' ? (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content : '';
		return meta ?? '';
	}

	const MSG_SESSION_EXPIRED = 'Session expired. Please refresh and try again.';
	const MSG_NETWORK_ERROR = 'Network error. Please try again.';

	async function api(method: string, url: string, body?: object): Promise<{ ok: boolean; data?: object; message?: string; errors?: Record<string, string[]> }> {
		try {
			const res = await fetch(url, {
				method,
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-CSRF-TOKEN': getCsrfToken(),
					'X-Requested-With': 'XMLHttpRequest',
				},
				credentials: 'same-origin',
				...(body ? { body: JSON.stringify(body) } : {}),
			});
			if (res.status === 419) {
				toaster.error({ title: MSG_SESSION_EXPIRED });
				return { ok: false, message: MSG_SESSION_EXPIRED };
			}
			const data = await res.json().catch(() => ({}));
			return { ok: res.ok, data, message: data?.message, errors: data?.errors };
		} catch (e) {
			toaster.error({ title: MSG_NETWORK_ERROR });
			return { ok: false, message: MSG_NETWORK_ERROR };
		}
	}

	async function submitNewRegistrationRequest(payload: import('../../Components/CreateRegistrationModal.svelte').CreateRegistrationPayload) {
		const { ok, data, message } = await api('POST', '/api/identity-registrations/direct', payload);
		const msg = (data as { message?: string })?.message ?? (message as string);
		return { ok, message: msg };
	}

	// Identity registration accept/reject
	const ACCEPT_CATEGORIES = [
		{ label: 'Regular', value: 'Regular' },
		{ label: 'PWD / Senior / Pregnant', value: 'PWD / Senior / Pregnant' },
	] as const;

	function openAcceptModal(reg: (typeof pending_identity_registrations)[0]) {
		acceptRegModalReg = reg;
		acceptVerifyFirstName = reg.first_name ?? '';
		acceptVerifyLastName = reg.last_name ?? '';
		acceptVerifyBirthDate = reg.birth_date ?? '';
		acceptVerifyCategory = reg.client_category ?? 'Regular';
		acceptPossibleMatches = [];
		acceptExistingClientByPhone = null;
		acceptLinkSearchName = clientDisplayName(reg);
		acceptLinkSearchBirthDate = reg.birth_date ?? '';
		acceptChosenClientId = null;
		acceptCreateNew = false;
		fetchPossibleMatches(reg.id);
	}

	function closeAcceptModal() {
		acceptRegModalReg = null;
		showAcceptRevealModal = false;
		acceptRevealResult = null;
		acceptRevealReason = '';
	}

	async function fetchPossibleMatches(regId: number) {
		const { ok, data } = await api('GET', `/api/identity-registrations/${regId}/possible-matches`);
		if (!ok || !data) return;
		const payload = data as {
			data?: { id: number; first_name: string; middle_name?: string | null; last_name: string; birth_date: string | null; mobile_masked?: string | null }[];
			existing_client_by_phone?: { id: number; first_name: string; middle_name?: string | null; last_name: string; birth_date: string | null } | null;
		};
		const list = Array.isArray(payload.data) ? payload.data : [];
		const existingByPhone = payload.existing_client_by_phone ?? null;
		acceptExistingClientByPhone = existingByPhone;
		// Ensure existing client by phone is in the list so selection works; prepend if not already present.
		if (existingByPhone && !list.some((c) => c.id === existingByPhone.id)) {
			acceptPossibleMatches = [{ ...existingByPhone }, ...list];
		} else {
			acceptPossibleMatches = list;
		}
		if (existingByPhone) {
			acceptChosenClientId = existingByPhone.id;
			acceptCreateNew = false;
		}
	}

	async function runAcceptLinkSearch(e?: SubmitEvent) {
		e?.preventDefault();
		const name = acceptLinkSearchName.trim();
		if (!name) return;
		acceptLinkSearching = true;
		try {
			const params = new URLSearchParams({ name, per_page: '10', page: '1' });
			if (effectiveProgram?.id != null) params.set('program_id', String(effectiveProgram.id));
			const bd = acceptLinkSearchBirthDate.trim();
			if (bd) params.set('birth_date', bd);
			const { ok, data } = await api('GET', `/api/clients/search?${params.toString()}`);
			if (ok && data) {
				const payload = data as { data?: { id: number; first_name: string; middle_name?: string | null; last_name: string; birth_date: string | null; mobile_masked?: string | null }[] };
				acceptPossibleMatches = payload.data ?? [];
			} else {
				acceptPossibleMatches = [];
			}
		} finally {
			acceptLinkSearching = false;
		}
	}

	async function runAcceptPhoneSearch(e?: SubmitEvent) {
		e?.preventDefault();
		const mobile = acceptLinkSearchPhone.trim();
		if (!mobile) return;
		acceptPhoneSearching = true;
		try {
			const body: Record<string, string | number> = { mobile };
			if (effectiveProgram?.id != null) body.program_id = effectiveProgram.id;
			const { ok, data } = await api('POST', '/api/clients/search-by-phone', body);
			if (ok && data) {
				const payload = data as { match_status?: string; client?: { id: number; first_name: string; middle_name?: string | null; last_name: string; birth_date: string | null; mobile_masked?: string | null } };
				if (payload.match_status === 'existing' && payload.client) {
					acceptPossibleMatches = [payload.client];
					acceptChosenClientId = payload.client.id;
					acceptCreateNew = false;
					acceptVerifyFirstName = payload.client.first_name ?? '';
					acceptVerifyLastName = payload.client.last_name ?? '';
					acceptVerifyBirthDate = payload.client.birth_date ?? '';
				} else {
					acceptPossibleMatches = [];
					acceptChosenClientId = null;
					toaster.warning({ title: 'No client found with that phone number.' });
				}
			} else {
				acceptPossibleMatches = [];
				acceptChosenClientId = null;
				toaster.warning({ title: 'No client found with that phone number.' });
			}
		} finally {
			acceptPhoneSearching = false;
		}
	}

	const acceptCanSubmit = $derived(
		acceptVerifyFirstName.trim() !== '' &&
		acceptVerifyLastName.trim() !== '' &&
		acceptVerifyBirthDate.trim() !== '' &&
		(acceptChosenClientId !== null || acceptCreateNew)
	);

	const acceptSelectedClient = $derived(
		!acceptCreateNew && acceptChosenClientId !== null
			? (acceptPossibleMatches.find((c) => c.id === acceptChosenClientId) ?? null)
			: null
	);

	async function submitAccept() {
		if (!acceptRegModalReg || !acceptCanSubmit || acceptSubmitting) return;
		acceptSubmitting = true;
		const body: Record<string, unknown> = {
			first_name: acceptVerifyFirstName.trim(),
			last_name: acceptVerifyLastName.trim(),
			birth_date: acceptVerifyBirthDate.trim(),
			client_category: acceptVerifyCategory,
			create_new_client: acceptCreateNew,
		};
		if (!acceptCreateNew && acceptChosenClientId) body.client_id = acceptChosenClientId;
		const { ok, message } = await api('POST', `/api/identity-registrations/${acceptRegModalReg.id}/accept`, body);
		acceptSubmitting = false;
		if (ok) {
			const selectedClient = !acceptCreateNew && acceptChosenClientId !== null
				? acceptPossibleMatches.find((c) => c.id === acceptChosenClientId) ?? null
				: null;
			const edited = selectedClient
				? (acceptVerifyFirstName.trim() !== (selectedClient.first_name ?? '') || acceptVerifyLastName.trim() !== (selectedClient.last_name ?? '') || acceptVerifyBirthDate.trim() !== (selectedClient.birth_date ?? '').trim())
				: false;
			if (selectedClient) {
				toaster.success({ title: edited ? 'Edited and verified client.' : 'Verified.' });
			} else {
				toaster.success({ title: 'Registration accepted.' });
			}
			closeAcceptModal();
			router.reload();
		} else {
			toaster.error({ title: (message as string) ?? 'Accept failed.' });
		}
	}

	async function submitReject(regId: number) {
		const { ok } = await api('POST', `/api/identity-registrations/${regId}/reject`, {});
		if (ok) {
			toaster.success({ title: 'Registration rejected.' });
			router.reload();
		}
	}

	/** FLOW A: staff confirms bind_confirmation hold → session created. */
	let confirmBindRegId = $state<number | null>(null);
	async function submitConfirmBind(regId: number) {
		if (confirmBindRegId !== null) return;
		confirmBindRegId = regId;
		const { ok, message } = await api('POST', `/api/identity-registrations/${regId}/confirm-bind`, {});
		confirmBindRegId = null;
		if (ok) {
			toaster.success({ title: 'Visit started.' });
			router.reload();
		} else {
			toaster.error({ title: (message as string) ?? 'Confirm bind failed.' });
		}
	}

	async function submitAcceptReveal() {
		if (!acceptRegModalReg || !acceptRevealReason.trim() || acceptRevealSubmitting) return;
		acceptRevealSubmitting = true;
		acceptRevealResult = null;
		const { ok, data, message } = await api('POST', `/api/identity-registrations/${acceptRegModalReg.id}/reveal-phone`, { reason: acceptRevealReason.trim() });
		acceptRevealSubmitting = false;
		if (ok && data && typeof (data as { mobile?: string }).mobile === 'string') {
			acceptRevealResult = (data as { mobile: string }).mobile;
		} else {
			toaster.error({ title: (message as string) ?? 'Reveal failed.' });
		}
	}

	// Read highlight_session_id from URL (e.g. from station unverified badge click)
	$effect(() => {
		if (typeof window === 'undefined') return;
		const url = new URL(window.location.href);
		const id = url.searchParams.get('highlight_session_id');
		highlightSessionId = id ? Number(id) : null;

		// Make highlight one-shot per redirect: strip param from URL so refresh doesn't re-highlight.
		if (id !== null) {
			url.searchParams.delete('highlight_session_id');
			window.history.replaceState(window.history.state, '', url.toString());
		}
	});

	// Auto-clear highlight a short time after it is applied so it doesn't stay permanently.
	$effect(() => {
		if (highlightSessionId == null) return;
		const timeoutId = setTimeout(() => {
			highlightSessionId = null;
		}, 2000);
		return () => {
			clearTimeout(timeoutId);
		};
	});
</script>

<svelte:head>
	<title>Client registration — FlexiQueue</title>
</svelte:head>

<MobileLayout headerTitle="Client registration" {queueCount} {processedToday}>
	<div class="flex flex-col gap-4 md:gap-6 text-surface-950 w-full max-w-2xl mx-auto px-4 md:px-6 py-4 md:py-6">
		{#if !effectiveProgram}
			<div class="rounded-container bg-surface-50 border border-surface-200 elevation-card p-6 md:p-8 text-center text-surface-950/80">
				<p class="font-medium">No active program</p>
				<p class="mt-2 text-sm">Activate a program from Admin → Programs.</p>
			</div>
		{:else}
			<div>
				<h1 class="text-xl md:text-2xl font-semibold text-surface-950">Client registration</h1>
				<p class="mt-1 text-sm text-surface-600">
					Verify or create registrations here. Guests scan tokens and start visits on the kiosk.
				</p>
			</div>

			{#if pending_identity_registrations?.length > 0}
				<section class="rounded-container bg-surface-50 elevation-card p-4" data-testid="identity-registrations-section">
					<div class="flex items-center justify-between gap-2 mb-2">
						<h2 class="text-sm font-semibold text-surface-950">Identity registrations</h2>
						<button
							type="button"
							class="btn preset-tonal text-sm touch-target-h flex items-center gap-2"
							onclick={() => (showNewRegModal = true)}
							data-testid="client-registration-new-button"
						>
							<Plus class="w-4 h-4" />
							New client registration
						</button>
					</div>
					<ul class="space-y-2">
						{#each pending_identity_registrations as reg (reg.id)}
							<li
								class="flex flex-wrap items-center justify-between gap-2 py-2 border-b border-surface-200 last:border-b-0 {highlightSessionId === reg.session_id ? 'client-registration-row-highlight' : ''}"
								data-testid="identity-registration-row-{reg.id}"
							>
								<div class="min-w-0">
									<p class="text-sm font-medium text-surface-900 truncate flex items-center gap-2">
										{clientDisplayName(reg)}
										{#if reg.request_type === 'bind_confirmation'}
											<span class="badge badge-sm badge-tonal-primary" data-testid="identity-registration-bind-confirmation-badge">Verify & start</span>
										{/if}
										{#if reg.id_verified_at}
											<span class="badge badge-sm badge-filled-primary-500" data-testid="identity-registration-verified-badge">Verified</span>
										{/if}
									</p>
									<p class="text-xs text-surface-600">
										Birth date: {reg.birth_date ?? '—'} · Category: {reg.client_category ?? '—'}
										{#if reg.mobile_masked}
											· {reg.mobile_masked}
										{/if}
										{#if reg.request_type === 'bind_confirmation' && (reg.token_physical_id || reg.track_name || reg.client_name)}
											· Token: {reg.token_physical_id ?? '—'} · Track: {reg.track_name ?? '—'} · Client: {reg.client_name ?? '—'}
										{/if}
										{#if reg.session_alias}
											· Session: {reg.session_alias}
										{/if}
									</p>
								</div>
								<div class="flex gap-2 shrink-0">
									{#if reg.request_type === 'bind_confirmation'}
										<button
											type="button"
											class="btn preset-filled-primary-500 text-sm touch-target-h"
											data-testid="identity-registration-confirm-bind-{reg.id}"
											disabled={confirmBindRegId === reg.id}
											onclick={() => submitConfirmBind(reg.id)}
										>
											{confirmBindRegId === reg.id ? 'Starting…' : 'Confirm Bind'}
										</button>
									{:else}
										<button
											type="button"
											class="btn preset-filled-primary-500 text-sm touch-target-h"
											data-testid="identity-registration-verify-{reg.id}"
											onclick={() => openAcceptModal(reg)}
										>
											Verify
										</button>
									{/if}
									<button
										type="button"
										class="btn preset-tonal text-sm touch-target-h"
										data-testid="identity-registration-reject-{reg.id}"
										onclick={() => submitReject(reg.id)}
									>
										Reject
									</button>
								</div>
							</li>
						{/each}
					</ul>
				</section>
			{:else}
				<section class="rounded-container bg-surface-50 elevation-card p-4" data-testid="identity-registrations-section-empty">
					<div class="flex items-center justify-between gap-2">
						<div class="min-w-0">
							<h2 class="text-sm font-semibold text-surface-950">Identity registrations</h2>
							<p class="text-xs text-surface-600">No pending registration requests.</p>
						</div>
						<button
							type="button"
							class="btn preset-tonal text-sm touch-target-h flex items-center gap-2"
							onclick={() => (showNewRegModal = true)}
							data-testid="client-registration-new-button"
						>
							<Plus class="w-4 h-4" />
							New registration
						</button>
					</div>
				</section>
			{/if}

			<CreateRegistrationModal
				open={showNewRegModal}
				onClose={() => (showNewRegModal = false)}
				onSubmitSuccess={() => {
					toaster.success({ title: 'Registration created.' });
					router.reload();
				}}
				programId={effectiveProgram?.id ?? null}
				submitRequest={submitNewRegistrationRequest}
			/>
		{/if}

		<Modal
			open={acceptRegModalReg !== null}
			title="Verify identity registration"
			onClose={closeAcceptModal}
			wide={true}
		>
			{#snippet children()}
				{#if acceptRegModalReg}
					<div class="space-y-4" data-testid="accept-registration-form">
						<p class="text-sm text-surface-700">
							Verify details, then choose an existing client to verify (or update their details) or create a new one.
						</p>
						{#if acceptRegModalReg.mobile_masked}
							<p class="text-xs text-surface-600">Phone: {acceptRegModalReg.mobile_masked}</p>
						{/if}
						{#if acceptExistingClientByPhone}
							<div class="rounded-container border border-primary-200 bg-primary-50 p-3 text-sm text-primary-900" data-testid="accept-existing-client-by-phone-banner">
								<p class="font-medium">This phone is already registered to a client.</p>
								<p class="text-xs mt-1">Verify that client below; you can reveal phone for verification or update their details.</p>
							</div>
						{/if}
						{#if acceptSelectedClient}
							<div class="rounded-container border border-primary-200 bg-primary-50/50 p-3 space-y-1">
								<p class="text-xs font-medium text-surface-700">Verify this client; edit details below if needed.</p>
								{#if acceptSelectedClient.mobile_masked}
									<p class="text-xs text-surface-600">Client phone: {acceptSelectedClient.mobile_masked}</p>
								{/if}
							</div>
						{/if}
						<label class="flex flex-col gap-1 text-sm">
							<span class="font-medium text-surface-800">First name (required)</span>
							<input type="text" class="input rounded-container border border-surface-200 px-3 py-2" bind:value={acceptVerifyFirstName} placeholder="First name" />
						</label>
						<label class="flex flex-col gap-1 text-sm">
							<span class="font-medium text-surface-800">Last name (required)</span>
							<input type="text" class="input rounded-container border border-surface-200 px-3 py-2" bind:value={acceptVerifyLastName} placeholder="Last name" />
						</label>
						<label class="flex flex-col gap-1 text-sm">
							<span class="font-medium text-surface-800">Birth date (required)</span>
							<input type="date" class="input rounded-container border border-surface-200 px-3 py-2" bind:value={acceptVerifyBirthDate} />
						</label>
						<label class="flex flex-col gap-1 text-sm">
							<span class="font-medium text-surface-800">Classification (required)</span>
							<select class="select select-theme input rounded-container border border-surface-200 px-3 py-2" bind:value={acceptVerifyCategory}>
								{#each ACCEPT_CATEGORIES as cat (cat.value)}
									<option value={cat.value}>{cat.label}</option>
								{/each}
							</select>
						</label>
						<div class="rounded-container border border-surface-200 bg-surface-50 p-3 space-y-3">
							<p class="text-sm font-medium text-surface-800">Client</p>
							<form class="space-y-2" onsubmit={runAcceptPhoneSearch}>
								<label for="accept-link-search-phone" class="label-text text-xs font-semibold uppercase tracking-wide text-surface-500 block">Search by phone</label>
								<div class="join w-full">
									<div class="join-item flex items-center gap-2 px-3 py-1 border border-surface-300 rounded-l-container bg-surface-50 w-full">
										<Search class="w-4 h-4 my-2 text-surface-400 shrink-0" />
										<input
											type="tel"
											inputmode="numeric"
											id="accept-link-search-phone"
											class="input input-ghost !bg-transparent px-0 py-0 h-auto text-sm w-full focus:!outline-none focus:!ring-0 focus:!border-transparent"
											bind:value={acceptLinkSearchPhone}
											placeholder="e.g. 09171234567"
											data-testid="accept-link-search-phone"
										/>
									</div>
									<button
										type="submit"
										class="join-item btn preset-filled-primary-500 px-4 text-sm shadow-sm !rounded-none !rounded-tr-lg !rounded-br-lg"
										disabled={acceptPhoneSearching || !acceptLinkSearchPhone.trim()}
										data-testid="accept-link-search-phone-button"
									>
										{acceptPhoneSearching ? 'Searching…' : 'Search'}
									</button>
								</div>
							</form>
							<form class="space-y-2" onsubmit={runAcceptLinkSearch}>
								<label for="accept-link-search-name" class="label-text text-xs font-semibold uppercase tracking-wide text-surface-500 block">Search by name</label>
								<div class="join w-full">
									<div class="join-item flex items-center gap-2 px-3 py-1 border border-surface-300 rounded-l-container bg-surface-50 w-full">
										<Search class="w-4 h-4 my-2 text-surface-400 shrink-0" />
										<input
											type="text"
											id="accept-link-search-name"
											class="input input-ghost !bg-transparent px-0 py-0 h-auto text-sm w-full focus:!outline-none focus:!ring-0 focus:!border-transparent"
											bind:value={acceptLinkSearchName}
											placeholder="e.g. Maria Santos"
											data-testid="accept-link-search-name"
										/>
									</div>
									<button
										type="submit"
										class="join-item btn preset-filled-primary-500 px-4 text-sm shadow-sm !rounded-none !rounded-tr-lg !rounded-br-lg"
										disabled={acceptLinkSearching || !acceptLinkSearchName.trim()}
										data-testid="accept-link-search-button"
									>
										{acceptLinkSearching ? 'Searching…' : 'Search'}
									</button>
								</div>
								<label class="flex flex-col gap-1 text-xs">
									<span class="text-surface-700">Birth date (optional)</span>
									<input type="date" class="input rounded-container border border-surface-200 px-3 py-2 text-sm w-full" bind:value={acceptLinkSearchBirthDate} data-testid="accept-link-search-birth-date" />
								</label>
							</form>
							{#if acceptPossibleMatches.length > 0}
								<p class="text-xs text-surface-600">Existing clients</p>
								<ul class="space-y-1 mb-2">
									{#each acceptPossibleMatches as client (client.id)}
										<li>
											<button
												type="button"
												class="btn preset-tonal text-sm w-full text-left justify-start py-3 {acceptChosenClientId === client.id && !acceptCreateNew ? 'ring-2 ring-primary-500' : ''}"
												onclick={() => {
													acceptChosenClientId = client.id;
													acceptCreateNew = false;
													acceptVerifyFirstName = client.first_name ?? '';
													acceptVerifyLastName = client.last_name ?? '';
													acceptVerifyBirthDate = client.birth_date ?? '';
												}}
											>
												<div class="flex flex-col min-w-0">
													<span class="truncate">{clientDisplayName(client)}</span>
													<span class="text-xs text-surface-600">
														{client.birth_date ?? '—'}{#if client.mobile_masked} · {client.mobile_masked}{/if}
													</span>
												</div>
											</button>
										</li>
									{/each}
								</ul>
							{:else}
								<p class="text-xs text-surface-600">No matches found. Create a new client.</p>
							{/if}
							<button
								type="button"
								class="btn preset-tonal text-sm w-full justify-start {acceptCreateNew ? 'ring-2 ring-primary-500' : ''}"
								onclick={() => { acceptCreateNew = true; acceptChosenClientId = null; }}
							>
								Create new client
							</button>
						</div>
						{#if acceptRegModalReg?.mobile_masked}
							<div class="border-t border-surface-200 pt-3">
								<p class="text-xs font-medium text-surface-600 mb-2">Phone on file</p>
								<p class="text-sm text-surface-700">{acceptRegModalReg.mobile_masked}</p>
								<button
									type="button"
									class="btn preset-tonal text-sm mt-2"
									onclick={() => (showAcceptRevealModal = true)}
									data-testid="accept-reveal-phone-btn"
								>
									Reveal phone
								</button>
								{#if acceptRegModalReg?.id_verified_at}
									<p class="text-xs text-surface-600 mt-1">Verified{acceptRegModalReg?.id_verified_by ? ` by ${acceptRegModalReg.id_verified_by}` : ''}{acceptRegModalReg?.id_verified_at ? ` on ${new Date(acceptRegModalReg.id_verified_at).toLocaleString()}` : ''}.</p>
								{/if}
							</div>
						{/if}
						<div class="flex gap-2 pt-2">
							<button type="button" class="btn preset-tonal flex-1" onclick={closeAcceptModal}>Cancel</button>
							<button
								type="button"
								class="btn preset-filled-primary-500 flex-1"
								disabled={!acceptCanSubmit || acceptSubmitting}
								onclick={submitAccept}
								data-testid="accept-registration-submit"
							>
								{#if acceptSubmitting}
									Submitting…
								{:else if acceptSelectedClient}
									Verify existing client
								{:else if acceptCreateNew}
									Create new client and accept
								{:else}
									Submit registration
								{/if}
							</button>
						</div>
					</div>
				{/if}
			{/snippet}
		</Modal>

		<!-- Reveal phone (from Accept modal) -->
		<Modal
			open={showAcceptRevealModal}
			title="Reveal phone"
			onClose={() => { showAcceptRevealModal = false; acceptRevealResult = null; acceptRevealReason = ''; }}
		>
			{#snippet children()}
				{#if acceptRevealResult !== null}
					<div class="space-y-3">
						<p class="text-sm text-surface-700">Phone (for verification only):</p>
						<p class="font-mono text-lg font-medium text-surface-950">{acceptRevealResult}</p>
						<button type="button" class="btn preset-tonal w-full" onclick={() => { showAcceptRevealModal = false; acceptRevealResult = null; acceptRevealReason = ''; }}>Close</button>
					</div>
				{:else}
					<form class="space-y-3" onsubmit={(e) => { e.preventDefault(); submitAcceptReveal(); }}>
						<label class="block">
							<span class="text-sm font-medium text-surface-800">Reason (required)</span>
							<textarea
								class="input textarea w-full mt-1 rounded-container border border-surface-200 px-3 py-2 text-sm"
								rows="2"
								placeholder="e.g. Verification before accept"
								bind:value={acceptRevealReason}
								disabled={acceptRevealSubmitting}
							></textarea>
						</label>
						<div class="flex gap-2 justify-end">
							<button type="button" class="btn preset-tonal" onclick={() => { showAcceptRevealModal = false; acceptRevealReason = ''; }}>Cancel</button>
							<button type="submit" class="btn preset-filled-primary-500" disabled={!acceptRevealReason.trim() || acceptRevealSubmitting}>
								{acceptRevealSubmitting ? 'Revealing…' : 'Reveal'}
							</button>
						</div>
					</form>
				{/if}
			{/snippet}
		</Modal>
	</div>
</MobileLayout>
