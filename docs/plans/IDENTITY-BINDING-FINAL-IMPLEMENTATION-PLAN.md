# Identity Binding — Final Implementation Plan (robust mapping)

Decisions are locked; this document maps each to concrete files and code locations.

---

## 1. Decisions locked

| Decision | Implication |
|----------|-------------|
| identity_binding_mode → disabled / required only | DB/JSON and all code paths that reference `optional` are updated or removed. |
| allow_unverified = true | Sessions enqueue normally; queue payload sets `unverified: true` when session has pending identity_registration (existing StationQueueService logic). |
| allow_unverified = false | No session from public bind unless via staff confirm-bind; all public flows go to holding area (IdentityRegistration rows). |
| FLOW A and FLOW B use IdentityRegistration | One table; add `request_type`, `token_id`, `track_id`; no second model. |
| Any staff with triage access can action holding area | Reuse existing triage auth; new confirm-bind endpoint in same middleware group as identity-registrations routes. |

---

## 2. Schema changes (migrations)

### Migration 1 — identity_binding_mode enum

- **Where stored:** Program JSON `settings` (Program model cast, ProgramSettings reads `identity_binding_mode`). Also `program_default_settings.settings` if table exists.
- **Migration:** New migration. For `programs`: decode `settings` JSON, if `identity_binding_mode === 'optional'` set to `'required'`, write back. Same for `program_default_settings` if present.
- **Backend code:** ProgramSettings.php: change `$allowed` to `['disabled', 'required']` in `getIdentityBindingMode()`; remove or repurpose `isBindingOptional()`; in `allowsPublicBinding()` return true only for `required`.
- **Validation:** UpdateProgramRequest and UpdateProgramDefaultSettingsRequest: change `'in:disabled,optional,required'` to `'in:disabled,required'`.
- **Frontend (remove optional):** PublicStart.svelte, Triage/Index.svelte, Admin/Programs/Show.svelte, Admin/ProgramDefaultSettings.svelte, TriageClientBinder.svelte — remove optional from types and UI.

### Migration 2 — clients.identity_hash

- **New migration:** Add to `clients`: `$table->string('identity_hash', 64)->nullable();` (no index/unique).
- **Model:** Client.php: add `identity_hash` to `$fillable`. No API exposure, no UI.

### Migration 3 — identity_registrations additions

- **New migration:** On `identity_registrations`: add `request_type` (default `'registration'`), `token_id` (nullable FK to `tokens`), `track_id` (nullable FK to `service_tracks`).
- **Model:** IdentityRegistration.php: add fillables; add `token()` and `track()` BelongsTo; scope `pending()` unchanged.

---

## 3. Backend changes (exact locations)

### 3.1 PublicTriageController — bind() guard

- **File:** app/Http/Controllers/Api/PublicTriageController.php.
- **Placement:** Immediately after resolving program, before reading `identity_registration_request`.
- **Logic:** If `!getAllowUnverifiedEntry()` and `!$request->filled('identity_registration_request')`, return 403 with message: "Token binding is not available. Please verify your identity or submit a registration for staff to process." Do not call SessionService::bind().
- **FLOW B:** Confirm the registration-only path never calls SessionService::bind() when allow_unverified is false; gate any existing-client phone_match branch the same way.

### 3.2 New endpoint — FLOW A exact-match verification

- **Route:** POST /api/public/verify-identity (no auth, rate-limited).
- **Request:** PublicVerifyIdentityRequest: program_id, name, birth_year, mobile; token_id or qr_hash; track_id.
- **Controller:** Resolve program; normalize mobile via MobileCryptoService::hash() only. Exact match: one Client (site_id, name trim+case-insensitive, birth_year, mobile_hash). On exactly one match:
  - **Race condition guard (before create):** Query for an existing IdentityRegistration where `client_id = matched client`, `token_id = resolved token`, `request_type = 'bind_confirmation'`, `status = 'pending'`. If one exists, return the same 200 `{ status: 'pending', message: '...' }` without creating a new row.
  - Otherwise create IdentityRegistration (program_id, client_id, token_id, track_id, request_type = 'bind_confirmation', status = 'pending', copy name/birth_year); return 200 `{ status: 'pending', message: '...' }`.
- On zero or multiple matches: return 200 `{ verified: false, message: 'No matching account found.' }` (never 404).

### 3.3 Staff confirm-bind action

- **Route:** POST identity-registrations/{id}/confirm-bind (same auth as existing identity-registrations).
- **Controller:** Load registration; assert request_type === 'bind_confirmation' and status === 'pending' → 422 if not. **Token lock check:** Before calling SessionService::bind(), check if the token is already bound to an active session (e.g. token->status === 'in_use' or token->current_session_id). If so, return 409 or 422 with a clear message ("This token is already in use.") and do not call bind or change the registration.
- On success: call SessionService::bind() with registration->token->qr_code_hash, registration->track_id, client_id from registration, source 'public_verify'; then update registration session_id, status = 'accepted', resolved_at, resolved_by_user_id.

### 3.4 FLOW B (registration) — no structural change

- Ensure forProgram()->pending() and index payload include request_type and, for bind_confirmation, token/track/client for the frontend.

### 3.5 Token hold guard (public bind)

- **File:** app/Http/Controllers/Api/PublicTriageController.php, in bind().
- **Placement:** After the existing allow_unverified guard (that returns 403 for plain bind when allow_unverified is false), and before any path that would create a session or a new hold: resolve the token (from qr_hash in request). Then check whether the token already has a **pending** IdentityRegistration: `IdentityRegistration::where('token_id', $token->id)->where('status', 'pending')->exists()`. If it does, return 409 (or 422) with message: "This token already has a pending verification. Please see a staff member." Do not create a session and do not create a new registration/hold for this token.
- **Rationale:** Prevents the same token from being submitted again on public triage while it has a pending hold (FLOW A or FLOW B), avoiding duplicate holds or duplicate sessions when staff later confirms.

---

## 4. Frontend changes (exact locations)

### 4.1 PublicStart.svelte

- **identity_binding_mode = disabled:** Token scan + track + Start my visit only; no FLOW A/B.
- **required + allow_unverified = true:** Current flow; unverified messaging.
- **required + allow_unverified = false:** FLOW A panel (name, birth year, phone; token + track required); FLOW B panel (registration form, no token required). No "Start my visit" for plain bind.
- Types: IdentityBindingMode = 'disabled' | 'required'.

### 4.2 Index.svelte (staff triage)

- Add allow_unverified_entry to payload (TriagePageController). Holding area section when allow_unverified_entry === false; request_type branching; Confirm Bind + Reject for bind_confirmation.

### 4.3 Unverified badge

- Station queue already sets unverified from session.identity_registration_id + pending; Station/Index.svelte already shows badge. No change needed unless product wants badge only when allow_unverified_entry is true.

---

## 5. Testing (mapped)

| Test case | Location |
|-----------|----------|
| Plain bind 403 when allow_unverified = false, no registration | PublicTriageTest |
| FLOW A: exact match → one bind_confirmation row created | verify-identity feature test |
| FLOW A: no match → 200 verified: false, no row | same |
| FLOW A: more than one match → 200 verified: false | same |
| FLOW A: rate limit, 403 for inactive program | same |
| **FLOW A: same client + token submitted twice → second call returns status: 'pending', no new row created** | same (race / idempotency) |
| **Token hold guard: bind() with token that has pending IdentityRegistration → 409 (or 422), no session/hold created** | PublicTriageTest or new test |
| Staff confirm-bind → session created, registration accepted | IdentityRegistrationApiTest or new |
| Staff confirm-bind wrong request_type or already resolved → 422 | same |
| Staff confirm-bind when token already in use → 409/422, registration unchanged | same |
| Registration-only request_submitted when allow_unverified = false | PublicTriageTest |
| forProgram()->pending() returns both types | unit/feature |

---

## 6. Implementation order (sequence)

1. Migrations (all three).
2. Model updates (Client, IdentityRegistration).
3. PublicTriageController guard (allow_unverified + no registration → 403).
4. **Token hold guard in bind()** (pending IdentityRegistration for token → 409/422).
5. FLOW B confirmation (no session when allow_unverified false).
6. FLOW A endpoint (exact match + **race condition guard** before create).
7. Staff confirm-bind (with **token-in-use check** before bind).
8. PublicStart.svelte (three branches, FLOW A/B).
9. Index.svelte holding area.
10. Unverified badge (verify existing).
11. Full test pass.

---

## 7. File and change summary (checklist)

| Area | File(s) | Change |
|------|---------|--------|
| Migration | New | optional → required; identity_hash; request_type, token_id, track_id |
| Model | Client.php, IdentityRegistration.php | fillables, relations |
| Support | ProgramSettings.php | disabled/required only; allowsPublicBinding |
| Request | UpdateProgramRequest, UpdateProgramDefaultSettingsRequest | in:disabled,required |
| Backend | PublicTriageController | allow_unverified guard; **token hold guard** (pending reg for token) |
| Backend | New + route | verify-identity + **race guard** (existing pending client+token → same response) |
| Backend | IdentityRegistrationController + route | confirm-bind + **token-in-use check** |
| Backend | TriagePageController, IdentityRegistrationController::index | allow_unverified_entry; request_type in payload |
| Frontend | PublicStart, Index, Admin Programs/DefaultSettings, TriageClientBinder | branches; remove optional |
| Tests | PublicTriageTest, new | guard 403; FLOW A idempotency; **token hold guard**; confirm-bind cases |

---

## 8. Do not do

- Do not add new mobile normalization — use MobileCryptoService::hash() only.
- Do not restructure PublicTriageController::bind() — only add guards and confirm FLOW B path.
- Do not create a second table for FLOW A — use IdentityRegistration with request_type.
- Do not return 404 from verify-identity for no match — always 200.
- Do not call SessionService::bind() from public path when allow_unverified is false (except staff confirm-bind).
- Do not expose identity_hash in API or UI.

---

## 9. Out of scope (future)

- Admin QR card issuance; public triage identity_hash scan; tokenless/consumable sessions; rebinding already-bound token.
