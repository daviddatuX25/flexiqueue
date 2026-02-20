# FlexiQueue — Phase 1 UI/UX Specifications

**Framework:** Svelte 5 + Inertia.js (via Laravel)
**Styling:** TailwindCSS 4 + Skeleton UI
**Layout:** Mobile-first (375px primary), desktop for admin (1440px)
**Font:** Inter, sans-serif

---

## 1. Component Library: Skeleton UI

FlexiQueue uses [Skeleton](https://www.skeleton.dev/) as its UI toolkit on top of TailwindCSS 4. Skeleton provides a design system with CSS custom properties (themes), utility-based components (`btn`, `card`, presets), and optional Svelte components.

### 1.1 Why Skeleton

- **Consistent design system** — Theme variables and presets (`preset-filled-primary-500`, `preset-tonal`, `preset-outlined`) for buttons, cards, and surfaces.
- **Accessible** — Works with semantic HTML and ARIA; Svelte components (when used) built with Zag.js.
- **Custom theming** — Full control via CSS custom properties in theme files (OKLCH color space). FlexiQueue theme: `resources/css/themes/flexiqueue.css`.
- **TailwindCSS 4 native** — Imports in `resources/css/app.css`; no JS config required for core styles.
- **Presets + utilities** — Use Skeleton presets and theme colors (`bg-surface-50`, `text-primary-500`) first; override with Tailwind when needed.

### 1.2 Installation

In `resources/css/app.css`:

```css
@import 'tailwindcss';
@import '@skeletonlabs/skeleton';
@import './themes/flexiqueue.css';
```

Set active theme in `resources/views/app.blade.php`: `<html ... data-theme="flexiqueue">`.

Packages: `@skeletonlabs/skeleton`, `@skeletonlabs/skeleton-svelte` (optional, for Svelte components).

### 1.3 Convention

- **Use Skeleton presets and theme colors first** — e.g., `btn preset-filled-primary-500`, `text-surface-950`, `bg-surface-50`.
- **Override with Tailwind when needed** — e.g., `btn preset-filled-primary-500 min-h-[48px]` for touch targets.
- **Component mapping** — See `docs/architecture/SKELETON-COMPONENT-MAPPING.md` for DaisyUI-to-Skeleton equivalents.
- **Custom components** only for domain-specific UI with no Skeleton equivalent (QR scanner, flow diagrams).

---

## 2. Custom Theme: "flexiqueue"

Skeleton themes are defined in CSS with `[data-theme='flexiqueue']`. FlexiQueue's theme is in `resources/css/themes/flexiqueue.css` and maps our design palette to Skeleton's color system (primary, secondary, tertiary, success, warning, error, surface). Activate with `data-theme="flexiqueue"` on `<html>` in `resources/views/app.blade.php`.

### 2.1 Color Mapping

| Design Token | Hex | Theme variable | Usage |
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

Legacy DaisyUI theme reference (superseded by `resources/css/themes/flexiqueue.css` for Skeleton). Old app.css snippet:

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

> **Note:** OKLCH values are approximate conversions from the hex palette. The live theme is in `resources/css/themes/flexiqueue.css`.

### 2.3 Visual hierarchy (elevation)

Elevation is controlled by CSS custom properties in the flexiqueue theme so cards, tables, and overlays have a consistent visual hierarchy.

| Token | Variable | Usage |
|-------|----------|--------|
| Card | `--shadow-card` | Cards, table containers, filter panels |
| Raised | `--shadow-raised` | Dropdowns, popovers, hover emphasis |
| Modal | `--shadow-modal` | Modal dialogs |

Use the utility classes `elevation-card`, `elevation-raised`, and `elevation-modal` on containers (e.g. `card bg-surface-50 rounded-container elevation-card`). Prefer elevation over borders for separating content. Data tables use the shared `.table-container` wrapper (rounded, elevation-card, sticky header, consistent cell padding, row hover). See `resources/css/themes/flexiqueue.css` for definitions.

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

| Element | Height | Skeleton / Class | Notes |
|---|---|---|---|
| Primary action button | 80px | `btn preset-filled-primary-500 h-20 text-lg` | "SEND TO CASHIER", "CALL NEXT" |
| Secondary action button | 48px | `btn preset-tonal h-12` | "Re-queue", "Override", "Cancel" |
| Category selection button | ~120px | `btn h-30 w-full` + custom | Triage category cards |
| Standard form button | 44px | `btn preset-filled-primary-500` (default size) | Login, modal confirms |
| Icon button | 44x44px min | `btn preset-tonal btn-icon` | Logout, menu toggles |

**WCAG minimum:** All interactive targets >= 44x44px.

---

## 6. Component Mapping: FlexiQueue to Skeleton

The app uses Skeleton UI as the component toolkit. This table maps FlexiQueue components to Skeleton classes and patterns. See `docs/architecture/SKELETON-COMPONENT-MAPPING.md` for preset and utility details.

### 6.1 Layout Components

| FlexiQueue Component | Skeleton / Implementation | Notes |
|---|---|---|
| `AppShell.svelte` | Custom layout | Header + footer with theme surfaces |
| `AdminLayout.svelte` | Custom drawer + nav | Sidebar 240px, `bg-surface-800`, active `bg-primary-500` |
| `MobileLayout.svelte` | Custom navbar + dock | Fixed top bar, bottom nav with theme buttons |
| `DisplayLayout.svelte` | Minimal header | Blue header, kiosk mode |

### 6.2 Data Display

| FlexiQueue Component | Skeleton / Class Example | Notes |
|---|---|---|
| Status badges | `preset-filled-success-500`, `preset-filled-warning-500`, `preset-filled-primary-500` | `text-xs px-2 py-0.5 rounded` on span |
| Stat cards | `stats`, `stat`, `stat-title`, `stat-value`, `stat-desc` | Theme utilities in flexiqueue.css; use `elevation-card` |
| Data tables | `table-container` + `table table-zebra` | Wrapper in theme: sticky header, padding, row hover |
| Progress bar | `progress progress-primary` | Theme `.progress-primary` in flexiqueue.css |
| Tabs | `.tabs`, `.tab`, `.tab-active` | Theme utilities in flexiqueue.css |
| Empty state | Custom | `rounded-box bg-surface-50 border border-surface-200 p-8` or elevation-card |

### 6.3 Actions & Feedback

| FlexiQueue Component | Skeleton / Class Example | Notes |
|---|---|---|
| Buttons | `btn preset-filled-primary-500`, `btn preset-tonal`, `btn preset-outlined` | Danger: `preset-filled-error-500` |
| `Modal.svelte` | `<dialog>` + `card rounded-container elevation-modal` | Native dialog, theme elevation |
| `ConfirmModal.svelte` | Uses Modal + preset buttons | Confirm/cancel with variant (danger/warning/neutral) |
| `Toast.svelte` | Custom | Position and alert-style feedback |
| `OfflineBanner.svelte` | Custom | Fixed top, warning styling |

### 6.4 Navigation

| FlexiQueue Component | Skeleton / Implementation | Notes |
|---|---|---|
| Admin sidebar | Custom nav + `Link` | Active: `bg-primary-500 text-primary-contrast-500` |
| Tab navigation (Program detail) | `.tabs`, `.tab`, `.tab-active` | Theme in flexiqueue.css |
| Pagination | `btn preset-tonal btn-sm` | Custom layout with buttons |
| Mobile bottom nav | Custom | Icon + label, theme buttons |

### 6.5 Form Inputs

| FlexiQueue Component | Skeleton / Class Example | Notes |
|---|---|---|
| Text input | `input rounded-container border border-surface-200 px-3 py-2 w-full` | Skeleton input + theme |
| Select | `select rounded-container border border-surface-200 px-3 py-2 w-full` | |
| Textarea | `textarea rounded-container border border-surface-200 w-full` | |
| Checkbox | `checkbox checkbox-sm` | Skeleton checkbox |
| Label | `label`, `label-text`, `label-text-alt` | Theme ensures dark text in main/dialog |
| Form validation | Custom | Error state via border/background and `form.errors` |

### 6.6 Custom Components (No Skeleton Equivalent)

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
| Color contrast | All text meets WCAG AA (4.5:1 body, 3:1 large text). Theme palette and presets ensure contrast. |
| No color-only indicators | Always pair color with text and/or icon (e.g., badge says "Priority" not just gold dot) |
| Keyboard navigation | All interactive elements focusable. Native `<dialog>` traps focus when modal is open. |
| Screen reader | Semantic HTML + `aria-label` on icon-only buttons |
| Touch targets | >= 44x44px on all interactive elements (WCAG 2.5.8) |
| Large text option | Future: 18px+ body text mode (Phase 2) |

---

## 9. Interaction Patterns

### 9.1 Buttons

- **Hover:** Skeleton presets provide darker shade on hover.
- **Active/Pressed:** Use `disabled` or loading state during submit.
- **Disabled:** `disabled` attribute — reduced opacity, `cursor-not-allowed`.
- **Loading:** `btn` with `loading-spinner loading-sm` (or `loading-lg`) child; theme in flexiqueue.css.

### 9.2 Modals

- Use native `<dialog>` element. Inner content: `card bg-surface-50 rounded-container elevation-modal`.
- Open via `element.showModal()`, close via `element.close()` or form method="dialog".
- Backdrop: `backdrop:bg-black/50` on dialog.
- Always include a close mechanism (X button, Cancel, or backdrop click).

### 9.3 Toasts

- Custom toast component; position top-right or as implemented.
- Stack: Multiple toasts stack vertically.
- Auto-dismiss: Success/Info after 3–5 seconds. Errors persist until dismissed.

### 9.4 Loading States

- Spinner: `loading-spinner` utility (theme in flexiqueue.css). Use `loading-sm` or `loading-lg` for size.
- Button loading state: show spinner inside button during submission.

### 9.5 Empty States

- Custom component: centered icon + descriptive text + optional action button.
- Examples: "NO CLIENT ACTIVE" on station, "No programs found" on admin list.

---

## 10. Components Not Used in Phase 1

The following are not in scope for Phase 1: carousel, chat, diff, rating, range sliders, countdown (custom timer used instead), calendar (native date input or lightweight lib). Mockup components are development-only.

---

## 11. Component Preview Page

A standalone HTML page at `public/dev/components.html` exists as a legacy reference; it currently loads DaisyUI via CDN. **The app uses Skeleton UI.** For Skeleton components and FlexiQueue theming, use the running app and `docs/architecture/SKELETON-COMPONENT-MAPPING.md`. Optionally, the preview page can be updated to Skeleton + flexiqueue theme to match the app.

---

## 12. Design References

- **Skeleton UI:** https://www.skeleton.dev/
- **Skeleton Docs (themes, presets, colors):** https://www.skeleton.dev/docs/design/themes
- **FlexiQueue component mapping (DaisyUI → Skeleton):** `docs/architecture/SKELETON-COMPONENT-MAPPING.md`
- **TailwindCSS 4 Docs:** https://tailwindcss.com/docs
- **Original Design Specs:** `docs v1/07-ui-ux-specs.md` (superseded by this document for implementation)
