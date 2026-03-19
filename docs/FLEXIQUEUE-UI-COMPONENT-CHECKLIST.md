# FlexiQueue UI Component Checklist

This document tracks UI components and patterns for the FlexiQueue design system. Items marked with `[x]` are implemented in **`public/ui-kit.html`**. Items marked with `[ ]` are used in the app and/or candidates for the UI kit. Use the UI kit as the reference for implemented patterns.

---

## Foundational Elements

- [x] **Typography System** — Outfit font, heading hierarchy (H1–H3), body, caption; responsive sizing
- [x] **Color Palette CSS Variables** — Light/dark themes; base, surface, text, border; primary (brand); status (success, warning, error)
- [x] **Glassmorphism Theme Tokens** — `.glass-surface`, backdrop blur (dark), translucent surfaces
- [ ] **Spacing & Layout Tokens** — Consistent padding/margin scale (e.g. 4, 8, 12, 16, 24)
- [ ] **Shadow / Elevation Scale** — `elevation-card`, `elevation-raised`, `elevation-modal` (theme tokens)
- [ ] **Motion & Animation** — Duration tokens, ease curves, reduced-motion preference
- [ ] **Radius Tokens** — `rounded-container`, `radius-base` (theme)

---

## Basic Components

### Buttons & Actions

- [x] **Buttons** — Primary, Tonal/Secondary, Outline, Ghost/Danger
- [x] **Icon Buttons** — Small icon-only rounded (e.g. settings)
- [ ] **Button presets** — `preset-filled-primary-500`, `preset-tonal`, `preset-outlined`, `preset-filled-warning-500`, `preset-filled-error-500`
- [ ] **Button sizes** — xs, sm, md, lg with min touch target (48px for mobile)
- [ ] **Disabled state** — All button variants
- [ ] **Loading state** — Button with spinner (e.g. submit, "Processing…")
- [ ] **Link-styled buttons** — `<a class="btn preset-filled-primary-500">` (e.g. PublicStart)
- [ ] **Ghost icon button** — `btn btn-ghost btn-icon` (e.g. display audio, close)

### Badges & Labels

- [x] **Badges / Status Chips** — Success, Warning, Error, Info (with dot/icon)
- [ ] **Neutral / default badge** — Gray or muted; `preset-tonal text-surface-600`
- [ ] **Badge sizes** — sm, md; `text-xs uppercase tracking-wider font-semibold px-2.5 py-1`
- [ ] **Pill badge** — Fully rounded
- [ ] **Category/track chip** — `preset-filled-primary-500/20`, `preset-outlined` (Station, Triage)

### Form Controls

- [x] **Text Inputs** — Standard; with leading icon (search); with trailing icon (token)
- [x] **Select Dropdowns** — Native select styled with theme
- [x] **Checkboxes & Radio Buttons** — Styled with primary focus ring
- [x] **Toggle Switches** — CSS-only toggle (track + knob, peer-checked); `appearance-none` custom track
- [x] **Textareas** — Multi-line with resize, min-height, focus ring
- [ ] **Form control group** — `form-control w-full`, `label`, `label-text` (DaisyUI-style wrapper)
- [ ] **Input error state** — `border-error-500 bg-error-50`, error message below, `aria-invalid`, `aria-describedby`
- [ ] **Input disabled state** — Muted, not editable
- [ ] **Password input** — type="password", autocomplete
- [ ] **Number input** — Spinner or plain, min/max
- [ ] **Date / time input** — Native or custom picker
- [ ] **Range slider** — Min/max, step
- [ ] **File upload** — Drop zone or file input styled
- [ ] **Checkbox indeterminate** — Select-all in tables (e.g. Admin Tokens)
- [ ] **Label as cursor-pointer** — `label cursor-pointer gap-2` for toggle/checkbox

### Links & Inline Text

- [ ] **Primary link** — Underline or color only, hover state
- [ ] **Muted / secondary link** — For less emphasis
- [ ] **Inline code** — Monospace, background; token IDs `font-mono text-primary-500`
- [ ] **Kbd / shortcut** — Keyboard key styling
- [ ] **Line clamp** — `line-clamp-2` for card titles/descriptions

---

## Layout & Containers

- [x] **Basic Cards** — Glass surface, elevation, rounded corners, borders; data widget, action card, profile summary
- [x] **Data Tables** — Responsive wrapper, sticky header area, hover rows, status badges, action column
- [ ] **Table container** — Theme class `.table-container` (rounded, elevation, sticky thead)
- [ ] **Table zebra** — `.table-zebra` striped rows
- [ ] **Table: sortable headers** — Sort indicator, click to sort
- [ ] **Table: row selection** — Checkbox column, select all
- [ ] **Table: empty state** — No rows message
- [ ] **List group** — Bordered list of items (e.g. nav list, result list)
- [ ] **Divider** — Horizontal or vertical rule
- [ ] **Container / max-width** — Page content width (e.g. max-w-7xl)

### Modals & Overlays

- [x] **Modal Dialogs** — Backdrop overlay, blur, animation (fade-in-up), action footer (Cancel / primary)
- [ ] **Native `<dialog>` modal** — `modal-dialog-center`, backdrop, explicit close (no click-outside); `elevation-modal`
- [ ] **Modal: sizes** — sm, md, lg, wide (e.g. scanner), full-screen
- [ ] **Modal: scrollable body** — Long content with fixed header/footer; `max-h-[90vh] overflow-y-auto`
- [ ] **Confirm / destructive modal** — ConfirmModal: danger, warning, neutral variants; loading state
- [ ] **Drawer / slide-over** — Admin sidebar (checkbox-driven, `peer-checked:translate-x-0`), mobile nav

### Navigation

- [x] **Sidebar Navigation** — Sample nav with active state (Dashboard, Programs, Tokens, Users, Reports)
- [x] **Top Header / App Bar** — Logo, menu trigger (mobile), role badge, Log out
- [ ] **Back / return link** — "Back to Programs" style; `btn preset-tonal btn-sm` + arrow icon
- [ ] **Breadcrumbs** — Path: Home > Section > Page
- [ ] **Tabs** — Horizontal tabs, active indicator; `role="tablist"`, `role="tab"`
- [ ] **Scrollable tab bar** — Overflow-x-auto, hidden scrollbar; left/right arrow buttons when scrollable
- [ ] **Sticky tab bar** — Sticky top on mobile (e.g. Program Show sections)
- [ ] **Pagination** — Previous / page numbers / Next
- [ ] **Bottom nav (mobile)** — Fixed bottom bar with icons

### States & Feedback

- [x] **Empty States** — Icon, heading, description, CTA button
- [x] **Loading States** — Spinners (sm/lg), skeleton placeholders (animated pulse)
- [ ] **Inline alert (error)** — `bg-error-100 text-error-900 border border-error-300 rounded-container p-4`; `role="alert"`
- [ ] **Inline alert (success)** — `bg-success-100 border border-success-300` (e.g. PublicStart "You're in the queue")
- [ ] **Status banner (left-accent)** — `border-l-4 border-l-success-500` / `border-l-warning-500`; session live/paused/inactive
- [ ] **Error state (page/section)** — Error message + retry CTA
- [ ] **Offline / connection lost** — Banner or inline message
- [ ] **Success feedback** — Inline success message (e.g. "Saved") or toast
- [ ] **Countdown / timer display** — "Closing in Xs" (Triage scanner, Display status); `aria-live="polite"`

---

## App-Specific Complex Components

- [x] **Active Program Card** — Dashboard stats with animated progress bars (Regular / Priority track), Live badge
- [x] **Station Board Display** — "Now Calling" emphasis, VIP highlight, serving list (high-constraint typography)
- [x] **Triage Scanner Input** — Large CTA (Scan or Enter Token ID), camera icon, manual entry field + Look up
- [x] **Health Stats Row** — Dashboard KPI cards (Active Sessions, Queue Waiting, Stations Online, Completed Today) with icons
- [x] **Queue Display Board** — Public TV-style: program name, Now Calling cell, station cells, waiting count
- [x] **Toasts / Notifications** — Success, Error, Info (floating snackbar-style with icon)
- [x] **Flow Diagram Nodes** — Track container (color dot, name, Default badge), step chips with arrows (→)
- [ ] **User Avatar / Initials** — UserAvatar: circle, initials or image, sizes (sm/md/lg), fallback icon
- [ ] **Status Footer** — Mobile bottom bar: network icon, availability drop-up, queue count, processed, clock
- [ ] **Offline Banner** — Full-width fixed top; `bg-warning-100 text-warning-900 border-b`; role="alert"
- [ ] **QR Scanner modal** — Camera view, cancel, extend countdown, success state
- [ ] **Station "Call Next" / "No-Show" / "Force Complete"** — Button set and modal flows (Serve, No-Show, Override, Force Complete, Cancel)
- [ ] **Program diagram (full)** — DiagramCanvas, nodes (Process, Station, Track, Shape, Text, Image, etc.), edges; draggable, publish
- [ ] **Token alias display** — Large monospace alias (e.g. A-104) for station/display; `text-4xl font-bold text-primary-500 tabular-nums`
- [ ] **Staff availability bar** — Profile row with status dot (available / on_break / away)
- [ ] **Drop-up / dropdown menu** — Availability selector (Available, On break, Away); click-outside + Escape to close
- [ ] **On-break full-screen overlay** — Overlay with Resume button (StatusFooter)
- [ ] **Display / public layout header** — Primary bar: FlexiQueue, program name (center), date + live time (right)
- [ ] **Segmented control / button group** — Single selection (e.g. Triage category buttons: preset-filled when selected, preset-tonal when not)
- [ ] **Station switcher** — Horizontal scroll of pill buttons (`overflow-x-auto`, `shrink-0`); multi-station
- [ ] **Details / Summary** — `<details><summary>` expandable section (e.g. Station notes, "More details" in Program Show)
- [ ] **Filter toggle / expandable filters** — Collapsible filter section (e.g. Reports)
- [ ] **QR code display** — Image or data URI (Token Print, Profile preset QR)
- [ ] **Hidden barcode input** — `sr-only` input for HID hardware scanner; refocus interval for continuous scan
- [ ] **Stat block** — Theme utilities: `stat`, `stat-title`, `stat-value`, `stat-desc`, `stat-figure` (HealthStats, KPI)
- [ ] **Print-specific layout** — Token Print: `@page` size, print-sheet grid, print-card, cut lines, show/hide instructions

---

## Diagram & Editor UI

- [ ] **Diagram canvas** — ProgramDiagram: pannable/zoomable canvas
- [ ] **Diagram nodes** — ProcessNode, StationNode, TrackNode, ShapeNode, TextNode, ImageNode, etc.
- [ ] **Diagram edges** — DottedFlowEdge, connection lines
- [ ] **Diagram toolbar** — Add node, publish, etc. (DiagramFlowContent)

---

## Utilities & Global

- [x] **Theme Switcher** — Toggle between light and dark (fixed top-right, demo only)
- [ ] **Focus visible** — Consistent focus ring (e.g. 2px primary offset); `focus:ring-2 focus:ring-primary-500`
- [ ] **Screen reader only** — `.sr-only` for labels and hints (e.g. barcode input, "Manage Program")
- [ ] **Print styles** — Hide nav, simplify layout; `@page`; print-sheet for token cards
- [ ] **Custom scrollbar** — Themed scrollbar (e.g. thin, rounded); hide scrollbar on tab strip `[scrollbar-width:none]`
- [ ] **Join (button group)** — Horizontal join for grouped buttons (theme utility)

---

## Accessibility & UX Notes

- [ ] **Touch targets** — Minimum 48×48px for interactive elements on mobile (per 07-UI-UX-SPECS); `min-h-[48px] min-w-[48px]`
- [ ] **Color contrast** — Text/background meets WCAG AA where required
- [ ] **Reduced motion** — Respect `prefers-reduced-motion` for animations
- [ ] **Form labels** — Every input has an associated label (visible or sr-only)
- [ ] **Error announcements** — Errors associated with fields and announced to screen readers; `aria-invalid`, `aria-describedby`, `id` on error span
- [ ] **Live regions** — `aria-live="polite"` for countdown, dynamic status
- [ ] **aria-expanded** — For filters, availability menu, details/summary

---

## Reference

- **UI Kit file:** `public/ui-kit.html` — open in browser for full component preview.
- **App theme:** `resources/css/themes/flexiqueue.css` (Skeleton UI); UI kit uses same design tokens where applicable (CSS variables).
- **Legacy reference:** `public/dev/components.html` (DaisyUI-based) for historical patterns; prefer `ui-kit.html` for new work.
