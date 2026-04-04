---
status: archived
reason: "Implemented under Central+Edge Phase A stabilisation (A.S); Station/Triage/Overrides now share session-first program selection for staff with multiple program assignments and are covered by StaffMultiProgramSelectorTest."
archived_at: "2026-03-13"
source: "../staff-program-choice-station-triage-overrides.md"
---

# Staff with multiple programs: program selector (Station / Triage / Overrides) (ARCHIVED)

**Status:** Archived — behavior implemented and covered by tests. See `central-edge-tasks.md` Phase A 🔧 A.S and `StaffMultiProgramSelectorTest`.

**Original source:** Follow-up from shared program selector and program-day rule. Same UX as admin/supervisor.

---

## Current behavior (historical)

- **Admin/supervisor with no assigned station:** Got program selector on Station and Triage; choice stored in session (`staff_selected_program_id`), so one selection applied to both. Program Overrides page accepted `?program=` and fell back to assigned station.
- **Staff (role=staff):** Program was always resolved from `assigned_station_id → program`. If a staff member had station assignments in **two or more active programs**, they still had only one `assigned_station_id` at a time (set by sync when assigning). So they could not choose which program to work in on Station/Triage/Overrides — they were effectively pinned to one program.

## Desired behavior (now implemented)

- **Staff with multiple program assignments** (assignments in more than one active program) can **choose which program** to work in on Station, Triage, and Program Overrides — using the same UX as admin/supervisors:
  - One shared selection via the `staff_selected_program_id` session key so the choice applies to Station, Triage, and Overrides.
  - Program selector UI when they have 2+ active programs they’re assigned to; changing selection updates context on all three pages.

## Relation to existing work

- Shared program selector for **admin/supervisor with no assigned station** was implemented first; this follow-up extended the same idea to **staff who have assignments in multiple active programs**.
- The "program-day staff one program at a time" rule is enforced via this shared selection — they pick one current program and Station/Triage/Overrides all respect it.

## Follow-up work (historical)

- [x] **Backend:** When resolving program for Station/Triage/Overrides, if user is **staff** and has `ProgramStationAssignment` in more than one active program, treat like admin-without-station: allow session-based program selection; pass `canSwitchProgram` and `programs` (restricted to active assigned programs). If they have only one active program, keep simple behavior.
- [x] **Frontend:** Reused the same program selector UI on Station, Triage, and Overrides when `canSwitchProgram` is true for staff with multiple programs.
- [x] **Overrides:** Program Overrides page uses the shared session when staff have multiple programs so one selection applies.

## Scheduling (historical)

Originally intended to be added under **🔧 A.S** or a dedicated Phase A item when scheduling. It is now complete and no longer part of the active follow-up backlog.

<?php

/**
 * Archived: Staff with multiple programs: program selector (Station / Triage / Overrides)
 *
 * Implemented under Phase A 🔧 A.S — Stabilize.
 *
 * - Staff with per-program station assignments in more than one active program now see the same
 *   program selector UX as admin/supervisor on Station, Triage, and Program Overrides.
 * - Selection is stored in the shared `staff_selected_program_id` session key (via ?program=)
 *   and applied consistently across the three pages.
 * - The selector list is restricted to active programs where the staff user has at least one
 *   ProgramStationAssignment; other programs do not appear in the dropdown.
 * - Behaviour is covered by `tests/Feature/StaffMultiProgramSelectorTest.php`.
 */

