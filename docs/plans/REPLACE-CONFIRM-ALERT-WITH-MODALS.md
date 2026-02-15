# Replace window.confirm / alert with Modals

## Rationale

- Native `confirm()` and `alert()` block the main thread and look inconsistent across browsers/OS.
- Modals keep the UX consistent with DaisyUI, are accessible, and allow better copy control.
- QA can verify that titles, messages, and button labels are correct before implementation.

---

## Current Usage (Audit)

| Location | Current Call | Action |
|----------|--------------|--------|
| `Admin/Programs/Show.svelte` L195 | `confirm(\`Stop program "${name}"? You can only stop when no clients are in queue.\`)` | Stop program |
| `Admin/Programs/Show.svelte` L318 | `confirm(\`Delete track "${name}"? This is only allowed if no active sessions use it.\`)` | Delete track |
| `Admin/Programs/Show.svelte` L387 | `confirm(\`Delete station "${name}"? This is only allowed if it is not used in any track steps.\`)` | Delete station |
| `Admin/Programs/Show.svelte` L435 | `confirm(\`Remove step "${station}"?\`)` | Remove track step |
| `Admin/Programs/Index.svelte` L162 | `confirm(\`Delete program "${name}"? This is only allowed if it has no sessions.\`)` | Delete program |

No `alert()` usages found in app code (only in docs/skills).

---

## Proposed Component: ConfirmModal

A reusable confirmation modal, built on the existing `Modal.svelte` pattern:

- Props: `open`, `title`, `message`, `confirmLabel`, `cancelLabel`, `variant` (e.g. `danger` for destructive), `onConfirm`, `onCancel`, `loading`
- Uses `<dialog>` (as per 07-UI-UX-SPECS) and DaisyUI modal classes.

---

## Draft Copy for QA Review

Each row below should be reviewed and approved before implementation. Status: **DRAFT** — adjust wording as needed.

### 1. Stop Program

| Field | Value |
|-------|-------|
| **Title** | Stop program? |
| **Message** | Stop "{programName}"? You can only stop when no clients are in the queue. |
| **Confirm label** | Stop program |
| **Cancel label** | Cancel |
| **Variant** | danger |
| **Notes** | Destructive action; emphasize consequence. |

### 2. Delete Track

| Field | Value |
|-------|-------|
| **Title** | Delete track? |
| **Message** | Delete track "{trackName}"? This is only allowed if no active sessions use it. |
| **Confirm label** | Delete |
| **Cancel label** | Cancel |
| **Variant** | danger |

### 3. Delete Station

| Field | Value |
|-------|-------|
| **Title** | Delete station? |
| **Message** | Delete station "{stationName}"? This is only allowed if it is not used in any track steps. |
| **Confirm label** | Delete |
| **Cancel label** | Cancel |
| **Variant** | danger |

### 4. Remove Track Step

| Field | Value |
|-------|-------|
| **Title** | Remove step? |
| **Message** | Remove step "{stationName}" from this track? |
| **Confirm label** | Remove |
| **Cancel label** | Cancel |
| **Variant** | warning or neutral |

### 5. Delete Program

| Field | Value |
|-------|-------|
| **Title** | Delete program? |
| **Message** | Delete program "{programName}"? This is only allowed if it has no sessions. |
| **Confirm label** | Delete |
| **Cancel label** | Cancel |
| **Variant** | danger |

---

## QA Checklist (Before Implementation)

- [ ] Each message clearly explains what will happen.
- [ ] Each message states any constraints (e.g. "only when no clients in queue").
- [ ] Confirm labels match the action (e.g. "Delete", "Stop program", "Remove").
- [ ] Cancel label is consistent (e.g. always "Cancel").
- [ ] Titles are short and descriptive.
- [ ] Variant (danger vs warning) matches severity.

---

## Implementation Steps (Future Bead)

1. Create `ConfirmModal.svelte` component.
2. Replace each `confirm()` in `Admin/Programs/Show.svelte` with modal + state.
3. Replace `confirm()` in `Admin/Programs/Index.svelte` with modal + state.
4. Remove any `alert()` if introduced later; use toast or inline message instead.
5. QA pass: verify each modal opens, copy is correct, and actions behave as expected.

---

## Notes

- `Modal.svelte` already exists; `ConfirmModal` can wrap or extend it.
- Per 07-UI-UX-SPECS Section 6.3, 9.2: use `element.showModal()` / `close()` with `<dialog>`.
- Consider adding `danger` / `warning` variants to the modal for destructive actions.
