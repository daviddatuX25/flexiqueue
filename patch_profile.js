import fs from 'fs';

const FILE_PATH = 'resources/js/Pages/Profile/Index.svelte';
let content = fs.readFileSync(FILE_PATH, 'utf8');

// 1. Swap imports
content = content.replace("import AppShell from '../../Layouts/AppShell.svelte';", "import AdminLayout from '../../Layouts/AdminLayout.svelte';\n\timport MobileLayout from '../../Components/MobileLayout.svelte';");

// 2. Wrap content in a snippet
content = content.replace('<AppShell>\n\t<div class="profile-page-content p-4 max-w-lg mx-auto">', '{#snippet profileContent()}\n\t<div class="profile-page-content p-4 max-w-lg mx-auto">');

// 3. Replace the closing tag and add the condition
const tailRegex = /<\/AppShell>\s*$/;
const newTail = `{/snippet}

{#if user?.role === 'admin'}
	<AdminLayout>
		{@render profileContent()}
	</AdminLayout>
{:else}
	<MobileLayout title="Profile" showBackBtn={false}>
		{@render profileContent()}
	</MobileLayout>
{/if}
`;
content = content.replace(tailRegex, newTail);

// 4. Update the avatar section
const oldAvatarSection = `<div class="flex items-center gap-4 mb-4">
					<UserAvatar user={user} size="lg" avatarUrlOverride={displayAvatarUrl} />
					<form onsubmit={submitAvatar} class="flex flex-col gap-2">
						<input
							type="file"
							name="avatar"
							accept="image/jpeg,image/png,image/jpg"
							class="file-input file-input-sm file-input-bordered w-full max-w-xs"
						/>
						<button type="submit" class="btn preset-filled-primary-500 btn-sm w-fit" disabled={avatarSubmitting}>
							{avatarSubmitting ? 'Uploading…' : 'Upload photo'}
						</button>
					</form>
				</div>`;

const newAvatarSection = `<div class="flex items-center gap-6 mb-5">
					<UserAvatar user={user} size="xl" avatarUrlOverride={displayAvatarUrl} />
					<form onsubmit={submitAvatar} class="flex flex-col gap-3 flex-1">
						<label class="block text-sm font-medium text-surface-700">Choose new photo</label>
						<div class="flex flex-wrap sm:flex-nowrap items-center gap-3">
							<input
								type="file"
								name="avatar"
								accept="image/jpeg,image/png,image/jpg"
								class="block w-full text-sm text-surface-500
								  file:mr-4 file:py-2 file:px-4
								  file:rounded-container file:border-0
								  file:text-sm file:font-semibold
								  file:bg-primary-50 file:text-primary-700
								  hover:file:bg-primary-100 transition-colors cursor-pointer"
							/>
							<button type="submit" class="btn preset-filled-primary-500 btn-sm shrink-0" disabled={avatarSubmitting}>
								{avatarSubmitting ? 'Uploading…' : 'Save'}
							</button>
						</div>
					</form>
				</div>`;

content = content.replace(oldAvatarSection, newAvatarSection);

fs.writeFileSync(FILE_PATH, content, 'utf8');
console.log('Profile page patched successfully.');
