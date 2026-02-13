# FlexiQueue — Phase 1 Bead-Ready Backlog

**Format:** Each task is an atomic bead (1–2 hour scope).
**Types:** Foundation / Feature / Polish
**Status:** All start as `open`. Claim via `bd update <id> --status in_progress`.

**Granular beads:** To import tasks into `bd` (section by section), run from repo root:
`./scripts/import-phase1-beads.sh foundation` (BD-001–007 done), then when needed:
`./scripts/import-phase1-beads.sh admin`, `triage`, `station`, etc. See script header for all sections.

---

## Foundation Layer (BD-001 — BD-007)

These must be completed first. They establish the project skeleton.

---

### BD-001: Initialize Laravel 12 project with Svelte 5 + Inertia.js + TailwindCSS + DaisyUI
- **Type**: Foundation
- **Context**: Tech stack per `docs v1/11-tech-decisions.md` and `07-UI-UX-SPECS.md`. Laravel 12, Svelte 5 (via `@inertiajs/svelte`), TailwindCSS 4, DaisyUI 5.
- **Dependencies**: None (first task).
- **Acceptance Criteria**:
  - `composer create-project laravel/laravel` with Laravel 12.
  - Inertia.js server-side adapter installed (`inertiajs/inertia-laravel`).
  - Svelte 5 + Vite + `@inertiajs/svelte` configured.
  - TailwindCSS 4 installed with `Inter` font imported (bundled locally for offline).
  - DaisyUI 5 installed (`npm install -D daisyui@latest`).
  - DaisyUI configured in `resources/css/app.css` via `@plugin "daisyui"`.
  - FlexiQueue custom theme defined per `07-UI-UX-SPECS.md` Section 2.2.
  - A test page (`/`) renders a Svelte component via Inertia with DaisyUI classes working.
  - `npm run dev` and `php artisan serve` both work.

---

### BD-002: Configure Laravel Reverb for local WebSocket server
- **Type**: Foundation
- **Context**: Real-time per `08-API-SPEC-PHASE1.md` Section 7. Laravel Reverb on port 6001.
- **Dependencies**: BD-001.
- **Acceptance Criteria**:
  - `php artisan install:broadcasting` completed.
  - Laravel Reverb installed and configured in `.env` (local host, port 6001).
  - `laravel-echo` + `pusher-js` installed on frontend.
  - Echo initialized in `app.js` pointing to local Reverb.
  - `php artisan reverb:start` runs without errors.
  - A test broadcast event fires and is received by a Svelte component.

---

### BD-003: Create database migrations for all Phase 1 tables
- **Type**: Foundation
- **Context**: Schema per `04-DATA-MODEL.md`. 8 tables: programs, service_tracks, track_steps, stations, tokens, sessions, transaction_logs, users (extended).
- **Dependencies**: BD-001.
- **Acceptance Criteria**:
  - Migration files for all 8 tables with exact column types, nullability, and defaults from `04-DATA-MODEL.md`.
  - Foreign keys with correct ON DELETE behavior (CASCADE / RESTRICT as specified).
  - All indexes created (idx_sessions_active, idx_tokens_hash, idx_logs_session, etc.).
  - `users` table extended with `role`, `override_pin`, `assigned_station_id`, `is_active`.
  - `php artisan migrate:fresh` runs cleanly on empty MariaDB.
  - No `hardware_units` or `device_events` tables.

---

### BD-004: Create Eloquent models with relationships and business rule scopes
- **Type**: Foundation
- **Context**: Relationships per `04-DATA-MODEL.md` Section "Relationship Summary". Business rules per Section "Key Business Rules".
- **Dependencies**: BD-003.
- **Acceptance Criteria**:
  - Models: `Program`, `ServiceTrack`, `TrackStep`, `Station`, `Token`, `Session`, `TransactionLog`, `User`.
  - All `belongsTo`, `hasMany`, `hasOne` relationships defined.
  - `TransactionLog` model: `update()` and `delete()` throw exceptions (append-only enforcement).
  - Scopes: `Session::scopeActive()` (status in waiting, serving), `Program::scopeActive()` (is_active = true).
  - `User` model: `role` cast as enum, `override_pin` hidden from serialization.
  - `Token` model: `qr_code_hash` in `$guarded` (immutable after creation).
  - Casts: `capabilities` as array (if needed later), `metadata` as array on `TransactionLog`.

---

### BD-005: Implement session-based authentication (login + logout)
- **Type**: Foundation
- **Context**: Auth per `05-SECURITY-CONTROLS.md` Sections 2.1–2.3. Laravel session driver = database.
- **Dependencies**: BD-003, BD-004.
- **Acceptance Criteria**:
  - Laravel Breeze or manual auth scaffolding (login form + controller).
  - `POST /login` authenticates via email + password.
  - On success: redirect based on role (admin → `/admin/dashboard`, staff → `/station`).
  - On failure: 422 with validation errors.
  - `POST /logout` invalidates session, redirects to `/login`.
  - Rate limiting: 5 attempts / 15 minutes (Laravel `ThrottlesLogins`).
  - Session driver set to `database` in `.env`.
  - `Login.svelte` page renders with Inertia.
  - Auth middleware applied to all non-public routes.

---

### BD-006: Create role-based authorization middleware and policies
- **Type**: Foundation
- **Context**: RBAC per `05-SECURITY-CONTROLS.md` Section 3. Middleware: `role:admin`, `role:admin,supervisor`, etc.
- **Dependencies**: BD-005.
- **Acceptance Criteria**:
  - `EnsureRole` middleware: checks `auth()->user()->role` against allowed roles. Returns 403 if unauthorized.
  - Registered in `bootstrap/app.php` as `role` alias.
  - Route groups applied: admin routes require `role:admin`, override routes require `role:admin,supervisor`.
  - `SessionPolicy`: validates station-scoped access (staff can only act on their assigned station).
  - `StationPolicy`: staff can only view their assigned station's queue.
  - Policy registered in `AuthServiceProvider`.
  - Tests: staff cannot access admin routes (403), admin can access everything.

---

### BD-007: Create shared Svelte layout components
- **Type**: Foundation
- **Context**: Layouts per `09-UI-ROUTES-PHASE1.md` Section 2. Design system per `07-UI-UX-SPECS.md`. Component mapping per `07-UI-UX-SPECS.md` Section 6.
- **Dependencies**: BD-001.
- **Acceptance Criteria**:
  - `AppShell.svelte`: wraps all auth pages using DaisyUI `navbar` (header) + custom footer.
  - `AdminLayout.svelte`: extends AppShell using DaisyUI `drawer` + `menu` (240px sidebar nav).
  - `MobileLayout.svelte`: full-width using DaisyUI `navbar` (top) + `dock` (bottom bar).
  - `DisplayLayout.svelte`: minimal chrome for kiosk mode (DaisyUI `navbar` header only).
  - `StatusFooter.svelte`: connection indicator, queue count, clock.
  - `Toast.svelte` + `toastStore`: notification system using DaisyUI `toast` + `alert` components.
  - `OfflineBanner.svelte`: listens to `navigator.onLine`, shows/hides DaisyUI `alert alert-warning` banner.
  - `Modal.svelte`: generic `<dialog>` wrapper using DaisyUI `modal` + `modal-box`.
  - All layouts use DaisyUI semantic classes per `07-UI-UX-SPECS.md` — no raw utility-only styling for standard components.

---

## Admin — Configuration (BD-008 — BD-014)

Program setup must be complete before triage and station flows work.

---

### BD-008: Program CRUD API endpoints + admin UI page
- **Type**: Feature
- **Context**: `08-API-SPEC-PHASE1.md` Section 5.1. `09-UI-ROUTES-PHASE1.md` Section 3.7.
- **Dependencies**: BD-004, BD-006, BD-007.
- **Acceptance Criteria**:
  - `GET /api/admin/programs` — list all programs.
  - `POST /api/admin/programs` — create program (name, description).
  - `PUT /api/admin/programs/{id}` — update.
  - `POST /api/admin/programs/{id}/activate` — activate (deactivates current active).
  - `POST /api/admin/programs/{id}/deactivate` — deactivate (blocked if active sessions).
  - `DELETE /api/admin/programs/{id}` — delete (blocked if any sessions exist).
  - `Admin/Programs/Index.svelte` with program cards, create/edit modal, activate/deactivate buttons.
  - Validation: name required, max 100 chars.
  - All endpoints behind `role:admin` middleware.

---

### BD-009: ServiceTrack CRUD API + admin UI (nested under program)
- **Type**: Feature
- **Context**: `08-API-SPEC-PHASE1.md` Section 5.2. `04-DATA-MODEL.md` Table 2.
- **Dependencies**: BD-008.
- **Acceptance Criteria**:
  - `GET /api/admin/programs/{id}/tracks` — list tracks for program.
  - `POST /api/admin/programs/{id}/tracks` — create track (name, description, is_default, color_code).
  - `PUT /api/admin/tracks/{id}` — update.
  - `DELETE /api/admin/tracks/{id}` — delete (blocked if active sessions use it).
  - Enforce: unique name within program, exactly one default per program.
  - UI: track list with color indicators, default badge, create/edit modal.

---

### BD-010: Station CRUD API + admin UI (nested under program)
- **Type**: Feature
- **Context**: `08-API-SPEC-PHASE1.md` Section 5.3. `04-DATA-MODEL.md` Table 4.
- **Dependencies**: BD-008.
- **Acceptance Criteria**:
  - `GET /api/admin/programs/{id}/stations` — list stations.
  - `POST /api/admin/programs/{id}/stations` — create (name, role_type, capacity).
  - `PUT /api/admin/stations/{id}` — update.
  - `DELETE /api/admin/stations/{id}` — blocked if referenced by track steps.
  - Enforce: unique name within program, role_type enum validation.
  - UI: station list with role_type badges, active toggle, create/edit modal.

---

### BD-011: TrackStep management API + admin UI (ordered steps per track)
- **Type**: Feature
- **Context**: `08-API-SPEC-PHASE1.md` Section 5.4. `04-DATA-MODEL.md` Table 3.
- **Dependencies**: BD-009, BD-010.
- **Acceptance Criteria**:
  - `GET /api/admin/tracks/{trackId}/steps` — ordered list.
  - `POST /api/admin/tracks/{trackId}/steps` — add step (station_id, step_order, is_required, estimated_minutes).
  - `PUT /api/admin/steps/{id}` — update.
  - `DELETE /api/admin/steps/{id}` — delete + auto-reorder remaining steps.
  - `POST /api/admin/tracks/{trackId}/steps/reorder` — reorder by step_ids array.
  - Enforce: contiguous step_order (no gaps), unique step_order within track.
  - UI: step list within track detail, drag/reorder or up/down buttons, add step row.

---

### BD-012: Token management API + admin UI (batch create, list, update status)
- **Type**: Feature
- **Context**: `08-API-SPEC-PHASE1.md` Section 5.5. `04-DATA-MODEL.md` Table 5. `09-UI-ROUTES-PHASE1.md` Section 3.9.
- **Dependencies**: BD-004, BD-006, BD-007.
- **Acceptance Criteria**:
  - `GET /api/admin/tokens` — paginated list, filterable by status, searchable by physical_id.
  - `POST /api/admin/tokens/batch` — create batch (prefix, count, start_number). Generates SHA-256 hashes.
  - `PUT /api/admin/tokens/{id}` — update status (mark lost, damaged, available).
  - UI: token table with status badges, batch create modal (preview before confirm), action dropdown per token.
  - Artisan command: `php artisan tokens:generate {prefix} {count}` — bulk seed tokens.

---

### BD-013: User/staff management API + admin UI
- **Type**: Feature
- **Context**: `08-API-SPEC-PHASE1.md` Section 5.6. `04-DATA-MODEL.md` Table 8.
- **Dependencies**: BD-004, BD-006, BD-007.
- **Acceptance Criteria**:
  - `GET /api/admin/users` — paginated list, filterable by role and active status.
  - `POST /api/admin/users` — create user (name, email, password, role, override_pin).
  - `PUT /api/admin/users/{id}` — update (password optional on edit).
  - `DELETE /api/admin/users/{id}` — soft delete (set `is_active = false`).
  - Enforce: `override_pin` required for supervisor/admin roles (6 digits, stored as bcrypt hash).
  - UI: user table with role badges, station assignment display, create/edit modal.

---

### BD-014: Staff-to-station assignment API + UI
- **Type**: Feature
- **Context**: `08-API-SPEC-PHASE1.md` Section 5.7. Staff assignment within active program context.
- **Dependencies**: BD-010, BD-013.
- **Acceptance Criteria**:
  - `POST /api/admin/users/{userId}/assign-station` — body: `{ station_id }`. Sets `users.assigned_station_id`.
  - `POST /api/admin/users/{userId}/unassign-station` — clears assignment.
  - Validation: station must belong to the active program.
  - UI: assignable from user edit modal (station dropdown) AND from station detail (assign staff dropdown).
  - Supervisor can also assign staff (not just admin).

---

## Triage Flow (BD-015 — BD-018)

---

### BD-015: QR scanner Svelte component (camera-based + manual entry)
- **Type**: Feature
- **Context**: `09-UI-ROUTES-PHASE1.md` Section 3.2 (Triage QrScanner component).
- **Dependencies**: BD-007.
- **Acceptance Criteria**:
  - `QrScanner.svelte` component using a JS QR library (e.g., `html5-qrcode` or `jsQR`).
  - Requests camera permission, shows live viewfinder (300x300 centered).
  - On QR detected: emits `scan` event with decoded string.
  - Manual entry fallback: text input for typing QR hash or physical_id.
  - Error handling: camera denied → show manual entry only.
  - Works on Android Chrome and iOS Safari (PWA-compatible).

---

### BD-016: POST /api/sessions/bind endpoint with full validation
- **Type**: Feature
- **Context**: `08-API-SPEC-PHASE1.md` Section 3.1. Flow logic per `05-flow-engine` Section 5.
- **Dependencies**: BD-004, BD-006.
- **Acceptance Criteria**:
  - `POST /api/sessions/bind` — accepts `{ qr_hash, track_id, client_category }`.
  - Derives `program_id` from active program (400 if none).
  - Validates token exists (404), checks `token.status`:
    - `available` → proceed.
    - `in_use` → 409 with active session details.
    - `lost`/`damaged` → 409 with message.
  - Validates `track_id` belongs to active program.
  - Creates Session: status=waiting, alias from token.physical_id, current_station_id = first step's station.
  - Updates Token: status=in_use, current_session_id.
  - Creates TransactionLog: action_type=bind.
  - Broadcasts `ClientArrived` to `station.{first_station_id}`.
  - Broadcasts `QueueLength` to `global.queue`.
  - Returns 201 with session + token data.

---

### BD-017: Triage page UI (scan → category select → confirm)
- **Type**: Feature
- **Context**: `09-UI-ROUTES-PHASE1.md` Section 3.2. Design per `docs v1/07-ui-ux-specs.md` Screen 1.
- **Dependencies**: BD-015, BD-016, BD-007.
- **Acceptance Criteria**:
  - `Triage/Index.svelte` page at `/triage` route.
  - Default state: camera active, scanning for QR.
  - After scan: shows "TOKEN SCANNED: A1", category buttons appear (Regular, Priority, Incomplete).
  - Category selection highlights chosen button, enables Confirm.
  - Confirm calls `POST /api/sessions/bind`.
  - On success: toast "Session created for A1", reset to scanning state.
  - On error: show appropriate error message.
  - Cancel button resets to scanning state.
  - Footer shows stats (queue count, processed today).

---

### BD-018: Double scan protection modal + force-end supervisor flow
- **Type**: Feature
- **Context**: `08-edge-cases` Section 3. `08-API-SPEC-PHASE1.md` Section 3.8. `09-UI-ROUTES-PHASE1.md` DoubleScanModal.
- **Dependencies**: BD-016, BD-017.
- **Acceptance Criteria**:
  - When bind returns 409 (token in use): show `DoubleScanModal`.
  - Modal shows: alias, current station, status, started time.
  - "View Details" button: navigates to station with that session.
  - "Force End Session" button: opens `SupervisorPinModal`.
  - `SupervisorPinModal`: PIN input (6 digits) + reason textarea + confirm.
  - Calls `POST /api/sessions/{id}/force-complete` with PIN + reason.
  - On success: toast "Session force-ended", token becomes available, allow re-bind.
  - On PIN failure: show "Invalid PIN" error.
  - `POST /api/auth/verify-pin` endpoint implemented.

---

## Station Flow (BD-019 — BD-027)

---

### BD-019: GET /api/stations/{id}/queue endpoint
- **Type**: Feature
- **Context**: `08-API-SPEC-PHASE1.md` Section 4.1.
- **Dependencies**: BD-004, BD-006.
- **Acceptance Criteria**:
  - Returns `now_serving` (session being served or null) + `waiting` list (ordered by started_at ASC).
  - Includes station info, session aliases, tracks, categories, timing.
  - Stats: total_waiting, total_served_today, avg_service_time.
  - Staff access: only assigned station (403 otherwise). Supervisor/admin: any station.
  - Queue ordering is FIFO by `started_at`.

---

### BD-020: Station page UI — layout, current client card, queue preview
- **Type**: Feature
- **Context**: `09-UI-ROUTES-PHASE1.md` Section 3.3. Design per `docs v1/07-ui-ux-specs.md` Screen 2.
- **Dependencies**: BD-019, BD-007.
- **Acceptance Criteria**:
  - `Station/Index.svelte` at `/station` (auto-detects station from user) or `/station/{id}`.
  - **Serving state**: CurrentClientCard (alias 72px, category badge, timer, progress bar).
  - **Empty state**: "NO CLIENT ACTIVE" + "CALL NEXT CLIENT" button with hint.
  - QueuePreview: next 5 waiting clients (alias, track, wait time).
  - StationHeader with station name and staff menu.
  - StatusFooter with connection/queue stats.
  - Responsive: optimized for 375px mobile.

---

### BD-021: Call next client logic (API + UI)
- **Type**: Feature
- **Context**: `08-API-SPEC-PHASE1.md` Section 3.7. No-show counting per `08-edge-cases` Section 2.
- **Dependencies**: BD-019, BD-020.
- **Acceptance Criteria**:
  - `POST /api/sessions/{id}/call` — increments no_show_attempts, sets status to serving.
  - When station has no serving client and queue has waiting clients:
    - Show "CALL NEXT CLIENT (B3 is ready)" button.
    - Pressing it calls the first waiting session.
  - When re-calling the same session (no_show_attempts > 0):
    - Show attempt counter "Calling A1... (Attempt 2/3)".
  - When `threshold_reached = true` (attempts >= 3):
    - Show modal: "Client Not Responding" with [Mark No-Show] [Keep Waiting].
  - Live duration timer on current client card.

---

### BD-022: POST /api/sessions/{id}/transfer endpoint (standard + custom)
- **Type**: Feature
- **Context**: `08-API-SPEC-PHASE1.md` Section 3.2. Flow engine per `05-flow-engine` Sections 1–3.
- **Dependencies**: BD-004, BD-006.
- **Acceptance Criteria**:
  - Accepts `{ mode: "standard" }` or `{ mode: "custom", target_station_id }`.
  - Standard: calls `FlowEngine::calculateNextStation()`. If NULL → "flow complete" response.
  - Custom: validates target station exists and is active.
  - Updates session: status=waiting, current_station_id, current_step_order.
  - Creates TransactionLog: action_type=transfer, previous_station_id, next_station_id.
  - Broadcasts to old station, new station, and global.queue channels.

---

### BD-023: Transfer UI (primary action button on station page)
- **Type**: Feature
- **Context**: `09-UI-ROUTES-PHASE1.md` PrimaryActionButton. Design per Screen 2.
- **Dependencies**: BD-020, BD-022.
- **Acceptance Criteria**:
  - Primary green button: "SEND TO {next_station_name} (Table X — Next Step)".
  - If last step: button changes to "COMPLETE SESSION" (green).
  - Pressing transfer button calls `POST /api/sessions/{id}/transfer` with mode=standard.
  - On success: current client card clears, next waiting client shown (or empty state).
  - Toast: "A1 transferred to Cashier".

---

### BD-024: POST /api/sessions/{id}/complete endpoint with step validation
- **Type**: Feature
- **Context**: `08-API-SPEC-PHASE1.md` Section 3.4. Completion rules per `04-DATA-MODEL.md` Business Rule 8.
- **Dependencies**: BD-004.
- **Acceptance Criteria**:
  - Validates session status = serving.
  - Checks all required track_steps have been completed (current_step_order >= max required step).
  - If not: 409 with remaining required steps.
  - Sets session: status=completed, completed_at=now, current_station_id=NULL.
  - Unbinds token: status=available, current_session_id=NULL.
  - Creates TransactionLog: action_type=complete.
  - Broadcasts to station + global.queue.

---

### BD-025: POST /api/sessions/{id}/cancel endpoint
- **Type**: Feature
- **Context**: `08-API-SPEC-PHASE1.md` Section 3.5. Any auth user can cancel.
- **Dependencies**: BD-004.
- **Acceptance Criteria**:
  - Accepts `{ remarks }` (optional).
  - Validates session status IN (waiting, serving). If terminal: 409.
  - Sets session: status=cancelled, completed_at=now, current_station_id=NULL.
  - Unbinds token.
  - Creates TransactionLog: action_type=cancel, remarks.
  - Broadcasts to station + global.queue.
  - UI: cancel button on station page with optional reason prompt.

---

### BD-026: POST /api/sessions/{id}/no-show endpoint
- **Type**: Feature
- **Context**: `08-API-SPEC-PHASE1.md` Section 3.6. `08-edge-cases` Section 2.
- **Dependencies**: BD-004.
- **Acceptance Criteria**:
  - Validates session status IN (waiting, serving).
  - Sets session: status=no_show, completed_at=now, current_station_id=NULL.
  - Unbinds token.
  - Creates TransactionLog: action_type=no_show.
  - Broadcasts to station + global.queue.

---

### BD-027: No-show UI with 3-attempt counter + confirmation modal
- **Type**: Feature
- **Context**: `09-UI-ROUTES-PHASE1.md` NoShowButton. `08-edge-cases` Section 2.
- **Dependencies**: BD-021, BD-026.
- **Acceptance Criteria**:
  - Gray button at bottom of station page: "Mark No-Show (0/3)".
  - Counter updates based on `no_show_attempts`.
  - When attempts >= 3: show modal "Client Not Responding" with [Mark No-Show] [Keep Waiting].
  - Mark No-Show calls `POST /api/sessions/{id}/no-show`.
  - Keep Waiting dismisses modal, session stays active.
  - On no-show success: toast "A1 marked as no-show", client card clears.

---

## Supervisor Override (BD-028 — BD-030)

---

### BD-028: Add override_pin field to users + PIN validation service
- **Type**: Feature
- **Context**: `05-SECURITY-CONTROLS.md` Section 4. `04-DATA-MODEL.md` Table 8.
- **Dependencies**: BD-004.
- **Acceptance Criteria**:
  - `users.override_pin` column (VARCHAR 255, nullable) — already in migration from BD-003.
  - `PinService::validate(userId, pin)` — hashes and compares against stored hash.
  - `POST /api/auth/verify-pin` endpoint: accepts `{ user_id, pin }`, returns `{ verified, role }`.
  - Rate limit: 5 attempts / minute per user_id. Returns 429 on exceed.
  - PIN is bcrypt-hashed on storage (same as password).
  - Admin UI for setting/resetting PINs (in user create/edit form, BD-013).

---

### BD-029: POST /api/sessions/{id}/override endpoint with PIN + reason
- **Type**: Feature
- **Context**: `08-API-SPEC-PHASE1.md` Section 3.3.
- **Dependencies**: BD-028, BD-022.
- **Acceptance Criteria**:
  - Accepts `{ target_station_id, reason, supervisor_user_id, supervisor_pin }`.
  - Validates supervisor PIN via PinService.
  - Validates reason is non-empty.
  - Validates target station exists and is active.
  - Updates session: current_station_id, status=waiting.
  - Creates TransactionLog: action_type=override, remarks=reason, metadata={supervisor_id}.
  - Broadcasts to affected stations + global.queue.

---

### BD-030: Override modal UI (station selection + reason + PIN)
- **Type**: Feature
- **Context**: `09-UI-ROUTES-PHASE1.md` OverrideModal. Design per Screen 2 Override Modal.
- **Dependencies**: BD-029, BD-020.
- **Acceptance Criteria**:
  - `OverrideModal.svelte`: triggered by "Override" button on station page.
  - Lists all active stations as radio options (excluding current).
  - Reason textarea (required, placeholder: "Explain why...").
  - Supervisor PIN input (6 digits).
  - Confirm button disabled until: target selected + reason filled + PIN entered.
  - On submit: calls override endpoint.
  - On success: toast + modal closes + client card updates.
  - On PIN failure: inline error "Invalid PIN".

---

## Edge Cases (BD-031 — BD-032)

---

### BD-031: Process skipper detection + invalid sequence warning screen
- **Type**: Feature
- **Context**: `08-edge-cases` Section 6. `09-UI-ROUTES-PHASE1.md` InvalidSequenceScreen. `05-flow-engine` Section 4.3.
- **Dependencies**: BD-019, BD-020, BD-029.
- **Acceptance Criteria**:
  - When a session arrives at a station (via transfer or manual scan):
    - Compare expected next station (from track steps) vs. actual station.
    - If mismatch AND the skipped step is required → show full-screen red "INVALID SEQUENCE" overlay.
  - Overlay shows: current progress, expected station, this station, missing required steps.
  - "Send Back to Expected Station" button: transfers session to the expected station.
  - "Supervisor Override" button: opens OverrideModal (BD-030).
  - If mismatch but skipped step is NOT required → allow with a yellow warning (non-blocking).

---

### BD-032: Identity verification prompt for priority-track clients
- **Type**: Feature
- **Context**: `08-edge-cases` Section 7. `09-UI-ROUTES-PHASE1.md` IdentityVerification component.
- **Dependencies**: BD-020.
- **Acceptance Criteria**:
  - When now_serving session has `client_category` in priority categories (PWD, Senior, Pregnant):
    - Show prominent "VERIFY IDENTITY" banner in CurrentClientCard.
    - Two buttons: "ID Verified" (green) and "Mismatch" (red).
  - "ID Verified": dismisses banner, normal operation continues.
  - "Mismatch": opens MismatchModal with textarea for issue description.
    - Options: "Send Back to Triage" or "Cancel Session".
    - Creates TransactionLog: action_type=identity_mismatch, remarks.
  - For non-priority categories: no verification prompt shown.

---

## Informant Display (BD-033 — BD-035)

---

### BD-033: GET /api/check-status/{qr_hash} public endpoint
- **Type**: Feature
- **Context**: `08-API-SPEC-PHASE1.md` Section 2.1.
- **Dependencies**: BD-004.
- **Acceptance Criteria**:
  - Public endpoint (no auth).
  - Looks up token by qr_code_hash.
  - If in_use: returns alias, track, status, current station, progress steps, estimated wait.
  - If available: returns minimal "not in use" response.
  - If lost/damaged: returns "unavailable" message.
  - If not found: 404.
  - **No internal IDs** in response (alias and names only).

---

### BD-034: Informant display page — now serving board + waiting summary
- **Type**: Feature
- **Context**: `09-UI-ROUTES-PHASE1.md` Section 3.4. Design per `docs v1/07-ui-ux-specs.md` Screen 3.
- **Dependencies**: BD-007, BD-033.
- **Acceptance Criteria**:
  - `Display/Board.svelte` at `/display` — no auth required.
  - Blue header with app name + program name + date.
  - "CHECK YOUR STATUS" section with QR scan button.
  - "NOW SERVING" grid: 2x2 cards showing alias → station name for all serving sessions.
  - "CURRENTLY WAITING" section: per-station alias list + count.
  - Total queue count at bottom.
  - SystemAnnouncement banner (if any active announcement).
  - Portrait kiosk layout (768x1024 optimized).

---

### BD-035: Informant QR scan status check (scan → progress → dismiss)
- **Type**: Feature
- **Context**: `09-UI-ROUTES-PHASE1.md` Section 3.5.
- **Dependencies**: BD-033, BD-034, BD-015.
- **Acceptance Criteria**:
  - From display board, tapping "CHECK YOUR STATUS" activates QR scanner.
  - On scan: calls `GET /api/check-status/{qr_hash}`.
  - Shows `Display/Status.svelte`: alias (72px), category badge, progress steps (complete/in_progress/pending), current station, estimated wait.
  - "OK, GOT IT" button returns to display board.
  - Auto-dismiss after 30 seconds → return to board.

---

## Real-Time Integration (BD-036 — BD-039)

---

### BD-036: Laravel broadcasting events for session state changes
- **Type**: Feature
- **Context**: `08-API-SPEC-PHASE1.md` Section 7. Broadcasting from service layer.
- **Dependencies**: BD-002, BD-004.
- **Acceptance Criteria**:
  - Event classes: `ClientArrived`, `StatusUpdate`, `QueueUpdated`, `OverrideAlert`, `NowServing`, `QueueLength`, `SystemAnnouncement`, `SessionCompleted`.
  - Each event implements `ShouldBroadcast` with correct channel (`station.{id}` or `global.queue`).
  - Events dispatched from SessionService methods (bind, transfer, override, complete, cancel, no_show).
  - Channel authorization: `station.{id}` validates user assignment; `global.queue` is public.

---

### BD-037: WebSocket integration on Station page (live queue updates)
- **Type**: Feature
- **Context**: `09-UI-ROUTES-PHASE1.md` Section 5.3. Station page subscribes to station.{id}.
- **Dependencies**: BD-036, BD-020.
- **Acceptance Criteria**:
  - Station page subscribes to `station.{id}` on mount, leaves on destroy.
  - `ClientArrived` → add to waiting queue, play notification sound (optional).
  - `StatusUpdate` → update session status in queue.
  - `QueueUpdated` → refresh full queue data.
  - `OverrideAlert` → show toast "Override: A1 sent here from Table 2".
  - UI updates without full page reload.

---

### BD-038: WebSocket integration on Informant display (live now-serving)
- **Type**: Feature
- **Context**: `09-UI-ROUTES-PHASE1.md` Section 3.4. Display subscribes to global.queue.
- **Dependencies**: BD-036, BD-034.
- **Acceptance Criteria**:
  - Display page subscribes to `global.queue` channel.
  - `NowServing` → update "NOW SERVING" grid.
  - `QueueLength` → update per-station waiting counts.
  - `SystemAnnouncement` → show banner with message and priority styling.
  - `SessionCompleted` → remove from now-serving grid.
  - All updates are instant (no polling, no page refresh).

---

### BD-039: WebSocket integration on Admin dashboard (live stats)
- **Type**: Feature
- **Context**: `09-UI-ROUTES-PHASE1.md` Section 3.6.
- **Dependencies**: BD-036, BD-040.
- **Acceptance Criteria**:
  - Dashboard subscribes to `global.queue`.
  - Health cards update in real-time (active sessions, waiting, completed today).
  - Station status table updates queue counts live.
  - Fallback: poll `GET /api/dashboard/stats` every 30 seconds if WebSocket drops.

---

## Admin Dashboard (BD-040 — BD-042)

---

### BD-040: Admin dashboard page — system health summary cards
- **Type**: Feature
- **Context**: `08-API-SPEC-PHASE1.md` Section 6.1. `09-UI-ROUTES-PHASE1.md` Section 3.6.
- **Dependencies**: BD-007, BD-006.
- **Acceptance Criteria**:
  - `Admin/Dashboard.svelte` at `/admin/dashboard`.
  - `GET /api/dashboard/stats` endpoint returns: active sessions, waiting count, serving count, completed today, cancelled today, no-show today, stations active, staff online.
  - 4 StatCards: Active Sessions, Queue Waiting, Stations Online, Completed Today.
  - System announcement form: message input + priority select + broadcast button.
  - Quick action buttons: Manage Programs, Manage Staff, View Reports.

---

### BD-041: Admin dashboard — active program overview with track stats
- **Type**: Feature
- **Context**: `09-UI-ROUTES-PHASE1.md` ActiveProgramSection.
- **Dependencies**: BD-040.
- **Acceptance Criteria**:
  - "ACTIVE PROGRAM: {name}" section below health cards.
  - Per-track row: track name + color + client count (waiting + serving).
  - If no active program: show "No active program" message + link to programs page.

---

### BD-042: Admin dashboard — station status table with live queue counts
- **Type**: Feature
- **Context**: `09-UI-ROUTES-PHASE1.md` StationStatusTable.
- **Dependencies**: BD-040.
- **Acceptance Criteria**:
  - Table columns: Station | Staff | Queue Length | Current Client | Status.
  - Data from `GET /api/stations` with queue counts.
  - Color-coded status: green (active, has staff), yellow (active, no staff), red (inactive).
  - Current client shows alias or "—" if empty.
  - Links to individual station pages for supervisors/admins.

---

## Reports (BD-043 — BD-045)

---

### BD-043: GET /api/admin/reports/audit endpoint + CSV export
- **Type**: Feature
- **Context**: `08-API-SPEC-PHASE1.md` Section 5.8.
- **Dependencies**: BD-004, BD-006.
- **Acceptance Criteria**:
  - `GET /api/admin/reports/audit` — paginated, filterable (program_id, date range, action_type, station_id, staff_user_id).
  - Returns: id, session alias, action_type, station name, staff name, remarks, timestamp.
  - `GET /api/admin/reports/audit/export` — same filters, returns CSV file download.
  - CSV columns: Timestamp, Session Alias, Action, Station, Staff, Remarks, Metadata.
  - Admin role required.

---

### BD-044: PDF report template — Daily Operations Summary
- **Type**: Feature
- **Context**: `08-API-SPEC-PHASE1.md` Section 5.8 (daily-summary).
- **Dependencies**: BD-043.
- **Acceptance Criteria**:
  - `GET /api/admin/reports/daily-summary/pdf` — generates PDF.
  - Uses a PDF library (e.g., `barryvdh/laravel-dompdf` or `spatie/laravel-pdf`).
  - Template includes:
    - Header: FlexiQueue logo, program name, date.
    - Summary table: total sessions, completed, cancelled, no-show, avg wait time, avg service time.
    - Per-track breakdown: track name, count, avg time.
    - Per-station breakdown: station, served count, avg service time, no-shows.
    - Override summary: count, by supervisor.
    - Footer: "Generated by FlexiQueue" + timestamp.

---

### BD-045: Reports admin UI page (filters + audit table + export buttons)
- **Type**: Feature
- **Context**: `09-UI-ROUTES-PHASE1.md` Section 3.11.
- **Dependencies**: BD-043, BD-044.
- **Acceptance Criteria**:
  - `Admin/Reports/Index.svelte` at `/admin/reports`.
  - Filter panel: program select, date range, action type multi-select, station filter.
  - Audit log table (paginated, color-coded by action type).
  - Export bar: "Download CSV", "Generate Daily Summary PDF", "Generate Session Detail PDF".
  - Session Detail PDF: full transaction history for selected session(s) or date range.

---

## Polish & Infrastructure (BD-046 — BD-050)

---

### BD-046: Offline detection banner + PWA service worker
- **Type**: Polish
- **Context**: `PHASES-OVERVIEW.md` Phase 1 "Offline Handling (Basic)".
- **Dependencies**: BD-007.
- **Acceptance Criteria**:
  - `OfflineBanner.svelte` shows "Offline — connection lost" when `navigator.onLine = false`.
  - Banner auto-hides when connection returns.
  - Service worker registered for app shell caching (HTML, CSS, JS bundles).
  - `manifest.json` for PWA install prompt (name, icons, start_url, display: standalone).
  - Pages load from cache when server is briefly unreachable.

---

### BD-047: Demo database seeder (realistic program + tracks + stations + tokens)
- **Type**: Foundation
- **Context**: `PHASES-OVERVIEW.md` "Database Seeder".
- **Dependencies**: BD-004.
- **Acceptance Criteria**:
  - `php artisan db:seed` creates:
    - 1 admin user, 1 supervisor, 3 staff users (all with known passwords for testing).
    - 1 program: "Cash Assistance Distribution" (is_active = true).
    - 3 tracks: Regular (4 steps), Priority (3 steps), Incomplete (5 steps).
    - 5 stations: Triage, Verification, Interview, Legal, Cashier.
    - 50 tokens: A1–A50 with generated QR hashes.
    - Staff assigned to stations.
  - Optional: `--with-sessions` flag seeds 10 sample sessions in various states.
  - Seeder is idempotent (re-running doesn't duplicate data).

---

### BD-048: Loading states (skeleton screens) and toast notification system
- **Type**: Polish
- **Context**: `09-UI-ROUTES-PHASE1.md` Section 4 shared components.
- **Dependencies**: BD-007.
- **Acceptance Criteria**:
  - `LoadingSkeleton.svelte`: animated gray bars matching card/table layouts.
  - Applied to: station queue loading, admin tables loading, dashboard stats loading.
  - Toast system (`toastStore` + `Toast.svelte`):
    - Success: green, auto-dismiss 3s.
    - Error: red, requires manual dismiss.
    - Info: blue, auto-dismiss 5s.
  - Toasts stack vertically in top-right corner.

---

### BD-049: Error handling patterns (validation, API errors, 403/404 pages)
- **Type**: Polish
- **Context**: `08-API-SPEC-PHASE1.md` Section 8 (error convention).
- **Dependencies**: BD-005, BD-006.
- **Acceptance Criteria**:
  - Custom 403 page: "You don't have permission to access this page."
  - Custom 404 page: "Page not found."
  - Custom 419 page: "Session expired. Please log in again." (CSRF token expired).
  - Inertia form validation errors displayed inline on all forms.
  - API errors (non-Inertia) return consistent JSON structure per Section 8.
  - Global Axios/fetch interceptor shows toast on 500 errors.

---

### BD-050: Final responsive polish + mobile touch-target audit
- **Type**: Polish
- **Context**: `09-UI-ROUTES-PHASE1.md` Section 6 (breakpoints) and Section 7 (design tokens).
- **Dependencies**: All feature beads.
- **Acceptance Criteria**:
  - All mobile pages (Triage, Station) tested at 375px width.
  - All primary action buttons >= 80px height.
  - All touch targets >= 44px (WCAG minimum).
  - Admin pages usable at 768px+ width.
  - Informant display optimized for 768x1024 portrait.
  - No horizontal scroll on any page at target width.
  - Font sizes match design tokens (alias 72px, h1 36px, h2 24px, body 16px).
  - Color palette matches spec (primary blue, success green, etc.).

---

### BD-051: Build DaisyUI component preview page (pure HTML)
- **Type**: Foundation
- **Context**: `07-UI-UX-SPECS.md` Section 11. Visual reference for all DaisyUI components with FlexiQueue theming.
- **Dependencies**: None (standalone HTML file, no build step).
- **Acceptance Criteria**:
  - Pure HTML file at `public/dev/components.html`.
  - Loads TailwindCSS 4 + DaisyUI 5 via CDN (no build step required).
  - Applies the FlexiQueue custom theme (colors, border radius, sizing from `07-UI-UX-SPECS.md`).
  - Showcases every DaisyUI component used in Phase 1, organized by category:
    - **Buttons**: primary, success, error, ghost, outline, disabled, loading, all size variants (80px, 48px, default).
    - **Badges**: primary, success, warning, error, accent (priority), neutral.
    - **Cards**: stat cards (4-up dashboard layout), elevated cards, card with actions.
    - **Modals**: basic modal, confirm dialog, form modal (PIN entry example).
    - **Tables**: zebra table with sortable headers, pagination.
    - **Forms**: input, select, textarea, checkbox, toggle, fieldset, validator states.
    - **Navigation**: navbar, sidebar menu, tabs (lifted/boxed), breadcrumbs, dock (mobile bottom nav), pagination.
    - **Feedback**: toast (success/error/info), alert (warning banner), progress bar, steps (vertical), skeleton, loading spinner.
    - **Layout**: drawer (admin sidebar), divider.
  - Each section has a heading and brief label describing the FlexiQueue use case.
  - Page is responsive (viewable at 375px mobile and 1440px desktop).
  - Accessible at `http://localhost/dev/components.html` during development.
  - **Not included in production builds** — lives in `public/dev/` which can be excluded or .gitignored for deployment.

---

### BD-052: Update tech decisions doc with DaisyUI rationale
- **Type**: Foundation
- **Context**: `docs v1/11-tech-decisions.md` needs a new section for DaisyUI. Records the decision and alternatives.
- **Dependencies**: None (documentation only).
- **Acceptance Criteria**:
  - New section added to `docs v1/11-tech-decisions.md`: "Styling — TailwindCSS + DaisyUI".
  - Documents: why DaisyUI (consistent design system, 65 ready-made accessible components, zero JS, TailwindCSS 4 native, reduces custom CSS).
  - Documents alternatives considered: raw TailwindCSS only (more flexibility but more work), Flowbite (JS-heavy), Skeleton UI (Svelte-specific but smaller community), Headless UI (unstyled, more work).
  - Decision: DaisyUI 5 as the component library for Phase 1.

---

## Dependency Graph (Critical Path)

```text
BD-001 (Project Init)
  ├── BD-002 (Reverb) ──→ BD-036 (Events) ──→ BD-037/038/039 (WS Integration)
  ├── BD-003 (Migrations) ──→ BD-004 (Models)
  │     ├── BD-005 (Auth) ──→ BD-006 (RBAC)
  │     │     └── BD-008 (Programs) ──→ BD-009 (Tracks) ──→ BD-011 (Steps)
  │     │     └── BD-008 (Programs) ──→ BD-010 (Stations)
  │     │     └── BD-012 (Tokens)
  │     │     └── BD-013 (Users) ──→ BD-014 (Assignment)
  │     ├── BD-016 (Bind API) ──→ BD-017 (Triage UI) ──→ BD-018 (Double Scan)
  │     ├── BD-019 (Queue API) ──→ BD-020 (Station UI)
  │     │     ├── BD-021 (Call Next) ──→ BD-027 (No-Show UI)
  │     │     ├── BD-022 (Transfer API) ──→ BD-023 (Transfer UI)
  │     │     ├── BD-024 (Complete API)
  │     │     ├── BD-025 (Cancel API)
  │     │     ├── BD-026 (No-Show API)
  │     │     ├── BD-031 (Process Skipper)
  │     │     └── BD-032 (Identity Verify)
  │     ├── BD-028 (PIN Service) ──→ BD-029 (Override API) ──→ BD-030 (Override UI)
  │     ├── BD-033 (Status API) ──→ BD-034 (Display Board) ──→ BD-035 (Display Status)
  │     ├── BD-040 (Dashboard) ──→ BD-041/042 (Dashboard sections)
  │     └── BD-043 (Audit API) ──→ BD-044 (PDF) ──→ BD-045 (Reports UI)
  └── BD-007 (Layouts) ──→ BD-015 (QR Scanner) ──→ BD-017 (Triage UI)
                        ──→ BD-046 (Offline/PWA)
                        ──→ BD-048 (Loading/Toast)

BD-047 (Seeder): depends on BD-004 only, can run anytime after models exist.
BD-049 (Error Handling): depends on BD-005, BD-006, can run anytime after auth.
BD-050 (Polish): final task, depends on all feature beads.
BD-051 (Component Preview): no dependencies, can be done anytime (pure HTML).
BD-052 (Tech Decisions Doc): no dependencies, documentation only.
```

---

## Suggested Sprint Plan (4-Week Capstone)

### Week 1: Foundation + Admin Config
BD-001 → BD-007 (Foundation, now includes DaisyUI setup)
BD-051 (Component Preview Page — can run in parallel, no dependencies)
BD-052 (Tech Decisions Doc — quick documentation task)
BD-008 → BD-014 (Admin CRUD)
BD-047 (Seeder)

### Week 2: Core Flows
BD-015 → BD-018 (Triage)
BD-019 → BD-027 (Station)
BD-028 → BD-030 (Override)

### Week 3: Display + Real-time + Dashboard + Edge Cases
BD-031 → BD-032 (Edge Cases)
BD-033 → BD-035 (Informant Display)
BD-036 → BD-039 (WebSocket)
BD-040 → BD-042 (Dashboard)

### Week 4: Reports + Polish + Testing
BD-043 → BD-045 (Reports)
BD-046 (PWA/Offline)
BD-048 → BD-050 (Polish)
Integration testing + SUS evaluation prep
