# Toast migration map — Option B (Skeleton Toaster)

This document maps every page and shared component that shows status, error, or success feedback. For each, it specifies how to unify behavior using the centralized Skeleton Toaster so feedback is consistent and toast-first where appropriate.

**Conventions used below:**

- **Move to toast**: Replace inline banner/div with a call to `toaster.success()`, `toaster.error()`, `toaster.warning()`, or `toaster.info()`. Remove the local `error` / `successMessage` state and the inline UI that displays it (or keep state only for “clear on dismiss” if needed).
- **Keep inline**: Keep the message next to the form/control (e.g. validation errors, field-level hints). Optionally also fire a toast for accessibility or consistency.
- **Flash → toast**: Laravel session flash (`status`, `error`, `success`) is shared via `HandleInertiaRequests` and consumed by `FlashToToast` in layouts; no page-specific change except where a page currently renders flash inline (e.g. Login).

---

## Summary table

| Location | Current pattern | Action |
|----------|-----------------|--------|
| **Pages** | | |
| Auth/Login | Inline `error` from props (flash) | Flash→toast via AuthLayout; remove or keep inline as fallback |
| Station/Index | Local `error`, inline div in multiple places | Move all API/action errors to toast; keep only genuinely field-context validation inline |
| Admin/Programs/Show | Local `error` + some `toast()` calls | Replace `toast()` with toaster; move all operation errors to toast; keep structural warnings inline only if they are long-lived banners |
| Admin/Programs/Index | Local `error`, inline alert with Dismiss | Move to toast |
| Admin/ProgramDefaultSettings | Error toast + inline load error | Use toasts for save success and error; keep load-failure card if desired |
| Admin/Tokens/Index | Local `error`, inline divs + Dismiss | Move to toast |
| Admin/Users/Index | Local `error`, inline div | Move operation errors to toast; consider toast for client-side validation too |
| Admin/Settings/Index | Inline error/success + some toasts | Use toasts for all storage/ElevenLabs operation success+error; no page-top status banners |
| Admin/Analytics/Index | Local `error`, inline (load failure) | Move to toast |
| Admin/Logs/Index | Local `error`, inline (load failure) | Move to toast; keep export success toast |
| Admin/Dashboard | Local `error`, inline alert | Move to toast |
| Profile/Index | Field-level validation + some success toasts | Keep field-level errors inline; ensure all operations have success+error toasts |
| Triage/Index | Local `error`, inline (token lookup/bind) | Move to toast |
| Triage/PublicStart | Local `error`, inline | Move to toast |
| ProgramOverrides/Index | Local `error`, inline div | Move to toast |
| Display/Status | Prop `error` from server, inline div | On mount, if `error` prop set → toaster.error(); optionally keep inline for context |
| Display/Board | Local `displaySettingsError` (PIN modal) | Move to toast on save failure; keep inline under field for validation (e.g. “Enter a 6-digit PIN”) |
| BroadcastTest | Local `errorMessage`, inline div | Prefer toast for operation failures; keep short inline hint only if needed for dev UX |
| **Components** | | |
| ProgramDiagram/DiagramFlowContent | Uses `toast()` for save/upload/validation | Replace with toaster (or wrapper); no behavior change |
| QrScanner | Local `errorMessage`, inline in scanner UI | Keep inline; optionally toast for “no camera” / “denied” once so it persists if user scrolls away |

---

## 1. Pages

### 1.1 Auth/Login

- **File:** `resources/js/Pages/Auth/Login.svelte`
- **Current:** Receives `status` and `error` from Laravel flash via props. Renders `{#if error}` inline div with `role="alert"`. No layout (no Toast in DOM).
- **Move to toast:** Use AuthLayout (Toaster + FlashToToast). Flash is then shown as toast. Either remove the inline error block or keep it as fallback for immediate visibility; recommend remove and rely on toast.

---

### 1.2 Station/Index

- **File:** `resources/js/Pages/Station/Index.svelte`
- **Current:** `let error = $state('')`. Many API failure paths set `error = message ?? '...'`. Rendered in multiple places: e.g. top-level error div, inside modals (Force complete, Call next override).
- **Move to toast:** On every `error = ...` (load queue, call, serve, transfer, complete, no-show, override, force complete, etc.), call `toaster.error({ title: message })` (or description if needed). Remove local `error` and all `{#if error}` blocks that only show the message. Keep modal flow; on API failure show only toast and close modal or leave modal open without a separate error div.

---

### 1.3 Admin/Programs/Show

- **File:** `resources/js/Pages/Admin/Programs/Show.svelte`
- **Current:** Uses `toaster` from `lib/toaster.js`. Operation failures call `toaster.error({ title: message })`; warning payloads use `toaster.info({ title: payload.warning })`. Save settings fires `toaster.success({ title: "Settings saved." })`. No main error banner; optional “missing stations” warning remains as inline banner where needed.
- **Move to toast:** Done. Ensure any new operations also use toaster for success/error and do not add page-level error banners.

---

### 1.4 Admin/Programs/Index

- **File:** `resources/js/Pages/Admin/Programs/Index.svelte`
- **Current:** `let error = $state("")`. Set on create/update/delete program, start/pause/resume session. Inline: `{#if error}` block with AlertCircle and Dismiss.
- **Move to toast:** On each failure, call `toaster.error({ title: errorMessage })`. Remove `error` state and the inline alert block.

---

### 1.5 Admin/ProgramDefaultSettings

- **File:** `resources/js/Pages/Admin/ProgramDefaultSettings.svelte`
- **Current:**
  - Uses an `api()` helper that already calls `toaster.error()` for session/network problems.
  - On load failure: inline `<div role="alert">Failed to load settings.</div>` plus retry button.
  - On save failure: `toaster.error({ title: message ?? "Failed to save." })`; no explicit success feedback beyond the button returning from “Saving…” to “Save default settings”.
- **Move to toast:**
  - On successful save (`ok === true` for `PUT /api/admin/program-default-settings`), call `toaster.success({ title: "Default settings updated." })` (or use API `message` if provided).
  - Keep the load-failure inline card + retry button as a page-level empty state, or move that message to toast-only; avoid duplicating long error text in both places.
  - Do not introduce a persistent success banner; rely on the toast plus the button state.

---

### 1.6 Admin/Tokens/Index

- **File:** `resources/js/Pages/Admin/Tokens/Index.svelte`
- **Current:** Uses `toaster` from `lib/toaster.js`. All operation failures call `toaster.error({ title: message })`; success toasts for create, delete, status update, etc. No inline operation-error blocks (TTS regenerate and other modals use toasts only). Structural warning (e.g. “Server TTS not set up”) remains inline.
- **Move to toast:** Done. Keep validation/structural messages inline only where appropriate; all operation feedback via toaster.

---

### 1.7 Admin/Users/Index

- **File:** `resources/js/Pages/Admin/Users/Index.svelte`
- **Current:** `let error = $state("")`. Set on create/update user, reset password, deactivate, and validation (e.g. “Name, email, and password are required”, “Password must be at least 8 characters”). Inline `{#if error}` div.
- **Move to toast:** For API/operation failures use `toaster.error({ title: msg })`. For client-side validation messages you can either use `toaster.error()` or keep short inline message; recommend toast for consistency. Remove `error` and inline block.

---

### 1.8 Admin/Settings/Index

- **File:** `resources/js/Pages/Admin/Settings/Index.svelte`
- **Current:**
  - Storage:
    - `fetchSummary()` shows load failures via `toaster.error()` and leaves the page in a “no data” state (no dedicated inline error banner).
    - Clear TTS cache / clear orphaned TTS already use `toaster.success(...)` with formatted sizes.
  - ElevenLabs:
    - Load status/usage/voices: on `419` or network, calls `toaster.error()` and falls back to inline text like “Usage unavailable.” or “Unable to load ElevenLabs integration status.”
    - API accounts:
      - Add/edit account: validation errors set `accountFormError` and show an inline alert in the modal; session/network errors fire `toaster.error()` or set inline error only.
      - Delete/activate account: on success, silently updates the list; on session/network error, uses `toaster.error()`.
- **Move to toast:**
  - Storage:
    - Keep the existing success toasts for clear/clear‑orphaned; no additional inline success banners.
    - For initial `fetchSummary()` failure, either keep the inline explanatory text or rely on toast-only; avoid duplicating full error messages.
  - ElevenLabs accounts:
    - On successful add/edit: call `toaster.success({ title: "Account saved." })` (or use API `message` if present) when closing the modal.
    - On successful delete: `toaster.success({ title: "Account removed." })`.
    - On successful activate: `toaster.success({ title: "Account set as active." })`.
    - Keep `accountFormError` inline inside the modal for validation errors (label/API key/model), but for generic server failures you may also fire a toast so the user notices even if the error is off-screen.
  - Do not add page-top “success” or “error” banners; operation feedback should come from toasts and, for modal forms, the inline validation block.

---

### 1.9 Admin/Analytics/Index

- **File:** `resources/js/Pages/Admin/Analytics/Index.svelte`
- **Current:** `let error = $state("")`. Set when analytics load fails. Inline display.
- **Move to toast:** On load failure call `toaster.error({ title: message })`. Remove `error` and inline block.

---

### 1.10 Admin/Logs/Index

- **File:** `resources/js/Pages/Admin/Logs/Index.svelte`
- **Current:** `let error = $state("")`. Set when audit log load fails. Inline display.
- **Move to toast:** On load failure call `toaster.error({ title: "Failed to load audit log." })`. Remove `error` and any inline load-failure block. Keep the existing `toaster.success({ title: "Export downloaded" })` toast for successful export.

---

### 1.11 Admin/Dashboard

- **File:** `resources/js/Pages/Admin/Dashboard.svelte`
- **Current:** `let error = $state("")`. Set when dashboard stats load fails. Inline alert with AlertCircle.
- **Move to toast:** On failure call `toaster.error({ title: "Failed to load dashboard stats." })`. Remove `error` and inline alert.

---

### 1.12 Profile/Index

- **File:** `resources/js/Pages/Profile/Index.svelte`
- **Current:**
  - Password + PIN forms:
    - Field-level errors (e.g. `passwordErrors.password`) rendered inline with `<span ... role="alert">...</span>` under the inputs.
    - On success, calls like `toaster.success({ title: data.message ?? "Password updated." })` and `toaster.success({ title: data.message ?? "Override PIN updated." })`.
  - QR + avatar upload:
    - Uses `toaster.success({ title: data.message ?? "Avatar updated." })` and other success toasts.
    - Avatar errors (`avatarError`) are shown inline below the avatar picker with `role="alert"`.
- **Move to toast:**
  - Keep field-level validation inline with `role="alert"` – these should remain next to their inputs for clarity and accessibility.
  - Ensure all non-field-specific failures (e.g. generic server error, network error) trigger `toaster.error({ title: message ?? "Failed to update profile." })` instead of only setting a local message.
  - Confirm that each operation (password update, PIN update, QR reset, avatar upload) has both success and error toasts; avoid adding any new top-of-page success banners.

---

### 1.13 Triage/Index

- **File:** `resources/js/Pages/Triage/Index.svelte`
- **Current:** `let error = $state('')`. Set on token lookup (not found, in use, deactivated) and bind failure. Inline display in scan/confirm flow.
- **Move to toast:** On lookup or bind failure call `toaster.error({ title: message })`. Clear or remove `error` and inline error UI.

---

### 1.14 Triage/PublicStart

- **File:** `resources/js/Pages/Triage/PublicStart.svelte`
- **Current:** `let error = $state('')`. Set on token lookup, “Enter or scan a token”, and start-visit failure. Inline display.
- **Move to toast:** On each failure call `toaster.error({ title: message })`. Remove `error` and inline block.

---

### 1.15 ProgramOverrides/Index

- **File:** `resources/js/Pages/ProgramOverrides/Index.svelte`
- **Current:** `let error = $state('')`. Set on generate failure, approve failure, reject failure. Inline div.
- **Move to toast:** On each failure call `toaster.error({ title: message })`. Remove `error` and inline div.

---

### 1.16 Display/Status

- **File:** `resources/js/Pages/Display/Status.svelte`
- **Current:** Receives `error` as prop from server (check-status). Renders `{#if error}` inline div with `role="alert"`.
- **Move to toast:**
  - In `$effect` or on mount, when `error` prop is non-empty call `toaster.error({ title: error })`.
  - Option A (recommended): keep the inline card for context (status page is meant to be always visible) and treat the toast as a secondary surface.
  - Option B: if you want to avoid duplication, remove the inline error card and rely solely on the toast (keeping the message short and clear). Ensure `DisplayLayout` has `<FlexiQueueToaster />` + `<FlashToToast />`.

---

### 1.17 Display/Board

- **File:** `resources/js/Pages/Display/Board.svelte`
- **Current:** `displaySettingsError` for PIN validation and save failure in display settings modal. Shown under PIN field and possibly elsewhere.
- **Move to toast:** On save failure (invalid PIN, API error) call `toaster.error({ title: message })`. For “Enter a 6-digit PIN” keep inline under the field as validation feedback, or use toast. Remove or simplify `displaySettingsError` for server errors.

---

### 1.18 BroadcastTest

- **File:** `resources/js/Pages/BroadcastTest.svelte`
- **Current:**
  - Uses `toaster.error({ title: 'Echo not available...' })` for connection issues and request failures.
  - Also has inline `<p role="alert">Echo not available. See toast for details.</p>` near the test UI.
- **Move to toast:**
  - Prefer toasts for operation failures; they already exist and should remain the primary feedback.
  - Optionally keep the short inline hint (“See toast for details”) since this is a dev-focused page, but avoid duplicating full error text both inline and in the toast.

---

### 1.19 Other pages (no change or N/A)

- **Welcome.svelte:** No error/success feedback; no change.
- **Staff/Dashboard.svelte:** No error/success feedback; no change.
- **Display/StationBoard.svelte:** No error/alert; no change.
- **Admin/Tokens/Print.svelte:** Print view; no toast needed for this map.

---

## 2. Shared components

### 2.1 ProgramDiagram/DiagramFlowContent

- **File:** `resources/js/Components/ProgramDiagram/DiagramFlowContent.svelte`
- **Current:** Uses `toaster` from `../../lib/toaster.js`. All feedback uses `toaster.error({ title: message })` and `toaster.success({ title: message })` (CSRF, save diagram, upload image, image added, diagram passed publish).
- **Move to toast:** Done. No dependency on toastStore; all call sites use toaster.

---

### 2.2 QrScanner

- **File:** `resources/js/Components/QrScanner.svelte`
- **Current:** `errorMessage` for camera access, no camera, permission denied, no QR in image, etc. Shown inline in scanner UI with `role="alert"`.
- **Recommendation:**
  - Keep inline errors as the primary feedback: they are tightly bound to the scanning surface and should stay in-context.
  - Optionally, for “hard” failures that block all scanning (no camera found, permission denied), also call `toaster.error({ title: errorMessage })` once so the user sees it even if they scroll away.
  - Avoid toasting every transient scan failure (“no QR in this frame”) to prevent toast spam.

---

### 2.3 OfflineBanner

- **File:** `resources/js/Components/OfflineBanner.svelte`
- **Current:** Renders a persistent connectivity banner with `role="alert"` when the app detects offline state.
- **Action:** Leave as inline banner only. This is a long-lived environmental status, not a one-off operation result; do not add toasts here.

---

### 2.3 Toast.svelte (current custom)

- **File:** `resources/js/Components/Toast.svelte`
- **Action:** Remove or deprecate after all call sites use Skeleton Toaster. Layouts will use `<Toaster toaster={toaster} />` instead.

---

### 2.4 toastStore.js

- **File:** `resources/js/stores/toastStore.js`
- **Action:** Remove or deprecate after migration. Provide optional wrapper in `lib/toaster.js` that matches `toast(message, type)` and calls `toaster[type]({ title: message })` for gradual migration.

---

## 3. Laravel / flash

- **HandleInertiaRequests:** Share `flash` (e.g. `status`, `error`, `success`) in `share()`.
- **FlashToToast:** New component; in layouts that have Toaster, read `page.props.flash` and call `toaster.error/success/info` so redirect-with-flash shows as toast. Use once per navigation (e.g. track last flash key or run once when flash is present).
- **Login:** Use AuthLayout (Toaster + FlashToToast); then flash on login redirect is shown as toast. No need to pass `status`/`error` into Login page props for display if flash is consumed by FlashToToast (can still pass for any other use).

---

## 4. Layouts

- **AdminLayout, MobileLayout, AppShell:** Replace `<Toast />` with `<Toaster toaster={toaster} />`. Add `<FlashToToast />` (or equivalent) that uses `toaster` and `page.props.flash`.
- **AuthLayout (new):** Minimal layout for Auth/Login: `<Toaster toaster={toaster} />` and `<FlashToToast />` so login flash shows as toast.
- **DisplayLayout:** Add `<Toaster toaster={toaster} />` and `<FlashToToast />` if display pages (Board, Status) should show toasts.

---

## 5. Implementation order (suggested)

1. Add `resources/js/lib/toaster.js` (createToaster, export store; optional `toast(message, type)` wrapper).
2. Swap Toast → Toaster + FlashToToast in AdminLayout, MobileLayout, AppShell; add AuthLayout and assign to Login; add Toaster to DisplayLayout if needed.
3. Share flash in HandleInertiaRequests; implement FlashToToast.
4. Migrate DiagramFlowContent and Programs/Show (already use toast) to toaster.
5. Migrate remaining pages in order of traffic or complexity (e.g. Station/Index, Tokens/Index, Programs/Index, then Settings, Users, Profile, Triage, ProgramOverrides, Analytics, Logs, Dashboard, ProgramDefaultSettings, Display/Status, Display/Board, BroadcastTest).
6. Remove or deprecate `toastStore.js` and `Toast.svelte`; document in 07-UI-UX-SPECS or this doc.

---

## 6. Consistency rules (after migration)

- **Operation feedback (save, delete, API call):** Use toaster (success/error/warning/info). No inline “operation failed” banners unless product explicitly wants a persistent banner.
- **Form validation (field-level):** Keep inline next to the field (e.g. “Enter a 6-digit PIN”, “Password must be at least 8 characters”) or use toast; prefer one pattern app-wide (recommend toast for consistency with operations).
- **Laravel flash:** Always consumed by FlashToToast in layout; pages do not render flash props as inline messages (except optional fallback on Login if desired).

### 6.1 Operation success vs inline status

- **Default:** Any user-triggered write operation (save/create/update/delete/activate/export/etc.) should fire:
  - `toaster.success({ title: '...' })` on success, and
  - `toaster.error({ title: message ?? 'Something went wrong.' })` on failure.
- **Inline only when necessary:** Keep inline messages only when they are:
  - tightly coupled to a specific field (validation under an input), or
  - long-lived contextual banners (e.g. “System offline”, “ZeroTier not configured”).
- **No duplicate messaging:** Do not show the same operation result both as a toast and as a full inline banner; prefer toast, and keep inline messages minimal and contextual.

---

## 7. Coverage checklist

After migration is complete, all of the following should be true:

- Every write operation (save/create/update/delete/activate/export/etc.) on:
  - Admin pages (Programs, Tokens, Users, Settings, ProgramDefaultSettings, Analytics, Logs, Dashboard),
  - Station/Index, ProgramOverrides, Triage pages, Profile, Display/Board, Display/Status,
  - and `ProgramDiagram/DiagramFlowContent`
  fires a success toast on success and an error toast on failure (or uses Laravel flash → FlashToToast for redirect flows).
- All legacy inline “operation failed/succeeded” banners described above have been removed or reduced to:
  - truly field-level validation next to inputs, or
  - intentional long-lived status banners (e.g. OfflineBanner, structural warnings).
- `toastStore.js` / `Toast.svelte` no longer drive any UX; all call sites use `lib/toaster.js` and layouts with `<FlexiQueueToaster />` and `<FlashToToast />`.
