<script lang="ts">
	/**
	 * Shared bind UI: token + track + optional client binder + confirm (device refactor Phase 1).
	 * Used from Triage/Index and staff footer QR triage modal.
	 */
	import TriageClientBinder, {
		type BindingMode as BinderBindingMode,
		type BinderStatus as BinderComponentStatus,
		type ClientBindingPayload,
	} from './TriageClientBinder.svelte';
	import { toaster } from '../lib/toaster.js';

	interface Track {
		id: number;
		name: string;
		color_code: string | null;
		is_default: boolean;
	}

	interface Program {
		id: number;
		name: string;
		is_active?: boolean;
		is_paused?: boolean;
		tracks: Track[];
		/** Effective mode (incl. edge offline → optional). */
		identity_binding_mode?: 'disabled' | 'required' | 'optional';
		/** From triage-bind-context API: when true, show category + Other (free text) when identity is not strictly required. */
		show_staff_client_category?: boolean;
		allow_unverified_entry?: boolean;
	}

	let {
		program = null,
		token,
		effectiveHid = true,
		effectiveCamera = true,
		getCsrfToken,
		onCancel,
		onBound,
	}: {
		program: Program | null;
		token: { physical_id: string; qr_hash: string; status: string };
		effectiveHid?: boolean;
		effectiveCamera?: boolean;
		getCsrfToken: () => string;
		onCancel: () => void;
		onBound: () => void;
	} = $props();

	const MAX_TRACKS_FOR_BUTTONS = 4;
	const showTrackButtons = $derived((program?.tracks?.length ?? 0) <= MAX_TRACKS_FOR_BUTTONS);

	/** Max length matches DB `client_category` (50); "Other: " prefix uses 7 chars. */
	const OTHER_PREFIX = 'Other: ';
	const MAX_CLIENT_CATEGORY_LEN = 50;
	const MAX_OTHER_DETAIL_LEN = MAX_CLIENT_CATEGORY_LEN - OTHER_PREFIX.length;

	type CategoryPreset = 'regular' | 'pwd' | 'other';

	/**
	 * When identity binding is required, category is driven by registration / binder; keep default Regular.
	 * When disabled or optional (e.g. edge), staff must set queue priority explicitly.
	 */
	const showClientCategoryPicker = $derived(
		program != null &&
			(typeof program.show_staff_client_category === 'boolean'
				? program.show_staff_client_category
				: (program.identity_binding_mode ?? 'disabled') !== 'required'),
	);

	let categoryPreset = $state<CategoryPreset>('regular');
	let otherDetail = $state('');
	/** When category is Other, optionally use priority lane for queue ordering (bind-time only). */
	let otherTreatAsPriority = $state(false);
	let selectedTrackId = $state<number | null>(null);

	const isOtherCategoryInvalid = $derived(
		showClientCategoryPicker &&
			categoryPreset === 'other' &&
			(!otherDetail.trim() ||
				`${OTHER_PREFIX}${otherDetail.trim()}`.length > MAX_CLIENT_CATEGORY_LEN),
	);

	$effect(() => {
		void token?.qr_hash;
		categoryPreset = 'regular';
		otherDetail = '';
		otherTreatAsPriority = false;
	});

	let isSubmitting = $state(false);

	let bindingMode = $derived<BinderBindingMode>(
		(program?.identity_binding_mode as BinderBindingMode | undefined) ?? 'disabled',
	);
	let clientBinding = $state<ClientBindingPayload | null>(null);
	let binderStatus = $state<BinderComponentStatus>('idle');

	const MSG_SESSION_EXPIRED = 'Session expired. Please refresh and try again.';
	const MSG_NETWORK_ERROR = 'Network error. Please try again.';

	function setDefaultTrack() {
		if (program?.tracks?.length) {
			const def = program.tracks.find((t) => t.is_default);
			selectedTrackId = def?.id ?? program.tracks[0]?.id ?? null;
		}
	}

	$effect(() => {
		if (program?.tracks?.length && selectedTrackId === null) {
			setDefaultTrack();
		}
	});

	async function api(
		method: string,
		url: string,
		body?: object,
	): Promise<{ ok: boolean; data?: object; message?: string; errors?: Record<string, string[]> }> {
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
		} catch {
			toaster.error({ title: MSG_NETWORK_ERROR });
			return { ok: false, message: MSG_NETWORK_ERROR };
		}
	}

	function resolveClientCategoryForBind(): string | null {
		if (!showClientCategoryPicker) {
			return 'Regular';
		}
		if (categoryPreset === 'regular') {
			return 'Regular';
		}
		if (categoryPreset === 'pwd') {
			return 'PWD / Senior / Pregnant';
		}
		const detail = otherDetail.trim();
		if (!detail) {
			return null;
		}
		const combined = `${OTHER_PREFIX}${detail}`;
		if (combined.length > MAX_CLIENT_CATEGORY_LEN) {
			return null;
		}
		return combined;
	}

	async function handleConfirm() {
		if (!token || selectedTrackId === null) return;
		if (
			bindingMode === 'required' &&
			binderStatus !== 'bound' &&
			!(binderStatus === 'binding_ready' && clientBinding)
		) {
			toaster.error({
				title: 'Client identity binding is required before completing triage.',
			});
			return;
		}
		const clientCategory = resolveClientCategoryForBind();
		if (clientCategory === null) {
			toaster.error({
				title: 'Enter a short description for “Other” (required, up to 43 characters).',
			});
			return;
		}
		isSubmitting = true;
		const payload: Record<string, unknown> = {
			qr_hash: token.qr_hash,
			track_id: Number(selectedTrackId),
			client_category: clientCategory,
		};
		if (program?.id != null) {
			payload.program_id = program.id;
		}
		if (clientBinding) {
			payload.client_binding = clientBinding;
		}

		const { ok, data, message } = await api('POST', '/api/sessions/bind', payload);
		isSubmitting = false;
		if (ok) {
			onBound();
			return;
		}
		const d = data as
			| {
					active_session?: { alias: string };
					token_status?: string;
					error_code?: string;
					message?: string;
			  }
			| undefined;
		if (d?.error_code === 'client_already_queued' && d?.active_session) {
			toaster.error({ title: `Client already has an active visit (Token ${d.active_session.alias}).` });
		} else if (d?.active_session) {
			toaster.error({ title: `Token already in use (${d.active_session.alias}).` });
		} else if (d?.token_status) {
			toaster.error({ title: `Token is marked as ${d.token_status}.` });
		} else {
			toaster.error({ title: (d?.message ?? message) ?? 'Bind failed.' });
		}
	}
</script>

<div
	class="rounded-container border border-surface-200 bg-surface-50 elevation-card p-4 md:p-6 space-y-4"
	data-testid="staff-triage-bind-panel"
>
	<p class="font-medium text-surface-950">
		Token: <span class="font-mono text-primary-500">{token.physical_id}</span>
	</p>

	<div>
		<p class="text-sm font-medium text-surface-950 mb-2">Track</p>
		{#if showTrackButtons}
			<div class="flex flex-wrap gap-2">
				{#each program?.tracks ?? [] as track (track.id)}
					<button
						type="button"
						class="btn touch-target-h px-4 py-2 {selectedTrackId === track.id
							? 'preset-filled-primary-500'
							: 'preset-tonal'}"
						onclick={() => (selectedTrackId = track.id)}
					>
						{track.name}
					</button>
				{/each}
			</div>
		{:else}
			<select
				id="staff-triage-track"
				class="select select-theme w-full rounded-container border border-surface-200 px-3 py-2 touch-target-h"
				bind:value={selectedTrackId}
			>
				{#each program?.tracks ?? [] as track (track.id)}
					<option value={track.id}>{track.name}</option>
				{/each}
			</select>
		{/if}
	</div>

	{#if showClientCategoryPicker}
		<div class="border-t border-surface-200 pt-4" data-testid="staff-triage-client-category">
			<p class="text-sm font-medium text-surface-950 mb-2">Client category</p>
			<p class="text-xs text-surface-600 mb-3">
				PWD / senior / pregnant uses the priority lane. Regular and Other default to the non-priority lane unless you
				check the option below for Other.
			</p>
			<div class="flex flex-wrap gap-2">
				<button
					type="button"
					class="btn touch-target-h px-3 py-2 text-sm {categoryPreset === 'regular'
						? 'preset-filled-primary-500'
						: 'preset-tonal'}"
					onclick={() => {
						categoryPreset = 'regular';
					}}
				>
					Regular
				</button>
				<button
					type="button"
					class="btn touch-target-h px-3 py-2 text-sm {categoryPreset === 'pwd'
						? 'preset-filled-primary-500'
						: 'preset-tonal'}"
					onclick={() => {
						categoryPreset = 'pwd';
					}}
				>
					PWD / Senior / Pregnant
				</button>
				<button
					type="button"
					class="btn touch-target-h px-3 py-2 text-sm {categoryPreset === 'other'
						? 'preset-filled-primary-500'
						: 'preset-tonal'}"
					onclick={() => {
						categoryPreset = 'other';
					}}
				>
					Other
				</button>
			</div>
			{#if categoryPreset === 'other'}
				<div class="mt-3 space-y-1">
					<label for="staff-triage-other-detail" class="text-sm font-medium text-surface-950">
						Describe “Other” <span class="text-error-600">*</span>
					</label>
					<input
						id="staff-triage-other-detail"
						type="text"
						class="input w-full rounded-container border border-surface-200 px-3 py-2 text-sm"
						placeholder="e.g. walk-in referral, agency partner"
						maxlength={MAX_OTHER_DETAIL_LEN}
						bind:value={otherDetail}
						autocomplete="off"
						data-testid="staff-triage-other-detail"
					/>
					<p class="text-xs text-surface-500">
						Stored as “Other: …” (max {MAX_OTHER_DETAIL_LEN} characters).
					</p>
					<label class="flex items-start gap-2 mt-3 cursor-pointer touch-target-h">
						<input
							type="checkbox"
							class="checkbox mt-0.5"
							bind:checked={otherTreatAsPriority}
							data-testid="staff-triage-other-priority"
						/>
						<span class="text-sm text-surface-950/90">Treat as priority lane</span>
					</label>
				</div>
			{/if}
		</div>
	{/if}

	{#if bindingMode !== 'disabled'}
		<div class="border-t border-surface-200 pt-4" data-testid="staff-triage-client-binder-wrapper">
			<TriageClientBinder
				bindingMode={bindingMode}
				programId={program?.id ?? null}
				allowHid={effectiveHid}
				allowCamera={effectiveCamera}
				onBindingChange={({ status, client_binding }) => {
					binderStatus = status;
					clientBinding = client_binding;
				}}
			/>
		</div>
	{/if}

	<div class="flex gap-2 pt-2">
		<button type="button" class="btn preset-tonal flex-1 touch-target-h" onclick={onCancel} disabled={isSubmitting}>
			Cancel
		</button>
		<button
			type="button"
			class="btn preset-filled-primary-500 flex-1 touch-target-h"
			data-testid="staff-triage-bind-confirm"
			onclick={handleConfirm}
			disabled={
				isSubmitting ||
				selectedTrackId === null ||
				isOtherCategoryInvalid ||
				(bindingMode === 'required' &&
					binderStatus !== 'bound' &&
					!(binderStatus === 'binding_ready' && clientBinding))
			}
		>
			{isSubmitting ? 'Binding…' : 'Confirm'}
		</button>
	</div>
</div>
