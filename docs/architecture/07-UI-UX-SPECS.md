# FlexiQueue — Phase 1 UI/UX Specifications

**Framework:** Svelte 5 + Inertia.js (via Laravel)
**Styling:** TailwindCSS 4 + DaisyUI 5
**Layout:** Mobile-first (375px primary), desktop for admin (1440px)
**Font:** Inter, sans-serif

---

## 1. Component Library: DaisyUI 5

FlexiQueue uses [DaisyUI 5](https://daisyui.com/) as its component library on top of TailwindCSS 4. DaisyUI provides **65 semantic CSS components** (buttons, cards, modals, tables, etc.) with zero JavaScript dependencies.

### 1.1 Why DaisyUI

- **Consistent design system** — Semantic class names (`btn`, `card`, `modal`, `badge`) instead of raw utility-heavy HTML.
- **Accessible by default** — Built with ARIA patterns and keyboard navigation.
- **Custom theming** — Full control over colors, border radius, and sizing via CSS variables (OKLCH color space).
- **TailwindCSS 4 native** — Imports via `@plugin "daisyui"`, no config file needed.
- **Zero JS** — Pure CSS components; no runtime overhead. Svelte handles all interactivity.
- **65 components** — Covers ~80% of FlexiQueue's UI needs out of the box.

### 1.2 Installation

In `resources/css/app.css`:

```css
@import "tailwindcss";
@plugin "daisyui";
```

Package install (during BD-001):

```bash
npm install -D daisyui@latest
```

### 1.3 Convention

- **Use DaisyUI semantic classes first** — e.g., `btn btn-primary` not `bg-blue-600 text-white py-4 px-6 rounded-lg`.
- **Override with Tailwind utilities when needed** — e.g., `btn btn-primary h-20` for the 80px tall mobile buttons.
- **Never duplicate** what DaisyUI already provides. If DaisyUI has a `table`, `badge`, `modal`, use it.
- **Custom components** only for domain-specific UI that has no DaisyUI equivalent (QR scanner, flow diagrams).

---

## 2. Custom Theme: "flexiqueue"

DaisyUI supports custom themes via `@plugin "daisyui/theme"`. FlexiQueue defines one custom theme that maps our design palette to DaisyUI's semantic color system.

### 2.1 Color Mapping

| Design Token | Hex | DaisyUI Variable | Usage |
|---|---|---|---|
| Primary (blue) | `#2563EB` | `--color-primary` | Main CTAs, headers, links, active states |
| Primary content | `#FFFFFF` | `--color-primary-content` | Text on primary backgrounds |
| Success (green) | `#16A34A` | `--color-success` | Confirm actions, success toasts, "serving" status |
| Warning (orange) | `#EA580C` | `--color-warning` | Incomplete docs, caution states, attention badges |
| Error (red) | `#DC2626` | `--color-error` | Blocking errors, invalid sequence, destructive actions |
| Priority (gold) | `#F59E0B` | `--color-accent` | Priority clients, express lane indicators |
| Accent content | `#FFFFFF` | `--color-accent-content` | Text on gold/accent backgrounds |
| Gray | `#6B7280` | `--color-neutral` | Secondary text, borders, inactive elements |
| Neutral content | `#FFFFFF` | `--color-neutral-content` | Text on neutral backgrounds |
| Background | `#FFFFFF` | `--color-base-100` | Page background |
| Surface | `#F9FAFB` | `--color-base-200` | Cards, elevated surfaces |
| Border | `#E5E7EB` | `--color-base-300` | Borders, dividers |
| Text | `#111827` | `--color-base-content` | Primary text color |
| Info (blue-light) | `#3B82F6` | `--color-info` | Informational toasts, "in progress" status |

### 2.2 Theme Definition

Add to `resources/css/app.css` after the DaisyUI plugin import:

```css
@import "tailwindcss";
@plugin "daisyui" {
  themes: flexiqueue --default;
}

@plugin "daisyui/theme" {
  name: "flexiqueue";
  default: true;
  color-scheme: light;

  /* Primary: #2563EB */
  --color-primary: oklch(54.56% 0.2031 264.05);
  --color-primary-content: oklch(100% 0 0);

  /* Secondary: #4B5563 (gray-600, for secondary buttons) */
  --color-secondary: oklch(44.64% 0.0146 264.36);
  --color-secondary-content: oklch(100% 0 0);

  /* Accent: #F59E0B (gold, for priority/express) */
  --color-accent: oklch(76.18% 0.1617 75.37);
  --color-accent-content: oklch(100% 0 0);

  /* Neutral: #6B7280 */
  --color-neutral: oklch(51.06% 0.013 255.71);
  --color-neutral-content: oklch(100% 0 0);

  /* Base (backgrounds) */
  --color-base-100: oklch(100% 0 0);
  --color-base-200: oklch(98.5% 0.002 240);
  --color-base-300: oklch(92% 0.004 240);
  --color-base-content: oklch(14.57% 0.014 285.82);

  /* Semantic status colors */
  --color-info: oklch(60.59% 0.1843 261.36);
  --color-info-content: oklch(100% 0 0);
  --color-success: oklch(58.58% 0.1686 149.48);
  --color-success-content: oklch(100% 0 0);
  --color-warning: oklch(59.30% 0.2037 38.37);
  --color-warning-content: oklch(100% 0 0);
  --color-error: oklch(52.35% 0.2109 27.33);
  --color-error-content: oklch(100% 0 0);

  /* Border radius */
  --radius-selector: 0.5rem;
  --radius-field: 0.5rem;
  --radius-box: 0.75rem;

  /* Base sizing */
  --size-selector: 0.25rem;
  --size-field: 0.25rem;

  /* Border */
  --border: 1px;

  /* Effects */
  --depth: 1;
  --noise: 0;
}
```

> **Note:** OKLCH values are approximate conversions from the hex palette. Fine-tune using the [DaisyUI Theme Generator](https://daisyui.com/theme-generator/) before implementation.

---

## 3. Typography

| Purpose | Size | Weight | Example |
|---|---|---|---|
| Alias display | 72px (`text-7xl`) | Bold | "A1" on station/informant |
| Page heading (H1) | 36px (`text-4xl`) | Bold | Screen titles |
| Section heading (H2) | 24px (`text-2xl`) | Semibold | Section labels |
| Body text | 16px (`text-base`) | Normal | General content |
| Small / caption | 14px (`text-sm`) | Normal | Metadata, timestamps |
| Badge text | 12px (`text-xs`) | Medium | Status badges |

**Font stack:** `Inter, ui-sans-serif, system-ui, sans-serif`

Import Inter via Google Fonts or locally bundled (preferred for offline):

```html
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
```

For offline-first deployment, bundle Inter as a local asset instead.

---

## 4. Spacing & Layout

| Token | Value | Usage |
|---|---|---|
| Base grid | 4px | All spacing is a multiple of 4px |
| Card padding | 24px (`p-6`) | Cards, panels |
| Section gap | 16px (`gap-4`) | Between cards, rows |
| Page padding (mobile) | 16px (`p-4`) | Screen edges |
| Page padding (desktop) | 32px (`p-8`) | Admin screens |

---

## 5. Touch Targets & Button Sizing

FlexiQueue is a mobile-first queue system used by staff on phones. Large touch targets are critical.

| Element | Height | DaisyUI Class | Notes |
|---|---|---|---|
| Primary action button | 80px | `btn btn-primary h-20 text-lg` | "SEND TO CASHIER", "CALL NEXT" |
| Secondary action button | 48px | `btn btn-ghost h-12` | "Re-queue", "Override", "Cancel" |
| Category selection button | ~120px | `btn h-30 w-full` + custom | Triage category cards |
| Standard form button | 44px | `btn btn-primary` (default size) | Login, modal confirms |
| Icon button | 44x44px min | `btn btn-ghost btn-square` | Logout, menu toggles |

**WCAG minimum:** All interactive targets >= 44x44px.

---

## 6. Component Mapping: FlexiQueue to DaisyUI

This table maps every planned FlexiQueue component to its DaisyUI implementation.

### 6.1 Layout Components

| FlexiQueue Component | DaisyUI Components | Implementation Notes |
|---|---|---|
| `AppShell.svelte` | `navbar` + `footer` | Navbar for header, custom footer for status bar |
| `AdminLayout.svelte` | `drawer` + `menu` | Drawer sidebar (240px) with vertical menu items |
| `MobileLayout.svelte` | `navbar` + `dock` | Fixed navbar top, dock component for bottom bar |
| `DisplayLayout.svelte` | `navbar` (minimal) | Blue header only, no navigation, kiosk mode |

### 6.2 Data Display

| FlexiQueue Component | DaisyUI Components | Class Example |
|---|---|---|
| `StatusBadge.svelte` | `badge` | `badge badge-success`, `badge badge-warning`, `badge badge-info` |
| `CategoryBadge.svelte` | `badge` | `badge badge-accent` (gold for priority), `badge badge-warning` (incomplete) |
| `StatCard.svelte` | `stat` | `stats shadow` → `stat` → `stat-title`, `stat-value`, `stat-desc` |
| `DataTable.svelte` | `table` | `table table-zebra` with sortable headers |
| `ProgressBar.svelte` | `progress` | `progress progress-primary` with label |
| `ProgressSteps.svelte` | `steps` | `steps steps-vertical` → `step step-primary` (completed), `step` (pending) |
| `LoadingSkeleton.svelte` | `skeleton` | `skeleton h-4 w-full`, `skeleton h-32 w-full` |
| `EmptyState.svelte` | Custom (simple) | Centered `div` with icon + text, no DaisyUI equivalent |

### 6.3 Actions & Feedback

| FlexiQueue Component | DaisyUI Components | Class Example |
|---|---|---|
| Buttons (all variants) | `btn` | `btn-primary`, `btn-success`, `btn-error`, `btn-ghost`, `btn-outline` |
| `Modal.svelte` | `modal` | `modal` + `modal-box` + `modal-action`. Use `<dialog>` element. |
| `ConfirmDialog.svelte` | `modal` | Same as Modal, with confirm/cancel `modal-action` buttons |
| `Toast.svelte` | `toast` + `alert` | `toast toast-end` wrapping `alert alert-success` etc. |
| `OfflineBanner.svelte` | `alert` | `alert alert-warning` fixed to top of page |
| `SupervisorPinModal.svelte` | `modal` + `fieldset` + `input` | Modal with PIN fieldset, textarea, action buttons |

### 6.4 Navigation

| FlexiQueue Component | DaisyUI Components | Class Example |
|---|---|---|
| Admin sidebar | `menu` | `menu bg-base-200 w-60` with `menu-title` and active items |
| Tab navigation (Program detail) | `tab` | `tabs tabs-lifted` or `tabs tabs-boxed` |
| Pagination | `join` + `btn` | `join` wrapping `btn` elements for page numbers |
| Breadcrumbs | `breadcrumbs` | `breadcrumbs` → `li` items |
| Mobile bottom nav | `dock` | `dock` with icon + label items |

### 6.5 Form Inputs

| FlexiQueue Component | DaisyUI Components | Class Example |
|---|---|---|
| Text input | `input` + `label` | `input input-bordered w-full` |
| Select dropdown | `select` | `select select-bordered w-full` |
| Textarea | `textarea` | `textarea textarea-bordered` |
| Checkbox | `checkbox` | `checkbox checkbox-primary` |
| Toggle switch | `toggle` | `toggle toggle-primary` |
| PIN input (6 digits) | `input` + `join` | 6 joined `input` fields, or single `input` with maxlength |
| Fieldset group | `fieldset` | `fieldset` with `fieldset-legend` and `label` |
| Form validation | `validator` | DaisyUI 5 validator class for error/success states |

### 6.6 Custom Components (No DaisyUI Equivalent)

These remain as fully custom Svelte components:

| Component | Reason |
|---|---|
| `QrScanner.svelte` | Camera integration, no CSS-only solution |
| `AliasDisplay.svelte` | 72px bold alias text, domain-specific styling |
| `TimerDisplay.svelte` | Live duration counter with `$effect` |
| `FlowDiagram.svelte` | Visual track flow (step1 → step2 → step3), optional Phase 1 |

---

## 7. Responsive Breakpoints

| Breakpoint | Target | Pages | Strategy |
|---|---|---|---|
| 375px | Mobile (primary) | Triage, Station | Full-width, stacked, large touch targets |
| 768px | Tablet (kiosk) | Informant Display | Portrait orientation, 2-column grids |
| 1024px | Tablet landscape | Admin (usable) | Sidebar collapses to drawer toggle |
| 1440px | Desktop (primary) | Admin Dashboard, Reports | Full sidebar + spacious content area |

**Mobile-first rule:** All components start at 375px and scale up. Admin pages have a minimum usable width of 768px with horizontal scroll below that.

---

## 8. Accessibility Requirements

| Requirement | Implementation |
|---|---|
| Color contrast | All text meets WCAG AA (4.5:1 body, 3:1 large text). DaisyUI themes handle this. |
| No color-only indicators | Always pair color with text and/or icon (e.g., badge says "Priority" not just gold dot) |
| Keyboard navigation | All interactive elements focusable. DaisyUI modals trap focus. |
| Screen reader | Semantic HTML + `aria-label` on icon-only buttons |
| Touch targets | >= 44x44px on all interactive elements (WCAG 2.5.8) |
| Large text option | Future: 18px+ body text mode (Phase 2) |

---

## 9. Interaction Patterns

### 9.1 Buttons

- **Hover:** DaisyUI handles darker shade automatically.
- **Active/Pressed:** DaisyUI applies subtle scale-down.
- **Disabled:** `btn-disabled` or `disabled` attribute — reduced opacity, `cursor-not-allowed`.
- **Loading:** `btn` with `loading loading-spinner` child element.

### 9.2 Modals

- Use `<dialog>` element with DaisyUI `modal` class.
- Open via `element.showModal()`, close via `element.close()` or form method="dialog".
- Dark backdrop via DaisyUI's built-in `modal-backdrop`.
- Always include a close mechanism (X button, Cancel, or backdrop click).

### 9.3 Toasts

- Position: `toast toast-top toast-end` (top-right corner).
- Stack: Multiple toasts stack vertically.
- Auto-dismiss: Success/Info after 3–5 seconds. Errors persist until dismissed.
- Use `alert` inside `toast` for color-coded variants.

### 9.4 Loading States

- Skeleton screens for initial page loads (DaisyUI `skeleton`).
- Spinner for async actions (DaisyUI `loading loading-spinner`).
- Button loading state: add `loading` class to `btn` during submission.

### 9.5 Empty States

- Custom component: centered icon + descriptive text + optional action button.
- Examples: "NO CLIENT ACTIVE" on station, "No programs found" on admin list.

---

## 10. DaisyUI Components NOT Used

These DaisyUI components are available but not planned for Phase 1:

| Component | Reason |
|---|---|
| `carousel` | No image galleries in Phase 1 |
| `chat` | No messaging feature |
| `diff` | No comparison views |
| `hover-3d`, `hover-gallery` | Not needed for utility app |
| `rating` | No rating feature |
| `range` | No slider inputs |
| `text-rotate` | No animated text |
| `countdown` | Using custom timer component with `$effect` instead |
| `calendar` | Date range picker handled by native `<input type="date">` or lightweight lib |
| Mockup components | Development-only, not in production |

---

## 11. Component Preview Page

A standalone HTML page at `public/dev/components.html` serves as a living style guide. It loads DaisyUI via CDN and applies the FlexiQueue custom theme, showcasing every DaisyUI component used in the project.

**Purpose:**
- Visual reference during development — see all components with FlexiQueue theming.
- Verify color palette, spacing, and sizing before building Svelte components.
- Shareable with stakeholders for early UI feedback.

**Implementation:** Pure HTML + TailwindCSS CDN + DaisyUI CDN. No build step required.
**Task:** BD-051 (see Phase 1 backlog).

---

## 12. Design References

- **DaisyUI Component Catalog:** https://daisyui.com/components/
- **DaisyUI Theme Generator:** https://daisyui.com/theme-generator/
- **DaisyUI Documentation:** https://daisyui.com/docs/
- **TailwindCSS 4 Docs:** https://tailwindcss.com/docs
- **Original Design Specs:** `docs v1/07-ui-ux-specs.md` (superseded by this document for implementation)
