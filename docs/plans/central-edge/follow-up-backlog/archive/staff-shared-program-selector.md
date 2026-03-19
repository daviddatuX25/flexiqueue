# Shared program selector for Station and Triage (admin/supervisor)

**Source:** User feedback; admin/supervisor "first active program" fallback.

**Status:** ✅ Done (A.4.5 — program selector in StatusFooter; session + `?program=`; dropdown on admin + station/triage).

---

## Current behavior

- **Station:** For admin/supervisor with no assigned station, program is "first active" (by name). Station page has station switcher (stations within that program) but no program switcher.
- **Triage:** Same — first active program when no assigned station. No program selector at all.

So admin/supervisor must rely on "first active" and cannot explicitly choose program. If they use both Station and Triage, the program is resolved independently on each page (same "first active" in practice, but no explicit choice).

## Desired behavior

- **One selection, shared:** When staff (admin/supervisor) has no assigned station, they select program **once**; that choice applies to **both** Station and Triage. No separate selection on each page.
- **Implementation:** Use session to store selected program id. Both `/station` and `/triage` read this when resolving program for admin/supervisor with no assignment. Optional `?program={id}` on either route sets the session and redirects to the same path (so the choice is sticky). Program selector UI on both pages (or in shared layout) shows current program and list of active programs; changing selection navigates with `?program=id` to set session and reload.

## Follow-up work

- [x] **Backend:** Station and Triage controllers: for admin/supervisor with no assigned station, resolve program as: `?program=` query (set session, redirect) → session `staff_selected_program_id` → first active. Pass `programs` (active list) and `canSwitchProgram`. Session key: `StationPageController::SESSION_KEY_PROGRAM_ID`.
- [x] **Frontend:** Program selector on Station and Triage when `canSwitchProgram` and `programs.length > 1`; on change, visit `/station?program=id` or `/triage?program=id` so session updates and both pages use same program.
- [x] **Robust redirect:** When setting program from `?program=`, Station controller always redirects to `/station` (never `/station/{id}`) so the new program context is applied; StatusFooter already uses base path only when switching.

## Scheduling

Implement when doing A.4 or as part of central-edge stabilize. Removes need for "first active" fallback once selector is in place (see [admin-first-active-program-fallback](admin-first-active-program-fallback.md)).
