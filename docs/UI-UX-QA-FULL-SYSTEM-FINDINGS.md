# UI/UX & QA Full-System Findings

This document is the result of **propagating** the framework from [docs/UI-UX-QA-AUDIT.md](UI-UX-QA-AUDIT.md) across the **entire** FlexiQueue system. The same UI/UX analyst and QA tester checklists were applied to every page, layout, component, and relevant backend surface. Output is **findings and suggestions/improvements only** — no code implementation.

---

## 1. Methodology and reference

- **Source of truth:** [docs/UI-UX-QA-AUDIT.md](UI-UX-QA-AUDIT.md) sections 2 (UI/UX analyst focus) and 3 (QA tester focus).
- **Categories used:** Accessibility (a11y), Consistency (UI/UX), Responsive and layout, Error handling and feedback, Security and edge cases, Test and QA infrastructure, Copy and clarity.
- **Severity scale:** Medium / Low / Info / Positive.
- **Scope:** All Pages (including Admin Analytics, Logs, Settings), 5 Layouts, all shared Components (including Dashboard, ProgramDiagram, Analytics, QrScanner), and backend controllers that affect user-visible messages and flows.
- **Toast migration:** Centralized toast (Option B) is implemented per [docs/TOAST-MIGRATION-MAP.md](TOAST-MIGRATION-MAP.md): Skeleton Toaster + FlashToToast in all layouts (including AuthLayout for Login). Many findings below that referred to inline errors or flash-on-login are **addressed**; see §6 and inline “(Addressed: …)” notes.

---

## 2. Summary

| Severity | Count (approx.) |
|----------|------------------|
| Medium   | 5                |
| Low      | 19               |
| Info     | 40               |
| Positive | 6                |

| Area                          | Findings | Addressed (toast + touch targets + 419/network) |
|-------------------------------|----------|-------------------------------------------------|
| 1. Auth and Welcome           | 6        | 4                                               |
| 2. Staff flows                | 8        | 3                                               |
| 3. Admin flows                | 12       | 4                                               |
| 4. Public and Display         | 5        | 1                                               |
| 5. Shared components and layouts | 19    | 9                                               |
| 6. Admin Analytics            | 7        | 4                                               |
| 7. Admin Logs                 | 5        | 3                                               |
| 8. Admin Settings             | 7        | 3                                               |
| 9. Cross-cutting              | 7        | 3                                               |
| 10. Backend UX surface        | 5        | 0                                               |

---

## 3. Findings by area

### 3.1 Area 1: Auth and Welcome

| # | Category     | Finding | Severity | Location | Suggestion / improvement |
|---|--------------|---------|----------|----------|---------------------------|
| 1 | Accessibility | Login submit button has no explicit min height; may be below 48px on mobile. | Low | `resources/js/Pages/Auth/Login.svelte` | **(Addressed: Submit button uses `touch-target-h`.)** |
| 2 | Consistency   | Login shows flashed `error` in a simple div; no "Dismiss" control. | Low | `resources/js/Pages/Auth/Login.svelte` | **(Addressed: Flash now shown via FlashToToast in AuthLayout; inline error removed per TOAST-MIGRATION-MAP.)** |
| 3 | Copy          | No "Forgot password?" or account-help link on login. | Info | `resources/js/Pages/Auth/Login.svelte` | If recovery is out of scope, document; otherwise add a link or short copy (e.g. "Contact admin to reset password"). |
| 4 | Copy          | Welcome page is dev-oriented ("Laravel 12 + Svelte 5...", "Test page"). | Low | `resources/js/Pages/Welcome.svelte` | If this page is ever user-facing, replace with product copy and remove stack references. |
| 5 | Accessibility | Welcome and BroadcastTest action buttons have no min height; may be below 48px. | Low | `resources/js/Pages/Welcome.svelte`, `resources/js/Pages/BroadcastTest.svelte` | **(Addressed: Welcome and BroadcastTest use `touch-target-h` on primary actions; BroadcastTest back link uses `touch-target-h`.)** |
| 6 | Error handling | BroadcastTest error state has no `role="alert"`. | Low | `resources/js/Pages/BroadcastTest.svelte` | **(Addressed: Error message paragraph has `role="alert"`.)** |

---

### 3.2 Area 2: Staff flows

| # | Category     | Finding | Severity | Location | Suggestion / improvement |
|---|--------------|---------|----------|----------|---------------------------|
| 1 | Accessibility | Station Index: multiple controls use `min-h-[44px]` or `min-w-[44px]` (station tabs, priority switch label, display settings button, queue action buttons). | Medium | `resources/js/Pages/Station/Index.svelte` | **(Addressed: 48px touch targets applied via shared utilities `touch-target-h` and `touch-target` in flexiqueue theme; Station Index and all other pages/components now use these utilities. See 07-UI-UX-SPECS touch targets section.)** |
| 2 | Consistency   | Staff Dashboard has no explicit loading or error state when metrics are missing. | Info | `resources/js/Pages/Staff/Dashboard.svelte` | If metrics can load async, add loading and error states; otherwise document that data is always server-rendered. |
| 3 | Consistency   | Profile: password, PIN, and avatar forms show success/error via inline message divs but not all use `role="alert"`. | Low | `resources/js/Pages/Profile/Index.svelte` | **(Addressed: Operation success/error moved to centralized toast.)** For any remaining inline blocks, use `role="alert"`. |
| 4 | Accessibility | Profile: form inputs (password, PIN, etc.) lack consistent `aria-invalid` and `aria-describedby` linking to error text. | Low | `resources/js/Pages/Profile/Index.svelte` | **(Addressed: Password, PIN, and avatar forms now use aria-invalid, aria-describedby, inline error spans with role="alert", toast + field-level errors; first invalid field focused on server validation error.)** |
| 5 | Accessibility | Profile: "Remove photo" and "Upload profile photo" have aria-labels; other icon buttons in the page may lack labels. | Info | `resources/js/Pages/Profile/Index.svelte` | Audit all icon-only buttons (e.g. QR regenerate, PIN visibility) and add `aria-label` where missing. |
| 6 | Error handling | Profile uses raw `fetch`; 419 or network errors may show generic or no message. | Info | `resources/js/Pages/Profile/Index.svelte` | **(Addressed: 419 shows toast "Session expired. Refresh and try again."; network errors show "Network error. Please try again." in password, PIN, and avatar submit handlers.)** |
| 7 | Consistency   | ProgramOverrides: error state and modals use inline messages; confirm dismiss/close wording is consistent with rest of app. | Low | `resources/js/Pages/ProgramOverrides/Index.svelte` | **(Addressed: Operation errors moved to toast.)** Confirm "Dismiss" vs "Close" on modals and document in 07-UI-UX-SPECS. |
| 8 | Responsive    | Staff Dashboard and Profile use MobileLayout; ensure all form actions and cards are usable on narrow viewports. | Info | `resources/js/Pages/Staff/Dashboard.svelte`, `resources/js/Pages/Profile/Index.svelte` | Manually verify on small viewports; add overflow or stacking if content is clipped. |

---

### 3.3 Area 3: Admin flows

| # | Category     | Finding | Severity | Location | Suggestion / improvement |
|---|--------------|---------|----------|----------|---------------------------|
| 1 | Accessibility | Programs Show: tab panels lack `aria-selected` on tabs and `aria-controls`/`id` linking tabs to panels. | Low | `resources/js/Pages/Admin/Programs/Show.svelte` | **(Addressed: ARIA semantics were already present; added roving tabindex, Arrow/Home/End keyboard navigation, and focus-to-panel on tab change.)** |
| 2 | Accessibility | Tokens Index: action buttons (Edit, Print, Mark available, etc.) use `min-h-[2.5rem]` (40px). | Medium | `resources/js/Pages/Admin/Tokens/Index.svelte` | **(Addressed: Bulk action bar and row actions use `touch-target-h`; 48px min height applied.)** |
| 3 | Consistency   | Programs Index: empty state is a single card; ensure copy is actionable (e.g. "Create your first program"). | Low | `resources/js/Pages/Admin/Programs/Index.svelte` | **(Addressed: Heading set to "Create your first program"; body tightened; CTA button retained; role="status" and touch-target-h added.)** |
| 4 | Consistency   | ProgramDefaultSettings: error shown inline after save failure; no `role="alert"`. | Low | `resources/js/Pages/Admin/ProgramDefaultSettings.svelte` | **(Addressed: Operation errors moved to centralized toast.)** If any inline message remains for field-level validation, add `role="alert"`. |
| 5 | Error handling | ProgramDefaultSettings: loading state on mount; if API fails, user may see empty form with no message. | Info | `resources/js/Pages/Admin/ProgramDefaultSettings.svelte` | **(Addressed: api() checks 419 and shows toast; catch shows "Network error. Please try again."; failed-load div has role="alert".)** |
| 6 | Consistency   | Admin Users Index: modals and error banners; confirm "Dismiss" vs "Close" consistency. | Low | `resources/js/Pages/Admin/Users/Index.svelte` | **(Addressed: Operation errors moved to toast.)** Align modal wording with app-wide choice and document. |
| 7 | Accessibility | Admin Users Index: create/edit modal form fields may lack `aria-invalid` and `aria-describedby` for validation errors. | Info | `resources/js/Pages/Admin/Users/Index.svelte` | Apply same pattern as Login for validation feedback. |
| 8 | Copy          | Tokens Print page is print-focused; ensure "Print" and "Back" are clear. | Info | `resources/js/Pages/Admin/Tokens/Print.svelte` | Verify labels and any print instructions are clear for first-time use. |
| 9 | Responsive    | Admin Dashboard (HealthStats, QuickActions, ActiveProgramCard): grid and cards; verify on small desktop and tablet. | Info | `resources/js/Pages/Admin/Dashboard.svelte`, Dashboard components | Confirm breakpoints and overflow; no min-width that causes horizontal scroll. |
| 10 | Accessibility | Logs Index: filter panel uses `aria-expanded` and `aria-controls="filter-panel-content"`. | Low | `resources/js/Pages/Admin/Logs/Index.svelte` | Verify the element with `id="filter-panel-content"` exists so the relationship is valid. |
| 11 | Consistency   | Admin Dashboard refresh button shows loading spinner; error alert uses "Dismiss" — good pattern. | Positive | `resources/js/Pages/Admin/Dashboard.svelte` | Reuse this pattern (loading + error with dismiss) on other admin pages that fetch data. |
| 12 | Test infra    | Admin flows (programs, tokens, users, logs) have no E2E coverage in repo. | Medium | `e2e/` missing | Add smoke E2E tests for at least: admin login → dashboard → programs list → one program show. |

---

### 3.4 Area 4: Public and Display

| # | Category     | Finding | Severity | Location | Suggestion / improvement |
|---|--------------|---------|----------|----------|---------------------------|
| 1 | Accessibility | Display Board and Status: barcode input and scan region have good aria-labels and live regions. | Positive | `resources/js/Pages/Display/Board.svelte`, `resources/js/Pages/Display/Status.svelte` | Keep and replicate for any new scan/input regions. |
| 2 | Security/QA   | Public triage and display settings use PIN; ensure API error messages do not leak sensitive data. | Info | `app/Http/Controllers/Api/PublicTriageController.php`, `PublicDisplaySettingsController.php` | Review "Invalid PIN" and other responses; avoid revealing whether a program exists or other details. |
| 3 | Consistency   | Display Board and PublicStart: scan countdown and settings modals follow same pattern; good. | Positive | `resources/js/Pages/Display/Board.svelte`, `resources/js/Pages/Triage/PublicStart.svelte` | Document this pattern for future display/triage features. |
| 4 | Error handling | Display Status: error div has no `role="alert"`. | Low | `resources/js/Pages/Display/Status.svelte` | **(Addressed: Display errors surfaced via toast where applicable.)** If any inline error block remains, add `role="alert"`. |
| 5 | Error handling | Display StationBoard: display-only; no user-facing error state if real-time or TTS fails. | Info | `resources/js/Pages/Display/StationBoard.svelte` | **(Addressed: Real-time and TTS failures now surface via toaster.warning — "Real-time updates unavailable" / "Audio unavailable".)** |

---

### 3.5 Area 5: Shared components and layouts

| # | Category     | Finding | Severity | Location | Suggestion / improvement |
|---|--------------|---------|----------|----------|---------------------------|
| 1 | Accessibility | ThemeToggle: `min-h-[2.5rem] min-w-[2.5rem]` (40px). | Medium | `resources/js/Components/ThemeToggle.svelte` | **(Addressed: Button uses `touch-target` for 48×48px.)** |
| 2 | Accessibility | MobileLayout: avatar/profile button and bottom nav items use `min-h-[44px]` / `min-w-[44px]`. | Medium | `resources/js/Layouts/MobileLayout.svelte` | **(Addressed: Avatar and bottom nav Links use `touch-target`.)** |
| 3 | Accessibility | Modal: `<dialog>` has no `aria-modal="true"`; focus trap not explicit. | Medium | `resources/js/Components/Modal.svelte` | **(Addressed: aria-modal, focus first on open, Tab trap, and restore focus on close now implemented.)** |
| 4 | Accessibility | ConfirmModal: confirm/cancel buttons have no explicit min height. | Low | `resources/js/Components/ConfirmModal.svelte` | **(Addressed: Confirm and cancel buttons use `touch-target-h`.)** |
| 5 | Accessibility | DiagramFlowContent: "Save" and fullscreen buttons use `min-h-[40px]` / `min-w-[40px]`. | Low | `resources/js/Components/ProgramDiagram/DiagramFlowContent.svelte` | **(Addressed: Track selector, Clear diagram, Add image, Fullscreen/Exit fullscreen use `touch-target-h`; Save/Publish and fullscreen close use `touch-target-h`/`touch-target`.)** |
| 6 | Accessibility | ProgramDiagram nodes (TrackNode, StationNode, ProcessNode): `min-h-[44px]`; ClientSeatNode: `min-h-[40px]`. | Low | `resources/js/Components/ProgramDiagram/nodes/*.svelte` | **(Addressed: Nodes use `touch-target` or `touch-target-h`.)** |
| 7 | Accessibility | FlowDiagram: toolbar uses `min-h-[2.5rem]` (40px). | Low | `resources/js/Components/FlowDiagram.svelte` | **(Addressed: Step row uses `touch-target-h`.)** |
| 8 | Consistency   | Toast: only error type shows dismiss button; success/info do not. | Info | ~~`resources/js/Components/Toast.svelte`~~ | **(Addressed: Migrated to Skeleton Toaster; all types support dismiss/auto-dismiss via `lib/toaster.js` and layouts.)** |
| 9 | Error handling | OfflineBanner: no "Try again" or retry action. | Info | `resources/js/Components/OfflineBanner.svelte` | **(Addressed: Dismiss and Try again buttons added; banner is dismissible for current session; reset on next offline cycle.)** |
| 10 | Accessibility | UserAvatar: when no user, div has `aria-hidden="true"`; when initials, has `aria-label`. Good. | Positive | `resources/js/Components/UserAvatar.svelte` | Keep; ensure any wrapper that uses UserAvatar as a button has its own aria-label. |
| 11 | Consistency   | AppShell: header links (Profile, Log out) are small; no StatusFooter when used. | Info | `resources/js/Layouts/AppShell.svelte` | Confirm AppShell usage; if used on staff flows, ensure footer and touch targets are consistent with MobileLayout. |
| 12 | Accessibility | AdminLayout: drawer uses checkbox + peer; "Open menu" / "Close sidebar" have aria-labels. | Positive | `resources/js/Layouts/AdminLayout.svelte` | Keep; ensure nav links have clear labels. |
| 13 | Accessibility | StatusFooter: availability control and listbox have good ARIA (aria-expanded, aria-label, role listbox/option). | Positive | `resources/js/Layouts/StatusFooter.svelte` | Reuse pattern for other custom dropdowns. |
| 14 | Consistency   | Dashboard components (ActiveProgramCard, HealthStats, QuickActions): Link/buttons may be below 48px. | Info | `resources/js/Components/Dashboard/*.svelte` | **(Addressed: QuickActions and ActiveProgramCard links use `touch-target-h`; 48px applied.)** |
| 15 | Accessibility | QrScanner: error/status divs use `role="alert"`; barcode input has `aria-label`. | Positive | `resources/js/Components/QrScanner.svelte` | Keep; replicate for any new scan/input regions. |
| 16 | Accessibility | QrScanner: camera dropdown, "Scan from file", start/stop buttons — audit for 48px and aria-labels on icon-only controls. | Low | `resources/js/Components/QrScanner.svelte` | **(Addressed: Camera select and Scan-from-file labels use `touch-target-h`; camera select and file labels have `aria-label`.)** |
| 17 | Consistency   | QrScanner: error messages (camera permission, no cameras) — align styling and dismiss pattern with rest of app. | Info | `resources/js/Components/QrScanner.svelte` | Use same alert styling and Dismiss/Close as other pages. |
| 18 | Accessibility | ApexChart: placeholder "Chart library not loaded" has no `role="status"` or aria for chart region. | Info | `resources/js/Components/Analytics/ApexChart.svelte` | Add `role="status"` or ensure parent passes aria for chart region. |
| 19 | Consistency   | ApexChart: placeholder copy is developer-facing — consider user-facing message when chart fails to load. | Info | `resources/js/Components/Analytics/ApexChart.svelte` | e.g. "Chart could not be loaded. Try refreshing the page." |

---

### 3.6 Area 6: Admin Analytics

| # | Category     | Finding | Severity | Location | Suggestion / improvement |
|---|--------------|---------|----------|----------|---------------------------|
| 1 | Accessibility | Error block has no `role="alert"`. | Low | `resources/js/Pages/Admin/Analytics/Index.svelte` | **(Addressed: Load/API errors moved to toast.)** If any inline error block remains, add `role="alert"`. |
| 2 | Accessibility | Date range and filter buttons (Today, 7, 30, custom, program/track selects) may be below 48px. | Low | `resources/js/Pages/Admin/Analytics/Index.svelte` | **(Addressed: Export buttons, date range buttons, custom date inputs, and program/track selects use `touch-target-h`.)** |
| 3 | Accessibility | Charts (ApexChart): no `aria-label` or live region for chart data; placeholder "Chart library not loaded" is visual only. | Info | `resources/js/Pages/Admin/Analytics/Index.svelte`, `ApexChart.svelte` | Summarize key metrics in text or `aria-describedby` for screen reader users. |
| 4 | Consistency   | Loading states for summary vs charts are separate; ensure skeleton/placeholder pattern matches other admin data pages. | Info | `resources/js/Pages/Admin/Analytics/Index.svelte` | Align with Admin Dashboard loading pattern. |
| 5 | Error handling | Fetch errors set `error` state; no 419 handling. | Info | `resources/js/Pages/Admin/Analytics/Index.svelte` | **(Addressed: apiGet and fetchAll check for 419 and show toast "Session expired. Please refresh and try again."; network errors show "Network error. Please try again.")** |
| 6 | Copy          | Metric labels (e.g. "Total clients served", "Median wait") — verify clarity for first-time admin users. | Info | `resources/js/Pages/Admin/Analytics/Index.svelte` | Review and add short tooltips or help text if needed. |
| 7 | Consistency   | When no programs or no data in range — document or add explicit empty state copy and CTA. | Info | `resources/js/Pages/Admin/Analytics/Index.svelte` | **(Addressed: Page-level empty state "No data for this range" with CTA "Change filters"; role="status" and aria-label.)** |

---

### 3.7 Area 7: Admin Logs

| # | Category     | Finding | Severity | Location | Suggestion / improvement |
|---|--------------|---------|----------|----------|---------------------------|
| 1 | Accessibility | Filter toggle has `aria-expanded` and `aria-controls="filter-panel-content"`; `id="filter-panel-content"` exists — valid relationship. | Positive | `resources/js/Pages/Admin/Logs/Index.svelte` | Keep; reuse for other filter panels. |
| 2 | Accessibility | Date inputs, selects, "Apply" / "Download CSV" — ensure 48px min height for touch. | Low | `resources/js/Pages/Admin/Logs/Index.svelte` | **(Addressed: Filter toggle, Apply, Download CSV, filter form controls, and pagination use `touch-target-h`.)** |
| 3 | Error handling | Error state displayed; add `role="alert"` if missing; document 419 handling for fetch. | Info | `resources/js/Pages/Admin/Logs/Index.svelte` | **(Addressed: Load errors moved to toast.)** Ensure 419 handling shows user message; document. |
| 4 | Consistency   | Empty state when no audit entries — ensure copy and CTA consistent with Programs/Tokens. | Info | `resources/js/Pages/Admin/Logs/Index.svelte` | **(Addressed: CTA "Clear filters" added; role="status" and aria-label.)** |
| 5 | Copy          | "Audit log" vs "Program sessions" — ensure tab/section labels are clear; action_type values — consider readable labels. | Info | `resources/js/Pages/Admin/Logs/Index.svelte` | Map raw action types to user-friendly labels where shown. |

---

### 3.8 Area 8: Admin Settings

| # | Category     | Finding | Severity | Location | Suggestion / improvement |
|---|--------------|---------|----------|----------|---------------------------|
| 1 | Accessibility | Tab buttons use `min-h-[2.75rem]` (44px) — below 48px. | Low | `resources/js/Pages/Admin/Settings/Index.svelte` | **(Addressed: Storage and Integrations tab buttons use `touch-target-h`.)** |
| 2 | Accessibility | Error and success messages (after clear, or TTS account save) — add `role="alert"` where missing. | Low | `resources/js/Pages/Admin/Settings/Index.svelte` | **(Addressed: TTS warning badge, ElevenLabs load error block, account form error block, and disk low-space warning use `role="alert"`.)** |
| 3 | Accessibility | ElevenLabs account form (label, model, API key): add `aria-invalid` and `aria-describedby` for validation errors. | Info | `resources/js/Pages/Admin/Settings/Index.svelte` | Replicate Login pattern for form validation feedback. |
| 4 | Consistency   | Storage warning (ttsShareOfDisk, ttsWarning) — use same alert pattern as rest of app; confirm "Dismiss" vs "Close" on modals. | Info | `resources/js/Pages/Admin/Settings/Index.svelte` | Align with app-wide modal/banner wording. |
| 5 | Error handling | All fetch calls (storage summary, clear, orphaned, ElevenLabs status, accounts CRUD) — handle 419 and network errors. | Info | `resources/js/Pages/Admin/Settings/Index.svelte` | **(Addressed: All fetch paths check 419 and show toast; catch blocks show "Network error. Please try again.")** |
| 6 | Security/edge  | API key masked in UI — good; ensure copy for "Add account" does not suggest pasting key in plain text in logs. | Info | `resources/js/Pages/Admin/Settings/Index.svelte` | Keep key input secure; avoid copy that implies logging. |
| 7 | Copy          | "Clear TTS cache" vs "Remove orphaned TTS files" — ensure destructive actions have clear confirmation copy. | Info | `resources/js/Pages/Admin/Settings/Index.svelte` | Confirm modal text explains impact before user confirms. |

---

### 3.9 Area 9: Cross-cutting

| # | Category     | Finding | Severity | Location | Suggestion / improvement |
|---|--------------|---------|----------|----------|---------------------------|
| 1 | Consistency   | No breadcrumbs in admin (e.g. Programs > [Program name] > Diagram). Users rely on sidebar + "← Programs" back links. | Info | Admin pages | Document or add breadcrumbs for deep flows (e.g. Program Show, Diagram). |
| 2 | Accessibility | AdminLayout nav links and Profile/Log out use `min-h-[2.75rem]` (44px) — below 48px. | Low | `resources/js/Layouts/AdminLayout.svelte` | **(Addressed: Nav links, Profile, Log out use `touch-target-h`; hamburger uses `touch-target` for 48×48px.)** |
| 3 | Consistency   | Real-time (Echo): Station Index, Display Board, StationBoard, ProgramOverrides, Tokens Index use Echo; when Echo is null (no key), pages guard and degrade. Reconnection/offline UX is automatic; no retry button (see OfflineBanner). | Info | Multiple pages | Document which pages use Echo; document that reconnection is automatic. |
| 4 | Error handling | Logs audit export: CSV download — add finding if there is no success/error feedback after export. | Info | `resources/js/Pages/Admin/Logs/Index.svelte` | **(Addressed: Success toast "Export downloaded" on completion; error toast on failure.)** |
| 5 | Responsive    | Print styles: ensure no horizontal overflow or missing print-specific styles for Tokens Print and any other print views. | Info | `resources/js/Pages/Admin/Tokens/Print.svelte` | Verify @media print and overflow on small viewports. |
| 6 | Consistency   | Empty states: audit copy and CTAs across Analytics, Logs, Settings, Programs, Tokens, Users — list any that lack actionable copy or CTA. | Info | Multiple admin pages | **(Addressed: Empty-state pattern applied — Logs, Tokens, Users, Analytics, Programs Show, Tokens Print; 07-UI-UX-SPECS updated with empty-state section.)** |
| 7 | Consistency   | Backend: APIs used by Analytics, Logs, Settings — ensure error response shape matches rest of API (e.g. `{ message, errors? }`). | Info | `app/Http/Controllers/Api/Admin/*.php` | Standardize JSON error format for new endpoints. |

---

### 3.10 Area 10: Backend UX surface

| # | Category     | Finding | Severity | Location | Suggestion / improvement |
|---|--------------|---------|----------|----------|---------------------------|
| 1 | Consistency   | API controllers return errors in mixed shapes: `message`, `errors` (validation), or status-only. | Low | Various `app/Http/Controllers/Api/*.php` | Standardize JSON error shape (e.g. `{ message, errors? }`) so frontend can show user-facing text consistently. |
| 2 | Error handling | 419 (session expired) may not be handled in all API consumers; Diagram save does, others may not. | Info | Frontend pages that use `fetch` for API | **(Addressed: 419 and network error handling applied across Admin Analytics, Settings, ProgramDefaultSettings, Programs Index/Show, Logs, Tokens, Users, Dashboard, Triage, Station, ProgramOverrides, StatusFooter, DiagramFlowContent, DiagramCanvas; single message "Session expired. Please refresh and try again." and "Network error. Please try again.")** |
| 3 | Copy          | LoginController: inactive user gets same "Invalid credentials" as wrong password. | Info | `app/Http/Controllers/Auth/LoginController.php` | Consider whether to keep generic message for security or to show "Account is inactive" when appropriate; document decision. |
| 4 | Error handling | Rate limit (lockout) message: "Too many attempts. Please try again in 15 minutes." — clear. | Positive | `app/Http/Controllers/Auth/LoginController.php` | Keep; ensure this is the only lockout message and that it appears in the login error area. |
| 5 | Consistency   | ValidationException and redirects: Inertia forms get `errors` object; fetch-based forms need to read `message` or `errors` from JSON. | Info | Controllers + frontend | Document in 06-ERROR-HANDLING or API spec: when to use flash vs JSON `errors`, and how the frontend should display them. |

---

## 4. Cross-cutting suggestions

1. **Extend `docs/architecture/07-UI-UX-SPECS.md`** — File exists but is a stub (print template only). Add touch target rule (48px), error/empty/loading patterns, and CTA copy (Dismiss vs Close) so stack rules and components have a single source.
2. **Touch targets** — Systematically raise all interactive elements used on mobile from 40px/44px to 48px (ThemeToggle, MobileLayout, AdminLayout nav, Station, Tokens, Settings tabs, Diagram, FlowDiagram, ConfirmModal when in mobile layout).
3. **Modal focus trap** — Implement in `Modal.svelte` and ensure all dialogs (ConfirmModal, settings modals, etc.) benefit from focus containment and `aria-modal="true"`.
4. **Form validation feedback** — Replicate Login’s `aria-invalid` + `aria-describedby` + inline error text on Profile, Admin Users, Admin Settings (ElevenLabs form), and any other form-heavy page.
5. **Error and empty states** — Use `role="alert"` for all error and critical success messages; standardize empty-state copy and CTAs across Programs, Tokens, Users, Analytics, Logs, Settings.
6. **419 and network errors** — Audit all fetch-based pages (Analytics, Logs, Settings, Profile, etc.) for 419 handling and network error messaging; use a single user message ("Session expired. Please refresh and try again.") and optionally redirect to login.
7. **Chart accessibility** — Where ApexChart is used (Analytics), provide a text summary or aria for key chart data so screen reader users get the same information.
8. **E2E tests** — Create `e2e/` and add smoke tests for login, staff dashboard, station, admin dashboard, and at least one admin CRUD flow (e.g. programs list → one program show) so QA can run Playwright for regression.
9. **Backend error shape** — Document and, where possible, standardize API error response format (e.g. `{ message, errors? }`) so the frontend can show consistent, user-friendly messages and handle 419 uniformly; include new Analytics, Logs, Settings, and System/Integrations endpoints.

---

## 5. Addressed by Toast Migration (Option B)

The following findings are **resolved or substantially addressed** by the centralized Skeleton Toaster and FlashToToast implementation (see [TOAST-MIGRATION-MAP.md](TOAST-MIGRATION-MAP.md)):

| Area | # | Resolution |
|------|---|------------|
| 3.1 Auth and Welcome | 2 | Login flash shown via FlashToToast in AuthLayout; inline error removed. |
| 3.3 Admin flows | 1 | Programs Show tabs: roving tabindex, Arrow/Home/End keyboard nav, focus-to-panel on tab change. |
| 3.2 Staff flows | 3 | Profile operation success/error moved to toast. |
| 3.2 Staff flows | 7 | ProgramOverrides operation errors moved to toast. |
| 3.3 Admin flows | 4 | ProgramDefaultSettings save errors moved to toast. |
| 3.3 Admin flows | 6 | Admin Users operation errors moved to toast. |
| 3.4 Public and Display | 4 | Display Status errors surfaced via toast. |
| 3.4 Public and Display | 5 | StationBoard: real-time and TTS failures surfaced via toast (Real-time updates unavailable / Audio unavailable). |
| 3.5 Shared components | 8 | Custom Toast replaced by Skeleton Toaster; dismiss/auto-dismiss consistent. |
| 3.5 Shared components | 3 | Modal focus trap completed (aria-modal, focus first, Tab trap, restore focus on close). |
| 3.6 Admin Analytics | 1 | Load/API errors moved to toast. |
| 3.7 Admin Logs | 3 | Load errors moved to toast. |
| 3.9 Cross-cutting | 4 | Logs CSV export: success toast "Export downloaded" on completion; error toast on failure. |
| 3.2 Staff flows | 1 | Station Index (and app-wide) touch targets: shared utilities `touch-target-h` and `touch-target` in flexiqueue theme; all interactive controls now 48px. See 07-UI-UX-SPECS. |
| 3.3 Admin flows | 2 | Tokens Index: bulk and row action buttons use `touch-target-h`. |
| 3.5 Shared components | 14 | Dashboard QuickActions and ActiveProgramCard: links use `touch-target-h`. |
| 3.6 Admin Analytics | 2 | Date range, export buttons, custom inputs, program/track selects use `touch-target-h`. |
| 3.7 Admin Logs | 2 | Filter toggle, Apply, Download CSV, filter form controls, pagination use `touch-target-h`. |
| 3.8 Admin Settings | 1 | Storage and Integrations tab buttons use `touch-target-h`. |
| 3.9 Cross-cutting | 2 | AdminLayout: nav links, Profile, Log out use `touch-target-h`; hamburger uses `touch-target` (48×48px). |

Additional pages that now use the centralized toaster for operation feedback (no separate finding row): Station/Index, Admin/Programs/Show, Admin/Programs/Index, Admin/Tokens/Index, Admin/Settings/Index, Admin/Dashboard, Triage/Index, Triage/PublicStart, Display/Board, BroadcastTest, DiagramFlowContent.

---

## 6. Remaining work (prioritized)

Work still open after the toast migration and from the full-system audit:

1. **Touch targets (48px)** — **(Addressed.)** Shared utilities `touch-target-h` and `touch-target` in `themes/flexiqueue.css`; applied app-wide (Station Index, MobileLayout, Tokens Index, ThemeToggle, Diagram/FlowDiagram/ConfirmModal, Auth/Login, Welcome, etc.). **Admin pass complete:** Tokens Index bulk/row actions, Admin Settings tabs, AdminLayout nav/Profile/Log out/hamburger, Admin Logs filter/Apply/Download/form/pagination, Admin Analytics export/date range/inputs/selects, Dashboard QuickActions and ActiveProgramCard. 07-UI-UX-SPECS updated. (Finding 3.2 #1 and related touch-target findings addressed.)
2. **Form validation a11y** — **(Profile done.)** Replicate Login’s `aria-invalid` + `aria-describedby` on Admin Users, Admin Settings (ElevenLabs form). (Findings 3.2 #4, 3.3 #7, 3.8 #3.)
3. **role="alert"** — Where inline error/success blocks remain (e.g. field-level validation), add `role="alert"`. (Several Low findings.)
4. **419 and network errors** — **(Addressed.)** Single message "Session expired. Please refresh and try again." and "Network error. Please try again." applied across all fetch-based pages. (Findings 3.2 #6, 3.6 #5, 3.7 #3, 3.8 #5, 3.10 #2.)
5. **Empty states and copy** — **(Addressed.)** Empty-state pattern (message + CTA, role="status", aria-label) applied to Logs, Tokens, Users, Analytics, Programs Show, Tokens Print; ApexChart placeholder copy; 07-UI-UX-SPECS updated with empty-state section and Dismiss/Close wording. (Findings 3.3 #3, 3.6 #7, 3.7 #4, 3.9 #6.)
6. **Chart accessibility** — ApexChart: text summary or aria for key data; user-facing placeholder copy when chart fails to load. (Findings 3.5 #18–19, 3.6 #3.)
7. **E2E tests** — Smoke tests for login, staff dashboard, station, admin dashboard, admin programs list → show. (Finding 3.3 #12.)
8. **Extend 07-UI-UX-SPECS** — Add touch target rule (48px), error/empty/loading patterns, CTA copy (Dismiss vs Close). (Cross-cutting #1.)
9. **Backend error shape** — Standardize API JSON error format and document in 06-ERROR-HANDLING or API spec. (Findings 3.9 #7, 3.10 #1, #5.)

---

## 7. References

| Document / asset | Path |
|------------------|------|
| Expertise and sampled findings | [docs/UI-UX-QA-AUDIT.md](UI-UX-QA-AUDIT.md) |
| Toast migration map (Option B) | [docs/TOAST-MIGRATION-MAP.md](TOAST-MIGRATION-MAP.md) |
| Stack conventions | `.cursor/rules/stack-conventions.mdc` |
| Developing gotchas | `.cursor/rules/developing-gotchas.mdc` |
| UI/UX specs (stub: print template; extend with touch, error, empty patterns) | [docs/architecture/07-UI-UX-SPECS.md](architecture/07-UI-UX-SPECS.md) |
| Data model | `docs/architecture/04-DATA-MODEL.md` |
| Deployment | `docs/architecture/10-DEPLOYMENT.md` |
| Web routes | `routes/web.php` |
