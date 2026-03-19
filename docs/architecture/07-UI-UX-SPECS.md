# UI/UX Specs — Token print template (excerpt)

This stub documents the admin token print template settings modal and its image upload options.

## Touch targets (mobile a11y)

- **Minimum size:** 48×48px for interactive controls (buttons, links, tabs) used on mobile.
- **Utilities (theme):** `touch-target-h` (min-height: 48px), `touch-target` (min-height + min-width: 48px).
- **Location:** `resources/css/themes/flexiqueue.css`. Use `class="btn preset-tonal touch-target-h"` or `touch-target` for icon buttons.

## Empty states

- **Pattern:** Icon in rounded circle, heading, body, CTA button.
- **Accessibility:** Use `role="status"` and `aria-label` on the empty-state container.
- **CTA:** Primary action (e.g. "Create program", "Clear filters", "Change filters") with `touch-target-h` for 48px min height.
- **Reference:** `resources/js/Pages/Admin/Programs/Index.svelte` (programs empty state).
- **Variants:**
  - **No data at all:** CTA creates first item (e.g. "Create First Program").
  - **No results for filters:** CTA clears or changes filters (e.g. "Clear filters").

## Modal and dialog copy

- **Dismiss vs Close:** Use "Dismiss" for alerts/toasts that can be acknowledged and cleared. Use "Close" for modals and dialogs that close a panel. Document choice per component in this spec.

## Admin › Tokens › Print settings

- Location: `Admin / Tokens`, **Print settings** button.
- Component: `resources/js/Pages/Admin/Tokens/Index.svelte` (print settings modal) and `Admin/Tokens/Print.svelte` (print view).

### Template options

- **Cards per page**: 4–8, grid auto-calculated for A4/Letter.
- **Paper / Orientation**: A4 or Letter; portrait or landscape.
- **Display options**:
  - Show “Scan for status” hint.
  - Show cut lines (dashed borders) for cutting cards.

### Logo and background image

- **Logo URL**:
  - Text input for a logo image URL (absolute or `/storage/...`).
  - **Upload image** button:
    - Opens a file picker (`accept=\"image/*\"`).
    - Calls `POST /api/admin/print-settings/image` with `type=logo`.
    - On success, updates the Logo URL field and shows a small inline preview.
- **Background image URL**:
  - Text input for a background image URL (absolute or `/storage/...`).
  - **Upload image** button:
    - Opens a file picker (`accept=\"image/*\"`).
    - Calls `POST /api/admin/print-settings/image` with `type=background`.
    - On success, updates the Background image URL field and shows a small preview block with the image as CSS `background-image`.
  - Guidance: Use 6:5 aspect ratio (e.g. `60×50mm`, `300×250px`) for best fit per card.

### Print view behavior

- The print view (`Admin/Tokens/Print.svelte`) receives:
  - `logoUrl` — rendered as a small `<img>` above the token ID.
  - `bgImageUrl` — applied as `background-image` on each `.print-card`.
- The UI is optimized so that text (token ID, footer text) remains readable over the background image (text shadow and translucent footer background).

