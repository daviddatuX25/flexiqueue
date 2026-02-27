# Staff Dashboard — Plan and Spec

**Bead:** flexiqueue-hzs — Staff: Back to Dashboard is useless; plan staff dashboard  
**Status:** Plan/spec only (no implementation in this deliverable)  
**Related:** `09-UI-ROUTES-PHASE1.md`, `04-DATA-MODEL.md`, `08-API-SPEC-PHASE1.md`

---

## 1. Problem Statement

The staff layout (`MobileLayout.svelte`) shows a **"Dashboard"** back link (and the same in the user dropdown) that points to `GET /dashboard`. For non-admin users (staff and supervisor), that route **redirects to the Station page** (`/station`). So "Back to Dashboard" is misleading: staff land on the same operational queue view with no dedicated dashboard. The link is effectively "go to station" with a wrong label.

**Current behaviour (from code):**

- **Routes** (`routes/web.php`): `GET /dashboard` → if Admin → `admin.dashboard`; else → `station`.
- **MobileLayout** (`resources/js/Layouts/MobileLayout.svelte`): For staff, `backHref = "/dashboard"`, `backLabel = "Dashboard"`.
- **09-UI-ROUTES-PHASE1.md** already notes: "Staff home / future staff dashboard URL."

This plan defines a **dedicated staff dashboard**: a view where staff see **their own** metrics and performance, distinct from the admin dashboard (program-level) and the station page (operational queue).

---

## 2. Purpose — What Staff See on "Their" Dashboard

When staff (or supervisor) go to "their" dashboard, they should see:

- **A personal summary view**, not the operational station queue.
- **Metrics and performance analysis specific to that staff member** (sessions they served, time per client, activity at their station), not program-wide or station-wide aggregates.
- **No quick links** to Station, Triage, or Track Overrides on the dashboard itself—the app already has a consistent footer nav for those. The dashboard is metrics-only.

So the purpose is:

- **Replace the current redirect** so that `GET /dashboard` (for staff/supervisor) renders a **Staff Dashboard** page instead of redirecting to `/station`.
- **Keep "Back to Dashboard" meaningful**: it returns to this summary view, not to the queue.
- **Leave Station page unchanged** for operational work (call next, transfer, complete, etc.).

---

## 3. Proposed Metrics and Performance Analysis (Per Staff)

All metrics are **scoped to the authenticated user** (and optionally to the active program and date range).

| Metric | Description | Data source |
|--------|-------------|-------------|
| **Sessions served today** | Count of clients this staff member has served today (e.g. check-in at their station, or call + complete/transfer by them). | `transaction_logs` where `staff_user_id = current user`, `action_type IN ('check_in', …)` and/or sessions where this staff performed call/complete/transfer at a station; filter by `created_at` today. |
| **Average time per client (today)** | Mean duration from check-in (or call) to transfer/complete for sessions this staff served. | Same as StationQueueService duration logic, but filtered by `transaction_logs.staff_user_id`. |
| **Queue stats for their station** | For the staff’s assigned station (if any): current waiting count, maybe "served today" at station (existing station-level stats). | Existing station queue API or equivalent; optionally cached/summarized for dashboard. |
| **Recent activity (today)** | Counts or list of actions by type (bind if at triage, check_in, transfer, complete, override, etc.) for this staff. | `transaction_logs` filtered by `staff_user_id`, `created_at` today (index `idx_logs_staff (staff_user_id, created_at)`). |
| **Optional: Triage binds today** | If the staff performed triage: number of sessions they bound today. | `transaction_logs` where `staff_user_id = user` and `action_type = 'bind'` (and optionally `station_id` NULL for triage). |

**Performance analysis (optional for later):**

- Trend: sessions served per day (e.g. last 7 days).
- Comparison to station average (e.g. "You served 12; station total 24").
- Busiest hour today (from transaction_log timestamps).

**Data model support (existing):**

- `transaction_logs`: `staff_user_id`, `action_type`, `station_id`, `session_id`, `created_at` (see `04-DATA-MODEL.md` Table 7). Index `idx_logs_staff (staff_user_id, created_at)` supports per-staff audit and aggregations.
- `queue_sessions` + `transaction_logs`: can derive "served by" and duration (check_in → transfer/complete) per staff.
- Station-level stats today are already computed in `StationQueueService` (per station); staff dashboard can add a **staff-scoped** service or extend the same service to filter by `staff_user_id`.

---

## 4. Suggested Route and Page

- **Route:** Keep `GET /dashboard` as the canonical URL for "staff home."
- **Behaviour:**
  - **Admin:** redirect to `route('admin.dashboard')` (unchanged).
  - **Staff / Supervisor:** render a **Staff Dashboard** page (Inertia), do **not** redirect to station.
- **Page component:** e.g. `Staff/Dashboard.svelte` or `Dashboard/Staff.svelte` (under `resources/js/Pages/`).
- **Layout:** Use the same **MobileLayout** as Station/Triage/Track Overrides so the header ("Dashboard" back link, user menu) and bottom dock (Station | Triage | Track Overrides) are consistent. The "Back to Dashboard" link then keeps users on the same dashboard when they navigate to Station and back.
- **Optional:** Add an explicit "Staff Dashboard" item in the user dropdown (linking to `/dashboard`) so it’s clear what "Dashboard" means; the back arrow already does the same.

No new top-level route is required; only the behaviour of existing `GET /dashboard` for non-admin changes from "redirect to station" to "render Staff Dashboard."

---

## 5. UI Sections (Wireframe / List)

Suggested sections on the Staff Dashboard page (mobile-first, scrollable):

1. **Header (within layout)**  
   - Title: e.g. "My Dashboard" or "Dashboard" (layout already provides back link and page title).

2. **Summary cards (today)**  
   - **Sessions served today** — single prominent number (and optional comparison to station total).  
   - **Average time per client** — e.g. "X min average" (today).  
   - **Your station** — name of assigned station (if any) and current queue count (or "No station assigned").

3. **Your station**  
   - Short summary: station name (if assigned) and current queue count. No CTA link—footer nav provides Station.

4. **Recent activity (today)**  
   - List or counts of actions: e.g. "5 check-ins, 4 transfers, 3 completions" or a compact list of last N actions (alias, action, time). Data from `transaction_logs` for this user, today.

5. **No quick links**  
   - Do not add Station / Triage / Track Overrides buttons on the dashboard; the layout already provides a consistent footer nav for those. The dashboard is about metrics only.

6. **Optional (later)**  
   - Simple trend: "Sessions served last 7 days" (bar or list).  
   - Date selector: "Today" vs "Yesterday" or a single previous date.

No wireframe image is included here; the above list is sufficient for implementation. Design should follow `07-UI-UX-SPECS.md` and Skeleton/Tailwind patterns used elsewhere (e.g. Admin Dashboard cards).

---

## 6. Dependencies on Existing Data and Code

- **transaction_logs:** Primary source for per-staff metrics. Already has `staff_user_id` and `idx_logs_staff (staff_user_id, created_at)`. No schema change required.
- **queue_sessions:** For "sessions served" and duration; join with transaction_logs (check_in/transfer/complete) per staff. No schema change.
- **StationQueueService:** Logic for "served today" and average service time is per station; can be reused or duplicated in a **StaffDashboardService** (or similar) that filters by `staff_user_id` and optionally by station (user’s assigned station).
- **Station queue API:** `GET /api/stations/{id}/queue` (or equivalent) already returns queue and station-level stats; dashboard can call it for the user’s assigned station to show "queue at your station."
- **Auth and role:** Already in place; dashboard is behind `auth` and `role:admin,supervisor,staff`. Admin branch redirects to admin dashboard; staff/supervisor get the new page.
- **Active program:** If metrics are program-scoped (e.g. "today" within the active program session), use existing active-program resolution (e.g. from HandleInertiaRequests or similar). If not, "today" can be calendar-day.

---

## 7. How This Differs From Other Views

| View | Audience | Purpose | Scope |
|------|-----------|---------|--------|
| **Admin Dashboard** | Admin only | System health, program status, all stations, quick actions (e.g. manage programs, tokens). | Program-level and system-level. |
| **Station page** | Staff, supervisor, admin (when at a station) | Operational: see queue, call next, transfer, complete, overrides. | One station’s queue and actions. |
| **Staff Dashboard (proposed)** | Staff, supervisor (non-admin) | Personal summary: *my* sessions served, *my* average time, *my* station’s queue, *my* activity. | Per-staff metrics and their assigned station. |

So:

- **Admin dashboard** = "How is the program / system doing?" (global).
- **Station page** = "What do I do next at this station?" (operational).
- **Staff dashboard** = "How did I do today?" (personal performance metrics only; navigation to Station/Triage/Track Overrides is via the footer nav).

---

## 8. Optional: Minimal Placeholder Route

If desired before full implementation, the route can be updated to render a **minimal placeholder** page (e.g. "Staff Dashboard — Coming soon" and links to Station, Triage, Track Overrides) so that "Back to Dashboard" already goes to a dedicated URL instead of redirecting to station. This is optional and not required for this plan deliverable.

---

## 9. Implementation Checklist (Done)

1. ~~Add `StaffDashboardController`~~ — Done; redirects admin to admin dashboard, renders Staff/Dashboard for staff/supervisor.
2. ~~Change `GET /dashboard`~~ — Route invokes `StaffDashboardController`; staff/supervisor get Staff Dashboard (no redirect to station).
3. ~~Add `resources/js/Pages/Staff/Dashboard.svelte`~~ — Done; uses MobileLayout, metrics-only (no quick links).
4. ~~Staff metrics~~ — `StaffDashboardService` provides sessions_served_today, average_time_per_client_minutes, station summary, activity_counts_today (from `transaction_logs`).
5. ~~UI sections~~ — Summary cards (sessions served, avg time), Your station, Activity today. No quick links (footer nav only).
6. Update 09-UI-ROUTES-PHASE1.md to document the Staff Dashboard page and route behaviour (optional).
7. ~~PHPUnit tests~~ — `test_staff_dashboard_returns_200_with_metrics`, `test_admin_visiting_dashboard_redirects_to_admin_dashboard`.

---

## 10. Summary

- **Purpose:** Staff get a real "Dashboard" view with **per-staff** metrics and performance (sessions served today, average time per client, queue at their station, recent activity).
- **Route:** Keep `GET /dashboard`; for staff/supervisor render Staff Dashboard instead of redirecting to station.
- **Data:** `transaction_logs` (and existing queue/session services) already support staff-level metrics; no new tables required.
- **Difference:** Admin dashboard = program/system; Station = operational queue; Staff dashboard = personal summary and home base for Station/Triage/Track Overrides.
