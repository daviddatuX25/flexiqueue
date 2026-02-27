import fs from 'fs';

const FILE_PATH = 'resources/js/Pages/Profile/Index.svelte';
let content = fs.readFileSync(FILE_PATH, 'utf8');

// 1. Update state variables in <script>
const stateRegex = /let displayAvatarUrl = \$state<string \| null>\(null\);/;
content = content.replace(stateRegex, `let displayAvatarUrl = $state<string | null>(null);

	// Avatar upload interactive state
	let selectedAvatarFile = $state<File | null>(null);
	let avatarPreviewUrl = $state<string | null>(null);
	let avatarDragging = $state(false);

	function handleAvatarSelect(files: FileList | null) {
		if (files && files.length > 0) {
			selectedAvatarFile = files[0];
			if (avatarPreviewUrl) URL.revokeObjectURL(avatarPreviewUrl);
			avatarPreviewUrl = URL.createObjectURL(selectedAvatarFile);
			avatarMessage = null;
		} else {
			selectedAvatarFile = null;
			if (avatarPreviewUrl) URL.revokeObjectURL(avatarPreviewUrl);
			avatarPreviewUrl = null;
		}
	}
	
	function triggerFileInput() {
		document.getElementById('hidden-avatar-input')?.click();
	}`);

// 2. Update submitAvatar function
const submitAvatarRegex = /async function submitAvatar\(e: Event\) \{[\s\S]*?avatarMessage = null;/;
content = content.replace(submitAvatarRegex, `async function submitAvatar(e: Event) {
		e.preventDefault();
		if (!selectedAvatarFile) {
			avatarMessage = { type: "error", text: "Please select an image." };
			return;
		}
		avatarMessage = null;`);

const fdAppendRegex = /fd\.append\("avatar", fileInput\.files\[0\]\);/;
content = content.replace(fdAppendRegex, `fd.append("avatar", selectedAvatarFile);`);

const routerReloadRegex = /if \(data\.avatar_url\) displayAvatarUrl = data\.avatar_url;\s*router\.reload\(\);/;
content = content.replace(routerReloadRegex, `if (data.avatar_url) displayAvatarUrl = data.avatar_url;
				selectedAvatarFile = null;
				if (avatarPreviewUrl) { URL.revokeObjectURL(avatarPreviewUrl); avatarPreviewUrl = null; }
				router.reload();`);

// 3. Update the avatar markup
const formMarkupRegex = /<div class="flex items-center gap-6 mb-5">[\s\S]*?<\/form>\s*<\/div>/;
const newMarkup = `<form onsubmit={submitAvatar} class="mb-5 flex flex-col gap-4">
					<div class="flex flex-col sm:flex-row items-start sm:items-center gap-6">
						<!-- Avatar Preview -->
						<div class="shrink-0 relative group">
							{#if avatarPreviewUrl}
								<img src={avatarPreviewUrl} alt="Preview" class="w-24 h-24 rounded-full object-cover border-4 border-primary-100 shadow-sm" />
								<button 
									type="button" 
									class="btn btn-sm preset-filled-surface-500 absolute -top-2 -right-2 rounded-full w-8 h-8 p-0 flex items-center justify-center shadow"
									onclick={() => handleAvatarSelect(null)}
									aria-label="Remove photo"
								>
									<svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
								</button>
							{:else}
								<UserAvatar user={user} size="xl" avatarUrlOverride={displayAvatarUrl} />
							{/if}
						</div>

						<!-- Dropzone -->
						<div class="flex-1 w-full">
							<div 
								class="border-2 border-dashed rounded-container p-6 text-center transition-colors {avatarDragging ? 'border-primary-500 bg-primary-50' : 'border-surface-300 hover:border-primary-400 bg-surface-50/50 hover:bg-surface-100/50'} cursor-pointer"
								ondragover={(e) => { e.preventDefault(); avatarDragging = true; }}
								ondragleave={() => avatarDragging = false}
								ondrop={(e) => { e.preventDefault(); avatarDragging = false; handleAvatarSelect(e.dataTransfer?.files || null); }}
								onclick={triggerFileInput}
								onkeydown={(e) => e.key === 'Enter' && triggerFileInput()}
								role="button"
								tabindex="0"
								aria-label="Upload profile photo"
							>
								<input 
									id="hidden-avatar-input"
									type="file" 
									accept="image/jpeg,image/png,image/jpg"
									class="hidden"
									onchange={(e) => handleAvatarSelect(e.currentTarget.files)}
								/>
								<div class="flex flex-col items-center justify-center gap-2 pointer-events-none">
									<svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-surface-400 {avatarDragging ? 'text-primary-500' : ''}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
									<div class="text-sm">
										<span class="font-semibold text-primary-600">Click to upload</span> or drag and drop
									</div>
									<p class="text-xs text-surface-500">PNG or JPG up to 2MB</p>
								</div>
							</div>
						</div>
					</div>
					
					{#if selectedAvatarFile}
						<div class="flex justify-end border-t border-surface-200 pt-3 mt-1">
							<button type="submit" class="btn preset-filled-primary-500 min-w-24" disabled={avatarSubmitting}>
								{avatarSubmitting ? "Saving…" : "Save photo"}
							</button>
						</div>
					{/if}
				</form>`;

content = content.replace(formMarkupRegex, newMarkup);

fs.writeFileSync(FILE_PATH, content, 'utf8');
console.log('Profile avatar dropzone patch applied.');
