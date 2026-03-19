# FlexiQueue UI Kit Refactor Plan

## Status: Refactor Cancelled and Reverted

**The UI-kit refactor (Phase 0–5) has been cancelled.** The codebase was reverted to the pre-refactor state (commit before ui-kit tokens, skeleton-shim, and ui-kit-primitives). We use the **current theme** as-is: **Skeleton UI** + [resources/css/themes/flexiqueue.css](../resources/css/themes/flexiqueue.css) with `data-theme="flexiqueue"`. Going forward, apply only **incremental UI improvements** (touch targets, spacing, bug fixes, consistency) using existing Skeleton/flexiqueue classes. No migration to ui-kit primitives and no Skeleton removal.

---

## Objective (Historical)

The original plan was to refactor the UI to the design system in [public/ui-kit.html](../public/ui-kit.html) (Tailwind + CSS variables, glass surfaces). That work was reverted; the following sections are kept for reference only.

---

## Current State vs Target (Reference Only)

| Layer         | Current                                                                    | Target (theme Skeleton)                                                                                |
| ------------- | -------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| Component lib | Skeleton UI (currently shim only; re-add `@skeletonlabs/skeleton`)           | **Keep Skeleton**; theme it with ui-kit tokens                                                          |
| Theme         | `flexiqueue.css` + shim + ui-kit-tokens                                   | Skeleton theme vars mapped to `--brand-*`, `--bg-base`, `--bg-surface`, etc. from ui-kit-tokens.css     |
| Font          | Inter (local)                                                              | Keep Inter (or Outfit; doc to decide)                                                                  |
| Dark mode     | Skeleton scheme / class-based `.dark`                                      | Keep; ensure Skeleton theme respects .dark                                                             |
| Buttons       | shim `.btn` + `.preset-*` or `.btn-ui-*`                                  | Skeleton `.btn`, `.preset-*` themed via flexiqueue theme so they use brand/surface tokens              |
| Surfaces      | `card`, `glass-surface`, primitives                                       | Skeleton cards + theme; keep `glass-surface` / primitives only where Skeleton has no equivalent        |
| Forms         | Skeleton `.input`, `.select` or primitives                                 | Skeleton form controls themed; primitives optional for one-off patterns                              |

---

## Deliverable: Consolidated Plan MD File

This document is that consolidated plan. It:

1. **Defines phases** (Theme foundation → Layouts → Shared components → Pages by area).
2. **Lists every module** (layouts, components, pages) with concrete sub-tasks so nothing is left implicit.
3. **Maps ui-kit sections** to implementation tasks (e.g. "Typography" → app.css + layout text classes).
4. **Stays in sync with** [docs/FLEXIQUEUE-UI-COMPONENT-CHECKLIST.md](FLEXIQUEUE-UI-COMPONENT-CHECKLIST.md) so checklist items are either done in the refactor or explicitly deferred.

---

## Phase 0: Theme and Foundation (No Skeleton Removal Yet) ✅ DONE

**Goal:** Introduce ui-kit tokens and Tailwind theme so new classes can be used alongside Skeleton during migration.

- **0.1 CSS variables**
  - Add a new theme file (e.g. `resources/css/themes/ui-kit-tokens.css`) that mirrors [public/ui-kit.html](../public/ui-kit.html) lines 13–69: `:root` (light) and `.dark` (dark) with `--bg-base`, `--bg-surface`, `--text-main`, `--text-muted`, `--border-color`, `--brand-*`, `--accent-*`, `--success-*`, `--warning-*`, `--error-*`, `--radius-*`.
  - Import this after Tailwind in `app.css` so vars are available everywhere.
- **0.2 Tailwind theme**
  - In `app.css` (or a dedicated config source), extend Tailwind theme to use those vars (see ui-kit script block ~173–204): `colors.surface`, `surface-border`, `primary.*`, `accent.*`, `success.*`, `warning.*`, `error.*`, `borderRadius.base/md/lg/xl`.
  - Ensure `darkMode: 'class'` so `.dark` on a root element toggles dark theme.
- **0.3 Base layout and typography**
  - Add `.ui-container` (max-width, padding) and `.glass-surface` (background, border, shadow) from ui-kit into the new theme file or app.css.
  - Optional: Add Outfit font (Google Fonts or local) and set `body` font-family to match ui-kit; otherwise keep Inter and document the difference.
- **0.4 Dark mode root**
  - Ensure `app.blade.php` or root layout can add `.dark` to `<html>` when user/brand prefers dark (e.g. no JS toggle required for Phase 0; can be added later).
- **Checklist alignment:** Covers "Color Palette CSS Variables", "Typography System" (if Outfit added), "Glassmorphism Theme Tokens", "Radius Tokens" from FLEXIQUEUE-UI-COMPONENT-CHECKLIST.

---

## Phase 1: Remove Skeleton and Add UI-Kit Primitives

**Goal:** Remove Skeleton dependency and replace with ui-kit-equivalent classes and minimal custom CSS.

- **1.1 Skeleton: keep and theme** (replaces "Remove Skeleton")
  - **Cancelled removal.** Re-add `@import '@skeletonlabs/skeleton'` in app.css and use Skeleton as the component library. Keep `themes/skeleton-shim.css` only if needed for overrides during theme work. Map Skeleton theme (flexiqueue.css) to ui-kit tokens so `--color-primary-*`, `--color-surface-*`, etc. use `--brand-*`, `--bg-surface`, etc. from ui-kit-tokens.css. `[data-theme='flexiqueue']` already overrides primary to `--brand-*` in app.css.
- **1.2 Button and form primitives** ✅ DONE
  - Add utility classes or small component-style blocks for: Primary, Tonal/Secondary, Outline, Ghost/Danger, Icon small — matching ui-kit "Buttons & Actions" (lines 264–329). Use Tailwind + CSS vars (e.g. `bg-primary-500`, `border-surface-border`).
  - Add form control base: label, input, select, textarea, checkbox, radio, toggle — matching ui-kit "Form Controls" (lines 335–398). Include error and disabled states.
  - Implemented: `resources/css/themes/ui-kit-primitives.css` (`.btn-ui-primary`, `.btn-ui-tonal`, `.btn-ui-outline`, `.btn-ui-ghost-danger`, `.btn-ui-icon`, `.label-ui`, `.input-ui`, `.select-ui`, `.textarea-ui`, `.form-group-ui`, error/disabled states).
- **1.3 Badges and alerts** ✅ DONE
  - Add status badge classes (success, warning, error, info) and inline alert blocks (error, success, left-accent banner) from ui-kit "Status Badges" and "Alerts & Feedback".
  - Implemented in `ui-kit-primitives.css`: `.badge-ui-success`, `.badge-ui-warning`, `.badge-ui-error`, `.badge-ui-info`; `.alert-ui-error`, `.alert-ui-success`; `.alert-ui-banner` + `.alert-ui-banner-warning/error/success`.
- **1.4 Cards and tables** ✅ DONE
  - Add `.glass-surface` usage and table wrapper (toolbar, sticky header, zebra, pagination, empty state) so that `table-container` / `elevation-*` can be replaced by ui-kit table pattern (ui-kit lines 409–502).
  - Implemented in `ui-kit-primitives.css`: `.card-ui`, `.card-ui-widget`, `.card-ui-action`; `.table-wrapper-ui`, `.table-ui-toolbar`, `.table-ui`, `.table-ui-zebra`, `.table-ui-footer`; `.empty-state-ui` (+ `.empty-state-ui-icon`, `.empty-state-ui-title`, `.empty-state-ui-desc`).
- **1.5 Modals and overlay** ✅ DONE
  - Added modal primitives in `ui-kit-primitives.css`: `.modal-backdrop-ui`, `.modal-panel-ui`, `.modal-close-btn-ui` (48px touch target), `.modal-footer-ui` (stacked on mobile, row on sm+), `@keyframes modal-fade-in-up`; `.btn-ui-danger` and `.btn-ui-warning` for confirm dialogs. Updated `Modal.svelte`: glass-surface + modal-panel-ui, backdrop blur, responsive padding (mx-4 sm:mx-6), SVG close button with modal-close-btn-ui. Updated `ConfirmModal.svelte`: ui-kit buttons (btn-ui-outline, btn-ui-primary/danger/warning), modal-footer-ui, text-text-muted; touch-friendly min-height on mobile.
- **1.6 Navigation** ✅ DONE
  - Added in `ui-kit-primitives.css`: `.breadcrumb-ui` (links + `.breadcrumb-ui-sep`, `.breadcrumb-ui-current`), `.tabs-ui` with `.tabs-ui-btn` / `.tabs-ui-btn-active` (min-height 2.75rem for touch, overflow-x + hide-scrollbar), `.pagination-ui` with `.pagination-ui-info`, `.pagination-ui-nav`, `.pagination-ui-btn` (prev/next), `.pagination-ui-page` / `.pagination-ui-page-active`, `.pagination-ui-ellipsis` (responsive flex col/row, touch-friendly button sizes). `.hide-scrollbar-ui` for scrollable tabs.
- **1.7 Empty, loading, toasts** ✅ DONE
  - Empty state already in 1.4 (`.empty-state-ui`). Added: `.spinner-ui` / `.spinner-ui-lg` (brand-500 border spin), `.skeleton-ui` (pulse, theme-aware light/dark), `.toast-ui` base + `.toast-ui-success`, `.toast-ui-error`, `.toast-ui-info`, `.toast-ui-warning` (per ui-kit Toasts; min-height 2.75rem for touch).
- **Checklist alignment:** All "Basic Components" and "Layout & Containers" and "Modals & Overlays" and "Navigation" and "States & Feedback" items that are marked implemented in ui-kit should be implementable with these primitives.

---

## Phase 2: Layouts ✅ DONE

**Goal:** Convert each layout to ui-kit structure and tokens so all pages sit inside the new shell.

- **2.1 AppShell** ✅ — Replaced with `bg-base`, `glass-surface` header, `border-surface-border`, `text-text-main`/`text-text-muted`, role badge `bg-primary-100 text-primary-600`, `btn-ui-tonal` + `touch-target` (48px min). OfflineBanner, Toast, StatusFooter unchanged.
- **2.2 AdminLayout** ✅ — `bg-base`; mobile header glass-surface, touch-target menu; sidebar `glass-surface`, nav active `bg-primary-500 text-white`, hover `hover:bg-black/5 dark:hover:bg-primary-500/10`; main `ui-container`; drawer overlay unchanged.
- **2.3 MobileLayout** ✅ — `bg-base`, glass-surface header and bottom nav, 48px touch targets for avatar and nav links, `text-text-main`/`text-text-muted`, dropdown glass-surface.
- **2.4 DisplayLayout** ✅ — `bg-base`, header `bg-primary-500 text-white`, program/date/time with contrast.
- **2.5 StatusFooter** ✅ — `glass-surface`, `border-surface-border`, status pills with `success-bg`/`error-bg`, availability menu glass-surface, Resume `btn-ui-primary touch-target`; `.ui-container` responsive padding (1rem → 1.5rem 2rem on md+).

---

## Phase 3: Shared Components (Alphabetical)

Each item: replace Skeleton/custom classes with ui-kit tokens and patterns; keep behavior and props.

- **3.1 ActiveProgramCard** ✅ [resources/js/Components/Dashboard/ActiveProgramCard.svelte](../resources/js/Components/Dashboard/ActiveProgramCard.svelte)  
  Glass-surface, gradient top accent, Live badge (success-bg/success-text), progress bars (primary/accent by track), View Full Details (btn-ui-outline, touch-friendly); responsive padding; priority track detection for accent bar.
- **3.2 ConfirmModal** ✅ — Done in Phase 1.5 (ui-kit buttons, modal-footer-ui).
- **3.3 Dashboard/HealthStats** ✅ [resources/js/Components/Dashboard/HealthStats.svelte](../resources/js/Components/Dashboard/HealthStats.svelte)  
  Glass-surface cards, text-text-muted titles, theme-colored values (primary-500, warning-text, success-text, text-main), icon boxes (primary-100, warning-bg, success-bg, neutral); responsive grid 1/2/4 cols.
- **3.4 Dashboard/QuickActions** ✅ [resources/js/Components/Dashboard/QuickActions.svelte](../resources/js/Components/Dashboard/QuickActions.svelte)  
  Glass-surface, section labels (text-text-muted uppercase), btn-ui-tonal links (min-height 2.75rem/48px), Staff Online card with primary icon box.
- **3.5 Dashboard/StationStatusTable** ✅ [resources/js/Components/Dashboard/StationStatusTable.svelte](../resources/js/Components/Dashboard/StationStatusTable.svelte)  
  Glass-surface, table-ui/table-ui-zebra, table-ui-toolbar, sticky thead, badge-ui-success/badge-ui-info, empty-state-ui; theme tokens for dots and text.
- **3.6 FlowDiagram** ✅ [resources/js/Components/FlowDiagram.svelte](../resources/js/Components/FlowDiagram.svelte)  
  Rounded-xl border-surface-border bg-surface; color dot (custom or primary-500/warning-text by track name); Default badge primary-100/primary-500/20 text-primary-600; step chips bg-black/5 dark:bg-primary-500/10 text-text-main; arrows text-text-muted; min-h step row.
- **3.7 OfflineBanner** ✅ [resources/js/Components/OfflineBanner.svelte](../resources/js/Components/OfflineBanner.svelte)  
  Left-accent warning banner (bg-surface, border-l-4 border-l-warning-text), ping dot, aria-live, touch-friendly Retry button placeholder.
- **3.8 Modal** ✅ — Done in Phase 1.5 (glass-surface, modal-panel-ui, modal-close-btn-ui).
- **3.9 QrScanner** ✅ [resources/js/Components/QrScanner.svelte](../resources/js/Components/QrScanner.svelte)  
  label-ui, select-ui (min-h 2.75rem), bg-surface border-surface-border rounded-xl; btn-ui-primary / btn-ui-tonal for file actions; alert-ui-error, alert-ui-banner-warning; text-text-main/text-text-muted.
- **3.10 Toast** ✅ [resources/js/Components/Toast.svelte](../resources/js/Components/Toast.svelte)  
  toast-ui + toast-ui-success/error/info, fixed top-right (full-width on mobile), 48px dismiss for error; aria-live.
- **3.11 UserAvatar** ✅ [resources/js/Components/UserAvatar.svelte](../resources/js/Components/UserAvatar.svelte)  
  Fallback: bg-surface border-surface-border text-text-muted; initials use name-derived hue or var(--brand-500); sizes sm/md/lg; role="img" and aria-label.
- **3.12 ProgramDiagram nodes and edges** ✅  
  Surface and border tokens only. DiagramCanvas: rounded-xl bg-surface border-surface-border, aside bg-black/[0.02], text-text-muted/text-text-main, drag items hover:bg-black/5. DiagramFlowContent: same tokens, btn-ui-tonal/btn-ui-primary, fullscreen panel bg-surface border-surface-border. DottedFlowEdge: fallback color var(--brand-500). Nodes: ShapeNode, TextNode, ProcessHandleNode (border-dashed border-surface-border bg-surface), StationGroupNode (border-surface-border, text-text-main/text-text-muted), ProcessNode/TrackNode/StationNode/StaffNode (border-surface-border or primary-500/50, bg black/5 or primary-100, orphan: error-bg/error-text), ImageNode, ClientSeatNode (border-surface-border, bg black/5, text-text-main).

---

## Phase 4: Pages by Area

For each page, sub-tasks: (1) use correct layout (already converted in Phase 2); (2) replace any remaining Skeleton/legacy classes with ui-kit; (3) use shared components already converted in Phase 3; (4) match ui-kit patterns for that page type where defined.

- **4.1 Auth & Welcome**
  - **Auth/Login** ✅ [resources/js/Pages/Auth/Login.svelte](../resources/js/Pages/Auth/Login.svelte): main bg-base; card → glass-surface rounded-xl border-surface-border; title text-primary-500, subtitle text-text-muted; error alert-ui-banner alert-ui-banner-error; form form-group-ui, label-ui, input-ui min-h-[2.75rem], input-ui-error + error-ui for validation; submit btn-ui-primary w-full min-h-[2.75rem]; responsive p-6 md:p-8.
  - **Welcome** [resources/js/Pages/Welcome.svelte](../resources/js/Pages/Welcome.svelte): Hero and CTAs with ui-kit buttons and typography.
- **4.2 Admin**
  - **Admin/Dashboard** ✅ [resources/js/Pages/Admin/Dashboard.svelte](../resources/js/Pages/Admin/Dashboard.svelte): ui-container, header text-text-main/text-text-muted; Refresh btn-ui-primary min-h-[2.75rem], spinner-ui; error alert-ui-banner alert-ui-banner-error; loading empty-state-ui + spinner-ui-lg; HealthStats, ActiveProgramCard, StationStatusTable, QuickActions from Phase 3.
  - **Admin/Programs/Index** ✅ [resources/js/Pages/Admin/Programs/Index.svelte](../resources/js/Pages/Admin/Programs/Index.svelte): Page header (text-text-main/text-text-muted), btn-ui-tonal/btn-ui-primary; error alert-ui-banner alert-ui-banner-error; empty-state-ui + glass-surface; program cards glass-surface, badge-ui-success/warning + neutral Inactive; card footer border-surface-border, btn-ui-tonal/btn-ui-primary/btn-ui-ghost-danger, btn-ui-icon for Edit/Delete (48px touch); Create/Edit modals form-group-ui, label-ui, input-ui, textarea-ui, modal-footer-ui, btn-ui-outline/btn-ui-primary (min-h 2.75rem).
  - **Admin/Programs/Show** ✅ [resources/js/Pages/Admin/Programs/Show.svelte](../resources/js/Pages/Admin/Programs/Show.svelte): Back link btn-ui-tonal; header text-text-main/text-text-muted; Live/Inactive badges (badge-ui-success, neutral); status banners alert-ui-banner-success/warning + glass-surface for Ready; btn-ui-primary, btn-ui-warning, btn-ui-ghost-danger (min-h 2.75rem); tabs-ui + tabs-ui-btn/tabs-ui-btn-active, hide-scrollbar-ui, scroll arrows; error alert-ui-banner-error; overview stat cards glass-surface; table-ui table-ui-zebra; form-group-ui, label-ui, input-ui, select-ui, textarea-ui; spinner-ui/spinner-ui-lg; process/station/track cards glass-surface, badge-ui-success + neutral; btn-ui-icon, btn-ui-ghost-danger, btn-ui-outline; alert-ui-banner-warning for warnings; modal forms input-ui/select-ui, modal-footer-ui; toggle theme tokens; code bg-black/10.
  - **Admin/ProgramDefaultSettings** [resources/js/Pages/Admin/ProgramDefaultSettings.svelte](../resources/js/Pages/Admin/ProgramDefaultSettings.svelte): Form controls and layout.
  - **Admin/Tokens/Index** [resources/js/Pages/Admin/Tokens/Index.svelte](../resources/js/Pages/Admin/Tokens/Index.svelte): Table, checkboxes, bulk actions, status badges.
  - **Admin/Tokens/Print** [resources/js/Pages/Admin/Tokens/Print.svelte](../resources/js/Pages/Admin/Tokens/Print.svelte): Print-specific layout; use ui-kit tokens for non-print styles.
  - **Admin/Users/Index** [resources/js/Pages/Admin/Users/Index.svelte](../resources/js/Pages/Admin/Users/Index.svelte): Table, role badges, actions.
  - **Admin/Reports/Index** [resources/js/Pages/Admin/Reports/Index.svelte](../resources/js/Pages/Admin/Reports/Index.svelte): Filters, table or cards, export buttons.
- **4.3 Staff / Mobile**
  - **Staff/Dashboard** [resources/js/Pages/Staff/Dashboard.svelte](../resources/js/Pages/Staff/Dashboard.svelte): Same as admin dashboard pattern with ui-kit cards and links.
  - **Triage/Index** ✅ [resources/js/Pages/Triage/Index.svelte](../resources/js/Pages/Triage/Index.svelte): Page text-text-main/text-text-muted; no-program glass-surface; glass-surface cards; Scan CTA btn-ui-primary 48px; input-ui, btn-ui-primary Look up; error alert-ui-banner-error, btn-ui-tonal Try again; modal btn-ui-tonal Extend, btn-ui-outline Cancel; category/track form-group-ui, label-ui, btn-ui-primary/btn-ui-tonal category pills, select-ui; Confirm/Cancel btn-ui-primary, btn-ui-outline min-h-[2.75rem]; touch-friendly throughout.
  - **Triage/PublicStart** ✅ [resources/js/Pages/Triage/PublicStart.svelte](../resources/js/Pages/Triage/PublicStart.svelte): Page text-text-main, px-4 md:px-0; not-allowed glass-surface rounded-xl, btn-ui-primary link; success alert-ui-banner-success, btn-ui-primary; Scan CTA rounded-xl border-primary-500/30 bg-primary-500/5, btn-ui-primary 48px; error text-error-text role=alert; modal btn-ui-tonal Extend, btn-ui-outline Cancel min-h-[2.75rem]; track card glass-surface, form-group-ui label-ui select-ui, btn-ui-outline/btn-ui-primary flex-wrap min-h-[2.75rem] min-w-[8rem]; touch-friendly throughout.
  - **Station/Index** [resources/js/Pages/Station/Index.svelte](../resources/js/Pages/Station/Index.svelte): Station board pattern (see 4.4), Call Next/No-Show/Force Complete buttons and modals.
  - **ProgramOverrides/Index** [resources/js/Pages/ProgramOverrides/Index.svelte](../resources/js/Pages/ProgramOverrides/Index.svelte): List and form controls.
- **4.4 Display (public)**
  - **Display/Board** [resources/js/Pages/Display/Board.svelte](../resources/js/Pages/Display/Board.svelte): Match "Queue Display Board" (ui-kit 717–734): program name, Now Calling cell, station cells, waiting count.
  - **Display/StationBoard** [resources/js/Pages/Display/StationBoard.svelte](../resources/js/Pages/Display/StationBoard.svelte): Match "Station Board" (ui-kit 656–686): Now Calling highlight, serving list.
  - **Display/Status** [resources/js/Pages/Display/Status.svelte](../resources/js/Pages/Display/Status.svelte): Status message and ui-kit typography/surfaces.
- **4.5 Other**
  - **Profile/Index** [resources/js/Pages/Profile/Index.svelte](../resources/js/Pages/Profile/Index.svelte): Profile card (avatar, name, email, Edit) per ui-kit "Profile Card Summary"; form sections.
  - **BroadcastTest** [resources/js/Pages/BroadcastTest.svelte](../resources/js/Pages/BroadcastTest.svelte): Buttons and feedback with ui-kit.

---

## Phase 5: Cleanup and Checklist (Revised: No Skeleton Removal)

- **5.1** ~~Remove all remaining references to Skeleton classes~~ **Cancelled.** Keep Skeleton classes (`btn`, `card`, `preset-*`, etc.); ensure they are themed via flexiqueue theme.
- **5.2** Refactor [resources/css/themes/flexiqueue.css](../resources/css/themes/flexiqueue.css): define Skeleton theme variables to use ui-kit tokens (`--brand-*`, `--bg-base`, `--bg-surface`, status colors); keep app-specific utilities; do not delete Skeleton theme structure.
- **5.3** Keep [resources/views/app.blade.php](../resources/views/app.blade.php): `data-theme="flexiqueue"` on `<html>`; add `.dark` for dark mode if desired; ensure root has correct class for CSS vars.
- **5.4** Sync [docs/FLEXIQUEUE-UI-COMPONENT-CHECKLIST.md](FLEXIQUEUE-UI-COMPONENT-CHECKLIST.md): mark items as implemented via Skeleton + theme or primitives; add "not implemented" notes for deferred items.
- **5.5** Optional: Add a theme toggle (light/dark) in AppShell/AdminLayout and persist preference (e.g. localStorage).

---

## Module Coverage Summary

- **Layouts (5):** AppShell, AdminLayout, MobileLayout, DisplayLayout, StatusFooter.
- **Shared components (24):** Modal, ConfirmModal, Toast, OfflineBanner, UserAvatar, QrScanner; Dashboard (ActiveProgramCard, HealthStats, QuickActions, StationStatusTable); FlowDiagram; ProgramDiagram (DiagramCanvas, DiagramFlowContent, all nodes and edges).
- **Pages (20):** Welcome, Auth/Login; Admin (Dashboard, Programs/Index, Programs/Show, ProgramDefaultSettings, Tokens/Index, Tokens/Print, Users/Index, Reports/Index); Staff/Dashboard; Triage/Index, Triage/PublicStart; Station/Index; ProgramOverrides/Index; Display/Board, Display/StationBoard, Display/Status; Profile/Index; BroadcastTest.

Each appears under a phase with at least one concrete sub-task so implementers can tick off work.

---

## Implementation Notes

- **Order:** Execute Phase 0 and 1 first so that every layout and page conversion (Phases 2–4) has the new tokens and primitives available. Within Phase 4, order can be by route group (e.g. Admin, then Staff, then Display) to reduce context switching.
- **Testing:** After Phase 1, run existing E2E and feature tests; after each layout (Phase 2) and after each page (Phase 4), smoke-check that page and run relevant tests.
- **Reference:** Keep [public/ui-kit.html](../public/ui-kit.html) as the single source of truth for structure and class names; copy markup and class strings from there into Svelte components, adapting for Svelte (e.g. `class` attributes, bindings, slots).
- **Build permission error:** If `./vendor/bin/sail npm run build` fails with `EACCES: permission denied` on `public/build/`, the build dir was likely created by root. **Always use Sail for dev/build** (e.g. `./vendor/bin/sail npm run build`). Fix: on host run `sudo chown -R "$USER:$USER" public/build`, or from Sail run `./vendor/bin/sail exec laravel.test rm -rf /var/www/html/public/build` then build again.

---

## Optional: Diagram and Print

- **Diagram editor (ProgramDiagram)**  
  Diagram canvas and nodes are app-specific; Phase 3 only requires surface/border tokens. Full "Diagram & Editor UI" from the checklist can be a later iteration.
- **Print (Tokens/Print)**  
  Print styles (`@page`, cut lines, etc.) remain app-specific; use ui-kit tokens for on-screen parts and keep print CSS separate.

---

## Completeness verification (rescan)

- **Pages (20):** All covered in Phase 4 — Auth/Login, Welcome; Admin (Dashboard, Programs/Index, Programs/Show, ProgramDefaultSettings, Tokens/Index, Tokens/Print, Users/Index, Reports/Index); Staff/Dashboard; Triage/Index, Triage/PublicStart; Station/Index; ProgramOverrides/Index; Display/Board, Display/StationBoard, Display/Status; Profile/Index; BroadcastTest. Routes in web.php and controllers map 1:1 to these pages.
- **Layouts (5):** All covered in Phase 2 — AppShell, AdminLayout, MobileLayout, DisplayLayout, StatusFooter.
- **Components (24):** All covered in Phase 3 — Modal, ConfirmModal, Toast, OfflineBanner, UserAvatar, QrScanner; Dashboard (ActiveProgramCard, HealthStats, QuickActions, StationStatusTable); FlowDiagram; ProgramDiagram (13 files as listed in 3.12).
- **CSS:** app.css (Phase 0–1), themes/flexiqueue.css (Phase 5.2). Custom utilities in flexiqueue.css (e.g. stat-title, stat-value, stat-desc, stat-figure) are replaced by ui-kit Health Stats structure in Phase 3.3 and removed in Phase 5.2.
- **Root view:** app.blade.php (Phase 5.3). No other Blade views render Inertia UI.
