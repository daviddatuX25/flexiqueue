# Program day: staff with multiple programs work in one at a time

**Source:** User clarification on staff assignment follow-up.

---

## Rule

On the **program day** (when staff are actually working), a staff member who has station assignments in **two or more active programs** cannot participate in both. They must work in **one program** at a time (e.g. chosen via shared program selector, or via their single `assigned_station_id` which effectively pins them to one program for the session).

## Relation to other follow-ups

- **Shared program selector** ([staff-shared-program-selector](staff-shared-program-selector.md)): For admin/supervisor with no station, one program selection applies to Station and Triage. **Done.**
- **Staff program choice** ([staff-program-choice-station-triage-overrides](staff-program-choice-station-triage-overrides.md)): For **staff** with multiple program assignments, the same UX (program selector on Station/Triage/Overrides, one shared selection) is the intended mechanism so they choose one program at a time. **Backlog:** not yet implemented.
- **Staff assignment** ([staff-assignment-one-program-per-staff](staff-assignment-one-program-per-staff.md)): We allow multi-program assignment; no UI warning on assign page. Activate shows warning toast when staff in multiple active programs; we still proceed.

## Follow-up work

- [ ] **Implement staff program selector:** See [staff-program-choice-station-triage-overrides](staff-program-choice-station-triage-overrides.md). Once done, staff with multiple assignments use one current program for station/triage/overrides (shared selector). No separate selection per page.
- [ ] **Optional:** Document in 09-UI-ROUTES or central-edge spec that "staff with multiple program assignments use one current program for station/triage/overrides (shared selector)."
