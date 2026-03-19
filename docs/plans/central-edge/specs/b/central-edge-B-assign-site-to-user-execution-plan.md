# Assign site to user + Super Admin — Execution Plan

**Reference:** [assign-site-to-user-ui.md](../../follow-up-backlog/assign-site-to-user-ui.md), [SITES-AND-ROLES.md](../../SITES-AND-ROLES.md), [central-edge-v2-final.md](../central-edge-v2-final.md) (Phase B).  
**Applied rules:** UX Architect, Code Reviewer, Security Engineer (site scoping, super_admin only can change site).

**Goal:** Provide UI and API so that (1) every admin can **see** which site a user belongs to; (2) a **super admin** can assign or change a user’s site (user edit and user create); (3) on the Site show page, list “Users in this site” and allow super admin to “Move to site”. No change to site-scoped behaviour for normal admins.

---

## Scope

- **In scope:** Super admin role; API and page support for viewing/editing user’s site; Site show page “Users in this site” and “Move to site”; feature tests.
- **Out of scope:** Super admin creation flow (e.g. first user or CLI); per-device edge settings; changing central-edge-v2-final Phase B tenant model.

---

## Delegateable tasks

### Task 1 — Super admin role and scoping

**Steps:**

1. Add `UserRole::SuperAdmin = 'super_admin'` to `App\Enums\UserRole`. No DB migration (role column is string).
2. Add `User::isSuperAdmin(): bool` (role === SuperAdmin). EnsureRole: allow `super_admin` wherever `admin` is allowed (routes use `role:admin,super_admin` for admin-only routes).
3. In UserController, ProgramController, ProgramPageController, UserPageController, SitesPageController: when `$request->user()->isSuperAdmin()`, bypass strict site filter for **index/list** (super admin sees all users/programs/sites) and allow **update** of `user.site_id` and **store** with optional `site_id`. Normal admin: unchanged (forSite(site_id), 403/404 when no site or wrong site).

**Files:** `app/Enums/UserRole.php`, `app/Models/User.php`, `app/Http/Middleware/EnsureRole.php`, `routes/web.php` (add `super_admin` to admin route middleware).

### Task 2 — API: user site in response and update/store

**Steps:**

1. **UserController index:** If super admin, return users from all sites (with optional `?site_id=` filter). If site admin, keep `forSite($siteId)`. Include in each user payload: `site` => `['id' => $u->site_id, 'name' => $u->site?->name, 'slug' => $u->site?->slug]` (load site relation).
2. **UserController userResource:** Add `site` (id, name, slug) to the returned user object.
3. **UserController store:** If auth is super admin and request has `site_id`, validate it (exists in sites) and use it; else use `$request->user()->site_id` (site admin or super admin without site_id). Validate site_id when present.
4. **UserController update:** If auth is super admin, allow `site_id` in request (validate exists); update user.site_id. ensureUserInSite: if super admin, allow access to any user; else require user in auth’s site.
5. **StoreUserRequest:** Add optional `site_id` => nullable, exists:sites,id. Only applied when caller is super admin (controller uses it).
6. **UpdateUserRequest:** Add optional `site_id` => nullable, exists:sites,id.

**Files:** `app/Http/Controllers/Api/Admin/UserController.php`, `app/Http/Requests/StoreUserRequest.php`, `app/Http/Requests/UpdateUserRequest.php`.

### Task 3 — User page: show site; edit/create site for super admin

**Steps:**

1. **UserPageController index:** Pass `sites` (all sites, id/name/slug) when auth is super admin; pass each user with `site` (id, name, slug). Add `auth_is_super_admin` => bool so frontend can show/hide site dropdown.
2. **Users/Index.svelte:** Add optional column or badge for “Site” (site name). In edit modal: show current site (read-only for admin; for super_admin show dropdown to change site). In create modal: if super_admin show site dropdown; otherwise no change. On save (create/update), send `site_id` when super_admin and selected.

**Files:** `app/Http/Controllers/Admin/UserPageController.php`, `resources/js/Pages/Admin/Users/Index.svelte`.

### Task 4 — Site show: Users in this site + Move to site

**Steps:**

1. **SitesPageController show:** Load `users_in_site` => User::forSite($site->id)->orderBy('name')->get(['id','name','email','role'])->map to minimal array. Pass `sites` (all) and `auth_is_super_admin` when super admin (for “Move to site” dropdown).
2. **Sites/Show.svelte:** Add section “Users in this site” listing name, email, role. If super_admin, each row has “Move to site” opening a modal with site dropdown; submit PATCH to update user’s site_id, then refresh or remove from list.

**Files:** `app/Http/Controllers/Admin/SitesPageController.php`, `resources/js/Pages/Admin/Sites/Show.svelte`. API: reuse UserController update with site_id (super_admin only).

### Task 5 — Tests and docs

**Steps:**

1. Feature test: site admin cannot set or change user’s site_id (only super_admin can). Super_admin can list all users, update user’s site_id, create user with site_id. Site show: users_in_site only for that site; super_admin can move user to another site.
2. Update follow-up backlog: mark assign-site-to-user-ui as done or scheduled; update SITES-AND-ROLES.md to mention super_admin and assign-site UI.

**Files:** `tests/Feature/Api/Admin/UserSiteAssignmentTest.php` (or extend existing), `docs/plans/central-edge/follow-up-backlog/assign-site-to-user-ui.md`, `docs/plans/central-edge/SITES-AND-ROLES.md`.

---

## Edge cases and tests

| # | Scenario | Test / behaviour |
|---|----------|------------------|
| 1 | Site admin updates user: send site_id in PATCH | 403 or ignore site_id (backend does not update site_id for non–super admin). |
| 2 | Super admin updates user with valid site_id | 200, user.site_id updated. |
| 3 | Super admin creates user with site_id | 201, user has that site_id. |
| 4 | Super admin creates user without site_id | 201, user has null site_id or default? (Spec: super_admin may have no site; then new user without site_id stays null. Optional: require site_id for new users even for super_admin—product choice. Prefer: allow null for super_admin-created users so they can assign later.) |
| 5 | Site show: non–super admin sees “Users in this site” | No “Move to site” button. |
| 6 | ensureUserInSite: super admin accessing user in another site | Allow (no 404). |
| 7 | Program/User index: super admin | List all programs/users (or filter by optional site_id query). |

---

## Files summary

| Area | Files |
|------|--------|
| Enum / model | `UserRole.php`, `User.php` (isSuperAdmin) |
| Middleware / routes | `EnsureRole.php`, `web.php` |
| API | `UserController.php`, `StoreUserRequest.php`, `UpdateUserRequest.php` |
| Page controllers | `UserPageController.php`, `SitesPageController.php` |
| Frontend | `Users/Index.svelte`, `Sites/Show.svelte` |
| Tests | New feature test for user site assignment and super_admin |
| Docs | `assign-site-to-user-ui.md`, `SITES-AND-ROLES.md` |
