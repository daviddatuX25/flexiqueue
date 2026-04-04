# A.3 Refactor — Iteration Ritual Phase 1 (Steps 1–4)

**Reference:** [central-edge-v2-final.md](specs/central-edge-v2-final.md) Phase A, [central-edge-tasks.md](central-edge-tasks.md) A.3.1–A.3.6  
**Rules:** Code Reviewer, Senior Developer, Backend Architect

---

## Step 1 — Contracts read

- **Phase A spec:** All 21 locations must get `programId` from request context; zero `Program::where('is_active', true)->first()`.
- **Resolution table:** Staff station → `station.program_id`; Staff triage → `user.assigned_station_id → station.program_id`; Public triage → URL `{program}`; Display → `?program={id}`; Admin → selector/URL.
- **HandleInertiaRequests:** Admin gets `programs` (plural); station/triage get `currentProgram` from page controller only.

---

## Step 2 — Logic confirmation

- **Assumption:** Every call path that today uses “the single active program” will receive an explicit `programId` (or program from station/user/query) from the request boundary. No fallback to “first active.”
- **Contract alignment:** Matches Phase A table. No conflict with existing A.2.1–A.2.5 (station/triage/display/admin resolution already specified).
- **IdentityRegistrationController:** Already uses `$request->user()->assignedStation?->program`; no single-active fallback. Verification only.
- **PublicTriageController:** Already uses `program_id` from request and `resolveProgramForPublicTriage()`; no single-active. Verification only.

---

## Step 3 — Edge cases & test list

1. **Admin index / dashboard** — No station context; must not assume single program. Dashboard/overview receives selected program from URL or sidebar; when no program selected, show program list / empty stats.
2. **Display status page** (`/display/status/{qr_hash}`) — Token/session has `program_id` when in_use; use that for display settings when present; else optional `?program=` or sensible default (e.g. first active for backward compat) or no program-specific settings.
3. **Home** — Authenticated staff with assigned station: use station’s program for footer stats. Admin/supervisor without station: no single-program stats (or pass `programs` only).
4. **Triage start redirect** — No “any active” fallback; use list of active programs, first that allows public triage for backward compat; if none, show PublicStart with no program.
5. **Station board** — Station has `program_id`; resolve program by `$station->program` and validate active; do not use “the” active program.
6. **Tests:** Existing tests pass; add/update tests for multi-program where missing (e.g. display ?program=1 vs 2, staff station sees only its program).

---

## Step 4 — Go/no-go and order

**We will:**

- **A.3.1** — SessionService: require `programId` in `bind()` (callers already pass it for staff/public). DisplayBoardService: keep `getBoardData(?int $programId)`; when `$programId` set, use `Program::find($programId)` and check `is_active` (no `where('is_active', true)->first()`). StaffDashboardService: `getStationSummary(User)` — get program from `$user->assignedStation?->program`; if null return null; no single-active.
- **A.3.2** — StaffDashboardController: program from `$request->user()->assignedStation?->program`; if null, pass null to footer stats. StationPageController: use `$station->program` only (remove fallback). HomeController: footer from `$user->assignedStation?->program` when user present; else no single-program stats. DisplayController: `stationBoard` use `$station->program` and validate active; `triageStartRedirect` use active list + first with allow_public_triage, remove “any active” fallback; `status()` use program from checkStatus result or optional `?program=`. ProgramOverridesPageController, Admin UserPageController, ReportPageController, Api/StationController, Api/PublicDisplaySettingsController, Api/Admin/ProgramStaffController: resolve program from route/request (program id in URL or selected program).
- **A.3.3** — HandleInertiaRequests: already shares `programs` (plural) for admin; confirm no single `program` or `activeProgram` in shared data for non-admin.
- **A.3.4** — IdentityRegistrationController: verify all 6 methods use only `assignedStation?->program`; no code change if already so.
- **A.3.5** — PublicTriageController: verify 3 entry points use request `program_id` only; no code change if already so.
- **A.3.6** — Grep verification: zero `where('is_active', true)->first()` on Program in app/.

**We will not:**

- Change ProgramService (A.1 already done).
- Change broadcasting channels (A.5).
- Add migrations.

**Tests:** T1 existing suite green; T2 add/update feature tests for multi-program display and staff program scope where missing.

**Risks:** R1 — Display status page without program in URL and token not in_use: mitigated by using optional `?program=` or first active only for display_scan_timeout_seconds fallback (minimal surface).

---

## Locations list (21 or fewer distinct usages)

| # | File | Line(s) | Change |
|---|------|--------|--------|
| 1 | SessionService.php | 55 | Require programId from callers; remove fallback |
| 2 | DisplayBoardService.php | 31 | find($programId) + is_active check |
| 3 | StaffDashboardService.php | 45 | Get program from User assignedStation |
| 4 | DisplayController.php | 81, 106, 185 | station->program; triage redirect list; status from data or ?program= |
| 5 | StationPageController.php | 31 | $station->program only |
| 6 | StaffDashboardController.php | 30 | user->assignedStation?->program |
| 7 | HomeController.php | 41 | user->assignedStation?->program for footer |
| 8 | ProgramOverridesPageController.php | 29 | program from route/request |
| 9 | DashboardService.php | 22, 116 | accept ?programId; when null empty stats |
| 10 | Api/StationController.php | 147 | station->program_id |
| 11 | StationQueueService.php | 243 | listStationsForActiveProgram($programId) |
| 12 | Api/PublicDisplaySettingsController.php | 27 | program from request |
| 13 | PinService.php | 198 | validatePinForActiveProgram($programId) or accept programId |
| 14 | Api/Admin/ProgramStaffController.php | 92, 113 | program from route |
| 15 | Admin/UserPageController.php | 37 | program from route/request |
| 16 | HandleInertiaRequests.php | 56 | Already .get() for admin; verify only |
| 17–18 | IdentityRegistrationController / PublicTriageController | — | Verify only |

**Go.** Proceed to implementation in order A.3.1 → A.3.2 → A.3.3 → A.3.4 → A.3.5 → A.3.6.
