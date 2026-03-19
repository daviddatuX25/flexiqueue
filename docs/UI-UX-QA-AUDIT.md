# UI/UX & QA System Audit — FlexiQueue

Single reference for UI/UX analysts and QA testers: role focus, audit scope, findings, and recommendations.

---

## 1. Purpose and audience

- **UI/UX analysts** — Use this doc to align checks with design system, usability, and consistency.
- **QA testers** — Use this doc for functional and UX test focus, edge cases, and accessibility.
- **Developers** — Use findings and recommendations when implementing or refactoring UI.

---

## 2. UI/UX analyst: expertise and focus

### Goals

- Consistency with the design system (Skeleton + flexiqueue theme).
- Clear information hierarchy and task success.
- Usability across devices (desktop, tablet, staff phones).

### What to check

| Area | Focus |
|------|--------|
| **Information architecture** | Navigation, section labels, breadcrumbs where relevant. |
| **Visual hierarchy** | Headings, spacing, alignment, typography scale. |
| **Feedback** | Loading states, error messages, empty states, success confirmation. |
| **Touch targets** | Minimum 48×48px for interactive elements on mobile (per stack conventions). |
| **Responsive behavior** | Breakpoints, overflow, marquees for long text. |
| **Copy** | Tone, clarity, consistency of CTAs and labels. |
| **Design tokens** | Use of theme colors, borders, rounded containers. |

### References

- Design tokens and component library: Skeleton UI + `resources/css/themes/flexiqueue.css`.
- UX spec: `docs/architecture/07-UI-UX-SPECS.md` (referenced in rules; **currently missing** — see findings).
- Stack conventions: `.cursor/rules/stack-conventions.mdc` (touch targets, Svelte 5, Inertia).

---

## 3. QA tester: expertise and focus

### Goals

- Correct behavior on happy paths and edge cases.
- No regressions; acceptable UX under errors and offline.
- Accessibility (keyboard, focus, screen readers) and cross-browser/viewport behavior.

### What to check

| Area | Focus |
|------|--------|
| **Happy paths** | Login, station queue, triage bind, display board, admin CRUD flows. |
| **Edge cases** | Empty data, validation errors, session expiry (419), offline, slow network. |
| **Forms** | Validation feedback, `aria-invalid` / `aria-describedby`, submit loading state. |
| **Accessibility** | Tab order, focus trap in modals, ARIA labels on icon-only controls, live regions. |
| **Spec alignment** | API and UI route behavior vs `docs/architecture/08-API-SPEC-PHASE1.md`, `09-UI-ROUTES-PHASE1.md`. |
| **E2E** | Playwright suite (when present) for regression. |

### References

- API spec: `docs/architecture/08-API-SPEC-PHASE1.md`.
- UI routes: `docs/architecture/09-UI-ROUTES-PHASE1.md`.
- Error handling: `docs/architecture/06-ERROR-HANDLING.md`.
- Playwright: `playwright.config.js`, `e2e/` (see Test infrastructure findings).

---

## 4. Audit scope

- **Frontend:** ~50 Svelte files under `resources/js/` (Pages, Layouts, Components).
- **Stack:** Laravel 12, Inertia.js v2, Svelte 5, TailwindCSS 4, Skeleton UI 4, Laravel Reverb.
- **Key flows:**
  - **Auth:** Login → Welcome or role-based redirect (Dashboard / Admin).
  - **Staff:** Station (queue, call, transfer, complete), Triage (scan/bind), Program Overrides, Profile.
  - **Admin:** Dashboard, Programs (incl. diagram editor), Tokens, Users, Reports.
  - **Public (no auth):** Display board/status, Triage start (when `allow_public_triage`).

---

## 5. Findings

### 5.1 Accessibility (a11y)

| Finding | Severity | Location / note |
|--------|----------|------------------|
| **Touch targets below 48px** | Medium | `resources/js/Components/ThemeToggle.svelte`: `min-h-[2.5rem] min-w-[2.5rem]` (40px). `resources/js/Layouts/MobileLayout.svelte`: avatar button `min-h-[44px] min-w-[44px]`. Stack convention requires 48×48px for mobile. |
| **Modal focus trap** | Medium | `resources/js/Components/Modal.svelte`: Uses `<dialog>` and Escape/Close but no explicit focus trap or `aria-modal`; focus can tab to backdrop. Native `<dialog>` traps focus in some browsers but not consistently. |
| **Tab panel semantics** | Low | `resources/js/Pages/Admin/Programs/Show.svelte`: Tabs use `role="tablist"` / `role="tab"` but no `aria-selected` or `aria-controls`/`id` linking to panels. |
| **Filter panel** | Low | `resources/js/Pages/Admin/Reports/Index.svelte`: `aria-expanded` and `aria-controls="filter-panel-content"` present — verify `id="filter-panel-content"` exists. |
| **Strong areas** | — | ARIA and `role` used in Display Board, Status, Triage, StatusFooter, Admin nav, Tokens, Diagram; `aria-live` for dynamic content; `aria-label` on icon-only controls. |

### 5.2 Consistency (UI/UX)

| Finding | Severity | Location / note |
|--------|----------|------------------|
| **Error display pattern** | Low | Mix of inline error divs with "Dismiss" vs "Close"; most use `role="alert"` and consistent error styling (e.g. `bg-error-100`, `border-error-300`). |
| **Empty states** | Low | Some pages have explicit empty copy (e.g. Station "Queue is empty", Reports "0 records", Tokens "Loading tokens..."); others (e.g. Programs list) show empty card — ensure copy is consistent and actionable. |
| **Loading states** | Info | Dashboard, Tokens, Reports, Station, Program Diagram use spinner + text; ConfirmModal uses inline spinner. Pattern is consistent. |
| **Missing UX spec doc** | Medium | `docs/architecture/07-UI-UX-SPECS.md` referenced in stack-conventions and component comments but **file not found**; design tokens and touch rules live in rules only. |

### 5.3 Responsive and layout

| Finding | Severity | Location / note |
|--------|----------|------------------|
| **Mobile layout** | Info | MobileLayout and DisplayLayout handle narrow viewports; marquee for long titles; bottom dock on staff flows. |
| **Diagram / complex UI** | Info | Diagram fullscreen and entity list use `max-h-[70vh]` / overflow; verify on small screens and zoom. |

### 5.4 Error handling and feedback

| Finding | Severity | Location / note |
|--------|----------|------------------|
| **Offline** | Info | `resources/js/Components/OfflineBanner.svelte`: Shows "Offline: connection lost. Reconnecting..." with `role="alert"`. No retry or "Try again" action. |
| **Toast** | Info | `resources/js/Components/Toast.svelte`: Dismiss button only for `type === 'error'`; success/info auto-dismiss or stack — confirm intended behavior. |
| **Form validation** | Info | Login uses `aria-invalid`, `aria-describedby`, and inline error text; good pattern to replicate on all forms. |

### 5.5 Security and edge cases (QA)

| Finding | Severity | Location / note |
|--------|----------|------------------|
| **Session expiry** | Info | Diagram save handles 419 with "Session expired. Refresh the page" (toast). Confirm other API calls show similar messaging. |
| **Public triage** | Info | PublicStart and Display flows work without auth; PIN for settings; ensure error messages do not leak sensitive data. |

### 5.6 Test and QA infrastructure

| Finding | Severity | Location / note |
|--------|----------|------------------|
| **E2E directory** | Medium | `playwright.config.js` points to `testDir: './e2e'` and `e2e/` is in `.gitignore`; **e2e folder not present** in repo (or excluded). No E2E tests visible to run for regression. |
| **Gotchas documented** | Positive | `.cursor/rules/developing-gotchas.mdc` documents QR flicker, latch pattern, form store usage — useful for QA and dev. |

### 5.7 Copy and clarity

| Finding | Severity | Location / note |
|--------|----------|------------------|
| **Welcome page** | Low | Dev-oriented copy ("Laravel 12 + Svelte 5...", "Test page"); acceptable for internal/dev; flag if this is ever user-facing. |
| **Login** | Info | Clear "Sign in with your email and password"; no "Forgot password?" link observed — confirm if intentional. |

---

## 6. Recommendations

1. **Add or restore `docs/architecture/07-UI-UX-SPECS.md`** — Capture design tokens, touch target rule, and feedback patterns so UI/UX and dev share one source of truth.
2. **Enforce 48×48px touch targets** — Update ThemeToggle and MobileLayout avatar button to meet stack convention for mobile.
3. **Modal focus trap** — In `Modal.svelte`, add explicit focus containment (e.g. focus first focusable on open, trap Tab, restore focus on close) and `aria-modal="true"` for consistent behavior.
4. **Tab semantics on Program Show** — Add `aria-selected` and `aria-controls`/panel `id`s for the Overview/Processes/Stations/etc. tabs.
5. **Add or restore E2E tests** — Create `e2e/` and at least smoke tests (e.g. login, dashboard load, triage scan flow) so QA can run Playwright for regression.
6. **Standardize error CTA copy** — Prefer one of "Dismiss" or "Close" for inline error banners and document in 07-UI-UX-SPECS or this doc.

---

## 7. References

| Doc / asset | Path |
|-------------|------|
| Stack conventions | `.cursor/rules/stack-conventions.mdc` |
| Developing gotchas | `.cursor/rules/developing-gotchas.mdc` |
| Data model | `docs/architecture/04-DATA-MODEL.md` |
| Deployment | `docs/architecture/10-DEPLOYMENT.md` |
| Web routes | `routes/web.php` |
| FlexiQueue theme | `resources/css/themes/flexiqueue.css` |
| Playwright config | `playwright.config.js` |
