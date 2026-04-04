# A.2.5 Admin Pages Program Resolution — Execution Plan

**Reference:** [central-edge-tasks.md](../../central-edge-tasks.md) (Phase A), [central-edge-v2-final.md](../central-edge-v2-final.md)  
**Goal:** Admin selects program from sidebar or URL. HandleInertiaRequests passes `programs` (all active) to admin pages; admin pages receive `currentProgram` when a program is in context (e.g. from URL or sidebar selection). No single global `program`; admin can see all programs regardless of site (Phase A has no site scoping).

**Status:** Not started.

---

## Two delegateable tasks

### Task A — HandleInertiaRequests and admin shared data

**Scope:** HandleInertiaRequests middleware; shared Inertia props for admin vs non-admin.

**Steps:**
1. **HandleInertiaRequests::share()** — For admin routes (e.g. when `$request->routeIs('admin.*')` or `$request->user()?->role === 'admin'`): pass `programs` = list of active programs, e.g. `Program::where('is_active', true)->orderBy('name')->get(['id', 'name'])`. Do not pass a single `activeProgram` or `program` for admin; admin context is multi-program. For non-admin (station, triage, display) leave `currentProgram` to be set by the specific page controller per Phase A table (station from station.program_id, triage from user.assigned_station, etc.); do not set a global `program` in shared data for those. Remove or deprecate the current `activeProgram` single-program key from shared data for admin; keep it only for non-admin if still used, or replace with `programs` for admin and nothing for non-admin (each controller passes its own `currentProgram`).
2. **Backward compatibility** — Per frontend compatibility plan in spec: introduce `currentProgram` (nullable) and keep `program` as deprecated alias for `currentProgram` during transition. In shared data, for admin pass `programs`; do not pass `currentProgram` globally (admin pages get `currentProgram` from their controller when viewing a specific program, e.g. program show page). So: shared data for admin = `programs` (array); shared data for staff/station/triage = leave to controllers. Remove `activeProgram` in favor of `programs` (admin) or controller-set `currentProgram` (station/triage/display).
3. **Tests** — Feature test: as admin, visit an admin page (e.g. dashboard); assert Inertia props contain `programs` (array) and no single `activeProgram` (or assert structure per spec). As staff, visit station page; assert controller provides `currentProgram` for that station's program.

**Files:** `app/Http/Middleware/HandleInertiaRequests.php`, `tests/Feature/HandleInertiaRequestsTest.php` or existing auth/Inertia tests.

---

### Task B — Admin routes, sidebar program selector, and currentProgram from URL

**Scope:** Admin layout sidebar, admin routes that are program-scoped (e.g. `/admin/programs/{program}`), and optional URL pattern for "current program" (e.g. query or segment).

**Steps:**
1. **Admin routes** — Existing routes already use `/admin/programs/{program}` for program-scoped pages. Ensure all admin controllers that need a program resolve it from the route parameter (e.g. `ProgramPageController::show(Program $program)`) and pass `currentProgram` (id, name, etc.) in Inertia props for that page. Controllers that list programs (e.g. program index) do not need a current program; they use `programs` from shared data.
2. **Sidebar program selector** — In AdminLayout.svelte (or equivalent): when on a program-scoped page (e.g. `/admin/programs/1`), show a program selector in the sidebar (dropdown or list) populated from `$page.props.programs`. Selected value = current program from URL (e.g. 1). Changing selection navigates to the same page type with the new program id (e.g. `router.visit('/admin/programs/' + selectedId)`). On non-program-scoped admin pages (dashboard, settings), selector can be hidden or show "All programs" / no selection. Ensure `programs` is read from shared props (available on all admin pages).
3. **Pass programs to layout** — HandleInertiaRequests already passes `programs` for admin (Task A). AdminLayout receives it via `$page.props.programs`. Use it in the sidebar component. If no program in URL (e.g. dashboard), sidebar can still show program quick-links (e.g. "Program A", "Program B") to jump to each program's show page.
4. **Tests** — Feature test: admin visits `/admin/programs/1`, page has `currentProgram` and `programs`; visits `/admin/dashboard`, has `programs`, may have no `currentProgram`. Manual or E2E: change program in sidebar on program show page, URL and content update to selected program.

**Files:** `resources/js/Layouts/AdminLayout.svelte`, `app/Http/Controllers/Admin/ProgramPageController.php` (ensure show() passes currentProgram), any other admin controllers that render program-scoped Inertia pages (e.g. tracks, stations under a program). List: `app/Http/Controllers/Admin/ProgramPageController.php`, and any admin page components that expect `program` or `currentProgram` under `resources/js/Pages/Admin/`.

---

## Notes

- **currentProgram vs program:** Per spec, use `currentProgram` (nullable); keep `program` as deprecated alias so existing Svelte pages that still use `$page.props.program` do not break until A.4 removes the alias.
- **Security:** Admin-only; `programs` list is restricted to active programs. Program-scoped routes continue to use route model binding and policy checks.
- **Station/triage pages:** They do not use admin shared data for program; their controllers set `currentProgram` per A.2.1 and A.2.2. This bead is only for admin pages and HandleInertiaRequests admin branch.
