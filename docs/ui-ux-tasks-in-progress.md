# UI/UX tasks in progress

One-page view of current batch. Source: [ui-ux-tasks-checklist.md](ui-ux-tasks-checklist.md).

| # | Task | Type | Status |
|---|------|------|--------|
| 1 | **Logout modal confirmation** — Add confirm step before logging out. | UX Polish | Done |
| 2 | **Modal close buttons** — Remove background color on close (X) buttons in modals; test gray removal on light mode. | UX Polish | Done |
| 3 | **Theme toggle button** — Remove border-color flash on click; no border interaction on that button. | Bug | Done |

---

## 1. Logout modal confirmation

- **Scope:** AdminLayout (sidebar + header), AppShell (header), MobileLayout (menu). All “Log out” actions open a confirmation modal; Confirm → `router.post('/logout')`, Cancel → close.
- **Approach:** New `LogoutConfirm.svelte` using existing `ConfirmModal`; each layout holds `showLogoutConfirm` and renders `<LogoutConfirm open={...} onClose={...} />`; all “Log out” buttons set `showLogoutConfirm = true`.

## 2. Modal close (X) buttons

- **Scope:** `resources/js/Components/Modal.svelte` — single close button.
- **Change:** Remove `preset-tonal` (background); use transparent bg + hover only (e.g. `bg-transparent hover:bg-surface-200` and dark equivalent) so the X has no fill by default, light hover in both themes.

## 3. Theme toggle button

- **Scope:** `resources/js/Components/ThemeToggle.svelte`.
- **Change:** Remove focus ring on mouse click (avoid border/ring flash). Keep keyboard focus visible: use `focus:outline-none` and `focus-visible:ring-2` so only keyboard focus shows the ring.
