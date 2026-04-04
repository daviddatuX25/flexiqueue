# FlexiQueue — Manual Testing Checklist

This document is the consolidated manual testing checklist for FlexiQueue. Use it to verify that every feature area and user flow works as expected before release or after major changes. Each section maps to the areas defined in the Manual Testing Checklist — Scoping Plan.

**How to use:** For each row, perform the **Action**, verify the **Expected result**, then mark **Pass** or **Fail** and add **Notes** if needed.

---

## Pre-requisites

Before running the checklist:

- [ ] Application running (e.g. `./vendor/bin/sail up -d` or equivalent).
- [ ] Database migrated (`./vendor/bin/sail artisan migrate`).
- [ ] Database seeded with test data (`./vendor/bin/sail artisan db:seed`).
- [ ] (Optional) Laravel Reverb running for real-time tests (`./vendor/bin/sail artisan reverb:start`).
- [ ] Test users available: at least one **admin**, one **staff** (non-supervisor), one **staff** who is supervisor for at least one program.
- [ ] Test tokens and an active program with stations/tracks for queue and display flows.

---

## Role matrix (quick reference)

| Role / context       | Login redirect / entry | Key routes |
|----------------------|------------------------|------------|
| Unauthenticated      | `/`, `/login`          | Login, Welcome (if not logged in), public Display/Triage |
| Admin                | → `/admin/dashboard`   | All `/admin/*`, `/api/admin/*` |
| Staff                | → `/station`           | `/dashboard`, `/station`, `/triage`, `/program-overrides`, `/profile` |
| Supervisor (program-scoped) | Same as staff | Plus: dashboard stats, temporary PIN/QR, permission approve/reject, authorizations list |
| Public (no auth)     | Direct URLs            | `/display`, `/display/station/{id}`, `/display/status/{qr_hash}`, `/triage/start` |

---

## 1. Authentication and session

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 1.1 | Log in with valid admin credentials. | Redirect to `/admin/dashboard`; admin UI visible. | | | |
| 1.2 | Log in with valid staff credentials. | Redirect to `/station`; staff UI (e.g. Station or dashboard) visible. | | | |
| 1.3 | Log in with invalid email/password. | Error shown (toast or inline); no redirect; stay on login. | | | |
| 1.4 | Log in with inactive user credentials. | Same generic error as invalid credentials (e.g. "Invalid credentials"); no redirect. | | | |
| 1.5 | Attempt login 5+ times with wrong password from same IP. | After 5 attempts: "Too many attempts. Please try again in 15 minutes." (or equivalent). | | | |
| 1.6 | Visit `/login` while already logged in as admin. | Redirect to `/admin/dashboard`. | | | |
| 1.7 | Visit `/login` while already logged in as staff. | Redirect to `/station`. | | | |
| 1.8 | Click Log out (any role). | Redirect to `/login`; session cleared; user availability set to away. | | | |
| 1.9 | Let session expire (or clear session cookie), then trigger any API action (e.g. save on a form). | 419 or session error; app shows "Session expired. Please refresh and try again." (or equivalent); no crash. | | | |
| 1.10 | Submit a form or API request with invalid/missing CSRF token. | Request rejected (e.g. 419); user sees error or session message. | | | |

---

## 2. Welcome and broadcast test (authenticated)

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 2.1 | Visit `/` while authenticated. | Welcome page renders; links (e.g. Test page) are clickable. | | | |
| 2.2 | Visit `/broadcast-test` and click Fire broadcast. | No JavaScript error; if Reverb is running, event is received (optional). | | | |
| 2.3 | Trigger error state on broadcast test page (if applicable). | Error message has `role="alert"` (inspect in dev tools). | | | |

---

## 3. Admin — Dashboard

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 3.1 | Open `/admin/dashboard` as admin. | Page loads; health stats, quick actions, and active program card (if any) visible. | | | |
| 3.2 | Click Refresh (if present). | Loading state shown; then updated data or dismissible error. | | | |
| 3.3 | Click each nav link: Programs, Tokens, Users, Logs, Analytics, Settings, Program default settings. | Each navigates to the correct page. | | | |
| 3.4 | With no active program configured, open dashboard. | Empty or appropriate state; no fatal errors. | | | |
| 3.5 | (Optional) With Reverb on, change program status elsewhere; stay on dashboard. | Dashboard updates (e.g. active program card) without full reload. | | | |

---

## 4. Admin — Programs

### 4.1 Programs list

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 4.1.1 | Open `/admin/programs`. | List of programs shown; or empty state with CTA (e.g. "Create your first program"). | | | |
| 4.1.2 | Click Create program (or equivalent); submit with valid name. | New program appears in list; success feedback. | | | |
| 4.1.3 | Try to create program with empty or invalid name. | Validation error shown; program not created. | | | |
| 4.1.4 | From list, Activate / Deactivate / Pause / Resume a program (where available). | State updates; list reflects new status. | | | |
| 4.1.5 | Click a program to open its detail page. | Navigate to `/admin/programs/{id}`. | | | |

### 4.2 Program show

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 4.2.1 | On program show, switch between tabs: Overview, Tracks & steps, Stations, Diagram, Settings, Staff. | Each tab loads correct content; no console errors. | | | |
| 4.2.2 | Use keyboard: Tab to tabs, Arrow keys / Home / End to move between tabs. | Focus moves correctly; selected tab and panel stay in sync. | | | |
| 4.2.3 | **Overview:** Edit program name/description; save. | Changes persist; success toast or message. | | | |
| 4.2.4 | **Overview:** Activate / Deactivate / Pause / Resume program. | Status updates; UI reflects new state. | | | |
| 4.2.5 | **Tracks & steps:** Add a track; add steps; link steps to stations/processes; reorder steps; edit/delete. | All CRUD and reorder work; list updates. | | | |
| 4.2.6 | **Stations:** Add station; edit; assign processes; regenerate station TTS. | Stations and process assignments save; TTS regeneration runs or shows feedback. | | | |
| 4.2.7 | **Diagram:** Load diagram; add nodes (tracks, stations, processes, client seat); Save/Publish; upload image; clear diagram. | Diagram saves; image upload works; clear removes diagram. | | | |
| 4.2.8 | **Settings:** Change no_show_timer, require_permission_before_override, priority_first, balance_mode, display_scan_timeout_seconds, allow_public_triage, etc.; Save. | Settings persist; success or error toast. | | | |
| 4.2.9 | **Staff:** Assign staff to program; add/remove supervisors. | Only staff users listed; assignments and supervisors update. | | | |
| 4.2.10 | Trigger program-level "Regenerate station TTS" (all stations). | Request succeeds; toast or status feedback. | | | |
| 4.2.11 | Cause a fetch failure (e.g. disconnect network) and save or load. | "Session expired" or "Network error" toast; no uncaught exception. | | | |

---

## 5. Admin — Program default settings

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 5.1 | Open `/admin/program-default-settings`. | Form loads with current settings pre-filled. | | | |
| 5.2 | Change one or more settings; Save. | Success toast; form shows saved values. | | | |
| 5.3 | Submit invalid data (e.g. invalid number). | Validation errors shown; no save. | | | |
| 5.4 | Simulate failed load (e.g. offline). | Error message (toast or inline with role="alert"); 419/network handled. | | | |

---

## 6. Admin — Tokens

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 6.1 | Open `/admin/tokens`. | Token list loads; filters (program, status) and pagination work if present. | | | |
| 6.2 | Batch create tokens: set count, program, optional generate_tts; submit. | New tokens appear; success feedback. | | | |
| 6.3 | Edit a token: change physical_id, pronounce_as, status, TTS options; save. | Changes persist; list updates. | | | |
| 6.4 | Mark token available; deactivate token; use bulk delete (if available). | Status and list update correctly. | | | |
| 6.5 | Open token TTS / phrases; edit and preview; trigger regenerate TTS (single or bulk). | TTS options save; regeneration runs or shows feedback. | | | |
| 6.6 | Open Print settings modal; change cards_per_page, paper, orientation, show_hint, show_cut_lines, logo_url, footer_text, bg_image_url; upload logo/background image; Save. | Settings and images save; success toast. | | | |
| 6.7 | Open Token TTS settings (global); change voice_id, rate; play sample phrase; Save. | Settings save; "requires_regeneration" shown when applicable. | | | |
| 6.8 | Open `/admin/tokens/print`. | Print view renders; layout correct; no horizontal overflow; Back returns to list. | | | |

---

## 7. Admin — Users

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 7.1 | Open `/admin/users`. | User list loads. | | | |
| 7.2 | Create user: name, email, password, role; submit valid data. | User appears in list; success feedback. | | | |
| 7.3 | Create user with invalid email or missing required fields. | Validation errors shown; user not created. | | | |
| 7.4 | Edit user: name, email, role, active; save. | Changes persist. | | | |
| 7.5 | Reset password for a user. | Success feedback; new password works at login. | | | |
| 7.6 | Assign/unassign station for a user (if supported). | Assignment updates. | | | |
| 7.7 | Check staff users in list. | Availability status (available / on_break / away) shown where applicable. | | | |
| 7.8 | Delete user (if supported); confirm in modal. | User removed from list or deactivated as designed. | | | |

---

## 8. Admin — Logs

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 8.1 | Open `/admin/logs`. | Program sessions and/or Audit log sections load. | | | |
| 8.2 | Apply filters: date range, program, user, action type; click Apply. | List updates according to filters. | | | |
| 8.3 | Check audit log list. | action_type values shown with readable labels; pagination works. | | | |
| 8.4 | Click Export CSV (or equivalent). | Download starts; success toast. | | | |
| 8.5 | Trigger export failure (e.g. invalid range). | Error toast; no crash. | | | |
| 8.6 | Use filters that return no data. | Empty state with message and CTA (e.g. "Change filters"). | | | |

---

## 9. Admin — Analytics

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 9.1 | Open `/admin/analytics`. | Summary metrics and charts load (throughput, wait distribution, station utilization, etc.). | | | |
| 9.2 | Change date range: Today, 7d, 30d, custom; change program/track filters. | Data updates; loading/error states shown appropriately. | | | |
| 9.3 | Trigger export (if available). | Download or success feedback; errors show toast. | | | |
| 9.4 | Use filters that yield no data. | Empty state with CTA (e.g. "Change filters"). | | | |
| 9.5 | If chart library fails to load. | User-facing placeholder (e.g. "Chart could not be loaded") instead of raw error. | | | |

---

## 10. Admin — Settings

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 10.1 | Open `/admin/settings`. | Storage and Integrations tabs visible. | | | |
| 10.2 | **Storage:** View summary (disk usage, tts_audio, profile_avatars, print_images, logs, database). | Summary loads; categories and sizes shown. | | | |
| 10.3 | **Storage:** Click Clear TTS cache; confirm in modal. | Success toast; summary may update. | | | |
| 10.4 | **Storage:** Click Clear orphaned TTS files; confirm. | Success toast; no errors. | | | |
| 10.5 | **Integrations (ElevenLabs):** View status (connected / not_configured); list accounts. | Status and account list load. | | | |
| 10.6 | **Integrations:** Add account (label, model, API key); save. | Account appears; validation errors if invalid. | | | |
| 10.7 | **Integrations:** Edit account; activate; delete account. | Changes persist; 419/network errors show toast. | | | |
| 10.8 | Switch between Storage and Integrations tabs. | Tab content switches; touch targets at least 48px. | | | |

---

## 11. Staff — Dashboard

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 11.1 | Log in as staff; go to `/dashboard`. | Metrics visible: sessions served today, average time per client; "Your station" if assigned; activity counts. | | | |
| 11.2 | Click links: Station, Triage, Program overrides, Profile. | Each navigates correctly. | | | |
| 11.3 | Resize to narrow viewport (e.g. 360px). | All actions usable; no clipping or overflow. | | | |

---

## 12. Staff — Station

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 12.1 | Open `/station` or `/station/{id}`. | Queue view loads: serving list, waiting list, no_show timer, stats. | | | |
| 12.2 | If multiple stations: switch station. | Queue updates for selected station. | | | |
| 12.3 | Call next in queue. | Session moves to serving; success toast. If call_next_requires_override and require_permission_before_override, PIN/QR prompt shown. | | | |
| 12.4 | Start serving a session (from waiting or after call); optionally select process. | Session marked serving; UI updates. | | | |
| 12.5 | Transfer session (standard). | Session moves to next step/station; queue updates. | | | |
| 12.6 | Complete session. | Session completed; token available; queue updates. | | | |
| 12.7 | Cancel session (with optional remarks). | Session cancelled; queue updates. | | | |
| 12.8 | Mark no-show. | no_show_attempts increment; timer/behavior as designed. | | | |
| 12.9 | Force complete (when allowed). With require_permission_before_override ON: enter supervisor PIN/QR. With OFF: enter reason only. | Action succeeds; correct auth path used. | | | |
| 12.10 | Override (when allowed). Same PIN/QR vs reason-only as force complete. | Action succeeds; queue updates. | | | |
| 12.11 | Scan or type token (session-by-token). | Session shown; serve/transfer/complete/cancel/no-show available as appropriate. | | | |
| 12.12 | Toggle Priority first for station. | Setting persists; queue order may change. | | | |
| 12.13 | Open station notes; edit and save. | Notes load and update correctly. | | | |
| 12.14 | Open display settings; change display_audio_muted, display_audio_volume; save. | Settings persist. | | | |
| 12.15 | Create a permission request (e.g. for override). | Success or error toast. | | | |
| 12.16 | (With Reverb) From another tab/device, call/serve/transfer/complete. | Station queue updates without full reload. | | | |
| 12.17 | Cause API failure (e.g. disconnect). | Session expired or network error toast; no crash. | | | |

---

## 13. Staff — Triage (staff-side)

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 13.1 | Open `/triage`. | Active program and tracks shown; scan or manual token input available. | | | |
| 13.2 | Scan or enter available token (QR hash or physical_id). | Token lookup returns "available"; track selection shown. | | | |
| 13.3 | Scan or enter token that is in_use or invalid. | Appropriate message (e.g. already in use); no bind. | | | |
| 13.4 | Select track/category; submit bind. | Session created; success feedback. | | | |
| 13.5 | Scan same QR repeatedly without moving it. | No flicker; single result (latch behavior). | | | |
| 13.6 | Cancel or "start over"; scan again. | New scan accepted; latch reset. | | | |
| 13.7 | (If HID barcode used) Use manual focus window; wait for refocus. | After timeout, focus returns to hidden input. | | | |

---

## 14. Public — Triage (self-serve)

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 14.1 | With allow_public_triage false, open `/triage/start`. | 403 or safe message; no internal details leaked. | | | |
| 14.2 | With allow_public_triage true, open `/triage/start`. | Program/tracks; scan or manual token; token lookup and bind with track selection. | | | |
| 14.3 | Complete bind flow: scan available token, select track, submit. | Session created; success. | | | |
| 14.4 | If display settings require PIN: enter PIN and update settings. | Settings update; rate limit (e.g. 10/min by IP) applies when exceeded. | | | |
| 14.5 | Scan same QR repeatedly. | No multiple navigations or flicker (latch). | | | |

---

## 15. Public — Display

### 15.1 Display board

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 15.1.1 | Open `/display` (no auth). | Program name, date; now serving grid; waiting by station; station activity; staff availability (profile bar + dots). | | | |
| 15.1.2 | Scan QR (camera or HID barcode) for a valid token. | Navigate to `/display/status/{qr_hash}` once (no multiple navigations). | | | |
| 15.1.3 | Open settings (PIN if required); change display_audio_muted, volume; save. | Settings persist. | | | |
| 15.1.4 | With TTS enabled, trigger "now serving" announcement. | Audio plays (or "Audio unavailable" toast if failure). | | | |
| 15.1.5 | With Reverb on: from station, call/serve/complete. | Board updates (now serving, waiting) without reload. | | | |
| 15.1.6 | With REVERB_APP_KEY unset. | No Pusher error; optional "Real-time updates unavailable" toast. | | | |

### 15.2 Display station board

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 15.2.1 | Open `/display/station/{id}`. | Station-specific view; TTS and real-time as per config. | | | |
| 15.2.2 | Trigger error (e.g. invalid station). | Error surfaced via toast or message. | | | |

### 15.3 Display status

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 15.3.1 | Open `/display/status/{qr_hash}` with valid in_use token. | Alias, status, client_category, progress, current_station, estimated_wait, message; diagram if program has one. | | | |
| 15.3.2 | Click Dismiss. | Navigate back to `/display`. | | | |
| 15.3.3 | Wait for auto-dismiss countdown (or use extend). | Countdown visible; auto-dismiss or extend works. | | | |
| 15.3.4 | Use hidden barcode input; scan/type next ticket; press Enter. | Navigate to new status URL. | | | |
| 15.3.5 | Open status with invalid or expired hash. | User-friendly error; no stack trace. | | | |

---

## 16. Public — Check status (API)

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 16.1 | GET `/api/check-status/{qr_hash}` with valid hash (e.g. from browser or curl). | JSON with status data returned. | | | |
| 16.2 | GET with invalid hash. | Safe error response; no internal leak. | | | |

---

## 17. Program overrides (authorizations)

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 17.1 | As supervisor/admin, open `/program-overrides`. | List of generated authorizations (temporary PIN/QR) visible. | | | |
| 17.2 | Generate temporary PIN: select TTL; submit. | PIN displayed/copied; expiry respected when used. | | | |
| 17.3 | Generate temporary QR: select TTL; submit. | QR displayed; expiry respected. | | | |
| 17.4 | As supervisor/admin: list pending permission requests; approve one (with optional reason). | Request approved; list updates. | | | |
| 17.5 | Reject a permission request. | Request rejected; list updates. | | | |
| 17.6 | As staff (non-supervisor): open `/program-overrides`. | Can create permission request only; no authorizations list or approve/reject. | | | |

---

## 18. Profile

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 18.1 | Open `/profile`. | Override PIN, Override QR, Password, Avatar sections visible. | | | |
| 18.2 | Set or change Override PIN; submit valid PIN. | Success toast; PIN works for verify-pin flow. | | | |
| 18.3 | Submit invalid PIN (e.g. wrong length). | Validation error shown. | | | |
| 18.4 | View Override QR; Regenerate QR. | QR displayed; regenerate success/error toast. | | | |
| 18.5 | Change password: current, new, confirm; submit valid. | Success; can log in with new password. | | | |
| 18.6 | Submit invalid password change (e.g. wrong current, mismatch). | Validation errors; no change. | | | |
| 18.7 | Upload profile photo; remove photo. | Avatar updates; success/error toast; 419/network handled. | | | |
| 18.8 | Trigger server validation error (e.g. wrong current password). | First invalid field focused; errors linked (aria-invalid, aria-describedby). | | | |

---

## 19. Verify PIN (Station flow)

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 19.1 | As staff at station, trigger action that requires supervisor auth (e.g. call next when call_next_requires_override). | PIN or QR prompt shown. | | | |
| 19.2 | Enter valid supervisor PIN. | 200; action proceeds; success. | | | |
| 19.3 | Enter invalid PIN. | 401; error message; action does not proceed. | | | |
| 19.4 | Fail verify-pin 5+ times in 1 minute. | Rate limit; appropriate message. | | | |

---

## 20. User availability

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 20.1 | As staff, open any page with StatusFooter (e.g. Station). | Availability chip shows current status (Available / On break / Away / Offline). | | | |
| 20.2 | Change availability via dropdown/listbox. | PATCH /api/users/me/availability sent; status updates; chip reflects new value. | | | |
| 20.3 | Open Display board (public). | Staff availability bar/footer shows updated status for staff. | | | |
| 20.4 | As admin, open Users list. | Staff availability_status shown (read-only). | | | |

---

## 21. Real-time (Echo / Reverb)

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 21.1 | With Reverb on: from Station, perform call, serve, transfer, complete, cancel, no_show. | Station queue view updates on other open Station tabs without reload. | | | |
| 21.2 | With Reverb on: from Station, update queue. | Display board and display station board update. | | | |
| 21.3 | With Reverb on: change staff availability. | Display board staff bar updates. | | | |
| 21.4 | With REVERB_APP_KEY not set: load Station, Display board. | No Pusher error; optional "Real-time updates unavailable" toast; pages work without live updates. | | | |

---

## 22. Public TTS

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 22.1 | From Display or Station, trigger TTS playback (e.g. now serving). | Audio streams; GET /api/public/tts used with correct params. | | | |
| 22.2 | Call /api/public/tts/voices (e.g. from Settings or Display). | List of voices returned. | | | |
| 22.3 | Call /api/public/tts/token/{token} (if used). | Token-specific TTS stream. | | | |
| 22.4 | Exceed TTS rate limit (e.g. 60/min). | 429 or rate-limit response; no crash. | | | |

---

## 23. Layouts and shared components

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 23.1 | **AdminLayout:** Open sidebar/drawer; click nav links; Profile; Log out. | All links work; nav items at least 48px; hamburger on mobile. | | | |
| 23.2 | **MobileLayout:** Use bottom nav (Station, Triage, Dashboard, Program overrides, Profile); check StatusFooter. | Navigation correct; availability, queue count, processed, clock visible. | | | |
| 23.3 | **DisplayLayout:** View Display board/status. | Minimal chrome; content readable. | | | |
| 23.4 | **AuthLayout:** View Login. | Layout wraps form; flash/error via FlashToToast. | | | |
| 23.5 | **Modal:** Open any modal; press Tab repeatedly; press Escape. | Focus trapped inside; Escape closes modal; focus restored. | | | |
| 23.6 | **ConfirmModal:** Open confirm dialog; focus and Tab. | Focus trap; 48px buttons; Cancel/Confirm work. | | | |
| 23.7 | **ThemeToggle:** Click theme switch. | Light/dark theme toggles; button at least 48px. | | | |
| 23.8 | **OfflineBanner:** Simulate offline (dev tools). | Banner appears; Dismiss and Try again (if present) work. | | | |
| 23.9 | **QrScanner:** Open camera; deny permission. | Error state; "Scan from file" or equivalent available; aria-labels/role="alert" where needed. | | | |
| 23.10 | **Toaster:** Trigger success, error, info toasts. | Toasts show; dismiss/auto-dismiss work; FlashToToast shows login error. | | | |

---

## 24. Responsive and accessibility (sample checks)

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 24.1 | On Station, Triage, Profile: measure primary buttons/links. | Minimum 48px height (or touch-target class). | | | |
| 24.2 | On Admin (Programs, Tokens, Users, Logs, Analytics, Settings), Program overrides, Modals: same. | 48px minimum for primary actions. | | | |
| 24.3 | Login: Tab through fields; submit with Enter. | Focus order correct; form submits. | | | |
| 24.4 | Program Show: use Tab and Arrow/Home/End on tabs. | Keyboard nav works; focus visible. | | | |
| 24.5 | Any modal: Tab through; focus does not leave modal until closed. | Focus trap works. | | | |
| 24.6 | Trigger critical error (e.g. validation). | Error has role="alert" (inspect). | | | |
| 24.7 | Staff dashboard and Station at 360px width. | No horizontal scroll; actions usable. | | | |
| 24.8 | Admin dashboard and Analytics on tablet width. | Layout adapts; no overflow. | | | |
| 24.9 | Display board on large and small viewport. | Readable; scan and status areas usable. | | | |

---

## 25. Edge cases and security

| # | Action | Expected result | Pass | Fail | Notes |
|---|--------|-----------------|------|------|-------|
| 25.1 | Set program allow_public_triage false; open `/triage/start`. | 403 or safe message; no internal details. | | | |
| 25.2 | Open `/display/status/{invalid_hash}` or expired token. | Safe message; no stack trace. | | | |
| 25.3 | As staff (non-supervisor): try to open authorizations list or approve permission request. | No access or no approve/reject UI (403 or hidden). | | | |
| 25.4 | As staff: try to open `/admin/dashboard` or any `/admin/*`. | 403 or redirect to allowed area. | | | |
| 25.5 | Exceed login rate limit (5 attempts). | "Too many attempts. Please try again in 15 minutes." (or equivalent). | | | |
| 25.6 | Exceed verify-pin rate limit (5/min). | Rate limit response. | | | |
| 25.7 | Exceed public display settings rate limit (10/min by IP). | Rate limit response. | | | |
| 25.8 | Exceed public TTS rate limit (60/min). | Rate limit response. | | | |

---

## Appendix A — Key routes (reference)

| Route | Method | Auth | Description |
|-------|--------|------|-------------|
| `/login` | GET, POST | guest | Login form; submit login |
| `/logout` | POST | auth | Log out |
| `/` | GET | auth | Welcome |
| `/broadcast-test` | GET, POST | auth | Broadcast test page |
| `/admin/dashboard` | GET | admin | Admin dashboard |
| `/admin/programs` | GET | admin | Programs list |
| `/admin/programs/{id}` | GET | admin | Program show |
| `/admin/program-default-settings` | GET | admin | Program default settings |
| `/admin/tokens` | GET | admin | Tokens list |
| `/admin/tokens/print` | GET | admin | Tokens print view |
| `/admin/users` | GET | admin | Users list |
| `/admin/logs` | GET | admin | Logs (sessions, audit) |
| `/admin/analytics` | GET | admin | Analytics |
| `/admin/settings` | GET | admin | Settings (storage, integrations) |
| `/dashboard` | GET | admin, supervisor, staff | Staff dashboard |
| `/station`, `/station/{id}` | GET | admin, supervisor, staff | Station queue |
| `/triage` | GET | admin, supervisor, staff | Staff triage |
| `/program-overrides` | GET | admin, supervisor, staff | Program overrides / authorizations |
| `/profile` | GET | auth | Profile |
| `/display` | GET | — | Public display board |
| `/display/station/{id}` | GET | — | Public station board |
| `/display/status/{qr_hash}` | GET | — | Public status by QR |
| `/triage/start` | GET | — | Public self-serve triage |

---

## Appendix B — Key API endpoints (reference)

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/check-status/{qr_hash}` | GET | — | Public token status |
| `/api/auth/verify-pin` | POST | staff+ | Verify supervisor PIN |
| `/api/auth/authorizations` | GET | admin, supervisor | List authorizations |
| `/api/auth/temporary-pin` | POST | admin, supervisor | Generate temp PIN |
| `/api/auth/temporary-qr` | POST | admin, supervisor | Generate temp QR |
| `/api/users/me/availability` | PATCH | auth | Update own availability |
| `/api/profile/*` | GET, PUT, POST | auth | Profile PIN, QR, password, avatar |
| `/api/dashboard/stats`, `/api/dashboard/stations` | GET | admin, supervisor | Dashboard data |
| `/api/admin/*` | various | admin | All admin CRUD, settings, analytics, logs |
| `/api/sessions/bind`, `/api/sessions/token-lookup` | GET, POST | staff+ | Session bind, lookup |
| `/api/sessions/{id}/call`, `serve`, `transfer`, `complete`, `cancel`, `no-show`, `force-complete`, `override` | POST | staff+ | Session actions |
| `/api/stations`, `/api/stations/{id}/queue`, `notes`, `display-settings`, `priority-first`, `session-by-token` | GET, PUT, POST | staff+ | Station queue API |
| `/api/permission-requests` | POST | staff+ | Create permission request |
| `/api/permission-requests/{id}/approve`, `reject` | POST | admin, supervisor | Approve/reject request |
| `/api/public/token-lookup` | GET | — | Public token lookup |
| `/api/public/sessions/bind` | POST | — | Public bind |
| `/api/public/display-settings` | POST | — | Public display/triage settings (auth_type: preset/temp PIN/QR; rate limited) |
| `/api/public/tts`, `/api/public/tts/voices`, `/api/public/tts/token/{id}` | GET | — | Public TTS stream, voices, token TTS |

---

## Appendix C — Environment notes

- **Reverb:** Set `REVERB_APP_KEY` (and related env) and run `reverb:start` for real-time tests; leave unset to verify graceful degradation.
- **Public triage:** Set program `allow_public_triage` true to test `/triage/start`; false to test 403/safe message.
- **Roles:** Ensure test users for admin, staff, and staff-as-supervisor (program_supervisors) for full coverage.
