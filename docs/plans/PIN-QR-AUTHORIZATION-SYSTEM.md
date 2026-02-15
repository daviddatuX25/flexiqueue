# PIN/QR Authorization System — Plan for Beads

**Purpose:** Unified authorization for override and force-complete actions. PIN and QR are the same tier; user chooses one. Each has Temporary (generate on demand) and Preset (user's own, persistent) variants.

---

## 1. Model

| Method | Variant | Description |
|--------|---------|-------------|
| PIN | Temporary | Supervisor generates one-time 6-digit code; expires after use or TTL (e.g. 5 min). Staff enters it to authorize. |
| PIN | Preset | User's persistent 6-digit PIN (hashed, stored on user). Admin cannot view. Used when supervisor enters it themselves. |
| QR | Temporary | Supervisor generates one-time QR; staff scans to authorize. Expires after use or TTL. |
| QR | Preset | User's persistent QR (encodes stable token). Admin cannot share/see. User can regenerate own. |

**UI:** Single option "Authorize with PIN or QR". Within that, user picks: "Temporary" (generate now) or "Preset" (use my own).

---

## 2. Schema

- **Table** `temporary_authorizations`: `id`, `user_id`, `token_hash`, `type` (pin|qr), `expires_at`, `used_at`, `created_at`
- **Column** `users.override_qr_token`: nullable, hashed; stable token for preset QR
- **Existing** `users.override_pin`: hashed; preset PIN

---

## 3. Edge Cases (Solid Handling)

### 3.1 Temporary auth

| Case | Handling |
|------|----------|
| Never used, expires | Mark expired; staff must request new. Log expiration. |
| Used twice | Reject second use; return 401. Log attempt. |
| Expired when staff tries | Return 401 "Authorization expired. Request a new one." |
| Multiple pending for same session | Allow; each override/force-complete can have its own temp auth. |
| Supervisor generates but goes offline | Temp auth still valid until TTL. |
| Token collision (extremely rare) | Use cryptographically secure random; token_hash unique index. |

### 3.2 Preset PIN

| Case | Handling |
|------|----------|
| User forgets | Reset via Profile; user sets new. Old invalidated. |
| Admin tries to view | Never expose; only allow admin to trigger reset flow. |
| Wrong PIN 5 times | Rate limit; lock for 15 min or require supervisor unlock. |
| Preset not set | Block override until user sets in Profile. Show "Set authorization in Profile" message. |
| PIN changed mid-session | New PIN takes effect immediately; old rejected. |

### 3.3 Preset QR

| Case | Handling |
|------|----------|
| User loses/compromises | Regenerate in Profile; old QR invalidated. |
| QR printed and left in office | Treat as secret; document: user should regenerate if exposed. |
| Admin cannot share another user's | No API to fetch; only user can view/regenerate own. |
| Scan fails (bad camera, damaged) | Offer "Enter PIN instead" fallback. |
| Replay (same QR scanned twice) | Optional nonce per scan; or single-use window for preset (configurable). |

### 3.4 General

| Case | Handling |
|------|----------|
| User has neither PIN nor QR | Cannot authorize; prompt to set in Profile. |
| Supervisor not for this program | Cannot authorize; 403. |
| Rate limit exceeded | 429; message "Too many attempts. Try again in X minutes." |
| Session binding | Optional: bind temp auth to session_id to prevent cross-session reuse. |

---

## 4. API Endpoints

- `POST /api/auth/temporary-pin` — Supervisor generates; returns 6-digit code (or stores hash, returns code to display). Body: `{ program_id?, expires_in_seconds? }`
- `POST /api/auth/temporary-qr` — Supervisor generates; returns QR image URL or data. Body: same.
- `POST /api/sessions/{session}/override` — Body: `{ target_station_id, reason, auth_type: "temp_pin"|"temp_qr"|"preset_pin"|"preset_qr", temp_code?, pin?, qr_scan_token? }`
- `POST /api/sessions/{session}/force-complete` — Same auth_type pattern.
- `GET /api/profile/override-qr` — User fetches own preset QR (image or data). Regenerate: `POST /api/profile/override-qr/regenerate`
- `PUT /api/profile/override-pin` — User sets/updates preset PIN. Body: `{ current_password, new_pin }`

---

## 5. Bead Breakdown

| Bead | Title | Description |
|------|-------|-------------|
| AUTH-1 | Schema: temporary_authorizations, users.override_qr_token | Migration + model. |
| AUTH-2 | Preset PIN/QR: Profile UI, hashed storage, admin cannot view | User sets in Profile; reset flow; never expose to admin. |
| AUTH-3 | Temporary PIN: generate endpoint, TTL, single-use | POST temporary-pin; store hash; return code; validate on override. |
| AUTH-4 | Temporary QR: generate endpoint, staff scans | POST temporary-qr; return image; validate scan token on override. |
| AUTH-5 | Override/force-complete UI: "Authorize with PIN or QR" + Temporary vs Preset | Station page: auth picker; integrate all four paths. |
| AUTH-6 | Edge cases: wrong PIN lockout, expiry, replay, rate limit, tests | PHPUnit + manual tests for all cases in §3. |

**Dependencies:** AUTH-1 → AUTH-2, AUTH-3, AUTH-4; AUTH-2,3,4 → AUTH-5; AUTH-5 → AUTH-6.
