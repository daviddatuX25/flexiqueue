# Display and availability UX — bead breakdown and change details

Beads created from the Display and availability UX plan. Each section below maps one bead to concrete file changes and acceptance criteria.

---

## flexiqueue-j4n — Availability footer: drop-up selector (Available / On break / Away)

**Goal:** Replace the tap-to-cycle availability control with a drop-up menu so users explicitly choose Available, On break, or Away.

**Files to change:**
- `resources/js/Layouts/StatusFooter.svelte`

**Detail:**
1. Remove the single button that cycles through `cycle` array on click.
2. Add a trigger element (button) that shows current status (same visual as today: dot + label). Click opens a menu that renders **above** the footer (drop-up: use `absolute`/`fixed` with `bottom: 100%` or equivalent so the menu opens upward).
3. Menu options: **Available**, **On break**, **Away**. Each option: on click call `PATCH /api/users/me/availability` with the corresponding `status`, then close the menu and update local state from response or sync from `$page.props.auth.user`.
4. When `navigator.onLine === false`, show **Offline** and do not open the menu (read-only), or disable the trigger.
5. Keep existing network indicator, queue/processed, and clock in the footer unchanged.
6. Ensure menu closes when clicking outside (optional) or when an option is selected. Use a single open state (e.g. `menuOpen = $state(false)`).

**Acceptance:** User can open a menu above the footer, pick Available / On break / Away, and selection updates immediately; offline state is read-only.

---

## flexiqueue-663 — Availability: auto-online on first site interaction

**Goal:** When the user is logged in and their status is `offline` (or unset), the first interaction with the app should set them to `available` automatically.

**Files to change:**
- `resources/js/Layouts/StatusFooter.svelte` (or a shared composable / layout that runs once per app load)

**Detail:**
1. On mount (or when `user` is set and `availabilityStatus === 'offline'`), attach a **one-time** listener to a meaningful “first interaction” (e.g. `document` or `window` `click` or `focusin`). Use a flag (e.g. `hasAutoSetOnline`) so the PATCH runs at most once per session.
2. When the event fires: if `user` exists and `(user.availability_status ?? 'offline') === 'offline'`, call `PATCH /api/users/me/availability` with `{ status: 'available' }`. On success, update local state or rely on next Inertia reload to reflect `available`.
3. Remove the listener after the first run (or after PATCH) to avoid repeated calls.
4. Do not run when the user is not authenticated.

**Acceptance:** Staff with offline status who click/focus anywhere on the app once get set to available without opening the footer menu.

---

## flexiqueue-eym — Availability: on-break full-screen overlay with Resume

**Goal:** When the user selects “On break” and the API succeeds, show a full-screen overlay that darkens the UI and shows only a “Resume” button.

**Files to change:**
- `resources/js/Layouts/StatusFooter.svelte` (or a small overlay component used by the layout that receives `showOnBreakOverlay` and `onResume`)

**Detail:**
1. Add state, e.g. `showOnBreakOverlay = $state(false)`.
2. In the drop-up (bead j4n), when user selects **On break**: after successful `PATCH /api/users/me/availability` with `status: 'on_break'`, set `showOnBreakOverlay = true`.
3. When `showOnBreakOverlay` is true, render a full-screen overlay:
   - `position: fixed; inset: 0`; background e.g. `bg-black/60` or similar to darken the interface.
   - Centered content: a single **Resume** button. On click: call `PATCH /api/users/me/availability` with `status: 'available'`, then set `showOnBreakOverlay = false`.
   - Use a high `z-index` so the overlay sits above modals and other content (e.g. `z-50` or higher).
4. Ensure the Resume button is focusable and keyboard-accessible (Enter to activate).
5. Do not close the overlay on backdrop click (only Resume closes it).

**Acceptance:** After choosing On break, the whole screen is dimmed and only “Resume” is visible; clicking Resume sets status back to available and hides the overlay.

---

## flexiqueue-87p — Display scan: program setting + countdown timer + Cancel

**Goal:** Add a per-program configurable countdown that auto-closes the scanner on the display board; keep “Cancel” to hide the camera (no “Close” label).

**Files to change:**
- `app/Models/Program.php` — add getter for `display_scan_timeout_seconds` from `settings`.
- `app/Http/Requests/UpdateProgramRequest.php` — add validation for `settings.display_scan_timeout_seconds` (nullable, integer, min 0, max e.g. 300).
- `app/Services/DisplayBoardService.php` — in `getBoardData()`, add `display_scan_timeout_seconds` to the returned array (from active program’s settings; default e.g. 60 if null).
- Program Show Settings tab (e.g. `resources/js/Pages/Admin/Programs/Show.svelte`) — add a field for “Display scan timeout (seconds)” so admins can set it per program.
- `resources/js/Pages/Display/Board.svelte` — accept prop `display_scan_timeout_seconds` (default 60). When `showScanner` becomes true: start a countdown from that value; display remaining seconds (e.g. “Closing in 25s”); when it reaches 0, set `showScanner = false` and clear `scanHandled`. Keep the existing **Cancel** button; on Cancel, clear the timer and set `showScanner = false`. On successful scan (navigate away), clear the timer in cleanup.

**Detail:**
- Timer: use `setInterval` (or `requestAnimationFrame` + delta) and decrement every second; clear interval when `showScanner` becomes false or on unmount.
- If `display_scan_timeout_seconds` is 0 or null, treat as “no auto-close” (only Cancel closes), or use default 60.
- Ensure the scanner section still shows “Cancel” only (no “Close” wording).

**Acceptance:** Admin can set display scan timeout per program; display board shows countdown when scanner is open and auto-closes when it hits 0; Cancel still hides the camera immediately.

---

## flexiqueue-m7z — Reports: include staff_activity_log in audit log and CSV

**Goal:** Audit log (and CSV export) should include staff availability changes from `staff_activity_log` so staff status is visible in reports.

**Files to change:**
- `app/Services/ReportService.php`
- Admin Reports UI if needed (e.g. `resources/js/Pages/Admin/Reports/Index.svelte` or the component that renders the audit table) to show a “Source” or “Type” column so staff_activity rows are distinguishable.

**Detail:**
1. **getAuditLog:**  
   - Add a method (e.g. `getStaffActivityLogsForAudit($filters)`) that queries `staff_activity_log` for the same date range as the audit (from/to or _range_start/_range_end). Optionally filter by `user_id` if the report has a staff filter.  
   - Normalize each row to the same shape as transaction/program logs: e.g. `id`, `source => 'staff_activity'`, `action_type` (e.g. `availability_change`), `staff` => user name (join `users` on `user_id`), `old_value`, `new_value` in remarks or dedicated columns, `created_at`.  
   - Merge these rows with the existing `transaction_logs` and `program_audit_log` results, sort combined by `created_at` desc, then paginate (same per_page and page as current).

2. **streamAuditCsv:**  
   - Include the same staff_activity rows in the merged stream. Add columns as needed (e.g. Source, Action, Staff, Old value, New value, Time). Use the same date range and filters.

3. **Admin Reports UI:**  
   - If the audit table has a “Source” or “Type” column, show `staff_activity` or “Staff status” for these rows so users can filter or recognize them.

**Acceptance:** In the Reports audit view and CSV export, staff availability changes appear with a clear source/type and are filterable by date (and optionally by staff).

---

## flexiqueue-wrx — Display: real-time staff availability

**Goal:** When a staff member changes their availability, the public display board should update staff status in real time without a full page refresh.

**Files to change:**
- Backend: broadcast when availability changes. Either add an event class (e.g. `StaffAvailabilityUpdated`) that broadcasts on channel `display.activity`, or from `UserAvailabilityController` (or a listener) dispatch a broadcast to `display.activity` with a payload like `staff_availability` containing `user_id` and `availability_status`.
- `app/Http/Controllers/Api/UserAvailabilityController.php` — after successful update, broadcast to the display channel (e.g. `display.activity` with event name and payload).
- `resources/js/Pages/Display/Board.svelte` — in `onMount`, subscribe to the new event (e.g. on `display.activity`). On event, call `router.reload({ only: ['staff_at_stations', 'staff_online'] })` so the board re-fetches only staff-related props and the UI updates.

**Detail:**
- Channel: use public `display.activity` (same as station_activity) so no auth is required. Payload: only `user_id` and `availability_status` (and optionally `name` for debugging); no sensitive data.
- Frontend: reuse existing Echo subscription to `display.activity`; add a listener for the new event name (e.g. `.staff_availability`). In the handler, call Inertia `router.reload` with `only: ['staff_at_stations', 'staff_online']` so the rest of the page (now serving, waiting, activity) is not refetched.

**Acceptance:** Changing availability from Station or any page updates the display board’s staff list and status dots within a few seconds without manual refresh.

---

## flexiqueue-540 — Display UI: single staff list, activity 5+scroll, waiting section

**Goal:** (1) Show staff once (remove duplicate between top bar and Staff on duty). (2) Recent activity: show ~5 items visible with scroll for the rest. (3) Optimize the “Currently waiting” section for clarity and space.

**Files to change:**
- `resources/js/Pages/Display/Board.svelte` only.

**Detail:**
1. **Single staff list:**  
   - Remove the top “Staff:” profile bar (the `staffForBar` section). Keep the **STAFF ON DUTY** section that lists staff by station. If `staff_online` was only in the bar, move it next to the “NOW SERVING” heading or into a small summary line so it still shows.

2. **Recent activity:**  
   - Keep `activityFeed` bound to the full list (or cap at 20). Wrap the list in a container with a max height (e.g. `max-h-[...]` equivalent to ~5 lines) and `overflow-y-auto` so only about 5 items are visible at once and the rest are visible by scrolling.

3. **Currently waiting:**  
   - Improve hierarchy and spacing: station name prominent, then “X clients waiting” and serving count/capacity. Tighter spacing; smaller or secondary text for client aliases. Optional: single-line summary per station with expand to show client list. No backend changes.

**Acceptance:** Staff appear only in “Staff on duty” (no duplicate bar). Recent activity shows a short scrollable list (~5 visible). Currently waiting section is clearer and more compact.

---

## Bead dependency summary

| Bead    | Depends on | Notes |
|---------|------------|--------|
| j4n     | —          | Drop-up selector; can be done first. |
| 663     | —          | Auto-online; can run in parallel with j4n. |
| eym     | j4n        | Overlay is shown when user selects On break from the drop-up; implement after j4n. |
| 87p     | —          | Display scan; independent. |
| m7z     | —          | Reports; independent. |
| wrx     | —          | Real-time staff; independent. |
| 540     | —          | Display UI polish; independent. |

Recommended order: **j4n → eym** (then 663 in parallel if desired). **87p, m7z, wrx, 540** can be done in any order or in parallel.
