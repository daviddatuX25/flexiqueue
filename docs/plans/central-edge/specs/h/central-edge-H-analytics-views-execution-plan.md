# Phase H — Analytics Views — Execution Plan

**Reference:** [central-edge-tasks.md](../../central-edge-tasks.md) (H.1–H.4), [central-edge-v2-final.md](../central-edge-v2-final.md) (Phase H — Analytics Views)  
**Goal:** Add `source`, `siteId`, and `bindingStatus` filters to analytics; implement Edge local, Central per-site, all-sites aggregate, edge-only, and unconfirmed bindings views; add Source and Binding status filter dropdowns in Admin UI.

**Status:** Draft — requires Phase F (source + binding_status columns on queue_sessions) and existing AnalyticsService/Admin Analytics page.

---

## Prerequisites

- **Phase F** — `queue_sessions.source` (e.g. `'central'` or `'edge:{SITE_ID}'`), `queue_sessions.binding_status` (e.g. `'verified'`, `'unconfirmed'`, `'reviewed'`) columns exist and are populated.
- **Existing:** `AnalyticsService` with `getSummary()`, `getThroughput()`, etc.; `AnalyticsController` (API) with `filters(Request)`; Admin Analytics page at `resources/js/Pages/Admin/Analytics/Index.svelte` that fetches via `/api/admin/analytics/*`.

---

## AnalyticsService filter contract

Extend filters so all analytics methods accept:

| Filter key | Type | Description |
|------------|------|-------------|
| `program_id` | `int \| null` | Existing. |
| `track_id` | `int \| null` | Existing. |
| `from` | `string` (Y-m-d) | Existing. |
| `to` | `string` (Y-m-d) | Existing. |
| `source` | `string \| null` | When set: `where('source', $source)`. Values: `'central'`, or `'edge:{siteId}'` for a specific site, or `'edge'` for any edge (source LIKE 'edge:%'). |
| `site_id` | `string \| int \| null` | When set (and source not explicitly 'central'): scope to sessions from that site: `where('source', 'edge:' . $siteId)`. |
| `binding_status` | `string \| null` | When set: `where('binding_status', $binding_status)`. Values: `'verified'`, `'unconfirmed'`, `'reviewed'`. |

**View-to-filter mapping (spec):**

| View | Filter applied | Available on |
|------|----------------|--------------|
| Edge local | `source = 'edge:{SITE_ID}'` AND `created_at >= today` | Pi only |
| Central — per site | `source = 'edge:{siteId}'` OR (central + site-scoped program) | Central |
| Central — all sites | No source filter, all programs | Central |
| Central — edge only | `source LIKE 'edge:%'` | Central |
| Central — unconfirmed bindings | `binding_status = 'unconfirmed'` | Central (admin) |

---

## Delegateable tasks

### H.1 — AnalyticsService: add source, siteId, bindingStatus filters

**Scope:** Extend `applySessionFilters()` (or equivalent) and every method that builds a session query so they accept and apply `source`, `site_id`, and `binding_status`.

**Steps:**

1. **AnalyticsService**  
   - Add to the internal filter array (and to `getSummary`, `getThroughput`, `getWaitTimeMinutesForSessions`, `getWaitTimeDistribution`, `getStationUtilization`, `getTrackPerformance`, `getBusiestHours`, `getDropOffFunnel`):  
     - If `source` is present:  
       - `source === 'central'` → `$query->where('source', 'central')`.  
       - `source === 'edge'` → `$query->where('source', 'like', 'edge:%')`.  
       - `source` starting with `edge:` (e.g. `edge:mswdo-dagupan`) → `$query->where('source', $source)`.  
     - If `site_id` is present (and not already constrained by source): `$query->where('source', 'edge:' . $site_id)`.  
     - If `binding_status` is present: `$query->where('binding_status', $binding_status)`.  
   - Ensure all queries that filter by `queue_sessions` use the same helper (e.g. `applySessionFilters`) so `source`/`site_id`/`binding_status` are applied consistently.  
   - For **Edge local** view (Pi): caller passes `source = 'edge:{SITE_ID}'` and `from`/`to` = today; no change to DB schema beyond Phase F columns.

2. **Tests**  
   - Unit or feature tests: with seeded sessions (mixed source and binding_status), call `getSummary()` with `source => 'central'`, then `source => 'edge'`, then `source => 'edge:site-1'`, then `binding_status => 'unconfirmed'`; assert counts match expected subsets.

**Files:**

- `app/Services/AnalyticsService.php` (extend filter application in all session-based methods).
- `tests/Unit/Services/AnalyticsServiceTest.php` or `tests/Feature/Api/Admin/AnalyticsControllerTest.php`.

---

### H.2.1 — Edge local view (Pi — today's sessions only)

**Scope:** On Pi, analytics view shows only today's sessions for this site (source = edge:{SITE_ID}, date = today).

**Steps:**

1. **Backend**  
   - When `APP_MODE=edge` (or when request is from Pi): analytics API or controller can resolve `SITE_ID` from config and set default filters: `source => 'edge:' . config('services.edge.site_id')`, `from` and `to` => today.  
   - Alternatively: Admin Analytics page on Pi is the only one that can show "Edge local"; the page passes `source=edge:{SITE_ID}` and `from`/`to`=today when loading.

2. **Frontend**  
   - When shared props indicate edge mode (e.g. `edgeMode === true` and optionally `edgeAnalyticsView === 'local'`):  
     - Default `source` filter to `edge:{siteId}` and date range to "today".  
     - Optionally hide or gray out "Source" dropdown and show a label "Local (this device) only".  
   - Fetch summary/throughput/etc. with these filters. No changes to AnalyticsService beyond H.1.

**Files:**

- `app/Http/Controllers/Api/Admin/AnalyticsController.php` or middleware: when edge, inject default `source` and today's date (or leave to frontend).
- `resources/js/Pages/Admin/Analytics/Index.svelte`: when `edgeMode`, set default source and date to today; optionally restrict source dropdown to "Local only".

---

### H.2.2 — Central per-site view

**Scope:** On Central, admin can select a site and see analytics for that site only (sessions where source = edge:{siteId} plus any central sessions that belong to that site’s programs).

**Steps:**

1. **Clarification**  
   - Per spec: "Central — per site: source LIKE 'edge:{siteId}%' OR (source = 'central' AND site-scoped program)". So filter is: (source = 'edge:' . siteId) OR (source = 'central' AND program.site_id = siteId).  
   - AnalyticsService may need a combined filter: when `site_id` is set, base query is something like:  
     `(source = 'edge:' . site_id) OR (source = 'central' AND program_id IN (programs for this site))`.  
   - Implement a helper that, given `site_id`, applies this OR condition to the session query.

2. **API**  
   - Accept `site_id` in query params. When present, apply the per-site filter above.  
   - Sites list for dropdown: from existing admin sites API or shared Inertia props (e.g. `sites: [{ id, name, slug }]`).

3. **Frontend**  
   - Add "Site" dropdown (or "Source / Site" combined): options "All sites", then per-site (e.g. "Central only", "Edge: Site A", "Edge: Site B").  
   - When a site is selected, send `site_id` (and optionally `source` if needed) so backend returns per-site view.

**Files:**

- `app/Services/AnalyticsService.php` (per-site filter logic).
- `app/Http/Controllers/Api/Admin/AnalyticsController.php`: pass `site_id` into filters.
- `resources/js/Pages/Admin/Analytics/Index.svelte`: Site dropdown, pass site_id in API calls.

---

### H.2.3 — Central all-sites aggregate

**Scope:** Default or explicit "All sites" view: no source/site filter; all programs and all sessions. Ensure no double-counting.

**Steps:**

1. **Backend**  
   - When neither `source` nor `site_id` is passed, do not add source/site conditions. Existing program_id/track_id/from/to only.  
   - Verification (H.4.2): aggregate = sum of per-site totals; add test that sums per-site counts and compares to all-sites count.

2. **Frontend**  
   - "Source" or "Site" dropdown includes "All sites" (or "All"); selecting it omits source and site_id from query params.

**Files:**

- `app/Services/AnalyticsService.php` (no source/site when not provided).  
- `resources/js/Pages/Admin/Analytics/Index.svelte`: "All sites" option.  
- Tests: assert all-sites total = sum of each site’s total.

---

### H.2.4 — Central edge-only view

**Scope:** Filter where source LIKE 'edge:%' (all edge sessions, any site).

**Steps:**

1. **Backend**  
   - When `source === 'edge'` (or a dedicated param), apply `where('source', 'like', 'edge:%')`. Already covered in H.1.

2. **Frontend**  
   - Source dropdown option: "Edge only" (or "All edge sites"); send `source=edge` in API calls.

**Files:**

- `app/Services/AnalyticsService.php` (H.1).  
- `resources/js/Pages/Admin/Analytics/Index.svelte`: add "Edge only" to source dropdown.

---

### H.2.5 — Central unconfirmed bindings view

**Scope:** Filter where binding_status = 'unconfirmed'. Used for admin review of offline-created bindings.

**Steps:**

1. **Backend**  
   - When `binding_status` is in filters, apply it (H.1).  
   - Add option in API to request only unconfirmed: `binding_status=unconfirmed`.

2. **Frontend**  
   - Binding status dropdown (H.3.2): option "Unconfirmed only"; send `binding_status=unconfirmed`.  
   - Summary/charts then show only sessions with unconfirmed bindings (and optional source/site/date filters).

**Files:**

- `app/Services/AnalyticsService.php` (H.1).  
- `resources/js/Pages/Admin/Analytics/Index.svelte`: binding status filter (H.3.2) drives this view.

---

### H.3.1 — Source filter dropdown (Admin UI)

**Scope:** Admin Analytics page has a Source filter: All / Central / Edge / specific site (or "Edge: Site A", "Edge: Site B").

**Steps:**

1. **Data for dropdown**  
   - Options: "All", "Central only", "Edge only", and optionally per-site "Edge: {site name}" (from sites list).  
   - Backend: ensure sites list is available (e.g. shared Inertia prop or GET /api/admin/sites).  
   - Map selection to query: All → no source/site_id; Central only → source=central; Edge only → source=edge; Edge: Site X → source=edge:{site_slug_or_id} or site_id param.

2. **Analytics/Index.svelte**  
   - Add state: `sourceFilter: 'all' | 'central' | 'edge' | string` (string = site id for "Edge: Site X").  
   - Dropdown component (Skeleton select or custom): label "Source", options as above.  
   - Include `source` (and `site_id` when per-site) in `queryParams()` and in every analytics API call (summary, throughput, wait distribution, etc.).

3. **Accessibility**  
   - Select has `<label>` or `aria-label`; options readable. Touch target for trigger per 07-UI-UX-SPECS.

**Files:**

- `resources/js/Pages/Admin/Analytics/Index.svelte` (source dropdown + query params).  
- Backend: ensure sites list available; AnalyticsController accepts source and site_id (H.1).

---

### H.3.2 — Binding status filter dropdown (Admin UI)

**Scope:** Admin Analytics page has a Binding status filter: All / Verified / Unconfirmed / Reviewed.

**Steps:**

1. **Options**  
   - "All" → do not send binding_status.  
   - "Verified", "Unconfirmed", "Reviewed" → send `binding_status=verified|unconfirmed|reviewed`.

2. **Analytics/Index.svelte**  
   - Add state: `bindingStatusFilter: 'all' | 'verified' | 'unconfirmed' | 'reviewed'`.  
   - Dropdown: label "Binding status"; include in `queryParams()` and all analytics API calls.

3. **Backend**  
   - Already supported once H.1 is done; no extra change.

**Files:**

- `resources/js/Pages/Admin/Analytics/Index.svelte` (binding status dropdown + query params).  
- `app/Http/Controllers/Api/Admin/AnalyticsController.php`: read `binding_status` from request and pass to AnalyticsService (in `filters()` method).

---

## Verification (H.4)

- **H.4.1** — No NULLs in `source` or `binding_status`: migration default and backfill; test `SELECT COUNT(*) FROM queue_sessions WHERE source IS NULL OR binding_status IS NULL` = 0.  
- **H.4.2** — All-sites aggregate = sum of per-site totals: test that sums per-site summary totals equal all-sites summary total.  
- **H.4.3** — Pi analytics shows only local sessions: when `source=edge:{SITE_ID}` and date=today, counts match only that site’s sessions for today.

---

## File list (Phase H)

| File | Purpose |
|------|--------|
| `app/Services/AnalyticsService.php` | Add source, site_id, binding_status to applySessionFilters and all session-based methods; per-site OR logic for H.2.2. |
| `app/Http/Controllers/Api/Admin/AnalyticsController.php` | Read `source`, `site_id`, `binding_status` from request; pass to AnalyticsService. |
| `resources/js/Pages/Admin/Analytics/Index.svelte` | Source dropdown (H.3.1), Binding status dropdown (H.3.2); default filters for edge local (H.2.1); pass filters in all API calls. |
| Optional: controller or middleware for Pi that injects default analytics filters (edge + today) when edge mode. | H.2.1. |
| `tests/Unit/Services/AnalyticsServiceTest.php` or Feature | Filter behavior for source, site_id, binding_status; H.4.2 aggregate test. |
| `tests/Feature/Api/Admin/AnalyticsControllerTest.php` | Request with source/binding_status params; assert response counts. |

---

## Summary

- **H.1** — AnalyticsService: implement and test source, siteId, bindingStatus filters everywhere.  
- **H.2.1** — Edge local: default to source=edge:{SITE_ID} and today on Pi.  
- **H.2.2** — Central per-site: filter by (edge:siteId OR central with program in site).  
- **H.2.3** — All-sites: no source/site filter; verify no double-counting.  
- **H.2.4** — Edge-only: source=edge (LIKE 'edge:%').  
- **H.2.5** — Unconfirmed bindings: binding_status=unconfirmed.  
- **H.3.1** — Source filter dropdown in Admin Analytics UI.  
- **H.3.2** — Binding status filter dropdown in Admin Analytics UI.  
- **H.4** — Verification tests for NULLs, aggregate = sum of parts, Pi local-only.
