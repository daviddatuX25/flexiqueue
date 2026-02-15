# FlexiQueue — Phases Overview

**Project:** FlexiQueue — Offline-First Queue Management for MSWDO Barangay Operations
**Date:** 2026-02-10
**Version:** Phase 1 Contract v1.0

---

## Phase 1 — The Freeze (One-Month MVP)

Phase 1 delivers the **Core Value Loop**: a client walks in, receives a token, is triaged into a track, flows through stations, and completes — with every action audit-logged. Staff use phones. No internet required.

### Capabilities Included

#### Authentication & Authorization
- Session-based login/logout for staff (email + password).
- Three roles: **Admin**, **Supervisor**, **Staff**.
- Role-based middleware on all endpoints.
- Supervisor override PIN (separate 6-digit PIN field on supervisor/admin users).
- Public/Informant access (no auth) for status checks and display board.

#### Admin — Configuration
- **Program management**: create, edit, activate/deactivate. Only ONE active program at a time.
- **Service Track management**: create, edit, delete tracks per program. One default track per program. Color-coded.
- **Station management**: create, edit, activate/deactivate stations per program (flow nodes with name, capacity; triage is separate, not a station type).
- **Track Step management**: define ordered station sequence per track. Mark steps as required/optional. Set estimated duration.
- **Token management**: create tokens in batches (generate QR hashes + physical IDs), list all tokens, update status (available/lost/damaged). Artisan command for bulk seeding.
- **User management**: CRUD staff accounts, assign roles, activate/deactivate.
- **Staff assignment**: assign staff to stations for the active program.

#### Triage Flow
- Camera-based QR scanner in browser (with manual entry fallback).
- Scan token → validate availability → select client category → select track → confirm bind.
- Double scan protection: if token is `in_use`, show modal with current session info. Supervisor can force-end.
- If token is `lost`/`damaged`, block with error message.
- Bind creates a `Session` in `waiting` status at the first station of the track.

#### Station Flow
- Station queue view: "now serving" card + ordered waiting list.
- **Call next**: pull next waiting session from queue, set to `serving`.
- **Transfer** (standard): send to next station in track sequence. Session goes to `waiting` at the next station.
- **Transfer** (custom): staff selects a target station explicitly (still logged as `transfer`).
- **Complete**: finalize session. Validates all required steps done. Unbinds token.
- **Cancel**: any authenticated user can cancel a session at any point. Unbinds token.
- **No-show**: 3-attempt counter. After 3 calls, prompt to mark no-show. Unbinds token.
- Identity verification prompt for priority-track clients (verify ID, report mismatch).
- Process skipper detection: if scanned at wrong station, show red "Invalid Sequence" screen with send-back or supervisor override options.

#### Supervisor Override
- Requires supervisor/admin role.
- Requires 6-digit override PIN (stored as hashed field on user).
- Requires reason text (stored in `transaction_logs.remarks`).
- Applies to: route override, force-end session, process skipper approval.

#### Informant Display
- Public "Now Serving" board: shows all currently-serving sessions and their stations. Auto-updates via WebSocket.
- Per-station waiting counts.
- QR status check: client scans token → sees progress through track steps + current station + estimated wait.
- System announcements (broadcast messages).

#### Admin Dashboard
- System health summary: active sessions count, queue waiting count, stations online, uptime.
- Active program overview with per-track client counts.
- Station status table: station name, assigned staff, queue length, current client.
- Live updates via WebSocket.

#### Real-time (WebSocket)
- `station.{id}` private channel: `client_arrived`, `status_update`, `override_alert`.
- `global.queue` broadcast channel: `now_serving`, `queue_length`, `system_announcement`.

#### Audit & Reports
- Immutable `transaction_logs` table (append-only, no UPDATE/DELETE).
- CSV export of transaction logs (filterable by program, date range, station, action type).
- PDF Report Template 1: **Daily Operations Summary** — totals per track, per station, avg wait times, no-show count, override count.
- PDF Report Template 2: **Session Detail Report** — full transaction history for a specific session or date range.

#### Offline Handling (Basic)
- Offline detection banner ("Offline Mode — connection lost").
- PWA service worker for app shell caching (pages load even if server is momentarily unreachable).
- When connection returns, page auto-refreshes state.
- Full IndexedDB action queuing is **deferred to Phase 2**.

#### Database Seeder
- Artisan command to seed a complete demo: program + 3 tracks + 5 stations + 50 tokens + sample staff accounts.

---

### Capabilities Explicitly EXCLUDED from Phase 1

These are **not built, not stubbed, not migrated**:

- `hardware_units` table and all ESP32/IoT device management.
- `device_events` table and device logging.
- `device.{mac}` WebSocket channel.
- Capability aggregation system.
- Audio streaming (WebRTC, binary WebSocket relay, I2S output).
- Hybrid Android app (Capacitor.js).
- Thermal printer integration.
- SMS notifications.
- Multi-site sync / replication.
- Advanced analytics / predictive wait times.
- Voice announcements / TTS.
- Cloud-optional mode.
- Permission delegation for cancel action.
- Simultaneous active programs (Phase 1 enforces ONE active program).

---

## Phase 2 — The Icebox (3–6 Months Post-MVP)

| Feature | Rationale for Deferral |
|---------|----------------------|
| Full offline action queue (IndexedDB + replay) | Complex; basic offline banner is sufficient for pilot |
| Simultaneous active programs | Requires schema + UI changes; one program is fine for pilot |
| Hybrid Android app (Capacitor.js) | PWA is sufficient; native wrapper adds camera/kiosk lock benefits |
| Thermal printer integration | Nice-to-have; manual token cards work for MVP |
| SMS notifications | Requires cell signal; offline-first sites may not have it |
| Permission delegation (cancel, reassign) | Simple "any user can cancel" is fine for pilot |
| Advanced audit log integrity (hash chaining) | Append-only is sufficient; hash chain adds tamper detection |
| Rogue device detection + auto-disable | No hardware devices in Phase 1 |

## Phase 3 — Future (6–12 Months)

| Feature | Notes |
|---------|-------|
| ESP32 hardware integration (displays, buttons, speakers) | Requires `hardware_units` table, capability aggregation |
| Audio streaming (phone mic → ESP32 speaker) | WebRTC/binary WS relay pipeline |
| Multi-site sync (USB drive replication) | Offline replication between MSWDO sites |
| Advanced analytics (wait time optimization, bottleneck detection) | Requires historical data accumulation |
| Voice announcements / TTS | "Now serving A1 at Table 2" auto-announcements |

## Phase 4 — Long-Term (1+ Years)

| Feature | Notes |
|---------|-------|
| Cloud-optional mode | Sync to central system when internet available |
| Mobile-first redesign | Full UX overhaul for multi-LGU usage |
| Open API for third-party integrations | Treasury, health, DSWD systems |
| Biometric verification | Fingerprint/facial recognition at stations |

---

## Success Metrics — Phase 1 Definition of Done

### Functional Completeness
- [ ] Admin can configure a program with tracks, stations, steps, and tokens.
- [ ] Triage staff can scan a QR token and bind it to a session on the correct track.
- [ ] Station staff can call next, serve, transfer, complete, cancel, and mark no-show.
- [ ] Supervisor can override routing with PIN + reason.
- [ ] Informant display shows live "Now Serving" board and supports QR status checks.
- [ ] Admin dashboard shows live system status.
- [ ] Audit logs are complete and exportable as CSV.
- [ ] Two PDF report templates generate correctly.

### Technical KPIs
- [ ] UI response time < 500 ms for common actions on local Wi-Fi.
- [ ] Supports 100+ active sessions without degradation.
- [ ] Database queries average < 100 ms.
- [ ] WebSocket updates propagate in < 1 second.
- [ ] Zero data loss during 8-hour simulated operations.

### Quality Gates
- [ ] All migrations run cleanly on fresh MariaDB.
- [ ] Demo seeder produces a working environment.
- [ ] Role-based access enforced on every endpoint (tested).
- [ ] Transaction logs written for every state change (verified).
- [ ] PWA loads app shell when server is briefly unreachable.

### Usability Target
- [ ] SUS (System Usability Scale) score >= 68 ("Good") across admin, staff, and informant interfaces.
- [ ] Staff onboarding time < 3 minutes with guided walkthrough.
- [ ] Token scan-to-display time < 10 seconds.
