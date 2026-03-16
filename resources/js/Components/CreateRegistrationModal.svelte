<script lang="ts">
	import Modal from "./Modal.svelte";

	export interface CreateRegistrationPayload {
		program_id?: number;
		first_name: string;
		middle_name?: string | null;
		last_name: string;
		birth_date: string;
		client_category: string;
		mobile?: string | null;
		address_line_1?: string | null;
		address_line_2?: string | null;
		city?: string | null;
		state?: string | null;
		postal_code?: string | null;
		country?: string | null;
	}

	const CATEGORIES = [
		{ label: "Regular", value: "Regular" },
		{ label: "PWD / Senior / Pregnant", value: "PWD / Senior / Pregnant" },
		{ label: "Incomplete Documents", value: "Incomplete Documents" },
	] as const;

	let {
		open = false,
		onClose = () => {},
		onSubmitSuccess = () => {},
		/** When set, used as program_id and program selector is hidden. */
		programId = undefined as number | null | undefined,
		/** When programId is not set and this is provided, show program dropdown (required). */
		programs = [] as { id: number; name: string }[],
		/** Parent must POST to API and return { ok, message? }. */
		submitRequest,
		/** Optional short description shown above the form. */
		description = "Create a client registration directly. The registration is created immediately; no Accept/Reject step. Use when a client has no token yet and you are entering their details.",
	}: {
		open?: boolean;
		onClose?: () => void;
		onSubmitSuccess?: () => void;
		programId?: number | null;
		programs?: { id: number; name: string }[];
		submitRequest: (payload: CreateRegistrationPayload) => Promise<{ ok: boolean; message?: string }>;
		description?: string;
	} = $props();

	let first = $state("");
	let middle = $state("");
	let last = $state("");
	let birthDate = $state("");
	let category = $state("Regular");
	let mobile = $state("");
	let address1 = $state("");
	let address2 = $state("");
	let city = $state("");
	let stateVal = $state("");
	let postalCode = $state("");
	let country = $state("");
	let selectedProgramId = $state<string | null>(null);
	let submitting = $state(false);
	let errorMessage = $state<string | null>(null);

	$effect(() => {
		if (open) {
			first = "";
			middle = "";
			last = "";
			birthDate = "";
			category = "Regular";
			mobile = "";
			address1 = "";
			address2 = "";
			city = "";
			stateVal = "";
			postalCode = "";
			country = "";
			selectedProgramId = programs.length === 1 ? String(programs[0].id) : null;
			submitting = false;
			errorMessage = null;
		}
	});

	async function submit() {
		const f = first.trim();
		const l = last.trim();
		const bd = birthDate.trim();
		if (!f || !l || !bd) {
			errorMessage = "First name, last name, and birth date are required.";
			return;
		}
		const pid = programId ?? (selectedProgramId != null && selectedProgramId !== "" ? Number(selectedProgramId) : null);
		if (pid == null && programs.length > 0) {
			errorMessage = "Please select a program.";
			return;
		}
		submitting = true;
		errorMessage = null;
		const payload: CreateRegistrationPayload = {
			...(pid != null ? { program_id: pid } : {}),
			first_name: f,
			middle_name: middle.trim() || undefined,
			last_name: l,
			birth_date: bd,
			client_category: category || "Regular",
			...(mobile.trim() ? { mobile: mobile.trim() } : {}),
			...(address1.trim() ? { address_line_1: address1.trim() } : {}),
			...(address2.trim() ? { address_line_2: address2.trim() } : {}),
			...(city.trim() ? { city: city.trim() } : {}),
			...(stateVal.trim() ? { state: stateVal.trim() } : {}),
			...(postalCode.trim() ? { postal_code: postalCode.trim() } : {}),
			...(country.trim() ? { country: country.trim() } : {}),
		};
		const result = await submitRequest(payload);
		submitting = false;
		if (result.ok) {
			onClose();
			onSubmitSuccess();
			return;
		}
		errorMessage = result.message ?? "Could not create registration.";
	}
</script>

<Modal {open} title="New client registration" onClose={onClose}>
	{#snippet children()}
		<div class="space-y-4 max-h-[70vh] overflow-y-auto" data-testid="triage-new-registration-modal">
			<p class="text-sm text-surface-700">
				{description}
			</p>

			{#if programId == null && programs.length > 0}
				<div class="form-control">
					<label class="label py-0" for="create-reg-program">
						<span class="label-text text-sm font-medium">Program (required)</span>
					</label>
					<select
						id="create-reg-program"
						class="select select-theme input rounded-container border border-surface-200 px-3 py-2 w-full bg-surface-50 text-sm"
						bind:value={selectedProgramId}
						disabled={submitting}
					>
						<option value="">Select a program…</option>
						{#each programs as p (p.id)}
							<option value={String(p.id)}>{p.name}</option>
						{/each}
					</select>
				</div>
			{/if}

			<div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
				<div class="form-control">
					<label class="label py-0" for="create-reg-first"><span class="label-text text-xs font-medium">First name *</span></label>
					<input id="create-reg-first" type="text" class="input input-sm rounded-container border border-surface-200 w-full bg-surface-50" bind:value={first} placeholder="First name" disabled={submitting} />
				</div>
				<div class="form-control">
					<label class="label py-0" for="create-reg-middle"><span class="label-text text-xs font-medium">Middle name</span></label>
					<input id="create-reg-middle" type="text" class="input input-sm rounded-container border border-surface-200 w-full bg-surface-50" bind:value={middle} placeholder="Middle" disabled={submitting} />
				</div>
				<div class="form-control">
					<label class="label py-0" for="create-reg-last"><span class="label-text text-xs font-medium">Last name *</span></label>
					<input id="create-reg-last" type="text" class="input input-sm rounded-container border border-surface-200 w-full bg-surface-50" bind:value={last} placeholder="Last name" disabled={submitting} />
				</div>
			</div>
			<div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
				<div class="form-control">
					<label class="label py-0" for="create-reg-birth"><span class="label-text text-xs font-medium">Birth date *</span></label>
					<input id="create-reg-birth" type="date" class="input input-sm rounded-container border border-surface-200 w-full bg-surface-50" bind:value={birthDate} disabled={submitting} />
				</div>
				<div class="form-control">
					<label class="label py-0" for="create-reg-category"><span class="label-text text-xs font-medium">Classification</span></label>
					<select
						id="create-reg-category"
						class="select select-theme input input-sm rounded-container border border-surface-200 w-full bg-surface-50"
						bind:value={category}
						disabled={submitting}
					>
						{#each CATEGORIES as cat (cat.value)}
							<option value={cat.value}>{cat.label}</option>
						{/each}
					</select>
				</div>
			</div>

			<div class="rounded-container border border-surface-200 bg-surface-50 p-3 space-y-2">
				<p class="text-sm font-medium text-surface-800">Optional phone</p>
				<input
					type="tel"
					inputmode="numeric"
					class="input input-sm rounded-container border border-surface-200 w-full bg-surface-50"
					bind:value={mobile}
					placeholder="e.g. 09171234567"
					disabled={submitting}
					data-testid="new-reg-mobile-input"
				/>
			</div>

			<div class="space-y-3">
				<p class="text-sm font-medium text-surface-800">Address (optional)</p>
				<div class="form-control">
					<label class="label py-0" for="create-reg-address1"><span class="label-text text-xs font-medium">Address line 1</span></label>
					<input id="create-reg-address1" type="text" class="input input-sm rounded-container border border-surface-200 w-full bg-surface-50" bind:value={address1} placeholder="Street, number" disabled={submitting} />
				</div>
				<div class="form-control">
					<label class="label py-0" for="create-reg-address2"><span class="label-text text-xs font-medium">Address line 2</span></label>
					<input id="create-reg-address2" type="text" class="input input-sm rounded-container border border-surface-200 w-full bg-surface-50" bind:value={address2} placeholder="Apt, building" disabled={submitting} />
				</div>
				<div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
					<div class="form-control">
						<label class="label py-0" for="create-reg-city"><span class="label-text text-xs font-medium">City</span></label>
						<input id="create-reg-city" type="text" class="input input-sm rounded-container border border-surface-200 w-full bg-surface-50" bind:value={city} placeholder="City" disabled={submitting} />
					</div>
					<div class="form-control">
						<label class="label py-0" for="create-reg-state"><span class="label-text text-xs font-medium">State / Province</span></label>
						<input id="create-reg-state" type="text" class="input input-sm rounded-container border border-surface-200 w-full bg-surface-50" bind:value={stateVal} placeholder="State" disabled={submitting} />
					</div>
					<div class="form-control">
						<label class="label py-0" for="create-reg-postal"><span class="label-text text-xs font-medium">Postal code</span></label>
						<input id="create-reg-postal" type="text" class="input input-sm rounded-container border border-surface-200 w-full bg-surface-50" bind:value={postalCode} placeholder="Postal" disabled={submitting} />
					</div>
					<div class="form-control">
						<label class="label py-0" for="create-reg-country"><span class="label-text text-xs font-medium">Country</span></label>
						<input id="create-reg-country" type="text" class="input input-sm rounded-container border border-surface-200 w-full bg-surface-50" bind:value={country} placeholder="Country" disabled={submitting} />
					</div>
				</div>
			</div>

			{#if errorMessage}
				<div class="rounded-container border border-error-200 bg-error-50 text-error-800 text-xs px-3 py-2">{errorMessage}</div>
			{/if}

			<div class="flex gap-2 pt-2">
				<button type="button" class="btn preset-tonal flex-1" onclick={onClose} disabled={submitting}>
					Cancel
				</button>
				<button
					type="button"
					class="btn preset-filled-primary-500 flex-1"
					onclick={submit}
					disabled={submitting || !first.trim() || !last.trim() || !birthDate.trim() || (programId == null && programs.length > 0 && !selectedProgramId)}
					data-testid="triage-new-registration-submit"
				>
					{submitting ? "Creating…" : "Create registration"}
				</button>
			</div>
		</div>
	{/snippet}
</Modal>
