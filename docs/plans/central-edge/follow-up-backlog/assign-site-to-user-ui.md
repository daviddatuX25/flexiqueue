# Assign site to user — UI (done)

**Discovered:** User question: "Who assigns a site to an admin? Which UI is that in our plan or is it missing?"

**Implemented (assign-site-to-user + super_admin):**
- **Super admin role** added (`UserRole::SuperAdmin`). Routes allow `role:admin,super_admin` for admin API and pages.
- **User list (Admin → Staff):** Each user shows **Site** (name). Super admin gets **sites** dropdown on create and edit; can set/change user's site. Site admin sees site as read-only.
- **Site show (Admin → Sites → {site}):** Section **"Users in this site"** lists users; **super admin** sees "Move to site" per user (modal with site dropdown), calling `PUT /api/admin/users/:id` with `site_id`.
- **API:** `GET /api/admin/users` returns users with `site` (id, name, slug). Super admin sees all users (optional `?site_id=` filter). `PUT /api/admin/users/:id` and `POST /api/admin/users` accept `site_id` when caller is super admin.

**Reference:** [SITES-AND-ROLES.md](../SITES-AND-ROLES.md), [specs/b/central-edge-B-assign-site-to-user-execution-plan.md](../specs/b/central-edge-B-assign-site-to-user-execution-plan.md).

**Status:** Done. Creating a super_admin user is still manual (e.g. DB or seeder); no in-app "promote to super admin" flow.
