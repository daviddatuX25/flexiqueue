<script lang="ts">
	import AdminLayout from '../../../Layouts/AdminLayout.svelte';
	import Modal from '../../../Components/Modal.svelte';
	import ConfirmModal from '../../../Components/ConfirmModal.svelte';
	import { get } from 'svelte/store';
	import { router, usePage } from '@inertiajs/svelte';

	interface UserItem {
		id: number;
		name: string;
		email: string;
		role: string;
		is_active: boolean;
		assigned_station_id: number | null;
		assigned_station: { id: number; name: string } | null;
	}

	let { users = [] }: { users: UserItem[] } = $props();

	let error = $state('');
	let submitting = $state(false);
	let showCreateModal = $state(false);
	let showEditModal = $state(false);
	let showResetModal = $state(false);
	let deactivateConfirmUser = $state<UserItem | null>(null);
	let editUser = $state<UserItem | null>(null);
	let resetUser = $state<UserItem | null>(null);
	let createName = $state('');
	let createEmail = $state('');
	let createPassword = $state('');
	let createRole = $state<'admin' | 'staff'>('staff');
	let createOverridePin = $state('');
	let editName = $state('');
	let editEmail = $state('');
	let editRole = $state<'admin' | 'staff'>('staff');
	let editIsActive = $state(true);
	let editPassword = $state('');
	let editOverridePin = $state('');
	let resetPassword = $state('');

	const page = usePage();
	function getCsrfToken(): string {
		const p = get(page);
		const fromProps = (p?.props as { csrf_token?: string } | undefined)?.csrf_token;
		if (fromProps) return fromProps;
		const meta =
			typeof document !== 'undefined'
				? (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content
				: '';
		return meta ?? '';
	}

	async function api(
		method: string,
		url: string,
		body?: object
	): Promise<{ ok: boolean; data?: { user?: UserItem } | object; message?: string }> {
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
		const data = await res.json().catch(() => ({}));
		return { ok: res.ok, data, message: (data as { message?: string })?.message };
	}

	function openCreate() {
		createName = '';
		createEmail = '';
		createPassword = '';
		createRole = 'staff';
		createOverridePin = '';
		error = '';
		showCreateModal = true;
	}

	async function handleCreate() {
		if (!createName.trim() || !createEmail.trim() || !createPassword.trim()) {
			error = 'Name, email, and password are required.';
			return;
		}
		submitting = true;
		error = '';
		const body: { name: string; email: string; password: string; role: string; override_pin?: string } = {
			name: createName.trim(),
			email: createEmail.trim(),
			password: createPassword,
			role: createRole,
		};
		if (createOverridePin.trim()) body.override_pin = createOverridePin.trim();
		const { ok, message: msg } = await api('POST', '/api/admin/users', body);
		submitting = false;
		if (ok) {
			showCreateModal = false;
			router.reload();
		} else error = msg ?? 'Failed to create user.';
	}

	function openEdit(u: UserItem) {
		editUser = u;
		editName = u.name;
		editEmail = u.email;
		editRole = u.role as 'admin' | 'staff';
		editIsActive = u.is_active;
		editPassword = '';
		editOverridePin = '';
		error = '';
		showEditModal = true;
	}

	async function handleEdit() {
		if (!editUser || !editName.trim() || !editEmail.trim()) return;
		submitting = true;
		error = '';
		const body: { name: string; email: string; role: string; is_active: boolean; password?: string; override_pin?: string | null } = {
			name: editName.trim(),
			email: editEmail.trim(),
			role: editRole,
			is_active: editIsActive,
		};
		if (editPassword.trim()) body.password = editPassword.trim();
		if (editOverridePin.trim()) body.override_pin = editOverridePin.trim();
		else body.override_pin = null;
		const { ok, data, message: msg } = await api('PUT', `/api/admin/users/${editUser.id}`, body);
		submitting = false;
		if (ok && data?.user) {
			showEditModal = false;
			editUser = null;
			router.reload();
		} else error = msg ?? 'Failed to update user.';
	}

	function openReset(u: UserItem) {
		resetUser = u;
		resetPassword = '';
		error = '';
		showResetModal = true;
	}

	async function handleReset() {
		if (!resetUser || !resetPassword.trim() || resetPassword.length < 8) {
			error = 'Password must be at least 8 characters.';
			return;
		}
		submitting = true;
		error = '';
		const { ok, message: msg } = await api('POST', `/api/admin/users/${resetUser.id}/reset-password`, {
			password: resetPassword,
		});
		submitting = false;
		if (ok) {
			showResetModal = false;
			resetUser = null;
			error = '';
		} else error = msg ?? 'Failed to reset password.';
	}

	function openDeactivateConfirm(u: UserItem) {
		deactivateConfirmUser = u;
		error = '';
	}

	async function handleDeactivateConfirm() {
		if (!deactivateConfirmUser) return;
		const u = deactivateConfirmUser;
		submitting = true;
		error = '';
		const { ok, message: msg } = await api('DELETE', `/api/admin/users/${u.id}`);
		submitting = false;
		if (ok) {
			deactivateConfirmUser = null;
			router.reload();
		} else error = msg ?? 'Failed to deactivate user.';
	}

	function closeDeactivateConfirm() {
		deactivateConfirmUser = null;
	}

	function closeModals() {
		showCreateModal = false;
		showEditModal = false;
		showResetModal = false;
		deactivateConfirmUser = null;
		editUser = null;
		resetUser = null;
		error = '';
	}

</script>

<svelte:head>
	<title>Staff — FlexiQueue</title>
</svelte:head>

<AdminLayout>
	<div class="flex flex-wrap items-center justify-between gap-2">
		<div>
			<h1 class="text-2xl font-semibold text-base-content">Staff</h1>
			<p class="mt-2 text-base-content/80">
				Manage staff accounts. Assign stations per program in Program → Staff tab.
			</p>
		</div>
		<button type="button" class="btn btn-primary btn-sm" onclick={openCreate}>+ Add Staff</button>
	</div>

	{#if error}
		<div class="alert alert-error mt-4">{error}</div>
	{/if}

	<div class="overflow-x-auto mt-6">
		<table class="table table-zebra">
			<thead>
				<tr>
					<th>Name</th>
					<th>Email</th>
					<th>Role</th>
					<th>Status</th>
					<th>Assigned to</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				{#each users as user (user.id)}
					<tr>
						<td>{user.name}</td>
						<td>{user.email}</td>
						<td>
							<span class="badge badge-ghost">{user.role}</span>
						</td>
						<td>
							{#if user.is_active}
								<span class="badge badge-success badge-sm">Active</span>
							{:else}
								<span class="badge badge-ghost badge-sm">Inactive</span>
							{/if}
						</td>
						<td>
							<span class="text-base-content/70">{user.assigned_station?.name ?? '—'}</span>
						</td>
						<td>
							{#if user.is_active}
								<div class="flex gap-1">
									<button
										type="button"
										class="btn btn-ghost btn-xs"
										onclick={() => openEdit(user)}
										disabled={submitting}
									>
										Edit
									</button>
									<button
										type="button"
										class="btn btn-ghost btn-xs"
										onclick={() => openReset(user)}
										disabled={submitting}
									>
										Reset PW
									</button>
									<button
										type="button"
										class="btn btn-ghost btn-xs text-error"
										onclick={() => openDeactivateConfirm(user)}
										disabled={submitting}
									>
										Deactivate
									</button>
								</div>
							{:else}
								<button
									type="button"
									class="btn btn-ghost btn-xs"
									onclick={() => openEdit(user)}
									disabled={submitting}
								>
									Edit
								</button>
							{/if}
						</td>
					</tr>
				{/each}
			</tbody>
		</table>
	</div>
</AdminLayout>

<Modal open={showCreateModal} title="Add staff" onClose={closeModals}>
	<div class="space-y-4">
		<div class="form-control">
			<label class="label"><span class="label-text">Name</span></label>
			<input type="text" class="input input-bordered w-full" bind:value={createName} placeholder="Juan Cruz" />
		</div>
		<div class="form-control">
			<label class="label"><span class="label-text">Email</span></label>
			<input type="email" class="input input-bordered w-full" bind:value={createEmail} placeholder="juan@example.com" />
		</div>
		<div class="form-control">
			<label class="label"><span class="label-text">Password</span></label>
			<input type="password" class="input input-bordered w-full" bind:value={createPassword} placeholder="Min 8 characters" />
		</div>
		<div class="form-control">
			<label class="label"><span class="label-text">Role</span></label>
			<select class="select select-bordered w-full" bind:value={createRole}>
				<option value="staff">Staff</option>
				<option value="admin">Admin</option>
			</select>
		</div>
		<div class="form-control">
			<label class="label"><span class="label-text">Override PIN (6 digits)</span></label>
			<input type="text" class="input input-bordered w-full max-w-xs" bind:value={createOverridePin} placeholder="e.g. 123456" maxlength="6" inputmode="numeric" pattern="[0-9]*" />
			<span class="label-text-alt">Required when assigning as program supervisor. Set now so the user can be added as supervisor later.</span>
		</div>
		<div class="flex gap-2 pt-2">
			<button type="button" class="btn btn-primary" disabled={submitting} onclick={handleCreate}>
				{submitting ? 'Creating…' : 'Create'}
			</button>
			<button type="button" class="btn btn-ghost" onclick={closeModals}>Cancel</button>
		</div>
	</div>
</Modal>

<Modal open={showEditModal} title={editUser ? `Edit ${editUser.name}` : 'Edit user'} onClose={closeModals}>
	{#if editUser}
		<div class="space-y-4">
			<div class="form-control">
				<label class="label"><span class="label-text">Name</span></label>
				<input type="text" class="input input-bordered w-full" bind:value={editName} />
			</div>
			<div class="form-control">
				<label class="label"><span class="label-text">Email</span></label>
				<input type="email" class="input input-bordered w-full" bind:value={editEmail} />
			</div>
			<div class="form-control">
				<label class="label"><span class="label-text">Role</span></label>
				<select class="select select-bordered w-full" bind:value={editRole}>
					<option value="staff">Staff</option>
					<option value="admin">Admin</option>
				</select>
			</div>
			<div class="form-control">
				<label class="label cursor-pointer justify-start gap-2">
					<input type="checkbox" class="checkbox" bind:checked={editIsActive} />
					<span class="label-text">Active (can log in)</span>
				</label>
			</div>
			<div class="form-control">
				<label class="label"><span class="label-text">New password (optional)</span></label>
				<input type="password" class="input input-bordered w-full" bind:value={editPassword} placeholder="Leave blank to keep current" />
			</div>
			<div class="form-control">
				<label class="label"><span class="label-text">Override PIN (6 digits)</span></label>
				<input type="text" class="input input-bordered w-full max-w-xs" bind:value={editOverridePin} placeholder="Leave blank to keep or clear" maxlength="6" inputmode="numeric" pattern="[0-9]*" />
				<span class="label-text-alt">Required for program supervisors. Set so the user can be added as supervisor in Program → Staff.</span>
			</div>
			<div class="flex gap-2 pt-2">
				<button type="button" class="btn btn-primary" disabled={submitting} onclick={handleEdit}>
					{submitting ? 'Saving…' : 'Save'}
				</button>
				<button type="button" class="btn btn-ghost" onclick={closeModals}>Cancel</button>
			</div>
		</div>
	{/if}
</Modal>

<Modal open={showResetModal} title={resetUser ? `Reset password for ${resetUser.name}` : 'Reset password'} onClose={closeModals}>
	{#if resetUser}
		<div class="space-y-4">
			<p class="text-sm text-base-content/70">Set a new password. The user will need to use this to log in.</p>
			<div class="form-control">
				<label class="label"><span class="label-text">New password</span></label>
				<input type="password" class="input input-bordered w-full" bind:value={resetPassword} placeholder="Min 8 characters" />
			</div>
			<div class="flex gap-2 pt-2">
				<button type="button" class="btn btn-primary" disabled={submitting || resetPassword.length < 8} onclick={handleReset}>
					{submitting ? 'Resetting…' : 'Reset'}
				</button>
				<button type="button" class="btn btn-ghost" onclick={closeModals}>Cancel</button>
			</div>
		</div>
	{/if}
</Modal>

<ConfirmModal
	open={!!deactivateConfirmUser}
	title="Deactivate user?"
	message={deactivateConfirmUser ? `Deactivate ${deactivateConfirmUser.name}? They will no longer be able to log in.` : ''}
	confirmLabel="Deactivate"
	cancelLabel="Cancel"
	variant="danger"
	loading={submitting}
	onConfirm={handleDeactivateConfirm}
	onCancel={closeDeactivateConfirm}
/>
