# `flexiqueue-xm2o` — Configurable token-to-client identity binding
Status: **design + implementation doc** (supporting bead `flexiqueue-xm2o`, currently **open**)

## Executive summary
FlexiQueue currently binds a physical token to a queue session but **does not bind a token/session to a human-identifiable client record**. This blocks later person-level tracing and attendance analytics.

This change introduces:
- A **per-program setting** that controls whether identity binding is **disabled / optional / required**.
- A minimal **Client identity** model (minimal PII) plus optional **ID documents**.
- **Triage UX** (staff triage in particular) for binding a token to an identity using:
  - ID scan/manual entry (primary)
  - name + birth year search (fallback, staff-only)
- **Strict access controls**, **encryption at rest**, and **auditable binding actions**.

Non-goals: analytics/reporting, retroactive migration, advanced dedupe/resolution.

---

## Current system context (what exists today)

### Token → session binding today (core lifecycle)
The canonical binding operation today is **token → session**:
- **Staff triage page**: `resources/js/Pages/Triage/Index.svelte`
  - Looks up tokens via `GET /api/sessions/token-lookup`
  - Binds via `POST /api/sessions/bind` (staff-authenticated)
- **Public self-serve triage page**: `resources/js/Pages/Triage/PublicStart.svelte`
  - Looks up tokens via `GET /api/public/token-lookup`
  - Binds via `POST /api/public/sessions/bind` (no auth, rate limited, gated by program setting)

Backend entry points:
- **Staff bind endpoint**: `app/Http/Controllers/Api/SessionController.php::bind()`
- **Public bind endpoint**: `app/Http/Controllers/Api/PublicTriageController.php::bind()`
- **Domain implementation**: `app/Services/SessionService.php::bind(...)`
  - Creates `queue_sessions` row
  - Sets `tokens.status = in_use` and `tokens.current_session_id`
  - Writes `transaction_logs` row with `action_type = 'bind'`

### Program-level configuration today
Program configuration already lives in the `programs.settings` JSON array and is accessed via:
- `app/Models/Program.php::settings(): ProgramSettings`
- `app/Support/ProgramSettings.php` getters (e.g. `getAllowPublicTriage()`, HID toggles, display settings)

Public triage is already gated by config:
- `ProgramSettings::getAllowPublicTriage()` checked by `PublicTriageController`

### “Security docs” note
This repo currently does **not** have a dedicated Phase 1 “Security Controls” architecture doc; role/policy patterns are enforced via:
- Controller auth gates/policies (e.g. `Gate::authorize('update', $session)` elsewhere in `SessionController`)
- Role helpers on `User` (not expanded here)
- Supervisor authorization services for specific actions (`SupervisorAuthService`)

For this change, access control requirements must be implemented explicitly in controllers/services (and backed by tests).

---

## Goals and configuration contract

### New per-program setting: identity binding mode
Add a program setting (stored under `programs.settings`):
- `identity_binding_mode`: `"disabled" | "optional" | "required"`

Behavior:
- **disabled**: current triage works exactly as-is; no client binding UI is shown and no identity is persisted.
- **optional**:
  - Staff triage: binding is available but skippable.
  - Public triage (when `allow_public_triage` is enabled): client may scan an ID to bind, or skip and proceed without binding.
- **required**:
  - Staff triage: triage cannot be completed without a successful binding.
  - Public triage (when `allow_public_triage` is enabled): client must scan a recognized ID to proceed.

Interaction with existing `allow_public_triage`:
- If `identity_binding_mode = disabled`: public triage behaves exactly as today (no binding), regardless of `allow_public_triage`.
- If `identity_binding_mode = optional` and `allow_public_triage = true`: public triage shows an **ID scan** option the client may use or skip.
- If `identity_binding_mode = required` and `allow_public_triage = true`: public triage **requires** a successful ID scan before proceeding.
- If `identity_binding_mode = required` and `allow_public_triage = false`: public triage remains blocked (unchanged).

Public triage restrictions (always):
- **No** name + birth year search.
- **No** client creation.
- **No** manual ID entry.

---

## Data model changes (portable: SQLite + MariaDB)

### New entity: `clients`
Purpose: minimal identity record.

Proposed columns:
- `id` (PK)
- `name` (string)
- `birth_year` (integer; privacy-friendly vs full DOB)
- timestamps

Privacy posture:
- Keep PII minimal and separable from session/token mechanics.

### Optional entity: `client_id_documents`
Purpose: store one or more ID documents per client.

Proposed columns:
- `id` (PK)
- `client_id` (FK → `clients.id`)
- `id_type` (enum-like string or reference table)
- `id_number_encrypted` (text; encrypted at rest)
- `id_number_hash` (string; deterministic hash for lookup)
- timestamps

Notes:
- Never store raw ID number plaintext in a normal string column.
- Hash should be computed from a canonicalized value:
  - trim, collapse whitespace, upper-case where appropriate, remove separators (depending on ID type rules)
  - lock normalization rules early: changing normalization later breaks lookups against existing hashes.

### Normalization rules (locked)
Before hashing, normalize `id_number` as:
- trim leading/trailing whitespace
- convert to uppercase
- remove separators: spaces, dashes, underscores
- (optional) apply ID-type-specific rules if needed, but keep deterministic and documented

### Token/session binding association
We need a durable association between the current flow and the identity:

Option A (simplest): add nullable FK on session
- Add `queue_sessions.client_id` (nullable FK to `clients.id`)
- Works well with “bind at triage” semantics.

Option B (audit-friendly join): create `token_client_bindings` (or `session_client_bindings`)
- If we anticipate rebind/reconciliation later, a join table records history.
- For Phase 1, Option A is usually enough if combined with transaction logs.

Recommendation (Phase 1): **Option A** + explicit audit logs for binding operations.

### Audit logging
Existing audit infrastructure:
- `transaction_logs` already records session events (bind, call, transfer, etc.).

For identity binding, ensure binding operations are auditable with:
- acting user id (null only when explicitly allowed)
- program/station context (when applicable)
- method (id_scan, manual_id_entry, name_birthyear_search, attach_new_id)
- the session/token involved
- the client id bound

Approach:
- Either extend `transaction_logs` with new `action_type` values and metadata payloads, or
- Add a dedicated binding audit table if `transaction_logs` semantics must remain purely “session lifecycle”.

Given existing patterns, prefer **`transaction_logs`**:
- add new `action_type` values (requires careful SQLite + MariaDB portability; see `docs/architecture/04-DATA-MODEL.md` enum notes)
- store method + redacted details in `metadata` (never raw ID number)

### Admin raw ID decrypt view (explicit requirement)
Admins must be able to decrypt and view the raw ID number for a specific `client_id_documents` record:
- admin-only
- single-record lookup action (not a list)
- requires an additional confirmation step before displaying decrypted value
- generates an audit log entry recording who viewed what and when
- never logs the raw ID number (only IDs and context)

---

## API surface (proposed)

### Staff-facing binding APIs (new)
Rationale: keep `SessionService::bind(...)` focused on queue/session creation, and add a dedicated identity service.

Suggested endpoints (staff-authenticated):

1) **Lookup by ID document**
- `POST /api/clients/lookup-by-id`
- Body: `{ id_type, id_number }`
- Returns: either `{ client: {...minimal...} }` or `{ client: null }`
- Implementation: hash lookup; never returns raw id_number.

2) **Create client (minimal)**
- `POST /api/clients`
- Body: `{ name, birth_year, id_document?: { id_type, id_number } }`
- Returns: `{ client }`

3) **Attach ID document to existing client**
- `POST /api/clients/{client}/id-documents`
- Body: `{ id_type, id_number }`

4) **Search clients by name + birth_year (fallback)**
- `GET /api/clients/search?name=...&birth_year=...`
- Staff-only, rate limited; results must be minimal.

4b) **Admin decrypt view for ID document**
- `POST /api/admin/client-id-documents/{id}/reveal` (or similar)
- Admin-only, requires explicit confirm payload (e.g. `{ confirm: true }`)
- Returns decrypted `id_number` for that record only
- Logs a `transaction_logs` (or dedicated audit log) entry for “id_decrypt_view”

5) **Bind client to a triage bind operation**
Two patterns:
- **Inline in bind request**: extend existing bind endpoints (staff only):
  - `POST /api/sessions/bind` additionally accepts:
    - `client_binding`: `{ mode: 'id'|'fallback', ... }` or `{ client_id }`
  - This makes triage “one call” but adds complexity.
- **Two-step**:
  - Bind session first, then associate client id:
    - `POST /api/sessions/{session}/client`

Recommendation: **Inline for staff triage only**, because “required binding” must block session creation.

### Public triage APIs (existing)
Public triage currently supports:
- token lookup + bind session (no auth) with program setting gate.

For `xm2o`, public triage identity binding behavior is constrained:
- ID scan path is allowed.
- If scanned ID does not match an existing client record:
  - public triage must reject and instruct client to approach staff
- public triage cannot create clients or attach IDs

---

## UI changes (modules + responsibilities)

### Staff triage (`Triage/Index.svelte`)
Current flow: scan/lookup token → select category/track → `POST /api/sessions/bind`.

New conditional flow when program setting enables binding:
- After token lookup and before final confirm, show an **Identity binding panel**:
  - Mode toggle: **ID scan/entry** vs **Name + birth year**
  - For ID scan/entry:
    - enter `id_type`, `id_number`
    - lookup existing client (show minimal match preview) or create new
  - For fallback:
    - enter `name`, `birth_year`
    - search results list, select existing or create new
- When binding is **required**, disable the “Confirm” button until a client binding is selected/created.
- When binding is **optional**, allow “Skip” and proceed with existing bind behavior.

Componentization suggestion (to keep `Triage/Index.svelte` from ballooning):
- `resources/js/Components/TriageClientBinder.svelte`
  - owns UI states + calls new client APIs
  - returns `client_id` (selected/created) to parent

### Public triage (`Triage/PublicStart.svelte`)
When `identity_binding_mode != disabled` and `allow_public_triage = true`, public triage shows an **ID scan** option:
- If scan matches a known client: bind proceeds and session starts.
- If scan does not match: show message directing client to staff; do not start session.
- No name search, no record creation, no manual entry.
- If binding is **optional**, show “Skip” to proceed without binding.
- If binding is **required**, do not allow skipping.

### Admin program settings UI (per-program configuration)
Program settings are edited in admin pages that already touch program settings (not exhaustively listed here).

Add a new program setting control:
- “Identity binding”
  - Disabled / Optional / Required (or checkbox if using boolean fallback)
  - Help text: privacy notice + operational implications

Backend modules likely involved (existing pattern):
- Controllers/requests already present for program settings:
  - `app/Http/Controllers/Api/Admin/ProgramController.php`
  - `app/Http/Controllers/Api/Admin/ProgramDefaultSettingsController.php`
  - Requests: `UpdateProgramRequest`, `UpdateProgramDefaultSettingsRequest`
- Model access:
  - `ProgramSettings` + `Program` casts

---

## Backend modules to touch (expected)

### Configuration
- `app/Support/ProgramSettings.php`
  - add `getIdentityBindingMode()` (or `getIdentityBindingRequired()`)
- `app/Models/Program.php`
  - no change needed beyond new settings accessors

### Controllers / requests
Staff triage bind currently uses:
- `BindSessionRequest` (for staff + public)
- `SessionController::bind()` calls `SessionService::bind(...)`

Identity binding will require:
- either extending `BindSessionRequest` to accept optional client-binding payload for staff requests, while keeping public triage requests unchanged, or
- creating a separate staff-only bind request and endpoint variant.

### Services
Add a dedicated service layer to enforce:
- encryption/hashing normalization
- id lookup behavior
- binding constraints (required/optional/disabled)

Suggested services:
- `ClientService` (create/search minimal clients)
- `ClientIdDocumentService` (normalize, encrypt, hash, attach)
- `ClientBindingService` (policy: can this user do this action in this surface?)

### Models + migrations
- New models: `Client`, `ClientIdDocument` (names to match conventions)
- Migrations for `clients`, `client_id_documents`, and optionally `queue_sessions.client_id`

### Audit logging
- Extend `transaction_logs.action_type` with new value(s) (e.g. `identity_bind`)
  - Ensure SQLite and MariaDB compatibility per `docs/architecture/04-DATA-MODEL.md`.
- Store method + redacted identifiers in `metadata`

---

## Security + privacy considerations (must-haves)

- **No plaintext ID numbers** anywhere persistent.
- **No echoing raw ID back to UI** after initial entry:
  - show masked summary only (e.g. last 2–4 chars) if absolutely needed.
- **Deterministic lookup via hash**, encryption for at-rest value only when recovery is explicitly permitted.
- **Staff-only** for:
  - name + birth year search
  - manual ID entry and binding flows
- **Public triage**:
  - no name search
  - scan-only match; unknown IDs rejected
  - no manual entry; no client creation
- **Rate limiting**:
  - especially on any search/lookup endpoints
- **Auditing**:
  - every binding operation records who/when/how, station + program context, and linked token/session

---

## Test plan (what should be covered)

### Feature tests (PHPUnit)
- Program setting disabled:
  - staff triage bind works unchanged; no client required; no identity logs written
- Program setting optional:
  - bind with client payload creates/associates client
  - bind without client payload still works
- Program setting required:
  - bind without client payload fails (422 with clear errors)
  - bind with valid client payload succeeds
- ID hashing/encryption:
  - lookup-by-id finds existing document via hash
  - encrypted value is not stored in plaintext
- Access control:
  - public triage cannot call staff-only client search endpoints
  - staff-only endpoints require auth and correct role/policy

### UI behavior checks
- Staff triage:
  - required mode blocks Confirm until bound
  - optional mode allows Skip
- Public triage:
  - unchanged UX and endpoints

---

## Open decisions / clarifications needed before implementation
Remaining decisions to lock before coding:
- Session association model: `queue_sessions.client_id` vs join table (this doc recommends `queue_sessions.client_id` for Phase 1).
- Exact role rules for staff triage binding and client search (staff vs supervisor vs admin); admin decrypt view is explicitly required.

