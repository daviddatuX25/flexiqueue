# Refactor: Tracks and Stations (Triage Separate)

**Purpose:** This document records the tracks-and-stations rework so any future reviewer or implementer can understand the rationale and scope. It is for reference only; no code or repo layout is changed by this doc.

---

## Purpose of the refactor

- **Separate triage from stations:** Triage is not a station type or a row in `stations`. It is the flow where staff assign a track to a token (scan + bind).
- **Stations = flow nodes only:** The `stations` table models only service points (desks) where staff serve clients. No `role_type` (no triage, processing, or release).
- **Single source of truth:** Flow start and end are defined by track steps and stations; release happens when a session completes at the last step of the track.

---

## Summary of changes

- **Migration:** Edited `database/migrations/2025_02_14_000005_create_stations_table.php` — removed `role_type` column. Stations have `program_id`, `name`, `capacity`, `is_active`, timestamps. No new alter migration was added.
- **Backend:** Removed `role_type` from `Station` model fillable, `StoreStationRequest`, `UpdateStationRequest`, `StationController::stationResource()`, and `ProgramPageController::show()` station payload.
- **Frontend:** Removed `role_type`, `ROLE_TYPES`, and role-type select/badge from `resources/js/Pages/Admin/Programs/Show.svelte` (station create/edit modals and station cards).
- **API spec:** Bind TransactionLog step: `station_id` = NULL (triage is not a station), `next_station_id` = first step's station. Station create body: `{ name, capacity }` only.
- **Docs:** Updated `04-DATA-MODEL.md`, `08-API-SPEC-PHASE1.md`, `01-PROJECT-BRIEF.md`, `09-UI-ROUTES-PHASE1.md`, `03-FLOW-ENGINE.md`, `PHASES-OVERVIEW.md`, `PHASE-1-TASKS.md`, `QUALITY-GATES.md`, and `docs v1/04-database-schema.md` to drop `role_type` and describe triage as separate.
- **Tests:** Removed `role_type` from all station creation and assertions in `StationControllerTest`, `ProgramControllerTest`, and `TrackControllerTest`; removed `test_store_validates_role_type_enum`.

---

## Current model (after refactor)

- **Stations:** Flow nodes only. Columns: `program_id`, `name`, `capacity`, `is_active`, timestamps. No triage or release types.
- **Triage:** Separate from stations. It is the **routing** flow: assign a track to a token and create a session (bind), done at `/triage` via scan + bind. Triage is not a row in `stations`.
- **Flow start/end:** Defined by track steps and stations. Session starts at the first step's station after bind. Release = when the session is completed at the last step of the track (token is unbound).

---

## Triage as a concept

- **Triage = routing:** Assigning a track to a token and creating a session (bind). It can be done by any staff with access to the triage flow, or restricted to a **routing-only** permission (e.g. a dedicated triage device or role that can only perform routing, not station operations).
- **Phase 1:** All authenticated staff can access `/triage`. A future bead can restrict triage to a routing-only permission (specific role or device) if desired.

---

## Constraint

All station schema changes were made by **editing** the existing stations migration (`2025_02_14_000005_create_stations_table.php`). No new alter migration was added.

---

## Files touched (traceability)

| Area | Files |
|------|--------|
| Migration | `database/migrations/2025_02_14_000005_create_stations_table.php` |
| Model | `app/Models/Station.php` |
| Requests | `app/Http/Requests/StoreStationRequest.php`, `app/Http/Requests/UpdateStationRequest.php` |
| Controllers | `app/Http/Controllers/Api/Admin/StationController.php`, `app/Http/Controllers/Admin/ProgramPageController.php` |
| Frontend | `resources/js/Pages/Admin/Programs/Show.svelte` |
| Docs | `docs/architecture/04-DATA-MODEL.md`, `08-API-SPEC-PHASE1.md`, `01-PROJECT-BRIEF.md`, `09-UI-ROUTES-PHASE1.md`, `03-FLOW-ENGINE.md`, `docs/plans/PHASES-OVERVIEW.md`, `docs/plans/backlog/PHASE-1-TASKS.md` (approach doc), `docs/plans/QUALITY-GATES.md`, `docs v1/04-database-schema.md` |
| Tests | `tests/Feature/Api/Admin/StationControllerTest.php`, `tests/Feature/Api/Admin/ProgramControllerTest.php`, `tests/Feature/Api/Admin/TrackControllerTest.php` |
