<script lang="ts">
    /**
     * Profile: password, preset PIN, preset QR, profile photo. Preset is per-user; any staff can set it.
     * Preset authorization only works when the user is a supervisor for the program where it's used.
     */
    import AdminLayout from "../../Layouts/AdminLayout.svelte";
    import MobileLayout from "../../Layouts/MobileLayout.svelte";
    import UserAvatar from "../../Components/UserAvatar.svelte";
    import PasswordInput from "../../Components/PasswordInput.svelte";
    import { usePage } from "@inertiajs/svelte";
    import { router } from "@inertiajs/svelte";
    import { toaster } from "../../lib/toaster.js";
    import { compressImage, AVATAR_PRESET, getUploadHint } from "../../lib/imageUtils.js";

    const page = usePage();
    const user = $derived($page.props?.auth?.user ?? null);

    // Password form
    let passwordCurrent = $state("");
    let passwordNew = $state("");
    let passwordConfirm = $state("");
    let passwordSubmitting = $state(false);
    let passwordErrors = $state<Record<string, string>>({});

    /** Digits only, max 6 — avoids browser "Please match the requested format" on pattern. */
    function sanitizePin(value: string): string {
        return value.replace(/\D/g, "").slice(0, 6);
    }

    // Override PIN form
    let currentPassword = $state("");
    let newPin = $state("");
    let pinSubmitting = $state(false);
    let pinErrors = $state<Record<string, string>>({});

    // Profile photo — use response URL so new avatar shows immediately (per ISSUES-ELABORATION §9)
    let avatarSubmitting = $state(false);
    let displayAvatarUrl = $state<string | null>(null);

	// Avatar upload interactive state
	let selectedAvatarFile = $state<File | null>(null);
	let avatarPreviewUrl = $state<string | null>(null);
	let avatarDragging = $state(false);
	let avatarError = $state<string | null>(null);

	function handleAvatarSelect(files: FileList | null) {
		avatarError = null;
		if (files && files.length > 0) {
			selectedAvatarFile = files[0];
			if (avatarPreviewUrl) URL.revokeObjectURL(avatarPreviewUrl);
			avatarPreviewUrl = URL.createObjectURL(selectedAvatarFile);
		} else {
			selectedAvatarFile = null;
			if (avatarPreviewUrl) URL.revokeObjectURL(avatarPreviewUrl);
			avatarPreviewUrl = null;
		}
	}
	
	function triggerFileInput() {
		document.getElementById('hidden-avatar-input')?.click();
	}

    // Preset QR
    let hasPresetQr = $state(false);
    let qrLoading = $state(false);
    let qrRegenerating = $state(false);
    let qrDataUri = $state<string | null>(null);

    async function fetchHasPresetQr() {
        qrLoading = true;
        try {
            const r = await fetch("/api/profile/override-qr", {
                credentials: "include",
            });
            const data = await r.json();
            hasPresetQr = !!data.has_preset_qr;
        } finally {
            qrLoading = false;
        }
    }

    $effect(() => {
        if (user) fetchHasPresetQr();
    });

    async function submitPassword(e: Event) {
        e.preventDefault();
        passwordSubmitting = true;
        passwordErrors = {};
        try {
            const r = await fetch("/api/profile/password", {
                method: "PUT",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-XSRF-TOKEN": getCsrfToken(),
                },
                credentials: "include",
                body: JSON.stringify({
                    current_password: passwordCurrent,
                    password: passwordNew,
                    password_confirmation: passwordConfirm,
                }),
            });
            const data = await r.json().catch(() => ({}));
            if (r.ok) {
                passwordErrors = {};
                toaster.success({ title: data.message ?? "Password updated." });
                passwordCurrent = "";
                passwordNew = "";
                passwordConfirm = "";
            } else if (r.status === 419) {
                toaster.error({ title: "Session expired. Refresh and try again." });
            } else {
                const errs = normalizeErrors(data.errors);
                passwordErrors = errs;
                toaster.error({
                    title:
                        data.message ??
                        (data.errors ? Object.values(data.errors).flat().join(" ") : "Failed to update password."),
                });
                const idByKey: Record<string, string> = {
                    current_password: "current_password",
                    password: "password_new",
                    password_confirmation: "password_confirm",
                };
                const firstKey = PASSWORD_FIELD_ORDER.find((k) => errs[k]);
                const focusId = firstKey ? idByKey[firstKey] : null;
                if (focusId) document.getElementById(focusId)?.focus();
            }
        } catch {
            toaster.error({ title: "Network error. Please try again." });
        } finally {
            passwordSubmitting = false;
        }
    }

    async function submitPin(e: Event) {
        e.preventDefault();
        const pin = sanitizePin(newPin);
        if (pin.length !== 6) {
            toaster.error({ title: "Enter a 6-digit PIN." });
            pinErrors = { new_pin: "Enter a 6-digit PIN." };
            return;
        }
        pinSubmitting = true;
        pinErrors = {};
        try {
            const r = await fetch("/api/profile/override-pin", {
                method: "PUT",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-XSRF-TOKEN": getCsrfToken(),
                },
                credentials: "include",
                body: JSON.stringify({
                    current_password: currentPassword,
                    new_pin: pin,
                }),
            });
            const data = await r.json().catch(() => ({}));
            if (r.ok) {
                pinErrors = {};
                toaster.success({ title: data.message ?? "Override PIN updated." });
                currentPassword = "";
                newPin = "";
            } else if (r.status === 419) {
                toaster.error({ title: "Session expired. Refresh and try again." });
            } else {
                const errs = normalizeErrors(data.errors);
                pinErrors = errs;
                toaster.error({
                    title:
                        data.message ??
                        (data.errors ? Object.values(data.errors).flat().join(" ") : "Failed to update PIN."),
                });
                const focusId = errs.current_password ? "pin_current_password" : errs.new_pin ? "new_pin" : null;
                if (focusId) document.getElementById(focusId)?.focus();
            }
        } catch {
            toaster.error({ title: "Network error. Please try again." });
        } finally {
            pinSubmitting = false;
        }
    }

    async function regenerateQr() {
        qrDataUri = null;
        qrRegenerating = true;
        try {
            const r = await fetch("/api/profile/override-qr/regenerate", {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "X-XSRF-TOKEN": getCsrfToken(),
                },
                credentials: "include",
            });
            const data = await r.json().catch(() => ({}));
            if (r.ok) {
                qrDataUri = data.qr_data_uri ?? null;
                hasPresetQr = true;
                toaster.success({
                    title: data.message ?? "Preset QR regenerated. Save or print it; it will not be shown again.",
                });
            } else {
                toaster.error({ title: data.message ?? "Failed to regenerate QR." });
            }
        } finally {
            qrRegenerating = false;
        }
    }

    async function submitAvatar(e: Event) {
		e.preventDefault();
		const form = e.target as HTMLFormElement;
		const fileInput = form.querySelector<HTMLInputElement>('input[type="file"][name="avatar"]');
		const file = fileInput?.files?.[0] ?? selectedAvatarFile;
		if (!file) {
			toaster.error({ title: "Please select an image." });
			avatarError = "Please select an image.";
			return;
		}
        avatarSubmitting = true;
        avatarError = null;
        try {
            const compressed = await compressImage(file, AVATAR_PRESET);
            const fd = new FormData();
            fd.append("avatar", compressed);
            const r = await fetch("/api/profile/avatar", {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "X-XSRF-TOKEN": getCsrfToken(),
                },
                credentials: "include",
                body: fd,
            });
            const data = await r.json().catch(() => ({}));
            if (r.ok) {
                avatarError = null;
                toaster.success({ title: data.message ?? "Avatar updated." });
                if (data.avatar_url) displayAvatarUrl = data.avatar_url;
				selectedAvatarFile = null;
				if (avatarPreviewUrl) { URL.revokeObjectURL(avatarPreviewUrl); avatarPreviewUrl = null; }
				if (fileInput) fileInput.value = "";
				router.reload();
            } else if (r.status === 419) {
                toaster.error({ title: "Session expired. Refresh and try again." });
            } else {
                const errs = normalizeErrors(data.errors);
                const msg =
                    errs.avatar ??
                    data.message ??
                    (data.errors ? Object.values(data.errors).flat().join(" ") : "Failed to update avatar.");
                avatarError = typeof msg === "string" ? msg : String(msg);
                toaster.error({ title: typeof data.message === "string" ? data.message : avatarError });
                document.getElementById("avatar-dropzone")?.focus();
            }
        } catch {
            toaster.error({ title: "Network error. Please try again." });
        } finally {
            avatarSubmitting = false;
        }
    }

    function getCsrfToken(): string {
        const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
        return match ? decodeURIComponent(match[1]) : "";
    }

    /** Normalize Laravel validation errors (array or string per key) to Record<string, string>. */
    function normalizeErrors(errors: unknown): Record<string, string> {
        if (!errors || typeof errors !== "object" || Array.isArray(errors)) return {};
        const out: Record<string, string> = {};
        for (const [key, val] of Object.entries(errors)) {
            const msg = Array.isArray(val) ? val[0] : val;
            out[key] = typeof msg === "string" ? msg : String(msg);
        }
        return out;
    }

    const PASSWORD_FIELD_ORDER = ["current_password", "password", "password_confirmation"] as const;
    const PIN_FIELD_ORDER = ["current_password", "new_pin"] as const;

    function printPresetQr() {
        if (!qrDataUri || !user) return;
        const name = user.name ?? "User";
        const w = window.open("", "_blank", "width=400,height=500");
        if (!w) return;
        w.document.write(`
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<title>Preset authorization QR — FlexiQueue</title>
	<style>
		body { font-family: system-ui, sans-serif; padding: 24px; text-align: center; }
		h1 { font-size: 1rem; margin: 0 0 8px; }
		p { font-size: 0.875rem; color: #666; margin: 0 0 16px; }
		img { max-width: 240px; height: auto; border: 1px solid #ddd; border-radius: 8px; }
		@media print { body { padding: 16px; } }
	</style>
</head>
<body>
	<h1>Preset authorization QR</h1>
	<p>${escapeHtml(name)} — FlexiQueue</p>
	<img src="${escapeHtml(qrDataUri)}" alt="Preset QR code" />
</body>
</html>`);
        w.document.close();
        w.focus();
        w.print();
    }

    function escapeHtml(s: string): string {
        const div = document.createElement("div");
        div.textContent = s;
        return div.innerHTML;
    }
</script>

<svelte:head>
    <title>Profile — FlexiQueue</title>
</svelte:head>

{#snippet profileContent()}
    <div class="profile-page-content p-4 max-w-lg mx-auto">
        <h1 class="text-xl font-semibold mb-4">Profile</h1>
        {#if user}
            <span class="text-sm text-surface-950/70">{user.name}</span>
            <span
                class="text-xs px-2 py-0.5 rounded preset-filled-primary-500 badge-sm ml-2"
                >{user.role}</span
            >
        {/if}

        <!-- Password -->
        <section class="card bg-surface-50 shadow-sm mt-4 mb-6">
            <div class="card-body">
                <h2 class="card-title text-base">Change password</h2>
                <form onsubmit={submitPassword} class="space-y-3">
                    <div>
                        <label for="current_password" class="label label-text"
                            >Current password</label
                        >
                        <input
                            id="current_password"
                            type="password"
                            class="input rounded-container border px-3 py-2 w-full {passwordErrors.current_password ? 'border-error-500 bg-error-50' : 'border-surface-200'}"
                            bind:value={passwordCurrent}
                            required
                            autocomplete="current-password"
                            aria-invalid={!!passwordErrors.current_password}
                            aria-describedby={passwordErrors.current_password ? 'current_password-error' : undefined}
                        />
                        {#if passwordErrors.current_password}
                            <span id="current_password-error" class="text-error-600 text-sm" role="alert">{passwordErrors.current_password}</span>
                        {/if}
                    </div>
                    <PasswordInput
                        bind:password={passwordNew}
                        bind:passwordConfirm={passwordConfirm}
                        errors={passwordErrors}
                        idPrefix="profile_pw"
                        disabled={passwordSubmitting}
                    />
                    <button
                        type="submit"
                        class="btn preset-filled-primary-500 btn-sm touch-target-h"
                        disabled={passwordSubmitting || passwordNew.length < 8 || passwordNew !== passwordConfirm}
                    >
                        {passwordSubmitting ? "Updating…" : "Update password"}
                    </button>
                </form>
            </div>
        </section>

        <!-- Preset PIN & QR (any user; only works when you're a supervisor for that program) -->
        <section class="card bg-surface-50 shadow-sm mb-6">
            <div class="card-body">
                <h2 class="card-title text-base">Override PIN</h2>
                <p class="text-sm text-surface-950/70">
                    Set or change your 6-digit PIN for authorizing overrides and
                    force-complete. Not visible to admins. <strong
                        >Only works when you are a supervisor for the program</strong
                    > where it’s used; otherwise staff will see: “You are not a supervisor
                    for this program.”
                </p>
                <form onsubmit={submitPin} class="space-y-3">
                    <div>
                        <label
                            for="pin_current_password"
                            class="label label-text">Current password</label
                        >
                        <input
                            id="pin_current_password"
                            type="password"
                            class="input rounded-container border px-3 py-2 w-full {pinErrors.current_password ? 'border-error-500 bg-error-50' : 'border-surface-200'}"
                            bind:value={currentPassword}
                            required
                            autocomplete="current-password"
                            aria-invalid={!!pinErrors.current_password}
                            aria-describedby={pinErrors.current_password ? 'pin_current_password-error' : undefined}
                        />
                        {#if pinErrors.current_password}
                            <span id="pin_current_password-error" class="text-error-600 text-sm" role="alert">{pinErrors.current_password}</span>
                        {/if}
                    </div>
                    <div>
                        <label for="new_pin" class="label label-text"
                            >New 6-digit PIN</label
                        >
                        <input
                            id="new_pin"
                            type="password"
                            inputmode="numeric"
                            maxlength="6"
                            class="input rounded-container border px-3 py-2 w-full {pinErrors.new_pin ? 'border-error-500 bg-error-50' : 'border-surface-200'}"
                            bind:value={newPin}
                            oninput={(e) => { newPin = sanitizePin(e.currentTarget.value); }}
                            required
                            placeholder="000000"
                            autocomplete="off"
                            aria-invalid={!!pinErrors.new_pin}
                            aria-describedby={pinErrors.new_pin ? 'new_pin-error' : undefined}
                        />
                        {#if pinErrors.new_pin}
                            <span id="new_pin-error" class="text-error-600 text-sm" role="alert">{pinErrors.new_pin}</span>
                        {/if}
                    </div>
                    <button
                        type="submit"
                        class="btn preset-filled-primary-500 btn-sm touch-target-h"
                        disabled={pinSubmitting}
                    >
                        {pinSubmitting ? "Saving…" : "Update PIN"}
                    </button>
                </form>
            </div>
        </section>

        <section class="card bg-surface-50 shadow-sm mb-6">
            <div class="card-body">
                <h2 class="card-title text-base">Preset QR</h2>
                <p class="text-sm text-surface-950/70">
                    Staff can scan your preset QR to authorize overrides. Preset
                    is per-user; <strong
                        >it only works when you are a supervisor for that
                        program</strong
                    >. Regenerating invalidates the previous QR. The QR is shown
                    only once after regeneration.
                </p>
                {#if qrLoading}
                    <p class="text-sm text-surface-950/60">Loading…</p>
                {:else}
                    {#if hasPresetQr && !qrDataUri}
                        <p class="text-sm text-surface-950/70">
                            You have a preset QR set. Regenerate to get a new
                            one (current one will stop working).
                        </p>
                    {/if}
                    {#if qrDataUri}
                        <div class="flex flex-col items-center gap-2 my-2">
                            <img
                                src={qrDataUri}
                                alt="Preset QR code"
                                class="w-48 h-48 object-contain border border-surface-200 rounded-lg"
                            />
                            <p class="text-xs text-warning-500">
                                Save or print this; it will not be shown again.
                            </p>
                            <button
                                type="button"
                                class="btn preset-outlined btn-sm"
                                onclick={printPresetQr}
                            >
                                Print
                            </button>
                        </div>
                    {/if}
                    <button
                        type="button"
                        class="btn preset-outlined btn-sm"
                        disabled={qrRegenerating}
                        onclick={regenerateQr}
                    >
                        {qrRegenerating
                            ? "Regenerating…"
                            : hasPresetQr
                              ? "Regenerate preset QR"
                              : "Generate preset QR"}
                    </button>
                {/if}
            </div>
        </section>

        <!-- Profile photo -->
        <section class="card bg-surface-50 shadow-sm">
            <div class="card-body">
                <h2 class="card-title text-base">Profile photo</h2>
                <p class="text-sm text-surface-950/70 mb-3">
                    JPEG or PNG. Images are compressed automatically; recommended under 50 KB for fast loading. Shows in header and on the display board.
                </p>
                <form onsubmit={submitAvatar} class="mb-5 flex flex-col gap-4">
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
								id="avatar-dropzone"
								class="border-2 border-dashed rounded-container p-6 text-center transition-colors {avatarDragging ? 'border-primary-500 bg-primary-50' : avatarError ? 'border-error-500 bg-error-50' : 'border-surface-300 hover:border-primary-400 bg-surface-50/50 hover:bg-surface-100/50'} cursor-pointer"
								ondragover={(e) => { e.preventDefault(); avatarDragging = true; }}
								ondragleave={() => avatarDragging = false}
								ondrop={(e) => { e.preventDefault(); avatarDragging = false; handleAvatarSelect(e.dataTransfer?.files || null); }}
								onclick={triggerFileInput}
								onkeydown={(e) => e.key === 'Enter' && triggerFileInput()}
								role="button"
								tabindex="0"
								aria-label="Upload profile photo"
								aria-describedby={avatarError ? 'avatar-error' : undefined}
							>
								<input 
									id="hidden-avatar-input"
									name="avatar"
									type="file" 
									accept="image/jpeg,image/png,image/jpg"
									class="hidden"
									aria-invalid={!!avatarError}
									aria-describedby={avatarError ? 'avatar-error' : undefined}
									onchange={(e) => handleAvatarSelect(e.currentTarget.files)}
								/>
								<div class="flex flex-col items-center justify-center gap-2 pointer-events-none">
									<svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-surface-400 {avatarDragging ? 'text-primary-500' : ''}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
									<div class="text-sm">
										<span class="font-semibold text-primary-600">Click to upload</span> or drag and drop
									</div>
									<p class="text-xs text-surface-500">PNG or JPG; {getUploadHint('avatar')}</p>
								</div>
							</div>
							{#if avatarError}
								<p id="avatar-error" class="text-error-600 text-sm mt-2" role="alert">{avatarError}</p>
							{/if}
						</div>
					</div>
					
					{#if selectedAvatarFile}
						<div class="flex justify-end border-t border-surface-200 pt-3 mt-1">
							<button type="submit" class="btn preset-filled-primary-500 min-w-24" disabled={avatarSubmitting}>
								{avatarSubmitting ? "Saving…" : "Save photo"}
							</button>
						</div>
					{/if}
				</form>
            </div>
        </section>
    </div>
{/snippet}

{#if user?.role === "admin"}
    <AdminLayout>
        {@render profileContent()}
    </AdminLayout>
{:else}
    <MobileLayout title="Profile" showBackBtn={false}>
        {@render profileContent()}
    </MobileLayout>
{/if}
