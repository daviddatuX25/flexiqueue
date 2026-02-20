# DaisyUI to Skeleton component mapping

Reference for migrating FlexiQueue from DaisyUI to Skeleton UI. Use Skeleton classes/presets first; override with Tailwind when needed.

## Buttons

| DaisyUI | Skeleton |
|---------|----------|
| `btn btn-primary` | `btn preset-filled-primary-500` |
| `btn btn-success` | `btn preset-filled-success-500` |
| `btn btn-warning` | `btn preset-filled-warning-500` |
| `btn btn-error` | `btn preset-filled-error-500` |
| `btn btn-outline` | `btn preset-outlined` or `btn preset-outlined-primary-500` |
| `btn btn-ghost` | `btn preset-tonal` or `btn preset-tonal-primary` |
| `btn btn-sm` | `btn btn-sm` (same) |
| `btn btn-lg` | `btn btn-lg` (same) |
| `btn btn-block` | `btn w-full` |
| `btn btn-circle` | `btn btn-icon rounded-full` |
| `btn btn-square` | `btn btn-icon` |

## Surfaces / cards

| DaisyUI | Skeleton |
|---------|----------|
| `card` | `card` |
| `card-body` | (no equivalent; use `card p-4` or `card p-6`) |
| `card-title` | `font-bold text-lg` or heading class |
| `card-actions` | `flex justify-end gap-2 mt-4` |
| `bg-base-100` | `bg-surface-50` or `bg-surface-100` |
| `bg-base-200` | `bg-surface-100` or `bg-surface-200` |
| `border-base-300` | `border-surface-200` or `border-surface-300` |

## Forms

| DaisyUI | Skeleton |
|---------|----------|
| `input input-bordered` | `input` (Skeleton input has border by default) |
| `input input-error` | `input` + custom error border/background or `preset-input-error` (if defined) |
| `input input-sm` | `input` with `text-sm` / smaller padding |
| `select select-bordered` | `select` or form select utilities |
| `select select-sm` | `select` with smaller text/padding |
| `toggle toggle-primary` | Use Skeleton form radios/checks or custom toggle with theme colors |

## Feedback

| DaisyUI | Skeleton |
|---------|----------|
| `alert alert-error` | `bg-error-100 text-error-900 border border-error-300 rounded-container p-4` or similar |
| `alert alert-success` | `bg-success-100 text-success-900 ...` |
| `alert alert-warning` | `bg-warning-100 text-warning-900 ...` |
| `badge badge-primary` | Skeleton badges: see `badges.css` / `preset-filled-primary-500` on a span |
| `badge badge-success` | `preset-filled-success-500` + badge size |
| `badge badge-warning` | `preset-filled-warning-500` |
| `badge badge-error` | `preset-filled-error-500` |
| `badge badge-ghost` | `preset-tonal` or `bg-surface-200` |
| `badge badge-outline` | `preset-outlined` |
| `badge badge-sm` | `text-xs px-2 py-0.5 rounded` |

## Layout

| DaisyUI | Skeleton |
|---------|----------|
| `navbar`, `navbar-start`, `navbar-end` | Custom flex layout with `flex`, `justify-between`, theme colors |
| `dropdown`, `dropdown-content`, `menu` | Custom with Skeleton card/preset or Skeleton Svelte components (e.g. Popover) |
| `drawer`, `drawer-toggle` | Custom sidebar + checkbox or Skeleton components |
| `modal`, `modal-box`, `modal-action`, `modal-backdrop` | Native `<dialog>` + Skeleton `card`, `rounded-container`, theme backgrounds |

## Theme tokens

| DaisyUI | Skeleton |
|---------|----------|
| `text-base-content` | `text-surface-950` |
| `text-base-content/80` | `text-surface-950/80` |
| `text-primary` | `text-primary-500` |
| `text-primary-content` | `text-primary-contrast-500` |
| `bg-primary`, `text-primary-content` (on btn) | `preset-filled-primary-500` |

## Loading

| DaisyUI | Skeleton |
|---------|----------|
| `loading loading-spinner loading-sm` | Custom spinner (Skeleton may have progress/keyframes) or inline SVG |

## Convention

- Use Skeleton presets (`preset-filled-primary-500`, `preset-outlined`, `preset-tonal`) for buttons.
- Use Skeleton `card`, `input`, `select` utilities for form and surfaces.
- Use theme colors: `bg-surface-50`, `text-surface-950`, `text-primary-500`, `bg-primary-500`, etc.
- Touch targets: keep `min-h-[48px] min-w-[48px]` where required per 07-UI-UX-SPECS.
