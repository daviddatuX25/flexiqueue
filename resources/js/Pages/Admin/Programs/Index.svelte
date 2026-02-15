<script lang="ts">
	import AdminLayout from '../../../Layouts/AdminLayout.svelte';
	import Modal from '../../../Components/Modal.svelte';
	import ConfirmModal from '../../../Components/ConfirmModal.svelte';
	import { get } from 'svelte/store';
	import { Link, router, usePage } from '@inertiajs/svelte';

	interface ProgramItem {
		id: number;
		name: string;
		description: string | null;
		is_active: boolean;
		is_paused?: boolean;
		created_at: string | null;
	}

	let { programs = [] }: { programs: ProgramItem[] } = $props();

	let showCreateModal = $state(false);
	let editProgram = $state<ProgramItem | null>(null);
	let deleteConfirmProgram = $state<ProgramItem | null>(null);
	let createName = $state('');
	let createDescription = $state('');
	let editName = $state('');
	let editDescription = $state('');
	let submitting = $state(false);
	let error = $state('');
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
	): Promise<{ ok: boolean; data?: object; message?: string }> {
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

	function openCreate() {
		createName = '';
		createDescription = '';
		error = '';
		showCreateModal = true;
	}

	function openEdit(p: ProgramItem) {
		editProgram = p;
		editName = p.name;
		editDescription = p.description ?? '';
		error = '';
	}

	function closeModals() {
		showCreateModal = false;
		editProgram = null;
		deleteConfirmProgram = null;
		error = '';
	}

	async function handleCreate() {
		if (!createName.trim()) return;
		submitting = true;
		error = '';
		const { ok, message } = await api('POST', '/api/admin/programs', {
			name: createName.trim(),
			description: createDescription.trim() || null
		});
		submitting = false;
		if (ok) {
			closeModals();
			router.reload();
		} else {
			error = message ?? 'Failed to create program.';
		}
	}

	async function handleUpdate() {
		if (!editProgram || !editName.trim()) return;
		submitting = true;
		error = '';
		const { ok, message } = await api('PUT', `/api/admin/programs/${editProgram.id}`, {
			name: editName.trim(),
			description: editDescription.trim() || null
		});
		submitting = false;
		if (ok) {
			closeModals();
			router.reload();
		} else {
			error = message ?? 'Failed to update program.';
		}
	}

	async function handleActivate(p: ProgramItem) {
		submitting = true;
		error = '';
		const { ok, message } = await api('POST', `/api/admin/programs/${p.id}/activate`);
		submitting = false;
		if (ok) {
			error = '';
			router.reload();
		} else {
			error = message ?? 'Failed to start session.';
		}
	}

	async function handlePause(p: ProgramItem) {
		submitting = true;
		error = '';
		const { ok, message } = await api('POST', `/api/admin/programs/${p.id}/pause`);
		submitting = false;
		if (ok) {
			error = '';
			router.reload();
		} else {
			error = message ?? 'Failed to pause.';
		}
	}

	async function handleResume(p: ProgramItem) {
		submitting = true;
		error = '';
		const { ok, message } = await api('POST', `/api/admin/programs/${p.id}/resume`);
		submitting = false;
		if (ok) {
			error = '';
			router.reload();
		} else {
			error = message ?? 'Failed to resume.';
		}
	}

	async function handleDeactivate(p: ProgramItem) {
		submitting = true;
		error = '';
		const { ok, message } = await api('POST', `/api/admin/programs/${p.id}/deactivate`);
		submitting = false;
		if (ok) {
			error = '';
			router.reload();
		} else {
			error = message ?? 'You can only stop the session when no clients are in the queue.';
		}
	}

	function openDeleteConfirm(p: ProgramItem) {
		deleteConfirmProgram = p;
		error = '';
	}

	async function handleDeleteConfirm() {
		if (!deleteConfirmProgram) return;
		const p = deleteConfirmProgram;
		submitting = true;
		error = '';
		const { ok, message } = await api('DELETE', `/api/admin/programs/${p.id}`);
		submitting = false;
		if (ok) {
			closeModals();
			router.reload();
		} else {
			error = message ?? 'Cannot delete: program has sessions.';
			deleteConfirmProgram = null;
		}
	}

	function closeDeleteConfirm() {
		deleteConfirmProgram = null;
	}
</script>

<svelte:head>
	<title>Programs — FlexiQueue</title>
</svelte:head>

<AdminLayout>
	<div class="flex flex-col gap-4">
		<div class="flex flex-wrap items-center justify-between gap-2">
			<h1 class="text-2xl font-semibold text-base-content">Programs</h1>
			<button type="button" class="btn btn-primary" onclick={openCreate}>+ Create Program</button>
		</div>

		{#if error}
			<div class="alert alert-error" role="alert">
				<span>{error}</span>
				<button type="button" class="btn btn-ghost btn-sm" onclick={() => (error = '')}>Dismiss</button>
			</div>
		{/if}

		{#if programs.length === 0}
			<div class="rounded-box bg-base-100 border border-base-300 p-8 text-center text-base-content/70">
				<p>No programs yet. Create one to get started.</p>
			</div>
		{:else}
			<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
				{#each programs as program (program.id)}
					<div class="card bg-base-100 border border-base-300 shadow-sm">
						<div class="card-body">
							<div class="flex items-start justify-between gap-2">
								<Link href="/admin/programs/{program.id}" class="card-title text-lg link link-hover">{program.name}</Link>
								<div class="flex gap-1 items-center">
									{#if program.is_active && !program.is_paused}
										<span class="badge badge-success badge-sm animate-pulse">LIVE</span>
									{:else if program.is_active && program.is_paused}
										<span class="badge badge-warning badge-sm">Paused</span>
									{:else}
										<span class="badge badge-ghost badge-sm">Inactive</span>
									{/if}
								</div>
							</div>
							{#if program.description}
								<p class="text-sm text-base-content/70 line-clamp-2">{program.description}</p>
							{/if}
							<div class="card-actions mt-2 justify-end flex-wrap gap-1">
								<button
									type="button"
									class="btn btn-ghost btn-sm"
									onclick={() => openEdit(program)}
									disabled={submitting}
								>
									Edit
								</button>
								{#if program.is_active}
									{#if program.is_paused}
										<button
											type="button"
											class="btn btn-primary btn-sm"
											onclick={() => handleResume(program)}
											disabled={submitting}
										>
											Resume
										</button>
									{:else}
										<button
											type="button"
											class="btn btn-ghost btn-sm"
											onclick={() => handlePause(program)}
											disabled={submitting}
										>
											Pause
										</button>
									{/if}
									<button
										type="button"
										class="btn btn-ghost btn-sm"
										onclick={() => handleDeactivate(program)}
										disabled={submitting}
										aria-label="Stop session"
									>
										Stop session
									</button>
								{:else}
									<button
										type="button"
										class="btn btn-primary btn-sm"
										onclick={() => handleActivate(program)}
										disabled={submitting}
										aria-label="Start session"
									>
										Start session
									</button>
								{/if}
								<button
									type="button"
									class="btn btn-ghost btn-sm text-error"
									onclick={() => openDeleteConfirm(program)}
									disabled={submitting}
								>
									Delete
								</button>
							</div>
						</div>
					</div>
				{/each}
			</div>
		{/if}
	</div>
</AdminLayout>

<Modal open={showCreateModal} title="Create Program" onClose={closeModals}>
	<form
		onsubmit={(e) => {
			e.preventDefault();
			handleCreate();
		}}
		class="flex flex-col gap-4"
	>
		<div class="form-control w-full">
			<label for="create-name" class="label"><span class="label-text">Name</span></label>
			<input
				id="create-name"
				type="text"
				class="input input-bordered w-full"
				placeholder="e.g. Cash Assistance Q1 2026"
				maxlength="100"
				bind:value={createName}
				required
			/>
		</div>
		<div class="form-control w-full">
			<label for="create-desc" class="label"><span class="label-text">Description (optional)</span></label>
			<textarea
				id="create-desc"
				class="textarea textarea-bordered w-full"
				rows="3"
				placeholder="Brief description"
				bind:value={createDescription}
			></textarea>
		</div>
		<div class="flex justify-end gap-2">
			<button type="button" class="btn btn-ghost" onclick={closeModals}>Cancel</button>
			<button type="submit" class="btn btn-primary" disabled={submitting || !createName.trim()}>
				{submitting ? 'Creating…' : 'Create'}
			</button>
		</div>
	</form>
</Modal>

{#if editProgram}
	<Modal
		open={!!editProgram}
		title="Edit Program"
		onClose={closeModals}
	>
		<form
			onsubmit={(e) => {
				e.preventDefault();
				handleUpdate();
			}}
			class="flex flex-col gap-4"
		>
			<div class="form-control w-full">
				<label for="edit-name" class="label"><span class="label-text">Name</span></label>
				<input
					id="edit-name"
					type="text"
					class="input input-bordered w-full"
					maxlength="100"
					bind:value={editName}
					required
				/>
			</div>
			<div class="form-control w-full">
				<label for="edit-desc" class="label"><span class="label-text">Description (optional)</span></label>
				<textarea
					id="edit-desc"
					class="textarea textarea-bordered w-full"
					rows="3"
					bind:value={editDescription}
				></textarea>
			</div>
			<div class="flex justify-end gap-2">
				<button type="button" class="btn btn-ghost" onclick={closeModals}>Cancel</button>
				<button type="submit" class="btn btn-primary" disabled={submitting || !editName.trim()}>
					{submitting ? 'Saving…' : 'Save'}
				</button>
			</div>
		</form>
	</Modal>
{/if}

<ConfirmModal
	open={!!deleteConfirmProgram}
	title="Delete program?"
	message={deleteConfirmProgram
		? `Delete program "${deleteConfirmProgram.name}"? This is only allowed if it has no sessions.`
		: ''}
	confirmLabel="Delete"
	cancelLabel="Cancel"
	variant="danger"
	loading={submitting}
	onConfirm={handleDeleteConfirm}
	onCancel={closeDeleteConfirm}
/>
