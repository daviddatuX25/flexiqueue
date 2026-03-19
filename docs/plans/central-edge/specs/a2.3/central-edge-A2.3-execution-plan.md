# A.2.3 Public Triage Program Resolution — Execution Plan

**Reference:** [central-edge-tasks.md](../../central-edge-tasks.md) (Phase A), [central-edge-v2-final.md](../central-edge-v2-final.md)  
**Goal:** Resolve `$programId` for public triage from the URL segment `/public/triage/{program}`. Require program in the URL; use it for the triage page and for all public triage API calls (token lookup, bind, client lookup-by-id).

**Status:** Not started.

---

## Two delegateable tasks

### Task A — Route, page controller, and SessionService bind usage

**Scope:** New route `/public/triage/{program}`, DisplayController (or dedicated controller), SessionService::bind() usage from public triage, PublicTriageController API accepting `program_id`.

**Steps:**
1. **Route** — Add `Route::get('/public/triage/{program}', [DisplayController::class, 'publicTriage'])->name('triage.public')` (or equivalent). Use route model binding for `{program}` (Program model by `id`). Redirect or deprecate old `/triage/start` (e.g. redirect to first active program or to a program selector) so existing links still work.
2. **DisplayController::publicTriage(Program $program)** — Signature change: accept `Program $program` from route. Resolve program from URL (route model binding). Validate: program must be active and `allow_public_triage` true; otherwise render same "not available" view as today. Pass `program_id` (and program name, settings) into the Inertia props so the frontend can send it to the API. Remove `Program::where('is_active', true)->first()`.
3. **SessionService::bind()** — Already has optional `?int $programId = null` (from A.2.2). No change required; PublicTriageController will pass `$programId` when calling bind.
4. **PublicTriageController** — In `tokenLookup()`, `bind()`, and `publicLookupById()`: accept `program_id` from request (query for tokenLookup, body for bind and lookup-by-id). Resolve program via `Program::find($programId)` (or `where('id', $programId)->where('is_active', true)->first()`). Return 403 if program missing, inactive, or `allow_public_triage` false. Pass resolved `$program->id` into `SessionService::bind()` in `bind()`. Remove all `Program::where('is_active', true)->first()` usages from this controller.
5. **Tests** — Feature test: `GET /public/triage/{programId}` with active program and allow_public_triage true returns 200 and page data; with inactive program or missing program returns 404; with active program but allow_public_triage false returns 200 with `allowed: false`. Feature test: POST bind with `program_id` in body binds session to that program (`queue_sessions.program_id`); bind without program_id or with invalid program_id returns 422 or 403. Feature test: tokenLookup with `program_id` query param uses that program for allow_public_triage check.

**Files:** `routes/web.php`, `app/Http/Controllers/DisplayController.php`, `app/Http/Controllers/Api/PublicTriageController.php`, `app/Services/SessionService.php` (reference only; no change if A.2.2 already passes programId), `tests/Feature/PublicTriageTest.php` (or equivalent), `tests/Feature/Api/PublicTriageControllerTest.php` if needed.

---

### Task B — Frontend: pass program to API and optional program selector

**Scope:** Public triage page (Triage/PublicStart.svelte) and any public triage entry points.

**Steps:**
1. **PublicStart.svelte** — Receive `program_id` (and program name/settings) from page props. Use `program_id` in all API calls: token lookup (`/api/public/token-lookup?qr_hash=...&program_id={id}` or in body as applicable), bind (`program_id` in request body), client lookup-by-id (`program_id` in body or query). Ensure no call is made without a valid program_id when on the program-scoped URL.
2. **Entry / discovery** — If the app exposes a single "Public triage" link (e.g. from a welcome or display page), either: (a) link to a program selector page that lists active programs with allow_public_triage and redirects to `/public/triage/{id}`, or (b) link to a default (e.g. first active program). Per spec, "Requires program to be explicitly in the URL"; so linking to a selector or first program is acceptable. Document or add a minimal selector if there are multiple programs.
3. **Tests** — Manual or E2E: open `/public/triage/1`, complete token lookup and bind; verify session belongs to program 1. Optional: test that API calls include program_id and that invalid program_id returns 403.

**Files:** `resources/js/Pages/Triage/PublicStart.svelte`, optionally a new `Triage/PublicProgramSelector.svelte` or link from `resources/js/Pages/Welcome.svelte` / display layout if entry point exists.

---

## Notes

- **Backward compatibility:** Old route `/triage/start` can redirect to `/public/triage/{firstActiveProgramId}` or to a small program picker so bookmarks and kiosks still work. Prefer redirect to first active program with allow_public_triage for minimal change.
- **Security:** Public triage remains unauthenticated. Validate program is active and allow_public_triage on every API call; do not trust client for authorization.
- **SessionService:** A.2.2 already added `$programId` to SessionService::bind(); PublicTriageController::bind() must pass the resolved program id into it.
