---
status: archived
reason: "Implemented under Central+Edge Phase A stabilisation (A.S); documented and verified via StaffMultiProgramSelectorTest and shared program resolution across Station/Triage/Overrides."
archived_at: "2026-03-13"
source: "../staff-assignment-one-program-per-staff.md"
---

# Staff assignment: multiple programs allowed, warning when assigning (ARCHIVED)

**Status:** Archived — behavior implemented and covered by tests. See `central-edge-tasks.md` Phase A 🔧 A.S and `StaffMultiProgramSelectorTest`.

**Original source:** Manual testing after A.3; user feedback.

---

## Observed

A staff user can be assigned to stations in **two (or more) active programs**. Visiting `/station` or `/triage` then resolves program/station in a way that can feel "biased" (e.g. one program wins via `assigned_station_id`).

## Desired rules

1. **Assignment:** Staff **can** be assigned to many programs. When assigning a staff user to a station in program B and they already have an assignment in program A, show a **warning** (do not block). API returns 201 with optional `warning` so admin UI can display it.
2. **On program day:** Staff who have two (or more) active programs they could participate in **cannot** work in both at once — they must work in one program at a time. Resolution: shared program selection (see "Shared program selector" follow-up) so they pick once and that applies to both station and triage.

## Follow-up work (historical)

- [x] **API:** Allow multi-program assignment; when staff already has assignment in another program, return 201 with `warning` in response so UI can show it.
- [x] **On program day:** Enforce or document that staff with multiple program assignments use a single "current program" for station/triage (shared selector). See shared program selector implementation and tests.
- [x] **Document:** Optional: state in main spec or admin docs (captured in central-edge specs and tasks).

## Scheduling (historical)

Originally tracked under **🔧 A.S** in `central-edge-tasks.md`. Feature test reference: `test_assign_staff_to_second_program_returns_201_with_warning_when_already_assigned_to_another`.

