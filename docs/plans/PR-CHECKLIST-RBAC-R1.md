# PR checklist — R1 “freeze the bridge” (authorization)

**Ref:** [`RBAC_AND_IDENTITY_END_STATE.md`](RBAC_AND_IDENTITY_END_STATE.md) §4 phase **R1** and the consolidated roadmap *RBAC + Hybrid Auth Order* (Cursor plan: `rbac_+_hybrid_auth_order_de1f25ab`).

**Goal:** Stop **new** technical debt on legacy authorization paths while Spatie + teams + policies become the only runtime story (R2–R6).

---

## Author — confirm before opening the PR

- [ ] **No new authorization** based on **`users.role`** (enum) alone — e.g. no new `if ($user->role === …)` / `isAdmin()` **as the sole gate** for “may this user do X?”. Prefer **`$user->can(PermissionCatalog::…)`**, route **`permission:`** middleware, **`Gate::authorize`**, and **`RbacContextService::hasPermissionInContext`** for site/program scope. *(Existing call sites may stay until R5/R6; do not add new ones.)*
- [ ] **No new authorization** based on **`program_supervisors`** pivot as the **decision** for access — e.g. no new “if attached to pivot, allow” without also going through Spatie/program team + policy. *(Legacy sync and existing checks may remain until R4.)*
- [ ] **New capabilities** use **`PermissionCatalog`** (+ seeder/migration if new permission strings), not ad-hoc strings.
- [ ] **Controllers** use **policies** or shared auth helpers aligned with [`RBAC_POLICY_CLEANUP.md`](RBAC_POLICY_CLEANUP.md) (no new scattered `abort(403)` + enum/pivot-only logic for the same rule).

## Reviewer — verify on each PR that touches auth, users, programs, or routes

- [ ] No **new** `users.role` / enum branch introduced for **authorization** (UI labels and sync are OK until R5).
- [ ] No **new** `program_supervisors` usage for **authorization** without an explicit bridge note and a bead/issue to remove it in R3/R4.
- [ ] HTTP protection matches **Spatie** (`permission:` / `can()`) and **policies** where resources exist (`Session`, `Station`, …).
- [ ] If the PR is an **intentional exception** (hotfix, spike): it states **why**, links a follow-up bead/issue, and is time-boxed.

---

## Quick references

| Topic | Doc / code |
|--------|------------|
| Catalog constants | [`app/Support/PermissionCatalog.php`](../../app/Support/PermissionCatalog.php) |
| Site/program context | [`app/Services/RbacContextService.php`](../../app/Services/RbacContextService.php) |
| Policy cleanup / patterns | [`RBAC_POLICY_CLEANUP.md`](RBAC_POLICY_CLEANUP.md) |
| Route ↔ permissions | [`docs/architecture/PERMISSIONS-MATRIX.md`](../architecture/PERMISSIONS-MATRIX.md) |
| Identity (login, credentials) | [`HYBRID_AUTH_ADMIN_FIRST_PRD.md`](HYBRID_AUTH_ADMIN_FIRST_PRD.md) — **H1+**; not covered by this checklist |

---

## Document history

| Date | Change |
|------|--------|
| **2026-03-22** | Initial R1 checklist for PR authors and reviewers. |
