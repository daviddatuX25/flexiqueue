# A.2.2 Staff Triage Program Resolution — Execution Plan

**Reference:** [central-edge-tasks.md](../../central-edge-tasks.md) (Phase A), [central-edge-v2-final.md](../central-edge-v2-final.md)  
**Goal:** Resolve `$programId` for staff triage from `user.assigned_station_id → station.program_id`. Return **422 "No station assigned."** when user has no assigned station.

**Status:** ✅ **Done** — Task A and Task B implemented via subagents; tests passing.

---

## Two delegateable tasks

### Task A — Triage page + session bind

**Scope:** TriagePageController, SessionService::bind(), SessionController::bind()

**Steps:**
1. **TriagePageController** — Resolve program via `$request->user()->assignedStation?->program` (eager-load `assignedStation.program` and `program.serviceTracks`). If null (no station or no program), return 422 with message "No station assigned." (or redirect with flash). Use this program for `$programPayload`, footer stats, display timeout, pending identity registrations. Remove `Program::where('is_active', true)->first()`.
2. **SessionService::bind()** — Add optional parameter `?int $programId = null` (e.g. last parameter). When `$programId !== null`, use `Program::find($programId)` (or where id) for bind logic; when null, keep current `Program::where('is_active', true)->first()` for backward compatibility (e.g. public triage).
3. **SessionController::bind()** — Resolve `$programId = $request->user()->assignedStation?->program_id`. If null, return `response()->json(['message' => 'No station assigned.'], 422)`. Pass `$programId` into `SessionService::bind()`.
4. **Tests** — Feature test: staff with `assigned_station_id` set can load triage page and bind session; staff with `assigned_station_id` null receives 422 on triage page and on POST bind.

**Files:** `app/Http/Controllers/TriagePageController.php`, `app/Services/SessionService.php`, `app/Http/Controllers/Api/SessionController.php`, plus new/updated feature tests.

---

### Task B — IdentityRegistrationController

**Scope:** All 6 methods in IdentityRegistrationController that use single active program.

**Steps:**
1. **Resolve program** — In each of: `index()`, `direct()`, `possibleMatches()`, `verifyId()`, `accept()`, `reject()`, replace `Program::where('is_active', true)->first()` with program from `$request->user()->assignedStation?->program` (or `->program_id`). If null, return `response()->json(['message' => 'No station assigned.'], 422)`.
2. **Consistency** — Use the resolved program for all existing logic (e.g. `forProgram($program->id)`, program_id checks). For `index()` current behavior returns empty data when no program; after change return 422 when no station.
3. **Tests** — Feature tests: staff with assigned station can call identity-registration endpoints; staff without assigned station gets 422 on each.

**Files:** `app/Http/Controllers/Api/IdentityRegistrationController.php`, plus new/updated feature tests.

---

## Notes

- **PublicTriageController** and **SessionController** both call `SessionService::bind()`. Only SessionController (staff) passes `programId`; PublicTriageController continues to call bind without the new arg (null), so service keeps single-active-program behavior for public.
- **User** has `assigned_station_id`; **Station** has `program_id`; `User::assignedStation()` exists. Use `$request->user()->assignedStation?->program_id` or load `assignedStation.program` once per request where needed.
