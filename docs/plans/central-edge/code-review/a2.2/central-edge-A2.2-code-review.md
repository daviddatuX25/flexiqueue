# A.2.2 Staff Triage Program Resolution — Code Review

**Reviewers:** Code Reviewer subagents (per `.cursor/rules/code-reviewer.mdc`)  
**Spec:** [central-edge-v2-final.md](../../specs/central-edge-v2-final.md) Phase A (staff triage)  
**Scope:** Task A (triage page + session bind) + Task B (IdentityRegistrationController)  
**Date:** 2026-03-13

---

## Overall verdict

| Area | Task A | Task B |
|------|--------|--------|
| **Spec compliance** | Met | Met |
| **Blockers** | None | None |
| **Suggestions** | 2 | 4 |
| **Nits** | 2 | 2 |

**Summary:** Implementation matches Phase A staff triage rules. Program is resolved from `user.assigned_station_id → station.program_id`; unassigned staff receive 422 with "No station assigned." No auth bypass; program is never taken from client input. Safe to ship; suggestions are maintainability and consistency improvements.

---

## Spec compliance (central-edge-v2-final.md)

| Requirement | Implementation | Status |
|-------------|-----------------|--------|
| Staff triage: resolve from `user.assigned_station_id → station.program_id` | TriagePageController + SessionController + IdentityRegistrationController all use `$request->user()->assignedStation?->program` or `->program_id`. | Met |
| No station → 422 "No station assigned." | Page: redirect with errors + status 422. API: `response()->json(['message' => 'No station assigned.'], 422)`. | Met |
| No client-controlled program | No `program_id` in BindSessionRequest or identity-registration request bodies; program is server-resolved only. | Met |
| Staff only act on their program’s registrations | IdentityRegistrationController checks `$identityRegistration->program_id === $program->id` (and status) before mutations. | Met |

---

## Task A — Blockers

**None.**

---

## Task A — Suggestions

### 1. TriagePageController: 422 on redirect (semantics)

- **Where:** `TriagePageController.php` lines 32–35.
- **What:** `redirect()->back()->withErrors(...)` with `setStatusCode(422)`.
- **Why:** A redirect with 422 is non-standard; many clients treat 3xx as redirects and 4xx as errors.
- **Suggestion:** Either (a) redirect with 302/303 and flash error only, or (b) if spec requires 422 for the page, return Inertia/JSON error with 422 and no redirect. Document the choice.

### 2. SessionService: single-active-program fallback when `$programId` is null

- **Where:** `SessionService.php` lines 54–58.
- **What:** When `$programId === null`, code uses `Program::where('is_active', true)->first()`.
- **Why:** Phase A success criteria aim for zero such calls; this branch is for public triage / backward compatibility.
- **Suggestion:** Add a one-line comment that this branch is intentional for public/backward compatibility; staff triage always passes `programId`. Remove or narrow when public triage uses URL-based program (A.2.3).

---

## Task A — Nits

1. **TriagePageController docblock** — Optionally add: “Redirects back with flash error and 422 when user has no assigned station.”
2. **SessionController::bind** — Optional comment that “no station” is a context error (not field validation), so only `message` is returned.

---

## Task A — Praise

- Resolution path uses only `user → assignedStation → program_id`; no client input.
- SessionController resolves program at boundary; SessionService stays generic with optional `$programId`.
- TriagePageControllerTest and SessionBindTest cover “with station” and “without station” (422).
- Docblocks reference 09-UI-ROUTES, central-edge A.2.2, 08-API-SPEC.
- BindSessionRequest has no `program_id` rule — contract is clear.

---

## Task B — Blockers

**None.**

---

## Task B — Suggestions

### 1. Program resolution duplicated in six places (Maintainability)

- **Where:** All six methods (index, direct, possibleMatches, verifyId, accept, reject).
- **What:** Same “resolve program, 422 if none” block repeated.
- **Suggestion:** Extract e.g. `resolveProgramForStaff(Request $request): Program|JsonResponse`; return early when it’s a response. Single place for behavior and future changes.

### 2. Inline validation instead of Form Request (Convention)

- **Where:** `direct()`, `verifyId()`, `accept()` use `$request->validate([...])` inline.
- **Why:** Stack convention: “All API endpoints use Form Request classes for validation.”
- **Suggestion:** Introduce Form Request classes (e.g. `DirectIdentityRegistrationRequest`, `VerifyIdRequest`, `AcceptIdentityRegistrationRequest`) and use in method signatures.

### 3. possibleMatches: empty data vs 404 (Consistency)

- **Where:** `possibleMatches` returns 200 with `['data' => []]` when registration is wrong program or not pending; `verifyId`/`accept`/`reject` return 404.
- **Suggestion:** Consider 404 for `possibleMatches` in that case for consistency, or add a one-line comment if empty data is intentional (e.g. to avoid leaking existence).

### 4. Full qualification of `Client` in accept()

- **Where:** Line 266: `\App\Models\Client::findOrFail($clientId)`.
- **Suggestion:** Add `use App\Models\Client;` and use `Client::findOrFail($clientId)`.

---

## Task B — Nits

1. **possibleMatches** — `per_page`/`page` hard-coded; optional comment e.g. “single page of matches for triage UI.”
2. **reject() docblock** — Add one-line description (e.g. “Mark registration as rejected and set resolved_at / resolved_by_user_id”).

---

## Task B — Praise

- Program resolution and 422 behavior consistent across all six methods.
- Program and status checks done before any mutation or sensitive data.
- `index()` uses `with(['session.token', 'idVerifiedBy'])` — no N+1.
- `direct()` and `accept()` use `DB::transaction()` for multi-step writes.
- `DuplicateClientIdDocumentException` mapped to 409 with clear message.
- IdentityRegistrationApiTest covers all six endpoints and 422 “No station assigned.” for each.
- Docblock on `index()` references A.2.2 and resolution rule.

---

## Next steps

1. **No blocking changes** — implementation is spec-compliant and safe.
2. **When convenient:** Apply suggestions above (redirect/422 semantics, SessionService comment, extract program resolution in IdentityRegistrationController, Form Requests, Client import).
3. **Phase A follow-up:** When A.2.3 (public triage URL) and full refactor are done, remove or narrow the single-active-program fallback in SessionService per Phase A success criteria.
