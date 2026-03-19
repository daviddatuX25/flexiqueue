# Super Admin vs Site Admin: Responsibility Split

This spec defines the distinction between **super_admin** (platform-level) and **admin** (site-scoped) roles. See also [SITES-AND-ROLES.md](SITES-AND-ROLES.md) for site and role concepts.

---

## Roles

| Role | Scope | Purpose |
|------|--------|---------|
| **super_admin** | Platform | Manage sites, admin users, system integrations, and admin-level audit. No access to programs, tokens, or operational audit. |
| **admin** | One site (`user.site_id`) | Manage programs, tokens, staff, clients, full audit, analytics, and storage for their site. Can create fellow admin for same site. |

---

## Per-area matrix

| Area | Super admin | Site admin |
|------|-------------|------------|
| **Dashboard** | Platform summary: sites count, admins count, links to Sites / Staff / System. No programs or stations. | Current dashboard: programs, stations, quick actions. |
| **Programs** | No access (no nav, no route, no API). | Full access (own site only). |
| **Tokens** | No access (no nav, no route, no token API). | Full access (own site / program scope). |
| **Staff (Users)** | Manage **admins only**: list/create/edit/delete users with role `admin`; assign site; cannot create/edit staff. | Manage **staff**; can create fellow **admin** for same site; cannot delete self. |
| **Sites** | Full: list, create, show, edit, regenerate key, edge_settings. | List own site only; show/edit own site only; no create. |
| **Clients** | No access (admin/staff only). | Unchanged (admin + staff). |
| **Audit log** | **Admin-level only:** system/admin action log (e.g. user/site/settings changes). No transaction/program/staff activity. | **Full audit:** transaction_logs + program_audit_log + staff_activity_log. |
| **Analytics** | No access (to be specified later: e.g. platform-level summary only). | Current program-scoped analytics. |
| **System settings** | **Full:** Storage + **Integrations** (ElevenLabs, etc.). Only super_admin may view/change integrations. | **Storage only:** storage tab and system storage API; no Integrations tab or integration API access. |

---

## RBAC rules

- **Super admin**
  - Cannot create `staff`; cannot create `super_admin` via UI/API.
  - Can create/edit/delete users with role `admin` only; can assign any site to an admin.
- **Site admin**
  - Can create `staff`; can create fellow `admin` for **same site** only.
  - Cannot delete self; cannot change own role.
  - Cannot create or edit users in other sites (enforced by site_id scoping).

---

## Reference

- [SITES-AND-ROLES.md](SITES-AND-ROLES.md) — site model and how to get a super_admin account.
- [central-edge-tasks.md](central-edge-tasks.md) — implementation task list.
