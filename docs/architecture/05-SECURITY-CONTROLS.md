# FlexiQueue — Phase 1 Security Controls

**Scope:** Phase 1 MVP — local LAN deployment, no internet exposure.

---

## 1. Threat Model

FlexiQueue operates on a **closed local network** with no internet exposure. The threat profile is fundamentally different from a cloud application.

### In-Scope Threats

| Threat | Severity | Mitigation Strategy |
|--------|----------|-------------------|
| **Staff privilege abuse** (skipping steps, queue manipulation) | HIGH | RBAC + immutable audit logs + supervisor overrides |
| **Unauthorized Wi-Fi access** | MEDIUM | WPA2-PSK + network-level access only |
| **Physical device theft** (server laptop) | MEDIUM | DB backups to removable media + encrypted at-rest (optional) |
| **Token swap fraud** (priority abuse) | MEDIUM | Identity verification prompt at priority stations |
| **Data tampering** (altering logs post-event) | HIGH | Append-only transaction logs + periodic checksum verification |
| **Session hijacking** (staff impersonation) | LOW | Session-based auth with CSRF protection |

### Out-of-Scope Threats

- External internet attacks (system is offline-first, not routable).
- DDoS / volumetric attacks (local LAN only, max ~30 devices).
- Advanced persistent threats (disproportionate to context).
- Biometric spoofing (no biometrics in Phase 1).

---

## 2. Authentication Strategy

### 2.1 Method: Laravel Session-Based Auth

- **Login**: email + password → Laravel `Auth::attempt()` → session cookie.
- **Session driver**: `database` (stored in `sessions` table — Laravel's built-in session table, NOT FlexiQueue's `sessions` table).
- **Session lifetime**: 8 hours (matches typical event duration). Configurable in `.env`.
- **CSRF protection**: enabled on all non-API forms (Laravel default `@csrf`).
- **Remember me**: disabled for Phase 1 (security-first for shared devices).

### 2.2 Login Page

- Route: `GET /login`
- Fields: email, password.
- On success: redirect to role-appropriate dashboard:
  - Admin → `/admin/dashboard`
  - Supervisor → `/station` (with elevated permissions)
  - Staff → `/station`
- On failure: "Invalid credentials" (generic message, no email enumeration).
- Account lockout: 5 failed attempts → 15-minute lockout (Laravel `ThrottlesLogins`).

### 2.3 Logout

- Route: `POST /logout`
- Invalidates session, clears cookie.
- Redirects to `/login`.

### 2.4 Public Access (No Auth)

These routes require NO authentication:
- `GET /display` — Informant display board.
- `GET /api/check-status/{qr_hash}` — QR token status lookup.
- `GET /display/status/{qr_hash}` — Informant status view page.

All other routes require authentication.

---

## 3. Authorization — Role-Based Access Control (RBAC)

### 3.1 Role Definitions

| Role | Level | Description |
|------|-------|-------------|
| **Admin** | 3 (highest) | Full system configuration, reporting, and all operational capabilities |
| **Supervisor** | 2 | Operational oversight, override approval, staff reassignment |
| **Staff** | 1 | Station operation only — call, serve, transfer, complete, cancel, no-show |
| **Public** | 0 | Read-only informant access, no authentication |

### 3.2 Permission Matrix

| Resource / Action | Admin | Supervisor | Staff | Public |
|-------------------|:-----:|:----------:|:-----:|:------:|
| **Programs** — create, edit, activate, delete | YES | NO | NO | NO |
| **Service Tracks** — CRUD | YES | NO | NO | NO |
| **Track Steps** — CRUD | YES | NO | NO | NO |
| **Stations** — CRUD | YES | NO | NO | NO |
| **Tokens** — create, list, update status | YES | NO | NO | NO |
| **Users** — CRUD, role assignment | YES | NO | NO | NO |
| **Staff Assignment** — assign to station | YES | YES | NO | NO |
| **Sessions — bind** (triage) | YES | YES | YES | NO |
| **Sessions — call next** | YES | YES | YES | NO |
| **Sessions — transfer** (standard) | YES | YES | YES | NO |
| **Sessions — transfer** (custom target) | YES | YES | YES | NO |
| **Sessions — complete** | YES | YES | YES | NO |
| **Sessions — cancel** | YES | YES | YES | NO |
| **Sessions — mark no-show** | YES | YES | YES | NO |
| **Sessions — override route** | YES | YES | NO | NO |
| **Sessions — force-end** (double scan) | YES | YES | NO | NO |
| **Reports — view audit logs** | YES | YES (own station) | NO | NO |
| **Reports — export CSV** | YES | NO | NO | NO |
| **Reports — generate PDF** | YES | NO | NO | NO |
| **Dashboard — system health** | YES | YES (limited) | NO | NO |
| **Informant Display** — view | YES | YES | YES | YES |
| **Check Status** — QR lookup | YES | YES | YES | YES |

### 3.3 Station-Scoped Access

Staff are scoped to their assigned station:
- A staff user can only see and act on the queue for `users.assigned_station_id`.
- API endpoints for station operations validate that `auth()->user()->assigned_station_id === $station_id`.
- Supervisors can access ANY station's queue (not scoped).
- Admins can access everything.

### 3.4 Enforcement Implementation

**Middleware stack** (applied via Laravel route groups):

```
Route::middleware(['auth'])->group(function () {
    // All authenticated routes

    Route::middleware(['role:admin'])->prefix('admin')->group(function () {
        // Admin-only: programs, tracks, stations, tokens, users, reports
    });

    Route::middleware(['role:admin,supervisor'])->group(function () {
        // Admin + Supervisor: overrides, force-end, staff reassignment
    });

    Route::middleware(['role:admin,supervisor,staff'])->group(function () {
        // All staff: session operations, station queue
    });
});

// Public (no auth):
Route::prefix('api')->group(function () {
    Route::get('/check-status/{qr_hash}', ...);
});
Route::get('/display', ...);
```

**Policy classes** (Laravel Policies):
- `SessionPolicy` — controls who can bind, transfer, override, complete, cancel.
- `StationPolicy` — controls station-scoped access (staff can only access assigned station).
- `ProgramPolicy` — admin-only for CRUD.

---

## 4. Supervisor Override PIN System

### 4.1 Purpose

Certain critical actions require a second factor beyond login: a 6-digit numeric PIN stored on supervisor/admin accounts. This prevents a staff member from using an unlocked supervisor phone to approve their own overrides.

### 4.2 PIN Storage

- Field: `users.override_pin` — VARCHAR(255), nullable.
- Storage: **Bcrypt hash** of the 6-digit PIN (same as password hashing).
- Required: application-enforced for users with `role` IN (`admin`, `supervisor`).
- Admin sets the PIN during user creation or via profile settings.

### 4.3 Actions Requiring PIN

| Action | Who Provides PIN | Context |
|--------|-----------------|---------|
| Route override | Supervisor or Admin | Station UI override modal |
| Force-end session (double scan) | Supervisor or Admin | Triage UI double-scan modal |
| Process skipper approval | Supervisor or Admin | Station UI invalid-sequence screen |

### 4.4 PIN Validation Flow

1. Staff initiates action (e.g., clicks "Supervisor Override").
2. UI shows PIN input modal.
3. Staff hands phone to supervisor OR supervisor enters PIN on their own device.
4. PIN is sent to backend: `POST /api/auth/verify-pin` with `{ user_id, pin }`.
5. Backend hashes and compares. Returns `200 OK` or `401 Unauthorized`.
6. On success, the original action proceeds with `supervisor_id` recorded in `transaction_logs.metadata`.
7. Rate limit: 5 PIN attempts per minute per user. Lockout after 5 failures.

### 4.5 PIN is NOT a Session

The PIN does not create a persistent session. It is a **one-time verification** for the specific action. The next override requires a fresh PIN entry.

---

## 5. Data Protection

### 5.1 Privacy by Design

- **No PII in queue data**: Sessions use `alias` (e.g., "A1"), not client names.
- **Category is generic**: `client_category` stores "PWD", "Senior", "Pregnant" — no personal details.
- **Token is anonymous**: QR hash cannot be reversed to identify a person.
- **Staff names in logs**: `transaction_logs.staff_user_id` links to staff — this is intentional for accountability.

### 5.2 Encryption

- **In transit**: HTTPS with self-signed certificate on local LAN. Acceptable for offline deployment.
- **At rest**: Not encrypted by default (controlled physical environment). Optional full-disk encryption on server laptop.
- **Passwords**: Bcrypt hashed (Laravel default, cost factor 12).
- **Override PINs**: Bcrypt hashed (same as passwords).

### 5.3 Compliance

- Aligned with **Philippine Data Privacy Act (RA 10173)**: minimal PII collection, purpose-limited processing.
- Aligned with **COA audit requirements**: complete, immutable transaction trail.

---

## 6. Audit Log Integrity

### 6.1 Append-Only Enforcement

The `TransactionLog` Eloquent model MUST:
- Disable `update()` and `delete()` methods (throw exception if called).
- Only allow `create()` / `insert()`.
- Never expose mass-update or mass-delete scopes.

### 6.2 What Gets Logged

Every call to these service methods MUST produce a `transaction_logs` entry:

| Service Method | `action_type` | `remarks` Required? |
|---------------|--------------|-------------------|
| `SessionService::bind()` | `bind` | No |
| `SessionService::callNext()` | `check_in` | No |
| `SessionService::transfer()` | `transfer` | No |
| `SessionService::override()` | `override` | **YES** (reason) |
| `SessionService::complete()` | `complete` | No |
| `SessionService::cancel()` | `cancel` | Recommended |
| `SessionService::markNoShow()` | `no_show` | No |
| `SessionService::forceComplete()` | `force_complete` | **YES** (reason) |
| Staff clicks "Identity Mismatch" | `identity_mismatch` | **YES** (description) |

### 6.3 Log Verification (Phase 1 — Basic)

- Admin can view full transaction log in the Reports section.
- CSV export includes all fields for offline audit.
- Future enhancement (Phase 2): hash-chain integrity verification.

---

## 7. Specific Access Boundaries

### 7.1 Staff Can Only See Their Station

- A staff user with `assigned_station_id = 5` calling `GET /api/stations/3/queue` → **403 Forbidden**.
- Exception: supervisors and admins can access any station.

### 7.2 Staff Cannot Modify Logs

- No endpoint exists for updating or deleting `transaction_logs`.
- The admin Reports UI is read-only (view + export).

### 7.3 Staff Cannot Manage Configuration

- Attempts to access `/admin/*` routes by staff → **403 Forbidden**.
- UI hides admin navigation for non-admin roles (defense in depth: backend still checks).

### 7.4 Public Cannot Access Staff Functions

- Informant display routes serve read-only data.
- `GET /api/check-status/{qr_hash}` returns limited fields: alias, track name, status, current station name, progress steps, estimated wait. No internal IDs exposed.

---

## 8. Developer Checklist

When implementing any feature, verify:

- [ ] Does this endpoint have the correct middleware (`auth`, `role:X`)?
- [ ] Is every state change recorded in `transaction_logs`?
- [ ] Is staff access scoped to their assigned station?
- [ ] Does the override flow require PIN + reason?
- [ ] Are we leaking internal IDs or PII in public API responses?
- [ ] Is CSRF protection active on form submissions?
- [ ] Are WebSocket channel subscriptions validated against user role + station assignment?
