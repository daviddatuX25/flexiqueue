# FlexiQueue — Feature Gaps & Improvement Opportunities

Based on reading the actual pages. These are missing capabilities, not refactors.

---

## 1. Analytics — No site-scoping on the filter bar

**What's there:** The Analytics page filter bar has date range, program, and track dropdowns. The programs dropdown fetches from `/api/admin/programs` — this returns all programs across the system.

**What's missing:** There's no site filter. If the admin is a super_admin managing multiple sites, the programId filter implicitly scopes to that program, but the summary KPIs (`/api/admin/analytics/summary`) are unfiltered unless a program_id is passed. This means the top-line numbers — total clients served, median wait, completion rate — always aggregate across all sites unless the admin manually drills into a specific program.

**The gap:** An admin at Tagudin MSWDO sees combined numbers that include test programs, other sites' programs, or inactive historical programs unless they manually pick one from the dropdown every time.

**Improvement:** Add a site selector above the program dropdown that filters the programs list and passes `site_id` to all analytics API calls. For super_admin this unlocks cross-site comparison. For site-scoped admin, pre-fill and lock it to their site.

---

## 2. Dashboard — 10-second polling with no WebSocket integration

**What's there:** Dashboard.svelte polls `/api/dashboard/stats` and `/api/dashboard/stations` on a 10-second `setInterval`. The rest of the app — Board, StationBoard, Station Index — all use WebSockets for live updates.

**What's missing:** The dashboard is the one screen an admin has open all day. It currently creates two HTTP requests every 10 seconds, unconditionally, regardless of whether anything changed. At 6 requests/minute × 2 endpoints = 12 backend hits per minute per admin device just to keep the dashboard current.

**Improvement:** Replace the polling with WebSocket subscriptions. The broadcast infrastructure is already there — `display.activity.{programId}` events already fire on every queue action. The dashboard just needs to subscribe to these same events and call `refresh()` only on relevant action types, instead of blindly polling. This drops the dashboard load from 12 hits/minute to near zero when operations are idle.

---

## 3. Dashboard — Active Program Card shows one program only

**What's there:** ActiveProgramCard.svelte receives `stats.active_program` — singular. It shows one program name, its track breakdown, and a "View Program" link to /station.

**What's missing:** The system supports multiple concurrent active programs (multi-program sessions, the StaffMultiProgramSelectorTest confirms this). The dashboard only surfaces one. If two programs are running simultaneously — which is a real MSWDO scenario during simultaneous relief payouts — the admin dashboard gives no indication the second program exists or is under load.

**Improvement:** Change ActiveProgramCard to an ActiveProgramsCard that shows a compact card per active program. Each card shows: program name, active session count, track breakdown bars, link to that program's station view. The HealthStats total numbers stay aggregated; the program cards break them down per program.

---

## 4. Analytics — No real-time "today" view; Export PDF is window.print()

**What's there:** The Export PDF button is literally `onclick={() => window.print()}`. The "Today" date range shows historical data from completed sessions.

**Two separate gaps:**

### Gap A — Export PDF

`window.print()` triggers the browser's print dialog on whatever is currently rendered. This includes the filter bar, navigation, skeleton loaders, etc. There's no print stylesheet, no `@media print` cleanup, no print-friendly layout. The result will be a mess.

**Improvement:** Implement a proper server-side PDF export via a dedicated `/api/admin/analytics/export?format=pdf` endpoint that returns a clean rendered PDF. On the frontend, swap the button to trigger a download fetch instead of `window.print()`. Alternatively, add a `@media print` stylesheet that hides everything except the KPI grid and charts.

### Gap B — Today view is stale

When `dateRangeKey === 'today'`, the analytics show whatever was completed as of the last manual filter change. There's no live updating. An admin watching operations on the "Today" filter sees numbers that are up to several minutes stale.

**Improvement:** When `dateRangeKey === 'today'`, auto-refresh `fetchAll()` every 60 seconds (not 10 — analytics are not real-time critical). Show a "Last updated X seconds ago" timestamp next to the filter bar. This is already partially possible since `debounceTimerRef` is in place — just add a periodic re-trigger for the today range only.

---

## 5. Logs — Filters require manual "Apply" button; no alias/token search

**What's there:** The logs filter panel has program, date, action type, station, staff, and program session dropdowns. All require you to set filters and then click "Apply filters" explicitly to trigger a fetch.

**Two gaps:**

### Gap A — No free-text search

There's no way to search for a specific token alias (e.g., "show me all log entries for A12"). An audit investigator trying to trace one specific client through the system has to manually scroll paginated results or export CSV and search there. The AuditLogEntry interface has `session_alias` — it's displayed in the table — but there's no search input for it.

**Improvement:** Add an alias/token search text input. Pass `alias` as a query param to the audit API. This is the single most useful COA audit action — tracing one token's entire lifecycle.

### Gap B — Apply button friction

Every filter change requires a separate "Apply" button click. The Analytics page uses debounced auto-fetch on filter change. The Logs page doesn't. This is inconsistent UX.

**Improvement:** Either auto-apply with debounce (same as Analytics), or make filters apply on blur/change. The apply button can stay for deliberate intent but shouldn't be the only way.

---

## 6. Logs — super_admin view is stripped compared to regular admin

**What's there:** In `buildAuditUrl`, if `auth_is_super_admin === true`, the filter set is dramatically reduced — only date range is passed. The program, station, staff, action type, and program session filters are all hidden. The page description text changes to "Platform admin actions (user and site changes) only."

**What's missing:** The super_admin effectively has a read-degraded audit view — fewer filters, narrower scope. This is probably intentional for the platform-level vs. operational separation, but it means a super_admin who is also overseeing operations can't use the Logs page the same way a site admin can. There's no way to switch between "platform audit" and "operational audit" views.

**Improvement:** Add a scope toggle for super_admin — "Platform actions" vs. "Operational" — that switches the filter set and API scope. Super_admin should have more visibility, not less.

---

## 7. Analytics — token-tts-health fetch has no date/program filter

**What's there:** In `fetchAll()`, all 7 analytics endpoints pass `queryParams()` (date range + program + track). The 8th call — `/api/admin/analytics/token-tts-health` — is hardcoded with no query params at all:

```js
fetchJson("/api/admin/analytics/token-tts-health"),  // no ?
```

**What this means:** The TTS & Token Health panel always shows system-wide token status regardless of the date range or program filter the admin has set. If you're looking at "Last 7 Days" for Program A, the TTS health donut charts reflect the entire token pool — not tokens used in that program during that range. The data is contextually disconnected from the rest of the page.

**Improvement:** Pass `queryParams()` to the token-tts-health endpoint too, and update the backend to scope by program/date if provided.

---

## 8. Settings — System page has a placeholder for future features that are still placeholder

**What's there:** At the bottom of the Storage tab there's an explicit placeholder section in the code:

```html
<!-- Placeholder for future sections: ZeroTier, OTA, health -->
Coming next: network & OTA controls
```

This ships in the current UI as a visible "Coming next" notice visible to admins.

**What's missing:** Given you're pausing on the distributed/edge architecture, this section will stay empty indefinitely if not addressed.

**Improvement options (pick one):** Either remove the placeholder entirely from the UI since the feature isn't coming in this version, or repurpose the space for something that's actually buildable now — like a server health section (PHP version, Laravel version, queue worker status, disk health summary reloaded live). The "queue worker running?" check in particular is directly relevant — if the queue worker dies, TTS generation stops silently.

---

## 9. Dashboard — No real-time staff availability visibility

**What's there:** HealthStats shows stations active/total/with_queue. StationStatusTable shows per-station client counts. Neither shows which staff members are online or their availability status.

The data exists — Board.svelte receives `staff_at_stations` with `staff[].availability_status` and `staff_online` count. The display board shows staff availability. The admin dashboard doesn't.

**What's missing:** An admin watching from the dashboard during a busy operation can't tell if a station is idle because there are no clients, or because the assigned staff member went on break and forgot to mark themselves back available. The distinction matters operationally — one is fine, the other is a service gap.

**Improvement:** Add a staff availability panel to the Dashboard. For each active station, show the assigned staff member name and their current `availability_status` dot (available / on_break / away). This already reacts to WebSocket `.staff_availability` broadcasts — the Board already does this, the Dashboard just needs to consume the same data.

---

## 10. Analytics — No per-staff performance breakdown

**What's there:** Track Performance (`/api/admin/analytics/tracks`) and Station Utilization (`/api/admin/analytics/station-utilization`) exist as charts. There's no chart or table for per-staff metrics.

**What's missing:** MSWDO operations are staff-intensive. An admin currently has no way to see from the analytics page which staff member handled the most sessions, which station has the slowest serve times, or whether a specific staff member's sessions have a disproportionate no-show rate vs. others on the same station. The data exists in transaction_logs (with user_id/staff fields visible in the logs table) but isn't surfaced analytically.

**Improvement:** Add a Staff Performance table to the Analytics page — columns: staff name, sessions handled, avg serve time, no-shows recorded, overrides performed. Scope it to the same date range and program filter. This is also directly useful for the COA accountability trail requirement the system describes.

---

## Summary — All 10 gaps

| # | Area     | Gap                                      | Value                                                |
|---|----------|------------------------------------------|------------------------------------------------------|
| 1 | Analytics | No site filter on KPI summary            | Cross-site admins see blended data                   |
| 2 | Dashboard | 10s polling instead of WebSocket         | 12 unnecessary server hits/min per admin device      |
| 3 | Dashboard | Single active program display            | Multi-program operations invisible                   |
| 4A | Analytics | window.print() PDF export                | Unusable output with nav/filters included            |
| 4B | Analytics | Today filter goes stale                  | Live operations not reflected                        |
| 5A | Logs     | No alias/token text search               | Can't trace a single client's full audit trail        |
| 5B | Logs     | Apply button friction vs. Analytics auto-fetch | Inconsistent UX across pages                   |
| 6 | Logs     | super_admin has fewer filters than regular admin | Degraded visibility for the highest role      |
| 7 | Analytics | TTS health ignores date/program filter   | Data disconnected from rest of page context          |
| 8 | Settings | Visible placeholder for unbuilt features | Dead UI space                                        |
| 9 | Dashboard | No staff availability visibility        | Can't distinguish idle station vs. absent staff      |
| 10 | Analytics | No per-staff performance chart          | No accountability breakdown for COA use              |

---

## Highest-value for capstone evaluation

The highest-value ones for your capstone evaluation are:

- **5A** (token alias search) — directly tied to COA compliance, which is in your manuscript.
- **9** (staff availability on dashboard) — operationally critical and low effort since the data already broadcasts.
- **2** (dashboard WebSocket vs. polling) — easy win for the performance section of your defense.
