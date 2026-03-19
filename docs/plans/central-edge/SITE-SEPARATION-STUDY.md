# Site Separation — Deep Study

**Purpose:** Identify which entities need site separation (or already have it) so multi-tenant central does not leak data across sites. This doc is the single reference for "what is scoped by site today" and "what still needs separation."

**Status:** Analysis only; no implementation. Use this to prioritize follow-up beads or backlog items.

---

## 1. Summary

| Category | Count | Notes |
|----------|--------|--------|
| **Direct `site_id`** | 2 tables | `programs`, `users` — fully site-scoped in schema and app. |
| **Indirect (via program or user)** | Many | Sessions, logs, identity registrations, stations, etc. — safe as long as access is always through a site-scoped program or user. |
| **Global, no site linkage** | 4 entity areas | **Tokens**, **Clients** (and client_id_documents), **Print settings**, **Admin action log** (platform-level). |
| **At-risk today** | 2 | **Tokens** and **Clients**: admin APIs/pages list all rows with no site filter → cross-site visibility. |

---

## 2. Schema: What Has `site_id` Today

Only two tables have a direct `site_id` foreign key:

- **`programs.site_id`** — every program belongs to one site. All admin program listing/CRUD is scoped by `user.site_id` (and super_admin can filter).
- **`users.site_id`** — every admin/staff belongs to one site. User listing and creation are scoped by site (or by filter for super_admin).

No other table has `site_id`. So any other entity is either:

- **Indirectly site-scoped** — reachable only via a program or user (e.g. sessions → program → site), or  
- **Global** — not tied to site in the schema, so the app must scope by convention or filter.

---

## 3. Entity-by-Entity Analysis

### 3.1 Programs

| Aspect | Status |
|--------|--------|
| Schema | `programs.site_id` |
| API/UI | Index/show/store/update scope by `$request->user()->site_id`; 404 if program not in site. |
| Cross-site risk | None. |

---

### 3.2 Users

| Aspect | Status |
|--------|--------|
| Schema | `users.site_id` |
| API/UI | Index/create/update scope by site; super_admin can filter by `site_id` or see all. |
| Cross-site risk | None. |

---

### 3.3 Tokens

| Aspect | Status |
|--------|--------|
| Schema | **No `site_id`.** Global pool. |
| Link to site | Only via **program_token** (token ↔ program). Program has site_id, so "tokens in my site" = tokens attached to my site’s programs. |
| API/UI today | **TokenController::index()** returns **all** tokens (filtered only by status/search). No site or program filter. So Site B admin can see Site A’s tokens. |
| Cross-site risk | **Yes.** Same token can be assigned to programs in different sites; and the global token list shows every token to every admin. |

**Recommendation:**

- **Option A (schema):** Add `tokens.site_id`. On create, set from context (e.g. creating user’s site or program’s site). All token list/create/update scope by site. Assignment to program still requires program in same site (or relax if you allow cross-site assignment by design).
- **Option B (no schema):** Keep tokens global. Scope **list** and **assignment** by site: e.g. "tokens assignable to this program" = tokens that are either (a) already in a program in my site, or (b) in a dedicated "unassigned" pool that is only visible when operating in a program context (so effectively "tokens for my site’s programs"). Bulk assign already receives program id (so you could restrict "assignable" tokens to those already in any program of the same site, or allow any token). Clarify product intent: shared physical token pool across sites vs per-site token pools.
- **Option C (seeder-only):** For testing only: seed separate token rows per site (e.g. LGU seeder creates its own tokens) and document that production may still share. Does not fix the global list API.

---

### 3.4 Clients

| Aspect | Status |
|--------|--------|
| Schema | **No `site_id`.** Only `name`, `birth_year`, timestamps. |
| Link to site | Only via **queue_sessions** (session → program → site) and **identity_registrations** (program → site). So "clients that ever had a session in my site’s programs" is derivable. |
| API/UI today | **ClientPageController::index()** and **ClientAdminController** use `Client::query()` with **no** site or program filter. So Site B admin can see and open any client (including those only ever used in Site A). |
| Cross-site risk | **Yes.** Client list and detail are global. |

**Recommendation:**

- **Option A (schema):** Add `clients.site_id`. Set when client is created (e.g. from creating user’s site, or from program’s site when created via triage/session). All client list/detail/create/update scope by site. Requires migration and rule for existing rows (e.g. assign to default site, or derive from "any session’s program’s site").
- **Option B (query-only):** No new column. Scope client list to "clients that have at least one queue_session in a program in my site" (e.g. `Client::whereHas('queueSessions', fn ($q) => $q->whereIn('program_id', $mySiteProgramIds))`). Slower and more complex; show/detail must enforce same rule (client must have at least one session in my site’s programs). Creation: set no site; list still derived. Or restrict creation to "current user’s site" by storing site_id on a related entity (e.g. first session or first identity_registration).
- **Option C (hybrid):** Add `clients.site_id` for **new** clients (and backfill from first session’s program’s site). List/show scope by site_id. Old clients without site_id: treat as "legacy" and either hide from site-scoped list or assign to default site in a one-time migration.

**Note:** Central-edge v2 spec says clients are created on central and Pi syncs; client dedup is hash-based. It does not explicitly say "clients are per-site." So product decision: are clients tenant-scoped (each site has its own client registry) or global (one registry, and we only scope who can *see* them)? Most multi-tenant systems make clients tenant-scoped.

---

### 3.5 Client ID Documents / Client ID Audit Log

| Aspect | Status |
|--------|--------|
| Schema | `client_id_documents.client_id`, `client_id_audit_log` references client/session. No site_id. |
| Link to site | Via client (if client gets site_id) or via usage in sessions (program → site). |
| API/UI today | Accessed via client or session. If client list is scoped, these are implicitly scoped; if not, same leak as clients. |
| Cross-site risk | Same as Clients. Fix client scoping first. |

---

### 3.6 Queue Sessions (queue_sessions)

| Aspect | Status |
|--------|--------|
| Schema | `program_id` (and token_id, client_id, etc.). No site_id. |
| Link to site | Session → program → site. |
| API/UI today | All session access is via program or station (which are site-scoped). No global "list all sessions" for admin. |
| Cross-site risk | **None** if every session path goes through a program or user that is already site-checked. |

---

### 3.7 Identity Registrations

| Aspect | Status |
|--------|--------|
| Schema | `program_id`. No site_id. |
| Link to site | program → site. |
| API/UI today | Listed/fetched in program or session context (program is site-scoped). |
| Cross-site risk | **None** as long as no API lists identity_registrations without a program (or with a program that wasn’t checked for site). |

---

### 3.8 Transaction Logs / Program Audit Log

| Aspect | Status |
|--------|--------|
| Schema | session_id → program; or program_id. No site_id. |
| Link to site | Via session or program. |
| API/UI today | Report/audit controllers filter by program or user (programs in site; users in site). |
| Cross-site risk | **None** if report/audit only ever scope by site’s programs/users. |

---

### 3.9 Staff Activity Log

| Aspect | Status |
|--------|--------|
| Schema | `user_id`. No site_id. |
| Link to site | user → site. |
| API/UI today | Shown in audit/reports; if those views only include staff in current site, effectively scoped. |
| Cross-site risk | **Low** if audit/report APIs only return data for users in the admin’s site. Verify no "list all staff_activity_log" without user/site filter. |

---

### 3.10 Stations, Processes, Tracks, Track Steps, Program Diagram, Program Station Assignments, Program Supervisors

| Aspect | Status |
|--------|--------|
| Schema | All have `program_id` (or program_id + user_id). No site_id. |
| Link to site | program → site. |
| API/UI today | Always accessed in program context; program is site-scoped. |
| Cross-site risk | **None.** |

---

### 3.11 Program Token (pivot)

| Aspect | Status |
|--------|--------|
| Schema | `program_id`, `token_id`. No site_id. |
| Link to site | program → site. |
| API/UI today | Assign/unassign are program-scoped (program is checked for site). So "which tokens are in this program" is site-safe. The only leak is the **token list** itself (see Tokens). |
| Cross-site risk | **None** for the pivot; risk is global token list. |

---

### 3.12 Print Settings

| Aspect | Status |
|--------|--------|
| Schema | **No program_id or site_id.** Single table; repository uses `PrintSetting::first()` (singleton). |
| Link to site | None. |
| API/UI today | One global instance. Any admin that can open print settings sees the same one. |
| Cross-site risk | **Yes.** Site B can read/update the same print settings as Site A. |

**Recommendation:**

- **Option A:** Add `site_id` (or `program_id`) to `print_settings`. One row per site (or per program). Repository and API take site from auth (or program from context) and return/update the right row.
- **Option B:** Keep global singleton and document that print settings are system-wide (one tenant or shared intentionally). Only acceptable if product wants a single print config for the whole instance.

---

### 3.13 Admin Action Log

| Aspect | Status |
|--------|--------|
| Schema | `user_id`, subject_type, subject_id, payload. No site_id. |
| Link to site | user → site. |
| API/UI today | Super_admin sees admin-level audit (user/site changes). Site admin typically does not see this log (per SUPER-ADMIN-VS-ADMIN-SPEC). If site admin ever sees it, filter by `user.site_id` so they only see actions by users in their site. |
| Cross-site risk | **Low** if only super_admin sees it; if site admin sees it, must filter by user’s site. |

---

### 3.14 Temporary Authorizations (PIN/QR)

| Aspect | Status |
|--------|--------|
| Schema | `user_id`. No site_id. |
| Link to site | user → site. |
| API/UI today | Created and used in station/session context (user is site-scoped). |
| Cross-site risk | **None** if only used in contexts where user is already site-scoped. |

---

### 3.15 TTS (Token TTS Settings, etc.)

| Aspect | Status |
|--------|--------|
| Schema | Token-related; tokens are global. No site_id on TTS tables. |
| Link to site | Only via token → program_token → program → site. |
| API/UI today | TTS is used in token or station context; if token list is scoped, TTS is scoped. |
| Cross-site risk | Same as Tokens. |

---

## 4. Priority Matrix

| Priority | Entity | Current risk | Suggested action |
|----------|--------|---------------|------------------|
| **High** | **Tokens** | Site B sees and can assign all tokens. | Add site scoping: either `tokens.site_id` + scope all token APIs, or scope token list/assign by "program in my site" and document shared vs per-site pool. |
| **High** | **Clients** | Site B sees all clients and ID docs. | Add `clients.site_id` (and set on create/backfill) and scope all client list/detail/create; or scope list by "has session in my site’s programs" and enforce on show. |
| **Medium** | **Print settings** | Single global row; Site B can change what Site A uses. | Add `print_settings.site_id` (or program_id) and one row per site (or per program); scope API by auth site. |
| **Low** | Admin action log | Only super_admin sees it; if site admin ever does, filter by user.site_id. | Verify audit UI filters by site when shown to site admin. |
| **Deferred** | Token TTS, etc. | Follow token scoping. | After tokens are site-scoped, TTS is implicitly scoped. |

---

## 5. Recommended Follow-Up Backlog Items

1. **Token site separation** — Either: (a) add `tokens.site_id`, set on create from user/program site, scope token list/create/update and bulk-assign by site; or (b) keep tokens global but scope admin token list to "tokens that appear in my site’s programs" plus optional "unassigned" pool and document behavior. Add to `follow-up-backlog/token-per-site-or-pool.md` and link from central-edge-tasks if done as a bead.
2. **Client site separation** — Add `clients.site_id`; set when creating client (from user’s site or program’s site); backfill existing; scope client list, show, create, update, and client ID document APIs by site. Add to follow-up backlog.
3. **Print settings per site** — Add `print_settings.site_id` (or program_id); one row per site (or per program); scope PrintSettingRepository and API by authenticated user’s site. Add to follow-up backlog.
4. **Verification pass** — After any of the above, run a "Site A vs Site B" test: two sites, two admins; assert no cross-site visibility for tokens, clients, print settings, and audit views.

---

## 6. References

- **Phase B:** `central-edge-v2-final.md` § Phase B — only `programs` and `users` get `site_id`.
- **Phase C:** Token–program association; no `site_id` on tokens.
- **SITES-AND-ROLES.md** — site = tenant; programs and users belong to site.
- **SUPER-ADMIN-VS-ADMIN-SPEC.md** — site admin has "own site / program scope" for tokens and clients; current implementation does not enforce site on token or client list.
- **04-DATA-MODEL.md** — core entities; does not define site scoping for tokens or clients.
