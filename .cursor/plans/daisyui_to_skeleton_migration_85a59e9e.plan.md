---
name: DaisyUI to Skeleton migration
overview: "Replace DaisyUI 5 with Skeleton UI across the FlexiQueue frontend: update dependencies and CSS, define a custom Skeleton theme from existing design tokens, then migrate all Svelte pages and shared components in a phased order (foundation, layouts, Auth/Display/Triage/Station/Admin)."
todos: []
isProject: false
---

# DaisyUI to Skeleton UI migration plan

## Current state

- **DaisyUI** is used in 26 Svelte files (layouts, pages, components) via semantic classes (`btn`, `card`, `modal`, `badge`, `alert`, `input`, `select`, `navbar`, `dropdown`, `toggle`, `drawer`, etc.) and theme tokens (`bg-base-100`, `border-base-300`, `text-primary`, etc.).
- Theme is defined in [resources/css/app.css](resources/css/app.css) with `@plugin "daisyui"` and a custom "flexiqueue" theme (OKLCH colors per [docs/architecture/07-UI-UX-SPECS.md](docs/architecture/07-UI-UX-SPECS.md)).
- Shared UI: [resources/js/Components/Modal.svelte](resources/js/Components/Modal.svelte), [resources/js/Components/ConfirmModal.svelte](resources/js/Components/ConfirmModal.svelte), [resources/js/Components/Toast.svelte](resources/js/Components/Toast.svelte), [resources/js/Components/OfflineBanner.svelte](resources/js/Components/OfflineBanner.svelte) use DaisyUI classes.
- Root HTML is [resources/views/app.blade.php](resources/views/app.blade.php) (no `data-theme` today).

## Target state

- **Skeleton** as the only UI toolkit: Tailwind 4 + Skeleton CSS and (where applicable) Svelte components.
- Custom theme aligned with existing design tokens (primary #2563EB, accent gold, success/warning/error, base surfaces).
- All existing pages and layouts restyled with Skeleton; touch targets and accessibility preserved per 07-UI-UX-SPECS.

## Prerequisites and risks

- **Stack compatibility**: Project uses Vite 7, Svelte 5, Tailwind 4; Skeleton supports these. Confirm exact package names and import paths from Skeleton docs (`@skeletonlabs/skeleton`, `@skeletonlabs/skeleton-svelte` or current equivalents).
- **Integration**: Skeleton is built for Svelte + Vite; FlexiQueue adds Laravel + Inertia. No structural change to Inertia or resolve logic; only CSS and Svelte component usage.
- **Form binding**: Existing forms use `bind:value` on native `<input>`/`<select>`. If Skeleton exposes wrapper components (e.g. `<Input>`), ensure they support `bind:value` or keep native elements with Skeleton utility classes.
- **Modals**: Current modals use native `<dialog>` + DaisyUI classes. Skeleton may provide a Modal/Dialog component; [resources/js/Components/Modal.svelte](resources/js/Components/Modal.svelte) should either use that or be restyled with Skeleton classes only.

---

## Phase 1: Foundation (deps, CSS, theme, one page spike)

**1.1 Dependencies**

- Remove: `daisyui` from [package.json](package.json).
- Add: Skeleton packages per official docs (e.g. `@skeletonlabs/skeleton`, `@skeletonlabs/skeleton-svelte` if still separate). Install with Sail: `./vendor/bin/sail npm install ...` / `npm uninstall daisyui`.
- No change to [vite.config.js](vite.config.js) unless Skeleton requires a specific plugin (Tailwind is already present).

**1.2 Global CSS and theme**

- In [resources/css/app.css](resources/css/app.css):
  - Remove all `@plugin "daisyui"` and `@plugin "daisyui/theme"` blocks.
  - Keep `@import 'tailwindcss'`, font imports, `@source` directives, and `@theme { --font-sans: ... }`.
  - Add Skeleton imports per docs (e.g. `@import '@skeletonlabs/skeleton'`, theme import).
  - Define a **custom Skeleton theme** that maps FlexiQueue tokens to Skeleton variables:
    - Primary, secondary, accent, neutral, base backgrounds, semantic (success, warning, error, info), and radii from 07-UI-UX-SPECS Section 2.
  - Preserve or adapt any Tailwind theme extensions (e.g. `--font-sans`) so Inter and layout remain correct.
- Set active theme on the root element: in [resources/views/app.blade.php](resources/views/app.blade.php), add `data-theme="flexiqueue"` (or the theme name chosen in Skeleton) on `<html>`.

**1.3 Component mapping reference**

Before touching pages, document the exact Skeleton equivalents (classes or components) for:

- Buttons: `btn`, `btn-primary`, `btn-ghost`, `btn-outline`, `btn-sm`, `btn-lg`, `btn-block`, `btn-circle`, `btn-square`, `btn-error`, `btn-warning`.
- Surfaces: `card`, `card-body`, `card-title`, `card-actions`; `modal`, `modal-box`, `modal-action`, `modal-backdrop`.
- Forms: `input`, `input-bordered`, `input-error`, `input-sm`; `select`, `select-bordered`, `select-sm`; `toggle`, `toggle-sm`, `toggle-primary`.
- Feedback: `alert`, `alert-error`, `alert-success`, `alert-warning`; `badge`, `badge-`* variants.
- Layout: `navbar`, `navbar-start`, `navbar-end`, `navbar-center`; `dropdown`, `dropdown-end`, `dropdown-content`, `menu`; `drawer`, `drawer-toggle`.

Use Skeleton docs and (if available) their theme generator to align names (e.g. Skeleton’s `.btn` vs DaisyUI’s). If Skeleton uses Svelte components (e.g. `<Button>`), decide whether to use components everywhere or only where they add value (e.g. accessibility); document the choice.

**1.4 Spike: single page**

- Migrate **one simple page** end-to-end (e.g. [resources/js/Pages/Welcome.svelte](resources/js/Pages/Welcome.svelte) or [resources/js/Pages/Auth/Login.svelte](resources/js/Pages/Auth/Login.svelte)) to Skeleton only. Goals:
  - Verify build and runtime (no DaisyUI, Skeleton styles applied).
  - Confirm theme (colors, radii) and font.
  - Validate buttons, card, and one form control if applicable.
  - Decide pattern for modals (Skeleton component vs native `<dialog>` + Skeleton classes).
- Fix any Vite/content/import issues and document gotchas (e.g. Skeleton’s Tailwind content paths if needed).

---

## Phase 2: Shared components and layouts

**2.1 Shared components**

- [resources/js/Components/Modal.svelte](resources/js/Components/Modal.svelte): Replace DaisyUI `modal`, `modal-box`, `modal-backdrop` and inner `btn` with Skeleton modal/dialog pattern. Keep the same public API (`open`, `title`, `onClose`, `children`) and `showModal()`/`close()` behavior.
- [resources/js/Components/ConfirmModal.svelte](resources/js/Components/ConfirmModal.svelte): Replace `modal-action` and `btn`/`btn-ghost`/`btn-error`/`btn-warning`/`btn-primary` with Skeleton; keep `loading loading-spinner` equivalent (Skeleton or custom).
- [resources/js/Components/Toast.svelte](resources/js/Components/Toast.svelte): Restyle with Skeleton (e.g. notification/banner classes).
- [resources/js/Components/OfflineBanner.svelte](resources/js/Components/OfflineBanner.svelte): Replace any DaisyUI alert/card classes with Skeleton.
- [resources/js/Components/FlowDiagram.svelte](resources/js/Components/FlowDiagram.svelte): Replace any `badge`/DaisyUI tokens with Skeleton.

**2.2 Layouts**

- [resources/js/Layouts/MobileLayout.svelte](resources/js/Layouts/MobileLayout.svelte): `navbar`, `navbar-start`, `navbar-end`, `btn`, `badge`, `dropdown`, `dropdown-content`, `menu`, `bg-base-`*, `border-base-300`, `text-primary`, etc. Replace with Skeleton navbar/header and menu/dropdown. Preserve 48px touch targets and structure.
- [resources/js/Layouts/AdminLayout.svelte](resources/js/Layouts/AdminLayout.svelte): `drawer-toggle`, `navbar`, `btn`, `badge`, sidebar/menu. Replace with Skeleton drawer/sidebar and nav.
- [resources/js/Layouts/AppShell.svelte](resources/js/Layouts/AppShell.svelte): Any DaisyUI classes to Skeleton.
- [resources/js/Layouts/DisplayLayout.svelte](resources/js/Layouts/DisplayLayout.svelte): Same.
- [resources/js/Layouts/StatusFooter.svelte](resources/js/Layouts/StatusFooter.svelte): Same.

---

## Phase 3: Pages by route group

Migrate each page to Skeleton-only; preserve behavior and Inertia/useForm usage.

**3.1 Auth and welcome**

- [resources/js/Pages/Auth/Login.svelte](resources/js/Pages/Auth/Login.svelte): Card, alert, input, btn.
- [resources/js/Pages/Welcome.svelte](resources/js/Pages/Welcome.svelte): Card, buttons.

**3.2 Display**

- [resources/js/Pages/Display/Status.svelte](resources/js/Pages/Display/Status.svelte): Alert, cards, badges, btn.
- [resources/js/Pages/Display/Board.svelte](resources/js/Pages/Display/Board.svelte): All DaisyUI usage.

**3.3 Triage and Station**

- [resources/js/Pages/Triage/Index.svelte](resources/js/Pages/Triage/Index.svelte): Buttons, input, select, alerts, category/track UI.
- [resources/js/Pages/Station/Index.svelte](resources/js/Pages/Station/Index.svelte): Heavy use of btn, badge, alert, modal, toggle, select, input, join-item; multiple dialogs. Migrate in logical blocks (main station UI, then each modal).

**3.4 Track overrides and profile**

- [resources/js/Pages/TrackOverrides/Index.svelte](resources/js/Pages/TrackOverrides/Index.svelte).
- [resources/js/Pages/Profile/Index.svelte](resources/js/Pages/Profile/Index.svelte).

**3.5 Admin**

- [resources/js/Pages/Admin/Dashboard.svelte](resources/js/Pages/Admin/Dashboard.svelte).
- [resources/js/Pages/Admin/Programs/Index.svelte](resources/js/Pages/Admin/Programs/Index.svelte).
- [resources/js/Pages/Admin/Programs/Show.svelte](resources/js/Pages/Admin/Programs/Show.svelte): Alerts, badges, buttons, modals.
- [resources/js/Pages/Admin/Users/Index.svelte](resources/js/Pages/Admin/Users/Index.svelte).
- [resources/js/Pages/Admin/Reports/Index.svelte](resources/js/Pages/Admin/Reports/Index.svelte): Filters card, selects, inputs, buttons, badges, table.
- [resources/js/Pages/Admin/Tokens/Index.svelte](resources/js/Pages/Admin/Tokens/Index.svelte).
- [resources/js/Pages/Admin/Tokens/Print.svelte](resources/js/Pages/Admin/Tokens/Print.svelte).

**3.6 Other**

- [resources/js/Pages/BroadcastTest.svelte](resources/js/Pages/BroadcastTest.svelte).

---

## Phase 4: Docs, dev reference, and rules

**4.1 Documentation**

- [docs/architecture/07-UI-UX-SPECS.md](docs/architecture/07-UI-UX-SPECS.md): Replace DaisyUI sections with Skeleton: component library choice, installation, theme definition (link to app.css and Skeleton theme vars), conventions (use Skeleton classes/components first, override with Tailwind when needed). Update design references (Skeleton docs, theme generator if any). Keep touch targets, layout, and font (Inter) requirements.

**4.2 Dev reference**

- [public/dev/components.html](public/dev/components.html): Replace DaisyUI CDN with a Skeleton-based component showcase (or remove if Skeleton provides a living style guide elsewhere). Ensure it uses the same `data-theme` so the FlexiQueue theme is visible.

**4.3 Cursor and stack**

- [.cursor/rules/stack-conventions.mdc](.cursor/rules/stack-conventions.mdc): Update "Components" row from DaisyUI 5 to Skeleton; remove anti-pattern "DaisyUI in tailwind.config.js"; add any Skeleton-specific rule (e.g. "Use Skeleton theme in app.css, not in tailwind.config"). Update "Component System" bullet to reference Skeleton and the updated 07-UI-UX-SPECS.

**4.4 Beads / backlog**

- Create beads (or a single parent bead) for "Migrate DaisyUI to Skeleton" and sub-tasks per phase so progress is trackable. Optionally add a dependency from Phase 2 to Phase 1 and Phase 3 to Phase 2.

---

## Phase 5: Quality and cleanup

- **Search**: Run a project-wide grep for `daisyui`, `btn` , `card` , `modal` , `badge` , `alert` , `navbar`, `dropdown`, `base-100`, `base-200`, `base-300`, `base-content`, `primary-content` and fix any remaining references (e.g. in comments or missed files).
- **Tests**: Run PHPUnit and Playwright; fix any snapshots or selectors that depended on DaisyUI class names or DOM structure.
- **Visual pass**: Smoke-test Auth, Triage, Station, Display, Admin (list/detail), and mobile layout (drawer, navbar, footer). Confirm 48px touch targets and contrast.

---

## Suggested execution order

```mermaid
flowchart LR
  subgraph P1 [Phase 1 Foundation]
    A[Deps and CSS]
    B[Theme]
    C[Spike page]
    A --> B --> C
  end
  subgraph P2 [Phase 2 Shared]
    D[Modal ConfirmModal Toast OfflineBanner FlowDiagram]
    E[Layouts]
    D --> E
  end
  subgraph P3 [Phase 3 Pages]
    F[Auth Welcome]
    G[Display Triage Station]
    H[Admin Other]
    F --> G --> H
  end
  P1 --> P2 --> P3
  P2 --> P4 [Phase 4 Docs and rules]
  P3 --> P4
  P4 --> P5 [Phase 5 Quality]
```



- Do Phase 1 fully (including spike) before changing shared components and layouts.
- Phase 2 unblocks consistent look for all Phase 3 pages; do layouts after shared components so Modal/ConfirmModal are stable.
- Phase 3 can be parallelized by file after layouts are done; Station and Admin have the most usage.
- Phase 4 and 5 after bulk of UI migration.

---

## Files to touch (summary)


| Area              | Files                                                                                                                                                                                                                                                                           |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Config / global   | [package.json](package.json), [resources/css/app.css](resources/css/app.css), [resources/views/app.blade.php](resources/views/app.blade.php)                                                                                                                                    |
| Shared components | Modal.svelte, ConfirmModal.svelte, Toast.svelte, OfflineBanner.svelte, FlowDiagram.svelte                                                                                                                                                                                       |
| Layouts           | MobileLayout.svelte, AdminLayout.svelte, AppShell.svelte, DisplayLayout.svelte, StatusFooter.svelte                                                                                                                                                                             |
| Pages             | Auth/Login, Welcome, Display/Status, Display/Board, Triage/Index, Station/Index, TrackOverrides/Index, Profile/Index, Admin/Dashboard, Admin/Programs/Index, Admin/Programs/Show, Admin/Users/Index, Admin/Reports/Index, Admin/Tokens/Index, Admin/Tokens/Print, BroadcastTest |
| Docs and rules    | 07-UI-UX-SPECS.md, stack-conventions.mdc, public/dev/components.html                                                                                                                                                                                                            |


No backend or route changes are required; the migration is frontend-only.